<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/src/Services/AiModels.php';

final class AiModelsTest extends TestCase
{
    private const NOW = 1_794_800_000;

    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/ai-models-test-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->cacheDir)) {
            rmdir($this->cacheDir);
        }
    }

    private function cacheFile(): string
    {
        return $this->cacheDir . '/models.json';
    }

    private static function listBody(): string
    {
        return (string) file_get_contents(ROOT_PATH . '/tests/fixtures/routerai/models.json');
    }

    private static function fetchOk(int &$calls): callable
    {
        return static function () use (&$calls): array {
            $calls++;

            return ['status' => 200, 'body' => self::listBody(), 'error' => null];
        };
    }

    private static function fetchFail(int &$calls): callable
    {
        return static function () use (&$calls): array {
            $calls++;

            return ['status' => null, 'body' => '', 'error' => 'Could not resolve host (curl 6)'];
        };
    }

    public function testParsesCapabilitiesAndPrices(): void
    {
        $models = parseAiModelsList(self::listBody());

        $qwen = $models['qwen/qwen4-max'];
        $this->assertSame('Qwen: Qwen4 Max', $qwen['name']);
        $this->assertTrue($qwen['images']);
        $this->assertTrue($qwen['json_schema']);
        $this->assertSame(16020, $qwen['price_in_kopecks']);   // 0,00016019562 ₽ за токен → 160,20 ₽ за 1 млн
        $this->assertSame(48059, $qwen['price_out_kopecks']);

        $this->assertFalse($models['qwen/qwen3.7-max']['images']);
        $this->assertTrue($models['qwen/qwen3.7-max']['json_schema']);
        $this->assertFalse($models['google/gemini-decision-1']['json_schema']);
    }

    public function testRejectsUnparsableList(): void
    {
        $this->assertNull(parseAiModelsList('<html>'));
        $this->assertNull(parseAiModelsList('{"data":[]}'));
        $this->assertNull(parseAiModelsList('{"error":"x"}'));
    }

    public function testConvertsStringAndMissingPrices(): void
    {
        $this->assertSame(16020, convertAiTokenPrice('0.00016019562'));
        $this->assertSame(0, convertAiTokenPrice(0));
        $this->assertNull(convertAiTokenPrice(null));
        $this->assertNull(convertAiTokenPrice('free'));
    }

    public function testDescribesModelForHint(): void
    {
        $models = parseAiModelsList(self::listBody());

        $this->assertSame(
            'Qwen: Qwen4 Max · картинки: да · 160,2 / 480,59 ₽ за 1 млн токенов',
            describeAiModel($models['qwen/qwen4-max'])
        );
        $this->assertStringContainsString('картинки: нет', describeAiModel($models['qwen/qwen3.7-max']));
    }

    public function testFormatsPrice(): void
    {
        $this->assertSame('160', formatAiPrice(16000));
        $this->assertSame('160,2', formatAiPrice(16020));
        $this->assertSame('0,59', formatAiPrice(59));
        $this->assertSame('0,05', formatAiPrice(5));
        $this->assertSame('3 844,69', formatAiPrice(384469));
    }

    public function testFetchesAndCachesList(): void
    {
        $calls = 0;
        $first = loadAiModels(self::fetchOk($calls), $this->cacheFile(), self::NOW);
        $second = loadAiModels(self::fetchOk($calls), $this->cacheFile(), self::NOW + 60);

        $this->assertSame(1, $calls);
        $this->assertArrayHasKey('qwen/qwen4-max', $first['models']);
        $this->assertSame($first['models'], $second['models']);
        $this->assertFalse($second['stale']);
    }

    public function testRefreshesExpiredCache(): void
    {
        $calls = 0;
        loadAiModels(self::fetchOk($calls), $this->cacheFile(), self::NOW);
        loadAiModels(self::fetchOk($calls), $this->cacheFile(), self::NOW + AI_MODELS_CACHE_TTL);

        $this->assertSame(2, $calls);
    }

    public function testFallsBackToStaleCacheWhenRouterAiIsDown(): void
    {
        $calls = 0;
        loadAiModels(self::fetchOk($calls), $this->cacheFile(), self::NOW);
        $result = loadAiModels(self::fetchFail($calls), $this->cacheFile(), self::NOW + 30 * AI_MODELS_CACHE_TTL);

        $this->assertTrue($result['stale']);
        $this->assertArrayHasKey('qwen/qwen4-max', $result['models']);
        $this->assertNotNull($result['error']);
    }

    public function testNoCacheAndNoNetworkGivesNull(): void
    {
        $calls = 0;
        $result = loadAiModels(self::fetchFail($calls), $this->cacheFile(), self::NOW);

        $this->assertNull($result['models']);
        $this->assertFalse($result['stale']);
        $this->assertSame('Could not resolve host (curl 6)', $result['error']);
    }

    public function testAfterFailureNetworkIsNotRetriedForAWhile(): void
    {
        $calls = 0;
        loadAiModels(self::fetchFail($calls), $this->cacheFile(), self::NOW);
        $soon = loadAiModels(self::fetchOk($calls), $this->cacheFile(), self::NOW + AI_MODELS_RETRY_AFTER - 1);

        $this->assertSame(1, $calls);
        $this->assertNull($soon['models']);
        $this->assertSame('Could not resolve host (curl 6)', $soon['error']);

        $later = loadAiModels(self::fetchOk($calls), $this->cacheFile(), self::NOW + AI_MODELS_RETRY_AFTER);

        $this->assertSame(2, $calls);
        $this->assertArrayHasKey('qwen/qwen4-max', $later['models']);
        $this->assertNull($later['error']);
    }

    public function testFailureKeepsStaleModelsInCache(): void
    {
        $calls = 0;
        $expired = self::NOW + AI_MODELS_CACHE_TTL;
        loadAiModels(self::fetchOk($calls), $this->cacheFile(), self::NOW);
        loadAiModels(self::fetchFail($calls), $this->cacheFile(), $expired);
        $result = loadAiModels(self::fetchFail($calls), $this->cacheFile(), $expired + 60);

        $this->assertSame(2, $calls);
        $this->assertTrue($result['stale']);
        $this->assertArrayHasKey('qwen/qwen4-max', $result['models']);
    }

    public function testBrokenListGivesNotListError(): void
    {
        $result = loadAiModels(
            static fn (): array => ['status' => 200, 'body' => '<html>', 'error' => null],
            $this->cacheFile(),
            self::NOW
        );

        $this->assertNull($result['models']);
        $this->assertSame(AI_MODELS_ERROR_NOT_LIST, $result['error']);
    }

    public function testErrorStatusGivesNoResponseError(): void
    {
        $result = loadAiModels(
            static fn (): array => ['status' => 503, 'body' => '', 'error' => null],
            $this->cacheFile(),
            self::NOW
        );

        $this->assertSame(AI_MODELS_ERROR_NO_RESPONSE, $result['error']);
    }
}
