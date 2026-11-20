<?php

declare(strict_types=1);

namespace Tests\Unit;

use ErrorException;
use HeadlessChromium\Browser;
use HeadlessChromium\Communication\Connection;
use HeadlessChromium\Communication\Socket\MockSocket;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once ROOT_PATH . '/src/Core/Logger.php';
require_once ROOT_PATH . '/src/Services/FindingFactory.php';
require_once ROOT_PATH . '/src/Services/Checks/ResourceCheck.php';
require_once ROOT_PATH . '/src/Services/BrowserService.php';

final class BrowserServiceTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/sd-browser-test-' . bin2hex(random_bytes(4));
        mkdir($this->baseDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->baseDir)) {
            removeDirectoryTree($this->baseDir);
        }
    }

    public function testLibraryIsInstalled(): void
    {
        $this->assertTrue(isBrowserLibraryAvailable());
    }

    public function testBrowserOptions(): void
    {
        $options = buildBrowserOptions('C:/temp/chrome-1-ab', 30);

        $this->assertTrue($options['headless']);
        $this->assertSame([BROWSER_WINDOW_WIDTH, BROWSER_WINDOW_HEIGHT], $options['windowSize']);
        $this->assertSame([1366, 768], $options['windowSize']);
        $this->assertSame('C:/temp/chrome-1-ab', $options['userDataDir']);
        $this->assertSame(30, $options['startupTimeout']);
        $this->assertTrue($options['ignoreCertificateErrors']);
        $this->assertFalse($options['keepAlive']);
        $this->assertSame([BROWSER_DISABLE_POPUP_BLOCKING_SWITCH], $options['excludedSwitches']);
        $this->assertSame([BROWSER_BLOCK_NEW_CONTENTS_FLAG], $options['customFlags']);
        $this->assertArrayNotHasKey('userAgent', $options);

        $merged = buildBrowserOptions('C:/temp/chrome-1-ab', 30, [
            'excludedSwitches' => [BROWSER_DISABLE_POPUP_BLOCKING_SWITCH, '--mute-audio'],
            'customFlags'      => [BROWSER_BLOCK_NEW_CONTENTS_FLAG, '--disable-dev-shm-usage'],
            ...buildBrowserPdfLaunchOptions(),
        ]);
        $this->assertTrue($merged['disableJavascript']);
        $this->assertFalse($merged['ignoreCertificateErrors']);
        $this->assertSame([BROWSER_DISABLE_POPUP_BLOCKING_SWITCH, '--mute-audio'], $merged['excludedSwitches']);
        $this->assertSame([BROWSER_BLOCK_NEW_CONTENTS_FLAG, '--disable-dev-shm-usage'], $merged['customFlags']);
    }

    public function testParseBrowserVersion(): void
    {
        $this->assertSame('157.0.8103.52', parseBrowserVersion('Chrome/157.0.8103.52'));
        $this->assertSame('157.0.8103.52', parseBrowserVersion('HeadlessChrome/157.0.8103.52'));
        $this->assertNull(parseBrowserVersion('Chrome'));
    }

    public function testSelectPagesSkipsBotProtection(): void
    {
        $selection = selectBrowserPages([
            ['url' => 'https://shop.test/', 'types' => ['home'], 'page_id' => 1, 'bot_protection' => null, 'body' => '<html>'],
            ['url' => 'https://shop.test/cart/', 'types' => ['cart', 'checkout'], 'page_id' => 2,
                'bot_protection' => ['provider' => 'ddos-guard', 'title' => 'DDoS-Guard', 'reason' => 'заглушка']],
            ['url' => 'https://shop.test/catalog/', 'types' => ['category'], 'page_id' => 3, 'bot_protection' => null],
        ]);

        $this->assertSame(['https://shop.test/', 'https://shop.test/catalog/'], array_column($selection['open'], 'url'));
        $this->assertSame([['url' => 'https://shop.test/cart/', 'types' => ['cart', 'checkout'], 'page_id' => 2]], $selection['blocked']);
        $this->assertArrayNotHasKey('body', $selection['open'][0]);
    }

    public function testPagesFromContextWithoutCrawlAndWithoutResponse(): void
    {
        $onlyHome = selectBrowserPages(collectResourcePages(['facts' => ['availability' => ['home' => [
            'url' => 'https://shop.test', 'final_url' => 'https://shop.test/', 'status' => 200, 'page_id' => 5,
            'bot_protection' => null,
        ]]]]));
        $this->assertSame(['https://shop.test/'], array_column($onlyHome['open'], 'url'));

        $noResponse = selectBrowserPages(collectResourcePages(['facts' => ['availability' => ['home' => [
            'url' => 'https://down.test/', 'final_url' => 'https://down.test/', 'status' => null, 'page_id' => 6,
        ]]]]));
        $this->assertSame(['open' => [], 'blocked' => []], $noResponse);
    }

    public function testStepMessage(): void
    {
        $home = ['url' => 'https://shop.test/', 'status' => BROWSER_PAGE_OPENED];
        $catalog = ['url' => 'https://shop.test/catalog/', 'status' => BROWSER_PAGE_OPENED];
        $cart = ['url' => 'https://shop.test/cart/', 'status' => BROWSER_PAGE_TIMEOUT];

        $this->assertSame(
            'Открыто в браузере: 2 из 2 страниц (4 загрузок); ошибок JS: 3; упавших запросов: 1',
            buildBrowserStepMessage([$home, $home, $catalog, $catalog], 0, 3, 1)
        );
        // Каталог и корзина не открылись; плюс одна страница за защитой от ботов
        $this->assertSame(
            'Открыто в браузере: 1 из 4 страниц (1 из 3 загрузок); не открылось: 2; ошибок JS: 0; '
                . 'упавших запросов: 0; пропущено (защита от ботов): 1',
            buildBrowserStepMessage([$home, ['status' => BROWSER_PAGE_ERROR] + $catalog, $cart], 1)
        );
    }

    public function testStepMessageCountsScreenshotsOfOpenedLoads(): void
    {
        $opened = ['url' => 'https://shop.test/', 'status' => BROWSER_PAGE_OPENED];
        $timeout = ['url' => 'https://shop.test/', 'status' => BROWSER_PAGE_TIMEOUT];

        $this->assertSame(
            'Открыто в браузере: 1 из 2 страниц (2 из 3 загрузок); ошибок JS: 0; упавших запросов: 0; '
                . 'скриншотов: 1 из 2; пропущено (защита от ботов): 1',
            buildBrowserStepMessage([$opened, $timeout, $opened], 1, 0, 0, 1)
        );
    }

    public function testStepMessageCountsPageOpenedOnSomeWidths(): void
    {
        $opened = ['url' => 'https://shop.test/', 'status' => BROWSER_PAGE_OPENED];
        $timeout = ['url' => 'https://shop.test/', 'status' => BROWSER_PAGE_TIMEOUT];

        $this->assertStringStartsWith(
            'Открыто в браузере: 1 из 1 страниц (2 из 3 загрузок); ошибок JS',
            buildBrowserStepMessage([$opened, $timeout, $opened], 0)
        );
    }

    public function testViewportOverride(): void
    {
        $this->assertSame(['width' => 360, 'height' => BROWSER_WINDOW_HEIGHT, 'deviceScaleFactor' => 1, 'mobile' => true], buildViewportOverride(360));
        $this->assertFalse(buildViewportOverride(768)['mobile']);
        $this->assertFalse(buildViewportOverride(1366)['mobile']);
        $this->assertSame([360, 768, 1366], BROWSER_VIEWPORT_WIDTHS);
    }

    public function testCreateAndRemoveProfileDir(): void
    {
        $dir = createBrowserProfileDir(42, $this->baseDir);
        mkdir($dir . '/Default/Cache', 0775, true);
        file_put_contents($dir . '/Default/Cache/data', 'x');

        $this->assertMatchesRegularExpression('~/chrome-42-[0-9a-f]{8}$~', $dir);
        $this->assertTrue(removeBrowserProfileDir($dir, $this->baseDir));
        $this->assertDirectoryDoesNotExist($dir);
        $this->assertTrue(removeBrowserProfileDir($dir, $this->baseDir), 'уже удалённая папка — не ошибка');
    }

    public function testRemoveProfileRefusesForeignDir(): void
    {
        $foreign = $this->baseDir . '/not-a-profile';
        mkdir($foreign);

        $this->expectException(InvalidArgumentException::class);
        removeBrowserProfileDir($foreign, $this->baseDir);
    }

    public function testRemoveStaleProfilesKeepsFreshOnes(): void
    {
        $stale = createBrowserProfileDir(1, $this->baseDir);
        $fresh = createBrowserProfileDir(2, $this->baseDir);
        $other = $this->baseDir . '/other';
        mkdir($other);
        $old = time() - BROWSER_PROFILE_MAX_AGE_SECONDS - 60;
        touch($stale, $old);
        touch($other, $old);

        $this->assertSame(1, removeStaleBrowserProfiles(time(), $this->baseDir));
        $this->assertDirectoryDoesNotExist($stale);
        $this->assertDirectoryExists($fresh);
        $this->assertDirectoryExists($other);
    }

    public function testPdfPrintOptions(): void
    {
        $options = buildBrowserPdfOptions();

        $this->assertFalse($options['landscape']);
        $this->assertTrue($options['printBackground']);
        $this->assertTrue($options['preferCSSPageSize']);
        $this->assertFalse($options['displayHeaderFooter']);
        $this->assertSame(BROWSER_PDF_PAPER_WIDTH_INCHES, $options['paperWidth']);
        $this->assertSame(BROWSER_PDF_PAPER_HEIGHT_INCHES, $options['paperHeight']);
        $this->assertSame(8.27, $options['paperWidth']);
        $this->assertSame(11.69, $options['paperHeight']);
        $this->assertSame(BROWSER_PDF_MARGIN_INCHES, $options['marginTop']);
        $this->assertSame(BROWSER_PDF_MARGIN_INCHES, $options['marginBottom']);
        $this->assertSame(BROWSER_PDF_MARGIN_INCHES, $options['marginLeft']);
        $this->assertSame(BROWSER_PDF_MARGIN_INCHES, $options['marginRight']);
        $this->assertSame(30, BROWSER_PDF_STARTUP_TIMEOUT_SECONDS);
        $this->assertSame(30000, BROWSER_PDF_PAGE_TIMEOUT_MS);
        $this->assertSame(60000, BROWSER_PDF_PRINT_TIMEOUT_MS);
    }

    public function testPdfPrintRejectsExternalAndUnsafeFileUrlsBeforeChromeLaunch(): void
    {
        foreach ([
            'https://example.test/report',
            'file://127.0.0.1/c$/report.html',
            'file:///C:/dir/../../etc/passwd',
        ] as $url) {
            try {
                printBrowserHtmlToPdf('missing-chrome.exe', $url, $this->baseDir . '/out.pdf', 1);
                $this->fail('Ожидалось отклонение адреса: ' . $url);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testGetBrowserProfileModifiedTimeReturnsNullForMissingPathWithoutWarning(): void
    {
        set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
            throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
        });
        try {
            $this->assertNull(getBrowserProfileModifiedTime($this->baseDir . '/chrome-gone-1234'));
        } finally {
            restore_error_handler();
        }
    }

    public function testRequestInterceptionCoversChildTargetsPopupsFailClosedAndNoFetchDisable(): void
    {
        $socket = $this->createFakeCdpSocket();
        $connection = new Connection($socket);
        $connection->connect();
        $browser = new Browser($connection);
        $page = $browser->createPage();
        $mainSession = $page->getSession();
        $mainSessionId = $mainSession->getSessionId();

        $errors = [];
        $cleanup = attachBrowserRequestInterception(
            $page,
            static function (array $params): bool {
                $url = (string) ($params['request']['url'] ?? '');
                if (str_contains($url, 'explode')) {
                    throw new RuntimeException('guard failed');
                }

                return str_starts_with($url, 'https://shop.test/');
            },
            static function (\Throwable $e) use (&$errors): void {
                $errors[] = $e->getMessage();
            }
        );

        $sent = $this->decodeSentMessages($socket->getSentData());
        $methods = array_column($sent, 'method');
        $this->assertContains('Network.setBypassServiceWorker', $methods);
        $this->assertContains('Fetch.enable', $methods);
        $this->assertContains('Target.setAutoAttach', $methods);

        // Разрешённый, запрещённый и аварийный запросы на основной вкладке
        $socket->flushData();
        $mainSession->emit('method:Fetch.requestPaused', [[
            'requestId'    => 'req-ok',
            'resourceType' => 'Document',
            'request'      => ['url' => 'https://shop.test/catalog', 'method' => 'GET'],
        ]]);
        $mainSession->emit('method:Fetch.requestPaused', [[
            'requestId'    => 'req-local',
            'resourceType' => 'XHR',
            'request'      => ['url' => 'http://127.0.0.1/settings', 'method' => 'GET'],
        ]]);
        $mainSession->emit('method:Fetch.requestPaused', [[
            'requestId'    => 'req-boom',
            'resourceType' => 'XHR',
            'request'      => ['url' => 'https://explode.test/', 'method' => 'GET'],
        ]]);

        // Дочерняя цель (iframe) с waitingForDebugger: true + перехват внутри неё
        $mainSession->emit('method:Target.attachedToTarget', [[
            'sessionId'          => 'child-sess-1',
            'waitingForDebugger' => true,
            'targetInfo'         => ['targetId' => 'iframe-1', 'type' => 'iframe'],
        ]]);
        $socket->addReceivedData(json_encode([
            'method'    => 'Fetch.requestPaused',
            'sessionId' => 'child-sess-1',
            'params'    => [
                'requestId'    => 'req-child-local',
                'resourceType' => 'Document',
                'request'      => ['url' => 'http://192.168.0.1/admin', 'method' => 'GET'],
            ],
        ], JSON_THROW_ON_ERROR));
        $connection->readData();

        // Всплывающее окно закрывается через Target.closeTarget
        $connection->emit(Connection::EVENT_TARGET_CREATED, [[
            'targetInfo' => ['targetId' => 'popup-99', 'type' => 'page', 'url' => 'https://evil.test/'],
        ]]);

        $after = $this->decodeSentMessages($socket->getSentData());
        $this->assertSame(['guard failed'], $errors);

        $this->assertNotNull($this->findSentMessage($after, 'Fetch.continueRequest', $mainSessionId, ['requestId' => 'req-ok']));
        $this->assertNotNull($this->findSentMessage($after, 'Fetch.failRequest', $mainSessionId, [
            'requestId'   => 'req-local',
            'errorReason' => BROWSER_BLOCK_REASON,
        ]));
        $this->assertNotNull($this->findSentMessage($after, 'Fetch.failRequest', $mainSessionId, [
            'requestId'   => 'req-boom',
            'errorReason' => BROWSER_BLOCK_REASON,
        ]));
        $this->assertNotNull($this->findSentMessage($after, 'Fetch.enable', 'child-sess-1'));
        $this->assertNotNull($this->findSentMessage($after, 'Network.setBypassServiceWorker', 'child-sess-1'));
        $this->assertNotNull($this->findSentMessage($after, 'Target.setAutoAttach', 'child-sess-1'));
        $this->assertNotNull($this->findSentMessage($after, 'Runtime.runIfWaitingForDebugger', 'child-sess-1'));
        $this->assertNotNull($this->findSentMessage($after, 'Fetch.failRequest', 'child-sess-1', [
            'requestId'   => 'req-child-local',
            'errorReason' => BROWSER_BLOCK_REASON,
        ]));
        $this->assertNotNull($this->findSentMessage($after, 'Target.closeTarget', null, ['targetId' => 'popup-99']));

        $socket->flushData();
        $cleanup();
        $afterCleanup = $this->decodeSentMessages($socket->getSentData());
        $this->assertNull($this->findSentMessage($afterCleanup, 'Fetch.disable'));
    }

    public function testOpenBrowserPageBlocksInitialLocalUrlWithoutCreatingPage(): void
    {
        $socket = $this->createFakeCdpSocket();
        $connection = new Connection($socket);
        $connection->connect();
        $browser = new Browser($connection);
        $socket->flushData();

        $result = openBrowserPage(
            $browser,
            'http://127.0.0.1/settings',
            1366,
            1000,
            true,
            static fn (): array => ['127.0.0.1']
        );

        $this->assertSame(BROWSER_PAGE_ERROR, $result['status']);
        $this->assertSame(BROWSER_SSRF_BLOCKED_ERROR, $result['error']);
        $this->assertArrayNotHasKey('screenshot', $result);
        $this->assertSame([], $socket->getSentData(), 'для запрещённого URL вкладка даже не создаётся');
    }

    public function testOpenBrowserPageAbortsAndSkipsScreenshotOnBlockedMainRedirect(): void
    {
        $socket = $this->createFakeCdpSocket(static function (array $msg, MockSocket $sock): void {
            if (($msg['method'] ?? '') !== 'Page.navigate') {
                return;
            }
            $sock->addReceivedData(json_encode([
                'method'    => 'Fetch.requestPaused',
                'sessionId' => $msg['sessionId'] ?? null,
                'params'    => [
                    'requestId'    => 'nav-redirect-1',
                    'resourceType' => BROWSER_DOCUMENT_TYPE,
                    'frameId'      => 'frame-1',
                    'request'      => ['url' => 'http://127.0.0.1/secret', 'method' => 'GET'],
                ],
            ], JSON_THROW_ON_ERROR));
        });
        $connection = new Connection($socket);
        $connection->connect();
        $browser = new Browser($connection);

        $result = openBrowserPage(
            $browser,
            'https://shop.test/redirect',
            1366,
            5000,
            true,
            static fn (string $host): array => $host === 'shop.test' ? ['93.184.216.34'] : ['127.0.0.1']
        );

        $this->assertSame(BROWSER_PAGE_ERROR, $result['status']);
        $this->assertSame(BROWSER_SSRF_BLOCKED_ERROR, $result['error']);
        $this->assertArrayNotHasKey('screenshot', $result);

        $sent = $this->decodeSentMessages($socket->getSentData());
        $this->assertNotNull($this->findSentMessage($sent, 'Fetch.failRequest', 'session-1', [
            'requestId'   => 'nav-redirect-1',
            'errorReason' => BROWSER_BLOCK_REASON,
        ]));
        $this->assertNull($this->findSentMessage($sent, 'Fetch.disable'));
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
