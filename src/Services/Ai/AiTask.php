<?php

declare(strict_types=1);

/**
 * Общий запуск ИИ-задачи диагностики (разбор проблемы, вёрстка, итоговый вывод): запрос → разбор ответа под
 * задачу → запись в `ai_results` → результат или исключение. Ответ записывается всегда, и неудачный тоже
 * (сырой ответ и причина — для разбора). Сеть и запись в БД передаются параметрами: тесты без сети и БД.
 */

require_once ROOT_PATH . '/src/Services/AiClient.php';

const AI_RESULT_STATUS_OK = 'ok';

/**
 * @param list<array{role: string, content: string|list<array>}> $messages
 * @param array{name: string, schema: array}                     $schema
 * @param callable(array $payload): array                        $send      `sendAiRequest()` с сетью
 * @param callable(array $decoded): ?array                        $interpret разбор под задачу (то, что пойдёт в
 *                                                                          `parsed_json`); null — не по схеме
 * @param callable(array $record): int                            $store     запись в `ai_results` → id
 * @param list<?int>                                             $screenshotIds id картинок запроса по порядку
 * @return array{ai_result_id: int, parsed: array, tokens_in: ?int, tokens_out: ?int}
 * @throws RuntimeException ошибка API или ответ не по схеме — после записи в `ai_results`; текст без ключа
 */
function runAiTask(
    int $diagnosticId,
    string $task,
    string $model,
    array $messages,
    array $schema,
    #[\SensitiveParameter] string $key,
    callable $send,
    callable $interpret,
    callable $store,
    array $screenshotIds = []
): array {
    $payload = buildAiPayload($model, $messages, $schema);
    $response = $send($payload);

    $parsed = null;
    if ($response['ok']) {
        $decoded = decodeAiJson((string) $response['content']);
        $parsed = $decoded !== null ? $interpret($decoded) : null;
        if ($parsed === null) {
            $response['ok'] = false;
            $response['error'] = AI_ERROR_BAD_SCHEMA;
        }
    }

    $record = buildAiResultRecord($diagnosticId, $task, $model, $payload, $response, $parsed, $key, $screenshotIds);
    $aiResultId = $store($record);

    if ($record['status'] !== AI_RESULT_STATUS_OK || $parsed === null) {
        throw new RuntimeException((string) ($record['error_message'] ?? AI_ERROR_NO_RESPONSE));
    }

    return [
        'ai_result_id' => $aiResultId,
        'parsed'       => $parsed,
        'tokens_in'    => $record['tokens_in'],
        'tokens_out'   => $record['tokens_out'],
    ];
}

/**
 * Настоящая отправка в RouterAI (`ROUTERAI_BASE_URL`) с повторами и паузами.
 *
 * @return callable(array $payload): array
 */
function makeAiSender(#[\SensitiveParameter] string $key): callable
{
    $api = makeRouterAiTransport();
    $sleep = makeAiSleeper();

    return static fn (array $payload): array => sendAiRequest(ROUTERAI_BASE_URL, $payload, $key, $api, $sleep);
}
