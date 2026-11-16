<?php

declare(strict_types=1);

/**
 * Шаг `exposed_files` без БД: открытые служебные файлы по фиксированному списку (`data/exposed-files.php`).
 * Сеть передаётся параметром (`$request`).
 *
 * Сначала запрашивается случайный несуществующий адрес — эталон «мягкого 404». Файл открыт, только если ответ
 * 200 / 206, путь после редиректов не сменился, тело не совпадает с эталоном и сработал признак содержимого.
 * Читается не больше EXPOSED_FILE_MAX_BYTES каждого файла. Содержимое файла (секреты) не попадает ни в находку,
 * ни в факты, ни в сообщение шага: только код ответа, размер прочитанного, Content-Type и имя признака.
 */

require_once ROOT_PATH . '/src/Services/HttpClient.php';
require_once ROOT_PATH . '/src/Services/FindingFactory.php';
require_once ROOT_PATH . '/src/Services/Checks/BotProtectionCheck.php';
require_once ROOT_PATH . '/src/Services/Checks/SeoCheck.php';

const EXPOSED_FILES_FILE = ROOT_PATH . '/data/exposed-files.php';

const EXPOSED_FILE_MAX_BYTES     = 16 * 1024; // phpinfo: заголовок и версия — в первых ~4 КБ
const EXPOSED_FILE_OK_STATUSES   = [200, 206];
const EXPOSED_FILE_HTML_SNIFF    = 512; // начало ответа, по которому видно HTML-страницу
const EXPOSED_FILE_HTML_MARKERS  = ['<!doctype', '<html', '<head', '<body'];
const EXPOSED_BASELINE_PREFIX    = 'sd-';
const EXPOSED_BASELINE_SUFFIX    = '.txt';
const EXPOSED_BASELINE_RANDOM_BYTES = 8;

const EXPOSED_NO_HOME_MESSAGE  = 'Нет данных главной страницы: шаг «Доступность сайта» не выполнен.';
const EXPOSED_BLOCKED_MESSAGE  = 'Сайт закрыт защитой от ботов (%s): служебные файлы не проверить.';
const EXPOSED_NO_ANSWER_MESSAGE = 'Сайт не ответил на проверочный запрос: служебные файлы не проверить.';

/**
 * @param array<string, mixed>                       $context   контекст шага (`buildStepContext()`)
 * @param callable(string $url, int $maxBytes): array $request   результат как у `httpRequest()`
 * @param callable(): void|null                      $onRequest после каждого запроса (heartbeat воркера)
 * @param array<string, mixed>|null                  $rules     null — из `data/exposed-files.php`
 * @param array<string, mixed>|null                  $botRules  null — из `data/bot-protection.php`
 * @return array{status: string, message: string, facts: array, findings: list<array>}
 */
function runExposedFilesCheck(
    array $context,
    callable $request,
    ?callable $onRequest = null,
    ?array $rules = null,
    ?array $botRules = null
): array {
    $home = $context['facts']['availability']['home'] ?? null;
    if (!is_array($home) || ($home['status'] ?? null) === null) {
        return ['status' => 'skipped', 'message' => EXPOSED_NO_HOME_MESSAGE, 'facts' => [], 'findings' => []];
    }

    $rules ??= loadExposedFilesRules();
    $homeUrl = (string) (($home['final_url'] ?? '') !== '' ? $home['final_url'] : $context['diagnostic']['url']);
    $origin = buildSeoOrigin($homeUrl);

    $fetch = static function (string $url) use ($request, $onRequest): array {
        $response = $request($url, EXPOSED_FILE_MAX_BYTES);
        if ($onRequest !== null) {
            $onRequest();
        }

        return $response;
    };

    $baselineUrl = $origin . '/' . buildExposedBaselinePath();
    $baseline = $fetch($baselineUrl);

    if (($baseline['status'] ?? null) === null) {
        return ['status' => 'skipped', 'message' => EXPOSED_NO_ANSWER_MESSAGE, 'facts' => [], 'findings' => []];
    }

    $protection = detectBotProtection($baseline, $botRules);
    if ($protection !== null) {
        return [
            'status'   => 'skipped',
            'message'  => sprintf(EXPOSED_BLOCKED_MESSAGE, $protection['title']),
            'facts'    => [],
            'findings' => [],
        ];
    }

    $baselineHash = isExposedOkStatus($baseline['status']) ? sha1((string) ($baseline['body'] ?? '')) : null;

    $checked = 0;
    $findings = [];
    $exposed = [];

    foreach ($rules['groups'] as $group) {
        foreach ($group['files'] as $path => $signatureName) {
            $url = $origin . '/' . ltrim((string) $path, '/');
            $response = $fetch($url);
            $checked++;

            $signature = $rules['signatures'][$signatureName] ?? null;
            if (!is_array($signature) || !isExposedFile($response, $url, $baselineHash, $signature)) {
                continue;
            }

            $findings[] = makeExposedFileFinding($group, (string) $path, $url, (string) $signatureName, $response);
            $exposed[] = ['path' => (string) $path, 'signature' => (string) $signatureName];
        }
    }

    return [
        'status'   => 'done',
        'message'  => "Проверено путей: {$checked}, открыто: " . count($exposed) . '.',
        'facts'    => ['checked' => $checked, 'exposed' => $exposed],
        'findings' => $findings,
    ];
}

/**
 * @return array{signatures: array<string, array>, groups: array<string, array>}
 */
function loadExposedFilesRules(): array
{
    $rules = require EXPOSED_FILES_FILE;
    if (!is_array($rules) || !is_array($rules['signatures'] ?? null) || !is_array($rules['groups'] ?? null)) {
        throw new RuntimeException('Файл служебных путей повреждён: ' . EXPOSED_FILES_FILE);
    }

    return $rules;
}

/**
 * Случайный путь, которого на сайте точно нет: по ответу на него видно, что сайт отдаёт на любой адрес.
 */
function buildExposedBaselinePath(): string
{
    return EXPOSED_BASELINE_PREFIX . bin2hex(random_bytes(EXPOSED_BASELINE_RANDOM_BYTES)) . EXPOSED_BASELINE_SUFFIX;
}

function isExposedOkStatus(mixed $status): bool
{
    return in_array($status, EXPOSED_FILE_OK_STATUSES, true);
}

/**
 * Настоящий ли это файл: код ответа, тот же путь после редиректов, не эталон «мягкого 404», признак содержимого.
 *
 * @param array<string, mixed> $response  ответ как у `httpRequest()`
 * @param array<string, mixed> $signature признак из `data/exposed-files.php`
 */
function isExposedFile(array $response, string $url, ?string $baselineHash, array $signature): bool
{
    if (($response['error'] ?? null) !== null || !isExposedOkStatus($response['status'] ?? null)) {
        return false;
    }

    $finalUrl = (string) ($response['final_url'] ?? $url);
    if (parse_url($finalUrl, PHP_URL_PATH) !== parse_url($url, PHP_URL_PATH)) {
        return false;
    }

    $body = substr((string) ($response['body'] ?? ''), 0, EXPOSED_FILE_MAX_BYTES);
    if ($body === '' || ($baselineHash !== null && sha1($body) === $baselineHash)) {
        return false;
    }

    return matchesExposedSignature($body, $signature);
}

/**
 * @param array{require?: list<string>, any?: list<string>, not_html?: bool} $signature
 */
function matchesExposedSignature(string $body, array $signature): bool
{
    if (($signature['not_html'] ?? false) === true && looksLikeHtmlPage($body)) {
        return false;
    }

    foreach ($signature['require'] ?? [] as $pattern) {
        if (preg_match($pattern, $body) !== 1) {
            return false;
        }
    }

    $any = $signature['any'] ?? [];
    if ($any === []) {
        return ($signature['require'] ?? []) !== [];
    }

    foreach ($any as $pattern) {
        if (preg_match($pattern, $body) === 1) {
            return true;
        }
    }

    return false;
}

function looksLikeHtmlPage(string $body): bool
{
    $head = strtolower(substr(ltrim($body), 0, EXPOSED_FILE_HTML_SNIFF));

    foreach (EXPOSED_FILE_HTML_MARKERS as $marker) {
        if (str_contains($head, $marker)) {
            return true;
        }
    }

    return false;
}

/**
 * Находка без содержимого файла: только факт доступности и имя признака.
 *
 * @param array{title: string, details: string, severity: string} $group
 * @param array<string, mixed>                                    $response
 */
function makeExposedFileFinding(array $group, string $path, string $url, string $signature, array $response): array
{
    return makeFinding(
        'exposed_file',
        $group['title'] . ': /' . ltrim($path, '/'),
        $url,
        $path,
        $group['details'],
        [
            'status'       => $response['status'],
            'bytes_read'   => min(strlen((string) ($response['body'] ?? '')), EXPOSED_FILE_MAX_BYTES),
            'content_type' => getHeaderValue($response['headers'] ?? [], 'content-type'),
            'signature'    => $signature,
        ],
        null,
        $group['severity']
    );
}
