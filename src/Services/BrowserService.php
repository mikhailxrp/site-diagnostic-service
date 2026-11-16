<?php

declare(strict_types=1);

/**
 * Chrome без окна через `chrome-php/chrome`: запуск с отдельной папкой профиля, открытие страницы с тайм-аутом,
 * версия браузера, гарантированное закрытие и удаление профиля.
 *
 * Объекты `chrome-php` используются только в этом файле. Чистые функции (параметры запуска, отбор страниц,
 * сообщение шага) не запускают браузер — их покрывают тесты.
 */

use HeadlessChromium\Browser;
use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Communication\Message;
use HeadlessChromium\Exception\OperationTimedOut;
use HeadlessChromium\Page;

const BROWSER_STARTUP_TIMEOUT_SECONDS = 30;    // запуск Chrome воркером
const BROWSER_CHECK_TIMEOUT_SECONDS   = 15;    // «Проверить Chrome» из веба: меньше max_execution_time запроса
const BROWSER_CHECK_TIME_LIMIT        = 60;    // set_time_limit() для проверки из веба: запуск + закрытие
const BROWSER_PAGE_TIMEOUT_MS         = 30000; // загрузка одной страницы (до готовности DOM)
const BROWSER_LOAD_WAIT_AFTER_DOM_MS  = 10000; // после DOM ждём `load` не дольше: медленный счётчик держит его минутами
const BROWSER_COMMAND_TIMEOUT_MS      = 10000; // синхронная команда DevTools (версия, закрытие вкладки)
const BROWSER_POLL_INTERVAL_US        = 10000; // пауза между чтениями событий DevTools при ожидании загрузки
const BROWSER_WINDOW_WIDTH            = 1366;
const BROWSER_WINDOW_HEIGHT           = 768;

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

/**
 * Установлен ли `chrome-php/chrome` (`vendor/autoload.php` подключён и пакет есть).
 */
function isBrowserLibraryAvailable(): bool
{
    return class_exists(BrowserFactory::class);
}

/**
 * Параметры `BrowserFactory::createBrowser()`. User-Agent не подменяется: Chrome представляется сам.
 *
 * @return array<string, mixed>
 */
function buildBrowserOptions(string $profileDir, int $startupTimeoutSeconds): array
{
    return [
        'headless'                => true,
        'windowSize'              => [BROWSER_WINDOW_WIDTH, BROWSER_WINDOW_HEIGHT],
        'userDataDir'             => $profileDir,
        'startupTimeout'          => $startupTimeoutSeconds,
        'sendSyncDefaultTimeout'  => BROWSER_COMMAND_TIMEOUT_MS,
        'ignoreCertificateErrors' => true, // проблемы SSL ловит SslCheck; страница всё равно должна открыться
        'keepAlive'               => false,
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
 * «Открыто в браузере: 4 из 5 страниц; не открылось: 1; пропущено (защита от ботов): 1».
 *
 * @param list<array{status: string}> $results результаты `openBrowserPage()`
 */
function buildBrowserStepMessage(array $results, int $blocked): string
{
    $opened = count(array_filter($results, static fn (array $result): bool => $result['status'] === BROWSER_PAGE_OPENED));
    $failed = count($results) - $opened;

    $message = "Открыто в браузере: {$opened} из " . (count($results) + $blocked) . ' страниц';
    if ($failed > 0) {
        $message .= "; не открылось: {$failed}";
    }
    if ($blocked > 0) {
        $message .= "; пропущено (защита от ботов): {$blocked}";
    }

    return $message;
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
        $modified = filemtime($dir);
        if ($modified !== false && $now - $modified > BROWSER_PROFILE_MAX_AGE_SECONDS && removeBrowserProfileDir($dir, $baseDir)) {
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
 * @return array{browser: Browser, profile_dir: string}
 */
function launchBrowser(string $chromePath, int $ownerId, int $startupTimeoutSeconds): array
{
    if (!isBrowserLibraryAvailable()) {
        throw new RuntimeException('библиотека chrome-php не установлена (composer install).');
    }
    if (!is_file($chromePath)) {
        throw new RuntimeException('файл не найден: ' . $chromePath);
    }

    removeStaleBrowserProfiles();
    $profileDir = createBrowserProfileDir($ownerId);

    try {
        $browser = (new BrowserFactory($chromePath))->createBrowser(buildBrowserOptions($profileDir, $startupTimeoutSeconds));
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
 * Открывает страницу в новой вкладке. Открыта — когда готов DOM (`DOMContentLoaded`); `load` ждём ещё не дольше
 * BROWSER_LOAD_WAIT_AFTER_DOM_MS (медленный сторонний ресурс задерживает `load` на десятки секунд — страница при
 * этом рабочая; `load_ms = null` — не дождались).
 * Сетевая ошибка (`net::ERR_NAME_NOT_RESOLVED` и т. п.) — `error`: Chrome показал бы свою страницу ошибки, и
 * `waitForNavigation()` из `chrome-php` счёл бы её загруженной. Не бросает исключений: итог — в результате.
 *
 * @return array{url: string, status: string, dom_ms: ?int, load_ms: ?int, error: ?string}
 */
function openBrowserPage(Browser $browser, string $url, int $timeoutMs = BROWSER_PAGE_TIMEOUT_MS): array
{
    $page = null;
    $result = ['url' => $url, 'status' => BROWSER_PAGE_OPENED, 'dom_ms' => null, 'load_ms' => null, 'error' => null];

    try {
        $page = $browser->createPage();
        $result = array_merge($result, navigateBrowserPage($page, $url, $timeoutMs));
    } catch (Throwable $e) {
        $result['status'] = BROWSER_PAGE_ERROR;
        $result['error'] = $e->getMessage() !== '' ? $e->getMessage() : get_class($e);
    } finally {
        if ($page !== null) {
            try {
                $page->close();
            } catch (Throwable) {
                // вкладка закроется вместе с браузером
            }
        }
    }

    return $result;
}

/**
 * `Page.navigate` и ожидание `DOMContentLoaded`, затем `load` — по событиям главного фрейма, с общим сроком.
 * Новая навигация (JS-редирект) подменяет ожидаемую, как в нестрогом режиме `chrome-php`.
 *
 * @return array{status: string, dom_ms?: int, load_ms?: int, error?: string}
 */
function navigateBrowserPage(Page $page, string $url, int $timeoutMs): array
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
                return $key === 'dom_ms' ? $timeout : $result;
            }
            usleep(BROWSER_POLL_INTERVAL_US);
        }
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
