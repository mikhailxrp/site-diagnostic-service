<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/src/Services/AiClient.php';

final class AiClientTest extends TestCase
{
    private const BASE_URL = 'https://routerai.ru/api/v1/';
    private const KEY      = 'sk-test_Key-1234567890';
    private const MODEL    = 'qwen/qwen4-max';
    private const FIXTURES = ROOT_PATH . '/tests/fixtures/routerai/';

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES . $name);
    }

    /**
     * Транспорт, который отдаёт ответы по очереди и запоминает запросы.
     *
     * @param list<array{status: ?int, body: string, error: ?string}> $responses
     */
    private static function queue(array $responses, array &$calls): callable
    {
        return static function (string $url, array $payload, array $headers, int $timeout) use (&$responses, &$calls): array {
            $calls[] = ['url' => $url, 'payload' => $payload, 'headers' => $headers, 'timeout' => $timeout];

            return array_shift($responses) ?? ['status' => null, 'body' => '', 'error' => 'нет ответа в очереди'];
        };
    }

    private static function ok(): array
    {
        return ['status' => 200, 'body' => self::fixture('chat-ok.json'), 'error' => null];
    }

    private function send(array $responses, array &$calls, array &$sleeps): array
    {
        $payload = buildAiPayload(self::MODEL, AI_CHECK_MESSAGES, AI_CHECK_SCHEMA);
        $sleep = static function (int $seconds) use (&$sleeps): void {
            $sleeps[] = $seconds;
        };

        return sendAiRequest(self::BASE_URL, $payload, self::KEY, self::queue($responses, $calls), $sleep);
    }

    public function testBuildsChatUrlWithoutDoubleSlash(): void
    {
        $this->assertSame('https://routerai.ru/api/v1/chat/completions', buildAiChatUrl(self::BASE_URL));
    }

    public function testPayloadHasSchemaReasoningAndMaxTokens(): void
    {
        $payload = buildAiPayload(self::MODEL, AI_CHECK_MESSAGES, AI_CHECK_SCHEMA, 50);

        $this->assertSame(self::MODEL, $payload['model']);
        $this->assertSame(50, $payload['max_tokens']);
        $this->assertSame('low', $payload['reasoning_effort']);
        $this->assertSame('json_schema', $payload['response_format']['type']);
        $this->assertTrue($payload['response_format']['json_schema']['strict']);
        $this->assertSame('connection_check', $payload['response_format']['json_schema']['name']);
        $this->assertSame(['ok'], $payload['response_format']['json_schema']['schema']['required']);
    }

    public function testPayloadWithoutSchemaHasNoResponseFormat(): void
    {
        $this->assertArrayNotHasKey('response_format', buildAiPayload(self::MODEL, AI_CHECK_MESSAGES, null));
    }

    public function testImagePartIsJpegDataUrl(): void
    {
        $part = buildAiImagePart("\xFF\xD8jpeg");

        $this->assertSame('image_url', $part['type']);
        $this->assertSame('data:image/jpeg;base64,' . base64_encode("\xFF\xD8jpeg"), $part['image_url']['url']);
    }

    public function testKeyValidation(): void
    {
        $this->assertSame(AI_ERROR_NO_KEY, validateAiKey(''));
        $this->assertSame(AI_ERROR_BAD_KEY, validateAiKey("sk-1\r\nX-Evil: 1"));
        $this->assertSame(AI_ERROR_BAD_KEY, validateAiKey('sk 1'));
        $this->assertNull(validateAiKey(self::KEY));
    }

    public function testKeyGoesOnlyToAuthorizationHeader(): void
    {
        $calls = [];
        $sleeps = [];
        $this->send([self::ok()], $calls, $sleeps);

        $this->assertSame(['Authorization: Bearer ' . self::KEY], $calls[0]['headers']);
        $this->assertStringNotContainsString(self::KEY, $calls[0]['url']);
        $this->assertStringNotContainsString(self::KEY, json_encode($calls[0]['payload']));
        $this->assertSame(AI_TIMEOUT_SECONDS, $calls[0]['timeout']);
    }

    public function testParsesSuccessfulResponse(): void
    {
        $calls = [];
        $sleeps = [];
        $result = $this->send([self::ok()], $calls, $sleeps);

        $this->assertTrue($result['ok']);
        $this->assertSame('{"ok": true}', $result['content']);
        $this->assertSame('qwen4-max', $result['model']);
        $this->assertSame(100, $result['tokens_in']);
        $this->assertSame(7, $result['tokens_out']);
        $this->assertSame(1, $result['attempts']);
        $this->assertNull($result['error']);
        $this->assertSame(['ok' => true], decodeAiJson((string) $result['content']));
    }

    public function testRetriesOn429And5xxThenSucceeds(): void
    {
        $calls = [];
        $sleeps = [];
        $result = $this->send([
            ['status' => 429, 'body' => self::fixture('synthetic-error-429.json'), 'error' => null],
            ['status' => 502, 'body' => '', 'error' => null],
            self::ok(),
        ], $calls, $sleeps);

        $this->assertTrue($result['ok']);
        $this->assertSame(3, $result['attempts']);
        $this->assertSame(AI_RETRY_DELAYS_SECONDS, $sleeps);
    }

    public function testGivesUpAfterMaxAttempts(): void
    {
        $calls = [];
        $sleeps = [];
        $tooMany = ['status' => 429, 'body' => self::fixture('synthetic-error-429.json'), 'error' => null];
        $result = $this->send([$tooMany, $tooMany, $tooMany, self::ok()], $calls, $sleeps);

        $this->assertFalse($result['ok']);
        $this->assertCount(AI_MAX_ATTEMPTS, $calls);
        $this->assertSame(AI_ERROR_RATE_LIMIT, $result['error']);
    }

    public function testDoesNotRetryUnauthorized(): void
    {
        $calls = [];
        $sleeps = [];
        $result = $this->send([
            ['status' => 401, 'body' => self::fixture('error-401.json'), 'error' => null],
            self::ok(),
        ], $calls, $sleeps);

        $this->assertFalse($result['ok']);
        $this->assertCount(1, $calls);
        $this->assertSame([], $sleeps);
        $this->assertSame(AI_ERROR_UNAUTHORIZED, $result['error']);
    }

    public function testDoesNotRetryTimeout(): void
    {
        $calls = [];
        $sleeps = [];
        $result = $this->send([
            ['status' => null, 'body' => '', 'error' => 'Источник не ответил вовремя: Operation timed out (curl 28)'],
            self::ok(),
        ], $calls, $sleeps);

        $this->assertCount(1, $calls);
        $this->assertSame(sprintf(AI_ERROR_TIMEOUT, AI_TIMEOUT_SECONDS), $result['error']);
    }

    public function testRetriesNetworkError(): void
    {
        $calls = [];
        $sleeps = [];
        $result = $this->send([
            ['status' => null, 'body' => '', 'error' => 'Could not resolve host: routerai.ru (curl 6)'],
            self::ok(),
        ], $calls, $sleeps);

        $this->assertTrue($result['ok']);
        $this->assertSame(2, $result['attempts']);
    }

    public function testUnknownModelErrorUsesRouterAiMessage(): void
    {
        $calls = [];
        $sleeps = [];
        $result = $this->send([
            ['status' => 400, 'body' => self::fixture('error-400-model.json'), 'error' => null],
        ], $calls, $sleeps);

        $this->assertSame("RouterAI: HTTP 400: Model 'no-such/model-x' not found", $result['error']);
    }

    public function testErrorTextIsRedacted(): void
    {
        $calls = [];
        $sleeps = [];
        $body = json_encode(['error' => ['message' => 'Bad key ' . self::KEY]]);
        $result = $this->send([['status' => 400, 'body' => $body, 'error' => null]], $calls, $sleeps);

        $this->assertStringNotContainsString(self::KEY, $result['error']);
        $this->assertStringContainsString(AI_REDACTED, $result['error']);
    }

    public function testInvalidKeyIsNotSent(): void
    {
        $calls = [];
        $result = sendAiRequest(self::BASE_URL, [], '', self::queue([self::ok()], $calls), static fn () => null);

        $this->assertFalse($result['ok']);
        $this->assertSame(AI_ERROR_NO_KEY, $result['error']);
        $this->assertSame([], $calls);
    }

    public function testParseResponseErrors(): void
    {
        $this->assertSame(AI_ERROR_NOT_JSON, parseAiResponse('<html>')['error']);
        $this->assertSame(AI_ERROR_EMPTY, parseAiResponse('{"choices":[{"message":{"content":"  "}}]}')['error']);
        $this->assertSame(
            AI_ERROR_TRUNCATED,
            parseAiResponse('{"choices":[{"message":{"content":"{\"ok\":"},"finish_reason":"length"}]}')['error']
        );
        $this->assertSame('RouterAI: boom', parseAiResponse('{"error":{"message":"boom"}}')['error']);
    }

    public function testStripImagesReplacesThemWithScreenshotIds(): void
    {
        $payload = buildAiPayload(self::MODEL, [[
            'role'    => 'user',
            'content' => [
                ['type' => 'text', 'text' => 'Проверь вёрстку'],
                buildAiImagePart('first'),
                buildAiImagePart('second'),
            ],
        ]], null);

        $stripped = stripAiPayloadImages($payload, [15]);
        $content = $stripped['messages'][0]['content'];

        $this->assertSame(['type' => 'text', 'text' => 'Проверь вёрстку'], $content[0]);
        $this->assertSame(['type' => 'image_url', 'screenshot_id' => 15], $content[1]);
        $this->assertSame(AI_IMAGE_PLACEHOLDER, $content[2]);
        $this->assertStringNotContainsString('base64', json_encode($stripped));
    }

    public function testResultRecordHasNoKeyAndNoImages(): void
    {
        $payload = buildAiPayload(self::MODEL, [[
            'role'    => 'user',
            'content' => [buildAiImagePart('jpeg-bytes')],
        ]], AI_CHECK_SCHEMA);
        $response = buildAiFailure(400, '{"error":{"message":"key ' . self::KEY . '"}}', 'Bad ' . self::KEY, 1, 10);

        $record = buildAiResultRecord(7, 'visual', self::MODEL, $payload, $response, null, self::KEY, [42]);
        $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertStringNotContainsString(self::KEY, $json);
        $this->assertStringNotContainsString('base64', $json);
        $this->assertSame('error', $record['status']);
        $this->assertSame(self::MODEL, $record['model']);
        $this->assertSame(['type' => 'image_url', 'screenshot_id' => 42], $record['request_json']['messages'][0]['content'][0]);
    }

    public function testResultRecordForSuccess(): void
    {
        $calls = [];
        $sleeps = [];
        $payload = buildAiPayload(self::MODEL, AI_CHECK_MESSAGES, AI_CHECK_SCHEMA);
        $response = $this->send([self::ok()], $calls, $sleeps);

        $record = buildAiResultRecord(7, 'problem', self::MODEL, $payload, $response, ['ok' => true], self::KEY);

        $this->assertSame('ok', $record['status']);
        $this->assertSame('{"ok": true}', $record['response_text']);
        $this->assertSame(['ok' => true], $record['parsed_json']);
        $this->assertSame(100, $record['tokens_in']);
        $this->assertSame(7, $record['tokens_out']);
        $this->assertNull($record['error_message']);
    }

    public function testConnectionCheckSuccessMessage(): void
    {
        $calls = [];
        $check = checkAiConnection(self::BASE_URL, self::MODEL, self::KEY, self::queue([self::ok()], $calls), static fn () => null);

        $this->assertTrue($check['ok']);
        $this->assertMatchesRegularExpression('~^qwen/qwen4-max, \d+,\d с, токены 100 / 7$~u', $check['message']);
        $this->assertSame(AI_CHECK_TIMEOUT_SECONDS, $calls[0]['timeout']);
        $this->assertSame(AI_CHECK_MAX_TOKENS, $calls[0]['payload']['max_tokens']);
    }

    public function testConnectionCheckRejectsAnswerOutsideSchema(): void
    {
        $calls = [];
        $body = json_encode(['model' => 'qwen4-max', 'choices' => [['message' => ['content' => 'Привет!'], 'finish_reason' => 'stop']]]);
        $check = checkAiConnection(
            self::BASE_URL,
            self::MODEL,
            self::KEY,
            self::queue([['status' => 200, 'body' => $body, 'error' => null]], $calls),
            static fn () => null
        );

        $this->assertFalse($check['ok']);
        $this->assertSame(self::MODEL . ': ' . AI_ERROR_BAD_SCHEMA, $check['message']);
    }

    public function testConnectionCheckReportsApiError(): void
    {
        $calls = [];
        $check = checkAiConnection(
            self::BASE_URL,
            self::MODEL,
            self::KEY,
            self::queue([['status' => 402, 'body' => '{}', 'error' => null]], $calls),
            static fn () => null
        );

        $this->assertFalse($check['ok']);
        $this->assertSame(self::MODEL . ': ' . AI_ERROR_NO_FUNDS, $check['message']);
    }
}
