<?php

declare(strict_types=1);

/**
 * Сравнение двух готовых наборов данных диагностики. Сервис не обращается к БД, сети или сессии:
 * контроллер передаёт находки и стек обеих диагностик готовыми массивами.
 */

require_once ROOT_PATH . '/src/Services/ReportBuilder.php';

const COMPARISON_FINGERPRINT_PATTERN = '/^[a-f0-9]{40}$/i';

/**
 * @param list<array<string, mixed>> $beforeFindings
 * @param list<array<string, mixed>> $afterFindings
 * @param list<array<string, mixed>> $beforeStack
 * @param list<array<string, mixed>> $afterStack
 * @return array{
 *     fixed: list<array>, remaining: list<array>, appeared: list<array>,
 *     aiBefore: list<array>, aiAfter: list<array>,
 *     stack: array{versionChanges: list<array>, addedPlugins: list<array>, removedPlugins: list<array>},
 *     invalidFingerprintCount: int, hasChanges: bool
 * }
 */
function buildDiagnosticComparison(
    array $beforeFindings,
    array $afterFindings,
    array $beforeStack,
    array $afterStack
): array {
    [$beforeFacts, $aiBefore, $beforeInvalid] = splitComparisonFindings($beforeFindings);
    [$afterFacts, $aiAfter, $afterInvalid] = splitComparisonFindings($afterFindings);

    $fixed = [];
    $remaining = [];
    $appeared = [];

    foreach ($beforeFacts as $fingerprint => $finding) {
        if (isset($afterFacts[$fingerprint])) {
            $remaining[] = buildComparisonFinding($afterFacts[$fingerprint]);
            continue;
        }

        $fixed[] = buildComparisonFinding($finding);
    }

    foreach ($afterFacts as $fingerprint => $finding) {
        if (!isset($beforeFacts[$fingerprint])) {
            $appeared[] = buildComparisonFinding($finding);
        }
    }

    sortComparisonFindings($fixed);
    sortComparisonFindings($remaining);
    sortComparisonFindings($appeared);

    $aiBefore = array_map('buildComparisonFinding', array_values($aiBefore));
    $aiAfter = array_map('buildComparisonFinding', array_values($aiAfter));
    sortComparisonFindings($aiBefore);
    sortComparisonFindings($aiAfter);

    $stack = compareDiagnosticStack($beforeStack, $afterStack);

    return [
        'fixed'                   => $fixed,
        'remaining'               => $remaining,
        'appeared'                => $appeared,
        'aiBefore'                => $aiBefore,
        'aiAfter'                 => $aiAfter,
        'stack'                   => $stack,
        'invalidFingerprintCount' => $beforeInvalid + $afterInvalid,
        'hasChanges'              => $fixed !== []
            || $appeared !== []
            || $stack['versionChanges'] !== []
            || $stack['addedPlugins'] !== []
            || $stack['removedPlugins'] !== [],
    ];
}

/**
 * @param list<array<string, mixed>> $findings
 * @return array{0: array<string, array>, 1: array<string, array>, 2: int}
 */
function splitComparisonFindings(array $findings): array
{
    $facts = [];
    $ai = [];
    $invalid = 0;

    foreach ($findings as $finding) {
        if (($finding['source'] ?? null) === 'ai') {
            $key = comparisonAiFindingKey($finding);
            $ai[$key] = isset($ai[$key]) ? chooseComparisonFinding($ai[$key], $finding) : $finding;
            continue;
        }

        $fingerprint = strtolower(trim((string) ($finding['fingerprint'] ?? '')));
        if (preg_match(COMPARISON_FINGERPRINT_PATTERN, $fingerprint) !== 1) {
            $invalid++;
            continue;
        }

        $facts[$fingerprint] = isset($facts[$fingerprint])
            ? chooseComparisonFinding($facts[$fingerprint], $finding)
            : $finding;
    }

    return [$facts, $ai, $invalid];
}

/**
 * ИИ-находки не сопоставляются между запусками, но одинаковые строки внутри одного запуска не дублируются.
 *
 * @param array<string, mixed> $finding
 */
function comparisonAiFindingKey(array $finding): string
{
    $id = (int) ($finding['id'] ?? 0);
    if ($id > 0) {
        return 'id:' . $id;
    }

    $parts = [
        (string) ($finding['category'] ?? ''),
        (string) ($finding['severity'] ?? ''),
        (string) ($finding['title'] ?? ''),
        (string) ($finding['location'] ?? ''),
        (string) ($finding['details'] ?? ''),
    ];

    return 'content:' . sha1(implode("\0", $parts));
}

/**
 * При дубле fingerprint берётся запись с меньшим положительным id; без id — лексикографически первая.
 *
 * @param array<string, mixed> $left
 * @param array<string, mixed> $right
 * @return array<string, mixed>
 */
function chooseComparisonFinding(array $left, array $right): array
{
    $leftId = (int) ($left['id'] ?? 0);
    $rightId = (int) ($right['id'] ?? 0);

    if ($leftId > 0 && $rightId > 0) {
        return $leftId <= $rightId ? $left : $right;
    }
    if ($leftId > 0 || $rightId > 0) {
        return $leftId > 0 ? $left : $right;
    }

    return comparisonFindingSignature($left) <= comparisonFindingSignature($right) ? $left : $right;
}

/** @param array<string, mixed> $finding */
function comparisonFindingSignature(array $finding): string
{
    return implode("\0", [
        (string) ($finding['severity'] ?? ''),
        (string) ($finding['category'] ?? ''),
        (string) ($finding['title'] ?? ''),
        (string) ($finding['location'] ?? ''),
        (string) ($finding['details'] ?? ''),
    ]);
}

/**
 * @param array<string, mixed> $finding
 * @return array{id: int, severity: string, severityLabel: string, badgeClass: string, categoryLabel: string,
 *               title: string, details: ?string, location: ?string}
 */
function buildComparisonFinding(array $finding): array
{
    $severity = array_key_exists((string) ($finding['severity'] ?? ''), REPORT_SEVERITY_LABELS)
        ? (string) $finding['severity']
        : 'info';
    $category = (string) ($finding['category'] ?? '');

    return [
        'id'            => (int) ($finding['id'] ?? 0),
        'severity'      => $severity,
        'severityLabel' => REPORT_SEVERITY_LABELS[$severity],
        'badgeClass'    => REPORT_SEVERITY_BADGES[$severity],
        'categoryLabel' => REPORT_FINDING_CATEGORY_LABELS[$category] ?? ($category !== '' ? $category : 'Другое'),
        'title'         => trim((string) ($finding['title'] ?? '')) ?: 'Без названия',
        'details'       => comparisonNullableText($finding['details'] ?? null),
        'location'      => comparisonNullableText($finding['location'] ?? null),
    ];
}

function comparisonNullableText(mixed $value): ?string
{
    $text = trim((string) ($value ?? ''));

    return $text !== '' ? $text : null;
}

/** @param list<array<string, mixed>> $findings */
function sortComparisonFindings(array &$findings): void
{
    $severityOrder = array_flip(array_keys(REPORT_SEVERITY_LABELS));
    usort($findings, static function (array $left, array $right) use ($severityOrder): int {
        $severity = ($severityOrder[$left['severity']] ?? PHP_INT_MAX)
            <=> ($severityOrder[$right['severity']] ?? PHP_INT_MAX);
        if ($severity !== 0) {
            return $severity;
        }

        $title = strnatcasecmp((string) $left['title'], (string) $right['title']);
        if ($title !== 0) {
            return $title;
        }

        $location = strnatcasecmp((string) ($left['location'] ?? ''), (string) ($right['location'] ?? ''));

        return $location !== 0 ? $location : ((int) $left['id'] <=> (int) $right['id']);
    });
}

/**
 * @param list<array<string, mixed>> $beforeStack
 * @param list<array<string, mixed>> $afterStack
 * @return array{versionChanges: list<array>, addedPlugins: list<array>, removedPlugins: list<array>}
 */
function compareDiagnosticStack(array $beforeStack, array $afterStack): array
{
    $before = indexComparisonStack($beforeStack);
    $after = indexComparisonStack($afterStack);
    $versionChanges = [];
    $addedPlugins = [];
    $removedPlugins = [];

    foreach ($before as $key => $item) {
        if (isset($after[$key])) {
            $afterItem = $after[$key];
            if ($item['version'] !== null && $afterItem['version'] !== null && $item['version'] !== $afterItem['version']) {
                $versionChanges[] = [
                    'category'      => $afterItem['category'],
                    'categoryLabel' => $afterItem['categoryLabel'],
                    'name'          => $afterItem['name'],
                    'beforeVersion' => $item['version'],
                    'afterVersion'  => $afterItem['version'],
                ];
            }
            continue;
        }

        if ($item['category'] === 'plugin') {
            $removedPlugins[] = $item;
        }
    }

    foreach ($after as $key => $item) {
        if (!isset($before[$key]) && $item['category'] === 'plugin') {
            $addedPlugins[] = $item;
        }
    }

    sortComparisonStackItems($versionChanges);
    sortComparisonStackItems($addedPlugins);
    sortComparisonStackItems($removedPlugins);

    return [
        'versionChanges' => $versionChanges,
        'addedPlugins'   => $addedPlugins,
        'removedPlugins' => $removedPlugins,
    ];
}

/**
 * @param list<array<string, mixed>> $items
 * @return array<string, array{category: string, categoryLabel: string, name: string, version: ?string}>
 */
function indexComparisonStack(array $items): array
{
    $indexed = [];

    foreach ($items as $item) {
        $category = mb_strtolower(trim((string) ($item['category'] ?? '')), 'UTF-8');
        $name = preg_replace('/\s+/u', ' ', trim((string) ($item['name'] ?? ''))) ?? '';
        if ($category === '' || $name === '') {
            continue;
        }

        $key = $category . "\0" . mb_strtolower($name, 'UTF-8');
        $prepared = [
            'category'      => $category,
            'categoryLabel' => REPORT_STACK_CATEGORY_LABELS[$category] ?? $category,
            'name'          => $name,
            'version'       => comparisonNullableText($item['version'] ?? null),
        ];
        $indexed[$key] = isset($indexed[$key]) ? chooseComparisonStackItem($indexed[$key], $prepared) : $prepared;
    }

    return $indexed;
}

/**
 * @param array{category: string, categoryLabel: string, name: string, version: ?string} $left
 * @param array{category: string, categoryLabel: string, name: string, version: ?string} $right
 * @return array{category: string, categoryLabel: string, name: string, version: ?string}
 */
function chooseComparisonStackItem(array $left, array $right): array
{
    if ($left['version'] === null || $right['version'] === null) {
        return $left['version'] !== null ? $left : $right;
    }

    return strnatcasecmp($left['version'], $right['version']) <= 0 ? $left : $right;
}

/** @param list<array<string, mixed>> $items */
function sortComparisonStackItems(array &$items): void
{
    $categoryOrder = array_flip(array_keys(REPORT_STACK_CATEGORY_LABELS));
    usort($items, static function (array $left, array $right) use ($categoryOrder): int {
        $category = ($categoryOrder[$left['category']] ?? PHP_INT_MAX)
            <=> ($categoryOrder[$right['category']] ?? PHP_INT_MAX);

        return $category !== 0 ? $category : strnatcasecmp((string) $left['name'], (string) $right['name']);
    });
}
