<?php

declare(strict_types=1);

/**
 * Безопасные данные клиентского отчёта. Технические поля находок намеренно не переносятся в результат.
 */

require_once ROOT_PATH . '/src/Services/Ai/ClientTextAnalysis.php';

const CLIENT_REPORT_DATE_FORMAT = 'd.m.Y';
const CLIENT_REPORT_UNKNOWN_SITE = 'Проверенный сайт';
const CLIENT_REPORT_UNKNOWN_DATE = 'Дата не указана';

const CLIENT_REPORT_SEVERITY_ORDER = [
    'critical' => 0,
    'high'     => 1,
    'medium'   => 2,
    'low'      => 3,
    'info'     => 4,
];

const CLIENT_REPORT_PRIORITY = [
    0 => ['label' => 'Исправить в первую очередь', 'badgeClass' => 'bg-red-lt'],
    1 => ['label' => 'Исправить в первую очередь', 'badgeClass' => 'bg-orange-lt'],
    2 => ['label' => 'Важно', 'badgeClass' => 'bg-yellow-lt'],
    3 => ['label' => 'Запланировать', 'badgeClass' => 'bg-azure-lt'],
    4 => ['label' => 'Рекомендация', 'badgeClass' => 'bg-secondary-lt'],
];

/**
 * @return array{fallback: array, categories: array<string, array>}
 */
function loadClientTextTemplates(?string $path = null): array
{
    $templatePath = $path ?? ROOT_PATH . '/data/client-texts.php';
    $templates = require $templatePath;

    if (!is_array($templates) || !is_array($templates['fallback'] ?? null) || !is_array($templates['categories'] ?? null)) {
        throw new RuntimeException('Некорректная карта клиентских текстов.');
    }

    return $templates;
}

/**
 * @param array<string, mixed>       $diagnostic строка `diagnostics`
 * @param list<array<string, mixed>> $findings строки `findings`
 * @param array{fallback: array, categories: array<string, array>}|null $templates
 * @param list<array<string, mixed>> $aiResults строки `ai_results`
 * @return array{site: string, date: string, partial: bool, summary: ?string, findingCount: int, groups: list<array>}
 */
function buildClientReport(array $diagnostic, array $findings, ?array $templates = null, array $aiResults = []): array
{
    $templates ??= loadClientTextTemplates();
    $fallback = normalizeClientTextTemplate($templates['fallback'] ?? []);
    $categoryTemplates = is_array($templates['categories'] ?? null) ? $templates['categories'] : [];
    $categoryOrder = array_flip(array_keys($categoryTemplates));
    $groups = [];

    foreach ($findings as $finding) {
        $category = is_string($finding['category'] ?? null) ? $finding['category'] : '';
        $template = normalizeClientTextTemplate($categoryTemplates[$category] ?? [], $fallback);
        $groupKey = array_key_exists($category, $categoryTemplates) ? $category : '__fallback:' . $category;
        $severityRank = CLIENT_REPORT_SEVERITY_ORDER[(string) ($finding['severity'] ?? '')]
            ?? CLIENT_REPORT_SEVERITY_ORDER['info'];

        if (!isset($groups[$groupKey])) {
            $groups[$groupKey] = [
                'count'       => 0,
                'texts'       => [],
                'template'    => $template,
                'priority'    => $severityRank,
                'sortOrder'   => $categoryOrder[$category] ?? count($categoryTemplates),
            ];
        }

        $groups[$groupKey]['count']++;
        $groups[$groupKey]['priority'] = min($groups[$groupKey]['priority'], $severityRank);
        $clientText = trim(is_string($finding['client_text'] ?? null) ? $finding['client_text'] : '');
        if ($clientText !== '') {
            $groups[$groupKey]['texts'][$clientText] = true;
        }
    }

    $groups = array_values($groups);
    usort(
        $groups,
        static fn (array $left, array $right): int => [$left['priority'], $left['sortOrder']]
            <=> [$right['priority'], $right['sortOrder']]
    );

    return [
        'site'         => buildClientReportSite($diagnostic['host'] ?? null),
        'date'         => formatClientReportDate($diagnostic['created_at'] ?? null),
        'partial'      => (string) ($diagnostic['status'] ?? '') === 'failed',
        'summary'      => buildClientAiSummary($diagnostic, $aiResults),
        'findingCount' => count($findings),
        'groups'       => array_map('buildClientReportGroup', $groups),
    ];
}

/**
 * Резюме только из успешного ответа текущего завершённого шага `ai_client`.
 *
 * @param array<string, mixed>       $diagnostic
 * @param list<array<string, mixed>> $aiResults
 */
function buildClientAiSummary(array $diagnostic, array $aiResults): ?string
{
    $step = findClientDiagnosticStep($diagnostic['steps'] ?? [], 'ai_client');
    if (($step['status'] ?? null) !== 'done') {
        return null;
    }
    $result = findCurrentClientAiResult($aiResults, AI_TASK_CLIENT, $step['started_at'] ?? null);
    if (($result['status'] ?? null) !== AI_RESULT_STATUS_OK || !is_array($result['parsed_json'] ?? null)) {
        return null;
    }

    return filterClientAiText($result['parsed_json']['summary'] ?? null, AI_CLIENT_SUMMARY_MAX_CHARS);
}

/**
 * @param array<string, mixed> $group
 * @return array{count: int, countLabel: string, priorityLabel: string, badgeClass: string,
 *               descriptions: list<string>, impact: string, action: string}
 */
function buildClientReportGroup(array $group): array
{
    $count = (int) $group['count'];
    $template = $group['template'];
    $priority = CLIENT_REPORT_PRIORITY[(int) $group['priority']] ?? CLIENT_REPORT_PRIORITY[4];
    $texts = array_keys($group['texts']);

    return [
        'count'         => $count,
        'countLabel'    => $count . ' ' . selectClientCountForm($count, $template['forms']),
        'priorityLabel' => $priority['label'],
        'badgeClass'    => $priority['badgeClass'],
        'descriptions'  => $texts !== [] ? $texts : [$template['problem']],
        'impact'        => $template['impact'],
        'action'        => $template['action'],
    ];
}

/**
 * @param array<string, mixed> $template
 * @param array{forms: list<string>, problem: string, impact: string, action: string}|null $fallback
 * @return array{forms: list<string>, problem: string, impact: string, action: string}
 */
function normalizeClientTextTemplate(array $template, ?array $fallback = null): array
{
    $fallback ??= [
        'forms'   => ['проблема', 'проблемы', 'проблем'],
        'problem' => 'Во время проверки обнаружена проблема, которая требует внимания.',
        'impact'  => 'Она может мешать работе сайта или ухудшать впечатление посетителей.',
        'action'  => 'Передайте результат специалисту, чтобы уточнить причину и способ исправления.',
    ];
    $forms = $template['forms'] ?? null;

    return [
        'forms' => is_array($forms) && count($forms) === 3
            ? array_map(static fn (mixed $form): string => (string) $form, array_values($forms))
            : $fallback['forms'],
        'problem' => readClientTemplateText($template, 'problem', $fallback['problem']),
        'impact'  => readClientTemplateText($template, 'impact', $fallback['impact']),
        'action'  => readClientTemplateText($template, 'action', $fallback['action']),
    ];
}

/**
 * @param array<string, mixed> $template
 */
function readClientTemplateText(array $template, string $key, string $fallback): string
{
    $value = trim(is_string($template[$key] ?? null) ? $template[$key] : '');

    return $value !== '' ? $value : $fallback;
}

/**
 * Русская форма существительного: 1 проблема, 2 проблемы, 5 проблем, 11 проблем, 21 проблема.
 *
 * @param list<string> $forms
 */
function selectClientCountForm(int $count, array $forms): string
{
    $absolute = abs($count);
    $lastTwo = $absolute % 100;
    if ($lastTwo >= 11 && $lastTwo <= 14) {
        return $forms[2];
    }

    return match ($absolute % 10) {
        1       => $forms[0],
        2, 3, 4 => $forms[1],
        default => $forms[2],
    };
}

function buildClientReportSite(mixed $host): string
{
    $value = trim(is_string($host) ? $host : '');

    return $value !== '' && filter_var($value, FILTER_VALIDATE_IP) === false
        ? $value
        : CLIENT_REPORT_UNKNOWN_SITE;
}

function formatClientReportDate(mixed $value): string
{
    if (!is_string($value) || $value === '') {
        return CLIENT_REPORT_UNKNOWN_DATE;
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);

    return $date !== false ? $date->format(CLIENT_REPORT_DATE_FORMAT) : CLIENT_REPORT_UNKNOWN_DATE;
}
