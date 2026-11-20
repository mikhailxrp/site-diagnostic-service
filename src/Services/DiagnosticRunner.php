<?php

declare(strict_types=1);

/**
 * Выполнение диагностики по шагам: общий контекст, цикл с try/catch на шаг, остановка проверки, прогресс.
 * Упавший шаг помечается `failed`, остальные выполняются дальше. Шаг может остановить проверку (`stop`):
 * тогда оставшиеся шаги помечаются `skipped` с причиной.
 */

require_once ROOT_PATH . '/src/Models/DiagnosticModel.php';
require_once ROOT_PATH . '/src/Models/FindingModel.php';
require_once ROOT_PATH . '/src/Models/PageModel.php';
require_once ROOT_PATH . '/src/Models/StackModel.php';
require_once ROOT_PATH . '/src/Models/ScreenshotModel.php';
require_once ROOT_PATH . '/src/Models/SettingsModel.php';
require_once ROOT_PATH . '/src/Models/AiResultModel.php';
require_once ROOT_PATH . '/src/Services/HttpClient.php';
require_once ROOT_PATH . '/src/Services/SettingsService.php';
require_once ROOT_PATH . '/src/Services/BrowserService.php';
require_once ROOT_PATH . '/src/Services/Checks/AvailabilityCheck.php';
require_once ROOT_PATH . '/src/Services/Checks/SslCheck.php';
require_once ROOT_PATH . '/src/Services/Checks/StackCheck.php';
require_once ROOT_PATH . '/src/Services/Checks/ResourceCheck.php';
require_once ROOT_PATH . '/src/Services/Checks/PhpErrorCheck.php';
require_once ROOT_PATH . '/src/Services/Checks/MixedContentCheck.php';
require_once ROOT_PATH . '/src/Services/Checks/BotProtectionCheck.php';
require_once ROOT_PATH . '/src/Services/Checks/SeoCheck.php';
require_once ROOT_PATH . '/src/Services/Checks/ExposedFilesCheck.php';
require_once ROOT_PATH . '/src/Services/Checks/OutdatedCheck.php';
require_once ROOT_PATH . '/src/Services/Checks/VulnerabilityCheck.php';
require_once ROOT_PATH . '/src/Services/Checks/SpeedCheck.php';
require_once ROOT_PATH . '/src/Services/ShopCrawler.php';
require_once ROOT_PATH . '/src/Services/CheckoutScenario.php';
require_once ROOT_PATH . '/src/Services/Checks/CheckoutCheck.php';
require_once ROOT_PATH . '/src/Services/AiClient.php';
require_once ROOT_PATH . '/src/Services/Ai/ProblemAnalysis.php';
require_once ROOT_PATH . '/src/Services/Ai/VisualAnalysis.php';
require_once ROOT_PATH . '/src/Services/Ai/SummaryAnalysis.php';
require_once ROOT_PATH . '/src/Services/Ai/ClientTextAnalysis.php';
require_once ROOT_PATH . '/src/Services/AiModels.php';
require_once ROOT_PATH . '/src/Services/DiagnosticNoLongerRunningException.php';

const HEARTBEAT_STALE_SECONDS        = 600;  // 10 минут без отметки воркера → диагностика прервана
const HEARTBEAT_MIN_INTERVAL_SECONDS = 30;   // обновлять heartbeat не чаще раза в 30 секунд
const STEP_MESSAGE_MAX_LENGTH        = 1000; // как diagnostic_steps.message VARCHAR(1000)

const WORKER_FATAL_ERROR_TYPES            = [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE];
const WORKER_FATAL_MEMORY_MARKER          = 'Allowed memory size';
const WORKER_FATAL_OOM_STEP_MESSAGE       = 'Шаг прерван: воркеру не хватило памяти.';
const WORKER_FATAL_OOM_DIAGNOSTIC_MESSAGE = 'Воркер аварийно завершился: недостаточно памяти.';
const WORKER_FATAL_STEP_MESSAGE           = 'Шаг прерван: фатальная ошибка воркера.';
const WORKER_FATAL_DIAGNOSTIC_MESSAGE     = 'Воркер аварийно завершился из-за фатальной ошибки.';

const PROGRESS_MAX = 100;

const STEP_STATUS_DONE    = 'done';
const STEP_STATUS_SKIPPED = 'skipped';
const STEP_STATUS_FAILED  = 'failed';

const STEP_STOPPED_MESSAGE           = 'Пропущено: шаг «%s» остановил проверку.';
const STEP_SAVE_FINDINGS_ERROR_PREFIX = 'Не удалось сохранить находки: ';
const AI_STEP_CODE_PREFIX            = 'ai_';

/**
 * Шаги диагностики по порядку. Обработчик получает контекст и возвращает
 * `['status' => 'done'|'skipped', 'message' => ?string, 'facts' => array, 'findings' => list, 'stop' => bool]`
 * (всё, кроме `status`, необязательно) или бросает исключение.
 *
 * @return list<array{code: string, title: string, handler: callable}>
 */
function getDiagnosticSteps(): array
{
    return [
        ['code' => 'availability', 'title' => 'Доступность сайта', 'handler' => 'runAvailabilityStep'],
        ['code' => 'stack',        'title' => 'Определение стека',       'handler' => 'runStackStep'],
        ['code' => 'outdated',     'title' => 'Устаревшие версии',       'handler' => 'runOutdatedStep'],
        ['code' => 'vulnerabilities', 'title' => 'Уязвимости',           'handler' => 'runVulnerabilitiesStep'],
        ['code' => 'crawl',        'title' => 'Поиск страниц магазина', 'handler' => 'runCrawlStep'],
        ['code' => 'resources',    'title' => 'Битые ресурсы',           'handler' => 'runResourcesStep'],
        ['code' => 'seo',          'title' => 'Базовое SEO',             'handler' => 'runSeoStep'],
        ['code' => 'exposed_files', 'title' => 'Служебные файлы',        'handler' => 'runExposedFilesStep'],
        ['code' => 'browser',      'title' => 'Проверка в браузере',     'handler' => 'runBrowserStep'],
        ['code' => 'checkout',     'title' => 'Сценарий заказа',         'handler' => 'runCheckoutStep'],
        ['code' => 'pagespeed',    'title' => 'Скорость (PageSpeed)',    'handler' => 'runPageSpeedStep'],
        ['code' => 'ai_problem',   'title' => 'Разбор проблемы (ИИ)',    'handler' => 'runAiProblemStep'],
        ['code' => 'ai_visual',    'title' => 'Проверка вёрстки (ИИ)',   'handler' => 'runAiVisualStep'],
        ['code' => 'ai_summary',   'title' => 'Итоговый вывод (ИИ)',     'handler' => 'runAiSummaryStep'],
        ['code' => 'ai_client',    'title' => 'Текст для клиента (ИИ)',  'handler' => 'runAiClientStep'],
    ];
}

/**
 * Только ИИ-шаги (код начинается с `ai_`) в порядке полного списка — для перезапуска ИИ-анализа по сохранённым
 * фактам: ни один из них не обращается к проверяемому сайту.
 *
 * @return list<array{code: string, title: string, handler: callable-string}>
 */
function getAiSteps(): array
{
    return array_values(array_filter(
        getDiagnosticSteps(),
        static fn (array $step): bool => str_starts_with($step['code'], AI_STEP_CODE_PREFIX)
    ));
}

/**
 * Шаг `availability` с настоящей сетью и БД: главная — в `checked_pages` (заглушка защиты от ботов — `blocked`),
 * сводка — в `summary_json`.
 *
 * @param array<string, mixed> $context
 * @return array<string, mixed>
 */
function runAvailabilityStep(array $context): array
{
    $diagnosticId = (int) $context['diagnostic']['id'];
    $result = runAvailabilityCheck(
        $context,
        withHeartbeat(static fn (string $url): array => httpRequest($url), $diagnosticId),
        withHeartbeat(static fn (string $host, bool $trusted): array => inspectSslCertificate($host, $trusted), $diagnosticId)
    );

    assertDiagnosticRunning($diagnosticId, touchDiagnosticHeartbeat($diagnosticId));
    $home = $result['facts']['home'];
    $botProtection = $home['status'] !== null ? detectBotProtection($home) : null;

    $pageId = insertCheckedPage($diagnosticId, [
        'url'         => $home['final_url'],
        'page_type'   => 'home',
        'found_by'    => 'start',
        'http_code'   => $home['status'],
        'response_ms' => $home['time_ms'],
        'blocked'     => $botProtection !== null,
    ]);
    $result['facts']['home']['page_id'] = $pageId;
    $result['facts']['home']['bot_protection'] = $botProtection;
    $result['findings'] = assignPageIdToFindings($result['findings'], $pageId, [$home['url'], $home['final_url']]);

    assertDiagnosticRunning(
        $diagnosticId,
        updateDiagnosticSummary($diagnosticId, $result['summary'] + [
            'blocked' => buildBlockedPagesSummary([$pageId => $botProtection]),
        ])
    );

    return $result;
}

/**
 * Шаг `stack` с настоящей сетью и БД: элементы стека — в `stack_items`, их id — в факты (`stack_item_id`)
 * для шага `outdated`.
 *
 * @param array<string, mixed> $context
 * @return array<string, mixed>
 */
function runStackStep(array $context): array
{
    $diagnosticId = (int) $context['diagnostic']['id'];
    $result = runStackCheck(
        $context,
        withHeartbeat(static fn (string $url): array => httpRequest($url), $diagnosticId)
    );

    assertDiagnosticRunning($diagnosticId, touchDiagnosticHeartbeat($diagnosticId));
    foreach ($result['facts']['items'] ?? [] as $index => $item) {
        $result['facts']['items'][$index]['stack_item_id'] = insertStackItem($diagnosticId, $item);
    }

    return $result;
}

/**
 * Шаг `outdated` с настоящей сетью и БД: актуальные версии — в `stack_items.latest_version`, дата справочника —
 * в `summary_json.databases`. Находки сохраняет `runDiagnostic()`.
 *
 * @param array<string, mixed> $context
 * @return array<string, mixed>
 */
function runOutdatedStep(array $context): array
{
    $diagnosticId = (int) $context['diagnostic']['id'];
    $result = runOutdatedCheck(
        $context,
        withHeartbeat(static fn (string $url): array => httpRequest($url), $diagnosticId)
    );
    if ($result['status'] !== STEP_STATUS_DONE) {
        return $result;
    }

    assertDiagnosticRunning($diagnosticId, touchDiagnosticHeartbeat($diagnosticId));
    foreach ($result['facts']['latest'] as $stackItemId => $latestVersion) {
        updateStackItemLatestVersion((int) $stackItemId, $latestVersion);
    }
    assertDiagnosticRunning(
        $diagnosticId,
        updateDiagnosticSummary($diagnosticId, ['databases' => $result['facts']['databases']])
    );

    return $result;
}

/**
 * Шаг `vulnerabilities` с БД: даты баз уязвимостей, с которыми сверялся стек, — в `summary_json.databases`
 * (рядом с базой версий). Находки сохраняет `runDiagnostic()`.
 *
 * @param array<string, mixed> $context
 * @return array<string, mixed>
 */
function runVulnerabilitiesStep(array $context): array
{
    $diagnosticId = (int) $context['diagnostic']['id'];
    $result = runVulnerabilityCheck(
        $context,
        api: withHeartbeat(static fn (string $url, ?array $json): array => httpApiRequest($url, $json), $diagnosticId)
    );
    if ($result['status'] === STEP_STATUS_DONE && $result['facts']['databases'] !== []) {
        assertDiagnosticRunning(
            $diagnosticId,
            updateDiagnosticSummary($diagnosticId, ['databases' => $result['facts']['databases']])
        );
    }

    return $result;
}

/**
 * Шаг `crawl` с настоящей сетью и БД: найденные страницы — в `checked_pages` (их `page_id` — в факты и находки),
 * причины не найденных — в `summary_json.pages`. HTML страниц остаётся в фактах для следующих шагов.
 *
 * @param array<string, mixed> $context
 * @return array<string, mixed>
 */
function runCrawlStep(array $context): array
{
    $diagnosticId = (int) $context['diagnostic']['id'];

    $result = crawlShopPages(
        $context,
        withHeartbeat(static fn (string $url): array => httpRequest($url), $diagnosticId),
        null,
        null
    );
    if ($result['status'] !== STEP_STATUS_DONE) {
        return $result;
    }

    assertDiagnosticRunning($diagnosticId, touchDiagnosticHeartbeat($diagnosticId));
    $blocked = [];
    foreach ($result['facts']['pages'] as $type => $page) {
        $botProtection = $page['status'] !== null ? detectBotProtection($page) : null;
        $pageId = insertCheckedPage($diagnosticId, [
            'url'         => $page['final_url'],
            'page_type'   => $type,
            'found_by'    => $page['found_by'],
            'http_code'   => $page['status'],
            'response_ms' => $page['time_ms'],
            'blocked'     => $botProtection !== null,
        ]);
        $blocked[$pageId] = $botProtection;
        $result['facts']['pages'][$type]['page_id'] = $pageId;
        $result['facts']['pages'][$type]['bot_protection'] = $botProtection;
        $result['findings'] = assignPageIdToFindings($result['findings'], $pageId, [$page['url'], $page['final_url']]);
    }

    assertDiagnosticRunning(
        $diagnosticId,
        updateDiagnosticSummary($diagnosticId, [
            'pages'   => [
                'platforms' => $result['facts']['platforms'],
                'missing'   => (object) $result['facts']['missing'],
            ],
            'blocked' => buildBlockedPagesSummary($blocked),
        ])
    );

    return $result;
}

/**
 * Шаг `resources` с настоящей сетью: битые ресурсы, PHP-ошибки и mixed content главной и найденных страниц.
 * Находки сохраняет `runDiagnostic()`; во время долгой проверки обновляется heartbeat, чтобы диагностику не сочли
 * зависшей.
 *
 * @param array<string, mixed> $context
 * @return array<string, mixed>
 */
function runResourcesStep(array $context): array
{
    $diagnosticId = (int) $context['diagnostic']['id'];

    $result = runResourceCheck(
        $context,
        withHeartbeat(
            static fn (string $url, string $method, ?int $maxBytes = null): array => httpRequest(
                $url,
                $method,
                null,
                null,
                $maxBytes
            ),
            $diagnosticId
        ),
        null
    );

    return addPageErrorFindings($result, $context);
}

/**
 * Шаг `seo` с настоящей сетью и БД: итоги robots.txt и sitemap — в `summary_json.seo`, находки сохраняет
 * `runDiagnostic()`.
 *
 * @param array<string, mixed> $context
 * @return array<string, mixed>
 */
function runSeoStep(array $context): array
{
    $diagnosticId = (int) $context['diagnostic']['id'];
    $result = runSeoCheck($context, withHeartbeat(static fn (string $url): array => httpRequest($url), $diagnosticId));

    if ($result['status'] === STEP_STATUS_DONE) {
        assertDiagnosticRunning(
            $diagnosticId,
            updateDiagnosticSummary($diagnosticId, ['seo' => [
                'robots'  => $result['facts']['robots'],
                'sitemap' => $result['facts']['sitemap'],
            ]])
        );
    }

    return $result;
}

/**
 * Шаг `exposed_files` с настоящей сетью: каждый путь читается не больше EXPOSED_FILE_MAX_BYTES, heartbeat — по времени
 * через `withHeartbeat()`. Находки сохраняет `runDiagnostic()`.
 *
 * @param array<string, mixed> $context
 * @return array<string, mixed>
 */
function runExposedFilesStep(array $context): array
{
    $diagnosticId = (int) $context['diagnostic']['id'];

    return runExposedFilesCheck(
        $context,
        withHeartbeat(
            static fn (string $url, int $maxBytes): array => httpRequest($url, 'GET', null, null, $maxBytes),
            $diagnosticId
        ),
        null
    );
}

/**
 * Шаг `browser` с настоящим Chrome и БД: главная и найденные страницы открываются в Chrome без окна на каждой
 * ширине BROWSER_VIEWPORT_WIDTHS (страницы с защитой от ботов и без ответа — нет). Браузер закрывается и профиль
 * удаляется в любом случае; heartbeat — после каждой загрузки и каждого повторного запроса. Ошибки JS и упавшие
 * запросы → находки `js_error` / `failed_request` (`Checks/BrowserConsoleCheck.php`). Chrome недоступен →
 * `skipped` с причиной.
 *
 * @param array<string, mixed> $context
 * @return array<string, mixed>
 */
function runBrowserStep(array $context): array
{
    $diagnosticId = (int) $context['diagnostic']['id'];
    $selection = selectBrowserPages(collectResourcePages($context));

    if ($selection['open'] === []) {
        return ['status' => STEP_STATUS_SKIPPED, 'message' => BROWSER_NO_PAGES_MESSAGE];
    }
    if (!isBrowserLibraryAvailable()) {
        return ['status' => STEP_STATUS_SKIPPED, 'message' => BROWSER_NO_LIBRARY_MESSAGE];
    }

    try {
        $session = launchBrowser(resolveChromePath(getSetting(CHROME_PATH_SETTING)), $diagnosticId, BROWSER_STARTUP_TIMEOUT_SECONDS);
    } catch (RuntimeException $e) {
        return ['status' => STEP_STATUS_SKIPPED, 'message' => BROWSER_UNAVAILABLE_PREFIX . $e->getMessage()];
    }

    $loads = [];
    try {
        foreach ($selection['open'] as $page) {
            foreach (BROWSER_VIEWPORT_WIDTHS as $width) {
                $load = openBrowserPage(
                    $session['browser'],
                    $page['url'],
                    $width,
                    BROWSER_PAGE_TIMEOUT_MS,
                    $page['page_id'] !== null
                ) + [
                    'types'   => $page['types'],
                    'page_id' => $page['page_id'],
                ];
                $loads[] = storeLoadScreenshot($load, $diagnosticId);
                assertDiagnosticRunning($diagnosticId, touchDiagnosticHeartbeat($diagnosticId));
            }
        }
    } finally {
        closeBrowser($session);
    }

    $recheck = withHeartbeat(static function (string $url): array {
        $response = httpRequest($url);

        return ['status' => $response['status'], 'error' => $response['error']];
    }, $diagnosticId);
    // «Свой домен» — по главной: даже если она за защитой от ботов и в браузере не открывалась
    $home = $context['facts']['availability']['home'] ?? [];
    $homeUrl = (string) (($home['final_url'] ?? '') !== '' ? $home['final_url'] : ($selection['open'][0]['url'] ?? ''));
    $browserFindings = buildBrowserFindings(
        $loads,
        $homeUrl,
        collectBrokenResourceUrls($context['facts']['resources']['resources'] ?? []),
        $recheck
    );

    return [
        'status'   => STEP_STATUS_DONE,
        'message'  => buildBrowserStepMessage(
            $loads,
            count($selection['blocked']),
            $browserFindings['js_errors'],
            $browserFindings['failed_requests'],
            count(array_filter(array_column($loads, 'screenshot_id')))
        ),
        'facts'    => [
            'loads'    => array_map(static fn (array $load): array => [
                'url'                  => $load['url'],
                'width'                => $load['width'],
                'status'               => $load['status'],
                'dom_ms'               => $load['dom_ms'],
                'load_ms'              => $load['load_ms'],
                'error'                => $load['error'],
                'types'                => $load['types'],
                'page_id'              => $load['page_id'],
                'events'               => count($load['events']),
                'dropped'              => $load['dropped'],
                'screenshot_id'        => $load['screenshot_id'] ?? null,
                'screenshot_truncated' => $load['screenshot_truncated'] ?? false,
                'screenshot_error'     => $load['screenshot_error'] ?? null,
            ], $loads),
            'blocked'  => $selection['blocked'],
            'rejected' => $browserFindings['rejected'],
        ],
        'findings' => $browserFindings['findings'],
    ];
}

/**
 * Шаг `checkout` с настоящим Chrome и БД: сценарий «товар → в корзину → корзина → форма оформления»
 * (`CheckoutScenario.php`), заказ не отправляется. Отдельный запуск Chrome; браузер закрывается и профиль удаляется
 * в любом случае; heartbeat — после каждой загрузки. Страницы сценария — новые строки `checked_pages`, снимки 1366 —
 * `screenshots`, итог — `summary_json.checkout` (по `page_ids` страницы сценария не попадают в «Проверенные
 * страницы» отчёта и в ИИ). Нет товара / Chrome → `skipped` с причиной.
 *
 * @param array<string, mixed> $context
 * @return array<string, mixed>
 */
function runCheckoutStep(array $context): array
{
    $diagnosticId = (int) $context['diagnostic']['id'];
    $target = selectCheckoutTarget($context);
    if ($target['skip'] !== null) {
        return ['status' => STEP_STATUS_SKIPPED, 'message' => buildCheckoutSkipMessage($target['skip'], $target['detail'])];
    }
    if (!isBrowserLibraryAvailable()) {
        return ['status' => STEP_STATUS_SKIPPED, 'message' => BROWSER_NO_LIBRARY_MESSAGE];
    }

    $shopPatterns = loadShopPatterns();
    $rules = buildCheckoutRules(
        loadCheckoutSelectors(),
        $shopPatterns,
        detectCheckoutPlatforms($context, array_keys($shopPatterns['platforms']))
    );

    try {
        $session = launchBrowser(
            resolveChromePath(getSetting(CHROME_PATH_SETTING)),
            $diagnosticId,
            BROWSER_STARTUP_TIMEOUT_SECONDS,
            buildCheckoutBrowserOptions()
        );
    } catch (RuntimeException $e) {
        return ['status' => STEP_STATUS_SKIPPED, 'message' => BROWSER_UNAVAILABLE_PREFIX . $e->getMessage()];
    }

    try {
        $scenario = runCheckoutScenario(
            $session['browser'],
            $target,
            $rules,
            (string) $context['diagnostic']['host'],
            static function () use ($diagnosticId): void {
                assertDiagnosticRunning($diagnosticId, touchDiagnosticHeartbeat($diagnosticId));
            }
        );
    } finally {
        closeBrowser($session);
    }

    assertDiagnosticRunning($diagnosticId, touchDiagnosticHeartbeat($diagnosticId));
    $scenario['steps'] = storeCheckoutSteps($scenario['steps'], $diagnosticId);
    $home = $context['facts']['availability']['home'] ?? [];
    $homeUrl = (string) (($home['final_url'] ?? '') !== '' ? $home['final_url'] : $context['diagnostic']['url']);
    $result = buildCheckoutResult($scenario, $homeUrl);
    assertDiagnosticRunning($diagnosticId, updateDiagnosticSummary($diagnosticId, ['checkout' => $result['summary']]));

    if ($scenario['requests']['checkout_sent'] > 0 || $scenario['requests']['errors'] > 0) {
        logWarning('Сценарий заказа: изменяющий запрос на оформлении не заблокирован или ошибка перехвата', [
            'diagnostic_id' => $diagnosticId,
            'requests'      => $scenario['requests'],
        ]);
    }

    return [
        'status'   => $result['status'],
        'message'  => $result['message'],
        'facts'    => ['summary' => $result['summary'], 'requests' => $scenario['requests']],
        'findings' => $result['findings'],
    ];
}

/**
 * Страницы шагов сценария, которые открывались в браузере, → `checked_pages`, их снимки → файлы и `screenshots`.
 * В шагах остаются `page_id` и `screenshot_id`, двоичные данные убираются.
 *
 * @param list<array<string, mixed>> $steps
 * @return list<array<string, mixed>>
 */
function storeCheckoutSteps(array $steps, int $diagnosticId): array
{
    foreach ($steps as $index => $step) {
        $step['page_id'] = null;
        if ($step['opened'] && is_string($step['url']) && $step['url'] !== '') {
            $step['page_id'] = insertCheckedPage($diagnosticId, [
                'url'         => $step['url'],
                'page_type'   => $step['page_type'],
                'found_by'    => $step['found_by'] ?? CRAWL_FOUND_BY_LINKS,
                'http_code'   => null,
                'response_ms' => $step['dom_ms'],
                'blocked'     => false,
            ]);
        }

        $stored = storeLoadScreenshot(
            ['page_id' => $step['page_id'], 'width' => CHECKOUT_VIEWPORT_WIDTH, 'screenshot' => $step['screenshot'] ?? null],
            $diagnosticId
        );
        unset($step['screenshot']);
        $step['screenshot_id'] = $stored['screenshot_id'] ?? null;
        if (isset($stored['screenshot_error'])) {
            $step['screenshot_error'] = $stored['screenshot_error'];
        }
        $steps[$index] = $step;
    }

    return $steps;
}

/**
 * Проверенные страницы диагностики для ИИ: без страниц сценария заказа (они описаны в `summary_json.checkout`).
 *
 * @param array<string, mixed>|null $summary разобранный `summary_json`
 * @return list<array<string, mixed>>
 */
function getCheckedPagesForAi(int $diagnosticId, ?array $summary): array
{
    return excludeCheckoutPages(getCheckedPagesByDiagnostic($diagnosticId), $summary['checkout'] ?? null);
}

/**
 * Шаг `pagespeed` с настоящей сетью и БД: главная через PageSpeed Insights (mobile и desktop по очереди, до
 * PAGESPEED_TIMEOUT_SECONDS каждая), heartbeat после каждого запроса. Итог — в `summary_json.pagespeed`, находки
 * `speed` сохраняет `runDiagnostic()`. Ключ — из `.env`, в запросе только заголовком.
 *
 * @param array<string, mixed> $context
 * @return array<string, mixed>
 */
function runPageSpeedStep(array $context): array
{
    $diagnosticId = (int) $context['diagnostic']['id'];
    $result = runSpeedCheck(
        $context,
        env('PAGESPEED_API_KEY', ''),
        withHeartbeat(
            static fn (string $url, array $headers): array => httpApiRequest(
                $url,
                null,
                PAGESPEED_TIMEOUT_SECONDS,
                HTTP_API_MAX_BODY_BYTES,
                $headers
            ),
            $diagnosticId
        ),
        null
    );

    if (isset($result['facts']['pagespeed'])) {
        assertDiagnosticRunning($diagnosticId, updateDiagnosticSummary($diagnosticId, ['pagespeed' => $result['facts']['pagespeed']]));
    }

    return $result;
}

/**
 * Шаг `ai_problem` с настоящей сетью и БД: жалоба и факты **из БД** (не из памяти воркера — так разбор можно
 * перезапустить на сохранённой диагностике) → RouterAI → `ai_results` → гипотезы-находки с `ai_result_id`
 * (сохраняет `runDiagnostic()`). Нет ключа → `skipped`; ошибка API или ответ не по схеме → исключение (`failed`),
 * ответ уже записан в `ai_results`.
 *
 * @param array<string, mixed> $context
 * @return array<string, mixed>
 */
function runAiProblemStep(array $context): array
{
    $key = env('ROUTERAI_API_KEY', '');
    $keyError = validateAiKey($key);
    if ($keyError !== null) {
        return ['status' => STEP_STATUS_SKIPPED, 'message' => $keyError];
    }

    $diagnosticId = (int) $context['diagnostic']['id'];
    $diagnostic = getDiagnosticWithSteps($diagnosticId);
    assertDiagnosticRunning($diagnosticId, $diagnostic !== null && ($diagnostic['status'] ?? null) === 'running');
    $summary = is_string($diagnostic['summary_json'] ?? null) ? json_decode($diagnostic['summary_json'], true) : null;

    $analysis = runProblemAnalysis(
        $context['diagnostic'],
        [
            'findings' => getFindingsByDiagnostic($diagnosticId),
            'stack'    => getStackItemsByDiagnostic($diagnosticId),
            'pages'    => getCheckedPagesForAi($diagnosticId, is_array($summary) ? $summary : null),
            'summary'  => is_array($summary) ? $summary : null,
        ],
        resolveModelSetting(getSetting(AI_PROBLEM_MODEL_SETTING)),
        $key,
        loadProblemPrompt(),
        withHeartbeat(makeAiSender($key), $diagnosticId),
        static fn (array $record): int => insertAiResult($diagnosticId, $record)
    );
    assertDiagnosticRunning($diagnosticId, touchDiagnosticHeartbeat($diagnosticId));

    return [
        'status'   => STEP_STATUS_DONE,
        'message'  => $analysis['message'],
        'facts'    => ['ai_result_id' => $analysis['ai_result_id']],
        'findings' => $analysis['findings'],
    ];
}

/**
 * Шаг `ai_visual` с настоящей сетью и БД: скриншоты и страницы **из БД** → по запросу на страницу в RouterAI →
 * `ai_results` → поломки вёрстки с `ai_result_id` (сохраняет `runDiagnostic()`). Нет ключа или скриншотов →
 * `skipped`; модель без картинок или не проверена ни одна страница → исключение (`failed`).
 *
 * @param array<string, mixed> $context
 * @return array<string, mixed>
 */
function runAiVisualStep(array $context): array
{
    $key = env('ROUTERAI_API_KEY', '');
    $keyError = validateAiKey($key);
    if ($keyError !== null) {
        return ['status' => STEP_STATUS_SKIPPED, 'message' => $keyError];
    }

    $diagnosticId = (int) $context['diagnostic']['id'];
    $diagnostic = getDiagnosticWithSteps($diagnosticId);
    assertDiagnosticRunning($diagnosticId, $diagnostic !== null && ($diagnostic['status'] ?? null) === 'running');
    $summary = is_string($diagnostic['summary_json'] ?? null) ? json_decode($diagnostic['summary_json'], true) : null;
    // Снимки сценария заказа не отправляются: картинки — самая дорогая часть ИИ, страницы уже сняты шагом `browser`
    $selection = selectVisualPages(
        getCheckedPagesForAi($diagnosticId, is_array($summary) ? $summary : null),
        getScreenshotsByDiagnostic($diagnosticId)
    );
    if ($selection['pages'] === []) {
        return ['status' => STEP_STATUS_SKIPPED, 'message' => AI_VISUAL_NO_SCREENSHOTS];
    }

    $model = resolveModelSetting(getSetting(AI_VISUAL_MODEL_SETTING));
    $modelError = findVisualModelError($model, loadRouterAiModels()['models']);
    if ($modelError !== null) {
        throw new RuntimeException($modelError);
    }

    $analysis = runVisualAnalysis(
        $context['diagnostic'],
        $selection,
        $model,
        $key,
        loadVisualPrompt(),
        withHeartbeat(makeAiSender($key), $diagnosticId),
        static fn (array $record): int => insertAiResult($diagnosticId, $record),
        'loadVisualImage',
        static function () use ($diagnosticId): void {
            assertDiagnosticRunning($diagnosticId, touchDiagnosticHeartbeat($diagnosticId));
        }
    );

    return [
        'status'   => STEP_STATUS_DONE,
        'message'  => $analysis['message'],
        'facts'    => ['ai_result_ids' => $analysis['ai_result_ids']],
        'findings' => $analysis['findings'],
    ];
}

/**
 * Шаг `ai_summary` с настоящей сетью и БД: факты, ИИ-находки шагов `ai_problem` / `ai_visual` и статусы шагов
 * **из БД** → RouterAI → итог в `ai_results.parsed_json` (находок шаг не создаёт). Нет ключа → `skipped`; ошибка
 * API или ответ не по схеме → исключение (`failed`), ответ уже записан в `ai_results`.
 *
 * @param array<string, mixed> $context
 * @return array<string, mixed>
 */
function runAiSummaryStep(array $context): array
{
    $key = env('ROUTERAI_API_KEY', '');
    $keyError = validateAiKey($key);
    if ($keyError !== null) {
        return ['status' => STEP_STATUS_SKIPPED, 'message' => $keyError];
    }

    $diagnosticId = (int) $context['diagnostic']['id'];
    $diagnostic = getDiagnosticWithSteps($diagnosticId);
    assertDiagnosticRunning($diagnosticId, $diagnostic !== null && ($diagnostic['status'] ?? null) === 'running');
    $summary = is_string($diagnostic['summary_json'] ?? null) ? json_decode($diagnostic['summary_json'], true) : null;

    $analysis = runSummaryAnalysis(
        $context['diagnostic'],
        [
            'findings' => getFindingsByDiagnostic($diagnosticId),
            'stack'    => getStackItemsByDiagnostic($diagnosticId),
            'pages'    => getCheckedPagesForAi($diagnosticId, is_array($summary) ? $summary : null),
            'summary'  => is_array($summary) ? $summary : null,
            'steps'    => $diagnostic['steps'] ?? [],
        ],
        resolveModelSetting(getSetting(AI_SUMMARY_MODEL_SETTING)),
        $key,
        loadSummaryPrompt(),
        withHeartbeat(makeAiSender($key), $diagnosticId),
        static fn (array $record): int => insertAiResult($diagnosticId, $record)
    );
    assertDiagnosticRunning($diagnosticId, touchDiagnosticHeartbeat($diagnosticId));

    return [
        'status'  => STEP_STATUS_DONE,
        'message' => $analysis['message'],
        'facts'   => ['ai_result_id' => $analysis['ai_result_id']],
    ];
}

/**
 * Шаг `ai_client`: находки и технический итог текущего запуска **из БД** → RouterAI → безопасные тексты в
 * `findings.client_text`, общее резюме — в `ai_results.parsed_json`. Нет ключа → `skipped` и шаблонный отчёт.
 *
 * @param array<string, mixed> $context
 * @return array<string, mixed>
 */
function runAiClientStep(array $context): array
{
    $key = env('ROUTERAI_API_KEY', '');
    $keyError = validateAiKey($key);
    if ($keyError !== null) {
        return ['status' => STEP_STATUS_SKIPPED, 'message' => $keyError];
    }

    $diagnosticId = (int) $context['diagnostic']['id'];
    $diagnostic = getDiagnosticWithSteps($diagnosticId);
    assertDiagnosticRunning($diagnosticId, $diagnostic !== null && ($diagnostic['status'] ?? null) === 'running');
    $aiResults = getAiResultsByDiagnostic($diagnosticId);
    $summaryStep = findClientDiagnosticStep($diagnostic['steps'] ?? [], 'ai_summary');
    $summaryResult = findCurrentClientAiResult(
        $aiResults,
        AI_TASK_SUMMARY,
        $summaryStep['started_at'] ?? null
    );
    $technicalSummary = ($summaryStep['status'] ?? null) === STEP_STATUS_DONE
        && ($summaryResult['status'] ?? null) === AI_RESULT_STATUS_OK
        && is_array($summaryResult['parsed_json'] ?? null)
        && is_string($summaryResult['parsed_json']['verdict'] ?? null)
            ? $summaryResult['parsed_json']['verdict']
            : null;
    $findings = getFindingsByDiagnostic($diagnosticId);
    $analysis = runClientTextAnalysis(
        $diagnosticId,
        $findings,
        $technicalSummary,
        resolveModelSetting(getSetting(AI_CLIENT_MODEL_SETTING)),
        $key,
        loadClientPrompt(),
        withHeartbeat(makeAiSender($key), $diagnosticId),
        static fn (array $record): int => insertAiResult($diagnosticId, $record)
    );
    assertDiagnosticRunning($diagnosticId, touchDiagnosticHeartbeat($diagnosticId));
    replaceFindingClientTexts($diagnosticId, $analysis['texts']);

    return [
        'status'  => STEP_STATUS_DONE,
        'message' => $analysis['message'],
        'facts'   => ['ai_result_id' => $analysis['ai_result_id']],
    ];
}

/**
 * Сохраняет снимок загрузки (файлы + запись `screenshots`) и убирает двоичные данные из результата: в нём остаются
 * `screenshot_id` и `screenshot_truncated`. Ошибка сохранения — `screenshot_error`, шаг продолжается.
 *
 * @param array<string, mixed> $load результат `openBrowserPage()` + `page_id`
 * @return array<string, mixed>
 */
function storeLoadScreenshot(array $load, int $diagnosticId): array
{
    $screenshot = $load['screenshot'] ?? null;
    unset($load['screenshot']);
    if (!is_array($screenshot) || $load['page_id'] === null) {
        return $load;
    }

    $filePath = buildScreenshotFilePath($diagnosticId, (int) $load['page_id'], (int) $load['width']);
    try {
        saveScreenshotFiles($filePath, $screenshot['image'], $screenshot['thumbnail']);
        $load['screenshot_id'] = insertScreenshot($diagnosticId, (int) $load['page_id'], (int) $load['width'], $filePath);
        $load['screenshot_truncated'] = $screenshot['truncated'];
    } catch (Throwable $e) {
        logWarning('Скриншот не сохранён', ['diagnostic_id' => $diagnosticId, 'file' => $filePath,
            'error' => $e->getMessage()]);
        $load['screenshot_error'] = 'не сохранён: ' . $e->getMessage();
    }

    return $load;
}

/**
 * Для `summary_json.blocked`: id страницы → защита (название, причина). Страницы без защиты не попадают.
 *
 * @param array<int, array{provider: string, title: string, reason: string}|null> $botProtections
 */
function buildBlockedPagesSummary(array $botProtections): object
{
    $blocked = [];
    foreach ($botProtections as $pageId => $botProtection) {
        if ($botProtection !== null) {
            $blocked[(string) $pageId] = ['title' => $botProtection['title'], 'reason' => $botProtection['reason']];
        }
    }

    return (object) $blocked;
}

/**
 * Дополняет результат проверки ресурсов PHP-ошибками и mixed content каждой проверенной страницы (заглушки защиты
 * от ботов пропускаются). Одна и та же ошибка на нескольких страницах — одна находка со списком страниц.
 *
 * @param array<string, mixed> $result  результат `runResourceCheck()`
 * @param array<string, mixed> $context контекст шага
 * @return array<string, mixed>
 */
function addPageErrorFindings(array $result, array $context): array
{
    if ($result['status'] !== STEP_STATUS_DONE) {
        return $result;
    }

    $phpErrors = [];
    $mixedContent = [];
    foreach (collectResourcePages($context) as $page) {
        if ($page['bot_protection'] !== null) {
            continue;
        }

        $pageResources = array_values(array_filter(
            $result['facts']['resources'],
            static fn (array $resource): bool => in_array($page['url'], $resource['pages'] ?? [], true)
        ));
        foreach (findPhpErrors($page['body'], $page['url'], $page['page_id']) as $finding) {
            $phpErrors[] = ['finding' => $finding, 'page_url' => $page['url']];
        }
        foreach (findMixedContent($page['url'], $pageResources, $page['page_id']) as $finding) {
            $mixedContent[] = ['finding' => $finding, 'page_url' => $page['url']];
        }
    }

    $phpErrors = mergePageFindings($phpErrors);
    $mixedContent = mergePageFindings($mixedContent);
    $broken = count($result['findings']);
    $pages = $result['facts']['pages'];
    $blocked = count(array_filter($pages, static fn (array $page): bool => !$page['checked']));

    $result['findings'] = [...$result['findings'], ...$phpErrors, ...$mixedContent];
    $result['message'] = buildResourcesStepMessage(
        count($pages) - $blocked,
        $blocked,
        count($result['facts']['resources']),
        $broken,
        count($phpErrors),
        count($mixedContent),
        (int) ($result['facts']['skipped_by_limit'] ?? 0)
    );

    return $result;
}

/**
 * Одинаковые находки с разных страниц — одна: первая (её `page_id` и адрес), все страницы — в `evidence.pages`.
 *
 * @param list<array{finding: array, page_url: string}> $items в порядке страниц
 * @return list<array>
 */
function mergePageFindings(array $items): array
{
    $merged = [];
    foreach ($items as $item) {
        $key = buildPageFindingMergeKey($item['finding']);
        if (!isset($merged[$key])) {
            $merged[$key] = $item['finding'];
            $merged[$key]['evidence'] = ($merged[$key]['evidence'] ?? []) + ['pages' => []];
        }
        if (!in_array($item['page_url'], $merged[$key]['evidence']['pages'], true)) {
            $merged[$key]['evidence']['pages'][] = $item['page_url'];
        }
    }

    return array_values($merged);
}

/**
 * PHP-ошибка — по типу, файлу и строке (адрес страницы входит в её fingerprint); остальное — по fingerprint.
 */
function buildPageFindingMergeKey(array $finding): string
{
    if ($finding['category'] === 'php_error') {
        $evidence = $finding['evidence'] ?? [];

        return implode('|', [
            'php_error',
            strtolower((string) ($evidence['type'] ?? '')),
            (string) ($evidence['file'] ?? ''),
            (string) ($evidence['line'] ?? ''),
        ]);
    }

    return (string) $finding['fingerprint'];
}

/**
 * Начальный контекст шагов: сама диагностика и пустые факты.
 *
 * @param array<string, mixed> $diagnostic строка `diagnostics`
 * @return array{diagnostic: array{id: int, url: string, host: string, product_url: ?string, problem_text: ?string},
 *               facts: array}
 */
function buildStepContext(array $diagnostic): array
{
    return [
        'diagnostic' => [
            'id'           => (int) $diagnostic['id'],
            'url'          => (string) $diagnostic['url'],
            'host'         => (string) $diagnostic['host'],
            'product_url'  => isset($diagnostic['product_url']) && $diagnostic['product_url'] !== ''
                ? (string) $diagnostic['product_url']
                : null,
            'problem_text' => isset($diagnostic['problem_text']) ? (string) $diagnostic['problem_text'] : null,
        ],
        'facts' => [],
    ];
}

/**
 * Процент выполнения 0–100; 100 — только когда закончены все шаги.
 */
function calculateProgress(int $finished, int $total): int
{
    if ($total <= 0) {
        return PROGRESS_MAX;
    }

    $finished = max(0, min($finished, $total));

    return intdiv($finished * PROGRESS_MAX, $total);
}

function truncateStepMessage(string $message): string
{
    return mb_substr($message, 0, STEP_MESSAGE_MAX_LENGTH);
}

/**
 * Прерывает выполнение воркера, если диагностика больше не в статусе `running`.
 */
function assertDiagnosticRunning(int $diagnosticId, bool $stillRunning): void
{
    if (!$stillRunning) {
        throw new DiagnosticNoLongerRunningException($diagnosticId);
    }
}

/**
 * Оборачивает сетевой вызов обновлением heartbeat по времени (не чаще раза в `$intervalSeconds`).
 * Проверяет статус до и после вызова: если `touch` вернул `false`, бросает `DiagnosticNoLongerRunningException`
 * и при повторных обращениях сразу останавливается без лишних запросов к БД и сети.
 *
 * @param callable(int $diagnosticId): bool|null $touch
 * @param callable(): int|null                   $clock
 */
function withHeartbeat(
    callable $request,
    int $diagnosticId,
    ?callable $touch = null,
    ?callable $clock = null,
    int $intervalSeconds = HEARTBEAT_MIN_INTERVAL_SECONDS
): callable {
    $touch ??= static fn (int $id): bool => touchDiagnosticHeartbeat($id);
    $clock ??= static fn (): int => time();
    $last = null;
    $aborted = false;

    $ping = static function () use ($diagnosticId, $touch, $clock, $intervalSeconds, &$last, &$aborted): void {
        if ($aborted) {
            throw new DiagnosticNoLongerRunningException($diagnosticId);
        }

        $now = (int) $clock();
        if ($last !== null && ($now - $last) < $intervalSeconds) {
            return;
        }

        if (!$touch($diagnosticId)) {
            $aborted = true;
            throw new DiagnosticNoLongerRunningException($diagnosticId);
        }

        $last = $now;
    };

    return static function (mixed ...$args) use ($request, $ping): mixed {
        $ping();
        $result = $request(...$args);
        $ping();

        return $result;
    };
}

/**
 * @param array<string, mixed>|null $error результат `error_get_last()`
 */
function isFatalWorkerError(?array $error): bool
{
    return $error !== null && in_array($error['type'] ?? null, WORKER_FATAL_ERROR_TYPES, true);
}

/**
 * @param array<string, mixed> $error результат `error_get_last()`
 * @return array{diagnostic: string, step: string}
 */
function buildWorkerFatalMessages(array $error): array
{
    $isOom = stripos((string) ($error['message'] ?? ''), WORKER_FATAL_MEMORY_MARKER) !== false;

    return $isOom
        ? ['diagnostic' => WORKER_FATAL_OOM_DIAGNOSTIC_MESSAGE, 'step' => WORKER_FATAL_OOM_STEP_MESSAGE]
        : ['diagnostic' => WORKER_FATAL_DIAGNOSTIC_MESSAGE, 'step' => WORKER_FATAL_STEP_MESSAGE];
}

/**
 * Обработчик фатального завершения воркера: логирует ошибку и переводит активную диагностику и её
 * текущий шаг в `failed`. Не выбрасывает исключений (безопасен для `register_shutdown_function`).
 *
 * @param array<string, mixed>                                                        $error
 * @param callable(int $diagnosticId, string $diagnosticMsg, string $stepMsg): bool|null $failDiagnostic
 * @param callable(string $message, array<string, mixed> $context): void|null         $log
 */
function handleWorkerFatalError(
    int $diagnosticId,
    array $error,
    ?callable $failDiagnostic = null,
    ?callable $log = null,
    ?PDO $pdo = null
): bool {
    $log ??= static function (string $message, array $context): void {
        logError($message, $context);
    };

    try {
        $log('Фатальная ошибка воркера', [
            'diagnostic_id' => $diagnosticId,
            'type'          => $error['type'] ?? null,
            'message'       => (string) ($error['message'] ?? ''),
            'file'          => (string) ($error['file'] ?? ''),
            'line'          => $error['line'] ?? null,
        ]);
    } catch (Throwable) {
    }

    $messages = buildWorkerFatalMessages($error);

    try {
        if ($failDiagnostic !== null) {
            return (bool) $failDiagnostic($diagnosticId, $messages['diagnostic'], $messages['step']);
        }

        $db = $pdo ?? getPdo();
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        return failRunningDiagnostic($diagnosticId, $messages['diagnostic'], $messages['step'], $db);
    } catch (Throwable $e) {
        try {
            $log('Не удалось отметить фатальное завершение воркера в БД', [
                'diagnostic_id' => $diagnosticId,
                'error'         => $e->getMessage(),
            ]);
        } catch (Throwable) {
        }

        return false;
    }
}

/**
 * Приводит ответ обработчика к одному виду.
 *
 * @return array{status: string, message: ?string, facts: array, findings: list<array>, stop: bool}
 */
function normalizeStepResult(mixed $result): array
{
    $result = is_array($result) ? $result : [];

    return [
        'status'   => ($result['status'] ?? null) === STEP_STATUS_SKIPPED ? STEP_STATUS_SKIPPED : STEP_STATUS_DONE,
        'message'  => isset($result['message']) ? truncateStepMessage((string) $result['message']) : null,
        'facts'    => isset($result['facts']) && is_array($result['facts']) ? $result['facts'] : [],
        'findings' => isset($result['findings']) && is_array($result['findings'])
            ? array_values(array_filter($result['findings'], 'is_array'))
            : [],
        'stop'     => ($result['stop'] ?? false) === true,
    ];
}

/**
 * Выполняет шаги по порядку, без БД: о каждом шаге сообщает через колбэки.
 * Исключение в шаге → `failed` с сообщением, его факты и находки не принимаются, следующие шаги выполняются.
 * `DiagnosticNoLongerRunningException` пробрасывается сразу наружу и останавливает цикл.
 * `stop` → оставшиеся шаги `skipped` с причиной (`onStepStart` для них не вызывается).
 *
 * @param list<array{code: string, title: string, handler: callable}> $steps
 * @param array<string, mixed> $context начальный контекст (`buildStepContext()`)
 * @param callable(array $step): void $onStepStart
 * @param callable(array $step, string $status, ?string $message, int $progress, ?Throwable $error, list<array> $findings): void $onStepFinish
 * @return array<string, mixed> контекст после всех шагов
 */
function executeSteps(array $steps, array $context, callable $onStepStart, callable $onStepFinish): array
{
    $total = count($steps);
    $finished = 0;
    $stoppedBy = null;

    foreach ($steps as $step) {
        if ($stoppedBy !== null) {
            $finished++;
            $message = truncateStepMessage(sprintf(STEP_STOPPED_MESSAGE, $stoppedBy['title']));
            $onStepFinish($step, STEP_STATUS_SKIPPED, $message, calculateProgress($finished, $total), null, []);
            continue;
        }

        $onStepStart($step);

        $error = null;
        $findings = [];
        try {
            $result   = normalizeStepResult(($step['handler'])($context));
            $status   = $result['status'];
            $message  = $result['message'];
            $findings = $result['findings'];
            $context['facts'][$step['code']] = $result['facts'];

            if ($result['stop']) {
                $stoppedBy = $step;
            }
        } catch (DiagnosticNoLongerRunningException $e) {
            throw $e;
        } catch (Throwable $e) {
            $error   = $e;
            $status  = STEP_STATUS_FAILED;
            $message = truncateStepMessage($e->getMessage() !== '' ? $e->getMessage() : get_class($e));
        }

        $finished++;
        $onStepFinish($step, $status, $message, calculateProgress($finished, $total), $error, $findings);
    }

    return $context;
}

/**
 * Полный прогон диагностики воркером. Ничего не делает, если диагностика не в `queued`.
 *
 * @param callable(): void|null $onClaimed вызывается сразу после перевода `queued → running`
 * @return bool true — диагностика выполнена этим вызовом
 */
function runDiagnostic(int $id, ?callable $onClaimed = null): bool
{
    if (!claimDiagnostic($id)) {
        return false;
    }
    if ($onClaimed !== null) {
        $onClaimed();
    }

    $diagnostic = getDiagnosticWithSteps($id);
    if ($diagnostic === null) {
        throw new DiagnosticNoLongerRunningException($id);
    }

    $steps = getDiagnosticSteps();
    createDiagnosticSteps($id, $steps);

    executeStepsWithDb($id, $steps, $diagnostic);
    assertDiagnosticRunning($id, finishDiagnostic($id, 'done', null));

    return true;
}

/**
 * Перезапуск ИИ-анализа воркером: только ИИ-шаги по фактам из БД, без запросов к проверяемому сайту.
 * Ничего не делает, если диагностика не в `queued` (её ставит в очередь `queueAiRerun()`).
 *
 * @param callable(): void|null $onClaimed вызывается сразу после перевода `queued → running`
 * @return bool true — перезапуск выполнен этим вызовом
 */
function runAiRerun(int $id, ?callable $onClaimed = null): bool
{
    if (!claimAiRerun($id)) {
        return false;
    }
    if ($onClaimed !== null) {
        $onClaimed();
    }

    $steps = getAiSteps();
    assertDiagnosticRunning($id, resetAiSteps($id, $steps));

    $diagnostic = getDiagnosticWithSteps($id);
    if ($diagnostic === null) {
        throw new DiagnosticNoLongerRunningException($id);
    }

    executeStepsWithDb($id, $steps, $diagnostic);
    assertDiagnosticRunning($id, finishDiagnostic($id, 'done', null));

    return true;
}

/**
 * Сохраняет находки шага и гарантированно фиксирует его статус и прогресс.
 * Сбой при записи находки логируется и переводит шаг в `failed`, не прерывая сохранение остальных находок
 * и вызов `$finishStep` / `$updateProgress` (шаг не остаётся в `running`, цикл шагов продолжается).
 * `DiagnosticNoLongerRunningException` пробрасывается наружу без обновления шага и прогресса.
 *
 * @param array{code: string, title: string}                                 $step
 * @param list<array<string, mixed>>                                         $findings
 * @param callable(array $finding): mixed                                    $saveFinding
 * @param callable(string $stepCode, string $status, ?string $message): void $finishStep
 * @param callable(int $progress): void                                      $updateProgress
 * @param callable(Throwable $e, array $context): void                       $logError
 */
function finalizeStepPersistence(
    int $diagnosticId,
    array $step,
    string $status,
    ?string $message,
    int $progress,
    ?Throwable $error,
    array $findings,
    callable $saveFinding,
    callable $finishStep,
    callable $updateProgress,
    callable $logError
): void {
    if ($error !== null) {
        $logError($error, ['diagnostic_id' => $diagnosticId, 'step_code' => $step['code']]);
    }

    foreach ($findings as $finding) {
        try {
            $saveFinding($finding);
        } catch (DiagnosticNoLongerRunningException $e) {
            throw $e;
        } catch (Throwable $e) {
            $logError($e, ['diagnostic_id' => $diagnosticId, 'step_code' => $step['code']]);
            $status = STEP_STATUS_FAILED;
            $detail = $e->getMessage() !== '' ? $e->getMessage() : get_class($e);
            $message = truncateStepMessage(STEP_SAVE_FINDINGS_ERROR_PREFIX . $detail);
        }
    }

    $finishStep($step['code'], $status, $message);
    $updateProgress($progress);
}

/**
 * Выполняет шаги с записью в БД: статус и сообщение шага, находки, прогресс и heartbeat.
 *
 * @param list<array{code: string, title: string, handler: callable}> $steps
 * @param array<string, mixed> $diagnostic строка `diagnostics`
 */
function executeStepsWithDb(int $id, array $steps, array $diagnostic): void
{
    executeSteps(
        $steps,
        buildStepContext($diagnostic),
        static function (array $step) use ($id): void {
            assertDiagnosticRunning($id, markStepRunning($id, $step['code']));
            assertDiagnosticRunning($id, touchDiagnosticHeartbeat($id));
        },
        static function (
            array $step,
            string $status,
            ?string $message,
            int $progress,
            ?Throwable $error,
            array $findings
        ) use ($id): void {
            assertDiagnosticRunning($id, touchDiagnosticHeartbeat($id));
            finalizeStepPersistence(
                $id,
                $step,
                $status,
                $message,
                $progress,
                $error,
                $findings,
                static fn (array $finding): int => insertFinding($id, $finding),
                static function (string $stepCode, string $stepStatus, ?string $stepMessage) use ($id): void {
                    assertDiagnosticRunning($id, finishStep($id, $stepCode, $stepStatus, $stepMessage));
                },
                static function (int $stepProgress) use ($id): void {
                    assertDiagnosticRunning($id, updateDiagnosticProgress($id, $stepProgress));
                },
                static function (Throwable $e, array $context): void {
                    logException($e, $context);
                }
            );
        }
    );
}

/**
 * Для экрана диагностики (таск 6): зависшая без heartbeat диагностика помечается прерванной.
 */
function refreshStaleDiagnostic(int $id): void
{
    failStaleDiagnostic($id, HEARTBEAT_STALE_SECONDS);
}
