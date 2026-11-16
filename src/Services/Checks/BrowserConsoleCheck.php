<?php

declare(strict_types=1);

/**
 * Ошибки JS и упавшие запросы из событий DevTools, без браузера и сети: разбор событий одной загрузки
 * (`applyBrowserEvent()`), отсев ложных срабатываний (правила — `data/browser-ignore.php`), склейка повторов со
 * всех страниц и ширин, сборка находок `js_error` / `failed_request`.
 *
 * Повторная проверка упавших ресурсов — через переданный `$recheck` (в воркере — `httpRequest()`).
 */

require_once ROOT_PATH . '/src/Services/FindingFactory.php';
require_once ROOT_PATH . '/src/Services/BrowserService.php';

const BROWSER_IGNORE_FILE = ROOT_PATH . '/data/browser-ignore.php';

const BROWSER_EVENT_EXCEPTION = 'exception'; // Runtime.exceptionThrown
const BROWSER_EVENT_CONSOLE   = 'console';   // Runtime.consoleAPICalled (console.error)
const BROWSER_EVENT_LOG       = 'log';       // Log.entryAdded
const BROWSER_EVENT_RESPONSE  = 'response';  // Network.responseReceived с кодом ≥ 400
const BROWSER_EVENT_FAILED    = 'failed';    // Network.loadingFailed

const BROWSER_MAX_EVENTS_PER_LOAD = 200;  // больше — бесконечный цикл ошибок; остальное только считается
const BROWSER_STACK_MAX_FRAMES    = 5;
const BROWSER_MESSAGE_MAX_LENGTH  = 1000; // текст ошибки в evidence
const BROWSER_TITLE_MESSAGE_MAX   = 180;  // текст ошибки в заголовке находки
const BROWSER_FAILED_STATUS_MIN   = 400;
const BROWSER_NETWORK_ERROR_MIN_LOADS = 2; // сетевая ошибка без ответа — находка, только если повторилась

const BROWSER_CONSOLE_ERROR_TYPE = 'error';
const BROWSER_LOG_ERROR_LEVEL    = 'error';
const BROWSER_DOCUMENT_TYPE      = 'Document';
const BROWSER_FAVICON_PATH       = '/favicon.ico';
const BROWSER_SCRIPT_ERROR_TEXT  = 'Script error';
const BROWSER_API_TYPES          = ['XHR', 'Fetch'];
const BROWSER_RECHECK_TYPES      = ['Script', 'Stylesheet', 'Image', 'Font', 'Media'];
const BROWSER_RECHECK_METHOD     = 'GET';

const BROWSER_SEVERITY_OWN     = 'medium'; // исключение / упавший запрос своего домена
const BROWSER_SEVERITY_FOREIGN = 'low';    // чужой домен
const BROWSER_SEVERITY_CONSOLE = 'low';    // console.error и ошибки из Log

// Причины, по которым событие не стало находкой (счётчики — в фактах шага)
const BROWSER_REJECT_NOT_OPENED     = 'page_not_opened';
const BROWSER_REJECT_IGNORED_HOST   = 'ignored_host';
const BROWSER_REJECT_NOT_HTTP       = 'not_http';
const BROWSER_REJECT_SCRIPT_ERROR   = 'script_error';
const BROWSER_REJECT_CONSOLE_TEXT   = 'ignored_message';
const BROWSER_REJECT_RECOVERABLE    = 'recoverable_error';
const BROWSER_REJECT_LOG_SOURCE     = 'log_source';
const BROWSER_REJECT_DOCUMENT       = 'page_document';
const BROWSER_REJECT_BROWSER_ERROR  = 'browser_error';
const BROWSER_REJECT_BLOCKED        = 'blocked_reason';
const BROWSER_REJECT_SERVICE_TYPE   = 'service_request';
const BROWSER_REJECT_API_AUTH       = 'api_auth';
const BROWSER_REJECT_STATUS         = 'ignored_status';
const BROWSER_REJECT_BROKEN_RESOURCE = 'broken_resource';
const BROWSER_REJECT_SINGLE_NETWORK = 'single_network_error';
const BROWSER_REJECT_RECHECK_OK     = 'recheck_ok';

/**
 * Правила из `data/browser-ignore.php`.
 *
 * @return array<string, list<mixed>>
 */
function loadBrowserIgnoreRules(): array
{
    $rules = require BROWSER_IGNORE_FILE;

    return is_array($rules) ? $rules : [];
}

/**
 * Пустое состояние разбора одной загрузки.
 *
 * @return array{main_frame_id: ?string, requests: array<string, array>, reported: array<string, true>,
 *               events: list<array<string, mixed>>, dropped: int}
 */
function createBrowserEventState(?string $mainFrameId): array
{
    return ['main_frame_id' => $mainFrameId, 'requests' => [], 'reported' => [], 'events' => [], 'dropped' => 0];
}

/**
 * Одно событие DevTools → состояние загрузки. Запросы запоминаются по `requestId` (у `loadingFailed` нет адреса);
 * ошибки сводятся к одному виду. Больше BROWSER_MAX_EVENTS_PER_LOAD ошибок — только счётчик `dropped`.
 *
 * @param array<string, mixed> $state  из createBrowserEventState()
 * @param array<string, mixed> $params параметры события
 * @return array<string, mixed>
 */
function applyBrowserEvent(array $state, string $method, array $params): array
{
    if ($method === 'Network.requestWillBeSent') {
        $request = is_array($params['request'] ?? null) ? $params['request'] : [];
        $state['requests'][(string) ($params['requestId'] ?? '')] = [
            'url'      => (string) ($request['url'] ?? ''),
            'method'   => strtoupper((string) ($request['method'] ?? 'GET')),
            'type'     => (string) ($params['type'] ?? ''),
            'frame_id' => isset($params['frameId']) ? (string) $params['frameId'] : null,
        ];

        return $state;
    }

    $event = match ($method) {
        'Runtime.exceptionThrown'  => parseExceptionEvent($params),
        'Runtime.consoleAPICalled' => parseConsoleEvent($params),
        'Log.entryAdded'           => parseLogEvent($params),
        'Network.responseReceived' => parseResponseEvent($state, $params),
        'Network.loadingFailed'    => parseLoadingFailedEvent($state, $params),
        default                    => null,
    };
    if ($event === null) {
        return $state;
    }

    if (isset($event['request_id'])) {
        // Ответ 404 уже записан — `loadingFailed` по тому же запросу второй ошибкой не считается
        if (isset($state['reported'][$event['request_id']])) {
            return $state;
        }
        $state['reported'][$event['request_id']] = true;
        unset($event['request_id']);
    }

    if (count($state['events']) >= BROWSER_MAX_EVENTS_PER_LOAD) {
        $state['dropped']++;

        return $state;
    }
    $state['events'][] = $event;

    return $state;
}

/**
 * `Runtime.exceptionThrown`: «Uncaught TypeError: …», файл, строка и столбец (с 1), кадры стека.
 *
 * @return array<string, mixed>
 */
function parseExceptionEvent(array $params): array
{
    $details = is_array($params['exceptionDetails'] ?? null) ? $params['exceptionDetails'] : [];
    $exception = is_array($details['exception'] ?? null) ? $details['exception'] : [];
    $text = trim((string) ($details['text'] ?? ''));

    $description = isset($exception['description']) ? (string) $exception['description']
        : (isset($exception['value']) && is_scalar($exception['value']) ? (string) $exception['value'] : '');
    $firstLine = trim(explode("\n", $description, 2)[0]);
    if ($firstLine !== '' && !str_contains($text, $firstLine)) {
        $text = $text === '' ? $firstLine : $text . ' ' . $firstLine;
    }

    return makeBrowserScriptEvent(
        BROWSER_EVENT_EXCEPTION,
        $text,
        isset($details['url']) ? (string) $details['url'] : '',
        isset($details['lineNumber']) ? (int) $details['lineNumber'] : null,
        isset($details['columnNumber']) ? (int) $details['columnNumber'] : null,
        $details['stackTrace'] ?? null
    );
}

/**
 * `Runtime.consoleAPICalled` с `type = error` (console.error из кода сайта); остальные типы — null.
 *
 * @return array<string, mixed>|null
 */
function parseConsoleEvent(array $params): ?array
{
    if (($params['type'] ?? '') !== BROWSER_CONSOLE_ERROR_TYPE) {
        return null;
    }

    $parts = [];
    foreach (is_array($params['args'] ?? null) ? $params['args'] : [] as $arg) {
        if (!is_array($arg)) {
            continue;
        }
        $value = $arg['value'] ?? null;
        $parts[] = is_scalar($value) ? (string) $value : (string) ($arg['description'] ?? $arg['type'] ?? '');
    }

    $stack = is_array($params['stackTrace'] ?? null) ? $params['stackTrace'] : null;
    $top = is_array($stack['callFrames'][0] ?? null) ? $stack['callFrames'][0] : [];

    return makeBrowserScriptEvent(
        BROWSER_EVENT_CONSOLE,
        trim(implode(' ', $parts)),
        (string) ($top['url'] ?? ''),
        isset($top['lineNumber']) ? (int) $top['lineNumber'] : null,
        isset($top['columnNumber']) ? (int) $top['columnNumber'] : null,
        $stack
    );
}

/**
 * `Log.entryAdded` уровня `error`; источник сохраняется — по нему отсев.
 *
 * @return array<string, mixed>|null
 */
function parseLogEvent(array $params): ?array
{
    $entry = is_array($params['entry'] ?? null) ? $params['entry'] : [];
    if (($entry['level'] ?? '') !== BROWSER_LOG_ERROR_LEVEL) {
        return null;
    }

    $event = makeBrowserScriptEvent(
        BROWSER_EVENT_LOG,
        trim((string) ($entry['text'] ?? '')),
        (string) ($entry['url'] ?? ''),
        isset($entry['lineNumber']) ? (int) $entry['lineNumber'] : null,
        null,
        $entry['stackTrace'] ?? null
    );
    $event['source'] = (string) ($entry['source'] ?? '');

    return $event;
}

/**
 * Ответ с кодом ≥ 400; меньше — null.
 *
 * @param array<string, mixed> $state
 * @return array<string, mixed>|null
 */
function parseResponseEvent(array $state, array $params): ?array
{
    $response = is_array($params['response'] ?? null) ? $params['response'] : [];
    $status = isset($response['status']) ? (int) $response['status'] : 0;
    if ($status < BROWSER_FAILED_STATUS_MIN) {
        return null;
    }

    $event = makeBrowserRequestEvent($state, $params, (string) ($response['url'] ?? ''));
    $event['kind'] = BROWSER_EVENT_RESPONSE;
    $event['status'] = $status;

    return $event;
}

/**
 * `Network.loadingFailed`: сетевая ошибка, отмена, блокировка.
 *
 * @param array<string, mixed> $state
 * @return array<string, mixed>
 */
function parseLoadingFailedEvent(array $state, array $params): array
{
    $event = makeBrowserRequestEvent($state, $params, '');
    $event['kind'] = BROWSER_EVENT_FAILED;
    $event['error_text'] = (string) ($params['errorText'] ?? '');
    $event['canceled'] = !empty($params['canceled']);
    $event['blocked_reason'] = isset($params['blockedReason']) ? (string) $params['blockedReason'] : null;

    return $event;
}

/**
 * Общая часть событий запроса: адрес, метод, тип — из `requestWillBeSent`, документ основного фрейма.
 *
 * @param array<string, mixed> $state
 * @return array<string, mixed>
 */
function makeBrowserRequestEvent(array $state, array $params, string $url): array
{
    $requestId = (string) ($params['requestId'] ?? '');
    $request = $state['requests'][$requestId] ?? [];
    $type = (string) ($params['type'] ?? ($request['type'] ?? ''));
    $frameId = isset($params['frameId']) ? (string) $params['frameId'] : ($request['frame_id'] ?? null);

    return [
        'request_id'    => $requestId,
        'url'           => $url !== '' ? $url : (string) ($request['url'] ?? ''),
        'method'        => (string) ($request['method'] ?? 'GET'),
        'type'          => $type,
        'main_document' => $type === BROWSER_DOCUMENT_TYPE && $frameId !== null && $frameId === $state['main_frame_id'],
    ];
}

/**
 * Ошибка скрипта: строка и столбец DevTools (с 0) → с 1; стек — до BROWSER_STACK_MAX_FRAMES кадров.
 *
 * @return array{kind: string, message: string, url: string, line: ?int, column: ?int, stack: list<string>}
 */
function makeBrowserScriptEvent(string $kind, string $message, string $url, ?int $line, ?int $column, mixed $stackTrace): array
{
    $frames = is_array($stackTrace) && is_array($stackTrace['callFrames'] ?? null) ? $stackTrace['callFrames'] : [];
    $top = is_array($frames[0] ?? null) ? $frames[0] : [];
    if ($url === '' && isset($top['url'])) {
        $url = (string) $top['url'];
        $line ??= isset($top['lineNumber']) ? (int) $top['lineNumber'] : null;
        $column ??= isset($top['columnNumber']) ? (int) $top['columnNumber'] : null;
    }

    $stack = [];
    foreach (array_slice($frames, 0, BROWSER_STACK_MAX_FRAMES) as $frame) {
        if (!is_array($frame)) {
            continue;
        }
        $name = (string) ($frame['functionName'] ?? '');
        $stack[] = ($name !== '' ? $name : '(anonymous)') . ' ' . (string) ($frame['url'] ?? '')
            . ':' . ((int) ($frame['lineNumber'] ?? 0) + 1) . ':' . ((int) ($frame['columnNumber'] ?? 0) + 1);
    }

    return [
        'kind'    => $kind,
        'message' => mb_substr($message, 0, BROWSER_MESSAGE_MAX_LENGTH),
        'url'     => $url,
        'line'    => $line !== null ? $line + 1 : null,
        'column'  => $column !== null ? $column + 1 : null,
        'stack'   => $stack,
        'frame_urls' => array_values(array_filter(
            array_map(static fn (mixed $frame): string => is_array($frame) ? (string) ($frame['url'] ?? '') : '', $frames),
            static fn (string $frameUrl): bool => $frameUrl !== ''
        )),
    ];
}

/**
 * Хост — сам сайт или его поддомен (без `www.`).
 */
function isOwnBrowserHost(string $host, string $siteHost): bool
{
    $host = preg_replace('~^www\.~', '', strtolower($host)) ?? '';
    $siteHost = preg_replace('~^www\.~', '', strtolower($siteHost)) ?? '';

    return $host !== '' && $siteHost !== '' && ($host === $siteHost || str_ends_with($host, '.' . $siteHost));
}

/**
 * Адрес относится к счётчику, рекламе или виджету из правил (`hosts` — хост или его поддомен, `url_substrings`).
 *
 * @param array<string, list<mixed>> $rules
 */
function isIgnoredBrowserUrl(string $url, array $rules): bool
{
    if ($url === '') {
        return false;
    }

    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    foreach ($rules['hosts'] ?? [] as $ignored) {
        $ignored = strtolower((string) $ignored);
        if ($host !== '' && ($host === $ignored || str_ends_with($host, '.' . $ignored))) {
            return true;
        }
    }
    foreach ($rules['url_substrings'] ?? [] as $substring) {
        if (stripos($url, (string) $substring) !== false) {
            return true;
        }
    }

    return false;
}

function isHttpBrowserUrl(string $url): bool
{
    return preg_match('~^https?://~i', $url) === 1;
}

/**
 * Причина, по которой событие не поломка сайта, или null — событие идёт в находки.
 *
 * @param array<string, mixed>      $event          из applyBrowserEvent()
 * @param array<string, list<mixed>> $rules
 * @param array<string, true>        $brokenResources нормализованные адреса битых ресурсов шага «Ресурсы»
 */
function findBrowserEventRejection(array $event, array $rules, array $brokenResources): ?string
{
    return in_array($event['kind'], [BROWSER_EVENT_RESPONSE, BROWSER_EVENT_FAILED], true)
        ? findRequestEventRejection($event, $rules, $brokenResources)
        : findScriptEventRejection($event, $rules);
}

/**
 * @param array<string, mixed>       $event
 * @param array<string, list<mixed>> $rules
 */
function findScriptEventRejection(array $event, array $rules): ?string
{
    if ($event['kind'] === BROWSER_EVENT_LOG && in_array($event['source'] ?? '', $rules['log_sources'] ?? [], true)) {
        return BROWSER_REJECT_LOG_SOURCE;
    }
    // Скрипт с чужого домена без CORS: браузер скрывает текст, файл и строку
    if ($event['url'] === '' && str_contains($event['message'], BROWSER_SCRIPT_ERROR_TEXT)) {
        return BROWSER_REJECT_SCRIPT_ERROR;
    }
    if ($event['url'] !== '' && !isHttpBrowserUrl($event['url'])) {
        return BROWSER_REJECT_NOT_HTTP;
    }
    foreach ([$event['url'], $event['frame_urls'][0] ?? ''] as $url) {
        if (isIgnoredBrowserUrl((string) $url, $rules)) {
            return BROWSER_REJECT_IGNORED_HOST;
        }
    }
    foreach ($rules['exception_messages'] ?? [] as $substring) {
        if (stripos($event['message'], (string) $substring) !== false) {
            return BROWSER_REJECT_RECOVERABLE;
        }
    }
    if ($event['kind'] !== BROWSER_EVENT_EXCEPTION) {
        foreach ($rules['console_messages'] ?? [] as $substring) {
            if (stripos($event['message'], (string) $substring) !== false) {
                return BROWSER_REJECT_CONSOLE_TEXT;
            }
        }
    }

    return null;
}

/**
 * @param array<string, mixed>       $event
 * @param array<string, list<mixed>> $rules
 * @param array<string, true>        $brokenResources
 */
function findRequestEventRejection(array $event, array $rules, array $brokenResources): ?string
{
    $url = (string) $event['url'];
    $path = (string) parse_url($url, PHP_URL_PATH);

    return match (true) {
        !isHttpBrowserUrl($url)                                          => BROWSER_REJECT_NOT_HTTP,
        $event['main_document']                                          => BROWSER_REJECT_DOCUMENT,
        isIgnoredBrowserUrl($url, $rules)                                => BROWSER_REJECT_IGNORED_HOST,
        in_array($event['type'], $rules['resource_types'] ?? [], true),
        $path === BROWSER_FAVICON_PATH                                   => BROWSER_REJECT_SERVICE_TYPE,
        $event['kind'] === BROWSER_EVENT_FAILED && (
            $event['canceled'] || in_array($event['error_text'], $rules['network_errors'] ?? [], true)
        )                                                                => BROWSER_REJECT_BROWSER_ERROR,
        $event['kind'] === BROWSER_EVENT_FAILED && $event['blocked_reason'] !== null
            && in_array($event['blocked_reason'], $rules['blocked_reasons'] ?? [], true) => BROWSER_REJECT_BLOCKED,
        $event['kind'] === BROWSER_EVENT_RESPONSE
            && in_array($event['status'], $rules['statuses'] ?? [], true)    => BROWSER_REJECT_STATUS,
        $event['kind'] === BROWSER_EVENT_RESPONSE && in_array($event['type'], BROWSER_API_TYPES, true)
            && in_array($event['status'], $rules['api_auth_statuses'] ?? [], true) => BROWSER_REJECT_API_AUTH,
        isset($brokenResources[normalizeFindingLocation($url)])          => BROWSER_REJECT_BROKEN_RESOURCE,
        default                                                          => null,
    };
}

/**
 * Адреса битых ресурсов шага «Ресурсы» (код ≥ 400 или нет ответа): упавший запрос по ним — дубль `broken_resource`.
 *
 * @param mixed $resources `facts.resources.resources`
 * @return array<string, true> нормализованный адрес → true
 */
function collectBrokenResourceUrls(mixed $resources): array
{
    $broken = [];
    foreach (is_array($resources) ? $resources : [] as $resource) {
        if (!is_array($resource) || !isset($resource['url'])) {
            continue;
        }
        $status = $resource['status'] ?? null;
        if ($status === null || (int) $status >= BROWSER_FAILED_STATUS_MIN) {
            $broken[normalizeFindingLocation((string) $resource['url'])] = true;
        }
    }

    return $broken;
}

/**
 * Ключ склейки: одинаковая ошибка со всех страниц и ширин — одна находка.
 *
 * @param array<string, mixed> $event
 */
function buildBrowserEventKey(array $event): string
{
    if (in_array($event['kind'], [BROWSER_EVENT_RESPONSE, BROWSER_EVENT_FAILED], true)) {
        // Без строки запроса: один адрес с меняющимся `session_id` / `?v=` — одна находка
        return implode("\n", [
            'request',
            explode('?', normalizeFindingLocation((string) $event['url']), 2)[0],
            $event['kind'] === BROWSER_EVENT_RESPONSE ? (string) $event['status'] : (string) $event['error_text'],
        ]);
    }

    return implode("\n", [
        $event['kind'] === BROWSER_EVENT_EXCEPTION ? 'exception' : 'console',
        $event['message'],
        normalizeFindingLocation((string) $event['url']),
        (string) ($event['line'] ?? ''),
    ]);
}

/**
 * Склейка принятых событий всех загрузок. Загрузка, которая не открылась, ошибок не даёт (страница недогружена).
 *
 * @param list<array{url: string, width: int, status: string, page_id: ?int, events: list<array>}> $loads
 * @param array<string, list<mixed>> $rules
 * @param array<string, true>        $brokenResources
 * @return array{groups: list<array<string, mixed>>, rejected: array<string, int>}
 */
function groupBrowserEvents(array $loads, array $rules, array $brokenResources): array
{
    $groups = [];
    $rejected = [];
    foreach ($loads as $loadIndex => $load) {
        $events = $load['events'] ?? [];
        if (($load['status'] ?? null) !== BROWSER_PAGE_OPENED) {
            if ($events !== []) {
                $rejected[BROWSER_REJECT_NOT_OPENED] = ($rejected[BROWSER_REJECT_NOT_OPENED] ?? 0) + count($events);
            }
            continue;
        }

        foreach ($events as $event) {
            $reason = findBrowserEventRejection($event, $rules, $brokenResources);
            if ($reason !== null) {
                $rejected[$reason] = ($rejected[$reason] ?? 0) + 1;
                continue;
            }

            $key = buildBrowserEventKey($event);
            $groups[$key] ??= ['event' => $event, 'count' => 0, 'pages' => [], 'page_id' => $load['page_id'] ?? null,
                'widths' => [], 'loads' => []];
            $groups[$key]['count']++;
            $groups[$key]['loads'][$loadIndex] = true;
            if (!in_array($load['url'], $groups[$key]['pages'], true)) {
                $groups[$key]['pages'][] = $load['url'];
            }
            if (!in_array((int) $load['width'], $groups[$key]['widths'], true)) {
                $groups[$key]['widths'][] = (int) $load['width'];
            }
        }
    }

    foreach ($groups as &$group) {
        sort($group['widths']);
        $group['loads'] = count($group['loads']);
    }
    unset($group);

    return ['groups' => array_values($groups), 'rejected' => $rejected];
}

/**
 * Нужно ли перезапросить упавший запрос: GET статического ресурса (у XHR / POST могут быть побочные эффекты).
 *
 * @param array<string, mixed> $event
 */
function shouldRecheckBrowserRequest(array $event): bool
{
    return in_array($event['kind'], [BROWSER_EVENT_RESPONSE, BROWSER_EVENT_FAILED], true)
        && $event['method'] === BROWSER_RECHECK_METHOD
        && in_array($event['type'], BROWSER_RECHECK_TYPES, true);
}

/**
 * Находки из событий всех загрузок.
 *
 * Сетевая ошибка без ответа — только если повторилась в BROWSER_NETWORK_ERROR_MIN_LOADS загрузках. GET статического
 * ресурса перезапрашивается: ответ < 400 — сбой был разовым, находки нет.
 *
 * @param list<array<string, mixed>>               $loads    результаты `openBrowserPage()` + page_id
 * @param callable(string $url): array{status: ?int, error: ?string} $recheck
 * @param array<string, list<mixed>>|null          $rules    null — из `data/browser-ignore.php`
 * @return array{findings: list<array>, js_errors: int, failed_requests: int, rejected: array<string, int>}
 */
function buildBrowserFindings(
    array $loads,
    string $siteUrl,
    array $brokenResources,
    callable $recheck,
    ?array $rules = null
): array {
    $rules ??= loadBrowserIgnoreRules();
    $grouped = groupBrowserEvents($loads, $rules, $brokenResources);
    $rejected = $grouped['rejected'];
    $siteHost = (string) parse_url($siteUrl, PHP_URL_HOST);

    $findings = [];
    $jsErrors = 0;
    $failedRequests = 0;
    foreach ($grouped['groups'] as $group) {
        $event = $group['event'];
        $isRequest = in_array($event['kind'], [BROWSER_EVENT_RESPONSE, BROWSER_EVENT_FAILED], true);

        if (!$isRequest) {
            $findings[] = makeBrowserScriptFinding($group, $siteHost);
            $jsErrors++;
            continue;
        }

        if ($event['kind'] === BROWSER_EVENT_FAILED && $group['loads'] < BROWSER_NETWORK_ERROR_MIN_LOADS) {
            $rejected[BROWSER_REJECT_SINGLE_NETWORK] = ($rejected[BROWSER_REJECT_SINGLE_NETWORK] ?? 0) + 1;
            continue;
        }

        $recheckResult = null;
        if (shouldRecheckBrowserRequest($event)) {
            $recheckResult = $recheck((string) $event['url']);
            $status = $recheckResult['status'] ?? null;
            if ($status !== null && $status < BROWSER_FAILED_STATUS_MIN) {
                $rejected[BROWSER_REJECT_RECHECK_OK] = ($rejected[BROWSER_REJECT_RECHECK_OK] ?? 0) + 1;
                continue;
            }
        }

        $findings[] = makeBrowserRequestFinding($group, $siteHost, $recheckResult);
        $failedRequests++;
    }

    return ['findings' => $findings, 'js_errors' => $jsErrors, 'failed_requests' => $failedRequests, 'rejected' => $rejected];
}

/**
 * Находка `js_error`. Место — файл скрипта, у встроенного скрипта — адрес страницы.
 *
 * @param array<string, mixed> $group из groupBrowserEvents()
 * @return array<string, mixed>
 */
function makeBrowserScriptFinding(array $group, string $siteHost): array
{
    $event = $group['event'];
    $location = $event['url'] !== '' ? $event['url'] : ($group['pages'][0] ?? null);
    $isException = $event['kind'] === BROWSER_EVENT_EXCEPTION;
    $message = $event['message'] !== '' ? $event['message'] : '(без текста)';
    // console.error(error) печатает и стек: в заголовок — только первая строка
    $firstLine = trim(explode("\n", $message, 2)[0]);

    $severity = BROWSER_SEVERITY_CONSOLE;
    if ($isException) {
        $host = (string) parse_url((string) $location, PHP_URL_HOST);
        $severity = isOwnBrowserHost($host, $siteHost) ? BROWSER_SEVERITY_OWN : BROWSER_SEVERITY_FOREIGN;
    }

    $details = $isException
        ? 'Необработанное исключение: скрипт остановился на этой строке, код после неё не выполнился.'
        : 'Ошибка в консоли браузера' . ($event['kind'] === BROWSER_EVENT_CONSOLE ? ' (console.error из кода сайта).' : '.');
    $details .= ' ' . describeBrowserRepeats($group);
    if ($event['stack'] !== []) {
        $details .= "\nСтек:\n" . implode("\n", $event['stack']);
    }

    return makeFinding(
        'js_error',
        mb_substr(($isException ? 'Ошибка JS: ' : 'Ошибка в консоли: ') . $firstLine, 0, BROWSER_TITLE_MESSAGE_MAX),
        $location,
        implode("\n", [$event['kind'], $message, (string) ($event['line'] ?? ''), (string) ($event['column'] ?? '')]),
        $details,
        [
            'kind'    => $event['kind'],
            'message' => $event['message'],
            'line'    => $event['line'],
            'column'  => $event['column'],
            'stack'   => $event['stack'],
            'source'  => $event['source'] ?? null,
            'count'   => $group['count'],
            'widths'  => $group['widths'],
            'pages'   => $group['pages'],
        ],
        $group['page_id'],
        $severity
    );
}

/**
 * Находка `failed_request`.
 *
 * @param array<string, mixed>                       $group
 * @param array{status: ?int, error: ?string}|null   $recheck результат повторного запроса
 * @return array<string, mixed>
 */
function makeBrowserRequestFinding(array $group, string $siteHost, ?array $recheck): array
{
    $event = $group['event'];
    $hasStatus = $event['kind'] === BROWSER_EVENT_RESPONSE;
    $problem = $hasStatus ? 'код ' . $event['status'] : ($event['error_text'] !== '' ? $event['error_text'] : 'нет ответа');
    $host = (string) parse_url((string) $event['url'], PHP_URL_HOST);
    $type = $event['type'] !== '' ? $event['type'] : 'Other';

    $details = $hasStatus
        ? "Запрос {$event['method']} ({$type}) вернул код {$event['status']}."
        : "Запрос {$event['method']} ({$type}) не выполнился: {$problem}.";
    if ($event['kind'] === BROWSER_EVENT_FAILED && $event['blocked_reason'] !== null) {
        $details .= " Заблокирован браузером: {$event['blocked_reason']}.";
    }
    if ($recheck !== null) {
        $details .= ' Повторный запрос: ' . ($recheck['status'] !== null ? 'код ' . $recheck['status']
            : 'нет ответа' . (($recheck['error'] ?? null) !== null ? " ({$recheck['error']})" : '')) . '.';
    }
    $details .= ' ' . describeBrowserRepeats($group);

    return makeFinding(
        'failed_request',
        'Упавший запрос: ' . $problem,
        (string) $event['url'],
        $hasStatus ? (string) $event['status'] : (string) $event['error_text'],
        $details,
        [
            'method'         => $event['method'],
            'resource_type'  => $event['type'],
            'status'         => $hasStatus ? $event['status'] : null,
            'error_text'     => $hasStatus ? null : $event['error_text'],
            'blocked_reason' => $hasStatus ? null : $event['blocked_reason'],
            'recheck'        => $recheck,
            'count'          => $group['count'],
            'widths'         => $group['widths'],
            'pages'          => $group['pages'],
        ],
        $group['page_id'],
        isOwnBrowserHost($host, $siteHost) ? BROWSER_SEVERITY_OWN : BROWSER_SEVERITY_FOREIGN
    );
}

/**
 * «Повторов: 6 (страниц: 2, ширины: 360, 768, 1366 px).»
 *
 * @param array<string, mixed> $group
 */
function describeBrowserRepeats(array $group): string
{
    return 'Повторов: ' . $group['count'] . ' (страниц: ' . count($group['pages'])
        . ', ширины: ' . implode(', ', $group['widths']) . ' px).';
}
