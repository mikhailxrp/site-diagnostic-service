<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/src/Services/DiagnosticRunner.php';

/**
 * Без сети: ответы — заготовки по адресу, на остальные адреса (и на случайный эталон) — ответ по умолчанию.
 */
final class ExposedFilesCheckTest extends TestCase
{
    private const HOME = 'https://shop.test/';
    private const ORIGIN = 'https://shop.test';
    private const SECRET = 'S3cr3t-Value-42';
    private const HOME_HTML = '<!DOCTYPE html><html><head><title>Магазин</title></head><body>Главная</body></html>';

    /** @var list<array{url: string, max: int}> */
    private array $requested = [];

    private function context(?array $home = []): array
    {
        $context = buildStepContext(['id' => 1, 'url' => self::HOME, 'host' => 'shop.test', 'problem_text' => null]);
        if ($home !== null) {
            $context['facts']['availability']['home'] = $home + [
                'url'       => self::HOME,
                'final_url' => self::HOME,
                'status'    => 200,
                'headers'   => [],
                'body'      => self::HOME_HTML,
            ];
        }

        return $context;
    }

    private function response(int $status, string $body, array $headers = [], ?string $finalUrl = null): array
    {
        return [
            'final_url' => $finalUrl,
            'status'    => $status,
            'headers'   => $headers,
            'body'      => $body,
            'truncated' => false,
            'redirects' => [],
            'time_ms'   => 1,
            'error'     => null,
        ];
    }

    /**
     * @param array<string, array> $responses путь от корня → ответ
     */
    private function request(array $responses, array $default): callable
    {
        return function (string $url, int $maxBytes) use ($responses, $default): array {
            $this->requested[] = ['url' => $url, 'max' => $maxBytes];
            $path = substr($url, strlen(self::ORIGIN) + 1);
            $response = $responses[$path] ?? $default;
            $response['final_url'] ??= $url;

            return $response;
        };
    }

    private function check(array $responses, ?array $default = null, ?callable $onRequest = null): array
    {
        return runExposedFilesCheck(
            $this->context(),
            $this->request($responses, $default ?? $this->response(404, 'Not found')),
            $onRequest
        );
    }

    private function totalPaths(): int
    {
        return array_sum(array_map(static fn (array $group): int => count($group['files']), loadExposedFilesRules()['groups']));
    }

    public function testSiteAnsweringHomeOnEveryUrlGivesNoFindings(): void
    {
        $result = $this->check([], $this->response(200, self::HOME_HTML, ['content-type' => ['text/html']]));

        $this->assertSame('done', $result['status']);
        $this->assertSame([], $result['findings']);
        $this->assertSame($this->totalPaths(), $result['facts']['checked']);
    }

    public function testHtmlStubOnRightPathGivesNoFinding(): void
    {
        $stub = "<!doctype html><html><body>\nAPP_KEY=nothing\n<p>Страница /.env не найдена</p></body></html>";

        $result = $this->check(['.env' => $this->response(200, $stub)]);

        $this->assertSame([], $result['findings']);
    }

    public function testSameTextOnEveryUrlIsNotAFile(): void
    {
        $result = $this->check([], $this->response(200, "APP_ENV=production\n"));

        $this->assertSame([], $result['findings']);
    }

    public function testOpenEnvAndGitAreCritical(): void
    {
        $result = $this->check([
            '.env'      => $this->response(200, "APP_ENV=production\nDB_PASSWORD=" . self::SECRET . "\n"),
            '.git/HEAD' => $this->response(200, "ref: refs/heads/master\n"),
        ]);

        $findings = array_column($result['findings'], null, 'location');
        $this->assertCount(2, $findings);
        $this->assertSame('critical', $findings[self::ORIGIN . '/.env']['severity']);
        $this->assertSame('exposed_file', $findings[self::ORIGIN . '/.env']['category']);
        $this->assertSame('critical', $findings[self::ORIGIN . '/.git/HEAD']['severity']);
        $this->assertSame('git_head', $findings[self::ORIGIN . '/.git/HEAD']['evidence']['signature']);
    }

    public function testSecretValuesAreNotStored(): void
    {
        $result = $this->check([
            '.env' => $this->response(200, 'DB_PASSWORD=' . self::SECRET, ['content-type' => ['text/plain']]),
        ]);

        $this->assertCount(1, $result['findings']);
        $this->assertSame(
            ['status', 'bytes_read', 'content_type', 'signature'],
            array_keys($result['findings'][0]['evidence'])
        );
        $this->assertSame('env', $result['findings'][0]['evidence']['signature']);
        $this->assertStringNotContainsString(self::SECRET, (string) json_encode($result, JSON_UNESCAPED_UNICODE));
    }

    public function testEveryRequestIsLimitedAndReported(): void
    {
        $calls = 0;
        $this->check([], null, static function () use (&$calls): void {
            $calls++;
        });

        $this->assertCount($this->totalPaths() + 1, $this->requested); // + эталон
        $this->assertSame($calls, count($this->requested));
        foreach ($this->requested as $request) {
            $this->assertSame(EXPOSED_FILE_MAX_BYTES, $request['max']);
        }
    }

    public function testBytesReadNeverExceedsLimit(): void
    {
        $dump = "-- MySQL dump 10.13\n" . str_repeat('x', EXPOSED_FILE_MAX_BYTES * 2);

        $result = $this->check(['dump.sql' => $this->response(200, $dump)]);

        $this->assertCount(1, $result['findings']);
        $this->assertSame(EXPOSED_FILE_MAX_BYTES, $result['findings'][0]['evidence']['bytes_read']);
    }

    public function testRedirectToAnotherPathIsNotAFile(): void
    {
        $result = $this->check([
            '.env' => $this->response(200, 'APP_ENV=production', [], self::ORIGIN . '/404/'),
        ]);

        $this->assertSame([], $result['findings']);
    }

    public function testArchivePhpinfoAndLogAreFound(): void
    {
        $phpinfo = '<!DOCTYPE html><html><head><title>PHP 8.3.0 - phpinfo()</title></head>'
            . '<body><h1 class="p">PHP Version 8.3.0</h1></body></html>';

        $result = $this->check([
            'backup.zip'  => $this->response(206, "PK\x03\x04" . str_repeat("\0", 20)),
            'phpinfo.php' => $this->response(200, $phpinfo),
            'error_log'   => $this->response(200, "[16-Nov-2026 10:00:00 UTC] PHP Warning:  Undefined variable\n"),
        ]);

        $severities = array_column($result['findings'], 'severity', 'location');
        $this->assertSame([
            self::ORIGIN . '/backup.zip'  => 'critical',
            self::ORIGIN . '/phpinfo.php' => 'high',
            self::ORIGIN . '/error_log'   => 'medium',
        ], $severities);
    }

    public function testNotFoundStatusIsNotAFile(): void
    {
        $result = $this->check(['.env' => $this->response(403, 'APP_ENV=production')]);

        $this->assertSame([], $result['findings']);
    }

    public function testBotProtectionSkipsStep(): void
    {
        $botRules = ['providers' => ['guard' => [
            'title'   => 'Guard',
            'headers' => ['x-guard' => ['challenge']],
            'html'    => [],
        ]]];

        $result = runExposedFilesCheck(
            $this->context(),
            $this->request([], $this->response(403, 'wait', ['x-guard' => ['challenge']])),
            null,
            null,
            $botRules
        );

        $this->assertSame('skipped', $result['status']);
        $this->assertStringContainsString('Guard', $result['message']);
        $this->assertSame([], $result['findings']);
        $this->assertCount(1, $this->requested);
    }

    public function testNoAnswerOnBaselineSkipsStep(): void
    {
        $result = $this->check([], ['final_url' => null, 'status' => null, 'body' => '', 'error' => 'timeout']);

        $this->assertSame('skipped', $result['status']);
        $this->assertCount(1, $this->requested);
    }

    public function testWithoutHomeStepIsSkipped(): void
    {
        $result = runExposedFilesCheck($this->context(null), $this->request([], $this->response(404, '')));

        $this->assertSame('skipped', $result['status']);
        $this->assertSame([], $this->requested);
    }

    public function testRulesFileIsConsistent(): void
    {
        $rules = loadExposedFilesRules();

        foreach ($rules['groups'] as $group) {
            $this->assertContains($group['severity'], FINDING_SEVERITIES);
            foreach ($group['files'] as $signature) {
                $this->assertArrayHasKey($signature, $rules['signatures']);
            }
        }
        foreach ($rules['signatures'] as $signature) {
            foreach ([...$signature['require'], ...$signature['any']] as $pattern) {
                $this->assertIsInt(preg_match($pattern, ''), $pattern);
            }
        }
    }
}
