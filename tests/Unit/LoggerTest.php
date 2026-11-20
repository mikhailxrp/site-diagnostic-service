<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

require_once ROOT_PATH . '/src/Core/Logger.php';
require_once ROOT_PATH . '/src/Services/AiClient.php';
require_once ROOT_PATH . '/src/Services/Checks/SpeedCheck.php';
require_once ROOT_PATH . '/src/Services/VulnerabilityDatabases.php';

final class LoggerTest extends TestCase
{
    /** @var array<string, array{has_env: bool, env_val: mixed, putenv_val: string|false}> */
    private array $savedEnv = [];

    private string $tmpLog;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (LOG_SECRET_ENV_NAMES as $name) {
            $this->savedEnv[$name] = [
                'has_env'    => array_key_exists($name, $_ENV),
                'env_val'    => $_ENV[$name] ?? null,
                'putenv_val' => getenv($name),
            ];
        }

        $this->tmpLog = sys_get_temp_dir() . '/sd-logger-test-' . bin2hex(random_bytes(4)) . '.log';
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $state) {
            if ($state['has_env']) {
                $_ENV[$name] = $state['env_val'];
            } else {
                unset($_ENV[$name]);
            }

            if ($state['putenv_val'] === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $state['putenv_val']);
            }
        }

        if (is_file($this->tmpLog)) {
            unlink($this->tmpLog);
        }

        parent::tearDown();
    }

    private function setTestSecret(string $name, string $value): void
    {
        $_ENV[$name] = $value;
        putenv($name . '=' . $value);
    }

    public function testRedactKnownSecretsMasksLongSecretsAndIgnoresShortOnes(): void
    {
        $this->setTestSecret('ROUTERAI_API_KEY', 'sk-long-router-secret-123');
        $this->setTestSecret('PAGESPEED_API_KEY', 'short77');
        $this->setTestSecret('WORDFENCE_API_KEY', 'wf-token-abcdef123');
        $this->setTestSecret('DB_PASS', 'db-pass-secret-999');

        $input = 'router=sk-long-router-secret-123 page=short77 wf=wf-token-abcdef123 db=db-pass-secret-999';
        $redacted = redactKnownSecrets($input);

        $this->assertSame(
            'router=[REDACTED] page=short77 wf=[REDACTED] db=[REDACTED]',
            $redacted
        );
    }

    public function testSanitizeLogContextRedactsKeysStringsTracesAndThrowables(): void
    {
        $secret = 'sk-context-secret-99887766';
        $this->setTestSecret('ROUTERAI_API_KEY', $secret);

        $sanitized = sanitizeLogContext([
            'api_key' => 'anything',
            'token'   => 'anything',
            'nested'  => ['msg' => "Failed with {$secret}"],
            'trace'   => ["#0 file.php(10): call('{$secret}')"],
            'cause'   => new RuntimeException("Wrapped {$secret}"),
        ]);

        $this->assertSame(LOG_REDACTED, $sanitized['api_key']);
        $this->assertSame(LOG_REDACTED, $sanitized['token']);
        $this->assertSame('Failed with [REDACTED]', $sanitized['nested']['msg']);
        $this->assertSame(["#0 file.php(10): call('[REDACTED]')"], $sanitized['trace']);
        $this->assertSame('RuntimeException: Wrapped [REDACTED]', $sanitized['cause']);
    }

    public function testExceptionInsideSendAiRequestAndCheckAiConnectionDoesNotLeakKey(): void
    {
        $key = 'sk-secret-trace-test-1234567890';
        $prefix = substr($key, 0, 15);
        $this->setTestSecret('ROUTERAI_API_KEY', $key);

        $throwingApi = static function () use ($key): array {
            throw new RuntimeException("Transport exploded for {$key}");
        };

        $caught = null;
        try {
            checkAiConnection(
                'https://routerai.ru/api/v1',
                'qwen/qwen4-max',
                $key,
                $throwingApi,
                static fn (): null => null
            );
        } catch (Throwable $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertStringNotContainsString($prefix, $caught->getTraceAsString());
        $this->assertStringNotContainsString($key, $caught->getTraceAsString());

        logException($caught, ['diagnostic_id' => 1], $this->tmpLog);
        $written = (string) file_get_contents($this->tmpLog);

        $this->assertStringContainsString(LOG_REDACTED, $written);
        $this->assertStringNotContainsString($prefix, $written);
        $this->assertStringNotContainsString($key, $written);
    }

    public function testExceptionInsideSpeedCheckDoesNotLeakKey(): void
    {
        $key = 'AIza-secret-pagespeed-key-987654';
        $prefix = substr($key, 0, 15);
        $this->setTestSecret('PAGESPEED_API_KEY', $key);

        $context = [
            'diagnostic' => ['id' => 1, 'url' => 'https://shop.test/'],
            'facts'      => ['availability' => ['home' => ['final_url' => 'https://shop.test/', 'status' => 200]]],
        ];
        $throwingApi = static function () use ($key): array {
            throw new RuntimeException("PageSpeed transport error with {$key}");
        };

        $caught = null;
        try {
            runSpeedCheck($context, $key, $throwingApi);
        } catch (Throwable $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertStringNotContainsString($prefix, $caught->getTraceAsString());
        $this->assertStringNotContainsString($key, $caught->getTraceAsString());

        logException($caught, ['diagnostic_id' => 2], $this->tmpLog);
        $written = (string) file_get_contents($this->tmpLog);

        $this->assertStringContainsString(LOG_REDACTED, $written);
        $this->assertStringNotContainsString($prefix, $written);
        $this->assertStringNotContainsString($key, $written);
    }

    public function testExceptionInsideUpdateWordfenceDatabaseDoesNotLeakToken(): void
    {
        $token = 'wf-secret-token-trace-123456789';
        $prefix = substr($token, 0, 15);
        $this->setTestSecret('WORDFENCE_API_KEY', $token);

        $tmpDownload = sys_get_temp_dir() . '/sd-wf-test-' . bin2hex(random_bytes(4)) . '.json';
        $throwingDownload = static function () use ($token): array {
            throw new RuntimeException("Download failed with {$token}");
        };

        $caught = null;
        try {
            updateWordfenceDatabase($token, $throwingDownload, $tmpDownload);
        } catch (Throwable $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertStringNotContainsString($prefix, $caught->getTraceAsString());
        $this->assertStringNotContainsString($token, $caught->getTraceAsString());

        logException($caught, ['service' => 'wordfence'], $this->tmpLog);
        $written = (string) file_get_contents($this->tmpLog);

        $this->assertStringContainsString(LOG_REDACTED, $written);
        $this->assertStringNotContainsString($prefix, $written);
        $this->assertStringNotContainsString($token, $written);
    }
}
