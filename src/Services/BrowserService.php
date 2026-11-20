<?php

declare(strict_types=1);

/**
 * Chrome без окна через `chrome-php/chrome`: запуск с отдельной папкой профиля, открытие страницы на заданной ширине
 * с тайм-аутом и сбором событий DevTools (ошибки JS, консоль, запросы — разбор в `Checks/BrowserConsoleCheck.php`),
 * версия браузера, гарантированное закрытие и удаление профиля.
 *
 * Объекты `chrome-php` используются только в этом файле. Чистые функции (параметры запуска, отбор страниц,
 * сообщение шага) не запускают браузер — их покрывают тесты.
 */

use HeadlessChromium\Browser;
use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Clip;
use HeadlessChromium\Communication\Connection;
use HeadlessChromium\Communication\Message;
use HeadlessChromium\Communication\Session;
use HeadlessChromium\Exception\OperationTimedOut;
use HeadlessChromium\Page;

require_once ROOT_PATH . '/src/Services/BrowserRequestGuard.php';
require_once ROOT_PATH . '/src/Services/Checks/BrowserConsoleCheck.php';
require_once ROOT_PATH . '/src/Services/ScreenshotService.php';

const BROWSER_STARTUP_TIMEOUT_SECONDS = 30;    // запуск Chrome воркером
const BROWSER_CHECK_TIMEOUT_SECONDS   = 15;    // «Проверить Chrome» из веба: меньше max_execution_time запроса
const BROWSER_CHECK_TIME_LIMIT        = 60;    // set_time_limit() для проверки из веба: запуск + закрытие
const BROWSER_PAGE_TIMEOUT_MS         = 30000; // загрузка одной страницы (до готовности DOM)
const BROWSER_LOAD_WAIT_AFTER_DOM_MS  = 10000; // после DOM ждём `load` не дольше: медленный счётчик держит его минутами
const BROWSER_COMMAND_TIMEOUT_MS      = 10000; // синхронная команда DevTools (версия, закрытие вкладки)
const BROWSER_POLL_INTERVAL_US        = 10000; // пауза между чтениями событий DevTools при ожидании загрузки
const BROWSER_WINDOW_WIDTH            = 1366;
const BROWSER_WINDOW_HEIGHT           = 768;
const BROWSER_VIEWPORT_WIDTHS         = [360, 768, 1366]; // каждая страница загружается на каждой ширине
const BROWSER_MOBILE_MAX_WIDTH        = 767;   // уже — мобильная эмуляция: учитывается <meta viewport>
const BROWSER_SETTLE_MS               = 2000;  // после `load` ловим ошибки таймеров и отложенных скриптов

const BROWSER_PDF_STARTUP_TIMEOUT_SECONDS = 30;
const BROWSER_PDF_PAGE_TIMEOUT_MS         = 30000;
const BROWSER_PDF_PRINT_TIMEOUT_MS        = 60000;
const BROWSER_PDF_TIME_LIMIT_SECONDS      = 120;
const BROWSER_PDF_PAPER_WIDTH_INCHES      = 8.27;
const BROWSER_PDF_PAPER_HEIGHT_INCHES     = 11.69;
const BROWSER_PDF_MARGIN_INCHES           = 0;

/** События DevTools, которые собираются при загрузке (Network и Runtime включает `chrome-php`, Log — мы) */
const BROWSER_COLLECTED_EVENTS = [
    'Network.requestWillBeSent',
    'Network.responseReceived',
    'Network.loadingFailed',
    'Runtime.exceptionThrown',
    'Runtime.consoleAPICalled',
    'Log.entryAdded',
];

const BROWSER_PROFILES_PATH           = ROOT_PATH . '/storage/temp';
const BROWSER_PROFILE_PREFIX          = 'chrome-';
const BROWSER_PROFILE_MAX_AGE_SECONDS = 86400; // старше суток — осталась от убитого воркера
const BROWSER_PROFILE_REMOVE_ATTEMPTS = 5;     // Windows держит файлы профиля ещё мгновение после закрытия
const BROWSER_PROFILE_REMOVE_PAUSE_US = 200000;
const BROWSER_PROFILE_RANDOM_BYTES    = 4;

const BROWSER_PAGE_OPENED  = 'opened';
const BROWSER_PAGE_TIMEOUT = 'timeout';
const BROWSER_PAGE_ERROR   = 'error';

const BROWSER_NO_LIBRARY_MESSAGE = 'Пропущено: библиотека chrome-php не установлена — выполните composer install.';
const BROWSER_NO_PAGES_MESSAGE   = 'Пропущено: нет страниц, которые можно открыть в браузере.';
const BROWSER_UNAVAILABLE_PREFIX = 'Пропущено: Chrome недоступен — ';

const BROWSER_BLOCK_REASON                  = 'BlockedByClient';
const BROWSER_DISABLE_POPUP_BLOCKING_SWITCH = '--disable-popup-blocking';
const BROWSER_BLOCK_NEW_CONTENTS_FLAG       = '--block-new-web-contents';
const BROWSER_SSRF_BLOCKED_ERROR            = 'Запрос к локальному или недопустимому адресу заблокирован';
const BROWSER_CHILD_CONTAINER_TYPES         = ['iframe', 'page'];

/**
 * Установлен ли `chrome-php/chrome` (`vendor/autoload.php` подключён и пакет есть).
 */
function isBrowserLibraryAvailable(): bool
{
    return class_exists(BrowserFactory::class);
}

/**
 * Параметры `Emulation.setDeviceMetricsOverride` для ширины: высота окна общая, узкие ширины — мобильная эмуляция.
 *
 * @return array{width: int, height: int, deviceScaleFactor: int, mobile: bool}
 */
function buildViewportOverride(int $width): array
{
    return [
        'width'             => $width,
        'height'            => BROWSER_WINDOW_HEIGHT,
        'deviceScaleFactor' => 1,
        'mobile'            => $width <= BROWSER_MOBILE_MAX_WIDTH,
    ];
}

/**
 * Параметры `BrowserFactory::createBrowser()`. User-Agent не подменяется: Chrome представляется сам.
 * Убираем `--disable-popup-blocking` и включаем `--block-new-web-contents`, чтобы вкладка не открывала
 * новые окна в обход перехвата.
 *
 * @param array<string, mixed> $extraOptions
 * @return array<string, mixed>
 */
function buildBrowserOptions(string $profileDir, int $startupTimeoutSeconds, array $extraOptions = []): array
{
    $options = [
        'headless'                => true,
        'windowSize'              => [BROWSER_WINDOW_WIDTH, BROWSER_WINDOW_HEIGHT],
        'userDataDir'             => $profileDir,
        'startupTimeout'          => $startupTimeoutSeconds,
        'sendSyncDefaultTimeout'  => BROWSER_COMMAND_TIMEOUT_MS,
        'ignoreCertificateErrors' => true, // проблемы SSL ловит SslCheck; страница всё равно должна открыться
        'keepAlive'               => false,
        'excludedSwitches'        => [BROWSER_DISABLE_POPUP_BLOCKING_SWITCH],
        'customFlags'             => [BROWSER_BLOCK_NEW_CONTENTS_FLAG],
    ];

    foreach (['excludedSwitches', 'customFlags'] as $listKey) {
        if (isset($extraOptions[$listKey]) && is_array($extraOptions[$listKey])) {
            $options[$listKey] = array_values(array_unique([...$options[$listKey], ...$extraOptions[$listKey]]));
        }
        unset($extraOptions[$listKey]);
    }

    return array_merge($options, $extraOptions);
}

/**
 * Опции запуска Chrome для печати PDF: локальный HTML без JS и без игнорирования ошибок сертификатов.
 *
 * @return array<string, mixed>
 */
function buildBrowserPdfLaunchOptions(): array
{
    return [
        'disableJavascript'       => true,
        'ignoreCertificateErrors' => false,
    ];
}

/**
 * Версия из ответа `Browser.getVersion`: «Chrome/157.0.8103.52» → «157.0.8103.52».
 */
function parseBrowserVersion(string $product): ?string
{
    if (preg_match('~/(\d+(?:\.\d+)+)~', $product, $match) !== 1) {
        return null;
    }

    return $match[1];
}

/**
 * Делит страницы (`collectResourcePages()`) на те, что открываются в браузере, и пропущенные из-за защиты от ботов.
 * Страниц без ответа во входе уже нет.
 *
 * @param list<array{url: string, types: list<string>, page_id: ?int, bot_protection: ?array}> $pages
 * @return array{open: list<array{url: string, types: list<string>, page_id: ?int}>,
 *               blocked: list<array{url: string, types: list<string>, page_id: ?int}>}
 */
function selectBrowserPages(array $pages): array
{
    $selection = ['open' => [], 'blocked' => []];
    foreach ($pages as $page) {
        $item = ['url' => $page['url'], 'types' => $page['types'], 'page_id' => $page['page_id']];
        $selection[$page['bot_protection'] !== null ? 'blocked' : 'open'][] = $item;
    }

    return $selection;
}

/**
 * «Открыто в браузере: 4 из 5 страниц (12 загрузок); не открылось: 1; ошибок JS: 3; упавших запросов: 2;
 * скриншотов: 12 из 12; пропущено (защита от ботов): 1». Страница открыта, если открылась хотя бы на одной ширине;
 * если открылись не все загрузки — «(11 из 12 загрузок)». Скриншоты считаются от открывшихся загрузок
 * (`$screenshots = null` — снимки не делались, части нет).
 *
 * @param list<array{url: string, status: string}> $loads результаты `openBrowserPage()`
 */
function buildBrowserStepMessage(
    array $loads,
    int $blocked,
    int $jsErrors = 0,
    int $failedRequests = 0,
    ?int $screenshots = null
): string {
    $pages = [];
    $openedLoads = 0;
    foreach ($loads as $load) {
        $isOpened = $load['status'] === BROWSER_PAGE_OPENED;
        $pages[$load['url']] = ($pages[$load['url']] ?? false) || $isOpened;
        $openedLoads += $isOpened ? 1 : 0;
    }
    $opened = count(array_filter($pages));
    $failed = count($pages) - $opened;
    $total = count($loads);

    $message = "Открыто в браузере: {$opened} из " . (count($pages) + $blocked) . ' страниц';
    if ($total > 0) {
        $message .= ' (' . ($openedLoads === $total ? '' : "{$openedLoads} из ") . "{$total} загрузок)";
    }
    if ($failed > 0) {
        $message .= "; не открылось: {$failed}";
    }
    $message .= "; ошибок JS: {$jsErrors}; упавших запросов: {$failedRequests}";
    if ($screenshots !== null) {
        $message .= "; скриншотов: {$screenshots} из {$openedLoads}";
    }
    if ($blocked > 0) {
        $message .= "; пропущено (защита от ботов): {$blocked}";
    }

    return $message;
}

/**
 * Время изменения папки профиля; null — папку уже удалил другой процесс.
 */
function getBrowserProfileModifiedTime(string $path): ?int
{
    set_error_handler(static fn (): bool => true);
    try {
        $modified = filemtime($path);
    } catch (Throwable) {
        $modified = false;
    } finally {
        restore_error_handler();
    }

    return $modified === false ? null : $modified;
}

/**
 * Удаляет папки профилей `chrome-*` старше суток (остались от убитого воркера).
 *
 * @return int сколько удалено
 */
function removeStaleBrowserProfiles(?int $now = null, string $baseDir = BROWSER_PROFILES_PATH): int
{
    $now ??= time();
    $removed = 0;
    foreach (glob($baseDir . '/' . BROWSER_PROFILE_PREFIX . '*', GLOB_ONLYDIR | GLOB_NOSORT) ?: [] as $dir) {
        $modified = getBrowserProfileModifiedTime($dir);
        if ($modified !== null && $now - $modified > BROWSER_PROFILE_MAX_AGE_SECONDS && removeBrowserProfileDir($dir, $baseDir)) {
            $removed++;
        }
    }

    return $removed;
}

/**
 * Новая папка профиля `storage/temp/chrome-<id>-<случайное>/`.
 */
function createBrowserProfileDir(int $ownerId, string $baseDir = BROWSER_PROFILES_PATH): string
{
    $dir = $baseDir . '/' . BROWSER_PROFILE_PREFIX . $ownerId . '-' . bin2hex(random_bytes(BROWSER_PROFILE_RANDOM_BYTES));
    if (!is_dir($baseDir) && !mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
        throw new RuntimeException('Не удалось создать папку для профиля Chrome: ' . $baseDir);
    }
    if (!mkdir($dir, 0775)) {
        throw new RuntimeException('Не удалось создать папку профиля Chrome: ' . $dir);
    }

    return $dir;
}

/**
 * Удаляет папку профиля с несколькими попытками. Удаляет только `chrome-*` прямо внутри `$baseDir`.
 *
 * @return bool true — папки больше нет
 */
function removeBrowserProfileDir(string $dir, string $baseDir = BROWSER_PROFILES_PATH): bool
{
    $realBase = realpath($baseDir);
    $realDir = realpath($dir);
    if ($realDir === false) {
        return true;
    }
    if (
        $realBase === false
        || dirname($realDir) !== $realBase
        || !str_starts_with(basename($realDir), BROWSER_PROFILE_PREFIX)
    ) {
        throw new InvalidArgumentException('Папка не похожа на профиль Chrome: ' . $dir);
    }

    for ($attempt = 1; $attempt <= BROWSER_PROFILE_REMOVE_ATTEMPTS; $attempt++) {
        if (removeDirectoryTree($realDir)) {
            return true;
        }
        usleep(BROWSER_PROFILE_REMOVE_PAUSE_US);
    }

    return false;
}

/**
 * Рекурсивное удаление без `@`: ошибки файловой системы не бросаются наружу, итог — по наличию папки.
 */
function removeDirectoryTree(string $dir): bool
{
    try {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $path = $item->getPathname();
            $item->isDir() && !$item->isLink() ? rmdir($path) : unlink($path);
        }
        rmdir($dir);
    } catch (Throwable) {
        // файл ещё занят Chrome — следующая попытка
    }
    clearstatcache(true, $dir);

    return !is_dir($dir);
}

/**
 * Запускает Chrome без окна с новой папкой профиля. Ошибка запуска → исключение с понятным текстом
 * (папка профиля в этом случае уже удалена).
 *
 * @param array<string, mixed> $extraOptions
 * @return array{browser: Browser, profile_dir: string}
 */
function launchBrowser(
    string $chromePath,
    int $ownerId,
    int $startupTimeoutSeconds,
    array $extraOptions = []
): array {
    if (!isBrowserLibraryAvailable()) {
        throw new RuntimeException('библиотека chrome-php не установлена (composer install).');
    }
    if (!is_file($chromePath)) {
        throw new RuntimeException('файл не найден: ' . $chromePath);
    }

    removeStaleBrowserProfiles();
    $profileDir = createBrowserProfileDir($ownerId);

    try {
        $browser = (new BrowserFactory($chromePath))->createBrowser(
            buildBrowserOptions($profileDir, $startupTimeoutSeconds, $extraOptions)
        );
    } catch (OperationTimedOut) {
        removeBrowserProfileDir($profileDir);
        throw new RuntimeException("Chrome не ответил за {$startupTimeoutSeconds} с.");
    } catch (Throwable $e) {
        removeBrowserProfileDir($profileDir);
        throw new RuntimeException('Chrome не запустился: ' . $e->getMessage(), 0, $e);
    }

    return ['browser' => $browser, 'profile_dir' => $profileDir];
}

/**
 * Закрывает браузер (завершает процесс) и удаляет папку профиля. Ошибки не бросает — пишет в лог.
 *
 * @param array{browser: Browser, profile_dir: string} $session
 */
function closeBrowser(array $session): void
{
    try {
        $session['browser']->close();
    } catch (Throwable $e) {
        logException($e, ['service' => 'browser', 'action' => 'close']);
    }

    try {
        if (!removeBrowserProfileDir($session['profile_dir'])) {
            logWarning('Не удалось удалить папку профиля Chrome', ['dir' => $session['profile_dir']]);
        }
    } catch (Throwable $e) {
        logException($e, ['service' => 'browser', 'action' => 'remove_profile']);
    }
}

/**
 * Версия запущенного Chrome через DevTools (`chrome.exe --version` на Windows ничего не печатает).
 */
function getBrowserVersion(Browser $browser): ?string
{
    $response = $browser->getConnection()->sendMessageSync(new Message('Browser.getVersion'), BROWSER_COMMAND_TIMEOUT_MS);
    $product = $response->isSuccessful() ? $response->getResultData('product') : null;

    return is_string($product) ? parseBrowserVersion($product) : null;
}

/**
 * Подключает перехват всех запросов вкладки, дочерних фреймов/воркеров (`Target.setAutoAttach`) и закрытие
 * всплывающих окон (`Target.targetCreated`). При исключении в `$shouldAllow` срабатывает безопасно (`failRequest`).
 *
 * Возвращает функцию очистки слушателей и дочерних сессий (без вызова `Fetch.disable`, чтобы перехват
 * оставался активным до закрытия вкладки).
 *
 * @param callable(array<string, mixed>, Session): bool $shouldAllow
 * @param callable(Throwable, array<string, mixed>): void|null $onError
 * @return callable(): void
 */
function attachBrowserRequestInterception(Page $page, callable $shouldAllow, ?callable $onError = null): callable
{
    $session = $page->getSession();
    $connection = $session->getConnection();
    $pageTargetId = $session->getTargetId();
    /** @var array<string, Session> $childSessions */
    $childSessions = [];

    $attachSession = static function (Session $targetSession, bool $isRoot, string $targetType) use (
        &$attachSession,
        &$childSessions,
        $connection,
        $shouldAllow,
        $onError
    ): void {
        $targetSession->on('method:Fetch.requestPaused', static function (array $params) use (
            $targetSession,
            $shouldAllow,
            $onError
        ): void {
            $requestId = (string) ($params['requestId'] ?? '');
            if ($requestId === '') {
                return;
            }
            try {
                $allowed = (bool) $shouldAllow($params, $targetSession);
                $targetSession->sendMessage($allowed
                    ? new Message('Fetch.continueRequest', ['requestId' => $requestId])
                    : new Message('Fetch.failRequest', [
                        'requestId'   => $requestId,
                        'errorReason' => BROWSER_BLOCK_REASON,
                    ]));
            } catch (Throwable $e) {
                if ($onError !== null) {
                    $onError($e, $params);
                }
                try {
                    $targetSession->sendMessage(new Message('Fetch.failRequest', [
                        'requestId'   => $requestId,
                        'errorReason' => BROWSER_BLOCK_REASON,
                    ]));
                } catch (Throwable) {
                    // запрос останется приостановленным до закрытия вкладки и не уйдёт в сеть
                }
            }
        });

        if ($isRoot || in_array($targetType, BROWSER_CHILD_CONTAINER_TYPES, true)) {
            $targetSession->on('method:Target.attachedToTarget', static function (array $params) use (
                &$attachSession,
                &$childSessions,
                $connection,
                $onError
            ): void {
                $childSessionId = (string) ($params['sessionId'] ?? '');
                $targetInfo = is_array($params['targetInfo'] ?? null) ? $params['targetInfo'] : [];
                $childTargetId = (string) ($targetInfo['targetId'] ?? '');
                $childType = (string) ($targetInfo['type'] ?? '');
                $waiting = !empty($params['waitingForDebugger']);
                if ($childSessionId === '' || isset($childSessions[$childSessionId])) {
                    return;
                }

                try {
                    $childSession = $connection->createSession($childTargetId, $childSessionId);
                    $childSessions[$childSessionId] = $childSession;
                    $attachSession($childSession, false, $childType);
                    if ($waiting) {
                        $childSession->sendMessage(new Message('Runtime.runIfWaitingForDebugger'));
                    }
                } catch (Throwable $e) {
                    if ($onError !== null) {
                        $onError($e, $params);
                    }
                }
            });

            $targetSession->on('method:Target.detachedFromTarget', static function (array $params) use (&$childSessions): void {
                $detachedSessionId = (string) ($params['sessionId'] ?? '');
                if ($detachedSessionId === '' || !isset($childSessions[$detachedSessionId])) {
                    return;
                }
                $child = $childSessions[$detachedSessionId];
                unset($childSessions[$detachedSessionId]);
                try {
                    $child->destroy();
                } catch (Throwable) {
                    // сессия уже закрыта
                }
            });
        }

        $fetchParams = ['patterns' => [['urlPattern' => '*', 'requestStage' => 'Request']]];
        $autoAttachParams = ['autoAttach' => true, 'waitForDebuggerOnStart' => true, 'flatten' => true];
        $bypassParams = ['bypass' => true];

        if ($isRoot) {
            $bypassResponse = $targetSession->sendMessageSync(
                new Message('Network.setBypassServiceWorker', $bypassParams),
                BROWSER_COMMAND_TIMEOUT_MS
            );
            if (!$bypassResponse->isSuccessful()) {
                throw new RuntimeException('обход Service Worker не включился: ' . $bypassResponse->getErrorMessage());
            }

            $fetchResponse = $targetSession->sendMessageSync(
                new Message('Fetch.enable', $fetchParams),
                BROWSER_COMMAND_TIMEOUT_MS
            );
            if (!$fetchResponse->isSuccessful()) {
                throw new RuntimeException('перехват запросов не включился: ' . $fetchResponse->getErrorMessage());
            }

            $attachResponse = $targetSession->sendMessageSync(
                new Message('Target.setAutoAttach', $autoAttachParams),
                BROWSER_COMMAND_TIMEOUT_MS
            );
            if (!$attachResponse->isSuccessful()) {
                throw new RuntimeException('перехват дочерних целей не включился: ' . $attachResponse->getErrorMessage());
            }

            return;
        }

        // Внутри Target.attachedToTarget только асинхронный sendMessage(): иначе readLine() реентрантно заблокируется.
        $targetSession->sendMessage(new Message('Fetch.enable', $fetchParams));
        if (in_array($targetType, BROWSER_CHILD_CONTAINER_TYPES, true)) {
            $targetSession->sendMessage(new Message('Network.setBypassServiceWorker', $bypassParams));
            $targetSession->sendMessage(new Message('Target.setAutoAttach', $autoAttachParams));
        }
    };

    $popupGuard = static function (array $params) use ($connection, $pageTargetId): void {
        $targetInfo = is_array($params['targetInfo'] ?? null) ? $params['targetInfo'] : [];
        $targetId = (string) ($targetInfo['targetId'] ?? '');
        if (($targetInfo['type'] ?? '') !== 'page' || $targetId === '' || $targetId === $pageTargetId) {
            return;
        }
        try {
            $connection->sendMessage(new Message('Target.closeTarget', ['targetId' => $targetId]));
        } catch (Throwable) {
            // окно уже закрылось или заблокировано --block-new-web-contents
        }
    };
    $connection->on(Connection::EVENT_TARGET_CREATED, $popupGuard);

    $cleanup = static function () use ($connection, $popupGuard, &$childSessions): void {
        $connection->removeListener(Connection::EVENT_TARGET_CREATED, $popupGuard);
        foreach ($childSessions as $child) {
            try {
                $child->destroy();
            } catch (Throwable) {
                // сессия уже уничтожена
            }
        }
        $childSessions = [];
    };

    try {
        $attachSession($session, true, 'page');
    } catch (Throwable $e) {
        $cleanup();
        throw $e;
    }

    return $cleanup;
}

/**
 * Открывает страницу в новой вкладке на ширине `$width`. Открыта — когда готов DOM (`DOMContentLoaded`); `load` ждём
 * ещё не дольше BROWSER_LOAD_WAIT_AFTER_DOM_MS (медленный сторонний ресурс задерживает `load` на десятки секунд —
 * страница при этом рабочая; `load_ms = null` — не дождались), затем BROWSER_SETTLE_MS ловим поздние ошибки.
 * Все сетевые запросы и редиректы проверяются через `isBrowserRequestAllowed()` на уровне CDP-перехвата.
 * Сетевая ошибка (`net::ERR_NAME_NOT_RESOLVED` и т. п.) — `error`: Chrome показал бы свою страницу ошибки, и
 * `waitForNavigation()` из `chrome-php` счёл бы её загруженной. Не бросает исключений: итог — в результате.
 * События DevTools сводятся `applyBrowserEvent()`; пришедшие после ожидания не учитываются.
 * `$capture` — открывшаяся страница снимается до закрытия вкладки (`captureBrowserScreenshots()`): итог в
 * `screenshot`, ошибка снимка — в `screenshot_error` (загрузка при этом остаётся открытой).
 *
 * @param callable(string $host): list<string>|null $resolver
 * @return array{url: string, width: int, status: string, dom_ms: ?int, load_ms: ?int, error: ?string,
 *               events: list<array<string, mixed>>, dropped: int,
 *               screenshot?: array{image: string, thumbnail: string, truncated: bool}, screenshot_error?: string}
 */
function openBrowserPage(
    Browser $browser,
    string $url,
    int $width = BROWSER_WINDOW_WIDTH,
    int $timeoutMs = BROWSER_PAGE_TIMEOUT_MS,
    bool $capture = false,
    ?callable $resolver = null
): array {
    $cachedResolver = createCachedBrowserResolver($resolver ?? 'resolveHostIps');
    $result = ['url' => $url, 'width' => $width, 'status' => BROWSER_PAGE_OPENED, 'dom_ms' => null, 'load_ms' => null,
        'error' => null];
    if (!isBrowserRequestAllowed($url, $cachedResolver)) {
        logWarning('Запрос Chrome заблокирован защитой от SSRF', [
            'url'   => sanitizeBrowserLogUrl($url),
            'stage' => 'navigate',
        ]);
        $result['status'] = BROWSER_PAGE_ERROR;
        $result['error'] = BROWSER_SSRF_BLOCKED_ERROR;

        return $result + ['events' => [], 'dropped' => 0];
    }

    $connection = $browser->getConnection();
    $wasStrict = $connection->isStrict();
    $connection->setStrict(false);
    $page = null;
    $cleanupInterception = null;
    $state = createBrowserEventState(null);
    $collecting = true;
    $guardState = ['blocked_main_document' => false];

    try {
        $page = $browser->createPage();
        $mainFrameId = $page->getFrameManager()->getMainFrame()->getFrameId();
        $state = createBrowserEventState($mainFrameId);
        $session = $page->getSession();
        foreach (BROWSER_COLLECTED_EVENTS as $method) {
            $session->on('method:' . $method, static function (array $params) use (&$state, &$collecting, $method): void {
                if ($collecting) {
                    $state = applyBrowserEvent($state, $method, $params);
                }
            });
        }
        $session->sendMessageSync(new Message('Log.enable'), BROWSER_COMMAND_TIMEOUT_MS);
        $page->setDeviceMetricsOverride(buildViewportOverride($width))->await(BROWSER_COMMAND_TIMEOUT_MS);

        $cleanupInterception = attachBrowserRequestInterception(
            $page,
            static function (array $params) use ($cachedResolver, $mainFrameId, &$guardState): bool {
                $request = is_array($params['request'] ?? null) ? $params['request'] : [];
                $reqUrl = (string) ($request['url'] ?? '');
                $resourceType = (string) ($params['resourceType'] ?? '');
                if (isBrowserRequestAllowed($reqUrl, $cachedResolver)) {
                    return true;
                }

                logWarning('Запрос Chrome заблокирован защитой от SSRF', [
                    'url'           => sanitizeBrowserLogUrl($reqUrl),
                    'resource_type' => $resourceType,
                ]);
                if ($resourceType === BROWSER_DOCUMENT_TYPE && ($params['frameId'] ?? null) === $mainFrameId) {
                    $guardState['blocked_main_document'] = true;
                }

                return false;
            },
            static function (Throwable $e, array $params): void {
                $request = is_array($params['request'] ?? null) ? $params['request'] : [];
                logException($e, [
                    'service' => 'browser',
                    'action'  => 'request_guard',
                    'url'     => sanitizeBrowserLogUrl((string) ($request['url'] ?? '')),
                ]);
            }
        );

        $result = array_merge($result, navigateBrowserPage(
            $page,
            $url,
            $timeoutMs,
            static function () use (&$guardState): bool {
                return $guardState['blocked_main_document'];
            }
        ));
        $collecting = false;
        if ($guardState['blocked_main_document']) {
            $result['status'] = BROWSER_PAGE_ERROR;
            $result['error'] = BROWSER_SSRF_BLOCKED_ERROR;
        }
        if ($capture && $result['status'] === BROWSER_PAGE_OPENED) {
            try {
                $result['screenshot'] = captureBrowserScreenshots($page, $width);
            } catch (Throwable $e) {
                $result['screenshot_error'] = $e->getMessage() !== '' ? $e->getMessage() : get_class($e);
            }
        }
    } catch (Throwable $e) {
        $result['status'] = BROWSER_PAGE_ERROR;
        $result['error'] = $e->getMessage() !== '' ? $e->getMessage() : get_class($e);
    } finally {
        $collecting = false;
        if ($page !== null) {
            try {
                $page->close();
            } catch (Throwable) {
                // вкладка закроется вместе с браузером
            }
        }
        if ($cleanupInterception !== null) {
            $cleanupInterception();
        }
        $connection->setStrict($wasStrict);
    }

    return $result + ['events' => $state['events'], 'dropped' => $state['dropped']];
}

/**
 * Снимок всей страницы (высота — по содержимому, с пределом SCREENSHOT_MAX_HEIGHT_PX) и миниатюра её верха,
 * уменьшенная самим Chrome (`clip.scale`): без раскодирования большого снимка в PHP. JPEG, двоичные данные.
 *
 * @return array{image: string, thumbnail: string, truncated: bool}
 */
function captureBrowserScreenshots(Page $page, int $width): array
{
    $contentSize = $page->getLayoutMetrics()->await(BROWSER_COMMAND_TIMEOUT_MS)->getCssContentSize();
    $clip = buildScreenshotClip($width, (float) ($contentSize['height'] ?? 0), BROWSER_WINDOW_HEIGHT);
    $thumbClip = buildThumbnailClip($clip);

    $image = $page->screenshot([
        'format'                => 'jpeg',
        'quality'               => SCREENSHOT_JPEG_QUALITY,
        'captureBeyondViewport' => true,
        'clip'                  => new Clip(0, 0, $clip['width'], $clip['height']),
    ])->getBase64(SCREENSHOT_CAPTURE_TIMEOUT_MS);
    $thumbnail = $page->screenshot([
        'format'                => 'jpeg',
        'quality'               => SCREENSHOT_THUMB_JPEG_QUALITY,
        'captureBeyondViewport' => true,
        'clip'                  => new Clip(0, 0, $thumbClip['width'], $thumbClip['height'], $thumbClip['scale']),
    ])->getBase64(SCREENSHOT_CAPTURE_TIMEOUT_MS);

    return [
        'image'     => (string) base64_decode($image, true),
        'thumbnail' => (string) base64_decode($thumbnail, true),
        'truncated' => $clip['truncated'],
    ];
}

/**
 * `Page.navigate` и ожидание `DOMContentLoaded`, затем `load` — по событиям главного фрейма, с общим сроком;
 * после — BROWSER_SETTLE_MS чтения событий.
 * Новая навигация (JS-редирект) подменяет ожидаемую, как в нестрогом режиме `chrome-php`.
 *
 * @param callable(): bool|null $shouldAbort
 * @return array{status: string, dom_ms?: int, load_ms?: int, error?: string}
 */
function navigateBrowserPage(Page $page, string $url, int $timeoutMs, ?callable $shouldAbort = null): array
{
    $started = hrtime(true);
    $elapsedMs = static fn (): int => intdiv(hrtime(true) - $started, 1000000);
    $timeout = ['status' => BROWSER_PAGE_TIMEOUT, 'error' => 'Страница не загрузилась за ' . intdiv($timeoutMs, 1000) . ' с'];

    $session = $page->getSession();
    $session->getConnection()->readData();
    $frame = $page->getFrameManager()->getMainFrame();
    $previousLoaderId = $frame->getLatestLoaderId();

    $reader = $session->sendMessage(new Message('Page.navigate', ['url' => $url]));
    while (!$reader->hasResponse()) {
        $reader->checkForResponse();
        if ($shouldAbort !== null && $shouldAbort()) {
            return ['status' => BROWSER_PAGE_ERROR, 'error' => BROWSER_SSRF_BLOCKED_ERROR];
        }
        if (!$reader->hasResponse()) {
            if ($elapsedMs() >= $timeoutMs) {
                return $timeout;
            }
            usleep(BROWSER_POLL_INTERVAL_US);
        }
    }

    $response = $reader->getResponse();
    if (!$response->isSuccessful()) {
        return ['status' => BROWSER_PAGE_ERROR, 'error' => $response->getErrorMessage()];
    }
    $errorText = $response->getResultData('errorText');
    if (is_string($errorText) && $errorText !== '') {
        return ['status' => BROWSER_PAGE_ERROR, 'error' => $errorText];
    }

    $loaderId = (string) $response->getResultData('loaderId');
    $result = ['status' => BROWSER_PAGE_OPENED];
    foreach (['dom_ms' => Page::DOM_CONTENT_LOADED, 'load_ms' => Page::LOAD] as $key => $event) {
        while (true) {
            $lifecycle = $page->getCurrentLifecycle();
            if ($shouldAbort !== null && $shouldAbort()) {
                return ['status' => BROWSER_PAGE_ERROR, 'error' => BROWSER_SSRF_BLOCKED_ERROR];
            }
            $latest = $frame->getLatestLoaderId();
            if ($latest !== $loaderId && $latest !== $previousLoaderId) {
                $loaderId = $latest;
            }
            if ($latest === $loaderId && array_key_exists($event, $lifecycle)) {
                $result[$key] = $elapsedMs();
                break;
            }
            $deadline = $key === 'dom_ms'
                ? $timeoutMs
                : min($timeoutMs, $result['dom_ms'] + BROWSER_LOAD_WAIT_AFTER_DOM_MS);
            if ($elapsedMs() >= $deadline) {
                if ($key === 'dom_ms') {
                    return $timeout;
                }
                break 2;
            }
            usleep(BROWSER_POLL_INTERVAL_US);
        }
    }

    // Ошибки из таймеров и отложенных скриптов приходят уже после `load`
    $settleUntil = $elapsedMs() + BROWSER_SETTLE_MS;
    while ($elapsedMs() < $settleUntil) {
        $session->getConnection()->readData();
        if ($shouldAbort !== null && $shouldAbort()) {
            return ['status' => BROWSER_PAGE_ERROR, 'error' => BROWSER_SSRF_BLOCKED_ERROR];
        }
        usleep(BROWSER_POLL_INTERVAL_US);
    }

    return $result;
}

/**
 * «Проверить Chrome» со страницы настроек: запуск, версия, закрытие.
 *
 * @return array{ok: bool, message: string}
 */
function checkChrome(string $chromePath): array
{
    set_time_limit(BROWSER_CHECK_TIME_LIMIT);

    try {
        $session = launchBrowser($chromePath, 0, BROWSER_CHECK_TIMEOUT_SECONDS);
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'Chrome недоступен: ' . $e->getMessage()];
    }

    try {
        $version = getBrowserVersion($session['browser']);

        return ['ok' => true, 'message' => 'Chrome ' . ($version ?? '(версия не определена)') . ' работает.'];
    } catch (Throwable $e) {
        logException($e, ['service' => 'browser', 'action' => 'version']);

        return ['ok' => false, 'message' => 'Chrome запустился, но не ответил на запрос версии.'];
    } finally {
        closeBrowser($session);
    }
}

/**
 * Параметры Page.printToPDF: A4, фоны и размер страницы из print.css.
 *
 * @return array<string, bool|int|float>
 */
function buildBrowserPdfOptions(): array
{
    return [
        'landscape'           => false,
        'printBackground'     => true,
        'displayHeaderFooter' => false,
        'paperWidth'          => BROWSER_PDF_PAPER_WIDTH_INCHES,
        'paperHeight'         => BROWSER_PDF_PAPER_HEIGHT_INCHES,
        'marginTop'           => BROWSER_PDF_MARGIN_INCHES,
        'marginBottom'        => BROWSER_PDF_MARGIN_INCHES,
        'marginLeft'          => BROWSER_PDF_MARGIN_INCHES,
        'marginRight'         => BROWSER_PDF_MARGIN_INCHES,
        'preferCSSPageSize'   => true,
    ];
}

/**
 * Печатает только локальный `file:///` HTML в новом профиле без JS и без выхода в сеть.
 * Вкладка, Chrome и профиль закрываются при любом исходе.
 */
function printBrowserHtmlToPdf(string $chromePath, string $htmlUri, string $pdfPath, int $ownerId): void
{
    $scheme = strtolower((string) parse_url($htmlUri, PHP_URL_SCHEME));
    if ($scheme !== 'file' || !isBrowserPdfRequestAllowed($htmlUri)) {
        throw new InvalidArgumentException('PDF можно печатать только из локального HTML-файла.');
    }

    set_time_limit(BROWSER_PDF_TIME_LIMIT_SECONDS);
    $session = launchBrowser($chromePath, $ownerId, BROWSER_PDF_STARTUP_TIMEOUT_SECONDS, buildBrowserPdfLaunchOptions());
    $connection = $session['browser']->getConnection();
    $wasStrict = $connection->isStrict();
    $connection->setStrict(false);
    $page = null;
    $cleanupInterception = null;
    $blockedMain = false;

    try {
        $page = $session['browser']->createPage();
        $mainFrameId = $page->getFrameManager()->getMainFrame()->getFrameId();
        $cleanupInterception = attachBrowserRequestInterception(
            $page,
            static function (array $params) use ($mainFrameId, &$blockedMain): bool {
                $request = is_array($params['request'] ?? null) ? $params['request'] : [];
                $reqUrl = (string) ($request['url'] ?? '');
                $resourceType = (string) ($params['resourceType'] ?? '');
                if (isBrowserPdfRequestAllowed($reqUrl)) {
                    return true;
                }

                logWarning('PDF: сетевой или недопустимый запрос заблокирован', [
                    'url'           => sanitizeBrowserLogUrl($reqUrl),
                    'resource_type' => $resourceType,
                ]);
                if ($resourceType === BROWSER_DOCUMENT_TYPE && ($params['frameId'] ?? null) === $mainFrameId) {
                    $blockedMain = true;
                }

                return false;
            },
            static function (Throwable $e, array $params): void {
                $request = is_array($params['request'] ?? null) ? $params['request'] : [];
                logException($e, [
                    'service' => 'browser',
                    'action'  => 'pdf_request_guard',
                    'url'     => sanitizeBrowserLogUrl((string) ($request['url'] ?? '')),
                ]);
            }
        );

        $navigation = navigateBrowserPage(
            $page,
            $htmlUri,
            BROWSER_PDF_PAGE_TIMEOUT_MS,
            static function () use (&$blockedMain): bool {
                return $blockedMain;
            }
        );
        if ($blockedMain) {
            throw new RuntimeException(BROWSER_SSRF_BLOCKED_ERROR);
        }
        if ($navigation['status'] !== BROWSER_PAGE_OPENED) {
            throw new RuntimeException('Локальный HTML не загрузился: ' . ($navigation['error'] ?? 'неизвестная ошибка'));
        }

        $page->pdf(buildBrowserPdfOptions())->saveToFile($pdfPath, BROWSER_PDF_PRINT_TIMEOUT_MS);
    } finally {
        if ($page !== null) {
            try {
                $page->close();
            } catch (Throwable $e) {
                logException($e, ['service' => 'browser', 'action' => 'close_pdf_page']);
            }
        }
        if ($cleanupInterception !== null) {
            $cleanupInterception();
        }
        $connection->setStrict($wasStrict);
        closeBrowser($session);
    }
}
