<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/src/Services/HttpClient.php';

/**
 * Без сети: DNS и transport — заглушки.
 */
final class HttpClientTest extends TestCase
{
    /** @var list<array> запросы, дошедшие до transport */
    private array $sent = [];

    private const PUBLIC_IP = '93.184.216.34';

    private function resolver(array $map = []): callable
    {
        return static fn (string $host): array => $map[$host] ?? [self::PUBLIC_IP];
    }

    /**
     * @param array<string, array> $responses url → ответ transport
     */
    private function transport(array $responses): callable
    {
        return function (array $request) use ($responses): array {
            $this->sent[] = $request;

            return $responses[$request['url']] + ['headers' => [], 'body' => '', 'truncated' => false, 'error' => null];
        };
    }

    private function redirect(int $status, string $location): array
    {
        return ['status' => $status, 'headers' => ['location' => [$location]]];
    }

    public static function redirectUrlCases(): array
    {
        $base = 'https://shop.ru/catalog/item?id=1';

        return [
            'абсолютный путь'      => [$base, '/cart', 'https://shop.ru/cart'],
            'относительный путь'   => [$base, 'other', 'https://shop.ru/catalog/other'],
            'на уровень выше'      => [$base, '../news/', 'https://shop.ru/news/'],
            'выше корня нельзя'    => [$base, '../../../x', 'https://shop.ru/x'],
            'точка'                => [$base, './', 'https://shop.ru/catalog/'],
            'только запрос'        => [$base, '?page=2', 'https://shop.ru/catalog/item?page=2'],
            'без схемы'            => [$base, '//cdn.shop.ru/a', 'https://cdn.shop.ru/a'],
            'полный адрес'         => [$base, 'http://www.shop.ru/', 'http://www.shop.ru/'],
            'фрагмент убирается'   => [$base, '/cart#top', 'https://shop.ru/cart'],
            'порт сохраняется'     => ['http://shop.ru:8080/a/b', 'c', 'http://shop.ru:8080/a/c'],
            'база без пути'        => ['https://shop.ru', 'cart', 'https://shop.ru/cart'],
            'пути с точками'       => ['https://shop.ru/a/b/c', '/x/./y/../z', 'https://shop.ru/x/z'],
            'пробелы по краям'     => [$base, '  /cart  ', 'https://shop.ru/cart'],
        ];
    }

    #[DataProvider('redirectUrlCases')]
    public function testResolveRedirectUrl(string $base, string $location, string $expected): void
    {
        $this->assertSame($expected, resolveRedirectUrl($base, $location));
    }

    public static function badRedirectCases(): array
    {
        return [
            'ftp'        => ['ftp://shop.ru/file'],
            'javascript' => ['javascript:alert(1)'],
            'пусто'      => ['   '],
            'нет хоста'  => ['https://'],
        ];
    }

    #[DataProvider('badRedirectCases')]
    public function testResolveRedirectUrlRejectsBadLocation(string $location): void
    {
        $this->assertNull(resolveRedirectUrl('https://shop.ru/', $location));
    }

    public function testRedirectChainIsFollowedAndRecorded(): void
    {
        $result = httpRequest('http://shop.ru/', 'GET', $this->resolver(), $this->transport([
            'http://shop.ru/'      => $this->redirect(301, 'https://shop.ru/'),
            'https://shop.ru/'     => $this->redirect(302, '/home'),
            'https://shop.ru/home' => ['status' => 200, 'body' => '<html>ok</html>'],
        ]));

        $this->assertNull($result['error']);
        $this->assertSame(200, $result['status']);
        $this->assertSame('https://shop.ru/home', $result['final_url']);
        $this->assertSame('<html>ok</html>', $result['body']);
        $this->assertSame(
            [
                ['url' => 'http://shop.ru/', 'status' => 301, 'location' => 'https://shop.ru/'],
                ['url' => 'https://shop.ru/', 'status' => 302, 'location' => '/home'],
            ],
            $result['redirects']
        );
        $this->assertCount(3, $this->sent);
    }

    public function testRequestGoesToCheckedIp(): void
    {
        httpRequest('https://shop.ru/', 'GET', $this->resolver(['shop.ru' => ['1.1.1.1']]), $this->transport([
            'https://shop.ru/' => ['status' => 200],
        ]));

        $this->assertSame(
            ['url' => 'https://shop.ru/', 'method' => 'GET', 'host' => 'shop.ru', 'port' => 443, 'ip' => '1.1.1.1'],
            $this->sent[0]
        );
    }

    public function testRedirectWithoutLocationIsFinal(): void
    {
        $result = httpRequest('https://shop.ru/', 'GET', $this->resolver(), $this->transport([
            'https://shop.ru/' => ['status' => 302],
        ]));

        $this->assertNull($result['error']);
        $this->assertSame(302, $result['status']);
        $this->assertSame([], $result['redirects']);
    }

    public static function forbiddenRedirectCases(): array
    {
        return [
            'loopback'  => ['http://127.0.0.1/admin', []],
            'частная'   => ['http://10.0.0.5/', []],
            'localhost' => ['http://localhost/', []],
            'DNS на частный адрес' => ['https://evil.ru/', ['evil.ru' => ['192.168.1.1']]],
            'ftp'       => ['ftp://shop.ru/', []],
        ];
    }

    #[DataProvider('forbiddenRedirectCases')]
    public function testRedirectToForbiddenTargetIsStopped(string $location, array $dns): void
    {
        $result = httpRequest('https://shop.ru/', 'GET', $this->resolver($dns), $this->transport([
            'https://shop.ru/' => $this->redirect(302, $location),
        ]));

        $this->assertNotNull($result['error']);
        $this->assertCount(1, $this->sent, 'запрос на запрещённый адрес не отправлен');
        $this->assertCount(1, $result['redirects']);
    }

    public function testForbiddenStartUrlIsNotRequested(): void
    {
        $result = httpRequest('http://192.168.0.1/', 'GET', $this->resolver(), $this->transport([]));

        $this->assertNotNull($result['error']);
        $this->assertNull($result['status']);
        $this->assertSame([], $this->sent);
    }

    public function testUnresolvableHostIsError(): void
    {
        $result = httpRequest('https://nope.ru/', 'GET', static fn (string $host): array => [], $this->transport([]));

        $this->assertNotNull($result['error']);
        $this->assertSame([], $this->sent);
    }

    public function testTooManyRedirectsStops(): void
    {
        $responses = [];
        for ($i = 0; $i <= HTTP_MAX_REDIRECTS + 5; $i++) {
            $responses["https://shop.ru/{$i}"] = $this->redirect(302, '/' . ($i + 1));
        }

        $result = httpRequest('https://shop.ru/0', 'GET', $this->resolver(), $this->transport($responses));

        $this->assertStringContainsString('Слишком много редиректов', (string) $result['error']);
        $this->assertCount(HTTP_MAX_REDIRECTS + 1, $this->sent);
    }

    public function testRedirectLoopStops(): void
    {
        $result = httpRequest('https://shop.ru/a', 'GET', $this->resolver(), $this->transport([
            'https://shop.ru/a' => $this->redirect(302, '/b'),
            'https://shop.ru/b' => $this->redirect(302, '/a'),
        ]));

        $this->assertNotNull($result['error']);
        $this->assertCount(HTTP_MAX_REDIRECTS + 1, $this->sent);
    }

    public function testTransportErrorIsReturned(): void
    {
        $result = httpRequest('https://shop.ru/', 'GET', $this->resolver(), $this->transport([
            'https://shop.ru/' => ['status' => null, 'error' => 'Сайт не ответил за 30 с (curl 28).'],
        ]));

        $this->assertSame('Сайт не ответил за 30 с (curl 28).', $result['error']);
        $this->assertNull($result['status']);
    }

    public function testTruncatedFlagIsPassedThrough(): void
    {
        $result = httpRequest('https://shop.ru/', 'GET', $this->resolver(), $this->transport([
            'https://shop.ru/' => ['status' => 200, 'body' => 'abc', 'truncated' => true],
        ]));

        $this->assertNull($result['error']);
        $this->assertTrue($result['truncated']);
    }

    public function testUnsupportedMethodIsRejected(): void
    {
        $result = httpRequest('https://shop.ru/', 'POST', $this->resolver(), $this->transport([]));

        $this->assertNotNull($result['error']);
        $this->assertSame([], $this->sent);
    }

    public function testHeadMethodIsPassed(): void
    {
        httpRequest('https://shop.ru/', 'head', $this->resolver(), $this->transport([
            'https://shop.ru/' => ['status' => 200],
        ]));

        $this->assertSame('HEAD', $this->sent[0]['method']);
    }

    public function testMaxBytesIsPassedToTransport(): void
    {
        httpRequest('https://shop.ru/.env', 'GET', $this->resolver(), $this->transport([
            'https://shop.ru/.env' => ['status' => 206],
        ]), 4096);

        $this->assertSame(4096, $this->sent[0]['max_bytes']);
    }

    public function testWithoutMaxBytesRequestHasNoLimit(): void
    {
        httpRequest('https://shop.ru/', 'GET', $this->resolver(), $this->transport([
            'https://shop.ru/' => ['status' => 200],
        ]));

        $this->assertArrayNotHasKey('max_bytes', $this->sent[0]);
    }

    public function testRequestHeadersWithLimitAskForRangeWithoutCompression(): void
    {
        $headers = buildRequestHeaders(4096);

        $this->assertContains('Range: bytes=0-4095', $headers);
        $this->assertContains('Accept-Encoding: identity', $headers);
    }

    public function testRequestHeadersWithoutLimitHaveNoRange(): void
    {
        foreach (buildRequestHeaders(null) as $header) {
            $this->assertStringStartsNotWith('Range:', $header);
            $this->assertStringStartsNotWith('Accept-Encoding:', $header);
        }
    }

    public function testLimitBodyChunkStopsAtLimit(): void
    {
        $this->assertSame(['chunk' => 'cd', 'truncated' => false], limitBodyChunk(2, 'cd', 4));
        $this->assertSame(['chunk' => 'cd', 'truncated' => true], limitBodyChunk(2, 'cdef', 4));
        $this->assertSame(['chunk' => '', 'truncated' => true], limitBodyChunk(4, 'e', 4));
    }

    public function testBodyNeverExceedsLimit(): void
    {
        $body = '';
        $truncated = false;
        $chunk = str_repeat('x', 1000);

        while (!$truncated) {
            $limited = limitBodyChunk(strlen($body), $chunk, 2500);
            $body .= $limited['chunk'];
            $truncated = $limited['truncated'];
        }

        $this->assertSame(2500, strlen($body));
    }

    public function testAddHeaderLine(): void
    {
        $headers = addHeaderLine([], "HTTP/1.1 301 Moved\r\n");
        $headers = addHeaderLine($headers, "Location: https://shop.ru/\r\n");
        $headers = addHeaderLine($headers, "Set-Cookie: a=1\r\n");
        $headers = addHeaderLine($headers, "set-cookie: b=2\r\n");
        $headers = addHeaderLine($headers, "\r\n");

        $this->assertSame(
            ['location' => ['https://shop.ru/'], 'set-cookie' => ['a=1', 'b=2']],
            $headers
        );
        $this->assertSame('https://shop.ru/', getHeaderValue($headers, 'Location'));
        $this->assertNull(getHeaderValue($headers, 'X-Missing'));
    }

    public function testNewStatusLineResetsHeaders(): void
    {
        $headers = addHeaderLine([], 'HTTP/1.1 100 Continue');
        $headers = addHeaderLine($headers, 'X-Old: 1');
        $headers = addHeaderLine($headers, 'HTTP/1.1 200 OK');
        $headers = addHeaderLine($headers, 'Server: nginx');

        $this->assertSame(['server' => ['nginx']], $headers);
    }
}
