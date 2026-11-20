<?php

declare(strict_types=1);

/**
 * Google PageSpeed Insights API v5 без БД: адрес запроса, ключ в заголовке, разбор ответа (оценка, лабораторные
 * метрики Lighthouse, данные реальных пользователей Chrome UX Report), понятные причины ошибок.
 * Сеть передаётся параметром (`$api`), чтобы тесты не ходили в интернет.
 *
 * Ключ — только заголовком `X-Goog-Api-Key`: в адресе его нет, поэтому он не попадает ни в лог, ни в текст
 * ошибки curl. Тексты ошибок дополнительно проходят через `redactPageSpeedKey()`.
 */

require_once ROOT_PATH . '/src/Services/HttpClient.php';

const PAGESPEED_API_URL         = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
const PAGESPEED_KEY_HEADER      = 'X-Goog-Api-Key';
const PAGESPEED_KEY_PATTERN     = '/^[A-Za-z0-9_\-]+$/'; // ключи Google — без пробелов и переводов строк
const PAGESPEED_TIMEOUT_SECONDS = 120; // анализ mobile на sudar.su — 67 с (16.11.2026)
const PAGESPEED_LOCALE          = 'ru';
const PAGESPEED_CATEGORY        = 'performance';
const PAGESPEED_STRATEGIES      = ['mobile', 'desktop'];

const PAGESPEED_HTTP_OK                = 200;
const PAGESPEED_HTTP_TOO_MANY_REQUESTS = 429;
const PAGESPEED_SCORE_SCALE            = 100; // score в ответе 0–1, на экране 0–100
const PAGESPEED_CLS_FIELD_DIVISOR      = 100; // CUMULATIVE_LAYOUT_SHIFT_SCORE приходит ×100 (12 → 0,12)
const PAGESPEED_CLS_PRECISION          = 3;
const PAGESPEED_ERROR_MAX_LENGTH       = 300;
const PAGESPEED_REDACTED               = '[REDACTED]';

const PAGESPEED_FIELD_SCOPE_URL    = 'url';
const PAGESPEED_FIELD_SCOPE_ORIGIN = 'origin';

/** Показатель → аудит Lighthouse (`numericValue`: мс, у CLS — без единиц) */
const PAGESPEED_LAB_AUDITS = [
    'lcp' => 'largest-contentful-paint',
    'cls' => 'cumulative-layout-shift',
    'tbt' => 'total-blocking-time',
    'fcp' => 'first-contentful-paint',
];

/** Показатель → метрика Chrome UX Report (`percentile` — 75-й процентиль за 28 дней) */
const PAGESPEED_FIELD_METRICS = [
    'lcp' => 'LARGEST_CONTENTFUL_PAINT_MS',
    'inp' => 'INTERACTION_TO_NEXT_PAINT',
    'cls' => 'CUMULATIVE_LAYOUT_SHIFT_SCORE',
    'fcp' => 'FIRST_CONTENTFUL_PAINT_MS',
];

const PAGESPEED_ERROR_TOO_MANY_REQUESTS = 'Превышен лимит запросов PageSpeed (429).';
const PAGESPEED_ERROR_NOT_JSON          = 'Ответ PageSpeed — не JSON.';
const PAGESPEED_ERROR_EMPTY             = 'Ответ PageSpeed без результатов.';
const PAGESPEED_ERROR_NO_RESPONSE       = 'PageSpeed не ответил.';

/**
 * @param string $url проверяемая страница
 * @param string $strategy `mobile` | `desktop`
 */
function buildPageSpeedUrl(string $url, string $strategy): string
{
    return PAGESPEED_API_URL . '?' . http_build_query([
        'url'      => $url,
        'strategy' => $strategy,
        'category' => PAGESPEED_CATEGORY,
        'locale'   => PAGESPEED_LOCALE,
    ], '', '&', PHP_QUERY_RFC3986);
}

/**
 * Ключ без лишних символов: перевод строки в нём дал бы чужой заголовок запроса.
 */
function isValidPageSpeedKey(#[\SensitiveParameter] string $key): bool
{
    return preg_match(PAGESPEED_KEY_PATTERN, $key) === 1;
}

/**
 * @return list<string>
 */
function buildPageSpeedHeaders(#[\SensitiveParameter] string $key): array
{
    return [PAGESPEED_KEY_HEADER . ': ' . $key];
}

/**
 * Обе стратегии по очереди. `$afterEach` вызывается после каждого запроса (heartbeat воркера).
 *
 * @param callable(string $url, list<string> $headers): array{status: ?int, body: string, error: ?string} $api
 * @param callable(string $strategy): void|null $afterEach
 * @return array{url: string, results: array<string, ?array>, errors: array<string, string>}
 */
function runPageSpeed(
    string $url,
    #[\SensitiveParameter] string $key,
    callable $api,
    ?callable $afterEach = null
): array {
    $results = [];
    $errors = [];

    foreach (PAGESPEED_STRATEGIES as $strategy) {
        $fetched = fetchPageSpeed($url, $strategy, $key, $api);
        $results[$strategy] = $fetched['result'];
        if ($fetched['error'] !== null) {
            $errors[$strategy] = $fetched['error'];
        }
        if ($afterEach !== null) {
            $afterEach($strategy);
        }
    }

    return ['url' => $url, 'results' => $results, 'errors' => $errors];
}

/**
 * Один запрос: результат или причина ошибки (ключ из текста вырезан).
 *
 * @param callable(string $url, list<string> $headers): array{status: ?int, body: string, error: ?string} $api
 * @return array{result: ?array, error: ?string}
 */
function fetchPageSpeed(string $url, string $strategy, #[\SensitiveParameter] string $key, callable $api): array
{
    $response = $api(buildPageSpeedUrl($url, $strategy), buildPageSpeedHeaders($key));
    $status = $response['status'] ?? null;

    if (($response['error'] ?? null) !== null || $status !== PAGESPEED_HTTP_OK) {
        $reason = describePageSpeedError($status, (string) ($response['body'] ?? ''), $response['error'] ?? null);

        return ['result' => null, 'error' => redactPageSpeedKey($reason, $key)];
    }

    $parsed = parsePageSpeedResponse((string) $response['body']);
    if ($parsed['error'] !== null) {
        $parsed['error'] = redactPageSpeedKey($parsed['error'], $key);
    }

    return $parsed;
}

/**
 * Причина неудачного запроса: сбой curl, 429, текст `error.message` из ответа Google или код ответа.
 */
function describePageSpeedError(?int $status, string $body, ?string $curlError): string
{
    if ($curlError !== null) {
        return limitPageSpeedError($curlError);
    }
    if ($status === null) {
        return PAGESPEED_ERROR_NO_RESPONSE;
    }
    if ($status === PAGESPEED_HTTP_TOO_MANY_REQUESTS) {
        return PAGESPEED_ERROR_TOO_MANY_REQUESTS;
    }

    $decoded = json_decode($body, true);
    $message = is_array($decoded) && is_string($decoded['error']['message'] ?? null)
        ? trim($decoded['error']['message'])
        : '';

    return limitPageSpeedError("HTTP {$status}" . ($message !== '' ? ': ' . $message : '.'));
}

function limitPageSpeedError(string $text): string
{
    return mb_substr($text, 0, PAGESPEED_ERROR_MAX_LENGTH);
}

function redactPageSpeedKey(string $text, #[\SensitiveParameter] string $key): string
{
    return $key === '' ? $text : str_replace($key, PAGESPEED_REDACTED, $text);
}

/**
 * Ответ 200 → результат стратегии. `runtimeError` Lighthouse или ни оценки, ни метрик → ошибка.
 *
 * @return array{result: ?array{score: ?int, lab: array<string, int|float|null>, field: ?array<string, int|float|null>,
 *               field_scope: ?string, warnings: list<string>, lighthouse_version: ?string, analyzed_at: ?string},
 *               error: ?string}
 */
function parsePageSpeedResponse(string $body): array
{
    $json = json_decode($body, true);
    if (!is_array($json)) {
        return ['result' => null, 'error' => PAGESPEED_ERROR_NOT_JSON];
    }

    $lighthouse = is_array($json['lighthouseResult'] ?? null) ? $json['lighthouseResult'] : [];
    $runtimeError = $lighthouse['runtimeError'] ?? null;
    if (is_array($runtimeError) && ($runtimeError['code'] ?? '') !== '' && ($runtimeError['code'] ?? '') !== 'NO_ERROR') {
        $message = is_string($runtimeError['message'] ?? null) ? ': ' . trim($runtimeError['message']) : '';

        return ['result' => null, 'error' => limitPageSpeedError('Lighthouse: ' . $runtimeError['code'] . $message)];
    }

    $score = $lighthouse['categories'][PAGESPEED_CATEGORY]['score'] ?? null;
    $score = is_int($score) || is_float($score) ? (int) round($score * PAGESPEED_SCORE_SCALE) : null;
    $lab = extractPageSpeedLab(is_array($lighthouse['audits'] ?? null) ? $lighthouse['audits'] : []);
    $field = extractPageSpeedField($json);

    if ($score === null && array_filter($lab, static fn ($value): bool => $value !== null) === []) {
        return ['result' => null, 'error' => PAGESPEED_ERROR_EMPTY];
    }

    return ['result' => [
        'score'              => $score,
        'lab'                => $lab,
        'field'              => $field['metrics'],
        'field_scope'        => $field['scope'],
        'warnings'           => array_values(array_filter(
            is_array($lighthouse['runWarnings'] ?? null) ? $lighthouse['runWarnings'] : [],
            static fn ($warning): bool => is_string($warning) && $warning !== ''
        )),
        'lighthouse_version' => is_string($lighthouse['lighthouseVersion'] ?? null) ? $lighthouse['lighthouseVersion'] : null,
        'analyzed_at'        => is_string($json['analysisUTCTimestamp'] ?? null) ? $json['analysisUTCTimestamp'] : null,
    ], 'error' => null];
}

/**
 * @param array<string, mixed> $audits `lighthouseResult.audits`
 * @return array<string, int|float|null> показатель → мс (целое) или CLS
 */
function extractPageSpeedLab(array $audits): array
{
    $lab = [];
    foreach (PAGESPEED_LAB_AUDITS as $metric => $auditId) {
        $value = $audits[$auditId]['numericValue'] ?? null;
        $lab[$metric] = is_int($value) || is_float($value) ? normalizePageSpeedValue($metric, (float) $value) : null;
    }

    return $lab;
}

/**
 * Данные реальных пользователей: по самой странице (`loadingExperience`, если это не подмена данными сайта),
 * иначе по всему сайту (`originLoadingExperience`), иначе нет.
 *
 * @param array<string, mixed> $json
 * @return array{metrics: ?array<string, int|float|null>, scope: ?string}
 */
function extractPageSpeedField(array $json): array
{
    $page = is_array($json['loadingExperience'] ?? null) ? $json['loadingExperience'] : [];
    $origin = is_array($json['originLoadingExperience'] ?? null) ? $json['originLoadingExperience'] : [];

    $candidates = [
        [$page, ($page['origin_fallback'] ?? false) === true ? PAGESPEED_FIELD_SCOPE_ORIGIN : PAGESPEED_FIELD_SCOPE_URL],
        [$origin, PAGESPEED_FIELD_SCOPE_ORIGIN],
    ];
    foreach ($candidates as [$experience, $scope]) {
        $metrics = extractPageSpeedFieldMetrics(is_array($experience['metrics'] ?? null) ? $experience['metrics'] : []);
        if ($metrics !== null) {
            return ['metrics' => $metrics, 'scope' => $scope];
        }
    }

    return ['metrics' => null, 'scope' => null];
}

/**
 * @param array<string, mixed> $metrics `loadingExperience.metrics`
 * @return array<string, int|float|null>|null null — ни одного известного показателя
 */
function extractPageSpeedFieldMetrics(array $metrics): ?array
{
    $result = [];
    foreach (PAGESPEED_FIELD_METRICS as $metric => $key) {
        $value = $metrics[$key]['percentile'] ?? null;
        if (!is_int($value) && !is_float($value)) {
            $result[$metric] = null;
            continue;
        }
        $value = (float) $value;
        if ($metric === 'cls') {
            $value /= PAGESPEED_CLS_FIELD_DIVISOR;
        }
        $result[$metric] = normalizePageSpeedValue($metric, $value);
    }

    return array_filter($result, static fn ($value): bool => $value !== null) !== [] ? $result : null;
}

/**
 * CLS — дробное с тремя знаками, остальное — целые миллисекунды.
 */
function normalizePageSpeedValue(string $metric, float $value): int|float
{
    return $metric === 'cls' ? round($value, PAGESPEED_CLS_PRECISION) : (int) round($value);
}
