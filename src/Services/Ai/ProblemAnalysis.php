<?php

declare(strict_types=1);

/**
 * ИИ-задача «Разбор проблемы»: жалоба клиента и пакет фактов → гипотезы причин со ссылками на находки и стек.
 * Гипотеза без допустимой ссылки отбрасывается; оставшиеся становятся находками `hypothesis` (`source = ai`).
 * Без БД и сети: их передаёт шаг `ai_problem` (`DiagnosticRunner.php`).
 */

require_once ROOT_PATH . '/src/Services/FactPacker.php';
require_once ROOT_PATH . '/src/Services/FindingFactory.php';
require_once ROOT_PATH . '/src/Services/Ai/AiTask.php';
require_once ROOT_PATH . '/src/Services/Ai/EvidenceValidator.php';

const AI_TASK_PROBLEM           = 'problem';
const AI_PROBLEM_MODEL_SETTING  = 'model_problem';
const AI_PROBLEM_PROMPT_FILE    = ROOT_PATH . '/config/prompts/problem.txt';
const AI_PROBLEM_MAX_HYPOTHESES = 7; // как в промпте: лишние отрезаются
const AI_PROBLEM_EVIDENCE_KEYS  = ['finding_ids', 'stack_ids'];
const AI_PROBLEM_CATEGORY       = 'hypothesis';
const AI_PROBLEM_HOW_TO_CHECK   = 'Как проверить: ';

const AI_PROBLEM_NO_COMPLAINT_NOTE = 'Описание проблемы не указано — гипотезы только по фактам.';
const AI_PROBLEM_PROMPT_MISSING    = 'Нет файла промпта config/prompts/problem.txt.';

/** Схема ответа: строгий JSON; число гипотез ограничивается в коде (maxItems строгий режим понимает не везде) */
const AI_PROBLEM_SCHEMA = [
    'name'   => 'problem_hypotheses',
    'schema' => [
        'type'                 => 'object',
        'properties'           => [
            'hypotheses' => [
                'type'  => 'array',
                'items' => [
                    'type'                 => 'object',
                    'properties'           => [
                        'title'        => ['type' => 'string'],
                        'explanation'  => ['type' => 'string'],
                        'how_to_check' => ['type' => 'string'],
                        'severity'     => ['type' => 'string', 'enum' => FINDING_SEVERITIES],
                        'finding_ids'  => ['type' => 'array', 'items' => ['type' => 'integer']],
                        'stack_ids'    => ['type' => 'array', 'items' => ['type' => 'integer']],
                    ],
                    'required'             => ['title', 'explanation', 'how_to_check', 'severity', 'finding_ids',
                        'stack_ids'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required'             => ['hypotheses'],
        'additionalProperties' => false,
    ],
];

/**
 * @throws RuntimeException нет файла промпта
 */
function loadProblemPrompt(string $file = AI_PROBLEM_PROMPT_FILE): string
{
    $prompt = is_file($file) ? file_get_contents($file) : false;
    if ($prompt === false || trim($prompt) === '') {
        throw new RuntimeException(AI_PROBLEM_PROMPT_MISSING);
    }

    return trim($prompt);
}

/**
 * Системный промпт отдельно, жалоба и факты — одним JSON во втором сообщении: это данные, не указания.
 *
 * @param array<string, mixed> $pack `buildFactPack()['pack']`
 * @return list<array{role: string, content: string}>
 */
function buildProblemMessages(string $systemPrompt, string $siteUrl, ?string $complaint, array $pack): array
{
    return [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => encodeFactJson([
            'site'      => $siteUrl,
            'complaint' => $complaint,
            'facts'     => $pack,
        ])],
    ];
}

/**
 * Ответ модели → гипотезы с допустимыми ссылками и число отброшенных. Нет списка `hypotheses` → null
 * (ответ не по схеме). Гипотеза без заголовка отбрасывается.
 *
 * @param array<string, mixed> $decoded
 * @param list<int>            $findingIds id находок из пакета
 * @param list<int>            $stackIds   id стека из пакета
 * @return array{hypotheses: list<array{title: string, explanation: string, how_to_check: string,
 *               severity: string, finding_ids: list<int>, stack_ids: list<int>}>, rejected: int}|null
 */
function interpretProblemResponse(array $decoded, array $findingIds, array $stackIds): ?array
{
    $hypotheses = $decoded['hypotheses'] ?? null;
    if (!is_array($hypotheses) || !array_is_list($hypotheses)) {
        return null;
    }

    $normalized = [];
    $untitled = 0;
    foreach (array_slice($hypotheses, 0, AI_PROBLEM_MAX_HYPOTHESES) as $hypothesis) {
        $item = is_array($hypothesis) ? normalizeProblemHypothesis($hypothesis) : null;
        if ($item === null) {
            $untitled++;
            continue;
        }
        $normalized[] = $item;
    }

    $checked = validateAiEvidence($normalized, [
        'finding_ids' => $findingIds,
        'stack_ids'   => $stackIds,
    ]);

    return ['hypotheses' => $checked['items'], 'rejected' => $checked['rejected'] + $untitled];
}

/**
 * @param array<string, mixed> $hypothesis
 * @return array<string, mixed>|null без заголовка — null
 */
function normalizeProblemHypothesis(array $hypothesis): ?array
{
    $title = trim((string) (is_string($hypothesis['title'] ?? null) ? $hypothesis['title'] : ''));
    if ($title === '') {
        return null;
    }
    $severity = $hypothesis['severity'] ?? null;

    return [
        'title'        => $title,
        'explanation'  => is_string($hypothesis['explanation'] ?? null) ? trim($hypothesis['explanation']) : '',
        'how_to_check' => is_string($hypothesis['how_to_check'] ?? null) ? trim($hypothesis['how_to_check']) : '',
        'severity'     => in_array($severity, FINDING_SEVERITIES, true) ? $severity : FINDING_DEFAULT_SEVERITY[AI_PROBLEM_CATEGORY],
        'finding_ids'  => $hypothesis['finding_ids'] ?? [],
        'stack_ids'    => $hypothesis['stack_ids'] ?? [],
    ];
}

/**
 * Гипотезы → находки `hypothesis`: объяснение и «Как проверить» — в `details`, ссылки — в `evidence`.
 *
 * @param list<array<string, mixed>> $hypotheses из interpretProblemResponse()
 * @return list<array<string, mixed>>
 */
function buildProblemFindings(array $hypotheses, int $aiResultId): array
{
    return array_map(static function (array $hypothesis) use ($aiResultId): array {
        $details = implode("\n\n", array_filter([
            $hypothesis['explanation'],
            $hypothesis['how_to_check'] !== '' ? AI_PROBLEM_HOW_TO_CHECK . $hypothesis['how_to_check'] : '',
        ], static fn (string $part): bool => $part !== ''));

        return makeAiFinding(
            AI_PROBLEM_CATEGORY,
            $hypothesis['title'],
            $details !== '' ? $details : null,
            $hypothesis['severity'],
            array_intersect_key($hypothesis, array_flip(AI_PROBLEM_EVIDENCE_KEYS)),
            $aiResultId
        );
    }, $hypotheses);
}

/**
 * «Гипотез: 3; отброшено без ссылок на факты: 1. Описание проблемы не указано — …»
 */
function buildProblemStepMessage(int $count, int $rejected, bool $hasComplaint): string
{
    $message = "Гипотез: {$count}";
    if ($rejected > 0) {
        $message .= "; отброшено без ссылок на факты: {$rejected}";
    }
    $message .= '.';

    return $hasComplaint ? $message : $message . ' ' . AI_PROBLEM_NO_COMPLAINT_NOTE;
}

/**
 * Разбор проблемы целиком: пакет фактов → запрос → `ai_results` → находки.
 *
 * @param array{id: int, url: string, problem_text: ?string} $diagnostic
 * @param array{findings: list<array>, stack: list<array>, pages: list<array>, summary: ?array} $facts из БД
 * @param callable(array $payload): array $send
 * @param callable(array $record): int    $store
 * @return array{findings: list<array>, message: string, ai_result_id: int}
 * @throws RuntimeException ошибка API / ответ не по схеме (ответ уже записан в `ai_results`)
 */
function runProblemAnalysis(
    array $diagnostic,
    array $facts,
    string $model,
    #[\SensitiveParameter] string $key,
    string $systemPrompt,
    callable $send,
    callable $store
): array {
    $complaint = trim((string) ($diagnostic['problem_text'] ?? ''));
    $complaint = $complaint !== '' ? $complaint : null;
    $packed = buildFactPack($facts['findings'], $facts['stack'], $facts['pages'], $facts['summary']);

    $result = runAiTask(
        (int) $diagnostic['id'],
        AI_TASK_PROBLEM,
        $model,
        buildProblemMessages($systemPrompt, (string) $diagnostic['url'], $complaint, $packed['pack']),
        AI_PROBLEM_SCHEMA,
        $key,
        $send,
        static fn (array $decoded): ?array => interpretProblemResponse(
            $decoded,
            $packed['finding_ids'],
            $packed['stack_ids']
        ),
        $store
    );

    $hypotheses = $result['parsed']['hypotheses'];

    return [
        'findings'     => buildProblemFindings($hypotheses, $result['ai_result_id']),
        'message'      => buildProblemStepMessage(count($hypotheses), $result['parsed']['rejected'], $complaint !== null),
        'ai_result_id' => $result['ai_result_id'],
    ];
}
