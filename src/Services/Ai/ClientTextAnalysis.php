<?php

declare(strict_types=1);

/**
 * ИИ-задача «Текст для клиента»: технический итог и находки → безопасное краткое резюме и простой текст,
 * привязанный только к id переданных находок. БД и сеть передаются вызывающим кодом.
 */

require_once ROOT_PATH . '/src/Services/FactPacker.php';
require_once ROOT_PATH . '/src/Services/Ai/AiTask.php';

const AI_TASK_CLIENT                  = 'client_text';
const AI_CLIENT_MODEL_SETTING         = 'model_summary';
const AI_CLIENT_PROMPT_FILE           = ROOT_PATH . '/config/prompts/client.txt';
const AI_CLIENT_MAX_FINDINGS          = 100;
const AI_CLIENT_SUMMARY_MAX_CHARS     = 1200;
const AI_CLIENT_FINDING_TEXT_MAX_CHARS = 800;
const AI_CLIENT_PROMPT_MISSING        = 'Нет файла промпта config/prompts/client.txt.';

const AI_CLIENT_SCHEMA = [
    'name'   => 'client_texts',
    'schema' => [
        'type'                 => 'object',
        'properties'           => [
            'summary'  => ['type' => 'string'],
            'findings' => [
                'type'  => 'array',
                'items' => [
                    'type'                 => 'object',
                    'properties'           => [
                        'finding_id' => ['type' => 'integer'],
                        'text'       => ['type' => 'string'],
                    ],
                    'required'             => ['finding_id', 'text'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required'             => ['summary', 'findings'],
        'additionalProperties' => false,
    ],
];

/**
 * @throws RuntimeException нет файла промпта
 */
function loadClientPrompt(string $file = AI_CLIENT_PROMPT_FILE): string
{
    $prompt = is_file($file) ? file_get_contents($file) : false;
    if ($prompt === false || trim($prompt) === '') {
        throw new RuntimeException(AI_CLIENT_PROMPT_MISSING);
    }

    return trim($prompt);
}

/**
 * Находки для запроса: от серьёзных к информационным, не больше именованного лимита.
 * Адреса и evidence не отправляются — для клиентского объяснения достаточно сути находки.
 *
 * @param list<array<string, mixed>> $findings
 * @return list<array{id: int, category: string, severity: string, title: string, details: ?string}>
 */
function packClientFindingFacts(array $findings): array
{
    $packed = [];
    foreach (array_slice($findings, 0, AI_CLIENT_MAX_FINDINGS) as $finding) {
        $id = filter_var($finding['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            continue;
        }
        $details = $finding['details'] ?? null;
        $packed[] = [
            'id'       => (int) $id,
            'category' => (string) ($finding['category'] ?? ''),
            'severity' => (string) ($finding['severity'] ?? ''),
            'title'    => truncateFactText((string) ($finding['title'] ?? ''), FACT_TEXT_MAX_CHARS),
            'details'  => is_string($details) && $details !== ''
                ? truncateFactText($details, FACT_TEXT_MAX_CHARS)
                : null,
        ];
    }

    return $packed;
}

/**
 * Системный промпт отдельно, технический итог и находки — JSON-данные во втором сообщении.
 *
 * @param list<array<string, mixed>> $findings
 * @return list<array{role: string, content: string}>
 */
function buildClientMessages(string $systemPrompt, ?string $technicalSummary, array $findings): array
{
    return [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => encodeFactJson([
            'technical_summary' => $technicalSummary,
            'findings'           => $findings,
        ])],
    ];
}

/**
 * Отбрасывает текст, способный вернуть в клиентский отчёт технические подробности.
 */
function filterClientAiText(mixed $value, int $maxChars): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $text = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '');
    if ($text === '' || containsClientTechnicalDetail($text)) {
        return null;
    }

    return mb_strlen($text) > $maxChars ? mb_substr($text, 0, $maxChars) : $text;
}

function containsClientTechnicalDetail(string $text): bool
{
    $patterns = [
        '/\b(?:https?|ftp):\/\/|\bwww\.|\b(?:[a-z0-9-]+\.)+[a-z]{2,}(?:\/[^\s]*)?/iu',
        '/\b[A-Za-z]:[\\\\\/]|\\\\\\\\[^\\s]+|(?<!\w)\/(?:[\w.-]+\/)+[\w.-]+/u',
        '/(?:^|[\s"\'(])(?:\.env|\.git(?:\/HEAD)?|\.htaccess|error_log|[\w.-]+\.(?:php|js|css|log|ini|conf|sql|txt|xml))(?:\b|:)/iu',
        '/\bv?\d+\.\d+(?:\.\d+)*(?:[-+][A-Za-z0-9.-]+)?\b/iu',
        '/\bHTTP(?:\/\d(?:\.\d)?)?\s*[1-5]\d{2}\b|\b(?:код(?:\s+ответа)?|статус)\s*[1-5]\d{2}\b|\b[1-5]\d{2}\s+(?:код|status)\b/iu',
        '/\b(?:fatal\s+error|parse\s+error|syntax\s+error|uncaught|exception|stack\s+trace|sqlstate|warning|notice)\b/iu',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text) === 1) {
            return true;
        }
    }

    if (preg_match_all('/(?<![\d.])(?:\d{1,3}\.){3}\d{1,3}(?![\d.])/', $text, $matches) > 0) {
        foreach ($matches[0] as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return true;
            }
        }
    }

    return false;
}

/**
 * @param array<string, mixed> $decoded
 * @param list<int>            $allowedFindingIds
 * @return array{summary: ?string, texts: list<array{finding_id: int, text: string}>, rejected: int}|null
 */
function interpretClientResponse(array $decoded, array $allowedFindingIds): ?array
{
    if (!array_key_exists('summary', $decoded) || !is_array($decoded['findings'] ?? null)
        || !array_is_list($decoded['findings'])) {
        return null;
    }

    $allowed = array_flip($allowedFindingIds);
    $seen = [];
    $texts = [];
    $rejected = max(0, count($decoded['findings']) - AI_CLIENT_MAX_FINDINGS);
    foreach (array_slice($decoded['findings'], 0, AI_CLIENT_MAX_FINDINGS) as $item) {
        if (!is_array($item)) {
            $rejected++;
            continue;
        }
        $id = $item['finding_id'] ?? null;
        if (is_string($id) && ctype_digit($id)) {
            $id = (int) $id;
        }
        $text = filterClientAiText($item['text'] ?? null, AI_CLIENT_FINDING_TEXT_MAX_CHARS);
        if (!is_int($id) || !isset($allowed[$id]) || isset($seen[$id]) || $text === null) {
            $rejected++;
            continue;
        }
        $seen[$id] = true;
        $texts[] = ['finding_id' => $id, 'text' => $text];
    }

    return [
        'summary'  => filterClientAiText($decoded['summary'], AI_CLIENT_SUMMARY_MAX_CHARS),
        'texts'    => $texts,
        'rejected' => $rejected,
    ];
}

function buildClientStepMessage(int $count, int $rejected): string
{
    $message = "Клиентских текстов: {$count}";
    if ($rejected > 0) {
        $message .= "; отброшено: {$rejected}";
    }

    return $message . '.';
}

/**
 * Последняя запись ИИ-задачи текущего запуска шага. Старые ответы после перезапуска не подходят.
 *
 * @param list<array<string, mixed>> $aiResults
 * @return array<string, mixed>|null
 */
function findCurrentClientAiResult(array $aiResults, string $task, mixed $stepStartedAt): ?array
{
    $startedAt = is_string($stepStartedAt) ? strtotime($stepStartedAt) : false;
    if ($startedAt === false) {
        return null;
    }

    $result = null;
    foreach ($aiResults as $aiResult) {
        $createdAt = is_string($aiResult['created_at'] ?? null) ? strtotime($aiResult['created_at']) : false;
        if (($aiResult['task'] ?? null) === $task && $createdAt !== false && $createdAt >= $startedAt) {
            $result = $aiResult;
        }
    }

    return $result;
}

/**
 * @param list<array<string, mixed>> $steps
 * @return array<string, mixed>|null
 */
function findClientDiagnosticStep(array $steps, string $code): ?array
{
    $found = null;
    foreach ($steps as $step) {
        if (($step['step_code'] ?? null) === $code) {
            $found = $step;
        }
    }

    return $found;
}

/**
 * @param list<array<string, mixed>> $findings
 * @param callable(array $payload): array $send
 * @param callable(array $record): int    $store
 * @return array{summary: ?string, texts: list<array{finding_id: int, text: string}>, message: string,
 *               ai_result_id: int}
 */
function runClientTextAnalysis(
    int $diagnosticId,
    array $findings,
    ?string $technicalSummary,
    string $model,
    #[\SensitiveParameter] string $key,
    string $systemPrompt,
    callable $send,
    callable $store
): array {
    $packed = packClientFindingFacts($findings);
    $allowedIds = array_column($packed, 'id');
    $result = runAiTask(
        $diagnosticId,
        AI_TASK_CLIENT,
        $model,
        buildClientMessages($systemPrompt, $technicalSummary, $packed),
        AI_CLIENT_SCHEMA,
        $key,
        $send,
        static fn (array $decoded): ?array => interpretClientResponse($decoded, $allowedIds),
        $store
    );

    return [
        'summary'      => $result['parsed']['summary'],
        'texts'        => $result['parsed']['texts'],
        'message'      => buildClientStepMessage(count($result['parsed']['texts']), $result['parsed']['rejected']),
        'ai_result_id' => $result['ai_result_id'],
    ];
}
