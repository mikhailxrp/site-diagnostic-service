<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/src/Core/Logger.php';
require_once ROOT_PATH . '/src/Services/FindingFactory.php';
require_once ROOT_PATH . '/src/Services/Checks/BrowserConsoleCheck.php';

final class BrowserConsoleCheckTest extends TestCase
{
    private const SITE_URL    = 'https://www.shop.test/';
    private const HOME_URL    = 'https://shop.test/';
    private const CATALOG_URL = 'https://shop.test/catalog/';
    private const MAIN_FRAME  = 'F1';

    /** @var list<string> адреса, которые перезапрашивались */
    private array $rechecked = [];

    public function testUncaughtExceptionBecomesFindingWithFileLineAndColumn(): void
    {
        $result = $this->build([$this->load('exception')]);
        $finding = $this->findByTitle($result['findings'], 'Ошибка JS: Uncaught TypeError');

        $this->assertSame('js_error', $finding['category']);
        $this->assertSame('medium', $finding['severity']);
        $this->assertSame(
            "Ошибка JS: Uncaught TypeError: Cannot read properties of undefined (reading 'foo')",
            $finding['title']
        );
        $this->assertSame('https://shop.test/js/app.js', $finding['location']);
        $this->assertSame(42, $finding['evidence']['line']);
        $this->assertSame(15, $finding['evidence']['column']);
        $this->assertSame('initCart https://shop.test/js/app.js:42:15', $finding['evidence']['stack'][0]);
        $this->assertSame([1366], $finding['evidence']['widths']);
        $this->assertSame([self::HOME_URL], $finding['evidence']['pages']);
        $this->assertSame(1, $finding['page_id']);
    }

    public function testScriptErrorCounterExtensionAndForeignWidget(): void
    {
        $result = $this->build([$this->load('exception')]);

        $this->assertSame(2, $result['js_errors']);
        $this->assertSame(1, $result['rejected'][BROWSER_REJECT_SCRIPT_ERROR]);
        $this->assertSame(1, $result['rejected'][BROWSER_REJECT_IGNORED_HOST]);
        $this->assertSame(1, $result['rejected'][BROWSER_REJECT_NOT_HTTP]);

        // Исключение в чужом скрипте — находка, но не выше `low`
        $widget = $this->findByTitle($result['findings'], 'Ошибка JS: Uncaught Error: widget failed');
        $this->assertSame('low', $widget['severity']);
    }

    public function testConsoleErrorsAndLogSources(): void
    {
        $result = $this->build([$this->load('console')]);

        $this->assertSame(2, $result['js_errors']);
        $console = $this->findByTitle($result['findings'], 'Ошибка в консоли: Ошибка корзины: Error: 500');
        $this->assertSame('low', $console['severity']);
        $this->assertSame('https://shop.test/js/cart.js', $console['location']);
        $this->assertSame(10, $console['evidence']['line']);

        $rendering = $this->findByTitle($result['findings'], 'Ошибка в консоли: Failed to decode image');
        $this->assertSame('rendering', $rendering['evidence']['source']);

        $this->assertSame(3, $result['rejected'][BROWSER_REJECT_LOG_SOURCE]); // network, security, deprecation
        $this->assertSame(1, $result['rejected'][BROWSER_REJECT_CONSOLE_TEXT]); // [Report Only]
    }

    public function testFailedRequestRules(): void
    {
        $result = $this->build(
            [$this->load('requests')],
            ['https://shop.test/css/broken.css' => true],
            ['https://shop.test/js/flaky.js' => 200]
        );

        $this->assertSame(3, $result['failed_requests']);
        $locations = array_column($result['findings'], 'location');
        sort($locations);
        $this->assertSame([
            'https://shop.test/api/cart',     // XHR 500 (POST — не перезапрашивается)
            'https://shop.test/frame.html',   // документ во фрейме — не сама страница
            'https://shop.test/js/missing.js', // 404 подтверждён повторным запросом
        ], $locations);

        $rejected = $result['rejected'];
        $this->assertSame(1, $rejected[BROWSER_REJECT_DOCUMENT]);
        $this->assertSame(1, $rejected[BROWSER_REJECT_API_AUTH]);
        $this->assertSame(1, $rejected[BROWSER_REJECT_STATUS]);
        $this->assertSame(3, $rejected[BROWSER_REJECT_BROWSER_ERROR]); // BLOCKED_BY_CLIENT, canceled, ABORTED
        $this->assertSame(1, $rejected[BROWSER_REJECT_BLOCKED]);       // mixed content
        $this->assertSame(1, $rejected[BROWSER_REJECT_IGNORED_HOST]);  // Метрика
        $this->assertSame(2, $rejected[BROWSER_REJECT_SERVICE_TYPE]);  // favicon, Ping
        $this->assertSame(1, $rejected[BROWSER_REJECT_NOT_HTTP]);      // data:
        $this->assertSame(1, $rejected[BROWSER_REJECT_BROKEN_RESOURCE]);
        $this->assertSame(1, $rejected[BROWSER_REJECT_SINGLE_NETWORK]); // разовый ERR_CONNECTION_REFUSED
        $this->assertSame(1, $rejected[BROWSER_REJECT_RECHECK_OK]);     // flaky.js при повторе — 200

        $this->assertSame(['https://shop.test/js/missing.js', 'https://shop.test/js/flaky.js'], $this->rechecked);

        $missing = $this->findByLocation($result['findings'], 'https://shop.test/js/missing.js');
        $this->assertSame('Упавший запрос: код 404', $missing['title']);
        $this->assertSame('medium', $missing['severity']);
        $this->assertSame(['status' => 404, 'error' => null], $missing['evidence']['recheck']);
        $this->assertStringContainsString('Повторный запрос: код 404', (string) $missing['details']);
    }

    public function testResponseAndLoadingFailedForSameRequestGiveOneEvent(): void
    {
        $events = array_filter(
            $this->load('requests')['events'],
            static fn (array $event): bool => $event['url'] === 'https://shop.test/js/missing.js'
        );

        $this->assertCount(1, $events);
    }

    public function testRepeatedNetworkErrorOnForeignHostIsLowFinding(): void
    {
        $loads = [$this->load('requests'), $this->load('requests', self::HOME_URL, 768)];
        $result = $this->build($loads, [], [], ['https://img.partner.test/logo.png' => null]);

        $finding = $this->findByLocation($result['findings'], 'https://img.partner.test/logo.png');
        $this->assertSame('Упавший запрос: net::ERR_CONNECTION_REFUSED', $finding['title']);
        $this->assertSame('low', $finding['severity']);
        $this->assertSame([768, 1366], $finding['evidence']['widths']);
    }

    public function testSameErrorOnTwoPagesAndThreeWidthsIsOneFinding(): void
    {
        $loads = [];
        foreach ([self::HOME_URL, self::CATALOG_URL] as $url) {
            foreach ([1366, 360, 768] as $width) {
                $loads[] = $this->load('exception', $url, $width);
            }
        }

        $result = $this->build($loads);
        $typeErrors = array_values(array_filter(
            $result['findings'],
            static fn (array $finding): bool => str_contains($finding['title'], 'TypeError')
        ));

        $this->assertCount(1, $typeErrors);
        $this->assertSame(6, $typeErrors[0]['evidence']['count']);
        $this->assertSame([self::HOME_URL, self::CATALOG_URL], $typeErrors[0]['evidence']['pages']);
        $this->assertSame([360, 768, 1366], $typeErrors[0]['evidence']['widths']);
        $this->assertStringContainsString('Повторов: 6 (страниц: 2, ширины: 360, 768, 1366 px)', (string) $typeErrors[0]['details']);
    }

    public function testFingerprintDoesNotDependOnPageOrWidth(): void
    {
        $home = $this->build([$this->load('exception', self::HOME_URL, 360)]);
        $catalog = $this->build([$this->load('exception', self::CATALOG_URL, 1366)]);

        $this->assertSame(
            array_column($home['findings'], 'fingerprint'),
            array_column($catalog['findings'], 'fingerprint')
        );
    }

    public function testLoadThatDidNotOpenGivesNoFindings(): void
    {
        $load = $this->load('exception');
        $load['status'] = BROWSER_PAGE_TIMEOUT;

        $result = $this->build([$load]);

        $this->assertSame([], $result['findings']);
        $this->assertSame(5, $result['rejected'][BROWSER_REJECT_NOT_OPENED]);
    }

    public function testRecoverableReactHydrationErrorsAreNotFindings(): void
    {
        $state = createBrowserEventState(self::MAIN_FRAME);
        foreach (['418', '423'] as $code) {
            $state = applyBrowserEvent($state, 'Runtime.exceptionThrown', ['exceptionDetails' => [
                'text'         => 'Uncaught',
                'url'          => 'https://static.shop.test/_next/static/chunks/framework.js',
                'lineNumber'   => 0,
                'columnNumber' => 52938,
                'exception'    => ['description' => "Error: Minified React error #{$code}; visit https://reactjs.org/docs/error-decoder.html?invariant={$code}"],
            ]]);
        }
        $load = ['events' => $state['events']] + $this->load('console');

        $result = $this->build([$load]);

        $this->assertSame([], $result['findings']);
        $this->assertSame(2, $result['rejected'][BROWSER_REJECT_RECOVERABLE]);
    }

    public function testConsoleErrorTitleIsFirstLineOnly(): void
    {
        $state = applyBrowserEvent(createBrowserEventState(self::MAIN_FRAME), 'Runtime.consoleAPICalled', [
            'type'       => 'error',
            'args'       => [['type' => 'object', 'description' => "TypeError: x is undefined\n    at init (https://shop.test/a.js:1:2)"]],
            'stackTrace' => ['callFrames' => [['functionName' => '', 'url' => 'https://shop.test/vue.js', 'lineNumber' => 5, 'columnNumber' => 1]]],
        ]);
        $load = ['events' => $state['events']] + $this->load('console');

        $finding = $this->build([$load])['findings'][0];

        $this->assertSame('Ошибка в консоли: TypeError: x is undefined', $finding['title']);
        $this->assertStringContainsString('at init', $finding['evidence']['message']);
    }

    public function testSameRequestWithChangingQueryIsOneFinding(): void
    {
        $loads = [];
        foreach ([360, 768, 1366] as $width) {
            $url = 'https://widgets.partner.test/api/badge?session_id=' . $width;
            $state = createBrowserEventState(self::MAIN_FRAME);
            $state = applyBrowserEvent($state, 'Network.requestWillBeSent', [
                'requestId' => 'r1', 'frameId' => self::MAIN_FRAME, 'type' => 'Fetch', 'request' => ['url' => $url, 'method' => 'GET'],
            ]);
            $state = applyBrowserEvent($state, 'Network.responseReceived', [
                'requestId' => 'r1', 'frameId' => self::MAIN_FRAME, 'type' => 'Fetch', 'response' => ['url' => $url, 'status' => 500],
            ]);
            $loads[] = ['events' => $state['events'], 'width' => $width] + $this->load('console');
        }

        $requests = array_values(array_filter(
            $this->build($loads)['findings'],
            static fn (array $finding): bool => $finding['category'] === 'failed_request'
        ));

        $this->assertCount(1, $requests);
        $this->assertSame([360, 768, 1366], $requests[0]['evidence']['widths']);
    }

    public function testEventsAreLimitedPerLoad(): void
    {
        $state = createBrowserEventState(self::MAIN_FRAME);
        $exception = $this->readFixture('exception')[0];
        for ($i = 0; $i < BROWSER_MAX_EVENTS_PER_LOAD + 50; $i++) {
            $state = applyBrowserEvent($state, $exception['method'], $exception['params']);
        }

        $this->assertCount(BROWSER_MAX_EVENTS_PER_LOAD, $state['events']);
        $this->assertSame(50, $state['dropped']);
    }

    public function testBrokenResourceUrls(): void
    {
        $broken = collectBrokenResourceUrls([
            ['url' => 'https://SHOP.test/a.css#x', 'status' => 404],
            ['url' => 'https://shop.test/b.js', 'status' => null],
            ['url' => 'https://shop.test/c.png', 'status' => 200],
        ]);

        $this->assertSame(['https://shop.test/a.css' => true, 'https://shop.test/b.js' => true], $broken);
        $this->assertSame([], collectBrokenResourceUrls(null));
    }

    public function testOwnHostAndIgnoredUrls(): void
    {
        $this->assertTrue(isOwnBrowserHost('cdn.shop.test', 'www.shop.test'));
        $this->assertTrue(isOwnBrowserHost('www.shop.test', 'shop.test'));
        $this->assertFalse(isOwnBrowserHost('notshop.test', 'shop.test'));

        $rules = loadBrowserIgnoreRules();
        $this->assertTrue(isIgnoredBrowserUrl('https://mc.yandex.ru/watch/1', $rules));
        $this->assertTrue(isIgnoredBrowserUrl('https://www.googletagmanager.com/gtm.js?id=1', $rules));
        $this->assertTrue(isIgnoredBrowserUrl('https://vk.com/rtrg?p=1', $rules));
        $this->assertTrue(isIgnoredBrowserUrl('https://pay.yandex.ru/api/v2/badge?sdk_session_id=1', $rules));
        $this->assertFalse(isIgnoredBrowserUrl('https://vk.com/shop', $rules));
        $this->assertFalse(isIgnoredBrowserUrl('https://notyandex.ru/mc.yandex.ru', $rules));
    }

    public function testRecheckOnlyStaticGetRequests(): void
    {
        $base = ['kind' => BROWSER_EVENT_RESPONSE, 'method' => 'GET', 'type' => 'Script'];

        $this->assertTrue(shouldRecheckBrowserRequest($base));
        $this->assertFalse(shouldRecheckBrowserRequest(['method' => 'POST'] + $base));
        $this->assertFalse(shouldRecheckBrowserRequest(['type' => 'Fetch'] + $base));
        $this->assertFalse(shouldRecheckBrowserRequest(['kind' => BROWSER_EVENT_EXCEPTION] + $base));
    }

    /**
     * Загрузка из фикстуры событий DevTools.
     *
     * @return array<string, mixed>
     */
    private function load(string $fixture, string $url = self::HOME_URL, int $width = 1366): array
    {
        $state = createBrowserEventState(self::MAIN_FRAME);
        foreach ($this->readFixture($fixture) as $event) {
            $state = applyBrowserEvent($state, $event['method'], $event['params']);
        }

        return [
            'url'     => $url,
            'width'   => $width,
            'status'  => BROWSER_PAGE_OPENED,
            'page_id' => $url === self::HOME_URL ? 1 : 2,
            'events'  => $state['events'],
            'dropped' => $state['dropped'],
        ];
    }

    /**
     * @return list<array{method: string, params: array}>
     */
    private function readFixture(string $name): array
    {
        $json = file_get_contents(ROOT_PATH . '/tests/fixtures/browser/' . $name . '.json');

        return json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, true>     $broken
     * @param array<string, int|null> $recheckStatuses адрес → код повторного запроса (null — нет ответа)
     * @param array<string, int|null> $extraStatuses
     */
    private function build(array $loads, array $broken = [], array $recheckStatuses = [], array $extraStatuses = []): array
    {
        $statuses = $recheckStatuses + $extraStatuses;
        $this->rechecked = [];
        $recheck = function (string $url) use ($statuses): array {
            $this->rechecked[] = $url;
            $status = array_key_exists($url, $statuses) ? $statuses[$url] : 404;

            return ['status' => $status, 'error' => $status === null ? 'Connection refused' : null];
        };

        return buildBrowserFindings($loads, self::SITE_URL, $broken, $recheck);
    }

    private function findByTitle(array $findings, string $prefix): array
    {
        foreach ($findings as $finding) {
            if (str_starts_with($finding['title'], $prefix)) {
                return $finding;
            }
        }
        $this->fail("Нет находки «{$prefix}…»");
    }

    private function findByLocation(array $findings, string $location): array
    {
        foreach ($findings as $finding) {
            if ($finding['location'] === $location) {
                return $finding;
            }
        }
        $this->fail("Нет находки по адресу {$location}");
    }
}
