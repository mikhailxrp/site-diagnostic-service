<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/src/Services/Checks/BotProtectionCheck.php';

/**
 * Без сети: заглушки защиты и настоящие страницы — синтетические фикстуры.
 */
final class BotProtectionCheckTest extends TestCase
{
    private const FIXTURES = ROOT_PATH . '/tests/fixtures/bot-protection/';

    /**
     * @param array<string, list<string>> $headers
     */
    private function response(string $body, int $status = 200, array $headers = []): array
    {
        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES . $name);
    }

    /**
     * @return array<string, array{string, int, string}>
     */
    public static function challengePages(): array
    {
        return [
            'Cloudflare challenge' => ['cloudflare-challenge.html', 403, 'cloudflare'],
            'DDoS-Guard'           => ['ddos-guard-challenge.html', 200, 'ddos-guard'],
            'Qrator'               => ['qrator-challenge.html', 401, 'qrator'],
            'капча'                => ['captcha.html', 200, 'captcha'],
        ];
    }

    #[DataProvider('challengePages')]
    public function testChallengePageIsRecognized(string $fixture, int $status, string $provider): void
    {
        $result = detectBotProtection($this->response($this->fixture($fixture), $status));

        $this->assertNotNull($result);
        $this->assertSame($provider, $result['provider']);
        $this->assertNotSame('', $result['title']);
        $this->assertNotSame('', $result['reason']);
    }

    public function testCloudflareChallengeHeaderIsEnough(): void
    {
        $result = detectBotProtection($this->response('', 403, ['cf-mitigated' => ['challenge']]));

        $this->assertSame('cloudflare', $result['provider'] ?? null);
        $this->assertStringContainsString('cf-mitigated', (string) ($result['reason'] ?? ''));
    }

    public function testRealPageBehindDdosGuardIsNotBlocked(): void
    {
        $response = $this->response($this->fixture('ddos-guard-real-page.html'), 200, ['server' => ['ddos-guard']]);

        $this->assertNull(detectBotProtection($response));
    }

    public function testRealPageWithCaptchaInFormIsNotBlocked(): void
    {
        $this->assertNull(detectBotProtection($this->response($this->fixture('page-with-recaptcha.html'))));
    }

    /**
     * Регрессия sudar.su: пустая корзина почти без текста, скрипт капчи подключён на всех страницах, виджета нет.
     */
    public function testThinPageWithCaptchaOnlyInScriptsIsNotBlocked(): void
    {
        $this->assertNull(detectBotProtection($this->response($this->fixture('empty-cart-with-captcha-script.html'))));
    }

    /**
     * @return array<string, array{string, int, array<string, list<string>>}>
     */
    public static function ordinaryResponses(): array
    {
        return [
            '403 без тела'          => ['', 403, []],
            '503 с текстом ошибки'  => ['<h1>Service Unavailable</h1><p>Try again later.</p>', 503, []],
            'заголовки CDN'         => ['<p>Обычная страница</p>', 200, ['server' => ['cloudflare'], 'cf-ray' => ['8a1']]],
            'битый HTML'            => ['<div><p>текст<span', 200, []],
        ];
    }

    /**
     * @param array<string, list<string>> $headers
     */
    #[DataProvider('ordinaryResponses')]
    public function testCodeAndCdnHeadersAloneAreNotBlocked(string $body, int $status, array $headers): void
    {
        $this->assertNull(detectBotProtection($this->response($body, $status, $headers)));
    }

    public function testRulesCanBePassedAsParameter(): void
    {
        $rules = ['thin_page_max_text' => 10, 'providers' => [
            'custom' => ['title' => 'Своя защита', 'headers' => [], 'html' => ['x-guard-wall'], 'thin_html' => []],
        ]];

        $this->assertSame('Своя защита', detectBotProtection($this->response('<div id="x-guard-wall"></div>'), $rules)['title'] ?? null);
        $this->assertNull(detectBotProtection($this->response($this->fixture('cloudflare-challenge.html')), $rules));
    }

    public function testWindows1251PageIsMeasuredWithoutErrors(): void
    {
        $text = (string) mb_convert_encoding(str_repeat('Обычный текст страницы магазина. ', 100), 'Windows-1251', 'UTF-8');
        $html = '<html><head><meta charset="windows-1251"></head><body><p>' . $text . '</p>'
            . '<script src="/.well-known/ddos-guard/id/1"></script></body></html>';

        $this->assertNull(detectBotProtection($this->response($html)));
    }
}
