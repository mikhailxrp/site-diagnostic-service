<?php

declare(strict_types=1);

namespace Tests\Unit;

use HeadlessChromium\Browser;
use HeadlessChromium\Communication\Connection;
use HeadlessChromium\Communication\Socket\MockSocket;
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
        return $extra + ['index' => $index, 'text' => $text, 'attrs' => '', 'form_action' => '',
            'by_selector' => $bySelector, 'visible' => true, 'disabled' => false];
    }

    // --- перехват запросов -------------------------------------------------------------------------------------

    public function testOnCheckoutEveryMutatingRequestIsBlockedAndSafeGetPasses(): void
    {
        $patterns = $this->rules()['requests'];

        foreach (['POST', 'put', 'PATCH', 'DELETE'] as $method) {
            $decision = decideCheckoutRequest($method, self::ORIGIN . '/bitrix/tools/track.php', 'XHR', CHECKOUT_STEP_CHECKOUT, $patterns);
            $this->assertTrue($decision['block'], $method . ' на оформлении');
        }
        $this->assertFalse(decideCheckoutRequest('GET', self::ORIGIN . '/checkout/', 'Document', CHECKOUT_STEP_CHECKOUT, $patterns)['block']);
        $this->assertTrue(decideCheckoutRequest('GET', self::ORIGIN . '/order/confirm?x=1', 'XHR', CHECKOUT_STEP_CART, $patterns)['block']);
    }

    public function testBeforeCheckoutAddToCartPostPassesAndDefaultDenyBlocksUnknownOrOrderPost(): void
    {
        $patterns = $this->rules()['requests'];
        $passes = [
            self::ORIGIN . '/?wc-ajax=add_to_cart',
            self::ORIGIN . '/index.php?route=checkout/cart/add',
            self::ORIGIN . '/index.php?route=checkout/cart.add',
            self::ORIGIN . '/checkout/cart/add/uenc/abc/product/5/',
            self::ORIGIN . '/catalog/chair/?action=ADD2BASKET&id=5',
            self::ORIGIN . '/cart_items.json',
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
            self::ORIGIN . '/bitrix/tools/track.php',
        ];
        foreach ($blocked as $url) {
            $this->assertTrue(decideCheckoutRequest('POST', $url, 'XHR', CHECKOUT_STEP_PRODUCT, $patterns)['block'], $url);
        }
    }

    public function testDecideCheckoutRequestInspectsGetTypesPostBodyAndCheckoutStage(): void
    {
        $patterns = $this->rules()['requests'];

        // GET на «заказной» адрес или быстрый заказ блокируется для XHR/Fetch/Ping/Other/PHP-скриптов
        foreach (['XHR', 'Fetch', 'Ping', 'Other'] as $type) {
            $this->assertTrue(
                decideCheckoutRequest('GET', self::ORIGIN . '/order/confirm?x=1', $type, CHECKOUT_STEP_CART, $patterns)['block'],
                $type
            );
        }
        $this->assertTrue(decideCheckoutRequest('GET', self::ORIGIN . '/ajax/oneclick.php', 'XHR', CHECKOUT_STEP_ADD, $patterns)['block']);
        $this->assertTrue(decideCheckoutRequest('GET', self::ORIGIN . '/ajax/oneclick.php', 'Document', CHECKOUT_STEP_ADD, $patterns)['block']);
        $this->assertTrue(decideCheckoutRequest('GET', self::ORIGIN . '/ajax/order.php', 'Script', CHECKOUT_STEP_CHECKOUT, $patterns)['block']);

        // Безопасные статические ассеты и страница оформления по GET разрешены
        $this->assertFalse(decideCheckoutRequest('GET', self::ORIGIN . '/checkout/', 'Document', CHECKOUT_STEP_CART, $patterns)['block']);
        $this->assertFalse(decideCheckoutRequest(
            'GET',
            self::ORIGIN . '/wp-content/plugins/woocommerce/assets/js/frontend/checkout.min.js',
            'Script',
            CHECKOUT_STEP_CHECKOUT,
            $patterns
        )['block']);
        $this->assertFalse(decideCheckoutRequest(
            'GET',
            self::ORIGIN . '/bitrix/components/bitrix/sale.order.ajax/templates/.default/style.css',
            'Stylesheet',
            CHECKOUT_STEP_CHECKOUT,
            $patterns
        )['block']);
        $this->assertFalse(decideCheckoutRequest('GET', self::ORIGIN . '/img/payment.svg', 'Image', CHECKOUT_STEP_CHECKOUT, $patterns)['block']);

        // Общие RPC-эндпоинты проверяются по телу POST (default-DENY)
        $this->assertFalse(decideCheckoutRequest(
            'POST',
            self::ORIGIN . '/wp-admin/admin-ajax.php',
            'XHR',
            CHECKOUT_STEP_ADD,
            $patterns,
            'action=woocommerce_add_to_cart&product_id=5'
        )['block']);
        $this->assertTrue(decideCheckoutRequest(
            'POST',
            self::ORIGIN . '/wp-admin/admin-ajax.php',
            'XHR',
            CHECKOUT_STEP_ADD,
            $patterns,
            'action=create_order&phone=79990001122'
        )['block']);
        $this->assertTrue(decideCheckoutRequest(
            'POST',
            self::ORIGIN . '/wp-admin/admin-ajax.php',
            'XHR',
            CHECKOUT_STEP_ADD,
            $patterns,
            ''
        )['block']);
        $this->assertTrue(decideCheckoutRequest(
            'POST',
            self::ORIGIN . '/wp-admin/admin-ajax.php',
            'XHR',
            CHECKOUT_STEP_ADD,
            $patterns,
            'action=foo'
        )['block']);

        // Заказной URL блокируется даже при «корзинном» теле
        $this->assertTrue(decideCheckoutRequest(
            'POST',
            self::ORIGIN . '/ajax/oneclick.php',
            'XHR',
            CHECKOUT_STEP_ADD,
            $patterns,
            'action=woocommerce_add_to_cart'
        )['block']);

        // GraphQL и прочие RPC
        $this->assertFalse(decideCheckoutRequest(
            'POST',
            self::ORIGIN . '/graphql',
            'Fetch',
            CHECKOUT_STEP_ADD,
            $patterns,
            '{"operationName":"AddToCart"}'
        )['block']);
        $this->assertTrue(decideCheckoutRequest(
            'POST',
            self::ORIGIN . '/graphql',
            'Fetch',
            CHECKOUT_STEP_ADD,
            $patterns,
            '{"operationName":"PlaceOrder"}'
        )['block']);
        $this->assertTrue(decideCheckoutRequest(
            'POST',
            self::ORIGIN . '/graphql',
            'Fetch',
            CHECKOUT_STEP_ADD,
            $patterns,
            '{"operationName":"AddToCart","next":"checkout"}'
        )['block']);
        $this->assertTrue(decideCheckoutRequest(
            'POST',
            self::ORIGIN . '/api/buy',
            'Fetch',
            CHECKOUT_STEP_ADD,
            $patterns,
            '{"id":1}'
        )['block']);
        $this->assertFalse(decideCheckoutRequest(
            'POST',
            self::ORIGIN . '/catalog/chair/',
            'Fetch',
            CHECKOUT_STEP_ADD,
            $patterns,
            'action=ADD2BASKET&id=5'
        )['block']);

        // На стадии checkout блокируется даже add_to_cart
        $this->assertTrue(decideCheckoutRequest(
            'POST',
            self::ORIGIN . '/?wc-ajax=add_to_cart',
            'XHR',
            CHECKOUT_STEP_CHECKOUT,
            $patterns,
            'action=woocommerce_add_to_cart'
        )['block']);
    }

    public function testExtractCheckoutRequestPostDataHandlesRawEntriesAndTruncation(): void
    {
        $this->assertSame('a=1', extractCheckoutRequestPostData(['postData' => 'a=1']));
        $this->assertSame(
            'action=add_to_cart',
            extractCheckoutRequestPostData([
                'postDataEntries' => [
                    ['bytes' => base64_encode('action=')],
                    ['bytes' => base64_encode('add_to_cart')],
                ],
            ])
        );
        $this->assertSame(
            CHECKOUT_POST_DATA_MAX_BYTES,
            strlen((string) extractCheckoutRequestPostData(['postData' => str_repeat('x', CHECKOUT_POST_DATA_MAX_BYTES + 100)]))
        );
        $this->assertNull(extractCheckoutRequestPostData(['postData' => '']));
        $this->assertNull(extractCheckoutRequestPostData([]));
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

    public function testRequestLogKeepsMutatingAndBlockedRequestsWithoutQuery(): void
    {
        $stats = createCheckoutRequestStats();
        $stats = recordCheckoutRequest($stats, 'GET', self::ORIGIN . '/checkout/', CHECKOUT_STEP_CHECKOUT, false);
        $stats = recordCheckoutRequest($stats, 'GET', self::ORIGIN . '/ajax/oneclick.php?phone=123', CHECKOUT_STEP_ADD, true);
        $stats = recordCheckoutRequest($stats, 'POST', self::ORIGIN . '/order/?token=secret&phone=79990001122', CHECKOUT_STEP_CHECKOUT, true);
        $stats = recordCheckoutRequest($stats, 'post', self::ORIGIN . '/?wc-ajax=add_to_cart', CHECKOUT_STEP_ADD, false);

        $this->assertSame(2, $stats['blocked']);
        $this->assertSame(1, $stats['passed']);
        $this->assertSame(0, $stats['checkout_sent']);
        $this->assertSame([
            ['stage' => CHECKOUT_STEP_ADD, 'method' => 'GET', 'url' => self::ORIGIN . '/ajax/oneclick.php', 'action' => CHECKOUT_ACTION_BLOCKED],
            ['stage' => CHECKOUT_STEP_CHECKOUT, 'method' => 'POST', 'url' => self::ORIGIN . '/order/', 'action' => CHECKOUT_ACTION_BLOCKED],
            ['stage' => CHECKOUT_STEP_ADD, 'method' => 'POST', 'url' => self::ORIGIN . '/', 'action' => CHECKOUT_ACTION_PASSED],
        ], $stats['log']);
        $this->assertStringNotContainsString('secret', (string) json_encode($stats));
        $this->assertStringNotContainsString('phone=123', (string) json_encode($stats));

        $stats = recordCheckoutRequest($stats, 'POST', self::ORIGIN . '/track', CHECKOUT_STEP_CHECKOUT, false);
        $this->assertSame(1, $stats['checkout_sent'], 'пропущенный на оформлении запрос виден в счётчике');

        for ($i = 0; $i < CHECKOUT_REQUEST_LOG_MAX; $i++) {
            $stats = recordCheckoutRequest($stats, 'POST', self::ORIGIN . '/order/', CHECKOUT_STEP_CHECKOUT, true);
        }
        $this->assertCount(CHECKOUT_REQUEST_LOG_MAX, $stats['log']);
        $this->assertSame(4, $stats['dropped']);
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

    public function testButtonFormActionAndDataAttrsAreCheckedSafely(): void
    {
        $rules = $this->rules();

        $forbidden = [
            'buy now с селектором'        => $this->candidate(0, 'buy now', true),
            'купить в форме order/create' => $this->candidate(1, 'купить', true, ['form_action' => '/order/create']),
            'купить в форме oneclick'     => $this->candidate(2, 'купить', true, ['form_action' => '/ajax/oneclick.php']),
            'купить с data-url quick'     => $this->candidate(3, 'купить', true, ['attrs' => 'data-url /ajax/quick_order.php']),
            'купить со ссылкой order/new' => $this->candidate(4, 'купить', true, ['attrs' => 'href /order/new']),
            'в корзину с data-target'     => $this->candidate(5, 'в корзину', true, ['attrs' => 'data-target #order-modal']),
        ];
        foreach ($forbidden as $label => $button) {
            $this->assertTrue(isForbiddenCheckoutButton($button, $rules), $label);
            $this->assertNull(chooseAddToCartButton([$button], $rules), $label);
        }

        $allowed = [
            'magento form action' => $this->candidate(6, 'add to cart', true, ['form_action' => '/checkout/cart/add/product/5/']),
            'bootstrap order-1'   => $this->candidate(7, 'в корзину', true, ['attrs' => 'btn btn-primary order-1']),
            'купить в cart/add'   => $this->candidate(8, 'купить', true, ['form_action' => '/cart/add']),
        ];
        foreach ($allowed as $label => $button) {
            $this->assertFalse(isForbiddenCheckoutButton($button, $rules), $label);
            $this->assertNotNull(chooseAddToCartButton([$button], $rules), $label);
        }
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
        foreach (['в корзину', 'добавить в корзину', 'купить', 'add to cart'] as $text) {
            $this->assertTrue(isAddToCartText($text, $rules), $text);
        }
        foreach (['buy', 'buy now'] as $text) {
            $this->assertFalse(isAddToCartText($text, $rules), $text);
        }
        $this->assertTrue(isForbiddenCheckoutButton($this->candidate(0, 'buy now', true), $rules));
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
        $this->assertStringContainsString('data-url', $script);
        $this->assertStringContainsString('data-href', $script);
        $this->assertStringContainsString('data-target', $script);
        $this->assertStringContainsString("closest('form')", $script);
        $this->assertStringContainsString('form_action', $script);
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

    // --- CDP-перехват (MockSocket) -----------------------------------------------------------------------------

    public function testBuildCheckoutBrowserOptionsDisablesPopups(): void
    {
        $options = buildCheckoutBrowserOptions();

        $this->assertSame([BROWSER_DISABLE_POPUP_BLOCKING_SWITCH], $options['excludedSwitches']);
        $this->assertSame([BROWSER_BLOCK_NEW_CONTENTS_FLAG], $options['customFlags']);
    }

    public function testAttachCheckoutListenersEnforcesSsrfPostBodyGetAndChildTargets(): void
    {
        $socket = $this->createFakeCdpSocket();
        $connection = new Connection($socket);
        $connection->connect();
        $browser = new Browser($connection);
        $page = $browser->createPage();
        $mainSession = $page->getSession();
        $mainSessionId = $mainSession->getSessionId();

        $run = [
            'stage'             => CHECKOUT_STEP_ADD,
            'requests'          => createCheckoutRequestStats(),
            'events'            => createBrowserEventState(null),
            'recording'         => false,
            'methods'           => [],
            'responses'         => [],
            'ssrf_blocked_main' => false,
        ];
        $resolver = static fn (string $host): array => in_array($host, ['shop.test', 'pay.test'], true)
            ? ['93.184.216.34']
            : ['127.0.0.1'];

        $cleanup = attachCheckoutListeners($page, $run, $this->rules(), $resolver);

        $setupMethods = array_column($this->decodeSentMessages($socket->getSentData()), 'method');
        $this->assertContains('Network.setBypassServiceWorker', $setupMethods);
        $this->assertContains('Fetch.enable', $setupMethods);
        $this->assertContains('Target.setAutoAttach', $setupMethods);

        $socket->flushData();

        // 1. SSRF на главном фрейме
        $mainSession->emit('method:Fetch.requestPaused', [[
            'requestId'    => 'req-ssrf',
            'resourceType' => CHECKOUT_DOCUMENT_TYPE,
            'frameId'      => 'frame-1',
            'request'      => ['method' => 'GET', 'url' => 'http://127.0.0.1/settings'],
        ]]);
        $this->assertTrue($run['ssrf_blocked_main']);

        // 2. Разрешённый POST добавления в корзину через общий admin-ajax.php
        $mainSession->emit('method:Fetch.requestPaused', [[
            'requestId'    => 'req-add',
            'resourceType' => 'XHR',
            'frameId'      => 'frame-1',
            'request'      => [
                'method'   => 'POST',
                'url'      => self::ORIGIN . '/wp-admin/admin-ajax.php',
                'postData' => 'action=woocommerce_add_to_cart&product_id=7',
            ],
        ]]);

        // 3. Заблокированный POST создания заказа через общий admin-ajax.php
        $mainSession->emit('method:Fetch.requestPaused', [[
            'requestId'    => 'req-order',
            'resourceType' => 'XHR',
            'frameId'      => 'frame-1',
            'request'      => [
                'method'   => 'POST',
                'url'      => self::ORIGIN . '/wp-admin/admin-ajax.php',
                'postData' => 'action=create_order&phone=123',
            ],
        ]]);

        // 4. Заблокированный GET быстрого заказа
        $mainSession->emit('method:Fetch.requestPaused', [[
            'requestId'    => 'req-get-oneclick',
            'resourceType' => 'XHR',
            'frameId'      => 'frame-1',
            'request'      => ['method' => 'GET', 'url' => self::ORIGIN . '/ajax/oneclick.php?id=7'],
        ]]);

        // 5. Дочерний iframe оплаты: перехватывается отдельно и блокирует POST заказа
        $mainSession->emit('method:Target.attachedToTarget', [[
            'sessionId'          => 'child-pay-1',
            'waitingForDebugger' => true,
            'targetInfo'         => ['targetId' => 'iframe-pay-1', 'type' => 'iframe'],
        ]]);
        $socket->addReceivedData(json_encode([
            'method'    => 'Fetch.requestPaused',
            'sessionId' => 'child-pay-1',
            'params'    => [
                'requestId'    => 'req-child-order',
                'resourceType' => 'XHR',
                'request'      => ['method' => 'POST', 'url' => 'https://pay.test/order/confirm', 'postData' => 'ok=1'],
            ],
        ], JSON_THROW_ON_ERROR));
        $connection->readData();

        $sent = $this->decodeSentMessages($socket->getSentData());
        $this->assertNotNull($this->findSentMessage($sent, 'Fetch.failRequest', $mainSessionId, [
            'requestId'   => 'req-ssrf',
            'errorReason' => CHECKOUT_BLOCK_REASON,
        ]));
        $this->assertNotNull($this->findSentMessage($sent, 'Fetch.continueRequest', $mainSessionId, [
            'requestId' => 'req-add',
        ]));
        $this->assertNotNull($this->findSentMessage($sent, 'Fetch.failRequest', $mainSessionId, [
            'requestId'   => 'req-order',
            'errorReason' => CHECKOUT_BLOCK_REASON,
        ]));
        $this->assertNotNull($this->findSentMessage($sent, 'Fetch.failRequest', $mainSessionId, [
            'requestId'   => 'req-get-oneclick',
            'errorReason' => CHECKOUT_BLOCK_REASON,
        ]));
        $this->assertNotNull($this->findSentMessage($sent, 'Fetch.enable', 'child-pay-1'));
        $this->assertNotNull($this->findSentMessage($sent, 'Runtime.runIfWaitingForDebugger', 'child-pay-1'));
        $this->assertNotNull($this->findSentMessage($sent, 'Fetch.failRequest', 'child-pay-1', [
            'requestId'   => 'req-child-order',
            'errorReason' => CHECKOUT_BLOCK_REASON,
        ]));

        $this->assertSame(4, $run['requests']['blocked']);
        $this->assertSame(1, $run['requests']['passed']);
        $this->assertSame(0, $run['requests']['checkout_sent']);
        $this->assertContains([
            'stage'  => CHECKOUT_STEP_ADD,
            'method' => 'GET',
            'url'    => self::ORIGIN . '/ajax/oneclick.php',
            'action' => CHECKOUT_ACTION_BLOCKED,
        ], $run['requests']['log']);

        $socket->flushData();
        $cleanup();
        $this->assertNull($this->findSentMessage($this->decodeSentMessages($socket->getSentData()), 'Fetch.disable'));
    }

    public function testRunCheckoutScenarioSkipsLocalTargetAndNeverSendsFetchDisable(): void
    {
        $socket = $this->createFakeCdpSocket();
        $connection = new Connection($socket);
        $connection->connect();
        $browser = new Browser($connection);
        $socket->flushData();

        $resolver = static fn (string $host): array => $host === 'shop.test' ? ['93.184.216.34'] : ['127.0.0.1'];
        $rules = $this->rules();

        $blockedTarget = runCheckoutScenario(
            $browser,
            ['url' => 'http://127.0.0.1/product', 'found_by' => CRAWL_FOUND_BY_MANUAL, 'cart' => null, 'checkout' => null],
            $rules,
            '127.0.0.1',
            null,
            $resolver
        );
        $this->assertSame(CHECKOUT_OUTCOME_SKIPPED, $blockedTarget['outcome']);
        $this->assertSame(CHECKOUT_REASON_PRODUCT_NOT_OPENED, $blockedTarget['reason']);
        $this->assertSame(BROWSER_SSRF_BLOCKED_ERROR, $blockedTarget['detail']);
        $this->assertSame([], $socket->getSentData(), 'для локального URL вкладка даже не создаётся');

        // Публичный URL, уводящий редиректом на 127.0.0.1: вкладка закрывается без Fetch.disable
        $redirectSocket = $this->createFakeCdpSocket(static function (array $msg, MockSocket $sock): void {
            if (($msg['method'] ?? '') !== 'Page.navigate') {
                return;
            }
            $sock->addReceivedData(json_encode([
                'method'    => 'Fetch.requestPaused',
                'sessionId' => $msg['sessionId'] ?? null,
                'params'    => [
                    'requestId'    => 'nav-ssrf-1',
                    'resourceType' => CHECKOUT_DOCUMENT_TYPE,
                    'frameId'      => 'frame-1',
                    'request'      => ['method' => 'GET', 'url' => 'http://127.0.0.1/secret'],
                ],
            ], JSON_THROW_ON_ERROR));
        });
        $redirectConnection = new Connection($redirectSocket);
        $redirectConnection->connect();
        $redirectBrowser = new Browser($redirectConnection);

        $redirectResult = runCheckoutScenario(
            $redirectBrowser,
            ['url' => self::ORIGIN . '/product', 'found_by' => CRAWL_FOUND_BY_LINKS, 'cart' => null, 'checkout' => null],
            $rules,
            'shop.test',
            null,
            $resolver
        );

        $this->assertSame(CHECKOUT_OUTCOME_SKIPPED, $redirectResult['outcome']);
        $this->assertSame(CHECKOUT_REASON_PRODUCT_NOT_OPENED, $redirectResult['reason']);
        $this->assertSame(BROWSER_SSRF_BLOCKED_ERROR, $redirectResult['detail']);
        $this->assertSame(1, $redirectResult['requests']['blocked']);

        $sent = $this->decodeSentMessages($redirectSocket->getSentData());
        $this->assertNotNull($this->findSentMessage($sent, 'Target.closeTarget', null, ['targetId' => 'target-1']));
        $this->assertNull($this->findSentMessage($sent, 'Fetch.disable'), 'Fetch.disable запрещён до закрытия вкладки');
    }

    /**
     * @param callable(array<string, mixed>, MockSocket): void|null $onSend
     */
    private function createFakeCdpSocket(?callable $onSend = null): MockSocket
    {
        return new class ($onSend) extends MockSocket {
            /** @var callable|null */
            private $onSend;

            public function __construct(?callable $onSend)
            {
                $this->onSend = $onSend;
            }

            public function sendData($data)
            {
                if (!$this->isConnected()) {
                    return false;
                }
                $this->sentData[] = $data;
                $decoded = json_decode($data, true);
                if (!is_array($decoded) || !isset($decoded['id'])) {
                    return true;
                }

                $method = (string) ($decoded['method'] ?? '');
                $id = (int) $decoded['id'];
                $result = match ($method) {
                    'Target.createTarget' => ['targetId' => 'target-1'],
                    'Target.attachToTarget' => ['sessionId' => 'session-1'],
                    'Page.getFrameTree' => ['frameTree' => ['frame' => [
                        'id'       => 'frame-1',
                        'loaderId' => 'loader-0',
                        'url'      => 'about:blank',
                    ]]],
                    'Page.navigate' => ['frameId' => 'frame-1', 'loaderId' => 'loader-1'],
                    default => new \stdClass(),
                };

                if ($method === 'Target.createTarget') {
                    $this->receivedData[] = json_encode([
                        'method' => 'Target.targetCreated',
                        'params' => ['targetInfo' => [
                            'targetId' => 'target-1',
                            'type'     => 'page',
                            'url'      => 'about:blank',
                        ]],
                    ], JSON_THROW_ON_ERROR);
                }
                if ($method === 'Target.closeTarget') {
                    $targetId = (string) ($decoded['params']['targetId'] ?? '');
                    $this->receivedData[] = json_encode([
                        'method' => 'Target.targetDestroyed',
                        'params' => ['targetId' => $targetId],
                    ], JSON_THROW_ON_ERROR);
                }

                if ($this->onSend !== null) {
                    ($this->onSend)($decoded, $this);
                }

                $this->receivedData[] = json_encode(['id' => $id, 'result' => $result], JSON_THROW_ON_ERROR);

                return true;
            }
        };
    }

    /**
     * @param list<string> $rawMessages
     * @return list<array<string, mixed>>
     */
    private function decodeSentMessages(array $rawMessages): array
    {
        $decoded = [];
        foreach ($rawMessages as $raw) {
            $item = json_decode($raw, true);
            if (is_array($item)) {
                $decoded[] = $item;
            }
        }

        return $decoded;
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @param array<string, mixed>       $expectedParams
     * @return array<string, mixed>|null
     */
    private function findSentMessage(
        array $messages,
        string $method,
        ?string $sessionId = null,
        array $expectedParams = []
    ): ?array {
        foreach ($messages as $message) {
            if (($message['method'] ?? null) !== $method) {
                continue;
            }
            if ($sessionId !== null && ($message['sessionId'] ?? null) !== $sessionId) {
                continue;
            }
            $params = is_array($message['params'] ?? null) ? $message['params'] : [];
            $matched = true;
            foreach ($expectedParams as $key => $value) {
                if (($params[$key] ?? null) !== $value) {
                    $matched = false;
                    break;
                }
            }
            if ($matched) {
                return $message;
            }
        }

        return null;
    }
}
