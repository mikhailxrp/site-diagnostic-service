<?php

declare(strict_types=1);

/**
 * Клиент RouterAI (OpenAI-совместимый `POST {base}/chat/completions`) без БД: сборка запроса, картинки в base64,
 * повторы при 429 / 5xx / сбое сети, разбор ответа, понятные причины ошибок, запись для `ai_results`.
 * Сеть и пауза передаются параметрами (`$api`, `$sleep`), чтобы тесты не ходили в интернет и не ждали.
 *
 * Ключ — только заголовком `Authorization`: в теле и адресе его нет. Тексты ошибок дополнительно проходят через
 * `redactAiKey()`. Рассуждения модели урезаны (`reasoning_effort = low`): проверено 16.11.2026 — RouterAI
 * понимает именно это поле, а `reasoning.effort` не действует.
 */

require_once ROOT_PATH . '/src/Services/HttpClient.php';

const AI_CHAT_PATH             = '/chat/completions';
const AI_KEY_HEADER            = 'Authorization: Bearer ';
const AI_KEY_PATTERN           = '/^[A-Za-z0-9_\-.]+$/'; // без пробелов и переводов строк — иначе чужой заголовок
const AI_TIMEOUT_SECONDS       = 180; // ответ по большому пакету фактов; повтор после тайм-аута не делается
const AI_CHECK_TIMEOUT_SECONDS = 60;
const AI_MAX_TOKENS            = 8000;
const AI_CHECK_MAX_TOKENS      = 100;
const AI_REASONING_EFFORT      = 'low';
const AI_MAX_ATTEMPTS          = 3;
const AI_RETRY_DELAYS_SECONDS  = [2, 5]; // пауза перед 2-й и 3-й попыткой

const AI_HTTP_OK                = 200;
const AI_HTTP_UNAUTHORIZED      = 401;
const AI_HTTP_PAYMENT_REQUIRED  = 402;
const AI_HTTP_TOO_MANY_REQUESTS = 429;
const AI_HTTP_SERVER_ERROR_MIN  = 500;

const AI_ERROR_MAX_LENGTH     = 500;
const AI_REDACTED             = '[REDACTED]';
const AI_IMAGE_PLACEHOLDER    = '[image]';
const AI_IMAGE_PART_TYPE      = 'image_url';
const AI_FINISH_REASON_LENGTH = 'length';
const AI_MS_PER_SECOND        = 1000;

const AI_ERROR_NO_KEY        = 'Ключ ROUTERAI_API_KEY не задан в .env.';
const AI_ERROR_BAD_KEY       = 'Ключ ROUTERAI_API_KEY содержит недопустимые символы.';
const AI_ERROR_NO_RESPONSE   = 'RouterAI не ответил.';
const AI_ERROR_TIMEOUT       = 'RouterAI не ответил вовремя (%d с).';
const AI_ERROR_UNAUTHORIZED  = 'RouterAI отклонил ключ API (401). Проверьте ROUTERAI_API_KEY в .env.';
const AI_ERROR_NO_FUNDS      = 'RouterAI: недостаточно средств на балансе (402).';
const AI_ERROR_RATE_LIMIT    = 'RouterAI: превышен лимит запросов (429).';
const AI_ERROR_NOT_JSON      = 'Ответ RouterAI — не JSON.';
const AI_ERROR_EMPTY         = 'Модель вернула пустой ответ.';
const AI_ERROR_TRUNCATED     = 'Ответ модели обрезан: не хватило предела max_tokens.';
const AI_ERROR_BAD_SCHEMA    = 'Модель ответила не по схеме JSON.';

/** Схема ответа тестового запроса «Проверить подключение» */
const AI_CHECK_SCHEMA = [
    'name'   => 'connection_check',
    'schema' => [
        'type'                 => 'object',
        'properties'           => ['ok' => ['type' => 'boolean']],
        'required'             => ['ok'],
        'additionalProperties' => false,
    ],
];
const AI_CHECK_MESSAGES = [
    ['role' => 'system', 'content' => 'Ты проверяешь связь с API. Отвечай только JSON по схеме.'],
    ['role' => 'user', 'content' => 'Проверка связи. Верни {"ok": true}.'],
];

function buildAiChatUrl(string $baseUrl): string
{
    return rtrim($baseUrl, '/') . AI_CHAT_PATH;
}

/**
 * Пустой ключ — AI_ERROR_NO_KEY, недопустимые символы — AI_ERROR_BAD_KEY, иначе null.
 */
function validateAiKey(string $key): ?string
{
    return match (true) {
        $key === ''                               => AI_ERROR_NO_KEY,
        preg_match(AI_KEY_PATTERN, $key) !== 1    => AI_ERROR_BAD_KEY,
        default                                   => null,
    };
}

/**
 * @return list<string>
 */
function buildAiHeaders(string $key): array
{
    return [AI_KEY_HEADER . $key];
}

/**
 * Тело запроса. `$schema` — `['name' => …, 'schema' => JSON Schema]`: ответ строго по схеме.
 *
 * @param list<array{role: string, content: string|list<array>}> $messages
 * @param ?array{name: string, schema: array} $schema
 * @return array<string, mixed>
 */
function buildAiPayload(string $model, array $messages, ?array $schema, int $maxTokens = AI_MAX_TOKENS): array
{
    $payload = [
        'model'            => $model,
        'messages'         => $messages,
        'max_tokens'       => $maxTokens,
        'reasoning_effort' => AI_REASONING_EFFORT,
    ];
    if ($schema !== null) {
        $payload['response_format'] = [
            'type'        => 'json_schema',
            'json_schema' => ['name' => $schema['name'], 'strict' => true, 'schema' => $schema['schema']],
        ];
    }

    return $payload;
}

/**
 * Кусок сообщения с картинкой JPEG (base64 в `data:`-адресе).
 *
 * @return array{type: string, image_url: array{url: string}}
 */
function buildAiImagePart(string $jpegBytes): array
{
    return [
        'type'      => AI_IMAGE_PART_TYPE,
        'image_url' => ['url' => 'data:image/jpeg;base64,' . base64_encode($jpegBytes)],
    ];
}

/**
 * Запрос с повторами при 429 / 5xx / сбое сети (не после тайм-аута: три тайм-аута подряд подвесили бы воркер).
 *
 * @param callable(string $url, array $payload, list<string> $headers, int $timeout):
 *        array{status: ?int, body: string, error: ?string} $api
 * @param callable(int $seconds): void $sleep
 * @return array{ok: bool, status: ?int, body: string, content: ?string, model: ?string, tokens_in: ?int,
 *               tokens_out: ?int, error: ?string, attempts: int, elapsed_ms: int}
 */
function sendAiRequest(
    string $baseUrl,
    array $payload,
    string $key,
    callable $api,
    callable $sleep,
    int $timeoutSeconds = AI_TIMEOUT_SECONDS
): array {
    $keyError = validateAiKey($key);
    if ($keyError !== null) {
        return buildAiFailure(null, '', $keyError, 0, 0);
    }

    $started = hrtime(true);
    $attempt = 0;
    while (true) {
        $attempt++;
        $response = $api(buildAiChatUrl($baseUrl), $payload, buildAiHeaders($key), $timeoutSeconds);
        $status = $response['status'] ?? null;
        $curlError = $response['error'] ?? null;
        $body = (string) ($response['body'] ?? '');

        $retry = $attempt < AI_MAX_ATTEMPTS && isRetryableAiResponse($status, $curlError);
        if (!$retry) {
            break;
        }
        $sleep(AI_RETRY_DELAYS_SECONDS[$attempt - 1] ?? AI_RETRY_DELAYS_SECONDS[count(AI_RETRY_DELAYS_SECONDS) - 1]);
    }
    $elapsedMs = intdiv(hrtime(true) - $started, 1_000_000);

    if ($curlError !== null || $status !== AI_HTTP_OK) {
        $reason = redactAiKey(describeAiError($status, $body, $curlError, $timeoutSeconds), $key);

        return buildAiFailure($status, $body, $reason, $attempt, $elapsedMs);
    }

    $parsed = parseAiResponse($body);
    if ($parsed['error'] !== null) {
        return array_replace(
            buildAiFailure($status, $body, redactAiKey($parsed['error'], $key), $attempt, $elapsedMs),
            ['model' => $parsed['model'], 'tokens_in' => $parsed['tokens_in'], 'tokens_out' => $parsed['tokens_out']]
        );
    }

    return [
        'ok'         => true,
        'status'     => $status,
        'body'       => $body,
        'content'    => $parsed['content'],
        'model'      => $parsed['model'],
        'tokens_in'  => $parsed['tokens_in'],
        'tokens_out' => $parsed['tokens_out'],
        'error'      => null,
        'attempts'   => $attempt,
        'elapsed_ms' => $elapsedMs,
    ];
}

/**
 * @return array{ok: false, status: ?int, body: string, content: null, model: ?string, tokens_in: ?int,
 *               tokens_out: ?int, error: string, attempts: int, elapsed_ms: int}
 */
function buildAiFailure(?int $status, string $body, string $error, int $attempts, int $elapsedMs): array
{
    return [
        'ok'         => false,
        'status'     => $status,
        'body'       => $body,
        'content'    => null,
        'model'      => null,
        'tokens_in'  => null,
        'tokens_out' => null,
        'error'      => $error,
        'attempts'   => $attempts,
        'elapsed_ms' => $elapsedMs,
    ];
}

/**
 * Повтор имеет смысл при 429, 5xx и сбое сети без ответа, но не после тайм-аута curl.
 */
function isRetryableAiResponse(?int $status, ?string $curlError): bool
{
    if ($curlError !== null) {
        return !isAiTimeoutError($curlError);
    }

    return $status === null || $status === AI_HTTP_TOO_MANY_REQUESTS || $status >= AI_HTTP_SERVER_ERROR_MIN;
}

/**
 * Тайм-аут curl: `httpApiRequest()` пишет в текст ошибки «(curl 28)».
 */
function isAiTimeoutError(string $curlError): bool
{
    return str_contains($curlError, '(curl ' . CURL_ERROR_TIMEOUT . ')');
}

/**
 * Причина неудачного запроса: тайм-аут, сбой curl, 401 / 402 / 429, текст `error.message` RouterAI или код ответа.
 */
function describeAiError(?int $status, string $body, ?string $curlError, int $timeoutSeconds): string
{
    if ($curlError !== null) {
        return isAiTimeoutError($curlError)
            ? sprintf(AI_ERROR_TIMEOUT, $timeoutSeconds)
            : limitAiError('RouterAI: ' . $curlError);
    }

    $message = extractAiErrorMessage($body);

    return match ($status) {
        null                      => AI_ERROR_NO_RESPONSE,
        AI_HTTP_UNAUTHORIZED      => AI_ERROR_UNAUTHORIZED,
        AI_HTTP_PAYMENT_REQUIRED  => AI_ERROR_NO_FUNDS,
        AI_HTTP_TOO_MANY_REQUESTS => AI_ERROR_RATE_LIMIT,
        default                   => limitAiError("RouterAI: HTTP {$status}" . ($message !== '' ? ': ' . $message : '.')),
    };
}

function extractAiErrorMessage(string $body): string
{
    $json = json_decode($body, true);

    return is_array($json) && is_string($json['error']['message'] ?? null) ? trim($json['error']['message']) : '';
}

function limitAiError(string $text): string
{
    return mb_substr($text, 0, AI_ERROR_MAX_LENGTH);
}

function redactAiKey(string $text, string $key): string
{
    return $key === '' ? $text : str_replace($key, AI_REDACTED, $text);
}

/**
 * Ответ 200 → текст модели, имя модели, токены. Ошибка в теле, пустой ответ, обрыв по `length` → `error`.
 *
 * @return array{content: ?string, model: ?string, tokens_in: ?int, tokens_out: ?int, finish_reason: ?string,
 *               error: ?string}
 */
function parseAiResponse(string $body): array
{
    $json = json_decode($body, true);
    if (!is_array($json)) {
        return ['content' => null, 'model' => null, 'tokens_in' => null, 'tokens_out' => null,
                'finish_reason' => null, 'error' => AI_ERROR_NOT_JSON];
    }

    $choice = $json['choices'][0] ?? null;
    $content = is_array($choice) && is_string($choice['message']['content'] ?? null)
        ? $choice['message']['content']
        : null;
    $finishReason = is_array($choice) && is_string($choice['finish_reason'] ?? null) ? $choice['finish_reason'] : null;
    $tokensIn = $json['usage']['prompt_tokens'] ?? null;
    $tokensOut = $json['usage']['completion_tokens'] ?? null;

    $errorMessage = extractAiErrorMessage($body);
    $error = match (true) {
        $errorMessage !== ''                         => limitAiError('RouterAI: ' . $errorMessage),
        $finishReason === AI_FINISH_REASON_LENGTH    => AI_ERROR_TRUNCATED,
        $content === null || trim($content) === ''   => AI_ERROR_EMPTY,
        default                                      => null,
    };

    return [
        'content'       => $content,
        'model'         => is_string($json['model'] ?? null) ? $json['model'] : null,
        'tokens_in'     => is_int($tokensIn) ? $tokensIn : null,
        'tokens_out'    => is_int($tokensOut) ? $tokensOut : null,
        'finish_reason' => $finishReason,
        'error'         => $error,
    ];
}

/**
 * Текст модели → массив JSON. Не JSON-объект → null.
 */
function decodeAiJson(string $content): ?array
{
    $json = json_decode(trim($content), true);

    return is_array($json) ? $json : null;
}

/**
 * Запрос без картинок для `ai_results.request_json`: n-я картинка заменяется на `{"screenshot_id": N}`
 * (id из `$screenshotIds` по порядку) или на AI_IMAGE_PLACEHOLDER. Ключа в теле запроса нет и так.
 *
 * @param list<?int> $screenshotIds
 * @return array<string, mixed>
 */
function stripAiPayloadImages(array $payload, array $screenshotIds = []): array
{
    $imageIndex = 0;
    foreach ($payload['messages'] ?? [] as $messageIndex => $message) {
        if (!is_array($message['content'] ?? null)) {
            continue;
        }
        foreach ($message['content'] as $partIndex => $part) {
            if (($part['type'] ?? null) !== AI_IMAGE_PART_TYPE) {
                continue;
            }
            $screenshotId = $screenshotIds[$imageIndex++] ?? null;
            $payload['messages'][$messageIndex]['content'][$partIndex] = $screenshotId !== null
                ? ['type' => AI_IMAGE_PART_TYPE, 'screenshot_id' => $screenshotId]
                : AI_IMAGE_PLACEHOLDER;
        }
    }

    return $payload;
}

/**
 * Строка для `ai_results` по результату `sendAiRequest()`: модель — та, что выбрана в настройках (RouterAI
 * в ответе пишет её без провайдера: `qwen4-max`), запрос — без картинок, ошибка — без ключа.
 *
 * @param list<?int> $screenshotIds
 * @return array{diagnostic_id: int, task: string, model: string, status: string, request_json: array,
 *               response_text: ?string, parsed_json: ?array, error_message: ?string, tokens_in: ?int,
 *               tokens_out: ?int}
 */
function buildAiResultRecord(
    int $diagnosticId,
    string $task,
    string $model,
    array $payload,
    array $response,
    ?array $parsed,
    string $key,
    array $screenshotIds = []
): array {
    $error = $response['error'] ?? null;

    return [
        'diagnostic_id' => $diagnosticId,
        'task'          => $task,
        'model'         => $model,
        'status'        => ($response['ok'] ?? false) && $error === null ? 'ok' : 'error',
        'request_json'  => stripAiPayloadImages($payload, $screenshotIds),
        'response_text' => $response['content'] ?? ($response['body'] !== '' ? redactAiKey($response['body'], $key) : null),
        'parsed_json'   => $parsed,
        'error_message' => $error !== null ? limitAiError(redactAiKey($error, $key)) : null,
        'tokens_in'     => $response['tokens_in'] ?? null,
        'tokens_out'    => $response['tokens_out'] ?? null,
    ];
}

/**
 * Тестовый запрос «Проверить подключение»: модель должна вернуть `{"ok": true}` по схеме.
 *
 * @return array{ok: bool, message: string}
 */
function checkAiConnection(string $baseUrl, string $model, string $key, callable $api, callable $sleep): array
{
    $payload = buildAiPayload($model, AI_CHECK_MESSAGES, AI_CHECK_SCHEMA, AI_CHECK_MAX_TOKENS);
    $response = sendAiRequest($baseUrl, $payload, $key, $api, $sleep, AI_CHECK_TIMEOUT_SECONDS);

    if (!$response['ok']) {
        return ['ok' => false, 'message' => "{$model}: {$response['error']}"];
    }
    if ((decodeAiJson((string) $response['content'])['ok'] ?? null) !== true) {
        return ['ok' => false, 'message' => "{$model}: " . AI_ERROR_BAD_SCHEMA];
    }

    return [
        'ok'      => true,
        'message' => sprintf(
            '%s, %s с, токены %s / %s',
            $model,
            number_format($response['elapsed_ms'] / AI_MS_PER_SECOND, 1, ',', ''),
            $response['tokens_in'] ?? '—',
            $response['tokens_out'] ?? '—'
        ),
    ];
}

/**
 * Настоящая сеть для `sendAiRequest()` / `checkAiConnection()`.
 *
 * @return callable(string, array, list<string>, int): array{status: ?int, body: string, error: ?string}
 */
function makeRouterAiTransport(): callable
{
    return static fn (string $url, array $payload, array $headers, int $timeout): array
        => httpApiRequest($url, $payload, $timeout, HTTP_API_MAX_BODY_BYTES, $headers);
}

/**
 * @return callable(int): void
 */
function makeAiSleeper(): callable
{
    return static function (int $seconds): void {
        sleep($seconds);
    };
}
