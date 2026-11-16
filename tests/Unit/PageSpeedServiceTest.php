<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/src/Services/PageSpeedService.php';

/**
 * PageSpeed без сети: адрес и заголовок с ключом, разбор настоящих ответов sudar.su (16.11.2026, урезаны до нужных
 * полей), собранных вручную вариантов (`synthetic-*`) и ошибок Google.
 */
final class PageSpeedServiceTest extends TestCase
{
    private const FIXTURES = ROOT_PATH . '/tests/fixtures/pagespeed';
    private const KEY = 'AIzaTestKey_123-abc';

    private function fixture(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES . '/' . $name);
    }

    /**
     * @return array<string, mixed>
     */
    private function parse(string $name): array
    {
        $parsed = parsePageSpeedResponse($this->fixture($name));
        $this->assertNull($parsed['error']);

        return $parsed['result'];
    }

    public function testUrlHasEncodedPageStrategyAndNoKey(): void
    {
        $url = buildPageSpeedUrl('https://shop.ru/каталог?a=1&b=2', 'mobile');

        $this->assertStringStartsWith(PAGESPEED_API_URL . '?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame([
            'url'      => 'https://shop.ru/каталог?a=1&b=2',
            'strategy' => 'mobile',
            'category' => 'performance',
            'locale'   => 'ru',
        ], $query);
        $this->assertStringNotContainsString('key', $url);
    }

    public function testKeyGoesOnlyToHeader(): void
    {
        $calls = [];
        $api = static function (string $url, array $headers) use (&$calls): array {
            $calls[] = ['url' => $url, 'headers' => $headers];

            return ['status' => 200, 'body' => (string) file_get_contents(ROOT_PATH . '/tests/fixtures/pagespeed/sudar-mobile.json'), 'error' => null];
        };

        runPageSpeed('https://sudar.su/', self::KEY, $api);

        $this->assertSame(['mobile', 'desktop'], array_map(
            static fn (array $call): string => (string) preg_replace('/^.*strategy=(\w+).*$/', '$1', $call['url']),
            $calls
        ));
        foreach ($calls as $call) {
            $this->assertStringNotContainsString(self::KEY, $call['url']);
            $this->assertSame(['X-Goog-Api-Key: ' . self::KEY], $call['headers']);
        }
    }

    public function testKeyValidationRejectsHeaderInjection(): void
    {
        $this->assertTrue(isValidPageSpeedKey(self::KEY));
        $this->assertFalse(isValidPageSpeedKey("abc\r\nX-Evil: 1"));
        $this->assertFalse(isValidPageSpeedKey('abc def'));
        $this->assertFalse(isValidPageSpeedKey(''));
    }

    public function testAfterEachIsCalledForEveryStrategyEvenOnError(): void
    {
        $called = [];
        runPageSpeed(
            'https://sudar.su/',
            self::KEY,
            static fn (string $url, array $headers): array => ['status' => null, 'body' => '', 'error' => 'timeout'],
            static function (string $strategy) use (&$called): void {
                $called[] = $strategy;
            }
        );

        $this->assertSame(['mobile', 'desktop'], $called);
    }

    public function testParsesRealMobileResponse(): void
    {
        $result = $this->parse('sudar-mobile.json');

        $this->assertSame(39, $result['score']);
        $this->assertSame(['lcp' => 14326, 'cls' => 0.102, 'tbt' => 1077, 'fcp' => 1951], $result['lab']);
        $this->assertSame(['lcp' => 2765, 'inp' => 117, 'cls' => 0.12, 'fcp' => 1323], $result['field']);
        $this->assertSame(PAGESPEED_FIELD_SCOPE_URL, $result['field_scope']);
        $this->assertSame('13.6.0', $result['lighthouse_version']);
        $this->assertSame('2026-11-16T11:45:27.191Z', $result['analyzed_at']);
        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('загружалась слишком медленно', $result['warnings'][0]);
    }

    public function testParsesRealDesktopResponse(): void
    {
        $result = $this->parse('sudar-desktop.json');

        $this->assertSame(55, $result['score']);
        $this->assertSame(0.229, $result['lab']['cls']);
        $this->assertSame(['lcp' => 1229, 'inp' => 26, 'cls' => 0.16, 'fcp' => 1083], $result['field']);
        $this->assertSame([], $result['warnings']);
    }

    public function testOriginFallbackIsMarkedAsOrigin(): void
    {
        $result = $this->parse('synthetic-origin-fallback.json');

        $this->assertSame(PAGESPEED_FIELD_SCOPE_ORIGIN, $result['field_scope']);
        $this->assertSame(1742, $result['field']['lcp']);
    }

    public function testNoFieldDataGivesNull(): void
    {
        $result = $this->parse('synthetic-no-field.json');

        $this->assertNull($result['field']);
        $this->assertNull($result['field_scope']);
        $this->assertSame(39, $result['score']);
    }

    public function testRuntimeErrorIsAnError(): void
    {
        $parsed = parsePageSpeedResponse($this->fixture('synthetic-runtime-error.json'));

        $this->assertNull($parsed['result']);
        $this->assertSame('Lighthouse: NO_FCP: Страница не отрисовала контент.', $parsed['error']);
    }

    public function testResponseWithoutScoreAndMetricsIsAnError(): void
    {
        $parsed = parsePageSpeedResponse('{"lighthouseResult": {"categories": {"performance": {"score": null}}}}');

        $this->assertNull($parsed['result']);
        $this->assertSame(PAGESPEED_ERROR_EMPTY, $parsed['error']);
    }

    public function testNullScoreWithMetricsIsKept(): void
    {
        $json = json_decode($this->fixture('sudar-mobile.json'), true);
        $json['lighthouseResult']['categories']['performance']['score'] = null;

        $parsed = parsePageSpeedResponse((string) json_encode($json));

        $this->assertNull($parsed['error']);
        $this->assertNull($parsed['result']['score']);
        $this->assertSame(14326, $parsed['result']['lab']['lcp']);
    }

    public function testNotJsonIsAnError(): void
    {
        $this->assertSame(PAGESPEED_ERROR_NOT_JSON, parsePageSpeedResponse('<html>')['error']);
    }

    public function testInvalidKeyReasonFromGoogle(): void
    {
        $fetched = $this->fetchWith(400, $this->fixture('error-invalid-key.json'));

        $this->assertNull($fetched['result']);
        $this->assertSame('HTTP 400: API key not valid. Please pass a valid API key.', $fetched['error']);
    }

    public function testFailedDocumentReasonFromGoogle(): void
    {
        $fetched = $this->fetchWith(400, $this->fixture('error-failed-document.json'));

        $this->assertStringStartsWith('HTTP 400: Lighthouse returned error: FAILED_DOCUMENT_REQUEST.', (string) $fetched['error']);
        $this->assertLessThanOrEqual(PAGESPEED_ERROR_MAX_LENGTH, mb_strlen((string) $fetched['error']));
    }

    public function testTooManyRequests(): void
    {
        $this->assertSame(PAGESPEED_ERROR_TOO_MANY_REQUESTS, $this->fetchWith(429, $this->fixture('synthetic-429.json'))['error']);
    }

    public function testServerErrorWithoutJsonBody(): void
    {
        $this->assertSame('HTTP 503.', $this->fetchWith(503, 'Service Unavailable')['error']);
    }

    public function testCurlErrorIsPassedThrough(): void
    {
        $fetched = $this->fetchWith(null, '', 'Источник не ответил вовремя: Operation timed out (curl 28)');

        $this->assertSame('Источник не ответил вовремя: Operation timed out (curl 28)', $fetched['error']);
    }

    public function testKeyIsRedactedFromAnyErrorText(): void
    {
        $fetched = $this->fetchWith(null, '', 'Could not resolve https://x/?key=' . self::KEY);
        $this->assertSame('Could not resolve https://x/?key=' . PAGESPEED_REDACTED, $fetched['error']);

        $body = (string) json_encode(['error' => ['message' => 'Bad key ' . self::KEY]]);
        $this->assertStringNotContainsString(self::KEY, (string) $this->fetchWith(400, $body)['error']);

        $this->assertSame('abc', redactPageSpeedKey('abc', ''));
    }

    /**
     * @return array{result: ?array, error: ?string}
     */
    private function fetchWith(?int $status, string $body, ?string $error = null): array
    {
        return fetchPageSpeed(
            'https://sudar.su/',
            'mobile',
            self::KEY,
            static fn (string $url, array $headers): array => ['status' => $status, 'body' => $body, 'error' => $error]
        );
    }
}
