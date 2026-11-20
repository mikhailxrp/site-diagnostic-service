<?php

declare(strict_types=1);

/**
 * Таблица `ai_results`: ответы ИИ по задачам диагностики. Ключ API сюда не попадает — запись готовит
 * `buildAiResultRecord()` (`src/Services/AiClient.php`).
 */

require_once ROOT_PATH . '/src/Core/Database.php';

/**
 * @param array{task: string, model: string, status: string, request_json: ?array, response_text: ?string,
 *              parsed_json: ?array, error_message: ?string, tokens_in: ?int, tokens_out: ?int} $result
 */
function insertAiResult(int $diagnosticId, array $result): int
{
    $pdo = getPdo();
    $statement = $pdo->prepare(
        'INSERT INTO ai_results
            (diagnostic_id, task, model, status, request_json, response_text, parsed_json, error_message,
             tokens_in, tokens_out)
         VALUES
            (:diagnostic_id, :task, :model, :status, :request_json, :response_text, :parsed_json, :error_message,
             :tokens_in, :tokens_out)'
    );
    $statement->execute([
        'diagnostic_id' => $diagnosticId,
        'task'          => $result['task'],
        'model'         => $result['model'],
        'status'        => $result['status'],
        'request_json'  => encodeAiResultJson($result['request_json'] ?? null),
        'response_text' => $result['response_text'] ?? null,
        'parsed_json'   => encodeAiResultJson($result['parsed_json'] ?? null),
        'error_message' => $result['error_message'] ?? null,
        'tokens_in'     => $result['tokens_in'] ?? null,
        'tokens_out'    => $result['tokens_out'] ?? null,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Ответы ИИ диагностики по порядку записи. `request_json` / `parsed_json` — массивы или null.
 *
 * @return list<array<string, mixed>>
 */
function getAiResultsByDiagnostic(int $diagnosticId): array
{
    $statement = getPdo()->prepare(
        'SELECT id, diagnostic_id, task, model, status, request_json, response_text, parsed_json, error_message,
                tokens_in, tokens_out, created_at
         FROM ai_results
         WHERE diagnostic_id = :diagnostic_id
         ORDER BY id'
    );
    $statement->execute(['diagnostic_id' => $diagnosticId]);

    return array_map(
        static function (array $row): array {
            foreach (['request_json', 'parsed_json'] as $column) {
                $decoded = is_string($row[$column]) ? json_decode($row[$column], true) : null;
                $row[$column] = is_array($decoded) ? $decoded : null;
            }

            return $row;
        },
        $statement->fetchAll()
    );
}

function encodeAiResultJson(?array $value): ?string
{
    return $value === null
        ? null
        : json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
}
