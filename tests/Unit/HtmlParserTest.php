<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/src/Services/HtmlParser.php';

final class HtmlParserTest extends TestCase
{
    public function testResolvesRelativeAndProtocolRelativeUrls(): void
    {
        $html = '<html><head>'
            . '<script src="js/app.js?v=1"></script>'
            . '<script src="//cdn.test/lib.js"></script>'
            . '<script src="/abs.js"></script>'
            . '<link rel="stylesheet" href="../css/a.css">'
            . '</head><body></body></html>';

        $parsed = parseHtml($html, 'https://shop.test/catalog/item/');

        $this->assertSame(
            ['https://shop.test/catalog/item/js/app.js?v=1', 'https://cdn.test/lib.js', 'https://shop.test/abs.js'],
            $parsed['scripts']
        );
        $this->assertSame(['https://shop.test/catalog/css/a.css'], $parsed['styles']);
    }

    public function testBaseHrefIsUsedForRelativeUrls(): void
    {
        $html = '<html><head><base href="https://parts.test/shop/"><script src="js/a.js"></script></head></html>';

        $parsed = parseHtml($html, 'https://parts.test/');

        $this->assertSame('https://parts.test/shop/', $parsed['base_url']);
        $this->assertSame(['https://parts.test/shop/js/a.js'], $parsed['scripts']);
    }

    public function testEntitiesInAttributesAreDecodedOnce(): void
    {
        $parsed = parseHtml('<link rel="stylesheet" href="/a.css?x=1&amp;ver=2">', 'https://shop.test/');

        $this->assertSame(['https://shop.test/a.css?x=1&ver=2'], $parsed['styles']);
    }

    public function testMetaLinksImagesInlineScriptsAndComments(): void
    {
        $html = '<html><head>'
            . '<meta name="Generator" content="WordPress 6.4">'
            . '<meta name="generator" content="WooCommerce 8.4">'
            . '<meta property="og:title" content="Товар">'
            . '<link rel="icon" href="/favicon.ico">'
            . '<script>var a = 1;</script>'
            . '</head><body>'
            . '<img src="/a.png" srcset="/a-2x.png 2x, /a-3x.png 3x" alt="">'
            . '<img src="data:image/png;base64,AAAA" srcset="data:image/png;base64,AA,BB 2x" alt="">'
            . '<!-- Yoast SEO plugin v21.0 -->'
            . '</body></html>';

        $parsed = parseHtml($html, 'https://shop.test/');

        $this->assertSame([
            ['name' => 'generator', 'content' => 'WordPress 6.4'],
            ['name' => 'generator', 'content' => 'WooCommerce 8.4'],
            ['name' => 'og:title', 'content' => 'Товар'],
        ], $parsed['meta']);
        $this->assertSame([['rel' => 'icon', 'url' => 'https://shop.test/favicon.ico']], $parsed['links']);
        $this->assertSame(['var a = 1;'], $parsed['inline_scripts']);
        $this->assertSame(
            ['https://shop.test/a.png', 'https://shop.test/a-2x.png', 'https://shop.test/a-3x.png'],
            $parsed['images']
        );
        $this->assertSame(['Yoast SEO plugin v21.0'], $parsed['comments']);
    }

    public function testNonHttpUrlsAreSkipped(): void
    {
        $html = '<script src="javascript:void(0)"></script><link rel="stylesheet" href="#top">'
            . '<link rel="preconnect" href="mailto:a@b.test">';

        $parsed = parseHtml($html, 'https://shop.test/');

        $this->assertSame([], $parsed['scripts']);
        $this->assertSame([], $parsed['styles']);
        $this->assertSame([], $parsed['links']);
    }

    public function testDuplicateUrlsAreRemoved(): void
    {
        $parsed = parseHtml('<script src="/a.js"></script><script src="/a.js"></script>', 'https://shop.test/');

        $this->assertSame(['https://shop.test/a.js'], $parsed['scripts']);
    }

    public function testCyrillicUtf8IsKept(): void
    {
        $parsed = parseHtml('<html><head><meta name="description" content="Магазин посуды"></head></html>', 'https://shop.test/');

        $this->assertSame('Магазин посуды', $parsed['meta'][0]['content']);
    }

    public function testWindows1251PageIsReadByMetaCharset(): void
    {
        $html = '<html><head><meta http-equiv="Content-Type" content="text/html; charset=windows-1251">'
            . mb_convert_encoding('<meta name="description" content="Сантехника">', 'Windows-1251', 'UTF-8')
            . '</head></html>';

        $parsed = parseHtml($html, 'https://shop.test/');

        $this->assertSame('Сантехника', $parsed['meta'][0]['content'] ?? null);
    }

    public function testInlineStylesFromStyleTagsAndAttributes(): void
    {
        $html = '<html><head><style>.a { background: url(/a.png); }</style><style>  </style></head>'
            . '<body><div style="background-image: url(\'/b.webp\')"></div><p style="">x</p></body></html>';

        $parsed = parseHtml($html, 'https://shop.test/');

        $this->assertSame(
            ['.a { background: url(/a.png); }', "background-image: url('/b.webp')"],
            $parsed['inline_styles']
        );
    }

    public function testLazyImagesPictureSourcesAndVideoPoster(): void
    {
        $html = '<img src="/stub.gif" data-src="/real.jpg" data-srcset="/real-2x.jpg 2x" alt="">'
            . '<picture><source srcset="/p.avif 1x, /p-2x.avif 2x" type="image/avif"><img src="/p.jpg" alt=""></picture>'
            . '<video poster="/poster.jpg"></video>';

        $parsed = parseHtml($html, 'https://shop.test/');

        $this->assertSame([
            'https://shop.test/stub.gif',
            'https://shop.test/real.jpg',
            'https://shop.test/real-2x.jpg',
            'https://shop.test/p.jpg',
            'https://shop.test/p.avif',
            'https://shop.test/p-2x.avif',
            'https://shop.test/poster.jpg',
        ], $parsed['images']);
    }

    public function testIframes(): void
    {
        $html = '<iframe src="//video.test/embed/1"></iframe><iframe src="//video.test/embed/1"></iframe>'
            . '<iframe src="about:blank"></iframe>';

        $parsed = parseHtml($html, 'https://shop.test/');

        $this->assertSame(['https://video.test/embed/1'], $parsed['iframes']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function brokenHtmlProvider(): array
    {
        return [
            'пусто'           => [''],
            'пробелы'         => ["  \n "],
            'мусор'           => ["<<<>>>\x00\x01<script"],
            'незакрытые теги' => ['<html><head><script src="/a.js"><div><p><span>'],
        ];
    }

    #[DataProvider('brokenHtmlProvider')]
    public function testBrokenHtmlDoesNotThrow(string $html): void
    {
        $parsed = parseHtml($html, 'https://shop.test/');

        $this->assertIsArray($parsed['scripts']);
        $this->assertSame('https://shop.test/', $parsed['base_url']);
    }

    public function testAnchorsHaveAbsoluteUrlTextAndClassWithoutDuplicates(): void
    {
        $html = "<a href=\"/catalog/\" class=\"menu-link\">  Каталог\n\t товаров </a>"
            . '<a href="/catalog/#top">Каталог (дубль)</a>'
            . '<a href="product/1" title="Футболка"><img src="/i.jpg" alt=""></a>'
            . '<a href="mailto:a@shop.test">Почта</a><a href="tel:+7000">Телефон</a>'
            . '<a href="javascript:void(0)">Купить</a><a href="#reviews">Отзывы</a>';

        $anchors = parseHtml($html, 'https://shop.test/catalog/')['anchors'];

        $this->assertSame([
            ['url' => 'https://shop.test/catalog/', 'text' => 'Каталог товаров', 'class' => 'menu-link'],
            ['url' => 'https://shop.test/catalog/product/1', 'text' => 'Футболка', 'class' => ''],
        ], $anchors);
    }

    public function testAnchorTextIsLimited(): void
    {
        $anchors = parseHtml('<a href="/x">' . str_repeat('я', 500) . '</a>', 'https://shop.test/')['anchors'];

        $this->assertSame(HTML_ANCHOR_TEXT_MAX_LENGTH, mb_strlen($anchors[0]['text']));
    }
}
