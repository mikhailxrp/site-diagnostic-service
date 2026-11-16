<?php

declare(strict_types=1);

/**
 * Шаг `pagespeed` без БД: главная через PageSpeed Insights (mobile + desktop), оценка показателей по порогам
 * Core Web Vitals, находки `speed`. Сеть передаётся параметром (`$api`).
 *
 * Пороги — как у Google (граница «хорошо» и «требует улучшения» включительно), один набор для лаборатории и
 * реальных пользователей. LCP и CLS оцениваются по реальным пользователям, если они есть, иначе по лаборатории;
 * отзывчивость — по INP пользователей, иначе по TBT лаборатории (TBT — лабораторная замена INP). Одна находка на
 * показатель сразу по обеим стратегиям, серьёзность — по худшей.
 */

require_once ROOT_PATH . '/src/Services/FindingFactory.php';
require_once ROOT_PATH . '/src/Services/PageSpeedService.php';

const SPEED_RATING_GOOD  = 'good';
const SPEED_RATING_NEEDS = 'needs_improvement';
const SPEED_RATING_POOR  = 'poor';

/** Чем больше, тем хуже: худшая оценка по стратегиям */
const SPEED_RATING_RANK = [SPEED_RATING_GOOD => 0, SPEED_RATING_NEEDS => 1, SPEED_RATING_POOR => 2];

const SPEED_RATING_LABELS = [
    SPEED_RATING_GOOD  => 'хорошо',
    SPEED_RATING_NEEDS => 'требует улучшения',
    SPEED_RATING_POOR  => 'плохо',
];

/** Показатель → [предел «хорошо», предел «требует улучшения»] включительно; мс, у CLS — без единиц */
const SPEED_THRESHOLDS = [
    'lcp' => [2500, 4000],
    'cls' => [0.1, 0.25],
    'inp' => [200, 500],
    'tbt' => [200, 600],
    'fcp' => [1800, 3000],
];

/** Оценка Lighthouse 0–100: ≥ 90 — хорошо, ≥ 50 — требует улучшения, ниже — плохо (шкала Google) */
const SPEED_SCORE_GOOD_MIN  = 90;
const SPEED_SCORE_NEEDS_MIN = 50;
const SPEED_SCORE_MAX       = 100;

const SPEED_SEVERITY = [SPEED_RATING_POOR => 'medium', SPEED_RATING_NEEDS => 'low'];

const SPEED_SOURCE_FIELD = 'field';
const SPEED_SOURCE_LAB   = 'lab';
const SPEED_SOURCE_LABELS = [SPEED_SOURCE_FIELD => 'реальные пользователи', SPEED_SOURCE_LAB => 'лаборатория'];

const SPEED_STRATEGY_LABELS = ['mobile' => 'Mobile', 'desktop' => 'Desktop'];

const SPEED_METRIC_LABELS = ['lcp' => 'LCP', 'cls' => 'CLS', 'inp' => 'INP', 'tbt' => 'TBT', 'fcp' => 'FCP'];

/** Показатели с находками: метрика реальных пользователей и её замена из лаборатории */
const SPEED_INDICATORS = [
    'lcp'            => ['title' => 'Медленно появляется основное содержимое', 'field' => 'lcp', 'lab' => 'lcp'],
    'cls'            => ['title' => 'Содержимое сдвигается при загрузке', 'field' => 'cls', 'lab' => 'cls'],
    'responsiveness' => ['title' => 'Медленный отклик на действия', 'field' => 'inp', 'lab' => 'tbt'],
];
const SPEED_SCORE_KEY   = 'score';
const SPEED_SCORE_TITLE = 'Низкая оценка скорости PageSpeed';

const SPEED_MS_IN_SECOND = 1000;

const SPEED_NO_KEY_MESSAGE      = 'Не задан PAGESPEED_API_KEY в .env.';
const SPEED_BAD_KEY_MESSAGE     = 'PAGESPEED_API_KEY в .env содержит недопустимые символы.';
const SPEED_NO_HOME_MESSAGE     = 'Главная не ответила — проверять скорость нечего.';
const SPEED_ALL_FAILED_PREFIX   = 'PageSpeed не проверил главную: ';
const SPEED_NO_SCORE_LABEL      = 'без оценки';

/**
 * @param array<string, mixed> $context контекст шагов (нужен `facts.availability.home`)
 * @param callable(string $url, list<string> $headers): array{status: ?int, body: string, error: ?string} $api
 * @param callable(string $strategy): void|null $afterEach после каждого запроса (heartbeat)
 * @return array<string, mixed> результат шага; `facts.pagespeed` — для `summary_json.pagespeed`
 */
function runSpeedCheck(array $context, string $key, callable $api, ?callable $afterEach = null): array
{
    $key = trim($key);
    if ($key === '') {
        return ['status' => 'skipped', 'message' => SPEED_NO_KEY_MESSAGE];
    }
    if (!isValidPageSpeedKey($key)) {
        return ['status' => 'skipped', 'message' => SPEED_BAD_KEY_MESSAGE];
    }

    $home = $context['facts']['availability']['home'] ?? [];
    $url = (string) ($home['final_url'] ?? '');
    if ($url === '' || ($home['status'] ?? null) === null) {
        return ['status' => 'skipped', 'message' => SPEED_NO_HOME_MESSAGE];
    }

    $run = runPageSpeed($url, $key, $api, $afterEach);
    if (array_filter($run['results']) === []) {
        return ['status' => 'skipped', 'message' => SPEED_ALL_FAILED_PREFIX . formatSpeedErrors($run['errors'])];
    }

    $pageId = isset($home['page_id']) ? (int) $home['page_id'] : null;
    $findings = buildSpeedFindings($run['results'], $url, $pageId);

    return [
        'status'   => 'done',
        'message'  => buildSpeedStepMessage($run['results'], $run['errors'], count($findings)),
        'facts'    => ['pagespeed' => ['url' => $url, ...$run['results'], 'errors' => $run['errors']]],
        'findings' => $findings,
    ];
}

function rateSpeedMetric(string $metric, int|float $value): string
{
    [$good, $needs] = SPEED_THRESHOLDS[$metric];

    return match (true) {
        $value <= $good  => SPEED_RATING_GOOD,
        $value <= $needs => SPEED_RATING_NEEDS,
        default          => SPEED_RATING_POOR,
    };
}

function rateSpeedScore(int $score): string
{
    return match (true) {
        $score >= SPEED_SCORE_GOOD_MIN  => SPEED_RATING_GOOD,
        $score >= SPEED_SCORE_NEEDS_MIN => SPEED_RATING_NEEDS,
        default                         => SPEED_RATING_POOR,
    };
}

/**
 * «2,8 с», «117 мс», CLS — «0,12».
 */
function formatSpeedValue(string $metric, int|float $value): string
{
    if ($metric === 'cls') {
        return rtrim(rtrim(number_format((float) $value, PAGESPEED_CLS_PRECISION, ',', ''), '0'), ',');
    }
    if ($value < SPEED_MS_IN_SECOND) {
        return number_format((float) $value, 0, ',', ' ') . ' мс';
    }

    return number_format($value / SPEED_MS_IN_SECOND, 1, ',', ' ') . ' с';
}

/**
 * Значение показателя для оценки: реальные пользователи, иначе лаборатория, иначе null.
 *
 * @param array<string, mixed>                       $result    результат стратегии (`parsePageSpeedResponse()`)
 * @param array{field: string, lab: string}          $indicator строка SPEED_INDICATORS
 * @return array{metric: string, value: int|float, source: string}|null
 */
function pickSpeedIndicatorValue(array $result, array $indicator): ?array
{
    $field = $result['field'][$indicator['field']] ?? null;
    if (is_int($field) || is_float($field)) {
        return ['metric' => $indicator['field'], 'value' => $field, 'source' => SPEED_SOURCE_FIELD];
    }

    $lab = $result['lab'][$indicator['lab']] ?? null;
    if (is_int($lab) || is_float($lab)) {
        return ['metric' => $indicator['lab'], 'value' => $lab, 'source' => SPEED_SOURCE_LAB];
    }

    return null;
}

/**
 * @param array<string, ?array> $results стратегия → результат или null (ошибка)
 * @return list<array<string, mixed>>
 */
function buildSpeedFindings(array $results, string $url, ?int $pageId): array
{
    $findings = [];

    foreach (SPEED_INDICATORS as $key => $indicator) {
        $entries = [];
        foreach ($results as $strategy => $result) {
            $picked = is_array($result) ? pickSpeedIndicatorValue($result, $indicator) : null;
            if ($picked !== null) {
                $entries[$strategy] = $picked + ['rating' => rateSpeedMetric($picked['metric'], $picked['value'])];
            }
        }

        $worst = findWorstSpeedRating(array_column($entries, 'rating'));
        if ($worst === SPEED_RATING_GOOD) {
            continue;
        }

        $metrics = array_unique(array_map(
            static fn (array $entry): string => SPEED_METRIC_LABELS[$entry['metric']],
            array_values($entries)
        ));
        $findings[] = makeFinding(
            'speed',
            $indicator['title'] . ' (' . implode(' / ', $metrics) . ')',
            $url,
            $key,
            formatSpeedFindingDetails($entries),
            ['strategies' => $entries],
            $pageId,
            SPEED_SEVERITY[$worst]
        );
    }

    $scoreFinding = buildSpeedScoreFinding($results, $url, $pageId);

    return $scoreFinding !== null ? [...$findings, $scoreFinding] : $findings;
}

/**
 * Находка «низкая оценка», если хотя бы у одной стратегии оценка ниже SPEED_SCORE_NEEDS_MIN.
 *
 * @param array<string, ?array> $results
 * @return array<string, mixed>|null
 */
function buildSpeedScoreFinding(array $results, string $url, ?int $pageId): ?array
{
    $scores = [];
    foreach ($results as $strategy => $result) {
        if (is_array($result) && is_int($result['score'] ?? null)) {
            $scores[$strategy] = $result['score'];
        }
    }
    $poor = array_filter($scores, static fn (int $score): bool => rateSpeedScore($score) === SPEED_RATING_POOR);
    if ($poor === []) {
        return null;
    }

    $parts = [];
    foreach ($scores as $strategy => $score) {
        $parts[] = SPEED_STRATEGY_LABELS[$strategy] . ': ' . $score . ' из ' . SPEED_SCORE_MAX;
    }

    return makeFinding(
        'speed',
        SPEED_SCORE_TITLE,
        $url,
        SPEED_SCORE_KEY,
        implode('; ', $parts) . '.',
        ['scores' => $scores],
        $pageId,
        SPEED_SEVERITY[SPEED_RATING_POOR]
    );
}

/**
 * @param list<string> $ratings
 */
function findWorstSpeedRating(array $ratings): string
{
    $worst = SPEED_RATING_GOOD;
    foreach ($ratings as $rating) {
        if (SPEED_RATING_RANK[$rating] > SPEED_RATING_RANK[$worst]) {
            $worst = $rating;
        }
    }

    return $worst;
}

/**
 * «Mobile: 2,8 с — требует улучшения (реальные пользователи); Desktop: 1,1 с — хорошо (лаборатория).»
 *
 * @param array<string, array{metric: string, value: int|float, source: string, rating: string}> $entries
 */
function formatSpeedFindingDetails(array $entries): string
{
    $parts = [];
    foreach ($entries as $strategy => $entry) {
        $parts[] = SPEED_STRATEGY_LABELS[$strategy] . ': ' . formatSpeedValue($entry['metric'], $entry['value'])
            . ' — ' . SPEED_RATING_LABELS[$entry['rating']] . ' (' . SPEED_SOURCE_LABELS[$entry['source']] . ')';
    }

    return implode('; ', $parts) . '.';
}

/**
 * «Mobile: 39, Desktop: ошибка — HTTP 400: …; находок: 2»
 *
 * @param array<string, ?array> $results
 * @param array<string, string> $errors
 */
function buildSpeedStepMessage(array $results, array $errors, int $findings): string
{
    $parts = [];
    foreach ($results as $strategy => $result) {
        $label = SPEED_STRATEGY_LABELS[$strategy];
        $parts[] = match (true) {
            is_array($result) && is_int($result['score'] ?? null) => $label . ': ' . $result['score'],
            is_array($result)                                       => $label . ': ' . SPEED_NO_SCORE_LABEL,
            default => $label . ': ошибка — ' . rtrim($errors[$strategy] ?? PAGESPEED_ERROR_NO_RESPONSE, '. '),
        };
    }

    return implode(', ', $parts) . '; находок: ' . $findings;
}

/**
 * «Mobile — HTTP 400: …; Desktop — …»
 *
 * @param array<string, string> $errors
 */
function formatSpeedErrors(array $errors): string
{
    $parts = [];
    foreach ($errors as $strategy => $error) {
        // Точка в конце причины перед «;» лишняя: «… API key.; Desktop»
        $parts[] = (SPEED_STRATEGY_LABELS[$strategy] ?? $strategy) . ' — ' . rtrim($error, '. ');
    }

    return $parts !== [] ? implode('; ', $parts) : PAGESPEED_ERROR_NO_RESPONSE;
}
