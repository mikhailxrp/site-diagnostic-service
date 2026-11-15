<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/src/Services/ShopCrawler.php';

/**
 * Без сети: страницы — фикстуры tests/fixtures/crawl/<платформа>/, остальные адреса отвечают 404.
 */
final class ShopCrawlerTest extends TestCase
{
    private const FIXTURES = ROOT_PATH . '/tests/fixtures/crawl/';
    private const ORIGIN   = 'https://shop.test';

    private const CART_HTML     = '<html><body><h1>Корзина</h1><p>В корзине пусто.</p></body></html>';
    private const CHECKOUT_HTML = '<html><body><h1>Оформление заказа</h1></body></html>';

    /** Адреса категорий и товаров из фикстур (как в генераторе) */
    private const PLATFORM_PAGES = [
        'woocommerce' => ['/product-category/odezhda/', '/product/futbolka/'],
        'bitrix'      => ['/catalog/odezhda/', '/catalog/odezhda/futbolka/'],
        'opencart'    => ['/index.php?route=product/category&path=20', '/index.php?route=product/product&path=20&product_id=40'],
        'joomla'      => [
            '/index.php?option=com_virtuemart&view=category&virtuemart_category_id=1',
            '/index.php?option=com_virtuemart&view=productdetails&virtuemart_product_id=1',
        ],
        'prestashop'  => ['/3-clothes', '/men/1-hummingbird-t-shirt.html'],
        'magento'     => ['/women.html', '/radiant-tee.html'],
        'shopscript'  => ['/category/odezhda/', '/product/futbolka/'],
        'megagroup'   => ['/magazin/folder/odezhda', '/magazin/product/futbolka'],
        'insales'     => ['/collection/odezhda', '/product/futbolka'],
        'generic'     => ['/catalog/', '/products/futbolka-1'],
    ];

    /** Ключ платформы → элементы стека, как их отдаёт StackDetector */
    private const STACK = [
        'woocommerce' => [['key' => 'wordpress', 'category' => 'cms'], ['key' => 'woocommerce', 'category' => 'plugin']],
        'generic'     => [],
    ];

    /** @var list<string> */
    private array $requested = [];

    /**
     * @return array<string, array{string}>
     */
    public static function platformProvider(): array
    {
        return array_combine(
            array_keys(self::PLATFORM_PAGES),
            array_map(static fn (string $platform): array => [$platform], array_keys(self::PLATFORM_PAGES))
        );
    }

    #[DataProvider('platformProvider')]
    public function testCategoryAndProductAreFoundForEveryPlatform(string $platform): void
    {
        [$categoryPath, $productPath] = self::PLATFORM_PAGES[$platform];
        $routes = [
            $categoryPath => $this->fixture($platform, 'category'),
            $productPath  => $this->fixture($platform, 'product'),
        ];

        $result = crawlShopPages($this->context($platform), $this->request($routes));
        $pages = $result['facts']['pages'];

        $this->assertSame('done', $result['status']);
        $this->assertSame(self::ORIGIN . $categoryPath, $pages['category']['url'] ?? null, 'категория');
        $this->assertSame(self::ORIGIN . $productPath, $pages['product']['url'] ?? null, 'товар');
        $this->assertSame(200, $pages['product']['status']);
        $this->assertStringContainsString('Футболка', $pages['product']['body']);
        $this->assertSame(
            $platform === 'generic' ? CRAWL_FOUND_BY_LINKS : CRAWL_FOUND_BY_PATTERN,
            $pages['category']['found_by']
        );
    }

    public function testTildaJsCatalogIsReportedAsNotFound(): void
    {
        $result = crawlShopPages($this->context('tilda', [['key' => 'tilda', 'category' => 'cms']]), $this->request([]));

        $this->assertArrayNotHasKey('category', $result['facts']['pages']);
        $this->assertArrayNotHasKey('product', $result['facts']['pages']);
        $this->assertSame(CRAWL_REASON_JS_CATALOG, $result['facts']['missing']['category']);
        $this->assertSame(CRAWL_REASON_JS_CATALOG, $result['facts']['missing']['product']);
    }

    public function testCartAndCheckoutAreFoundByPlatformPaths(): void
    {
        $result = crawlShopPages($this->context('bitrix'), $this->request([
            '/personal/cart/'       => self::CART_HTML,
            '/personal/order/make/' => self::CHECKOUT_HTML,
        ]));

        $pages = $result['facts']['pages'];
        $this->assertSame(self::ORIGIN . '/personal/cart/', $pages['cart']['url']);
        $this->assertSame(CRAWL_FOUND_BY_PATTERN, $pages['cart']['found_by']);
        $this->assertSame(self::ORIGIN . '/personal/order/make/', $pages['checkout']['url']);
    }

    public function testUnknownCmsFindsCartByLinkAndExplainsMissingCheckout(): void
    {
        $result = crawlShopPages($this->context('generic'), $this->request(['/moya-korzinka/' => self::CART_HTML]));

        $this->assertSame(self::ORIGIN . '/moya-korzinka/', $result['facts']['pages']['cart']['url']);
        $this->assertSame(CRAWL_FOUND_BY_LINKS, $result['facts']['pages']['cart']['found_by']);
        $this->assertArrayNotHasKey('checkout', $result['facts']['pages']);
        $this->assertStringContainsString('типовые адреса не подошли', $result['facts']['missing']['checkout']);
    }

    public function testSoftNotFoundPageIsNotAcceptedAsCart(): void
    {
        $result = crawlShopPages($this->context('generic'), $this->request([
            '/cart/' => '<html><body><h1>Страница не найдена</h1></body></html>',
        ]));

        $this->assertArrayNotHasKey('cart', $result['facts']['pages']);
    }

    public function testManualProductIsUsedInsteadOfSearch(): void
    {
        $context = $this->context('bitrix');
        $context['diagnostic']['product_url'] = self::ORIGIN . '/catalog/odezhda/kurtka/';

        $result = crawlShopPages($context, $this->request([
            '/catalog/odezhda/'        => $this->fixture('bitrix', 'category'),
            '/catalog/odezhda/kurtka/' => $this->fixture('bitrix', 'product'),
        ]));

        $product = $result['facts']['pages']['product'];
        $this->assertSame(CRAWL_FOUND_BY_MANUAL, $product['found_by']);
        $this->assertSame(self::ORIGIN . '/catalog/odezhda/kurtka/', $product['url']);
        $this->assertNotContains(self::ORIGIN . '/catalog/odezhda/futbolka/', $this->requested);
        $this->assertSame([], $result['findings']);
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function brokenProductProvider(): array
    {
        return ['404' => [404, 'medium'], '500' => [500, 'high']];
    }

    #[DataProvider('brokenProductProvider')]
    public function testBrokenManualProductGivesFinding(int $status, string $severity): void
    {
        $context = $this->context('bitrix');
        $context['diagnostic']['product_url'] = self::ORIGIN . '/catalog/odezhda/net-takogo/';

        $result = crawlShopPages($context, $this->request([
            '/catalog/odezhda/net-takogo/' => ['status' => $status, 'body' => 'Ошибка'],
        ]));

        $this->assertSame($status, $result['facts']['pages']['product']['status']);
        $this->assertCount(1, $result['findings']);
        $this->assertSame('availability', $result['findings'][0]['category']);
        $this->assertSame($severity, $result['findings'][0]['severity']);
        $this->assertSame(self::ORIGIN . '/catalog/odezhda/net-takogo/', $result['findings'][0]['location']);
    }

    public function testForeignHostsAndFilesAreNeverRequested(): void
    {
        crawlShopPages($this->context('generic'), $this->request([]));

        $this->assertNotEmpty($this->requested);
        foreach ($this->requested as $url) {
            $this->assertStringStartsWith(self::ORIGIN . '/', $url);
            $this->assertStringNotContainsString('.pdf', $url);
        }
        $this->assertSame(count($this->requested), count(array_unique($this->requested)), 'адрес запрошен дважды');
    }

    public function testCrawlStopsWhenAllPagesAreFound(): void
    {
        $result = crawlShopPages($this->context('bitrix'), $this->request([
            '/catalog/odezhda/'           => $this->fixture('bitrix', 'category'),
            '/catalog/odezhda/futbolka/'  => $this->fixture('bitrix', 'product'),
            '/personal/cart/'             => self::CART_HTML,
            '/personal/order/make/'       => self::CHECKOUT_HTML,
        ]));

        $this->assertSame([], $result['facts']['missing']);
        $this->assertNotContains(self::ORIGIN . '/catalog/odezhda/kurtka/', $this->requested);
        $this->assertNotContains(self::ORIGIN . '/basket/', $this->requested);
        $this->assertSame(count($this->requested), $result['facts']['requests']);
    }

    public function testNoHomeSkipsStep(): void
    {
        $context = buildCrawlTestContext();

        $result = crawlShopPages($context, $this->request([]));

        $this->assertSame('skipped', $result['status']);
        $this->assertSame([], $this->requested);
    }

    public function testBrokenHtmlDoesNotBreakStep(): void
    {
        $context = $this->context('generic');
        $context['facts']['availability']['home']['body'] = "<html><body><a href='/catalog/'>Каталог<div><<<\x00";

        $result = crawlShopPages($context, $this->request(['/catalog/' => '<<<not html']));

        $this->assertSame('done', $result['status']);
        $this->assertArrayHasKey('category', $result['facts']['missing']);
    }

    public function testBotProtectionOnHomeIsExplained(): void
    {
        $context = $this->context('generic');
        $context['facts']['availability']['home']['status'] = 403;

        $result = crawlShopPages($context, $this->request([]));

        $this->assertSame(sprintf(CRAWL_REASON_BOT_PROTECTION, 403), $result['facts']['missing']['category']);
        $this->assertSame(sprintf(CRAWL_REASON_BOT_PROTECTION, 403), $result['facts']['missing']['product']);
    }

    public function testCategoryWithoutProductLinksIsNotConfirmed(): void
    {
        $result = crawlShopPages($this->context('generic'), $this->request([
            '/catalog/' => '<html><body><h1>Каталог</h1><p>Скоро здесь будут товары.</p></body></html>',
        ]));

        $this->assertSame(sprintf(CRAWL_REASON_NOT_CONFIRMED, 1, 'категорию'), $result['facts']['missing']['category']);
    }

    /**
     * Регрессия (citilink.ru): пункты меню с классами `nav-item` / `card` и кнопки «Купить» / «В корзину»
     * на обычной странице не делают её категорией или товаром.
     */
    public function testMenuItemsAndBuyButtonsDoNotFakeCategoryOrProduct(): void
    {
        $menu = '';
        foreach (['/services/digital/', '/corporate/', '/promo/', '/help/'] as $path) {
            $menu .= '<a href="' . $path . '" class="nav-item card-link">Раздел</a>';
        }
        $context = $this->context('generic');
        $context['facts']['availability']['home']['body'] = '<html><body><a href="/catalog/">Каталог</a>' . $menu . '</body></html>';
        $page = '<html><body>' . $menu . '<button>Купить</button><button>В корзину</button></body></html>';

        $result = crawlShopPages($context, $this->request(['/catalog/' => $page, '/corporate/' => $page]));

        $this->assertArrayNotHasKey('category', $result['facts']['pages']);
        $this->assertArrayNotHasKey('product', $result['facts']['pages']);
        $this->assertNotContains(self::ORIGIN . '/corporate/', $this->requested);
    }

    public function testMessageListsFoundAndMissingPages(): void
    {
        $result = crawlShopPages($this->context('generic'), $this->request([
            '/catalog/'            => $this->fixture('generic', 'category'),
            '/products/futbolka-1' => $this->fixture('generic', 'product'),
        ]));

        $this->assertStringStartsWith('Найдено: категория, товар; не найдено: корзина, оформление; запросов:', $result['message']);
    }

    /**
     * @param list<array<string, string>>|null $stack
     * @return array<string, mixed>
     */
    private function context(string $platform, ?array $stack = null): array
    {
        $context = buildCrawlTestContext();
        $context['facts']['availability']['home'] = [
            'url'                     => self::ORIGIN . '/',
            'final_url'               => self::ORIGIN . '/',
            'status'                  => 200,
            'headers'                 => [],
            'body'                    => $this->fixture($platform, 'home'),
            'truncated'               => false,
            'possible_bot_protection' => false,
        ];
        $context['facts']['stack']['items'] = $stack
            ?? self::STACK[$platform]
            ?? [['key' => $platform, 'category' => 'cms']];

        return $context;
    }

    private function fixture(string $platform, string $page): string
    {
        return (string) file_get_contents(self::FIXTURES . $platform . '/' . $page . '.html');
    }

    /**
     * Заглушка сети: путь → HTML (ответ 200) или ['status' => …, 'body' => …]; остальное — 404.
     *
     * @param array<string, string|array{status: int, body: string}> $routes
     */
    private function request(array $routes): callable
    {
        return function (string $url) use ($routes): array {
            $this->requested[] = $url;
            $path = substr($url, strlen(self::ORIGIN));
            $route = $routes[$path] ?? ['status' => 404, 'body' => 'Not found'];
            if (is_string($route)) {
                $route = ['status' => 200, 'body' => $route];
            }

            return [
                'final_url' => $url,
                'status'    => $route['status'],
                'headers'   => [],
                'body'      => $route['body'],
                'truncated' => false,
                'redirects' => [],
                'time_ms'   => 10,
                'error'     => null,
            ];
        };
    }
}

/**
 * Контекст шагов для тестов обхода (как buildStepContext(), без зависимости от DiagnosticRunner и БД).
 *
 * @return array<string, mixed>
 */
function buildCrawlTestContext(): array
{
    return [
        'diagnostic' => ['id' => 1, 'url' => 'https://shop.test/', 'host' => 'shop.test', 'product_url' => null, 'problem_text' => null],
        'facts'      => [],
    ];
}
