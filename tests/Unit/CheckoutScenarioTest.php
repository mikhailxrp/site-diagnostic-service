<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/src/Services/DiagnosticRunner.php';

/**
 * Чистые функции сценария заказа: без Chrome. Правила — настоящие data/checkout-selectors.php и data/shop-patterns.php.
 */
final class CheckoutScenarioTest extends TestCase
{
    private const ORIGIN = 'https://shop.test';

    /**
     * @param list<string> $platforms
     * @return array<string, mixed>
     */
    private function rules(array $platforms = []): array
    {
        return buildCheckoutRules(loadCheckoutSelectors(), loadShopPatterns(), $platforms);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function probe(array $overrides = []): array
    {
        return normalizeCheckoutProbe($overrides + ['url' => self::ORIGIN . '/product/chair/']);
    }

    /**
     * @return array<string, mixed>
     */
    private function candidate(int $index, string $text, bool $bySelector = false, array $extra = []): array
    {
        return $extra + ['index' => $index, 'text' => $text, 'attrs' => '', 'by_selector' => $bySelector, 'visible' => true,
            'disabled' => false];
    }

    // --- перехват запросов -------------------------------------------------------------------------------------

    public function testOnCheckoutEveryMutatingRequestIsBlockedAndGetPasses(): void
    {
        $patterns = $this->rules()['requests'];

        foreach (['POST', 'put', 'PATCH', 'DELETE'] as $method) {
            $decision = decideCheckoutRequest($method, self::ORIGIN . '/bitrix/tools/track.php', 'XHR', CHECKOUT_STEP_CHECKOUT, $patterns);
            $this->assertTrue($decision['block'], $method . ' на оформлении');
        }
        $this->assertFalse(decideCheckoutRequest('GET', self::ORIGIN . '/checkout/', 'Document', CHECKOUT_STEP_CHECKOUT, $patterns)['block']);
        $this->assertFalse(decideCheckoutRequest('GET', self::ORIGIN . '/order/confirm?x=1', 'XHR', CHECKOUT_STEP_CART, $patterns)['block']);
    }

    public function testBeforeCheckoutAddToCartPostPassesAndOrderPostIsBlocked(): void
    {
        $patterns = $this->rules()['requests'];
        $passes = [
            self::ORIGIN . '/?wc-ajax=add_to_cart',
            self::ORIGIN . '/index.php?route=checkout/cart/add',
            self::ORIGIN . '/checkout/cart/add/uenc/abc/product/5/',
            self::ORIGIN . '/catalog/chair/?action=ADD2BASKET&id=5',
            self::ORIGIN . '/cart_items.json',
            self::ORIGIN . '/bitrix/tools/track.php',
        ];
        foreach ($passes as $url) {
            $this->assertFalse(decideCheckoutRequest('POST', $url, 'XHR', CHECKOUT_STEP_ADD, $patterns)['block'], $url);
        }

        $blocked = [
            self::ORIGIN . '/order/',
            self::ORIGIN . '/checkout/',
            self::ORIGIN . '/personal/order/make/',
            self::ORIGIN . '/bitrix/components/bitrix/sale.order.ajax/ajax.php',
            self::ORIGIN . '/oformlenie-zakaza/',
            self::ORIGIN . '/local/ajax/quick_order.php',
            self::ORIGIN . '/payment/process',
        ];
        foreach ($blocked as $url) {
            $this->assertTrue(decideCheckoutRequest('POST', $url, 'XHR', CHECKOUT_STEP_PRODUCT, $patterns)['block'], $url);
        }
    }

    public function testOpeningCheckoutDocumentSwitchesToCheckoutStage(): void
    {
        $patterns = $this->rules()['requests'];

        $decision = decideCheckoutRequest('GET', self::ORIGIN . '/checkout/', 'Document', CHECKOUT_STEP_ADD, $patterns);
        $this->assertSame(CHECKOUT_STEP_CHECKOUT, $decision['stage'], '«Купить» сразу открыло оформление');
        $this->assertTrue(decideCheckoutRequest('POST', self::ORIGIN . '/cart/update', 'XHR', $decision['stage'], $patterns)['block']);

        $magentoCart = decideCheckoutRequest('GET', self::ORIGIN . '/checkout/cart/', 'Document', CHECKOUT_STEP_CART, $patterns);
        $this->assertSame(CHECKOUT_STEP_CART, $magentoCart['stage'], 'корзина Magento — ещё не оформление');
        $script = decideCheckoutRequest('GET', self::ORIGIN . '/checkout/', 'Script', CHECKOUT_STEP_ADD, $patterns);
        $this->assertSame(CHECKOUT_STEP_ADD, $script['stage'], 'стадию меняет только документ');
    }

    public function testStageNeverGoesBack(): void
    {
        $this->assertSame(CHECKOUT_STEP_CHECKOUT, raiseCheckoutStage(CHECKOUT_STEP_CHECKOUT, CHECKOUT_STEP_CART));
        $this->assertSame(CHECKOUT_STEP_CART, raiseCheckoutStage(CHECKOUT_STEP_ADD, CHECKOUT_STEP_CART));
    }

    public function testRequestLogKeepsOnlyMutatingRequestsWithoutQuery(): void
    {
        $stats = createCheckoutRequestStats();
        $stats = recordCheckoutRequest($stats, 'GET', self::ORIGIN . '/checkout/', CHECKOUT_STEP_CHECKOUT, false);
        $stats = recordCheckoutRequest($stats, 'POST', self::ORIGIN . '/order/?token=secret&phone=79990001122', CHECKOUT_STEP_CHECKOUT, true);
        $stats = recordCheckoutRequest($stats, 'post', self::ORIGIN . '/?wc-ajax=add_to_cart', CHECKOUT_STEP_ADD, false);

        $this->assertSame(1, $stats['blocked']);
        $this->assertSame(1, $stats['passed']);
        $this->assertSame(0, $stats['checkout_sent']);
        $this->assertSame([
            ['stage' => CHECKOUT_STEP_CHECKOUT, 'method' => 'POST', 'url' => self::ORIGIN . '/order/', 'action' => CHECKOUT_ACTION_BLOCKED],
            ['stage' => CHECKOUT_STEP_ADD, 'method' => 'POST', 'url' => self::ORIGIN . '/', 'action' => CHECKOUT_ACTION_PASSED],
        ], $stats['log']);
        $this->assertStringNotContainsString('secret', (string) json_encode($stats));

        $stats = recordCheckoutRequest($stats, 'POST', self::ORIGIN . '/track', CHECKOUT_STEP_CHECKOUT, false);
        $this->assertSame(1, $stats['checkout_sent'], 'пропущенный на оформлении запрос виден в счётчике');

        for ($i = 0; $i < CHECKOUT_REQUEST_LOG_MAX; $i++) {
            $stats = recordCheckoutRequest($stats, 'POST', self::ORIGIN . '/order/', CHECKOUT_STEP_CHECKOUT, true);
        }
        $this->assertCount(CHECKOUT_REQUEST_LOG_MAX, $stats['log']);
        $this->assertSame(3, $stats['dropped']);
    }

    // --- кнопка «В корзину» ------------------------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function forbiddenButtons(): array
    {
        return [
            'в 1 клик'        => ['купить в 1 клик', ''],
            'в один клик'     => ['купить в один клик', ''],
            'быстрый заказ'   => ['быстрый заказ', ''],
            'оформить'        => ['оформить заказ', ''],
            'заказать'        => ['заказать', ''],
            'подтвердить'     => ['подтвердить', ''],
            'рассрочка'       => ['купить в рассрочку', ''],
            'checkout'        => ['proceed to checkout', ''],
            'place order'     => ['place order', ''],
            'класс oneclick'  => ['купить', 'btn buy-oneclick'],
            'класс 1-click'   => ['', 'js-1-click-buy'],
            'ссылка checkout' => ['в корзину', '/checkout/?add-to-cart=5'],
            'quick order'     => ['купить', 'quick-order-btn'],
        ];
    }

    #[DataProvider('forbiddenButtons')]
    public function testForbiddenButtonIsNeverChosen(string $text, string $attrs): void
    {
        $rules = $this->rules();
        $button = $this->candidate(0, $text, true, ['attrs' => $attrs]);

        $this->assertTrue(isForbiddenCheckoutButton($button, $rules));
        $this->assertNull(chooseAddToCartButton([$button], $rules));
    }

    public function testEnabledSelectorButtonWinsOverTextAndHiddenIsIgnored(): void
    {
        $rules = $this->rules();
        $candidates = [
            $this->candidate(0, 'в корзину', true, ['visible' => false]),
            $this->candidate(1, 'купить в 1 клик', true),
            $this->candidate(2, 'в корзину'),
            $this->candidate(3, '', true, ['disabled' => true]),
            $this->candidate(4, '', true),
        ];

        $this->assertSame(4, chooseAddToCartButton($candidates, $rules)['index']);
        $this->assertSame(2, chooseAddToCartButton(array_slice($candidates, 0, 3), $rules)['index'], 'по тексту, если по селектору нет');
        $this->assertSame(3, chooseAddToCartButton([$candidates[3]], $rules)['index'], 'только неактивная — вернётся она');
        $this->assertNull(chooseAddToCartButton([$this->candidate(0, 'подробнее')], $rules));
    }

    public function testAddToCartTexts(): void
    {
        $rules = $this->rules();
        foreach (['в корзину', 'добавить в корзину', 'купить', 'add to cart', 'buy now'] as $text) {
            $this->assertTrue(isAddToCartText($text, $rules), $text);
        }
        foreach (['перейти в корзину', 'корзина', 'купить в 1 клик', 'buy one click'] as $text) {
            $this->assertFalse(
                isAddToCartText($text, $rules) && !isForbiddenCheckoutButton($this->candidate(0, $text), $rules),
                $text
            );
        }
    }

    public function testProductPageAnalysis(): void
    {
        $rules = $this->rules(['woocommerce']);
        $button = $this->candidate(0, 'в корзину', true);

        $variants = analyzeCheckoutProductPage($this->probe(['counts' => ['variants' => 1], 'candidates' => [$button]]), $rules);
        $this->assertSame(CHECKOUT_REASON_VARIANTS, $variants['reason']);

        $outOfStock = analyzeCheckoutProductPage($this->probe(['text' => 'стул венский нет в наличии']), $rules);
        $this->assertSame(CHECKOUT_REASON_OUT_OF_STOCK, $outOfStock['reason']);

        $noButton = analyzeCheckoutProductPage($this->probe(['text' => 'стул венский 1 200 ₽']), $rules);
        $this->assertSame(CHECKOUT_REASON_BUTTON_NOT_FOUND, $noButton['reason']);

        $disabled = analyzeCheckoutProductPage(
            $this->probe(['candidates' => [$this->candidate(0, 'в корзину', true, ['disabled' => true])]]),
            $rules
        );
        $this->assertSame(CHECKOUT_REASON_BUTTON_DISABLED, $disabled['reason']);

        $soldOut = analyzeCheckoutProductPage($this->probe([
            'counts'     => ['out_of_stock' => 1],
            'candidates' => [$this->candidate(0, 'в корзину', true, ['disabled' => true])],
        ]), $rules);
        $this->assertSame(CHECKOUT_REASON_OUT_OF_STOCK, $soldOut['reason'], 'неактивна из-за наличия — не поломка');

        $ok = analyzeCheckoutProductPage($this->probe(['candidates' => [$button]]), $rules);
        $this->assertNull($ok['reason']);
        $this->assertSame(0, $ok['button']['index']);
    }

    public function testBotProtectionOpenedOnlyInChromeIsDetected(): void
    {
        $blocked = $this->probe([
            'html' => '<html><head><title>Just a moment...</title></head><body>Checking your browser</body></html>',
        ]);

        $this->assertSame('Cloudflare', detectCheckoutBotProtection($blocked)['title']);
        $this->assertNull(detectCheckoutBotProtection($this->probe([
            'html' => '<html><body><main><h1>Стул</h1><p>' . str_repeat('Описание товара. ', 100) . '</p></main></body></html>',
        ])));
    }

    // --- сигнал добавления, корзина, форма --------------------------------------------------------------------

    public function testAddToCartSignals(): void
    {
        $rules = $this->rules();
        $before = $this->probe(['cart_count' => '0', 'text' => 'стул в корзину']);

        $this->assertNull(detectAddToCartSignal($before, $before, [], $rules));
        $this->assertSame(CHECKOUT_SIGNAL_COUNTER, detectAddToCartSignal($before, $this->probe(['cart_count' => '1']), [], $rules));
        $this->assertSame(
            CHECKOUT_SIGNAL_MESSAGE,
            detectAddToCartSignal($before, $this->probe(['cart_count' => '0', 'text' => 'товар добавлен в корзину']), [], $rules)
        );
        $this->assertSame(
            CHECKOUT_SIGNAL_NAVIGATION,
            detectAddToCartSignal($before, $this->probe(['url' => self::ORIGIN . '/cart/', 'cart_count' => '0']), [], $rules)
        );

        $responses = [['url' => self::ORIGIN . '/ajax/basket.php', 'status' => 200, 'type' => 'XHR', 'method' => 'POST']];
        $this->assertSame(CHECKOUT_SIGNAL_REQUEST, detectAddToCartSignal($before, $before, $responses, $rules));
        $failed = [['url' => self::ORIGIN . '/?wc-ajax=add_to_cart', 'status' => 500, 'type' => 'XHR', 'method' => 'POST']];
        $this->assertNull(detectAddToCartSignal($before, $before, $failed, $rules), 'ответ 500 — не сигнал');
        $analytics = [['url' => 'https://mc.yandex.ru/watch/1', 'status' => 200, 'type' => 'Image', 'method' => 'GET']];
        $this->assertNull(detectAddToCartSignal($before, $before, $analytics, $rules));
    }

    public function testCartClassification(): void
    {
        $rules = $this->rules();

        $this->assertSame(CHECKOUT_CART_ITEMS, classifyCheckoutCart($this->probe(['counts' => ['cart_item' => 1]]), $rules));
        $this->assertSame(CHECKOUT_CART_EMPTY, classifyCheckoutCart($this->probe(['text' => 'ваша корзина пуста']), $rules));
        $this->assertSame(CHECKOUT_CART_EMPTY, classifyCheckoutCart($this->probe(['counts' => ['cart_empty' => 1]]), $rules));
        $this->assertSame(CHECKOUT_CART_UNKNOWN, classifyCheckoutCart($this->probe(['text' => 'корзина итого 1 200 ₽']), $rules));
        $this->assertSame(CHECKOUT_CART_NOT_CART, classifyCheckoutCart($this->probe(['text' => 'страница не найдена']), $rules));
    }

    public function testCheckoutFormNeedsPlatformBlockOrThreeContactFields(): void
    {
        $this->assertFalse(hasCheckoutForm($this->probe(['forms' => [['contact_fields' => 2]]])), 'обратный звонок');
        $this->assertTrue(hasCheckoutForm($this->probe(['forms' => [['contact_fields' => 1], ['contact_fields' => 4]]])));
        $this->assertTrue(hasCheckoutForm($this->probe(['counts' => ['checkout_form' => 1]])));
        $this->assertFalse(hasCheckoutForm($this->probe()));
    }

    // --- адреса и цель -----------------------------------------------------------------------------------------

    public function testCartCandidatesInOrderWithoutDuplicatesAndForeignHosts(): void
    {
        $rules = $this->rules(['woocommerce']);
        $links = [
            ['href' => 'https://other.test/cart/', 'text' => 'корзина'],
            ['href' => self::ORIGIN . '/basket/#top', 'text' => 'Корзина'],
            ['href' => self::ORIGIN . '/cart/', 'text' => ''],
            ['href' => 'javascript:void(0)', 'text' => 'корзина'],
        ];

        $candidates = buildCheckoutUrlCandidates(
            CHECKOUT_STEP_CART,
            ['url' => self::ORIGIN . '/cart', 'found_by' => CRAWL_FOUND_BY_PATTERN],
            $links,
            $rules,
            self::ORIGIN,
            'shop.test'
        );

        $this->assertSame(self::ORIGIN . '/cart', $candidates[0]['url'], 'сначала из обхода');
        $this->assertSame(self::ORIGIN . '/basket/', $candidates[1]['url'], 'потом ссылка, без фрагмента');
        $this->assertSame(CRAWL_FOUND_BY_LINKS, $candidates[1]['found_by']);
        $urls = array_column($candidates, 'url');
        $this->assertNotContains(self::ORIGIN . '/cart/', $urls, '/cart/ = /cart');
        $this->assertNotContains('https://other.test/cart/', $urls);
        $this->assertContains(self::ORIGIN . '/korzina/', $urls, 'типовые пути платформы');
    }

    public function testTargetFromCrawlOrProductUrl(): void
    {
        $context = buildStepContext(['id' => 1, 'url' => self::ORIGIN . '/', 'host' => 'shop.test',
            'product_url' => self::ORIGIN . '/manual/']);
        $this->assertSame(CRAWL_FOUND_BY_MANUAL, selectCheckoutTarget($context)['found_by'], 'без обхода — адрес из формы');
        $this->assertSame(self::ORIGIN . '/manual/', selectCheckoutTarget($context)['url']);

        $context['facts']['crawl']['pages'] = [
            'product'  => ['url' => self::ORIGIN . '/p', 'final_url' => self::ORIGIN . '/p/', 'status' => 200,
                'found_by' => CRAWL_FOUND_BY_LINKS, 'bot_protection' => null],
            'cart'     => ['url' => self::ORIGIN . '/cart/', 'final_url' => '', 'status' => 200, 'found_by' => 'cms_pattern',
                'bot_protection' => null],
            'checkout' => ['url' => self::ORIGIN . '/checkout/', 'final_url' => '', 'status' => 404, 'found_by' => 'cms_pattern',
                'bot_protection' => null],
        ];
        $target = selectCheckoutTarget($context);
        $this->assertNull($target['skip']);
        $this->assertSame(self::ORIGIN . '/p/', $target['url']);
        $this->assertSame(self::ORIGIN . '/cart/', $target['cart']['url']);
        $this->assertNull($target['checkout'], 'оформление с 404 — не адрес');

        $context['facts']['crawl']['pages']['product']['bot_protection'] = ['title' => 'DDoS-Guard', 'reason' => 'x'];
        $blocked = selectCheckoutTarget($context);
        $this->assertSame(CHECKOUT_REASON_BOT_PROTECTION, $blocked['skip']);
        $this->assertSame('DDoS-Guard', $blocked['detail']);

        $empty = buildStepContext(['id' => 1, 'url' => self::ORIGIN . '/', 'host' => 'shop.test']);
        $this->assertSame(CHECKOUT_REASON_NO_PRODUCT, selectCheckoutTarget($empty)['skip']);
    }

    public function testRulesPutPlatformSelectorsFirst(): void
    {
        $rules = $this->rules(['woocommerce']);
        $this->assertSame('button.single_add_to_cart_button', $rules['css']['add_to_cart'][0]);
        $this->assertContains('.add-to-cart', $rules['css']['add_to_cart']);
        $this->assertFalse($rules['inline_cart']);
        $this->assertTrue($this->rules(['tilda'])['inline_cart']);
        $this->assertSame([], array_diff(CHECKOUT_CSS_KEYS, array_keys($rules['css'])));
    }

    public function testProbeScriptGetsConfig(): void
    {
        $script = buildCheckoutProbeScript($this->rules(['bitrix']));

        $this->assertStringNotContainsString(CHECKOUT_PROBE_PLACEHOLDER, $script);
        $this->assertStringContainsString('"maxText":' . CHECKOUT_PAGE_TEXT_MAX_CHARS, $script);
        $this->assertStringContainsString('"maxHtml":' . CHECKOUT_PAGE_HTML_MAX_CHARS, $script);
        $this->assertStringContainsString('#bx-soa-order-form', $script);
    }

    public function testConsoleSummaryCountsOnlyScriptErrors(): void
    {
        $events = [
            ['kind' => BROWSER_EVENT_EXCEPTION, 'message' => str_repeat('a', 300)],
            ['kind' => BROWSER_EVENT_RESPONSE, 'message' => '404'],
            ['kind' => BROWSER_EVENT_CONSOLE, 'message' => 'b'],
            ['kind' => BROWSER_EVENT_LOG, 'message' => 'c'],
            ['kind' => BROWSER_EVENT_LOG, 'message' => 'Failed to load resource: net::ERR_BLOCKED_BY_CLIENT.Inspector'],
        ];

        $summary = summarizeCheckoutConsole($events, 2);
        $this->assertSame(5, $summary['count']);
        $this->assertCount(CHECKOUT_CONSOLE_SAMPLE, $summary['sample']);
        $this->assertSame(CHECKOUT_CONSOLE_MESSAGE_MAX_CHARS, mb_strlen($summary['sample'][0]));
    }
}
