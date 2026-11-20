<?php

declare(strict_types=1);

namespace Tests\Unit;

use ErrorException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/src/Services/HtmlParser.php';
require_once ROOT_PATH . '/src/Services/StackDetector.php';

/**
 * Без сети: фикстуры HTML в `tests/fixtures/stack/` (синтетические) + заголовки.
 */
final class StackDetectorTest extends TestCase
{
    private const FIXTURES = ROOT_PATH . '/tests/fixtures/stack/';

    /**
     * @param array<string, list<string>> $headers
     */
    private function detect(string $fixture, array $headers = [], string $url = 'https://shop.test/'): array
    {
        $body = $fixture === '' ? '' : (string) file_get_contents(self::FIXTURES . $fixture . '.html');

        return detectStack(['headers' => $headers, 'body' => $body], parseHtml($body, $url), loadStackSignatures());
    }

    private function find(array $items, string $category, string $name): ?array
    {
        foreach ($items as $item) {
            if ($item['category'] === $category && $item['name'] === $name) {
                return $item;
            }
        }

        return null;
    }

    private function names(array $items, string $category): array
    {
        $names = array_column(array_filter($items, static fn (array $item): bool => $item['category'] === $category), 'name');
        sort($names);

        return $names;
    }

    /**
     * @return array<string, array{string, string, list<string>, list<string>}>
     */
    public static function cmsProvider(): array
    {
        return [
            'WordPress'   => ['wordpress', 'WordPress', ['storefront', 'storefront-child'], ['contact-form-7', 'мой-плагин', 'WooCommerce']],
            '1С-Битрикс'  => ['bitrix', '1С-Битрикс', ['aspro_max'], ['aspro']],
            'OpenCart'    => ['opencart', 'OpenCart', ['journal3'], ['ocfilter']],
            'Joomla'      => ['joomla', 'Joomla', ['cassiopeia'], ['com_jshopping', 'mod_jshopping_cart']],
            'PrestaShop'  => ['prestashop', 'PrestaShop', ['classic'], ['blockreassurance', 'ps_searchbar']],
            'Magento'     => ['magento', 'Magento', ['Magento/luma'], ['Amasty_Shopby']],
            'Shop-Script' => ['shopscript', 'Shop-Script (Webasyst)', ['default'], ['productbrands']],
            'Мегагрупп'   => ['megagroup', 'Мегагрупп (S3)', ['130-4'], []],
            'InSales'     => ['insales', 'InSales', [], []],
            'Tilda'       => ['tilda', 'Tilda', [], []],
        ];
    }

    /**
     * @param list<string> $themes
     * @param list<string> $plugins
     */
    #[DataProvider('cmsProvider')]
    public function testDetectsCmsThemesAndPlugins(string $fixture, string $cms, array $themes, array $plugins): void
    {
        $items = $this->detect($fixture);

        $this->assertSame([$cms], $this->names($items, 'cms'), 'CMS');
        $this->assertSame($themes, $this->names($items, 'theme'), 'темы');

        $expectedPlugins = $plugins;
        sort($expectedPlugins);
        $this->assertSame($expectedPlugins, $this->names($items, 'plugin'), 'плагины');
    }

    public function testPlainSiteHasNoCmsThemesOrPlugins(): void
    {
        $items = $this->detect('plain');

        $this->assertSame([], $this->names($items, 'cms'));
        $this->assertSame([], $this->names($items, 'theme'));
        $this->assertSame([], $this->names($items, 'plugin'));
    }

    public function testWordPressVersionAndHighConfidence(): void
    {
        $items = $this->detect('wordpress', ['link' => ['<https://shop.test/wp-json/>; rel="https://api.w.org/"']]);
        $wordpress = $this->find($items, 'cms', 'WordPress');

        $this->assertSame('6.4.2', $wordpress['version']);
        $this->assertSame('high', $wordpress['confidence']);
        $sources = array_column($wordpress['evidence'], 'source');
        $this->assertContains('meta', $sources);
        $this->assertContains('header', $sources);
        $this->assertContains('url', $sources);
    }

    public function testWooCommerceMergedFromGeneratorAndPluginPath(): void
    {
        $woo = $this->find($this->detect('wordpress'), 'plugin', 'WooCommerce');

        $this->assertSame('8.4.0', $woo['version']);
        $this->assertSame('high', $woo['confidence']);
        $this->assertNull($this->find($this->detect('wordpress'), 'plugin', 'woocommerce'), 'нет дубля по slug');
    }

    public function testPluginVersionFromQueryButNotCoreVersion(): void
    {
        $items = $this->detect('wordpress');

        $this->assertSame('5.8.4', $this->find($items, 'plugin', 'contact-form-7')['version']);
        $this->assertSame('4.5.3', $this->find($items, 'theme', 'storefront')['version']);
        // ?ver=6.4.2 — это версия WordPress, подставленная по умолчанию, а не версия дочерней темы
        $this->assertNull($this->find($items, 'theme', 'storefront-child')['version']);
    }

    /**
     * Как на wpbeginner.com: первым идёт `?ver=` встроенной библиотеки (jquery.validate 1.21.0), а версия плагина —
     * у его собственных файлов (2.0.3).
     */
    public function testPluginVersionIgnoresBundledLibraries(): void
    {
        $items = $this->detect('wordpress-versions');

        $this->assertSame('2.0.3', $this->find($items, 'plugin', 'wpforms')['version']);
        $this->assertSame('2.1.0', $this->find($items, 'theme', 'shopline')['version'], 'vendor-файл темы не голосует');
        $this->assertNull($this->find($items, 'plugin', 'plain-plugin')['version'], 'только файл плагина jQuery — версии нет');
    }

    public function testOwnFileOutweighsOtherAssetAndTieGivesNoVersion(): void
    {
        $items = $this->detect('wordpress-versions');

        $this->assertSame('3.1.0', $this->find($items, 'plugin', 'own-wins')['version']);
        $this->assertNull($this->find($items, 'plugin', 'tie-plugin')['version'], 'ничья 1.0.0 / 1.2.0 — версия неизвестна');
    }

    public function testLibraryInThemeFolderDoesNotTakeThemeVersion(): void
    {
        $items = $this->detect('wordpress-versions');

        $this->assertNull($this->find($items, 'js_library', 'Swiper')['version'], '?ver= темы — не версия Swiper');
        $this->assertNull($this->find($items, 'js_library', 'Bootstrap')['version']);
        $this->assertSame('3.7.1', $this->find($items, 'js_library', 'jQuery')['version'], 'wp-includes — версия библиотеки');
    }

    public function testPickVotedVersion(): void
    {
        $this->assertSame('2.0.3', pickVotedVersion(['1.21.0' => 1, '2.0.3' => 3]));
        $this->assertNull(pickVotedVersion(['1.0.0' => 1, '1.2.0' => 1]));
        $this->assertNull(pickVotedVersion([]));
    }

    public function testAssetVersionIsNotThemeVersionOutsideWordPress(): void
    {
        // у Битрикса `?ver=1.09` — версия отдельного файла шаблона, а не шаблона
        $this->assertNull($this->find($this->detect('bitrix'), 'theme', 'aspro_max')['version']);
    }

    public function testSingleWeakSignalIsLowConfidence(): void
    {
        $html = '<link rel="stylesheet" href="/wp-content/uploads/a.css">';
        $items = detectStack(['headers' => [], 'body' => $html], parseHtml($html, 'https://shop.test/'), loadStackSignatures());

        $this->assertSame('low', $this->find($items, 'cms', 'WordPress')['confidence']);
    }

    public function testBitrixByHeaderAndCookie(): void
    {
        $items = $this->detect('', [
            'x-powered-cms' => ['Bitrix Site Manager (DEMO)'],
            'set-cookie'    => ['BITRIX_SM_GUEST_ID=1; path=/', 'PHPSESSID=abc; path=/'],
        ]);
        $bitrix = $this->find($items, 'cms', '1С-Битрикс');

        $this->assertSame('high', $bitrix['confidence']);
        $this->assertSame(
            [['source' => 'header', 'detail' => 'x-powered-cms: Bitrix Site Manager (DEMO)'], ['source' => 'cookie', 'detail' => 'BITRIX_SM_GUEST_ID']],
            $bitrix['evidence']
        );
        $this->assertSame('low', $this->find($items, 'php', 'PHP')['confidence'], 'PHP только по cookie');
    }

    public function testServerPhpAndCdnFromHeaders(): void
    {
        $items = $this->detect('', [
            'server'       => ['nginx/1.18.0 (Ubuntu)'],
            'x-powered-by' => ['PHP/7.4.33'],
            'cf-ray'       => ['8a1b2c3d4e-DME'],
        ]);

        $server = $this->find($items, 'server', 'nginx');
        $this->assertSame('1.18.0', $server['version']);
        $this->assertSame('high', $server['confidence']);
        $this->assertSame([['source' => 'header', 'detail' => 'server: nginx/1.18.0 (Ubuntu)']], $server['evidence']);

        $php = $this->find($items, 'php', 'PHP');
        $this->assertSame('7.4.33', $php['version']);
        $this->assertSame([['source' => 'header', 'detail' => 'x-powered-by: PHP/7.4.33']], $php['evidence']);

        $this->assertNotNull($this->find($items, 'cdn', 'Cloudflare'));
    }

    public function testCdnServerValueIsNotWebServer(): void
    {
        $items = $this->detect('', ['server' => ['cloudflare']]);

        $this->assertSame([], $this->names($items, 'server'));
        $this->assertSame(['Cloudflare'], $this->names($items, 'cdn'));
    }

    public function testApacheWithPhpInServerHeader(): void
    {
        $items = $this->detect('', ['server' => ['Apache/2.4.41 (Unix) PHP/7.2.34']]);

        $this->assertSame('2.4.41', $this->find($items, 'server', 'Apache')['version']);
        $this->assertSame('7.2.34', $this->find($items, 'php', 'PHP')['version']);
    }

    public function testOtherPoweredByIsKept(): void
    {
        $items = $this->detect('', ['x-powered-by' => ['PleskLin']]);

        $this->assertSame(['PleskLin'], $this->names($items, 'other'));
        $this->assertSame([], $this->names($items, 'php'));
    }

    public function testLibrariesWithVersions(): void
    {
        $wordpress = $this->detect('wordpress');
        $this->assertSame('3.7.1', $this->find($wordpress, 'js_library', 'jQuery')['version']);
        $this->assertSame('3.4.1', $this->find($wordpress, 'js_library', 'jQuery Migrate')['version']);

        $bitrix = $this->detect('bitrix');
        $this->assertSame('1.12.4', $this->find($bitrix, 'js_library', 'jQuery')['version'], 'версия из имени файла');

        $opencart = $this->detect('opencart');
        $this->assertSame('2.1.1', $this->find($opencart, 'js_library', 'jQuery')['version']);
        $bootstrap = $this->find($opencart, 'js_library', 'Bootstrap');
        $this->assertNull($bootstrap['version']);
        $this->assertSame('high', $bootstrap['confidence'], 'CSS и JS — два файла');

        $plain = $this->detect('plain');
        $this->assertSame('11.0.5', $this->find($plain, 'js_library', 'Swiper')['version'], 'версия из пути CDN');
    }

    /**
     * Ключ библиотеки из data/signatures.php — по нему шаг `outdated` находит источник версии.
     */
    public function testLibraryItemsHaveSignatureKey(): void
    {
        $wordpress = $this->detect('wordpress');

        $this->assertSame('jquery', $this->find($wordpress, 'js_library', 'jQuery')['key']);
        $this->assertSame('jquery-migrate', $this->find($wordpress, 'js_library', 'jQuery Migrate')['key']);
    }

    public function testJqueryUiAndMigrateAreNotJquery(): void
    {
        $html = '<script src="/js/jquery-ui.min.js"></script><script src="/js/jquery-migrate-3.4.1.min.js"></script>';
        $items = detectStack(['headers' => [], 'body' => $html], parseHtml($html, 'https://shop.test/'), loadStackSignatures());

        $this->assertSame(['jQuery Migrate', 'jQuery UI'], $this->names($items, 'js_library'));
        $this->assertSame('3.4.1', $this->find($items, 'js_library', 'jQuery Migrate')['version']);
    }

    public function testAnalyticsCounters(): void
    {
        $wordpress = $this->detect('wordpress');
        $this->assertSame(['Google Analytics', 'Яндекс Метрика'], $this->names($wordpress, 'analytics'));
        $this->assertSame('high', $this->find($wordpress, 'analytics', 'Яндекс Метрика')['confidence'], 'тег + ym()');

        $this->assertSame(['Google Tag Manager'], $this->names($this->detect('bitrix'), 'analytics'));
        $this->assertSame([], $this->names($this->detect('plain'), 'analytics'));
    }

    public function testEveryItemHasEvidenceAndConfidence(): void
    {
        foreach (self::cmsProvider() as [$fixture]) {
            foreach ($this->detect($fixture, ['server' => ['nginx']]) as $item) {
                $this->assertNotEmpty($item['evidence'], "{$fixture}: {$item['name']}");
                $this->assertContains($item['confidence'], ['high', 'medium', 'low']);
                $this->assertLessThanOrEqual(STACK_EVIDENCE_MAX_ITEMS, count($item['evidence']));
            }
        }
    }

    public function testItemsAreOrderedByCategory(): void
    {
        $categories = array_column($this->detect('wordpress', ['server' => ['nginx']]), 'category');

        $this->assertSame('cms', $categories[0]);
        $positions = array_map(static fn (string $category): int => array_search($category, STACK_CATEGORY_ORDER, true), $categories);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);
    }

    public function testLongValuesAreTruncatedToColumns(): void
    {
        $items = $this->detect('', ['x-powered-by' => [str_repeat('я', 400)]]);

        $this->assertSame(STACK_NAME_MAX_LENGTH, mb_strlen($items[0]['name']));
        $this->assertSame(STACK_EVIDENCE_DETAIL_MAX_LENGTH, mb_strlen($items[0]['evidence'][0]['detail']));
    }

    public function testEmptyPageGivesNoItems(): void
    {
        $this->assertSame([], $this->detect(''));
    }

    public function testExtractUrlVersion(): void
    {
        $this->assertSame('6.4.2', extractUrlVersion('https://a.test/x.js?ver=6.4.2'));
        $this->assertSame('3.6.0', extractUrlVersion('https://cdn.test/ajax/libs/jquery/3.6.0/jquery.min.js'));
        $this->assertSame('3.6.0', extractUrlVersion('https://cdn.test/npm/jquery@3.6.0/dist/jquery.min.js'));
        $this->assertNull(extractUrlVersion('https://a.test/x.js?ver=abc'));
        $this->assertNull(extractUrlVersion('https://a.test/x.js?17000000001'));
    }

    public function testVersionProbeUrlsOnlyForKnownCms(): void
    {
        $signatures = loadStackSignatures();

        $this->assertSame(
            [
                ['url' => 'https://shop.test/feed/', 'version_pattern' => $signatures['platforms']['wordpress']['probes'][0]['version']],
                ['url' => 'https://shop.test/readme.html', 'version_pattern' => $signatures['platforms']['wordpress']['probes'][1]['version']],
            ],
            buildVersionProbeUrls('wordpress', 'https://shop.test/catalog/?a=1', $signatures)
        );
        $this->assertSame([], buildVersionProbeUrls('tilda', 'https://shop.test/', $signatures));
        $this->assertSame([], buildVersionProbeUrls('unknown', 'https://shop.test/', $signatures));
    }

    public function testExtractVersionFromProbe(): void
    {
        $signatures = loadStackSignatures()['platforms'];

        $this->assertSame('6.4.2', extractVersionFromProbe(
            '<generator>https://wordpress.org/?v=6.4.2</generator>',
            $signatures['wordpress']['probes'][0]['version']
        ));
        $this->assertSame('4.4.1', extractVersionFromProbe(
            '<extension><version>4.4.1</version></extension>',
            $signatures['joomla']['probes'][0]['version']
        ));
        $this->assertSame('2.4', extractVersionFromProbe('Magento/2.4 (Community)', $signatures['magento']['probes'][0]['version']));
        $this->assertNull(extractVersionFromProbe('<html>404</html>', $signatures['magento']['probes'][0]['version']));
    }

    public function testInvalidUtf8InThemePathAndHeaderIsScrubbed(): void
    {
        $html = '<html><head><meta name="generator" content="WordPress 6.4.2">'
            . '<link rel="stylesheet" href="https://shop.test/wp-content/themes/%D2%E5%EC%E0/style.css">'
            . '</head><body></body></html>';

        $items = detectStack(
            ['headers' => ['x-powered-by' => ["PHP/8.1\xC0"]], 'body' => $html],
            parseHtml($html, 'https://shop.test/'),
            loadStackSignatures()
        );

        $this->assertNotEmpty($items);
        foreach ($items as $item) {
            $this->assertTrue(mb_check_encoding($item['name'], 'UTF-8'));
            if ($item['version'] !== null) {
                $this->assertTrue(mb_check_encoding($item['version'], 'UTF-8'));
            }
            foreach ($item['evidence'] as $evidence) {
                $this->assertTrue(mb_check_encoding($evidence['detail'], 'UTF-8'));
            }
        }
        $this->assertIsString(json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function testExtractQueryVersionHandlesTwentyThousandParamsWithoutWarning(): void
    {
        $manyParams = 'https://a.test/x.js?' . str_repeat('a=&', 20000);

        set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
            throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
        });
        try {
            $this->assertNull(extractQueryVersion($manyParams));
            $this->assertSame('6.4.2', extractQueryVersion($manyParams . 'ver=6.4.2'));
            $this->assertSame('1.2.3', extractQueryVersion('https://a.test/x.js?v=abc&ver=1.2.3'));
            $this->assertSame('2.0.1', extractQueryVersion('https://a.test/x.js?Ver=2.0.1'));
        } finally {
            restore_error_handler();
        }
    }

    public function testDetectThemesAndPluginsCapsFakeItemsWhileKeepingPlatformPluginsAndExistingVersions(): void
    {
        $links = [];
        $scripts = [];
        for ($i = 1; $i <= 250; $i++) {
            $links[] = '<link rel="stylesheet" href="https://shop.test/wp-content/plugins/fake-plugin-' . $i . '/style.css">';
        }
        for ($i = 1; $i <= 40; $i++) {
            $links[] = '<link rel="stylesheet" href="https://shop.test/wp-content/themes/fake-theme-' . $i . '/style.css">';
        }
        // Поздний скрипт первого плагина с версией и плагин-платформа WooCommerce после лимита
        $scripts[] = '<script src="https://shop.test/wp-content/plugins/fake-plugin-1/fake-plugin-1.js?ver=1.4.0"></script>';
        $scripts[] = '<script src="https://shop.test/wp-content/plugins/woocommerce/woocommerce.min.js?ver=9.1.0"></script>';

        $html = '<html><head><meta name="generator" content="WordPress 6.4.2">'
            . implode('', $links)
            . implode('', $scripts)
            . '</head><body></body></html>';

        $items = detectStack(['headers' => [], 'body' => $html], parseHtml($html, 'https://shop.test/'), loadStackSignatures());

        $plugins = array_values(array_filter($items, static fn (array $item): bool => $item['category'] === 'plugin'));
        $themes = array_values(array_filter($items, static fn (array $item): bool => $item['category'] === 'theme'));

        // 100 обычных плагинов + плагин-платформа WooCommerce
        $this->assertCount(STACK_MAX_PLUGINS_PER_CMS + 1, $plugins);
        $this->assertCount(STACK_MAX_THEMES_PER_CMS, $themes);

        $firstPlugin = $this->find($items, 'plugin', 'fake-plugin-1');
        $this->assertNotNull($firstPlugin);
        $this->assertSame('1.4.0', $firstPlugin['version']);

        $woocommerce = $this->find($items, 'plugin', 'WooCommerce');
        $this->assertNotNull($woocommerce);
        $this->assertSame('9.1.0', $woocommerce['version']);

        $this->assertNull($this->find($items, 'plugin', 'fake-plugin-101'));
        $this->assertNull($this->find($items, 'theme', 'fake-theme-21'));
    }
}
