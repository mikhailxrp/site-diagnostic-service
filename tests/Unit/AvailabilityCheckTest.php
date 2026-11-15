<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/src/Services/DiagnosticRunner.php';

/**
 * Без сети: ответы `httpRequest` и сертификат — заготовки.
 */
final class AvailabilityCheckTest extends TestCase
{
    /** @var list<string> адреса, которые запросила проверка */
    private array $requested = [];

    /** @var list<string> хосты, у которых проверялся сертификат */
    private array $sslHosts = [];

    /** @var list<bool> доверие к цепочке, переданное в проверку сертификата */
    private array $sslTrusted = [];

    private function context(string $url = 'https://sudar.su/'): array
    {
        return buildStepContext(['id' => 1, 'url' => $url, 'host' => 'sudar.su', 'problem_text' => null]);
    }

    private function ok(string $finalUrl, int $status = 200, string $body = '<html></html>'): array
    {
        return [
            'final_url' => $finalUrl,
            'status'    => $status,
            'headers'   => ['server' => ['nginx']],
            'body'      => $body,
            'truncated' => false,
            'redirects' => [],
            'time_ms'   => 120,
            'error'     => null,
        ];
    }

    private function redirected(string $from, string $to): array
    {
        return ['redirects' => [['url' => $from, 'status' => 301, 'location' => $to]]] + $this->ok($to);
    }

    private function noResponse(string $url, string $error = 'Сайт не ответил вовремя (curl 28)'): array
    {
        return [
            'final_url' => $url,
            'status'    => null,
            'headers'   => [],
            'body'      => '',
            'truncated' => false,
            'redirects' => [],
            'time_ms'   => 30000,
            'error'     => $error,
        ];
    }

    /**
     * Обычный сайт: всё ведёт на https://sudar.su/.
     */
    private function healthySite(): array
    {
        return [
            'http://sudar.su/'      => $this->redirected('http://sudar.su/', 'https://sudar.su/'),
            'https://sudar.su/'     => $this->ok('https://sudar.su/'),
            'http://www.sudar.su/'  => $this->redirected('http://www.sudar.su/', 'https://sudar.su/'),
            'https://www.sudar.su/' => $this->redirected('https://www.sudar.su/', 'https://sudar.su/'),
        ];
    }

    private function goodSsl(): array
    {
        return ['facts' => ['host' => 'sudar.su', 'error' => null, 'days_left' => 60], 'findings' => []];
    }

    private function check(array $responses, ?array $ssl = null, ?string $url = null): array
    {
        return runAvailabilityCheck(
            $this->context($url ?? 'https://sudar.su/'),
            function (string $url) use ($responses): array {
                $this->requested[] = $url;

                return $responses[$url] ?? $this->noResponse($url, 'Сайт не найден: у домена нет IP-адреса.');
            },
            function (string $host, bool $trusted) use ($ssl): array {
                $this->sslHosts[] = $host;
                $this->sslTrusted[] = $trusted;

                return $ssl ?? $this->goodSsl();
            }
        );
    }

    private function findingKeys(array $result): array
    {
        return array_map(
            static fn (array $finding): string => $finding['category'] . ':' . $finding['severity'],
            $result['findings']
        );
    }

    public function testFourVariants(): void
    {
        $this->assertSame(
            [
                'http'      => 'http://sudar.su/',
                'https'     => 'https://sudar.su/',
                'http_www'  => 'http://www.sudar.su/',
                'https_www' => 'https://www.sudar.su/',
            ],
            buildAvailabilityVariants('www.sudar.su')
        );
    }

    public function testHealthySiteHasNoFindings(): void
    {
        $result = $this->check($this->healthySite());

        $this->assertSame([], $result['findings']);
        $this->assertFalse($result['stop']);
        $this->assertSame('done', $result['status']);
        $this->assertTrue($result['summary']['availability']['https_redirect']);
        $this->assertTrue($result['summary']['availability']['www_consistent']);
        $this->assertSame(120, $result['summary']['availability']['response_ms']);
        $this->assertSame(['sudar.su'], $this->sslHosts);
    }

    public function testHomeIsNotRequestedTwice(): void
    {
        $this->check($this->healthySite());

        $this->assertSame(
            ['https://sudar.su/', 'http://sudar.su/', 'http://www.sudar.su/', 'https://www.sudar.su/'],
            $this->requested
        );
    }

    public function testHomeHtmlIsInFactsButNotInSummary(): void
    {
        $site = $this->healthySite();
        $site['https://sudar.su/'] = $this->ok('https://sudar.su/', 200, '<html>магазин</html>');

        $result = $this->check($site);

        $this->assertSame('<html>магазин</html>', $result['facts']['home']['body']);
        $this->assertSame(['server' => ['nginx']], $result['facts']['home']['headers']);
        $this->assertArrayNotHasKey('body', $result['summary']['availability']['home']);
        $this->assertArrayNotHasKey('headers', $result['summary']['availability']['home']);
    }

    public function testNoResponseIsCriticalAndStops(): void
    {
        $result = $this->check([]);

        $this->assertTrue($result['stop']);
        $this->assertSame(['availability:critical'], $this->findingKeys($result));
        $this->assertSame('Сайт не отвечает', $result['findings'][0]['title']);
        $this->assertSame(['https://sudar.su/'], $this->requested, 'после отказа варианты не запрашиваются');
        $this->assertSame([], $result['facts']['variants']);
    }

    public function testNoResponseOverHttpsStillInspectsCertificate(): void
    {
        $expired = makeFinding('ssl', 'SSL-сертификат истёк', 'https://sudar.su/', 'expired', null, null, null, 'critical');
        $result = $this->check([], ['facts' => ['host' => 'sudar.su', 'error' => null], 'findings' => [$expired]]);

        $this->assertSame(['availability:critical', 'ssl:critical'], $this->findingKeys($result));
        $this->assertSame(['sudar.su'], $this->sslHosts);
    }

    public function testNoResponseOverHttpSkipsCertificate(): void
    {
        $this->check([], null, 'http://sudar.su/');

        $this->assertSame([], $this->sslHosts);
    }

    public function testTooManyRedirectsIsCritical(): void
    {
        $loop = ['status' => 301, 'error' => 'Слишком много редиректов: больше 10.'] + $this->ok('https://sudar.su/');
        $result = $this->check(['https://sudar.su/' => $loop]);

        $this->assertTrue($result['stop']);
        $this->assertSame('Главная страница не открывается', $result['findings'][0]['title']);
    }

    public function testServerErrorIsHigh(): void
    {
        $site = $this->healthySite();
        $site['https://sudar.su/'] = $this->ok('https://sudar.su/', 500);

        $result = $this->check($site);

        $this->assertSame(['availability:high'], $this->findingKeys($result));
        $this->assertFalse($result['stop']);
    }

    public function testBotProtectionCodesOnlyInFacts(): void
    {
        foreach ([403, 503] as $status) {
            $site = $this->healthySite();
            $site['https://sudar.su/'] = $this->ok('https://sudar.su/', $status);

            $result = $this->check($site);

            $this->assertSame([], $result['findings'], "код {$status}");
            $this->assertTrue($result['facts']['home']['possible_bot_protection']);
        }
    }

    public function testNoHttpsRedirect(): void
    {
        $site = $this->healthySite();
        $site['http://sudar.su/'] = $this->ok('http://sudar.su/');

        $result = $this->check($site);

        $this->assertSame(['ssl:medium'], $this->findingKeys($result));
        $this->assertSame('Нет редиректа с http на https', $result['findings'][0]['title']);
        $this->assertFalse($result['summary']['availability']['https_redirect']);
    }

    public function testHttpsUnavailable(): void
    {
        $site = [
            'http://sudar.su/'     => $this->ok('http://sudar.su/'),
            'http://www.sudar.su/' => $this->redirected('http://www.sudar.su/', 'http://sudar.su/'),
        ];
        $ssl = ['facts' => ['host' => 'sudar.su', 'error' => 'Connection refused'], 'findings' => []];

        $result = $this->check($site, $ssl, 'http://sudar.su/');

        $this->assertSame(['ssl:high'], $this->findingKeys($result));
        $this->assertSame('Сайт не открывается по https', $result['findings'][0]['title']);
        $this->assertNull($result['summary']['availability']['https_redirect'], 'https нет — редиректу некуда вести');
    }

    public function testWwwMismatch(): void
    {
        $site = $this->healthySite();
        $site['https://www.sudar.su/'] = $this->ok('https://www.sudar.su/');
        $site['http://www.sudar.su/'] = $this->redirected('http://www.sudar.su/', 'https://www.sudar.su/');

        $result = $this->check($site);

        $this->assertSame(['seo:medium'], $this->findingKeys($result));
        $this->assertFalse($result['summary']['availability']['www_consistent']);
    }

    public function testWwwWithoutDnsIsNotAFinding(): void
    {
        $site = $this->healthySite();
        unset($site['http://www.sudar.su/'], $site['https://www.sudar.su/']);

        $result = $this->check($site);

        $this->assertSame([], $result['findings']);
        $this->assertNull($result['summary']['availability']['www_consistent']);
        $this->assertNotNull($result['facts']['variants']['https_www']['error']);
    }

    public function testSslFindingsAreIncluded(): void
    {
        $expiring = makeFinding('ssl', 'Истекает', 'https://sudar.su/', 'expiring', null, null, null, 'medium');
        $result = $this->check($this->healthySite(), ['facts' => ['error' => null], 'findings' => [$expiring]]);

        $this->assertSame(['ssl:medium'], $this->findingKeys($result));
    }

    public function testHealthyHttpsIsTrusted(): void
    {
        $this->check($this->healthySite());

        $this->assertSame([true], $this->sslTrusted);
    }

    public function testCurlCertificateErrorIsUntrusted(): void
    {
        $result = $this->check(['https://sudar.su/' => $this->noResponse('https://sudar.su/', 'SSL certificate problem (curl 60)')]);

        $this->assertSame([false], $this->sslTrusted);
        $this->assertSame('Сайт не открывается: браузер не доверяет SSL-сертификату', $result['findings'][0]['title']);
    }

    public function testCertificateErrorOnOtherHostIsIgnored(): void
    {
        $site = $this->healthySite();
        $site['https://sudar.su/'] = $this->ok('http://sudar.su/');
        $site['https://www.sudar.su/'] = $this->noResponse('https://www.sudar.su/', 'unable to get local issuer (curl 60)');

        $this->check($site, null, 'http://sudar.su/');

        $this->assertSame([true], $this->sslTrusted, 'https на sudar.su открылся, ошибка у www — другой хост');
    }

    public function testTimeoutIsNotUntrusted(): void
    {
        $this->check([]);

        $this->assertSame([true], $this->sslTrusted, 'по тайм-ауту о цепочке судить нельзя');
    }

    public function testAssignPageIdToHomeFindings(): void
    {
        $findings = [
            makeFinding('availability', 'Главная', 'https://SUDAR.su/#top', '500'),
            makeFinding('ssl', 'http', 'http://sudar.su/', 'no_https_redirect'),
        ];

        $result = assignPageIdToFindings($findings, 42, ['https://sudar.su/', 'https://sudar.su/']);

        $this->assertSame(42, $result[0]['page_id']);
        $this->assertNull($result[1]['page_id']);
    }

    public function testStopSkipsLaterStepsInRunner(): void
    {
        $steps = [
            ['code' => 'availability', 'title' => 'Доступность сайта', 'handler' => fn (array $context): array => $this->check([])],
            ['code' => 'stack', 'title' => 'Стек', 'handler' => static fn (): array => ['status' => 'done']],
        ];
        $finished = [];

        executeSteps(
            $steps,
            $this->context(),
            static function (): void {
            },
            static function (array $step, string $status, ?string $message, int $progress) use (&$finished): void {
                $finished[] = [$step['code'], $status, $progress];
            }
        );

        $this->assertSame([['availability', 'done', 50], ['stack', 'skipped', 100]], $finished);
    }
}
