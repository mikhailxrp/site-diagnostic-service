<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once ROOT_PATH . '/src/Services/VersionSources.php';

/**
 * Без сети: ответы источников — фикстуры `tests/fixtures/versions/` (сокращённые настоящие ответы).
 */
final class VersionSourcesTest extends TestCase
{
    private const FIXTURES = ROOT_PATH . '/tests/fixtures/versions/';

    /** @var list<string> */
    private array $requested = [];

    private function fixture(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES . $name);
    }

    /**
     * @param array<string, array{status?: ?int, body?: string}> $responses часть адреса → ответ; остальное — 404
     */
    private function request(array $responses): callable
    {
        return function (string $url) use ($responses): array {
            $this->requested[] = $url;
            foreach ($responses as $needle => $response) {
                if (str_contains($url, $needle)) {
                    return ['status' => array_key_exists('status', $response) ? $response['status'] : 200,
                        'body' => $response['body'] ?? '', 'truncated' => false, 'error' => null, 'headers' => []];
                }
            }

            return ['status' => 404, 'body' => '', 'truncated' => false, 'error' => null, 'headers' => []];
        };
    }

    public function testParsersOnRealShapedResponses(): void
    {
        $this->assertSame('4.0.0', parseNpmLatest($this->fixture('npm-jquery.json')));
        $this->assertSame('4.1.0.4', parsePackagistLatest($this->fixture('packagist-opencart.json'), 'opencart/opencart'));
        $this->assertSame('2.4.9', parseGithubLatest($this->fixture('github-magento.json')));
        $this->assertSame('7.1.4', parseWordpressCoreLatest($this->fixture('wordpress-core.json')));

        $php = parsePhpActiveBranches($this->fixture('php-active.json'));
        $this->assertSame('8.5.12', $php['latest']);
        $this->assertSame(['8.2' => '8.2.35', '8.3' => '8.3.36', '8.4' => '8.4.27', '8.5' => '8.5.12'], $php['branches']);
    }

    public function testBrokenResponsesThrow(): void
    {
        $cases = [
            static fn () => parseNpmLatest('<html>не JSON</html>'),
            static fn () => parseNpmLatest('{"version":"5.0.0-beta.1"}'),
            static fn () => parsePackagistLatest('{"packages":{}}', 'opencart/opencart'),
            static fn () => parseGithubLatest('{"message":"API rate limit exceeded"}'),
            static fn () => parsePhpActiveBranches('{}'),
        ];
        foreach ($cases as $index => $case) {
            try {
                $case();
                $this->fail("Случай {$index}: ожидалось исключение");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testFailedSourceKeepsPreviousDataOthersUpdate(): void
    {
        $sources = [
            'jquery'   => ['source' => 'npm', 'package' => 'jquery'],
            'opencart' => ['source' => 'packagist', 'package' => 'opencart/opencart'],
            'php'      => ['source' => 'php'],
            'woo'      => ['source' => 'wp-plugin', 'slug' => 'woocommerce'],
        ];
        $previous = ['items' => [
            'opencart' => ['latest' => '4.0.2.3', 'source' => 'packagist', 'updated_at' => '2026-11-01T10:00:00+03:00'],
        ]];

        $result = updateLatestVersionsDatabase($previous, $this->request([
            'registry.npmjs.org/jquery' => ['body' => $this->fixture('npm-jquery.json')],
            'packagist'                 => ['status' => 503],
            'php.net'                   => ['body' => '{сломано'],
        ]), $sources);

        $this->assertSame(['jquery'], $result['updated']);
        $this->assertSame(['opencart', 'php'], array_keys($result['failed']));
        $this->assertSame('4.0.0', $result['data']['items']['jquery']['latest']);
        // Прошлые данные упавшего источника — с прошлой датой; данных не было — записи нет
        $this->assertSame($previous['items']['opencart'], $result['data']['items']['opencart']);
        $this->assertArrayNotHasKey('php', $result['data']['items']);
        // Плагины WordPress в базу не скачиваются
        $this->assertArrayNotHasKey('woo', $result['data']['items']);
        $this->assertSame([], array_filter($this->requested, static fn (string $u): bool => str_contains($u, 'plugins/info')));
    }

    public function testWordpressPluginInfo(): void
    {
        $found = fetchWordpressPluginVersion('contact-form-7', $this->request([
            'contact-form-7' => ['body' => '{"name":"Contact Form 7","slug":"contact-form-7","version":"6.2.5"}'],
        ]));
        $this->assertSame(['status' => WP_PLUGIN_FOUND, 'version' => '6.2.5'], $found);

        $missing = fetchWordpressPluginVersion('paid-plugin', $this->request([
            'paid-plugin' => ['body' => '{"error":"Plugin not found."}'],
        ]));
        $this->assertSame(WP_PLUGIN_NOT_FOUND, $missing['status']);

        $error = fetchWordpressPluginVersion('some-plugin', $this->request(['some-plugin' => ['status' => 502]]));
        $this->assertSame(WP_PLUGIN_ERROR, $error['status']);

        // Непохожий на slug — в сеть не уходит
        $this->requested = [];
        $this->assertSame(WP_PLUGIN_NOT_FOUND, fetchWordpressPluginVersion('../etc/passwd', $this->request([]))['status']);
        $this->assertSame([], $this->requested);
    }

    public function testRealSourcesFileIsValid(): void
    {
        foreach (loadVersionSources() as $key => $source) {
            $this->assertContains($source['source'], ['npm', 'packagist', 'github', 'wordpress', 'php', 'wp-plugin'], $key);
            if (isset($source['eol_below'])) {
                $this->assertNotNull(normalizeVersion($source['eol_below']), $key);
            }
        }
    }
}
