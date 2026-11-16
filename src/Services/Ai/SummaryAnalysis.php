<?php

declare(strict_types=1);

/**
 * ИИ-задача «Итоговый вывод»: факты проверок + ИИ-находки тасков «Разбор проблемы» и «Проверка вёрстки» → что
 * сломано, насколько серьёзно и план ремонта по шагам. Шаг плана без допустимой ссылки отбрасывается. Итог хранится
 * только в `ai_results.parsed_json` (находок не создаёт: план — не поломка). Без БД и сети: их передаёт шаг
 * `ai_summary` (`DiagnosticRunner.php`).
 */

require_once ROOT_PATH . '/src/Services/FactPacker.php';
require_once ROOT_PATH . '/src/Services/FindingFactory.php';
require_once ROOT_PATH . '/src/Services/Ai/AiTask.php';
require_once ROOT_PATH . '/src/Services/Ai/EvidenceValidator.php';

const AI_TASK_SUMMARY              = 'summary';
const AI_SUMMARY_MODEL_SETTING     = 'model_summary';
const AI_SUMMARY_PROMPT_FILE       = ROOT_PATH . '/config/prompts/summary.txt';
const AI_SUMMARY_MAX_STEPS         = 10;  // как в промпте: лишние отрезаются
const AI_SUMMARY_MAX_AI_FINDINGS   = 60;  // гипотез до 7 + поломок вёрстки до 5 × 10 страниц
const AI_SUMMARY_DETAILS_MAX_CHARS = 300; // объяснение ИИ-находки в пакете
const AI_SUMMARY_DEFAULT_SEVERITY  = 'medium';
const AI_SUMMARY_EVIDENCE_KEYS     = ['finding_ids', 'stack_ids', 'ai_finding_ids'];

/** ИИ-шаги, на результаты которых опирается вывод: код → «без чего» вывод построен, если шаг не выполнен */
const AI_SUMMARY_INPUT_STEPS = [
    'ai_problem' => 'разбора жалобы',
    'ai_visual'  => 'проверки вёрстки',
];
const AI_SUMMARY_STEP_DONE      = 'done';
const AI_SUMMARY_WITHOUT_NOTE   = 'Вывод без %s: эти ИИ-шаги не выполнены.';
const AI_SUMMARY_PROMPT_MISSING = 'Нет файла промпта config/prompts/summary.txt.';

/** Схема ответа: строгий JSON; число шагов ограничивается в коде */
const AI_SUMMARY_SCHEMA = [
    'name'   => 'repair_summary',
    'schema' => [
        'type'                 => 'object',
        'properties'           => [
            'verdict'  => ['type' => 'string'],
            'severity' => ['type' => 'string', 'enum' => FINDING_SEVERITIES],
            'steps'    => [
                'type'  => 'array',
                'items' => [
                    'type'                 => 'object',
                    'properties'           => [
                        'title'          => ['type' => 'string'],
                        'action'         => ['type' => 'string'],
                        'severity'       => ['type' => 'string', 'enum' => FINDING_SEVERITIES],
                        'finding_ids'    => ['type' => 'array', 'items' => ['type' => 'integer']],
                        'stack_ids'      => ['type' => 'array', 'items' => ['type' => 'integer']],
                        'ai_finding_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                    ],
                    'required'             => ['title', 'action', 'severity', 'finding_ids', 'stack_ids',
                        'ai_finding_ids'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required'             => ['verdict', 'severity', 'steps'],
        'additionalProperties' => false,
    ],
];

/**
 * @throws RuntimeException нет файла промпта
 */
function loadSummaryPrompt(string $file = AI_SUMMARY_PROMPT_FILE): string
{
    $prompt = is_file($file) ? file_get_contents($file) : false;
    if ($prompt === false || trim($prompt) === '') {
        throw new RuntimeException(AI_SUMMARY_PROMPT_MISSING);
    }

    return trim($prompt);
}

/**
 * ИИ-находки (гипотезы, поломки вёрстки) для пакета: сначала серьёзные, не больше AI_SUMMARY_MAX_AI_FINDINGS.
 *
 * @param list<array<string, mixed>> $findings из getFindingsByDiagnostic() (находки проверок пропускаются)
 * @param list<array<string, mixed>> $pages    из getCheckedPagesByDiagnostic()
 * @return list<array{id: int, category: string, severity: string, title: string, details: ?string, page: ?string}>
 */
function packAiFindingFacts(array $findings, array $pages): array
{
    $pageUrls = array_column($pages, 'url', 'id');
    $aiFindings = array_values(array_filter(
        $findings,
        static fn (array $finding): bool => ($finding['source'] ?? FINDING_SOURCE_CHECK) === FINDING_SOURCE_AI
            && isset($finding['id'])
    ));
    usort($aiFindings, static function (array $a, array $b): int {
        $rankA = array_search($a['severity'] ?? null, FINDING_SEVERITIES, true);
        $rankB = array_search($b['severity'] ?? null, FINDING_SEVERITIES, true);

        return ($rankA === false ? count(FINDING_SEVERITIES) : $rankA)
            <=> ($rankB === false ? count(FINDING_SEVERITIES) : $rankB);
    });

    return array_map(static function (array $finding) use ($pageUrls): array {
        $pageId = isset($finding['page_id']) ? (int) $finding['page_id'] : null;
        $details = $finding['details'] ?? null;

        return [
            'id'       => (int) $finding['id'],
            'category' => (string) $finding['category'],
            'severity' => (string) $finding['severity'],
            'title'    => truncateFactText((string) $finding['title'], FACT_TEXT_MAX_CHARS),
            'details'  => is_string($details) && $details !== ''
                ? truncateFactText($details, AI_SUMMARY_DETAILS_MAX_CHARS)
                : null,
            'page'     => $pageId !== null && isset($pageUrls[$pageId]) ? (string) $pageUrls[$pageId] : null,
        ];
    }, array_slice($aiFindings, 0, AI_SUMMARY_MAX_AI_FINDINGS));
}

/**
 * Какие ИИ-шаги не выполнены (вывод строится без их результатов): «разбора жалобы», «проверки вёрстки».
 * Шага нет вовсе (старая диагностика) — тоже не выполнен.
 *
 * @param list<array<string, mixed>> $steps строки `diagnostic_steps`
 * @return list<string>
 */
function findSummaryMissingInputs(array $steps): array
{
    $statuses = array_column($steps, 'status', 'step_code');
    $missing = [];
    foreach (AI_SUMMARY_INPUT_STEPS as $code => $label) {
        if (($statuses[$code] ?? null) !== AI_SUMMARY_STEP_DONE) {
            $missing[] = $label;
        }
    }

    return $missing;
}

/**
 * «Вывод без разбора жалобы и проверки вёрстки: эти ИИ-шаги не выполнены.» Всё выполнено → null.
 *
 * @param list<string> $missing из findSummaryMissingInputs()
 */
function buildSummaryWithoutNote(array $missing): ?string
{
    return $missing === [] ? null : sprintf(AI_SUMMARY_WITHOUT_NOTE, implode(' и ', $missing));
}

/**
 * Системный промпт отдельно, жалоба, факты и ИИ-находки — одним JSON во втором сообщении: это данные, не указания.
 *
 * @param array<string, mixed>       $pack       `buildFactPack()['pack']`
 * @param list<array<string, mixed>> $aiFindings из packAiFindingFacts()
 * @return list<array{role: string, content: string}>
 */
function buildSummaryMessages(
    string $systemPrompt,
    string $siteUrl,
    ?string $complaint,
    array $pack,
    array $aiFindings
): array {
    return [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => encodeFactJson([
            'site'        => $siteUrl,
            'complaint'   => $complaint,
            'facts'       => $pack,
            'ai_findings' => $aiFindings,
        ])],
    ];
}

/**
 * Ответ модели → вывод, шаги плана с допустимыми ссылками и число отброшенных. Нет `verdict` (или пустой) или
 * списка `steps` → null (ответ не по схеме). Шаг без заголовка отбрасывается.
 *
 * @param array<string, mixed>     $decoded
 * @param array<string, list<int>> $allowed `finding_ids` / `stack_ids` / `ai_finding_ids` → допустимые id
 * @return array{verdict: string, severity: string, steps: list<array>, rejected: int}|null
 */
function interpretSummaryResponse(array $decoded, array $allowed): ?array
{
    $verdict = is_string($decoded['verdict'] ?? null) ? trim($decoded['verdict']) : '';
    $steps = $decoded['steps'] ?? null;
    if ($verdict === '' || !is_array($steps) || !array_is_list($steps)) {
        return null;
    }

    $normalized = [];
    $untitled = 0;
    foreach (array_slice($steps, 0, AI_SUMMARY_MAX_STEPS) as $step) {
        $item = is_array($step) ? normalizeSummaryStep($step) : null;
        if ($item === null) {
            $untitled++;
            continue;
        }
        $normalized[] = $item;
    }

    $checked = validateAiEvidence($normalized, array_intersect_key($allowed, array_flip(AI_SUMMARY_EVIDENCE_KEYS)));

    return [
        'verdict'  => $verdict,
        'severity' => normalizeSummarySeverity($decoded['severity'] ?? null),
        'steps'    => $checked['items'],
        'rejected' => $checked['rejected'] + $untitled,
    ];
}

/**
 * @param array<string, mixed> $step
 * @return array<string, mixed>|null без заголовка — null
 */
function normalizeSummaryStep(array $step): ?array
{
    $title = is_string($step['title'] ?? null) ? trim($step['title']) : '';
    if ($title === '') {
        return null;
    }

    return [
        'title'          => $title,
        'action'         => is_string($step['action'] ?? null) ? trim($step['action']) : '',
        'severity'       => normalizeSummarySeverity($step['severity'] ?? null),
        'finding_ids'    => $step['finding_ids'] ?? [],
        'stack_ids'      => $step['stack_ids'] ?? [],
        'ai_finding_ids' => $step['ai_finding_ids'] ?? [],
    ];
}

function normalizeSummarySeverity(mixed $severity): string
{
    return in_array($severity, FINDING_SEVERITIES, true) ? $severity : AI_SUMMARY_DEFAULT_SEVERITY;
}

/**
 * «Шагов плана: 5; отброшено без ссылок на факты: 1. Вывод без проверки вёрстки: …»
 */
function buildSummaryStepMessage(int $count, int $rejected, ?string $withoutNote): string
{
    $message = "Шагов плана: {$count}";
    if ($rejected > 0) {
        $message .= "; отброшено без ссылок на факты: {$rejected}";
    }
    $message .= '.';

    return $withoutNote !== null ? $message . ' ' . $withoutNote : $message;
}

/**
 * Итоговый вывод целиком: пакет фактов + ИИ-находки → запрос → `ai_results` (итог в `parsed_json`).
 *
 * @param array{id: int, url: string, problem_text: ?string} $diagnostic
 * @param array{findings: list<array>, stack: list<array>, pages: list<array>, summary: ?array, steps: list<array>} $facts из БД
 * @param callable(array $payload): array $send
 * @param callable(array $record): int    $store
 * @return array{message: string, ai_result_id: int}
 * @throws RuntimeException ошибка API / ответ не по схеме (ответ уже записан в `ai_results`)
 */
function runSummaryAnalysis(
    array $diagnostic,
    array $facts,
    string $model,
    #[\SensitiveParameter] string $key,
    string $systemPrompt,
    callable $send,
    callable $store
): array {
    $complaint = trim((string) ($diagnostic['problem_text'] ?? ''));
    $packed = buildFactPack($facts['findings'], $facts['stack'], $facts['pages'], $facts['summary']);
    $aiFindings = packAiFindingFacts($facts['findings'], $facts['pages']);
    $allowed = [
        'finding_ids'    => $packed['finding_ids'],
        'stack_ids'      => $packed['stack_ids'],
        'ai_finding_ids' => array_column($aiFindings, 'id'),
    ];

    $result = runAiTask(
        (int) $diagnostic['id'],
        AI_TASK_SUMMARY,
        $model,
        buildSummaryMessages(
            $systemPrompt,
            (string) $diagnostic['url'],
            $complaint !== '' ? $complaint : null,
            $packed['pack'],
            $aiFindings
        ),
        AI_SUMMARY_SCHEMA,
        $key,
        $send,
        static fn (array $decoded): ?array => interpretSummaryResponse($decoded, $allowed),
        $store
    );

    return [
        'message'      => buildSummaryStepMessage(
            count($result['parsed']['steps']),
            $result['parsed']['rejected'],
            buildSummaryWithoutNote(findSummaryMissingInputs($facts['steps']))
        ),
        'ai_result_id' => $result['ai_result_id'],
    ];
}
