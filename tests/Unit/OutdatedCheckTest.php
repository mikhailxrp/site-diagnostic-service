<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once ROOT_PATH . '/src/Services/DiagnosticRunner.php';

/**
 * Без сети и БД: справочник версий — массив в тесте, каталог плагинов WordPress — заглушка, кэш — во временной
 * папке. Серьёзности сверяются с OUTDATED_SEVERITY.
 */
final class OutdatedCheckTest extends TestCase
{
    private const NOW = 1_800_000_000;
    private const DB_DATE = '2026-11-16T10:00:00+03:00';

    private string $cacheDir;

    /** @var list<string> */
    private array $requested = [];

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/sd-wp-plugins-' . bin2hex(random_bytes(4));
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

    private function database(): array
    {
        $item = static fn (string $latest, array $extra = []): array => ['latest' => $latest, 'updated_at' => self::DB_DATE] + $extra;

        return ['items' => [
            'jquery'    => $item('4.0.0'),
            'wordpress' => $item('7.1.4'),
            'joomla'    => $item('6.2.0'),
            'php'       => $item('8.5.12', ['branches' => ['8.2' => '8.2.35', '8.3' => '8.3.36', '8.4' => '8.4.27', '8.5' => '8.5.12']]),
        ]];
    }

    private function sources(): array
    {
        return [
            'jquery'      => ['source' => 'npm', 'package' => 'jquery', 'eol_below' => '3.0.0'],
            'wordpress'   => ['source' => 'wordpress'],
            'joomla'      => ['source' => 'github', 'repo' => 'joomla/joomla-cms', 'eol_below' => '5.0.0'],
            'php'         => ['source' => 'php'],
            'woocommerce' => ['source' => 'wp-plugin', 'slug' => 'woocommerce'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $items элементы стека (поля поверх обычных)
     */
    private function context(array $items): array
    {
        $context = buildStepContext(['id' => 1, 'url' => 'https://shop.test/', 'host' => 'shop.test', 'problem_text' => null]);
        $id = 100;
        foreach ($items as $item) {
            $context['facts']['stack']['items'][] = $item + [
                'key' => null, 'parent' => null, 'version' => null, 'stack_item_id' => ++$id,
            ];
        }

        return $context;
    }

    /**
     * @param array<string, array{status?: int, body?: string}|\Throwable> $plugins slug → ответ каталога
     */
    private function request(array $plugins = []): callable
    {
        return function (string $url) use ($plugins): array {
            $this->requested[] = $url;
            foreach ($plugins as $slug => $response) {
                if (str_contains($url, '=' . $slug . '&')) {
                    if ($response instanceof \Throwable) {
                        throw $response;
                    }

                    return ['status' => $response['status'] ?? 200, 'body' => $response['body'] ?? '', 'headers' => []];
                }
            }

            return ['status' => 200, 'body' => '{"error":"Plugin not found."}', 'headers' => []];
        };
    }

    private function check(array $items, array $plugins = [], ?array $database = null): array
    {
        return runOutdatedCheck(
            $this->context($items),
            $this->request($plugins),
            $database ?? $this->database(),
            $this->sources(),
            $this->cacheDir,
            self::NOW
        );
    }

    private function jquery(?string $version): array
    {
        return ['key' => 'jquery', 'category' => 'js_library', 'name' => 'jQuery', 'version' => $version];
    }

    public function testNoStackIsSkipped(): void
    {
        $context = buildStepContext(['id' => 1, 'url' => 'https://shop.test/', 'host' => 'shop.test', 'problem_text' => null]);

        $this->assertSame('skipped', runOutdatedCheck($context, $this->request(), $this->database(), $this->sources())['status']);
    }

    public function testJquery1IsUnsupportedBranch(): void
    {
        $result = $this->check([$this->jquery('1.12.4')]);

        $this->assertCount(1, $result['findings']);
        $finding = $result['findings'][0];
        $this->assertSame('outdated', $finding['category']);
        $this->assertSame(OUTDATED_SEVERITY['js_library']['unsupported'], $finding['severity']);
        $this->assertTrue($finding['evidence']['unsupported']);
        $this->assertSame('4.0.0', $finding['evidence']['latest']);
        $this->assertSame(101, $finding['evidence']['stack_item_id']);
        $this->assertStringContainsString('4.0.0', $finding['title']);
        $this->assertSame([101 => '4.0.0'], $result['facts']['latest']);
    }

    public function testJquery3IsOutdatedMajorBehindButSupported(): void
    {
        $finding = $this->check([$this->jquery('3.6.0')])['findings'][0];

        $this->assertSame(OUTDATED_SEVERITY['js_library']['outdated'], $finding['severity']);
        $this->assertSame('low', $finding['severity']);
        $this->assertFalse($finding['evidence']['unsupported']);
        $this->assertTrue($finding['evidence']['major_behind']);
        $this->assertStringContainsString('мажорную', (string) $finding['details']);
    }

    public function testCurrentVersionHasNoFindingButLatestIsRecorded(): void
    {
        $result = $this->check([$this->jquery('4.0.0'), ['key' => 'wordpress', 'category' => 'cms', 'name' => 'WordPress', 'version' => '7.1.4']]);

        $this->assertSame([], $result['findings']);
        $this->assertSame([101 => '4.0.0', 102 => '7.1.4'], $result['facts']['latest']);
    }

    public function testNewerThanDatabaseIsNotFindingAndLatestNotRecorded(): void
    {
        $result = $this->check([['key' => 'wordpress', 'category' => 'cms', 'name' => 'WordPress', 'version' => '7.2']]);

        $this->assertSame([], $result['findings']);
        $this->assertSame([], $result['facts']['latest']);
        $this->assertStringContainsString('Сравнено версий: 1', $result['message']);
    }

    public function testPhpBranches(): void
    {
        $php = static fn (string $version): array => ['category' => 'php', 'name' => 'PHP', 'version' => $version];
        $result = $this->check([$php('7.4.33'), $php('8.2.10'), $php('8.2.35')]);

        $this->assertCount(2, $result['findings']);
        [$old, $patch] = $result['findings'];
        $this->assertTrue($old['evidence']['unsupported']);
        $this->assertSame(OUTDATED_SEVERITY['php']['unsupported'], $old['severity']);
        $this->assertFalse($patch['evidence']['unsupported']);
        $this->assertSame('8.2.35', $patch['evidence']['branch_latest']);
        $this->assertSame(OUTDATED_SEVERITY['php']['outdated'], $patch['severity']);
    }

    public function testCmsSeverities(): void
    {
        $result = $this->check([
            ['key' => 'wordpress', 'category' => 'cms', 'name' => 'WordPress', 'version' => '6.4.3'],
            ['key' => 'joomla', 'category' => 'cms', 'name' => 'Joomla', 'version' => '3.10.12'],
        ]);

        [$wordpress, $joomla] = $result['findings'];
        $this->assertSame(OUTDATED_SEVERITY['cms']['outdated'], $wordpress['severity']);
        $this->assertSame(OUTDATED_SEVERITY['cms']['unsupported'], $joomla['severity']);
    }

    public function testNoVersionOrHashOrUnknownSourceGivesNoFinding(): void
    {
        $result = $this->check([
            $this->jquery(null),
            $this->jquery('a8f3c2d1'),
            $this->jquery('1699999999'),
            ['key' => 'bitrix', 'category' => 'cms', 'name' => '1С-Битрикс', 'version' => '20.0.0'],
            ['category' => 'theme', 'parent' => 'wordpress', 'name' => 'storefront', 'version' => '1.0.0'],
        ]);

        $this->assertSame([], $result['findings']);
        $this->assertSame([], $result['facts']['latest']);
        $this->assertSame([], $this->requested);
    }

    public function testWordpressPluginFromCatalogAndCache(): void
    {
        $plugins = [
            'contact-form-7' => ['body' => '{"slug":"contact-form-7","version":"6.2.5"}'],
            'woocommerce'    => ['body' => '{"slug":"woocommerce","version":"10.3.0"}'],
        ];
        $items = [
            ['category' => 'plugin', 'parent' => 'wordpress', 'name' => 'contact-form-7', 'version' => '5.1.0'],
            ['key' => 'woocommerce', 'category' => 'plugin', 'name' => 'WooCommerce', 'version' => '10.3.0'],
            ['category' => 'plugin', 'parent' => 'wordpress', 'name' => 'paid-builder', 'version' => '2.0.0'],
        ];

        $first = $this->check($items, $plugins);
        $this->assertSame(['contact-form-7 5.1.0 устарел (актуальная 6.2.5)'], array_column($first['findings'], 'title'));
        $this->assertSame(OUTDATED_SEVERITY['plugin']['outdated'], $first['findings'][0]['severity']);
        $this->assertCount(3, $this->requested);

        // Повторная диагностика — из кэша, и «нет в каталоге» тоже
        $this->requested = [];
        $second = $this->check($items, $plugins);
        $this->assertSame([], $this->requested);
        $this->assertSame(array_column($first['findings'], 'title'), array_column($second['findings'], 'title'));
    }

    public function testCatalogFailureIsNotCachedAndDoesNotBreakStep(): void
    {
        $item = ['category' => 'plugin', 'parent' => 'wordpress', 'name' => 'contact-form-7', 'version' => '5.1.0'];

        $result = $this->check([$item], ['contact-form-7' => new RuntimeException('Тайм-аут')]);
        $this->assertSame('done', $result['status']);
        $this->assertSame([], $result['findings']);

        $result = $this->check([$item], ['contact-form-7' => ['status' => 502]]);
        $this->assertSame([], $result['findings']);

        // Каталог снова ответил — запрос уходит (сбой не закэширован)
        $this->requested = [];
        $result = $this->check([$item], ['contact-form-7' => ['body' => '{"version":"6.2.5"}']]);
        $this->assertCount(1, $this->requested);
        $this->assertCount(1, $result['findings']);
    }

    public function testExpiredCacheIsRefreshed(): void
    {
        $item = ['category' => 'plugin', 'parent' => 'wordpress', 'name' => 'contact-form-7', 'version' => '5.1.0'];
        saveDatabaseFile($this->cacheDir . '/contact-form-7.json', [
            'fetched_at' => self::NOW - OUTDATED_PLUGIN_CACHE_TTL - 1, 'status' => 'found', 'version' => '5.1.0',
        ]);

        $result = $this->check([$item], ['contact-form-7' => ['body' => '{"version":"6.2.5"}']]);

        $this->assertCount(1, $this->requested);
        $this->assertCount(1, $result['findings']);
    }

    public function testDatabaseDateAndMissingDatabase(): void
    {
        $result = $this->check([$this->jquery('1.12.4')]);
        $this->assertSame(self::DB_DATE, $result['facts']['databases']['latest_versions']['updated_at']);
        $this->assertStringContainsString('база версий от 16.11.2026', $result['message']);

        $empty = runOutdatedCheck($this->context([$this->jquery('1.12.4')]), $this->request(), [], $this->sources(), $this->cacheDir, self::NOW);
        $this->assertSame('done', $empty['status']);
        $this->assertSame([], $empty['findings']);
        $this->assertNull($empty['facts']['databases']['latest_versions']['updated_at']);
    }
}
