<?php

declare(strict_types=1);

/**
 * HTTP-клиент для проверок: curl, редиректы вручную с записью цепочки, проверка хоста перед каждым
 * запросом (частные и служебные адреса запрещены), тайм-ауты и предел размера ответа.
 * Сеть и DNS передаются параметрами (`$transport`, `$resolver`), чтобы тесты не ходили в интернет.
 * Ошибки сети не бросаются исключением, а возвращаются в `error`. Паузы между запросами нет (решение владельца).
 */

require_once ROOT_PATH . '/src/Services/UrlValidator.php';

const HTTP_MAX_REDIRECTS           = 10;
const HTTP_CONNECT_TIMEOUT_SECONDS = 10;
const HTTP_TIMEOUT_SECONDS         = 30;
const HTTP_MAX_BODY_BYTES          = 5 * 1024 * 1024; // 5 МБ — больше главной страницы магазина не бывает
const HTTP_USER_AGENT              = 'SiteDiagnostics/1.0 (site audit)';
const HTTP_ACCEPT_LANGUAGE         = 'ru-RU,ru;q=0.9,en;q=0.8';

const HTTP_REDIRECT_CODES = [301, 302, 303, 307, 308];
const HTTP_ALLOWED_METHODS = ['GET', 'HEAD'];
const HTTP_DEFAULT_PORTS = ['http' => 80, 'https' => 443];
const HTTP_MIN_PORT = 1;
const HTTP_MAX_PORT = 65535;
const HTTP_FORBIDDEN_URL_CHARS_UTF8_PATTERN = '/[\s\x00-\x1F\x7F]/u';
const HTTP_FORBIDDEN_URL_CHARS_BYTE_PATTERN = '/[\s\x00-\x1F\x7F]/';

const CURL_ERROR_TIMEOUT = 28; // CURLE_OPERATION_TIMEDOUT

/** Внешние API уязвимостей (NVD, OSV): ответ NVD по Magento — около 4 МБ */
const HTTP_API_TIMEOUT_SECONDS = 30;
const HTTP_API_MAX_BODY_BYTES  = 20 * 1024 * 1024;

const NANOSECONDS_IN_MILLISECOND = 1_000_000;

/**
 * Запрос с ручным следованием редиректам.
 *
 * @param callable(string $host): list<string>|null $resolver  DNS; null — настоящий (`resolveHostIps`)
 * @param callable(array $request): array|null       $transport один запрос без редиректов; null — curl
 * @param int|null                                    $maxBytes  предел тела ответа: читается только начало файла
 *                                                               (`Range`, без сжатия, обрыв загрузки); null —
 *                                                               HTTP_MAX_BODY_BYTES без `Range`
 * @return array{final_url: string, status: ?int, headers: array<string, list<string>>, body: string,
 *               truncated: bool, redirects: list<array{url: string, status: int, location: string}>,
 *               time_ms: int, error: ?string}
 */
function httpRequest(
    string $url,
    string $method = 'GET',
    ?callable $resolver = null,
    ?callable $transport = null,
    ?int $maxBytes = null
): array {
    $resolver ??= 'resolveHostIps';
    $transport ??= 'sendCurlRequest';
    $method = strtoupper($method);

    $startedAt = hrtime(true);
    $redirects = [];
    $currentUrl = $url;

    $finish = static function (array $response, ?string $error) use (&$currentUrl, &$redirects, $startedAt): array {
        return buildHttpResult($currentUrl, $redirects, $startedAt, $response, $error);
    };

    if (!in_array($method, HTTP_ALLOWED_METHODS, true)) {
        return $finish([], "Метод {$method} не поддерживается.");
    }

    while (true) {
        $target = prepareRequestTarget($currentUrl, $resolver);
        if (isset($target['error'])) {
            return $finish([], $target['error']);
        }
        $currentUrl = $target['url'];

        $request = [
            'url'    => $target['url'],
            'method' => $method,
            'host'   => $target['host'],
            'port'   => $target['port'],
            'ip'     => $target['ip'],
        ];
        if ($maxBytes !== null) {
            $request['max_bytes'] = max(1, $maxBytes);
        }

        $response = $transport($request);

        if (($response['error'] ?? null) !== null) {
            return $finish($response, (string) $response['error']);
        }

        $status = (int) ($response['status'] ?? 0);
        $location = getHeaderValue($response['headers'] ?? [], 'location');

        if (!in_array($status, HTTP_REDIRECT_CODES, true) || $location === null) {
            return $finish($response, null);
        }

        $redirects[] = ['url' => $currentUrl, 'status' => $status, 'location' => $location];

        if (count($redirects) > HTTP_MAX_REDIRECTS) {
            return $finish($response, 'Слишком много редиректов: больше ' . HTTP_MAX_REDIRECTS . '.');
        }

        $nextUrl = resolveRedirectUrl($currentUrl, $location);
        if ($nextUrl === null) {
            return $finish($response, "Редирект на неподдерживаемый адрес: {$location}");
        }

        $currentUrl = $nextUrl;
    }
}

/**
 * @param list<array{url: string, status: int, location: string}> $redirects
 * @param array<string, mixed>                                    $response ответ transport (может быть пустым)
 */
function buildHttpResult(string $finalUrl, array $redirects, int $startedAt, array $response, ?string $error): array
{
    return [
        'final_url' => $finalUrl,
        'status'    => $response['status'] ?? null,
        'headers'   => $response['headers'] ?? [],
        'body'      => $response['body'] ?? '',
        'truncated' => $response['truncated'] ?? false,
        'redirects' => $redirects,
        'time_ms'   => intdiv(hrtime(true) - $startedAt, NANOSECONDS_IN_MILLISECOND),
        'error'     => $error,
    ];
}

/**
 * Проверка адреса перед запросом: только http(s), хост публичный. Возвращает канонический URL с тем же
 * нормализованным хостом, что и для `CURLOPT_RESOLVE` — libcurl не делает отдельный DNS-запрос.
 *
 * @return array{host: string, port: int, ip: string, url: string}|array{error: string}
 */
function prepareRequestTarget(string $url, callable $resolver): array
{
    if (
        preg_match(HTTP_FORBIDDEN_URL_CHARS_UTF8_PATTERN, $url) === 1
        || preg_match(HTTP_FORBIDDEN_URL_CHARS_BYTE_PATTERN, $url) === 1
    ) {
        return ['error' => "В адресе не должно быть пробелов и служебных символов: {$url}"];
    }

    $parts = parse_url($url);
    if (!is_array($parts)) {
        return ['error' => "Некорректный адрес: {$url}"];
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));

    if (!isset(HTTP_DEFAULT_PORTS[$scheme])) {
        return ['error' => "Поддерживаются только адреса http:// и https://: {$url}"];
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        return ['error' => "Адрес с логином и паролем не запрашивается: {$url}"];
    }

    $port = (int) ($parts['port'] ?? HTTP_DEFAULT_PORTS[$scheme]);
    if ($port < HTTP_MIN_PORT || $port > HTTP_MAX_PORT) {
        return ['error' => "Некорректный порт в адресе: {$url}"];
    }

    $host = normalizeHostName((string) ($parts['host'] ?? ''));
    if ($host === null) {
        return ['error' => "Некорректный хост в адресе: {$url}"];
    }

    $resolvedIps = [];
    $resolverError = null;
    $capturingResolver = static function (string $name) use ($resolver, &$resolvedIps, &$resolverError): array {
        try {
            $resolvedIps = $resolver($name);
        } catch (Throwable $e) {
            $resolverError = $e->getMessage() !== '' ? $e->getMessage() : get_class($e);
            $resolvedIps = [];
        }

        return $resolvedIps;
    };

    $hostError = validateSiteHost($host, $capturingResolver);
    if ($resolverError !== null) {
        return ['error' => "Не удалось выполнить DNS-запрос ({$resolverError}). Адрес: {$url}"];
    }
    if ($hostError !== null) {
        return ['error' => "{$hostError} Адрес: {$url}"];
    }

    $isIpLiteral = filter_var($host, FILTER_VALIDATE_IP) !== false;
    $authority = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? "[{$host}]" : $host;
    $path = isset($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/';
    if (!str_starts_with($path, '/')) {
        $path = '/' . $path;
    }
    $canonical = $scheme . '://' . $authority
        . (isset($parts['port']) ? ':' . $port : '')
        . $path
        . (isset($parts['query']) ? '?' . $parts['query'] : '');

    return [
        'host' => $host,
        'port' => $port,
        'ip'   => $isIpLiteral ? $host : (string) $resolvedIps[0],
        'url'  => $canonical,
    ];
}

/**
 * Относительный `Location` → полный адрес (RFC 3986, раздел 5.2), без `#фрагмента`.
 * null — схема не http(s) или адрес не разобрать.
 */
function resolveRedirectUrl(string $base, string $location): ?string
{
    $location = trim($location);
    $baseParts = parse_url($base);

    if ($location === '' || $baseParts === false || !isset($baseParts['scheme'], $baseParts['host'])) {
        return null;
    }

    $base = explode('#', $base, 2)[0];
    $location = explode('#', $location, 2)[0];
    $baseScheme = strtolower($baseParts['scheme']);

    if (preg_match('/^[a-z][a-z0-9+.\-]*:/i', $location) === 1) {
        $resolved = $location;
    } elseif (str_starts_with($location, '//')) {
        $resolved = $baseScheme . ':' . $location;
    } else {
        $origin = $baseScheme . '://' . $baseParts['host'] . (isset($baseParts['port']) ? ':' . $baseParts['port'] : '');
        $basePath = $baseParts['path'] ?? '/';

        $resolved = match (true) {
            $location === ''                => $base,
            str_starts_with($location, '?') => $origin . $basePath . $location,
            str_starts_with($location, '/') => $origin . resolveRelativePath('/', $location),
            default                         => $origin . resolveRelativePath($basePath, $location),
        };
    }

    $parts = parse_url($resolved);
    if ($parts === false || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
        return null;
    }
    if (!isset(HTTP_DEFAULT_PORTS[strtolower($parts['scheme'])])) {
        return null;
    }

    return $resolved;
}

/**
 * Склеивает путь `relative` с каталогом `basePath` и убирает `.` и `..`. Строка запроса сохраняется.
 */
function resolveRelativePath(string $basePath, string $relative): string
{
    [$relativePath, $query] = array_pad(explode('?', $relative, 2), 2, null);

    $path = str_starts_with($relativePath, '/')
        ? $relativePath
        : substr($basePath, 0, (int) strrpos($basePath, '/') + 1) . $relativePath;

    return removeDotSegments($path) . ($query !== null ? '?' . $query : '');
}

/**
 * `/a/b/../c/./d` → `/a/c/d`; выше корня не поднимается.
 */
function removeDotSegments(string $path): string
{
    $output = [];
    $segments = explode('/', $path);
    $lastIndex = count($segments) - 1;

    foreach ($segments as $index => $segment) {
        $isLast = $index === $lastIndex;

        if ($segment === '.' || $segment === '..') {
            if ($segment === '..' && count($output) > 1) {
                array_pop($output);
            }
            if ($isLast) {
                $output[] = '';
            }
            continue;
        }

        $output[] = $segment;
    }

    $result = implode('/', $output);

    return str_starts_with($result, '/') ? $result : '/' . $result;
}

/**
 * Первое значение заголовка (имена — в нижнем регистре).
 *
 * @param array<string, list<string>> $headers
 */
function getHeaderValue(array $headers, string $name): ?string
{
    $value = $headers[strtolower($name)][0] ?? null;

    return $value !== null && $value !== '' ? $value : null;
}

/**
 * Какую часть куска ответа можно дописать, не выходя за предел. truncated — предел достигнут,
 * загрузку надо оборвать.
 *
 * @return array{chunk: string, truncated: bool}
 */
function limitBodyChunk(int $currentLength, string $chunk, int $maxBytes): array
{
    $room = $maxBytes - $currentLength;

    if (strlen($chunk) <= $room) {
        return ['chunk' => $chunk, 'truncated' => false];
    }

    return ['chunk' => substr($chunk, 0, max(0, $room)), 'truncated' => true];
}

/**
 * Разбирает строку заголовка ответа в массив заголовков. Новая строка статуса (`HTTP/…`, например
 * после `100 Continue`) начинает заголовки заново.
 *
 * @param array<string, list<string>> $headers
 * @return array<string, list<string>>
 */
function addHeaderLine(array $headers, string $line): array
{
    $line = trim($line);

    if (str_starts_with($line, 'HTTP/')) {
        return [];
    }
    if (!str_contains($line, ':')) {
        return $headers;
    }

    [$name, $value] = explode(':', $line, 2);
    $headers[strtolower(trim($name))][] = trim($value);

    return $headers;
}

/**
 * Заголовки запроса. С пределом размера — `Range` на первые `$maxBytes` байт и `Accept-Encoding: identity`:
 * Range режет сжатый поток, а не файл, поэтому сжатие в этом режиме не запрашивается.
 *
 * @return list<string>
 */
function buildRequestHeaders(?int $maxBytes): array
{
    $headers = [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: ' . HTTP_ACCEPT_LANGUAGE,
    ];

    if ($maxBytes !== null) {
        $headers[] = 'Range: bytes=0-' . (max(1, $maxBytes) - 1);
        $headers[] = 'Accept-Encoding: identity';
    }

    return $headers;
}

/**
 * Один запрос через curl без авто-редиректов, на заранее проверенный IP.
 *
 * @param array{url: string, method: string, host: string, port: int, ip: string, max_bytes?: int} $request
 * @return array{status: ?int, headers: array<string, list<string>>, body: string, truncated: bool, error: ?string}
 */
function sendCurlRequest(array $request): array
{
    $handle = curl_init();
    if ($handle === false) {
        return ['status' => null, 'headers' => [], 'body' => '', 'truncated' => false, 'error' => 'Не удалось запустить curl.'];
    }

    $headers = [];
    $body = '';
    $truncated = false;
    $maxBytes = isset($request['max_bytes']) ? (int) $request['max_bytes'] : null;
    $bodyLimit = $maxBytes ?? HTTP_MAX_BODY_BYTES;

    $options = [
        CURLOPT_URL            => $request['url'],
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => HTTP_CONNECT_TIMEOUT_SECONDS,
        CURLOPT_TIMEOUT        => HTTP_TIMEOUT_SECONDS,
        CURLOPT_USERAGENT      => HTTP_USER_AGENT,
        CURLOPT_HTTPHEADER     => buildRequestHeaders($maxBytes),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_NOBODY         => $request['method'] === 'HEAD',
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
            $headers = addHeaderLine($headers, $line);

            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION  => static function ($curl, string $chunk) use (&$body, &$truncated, $bodyLimit): int {
            $limited = limitBodyChunk(strlen($body), $chunk, $bodyLimit);
            $body .= $limited['chunk'];
            $truncated = $limited['truncated'];

            return $truncated ? 0 : strlen($chunk); // 0 — curl обрывает загрузку
        },
    ];

    if ($maxBytes === null) {
        $options[CURLOPT_ENCODING] = ''; // gzip/br распаковываются; предел размера считается по распакованному
    } else {
        // Обрыв потока HTTP/2 по пределу curl 8.7 дожидается до тайм-аута (30 с на запрос); HTTP/1.1 рвёт сразу
        $options[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_1_1;
    }

    if (filter_var($request['host'], FILTER_VALIDATE_IP) === false) {
        $ip = filter_var($request['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            ? '[' . $request['ip'] . ']'
            : $request['ip'];
        $options[CURLOPT_RESOLVE] = [$request['host'] . ':' . $request['port'] . ':' . $ip];
    }

    curl_setopt_array($handle, $options);
    curl_exec($handle);

    $errno = curl_errno($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    // Обрезано по пределу — тело получено целиком в пределах лимита, обрыв (или тайм-аут после него) не ошибка
    $error = match (true) {
        $errno === 0, $truncated      => null,
        $errno === CURL_ERROR_TIMEOUT => 'Сайт не ответил вовремя: ' . curl_error($handle) . " (curl {$errno})",
        default                       => curl_error($handle) . " (curl {$errno})",
    };
    curl_close($handle);

    return [
        'status'    => $status > 0 ? $status : null,
        'headers'   => $headers,
        'body'      => $body,
        'truncated' => $truncated,
        'error'     => $error,
    ];
}

/**
 * Скачивание большого файла с известного источника баз (Wordfence) сразу на диск: без предела HTTP_MAX_BODY_BYTES,
 * со своими заголовками (`Authorization`), тайм-аутом и пределом размера. Только https, редиректы — силами curl.
 * Для адресов из диагностики не используется: там нужен `httpRequest()` с проверкой хоста.
 * Файл при ошибке или превышении предела удаляется.
 *
 * @param list<string> $headers строки заголовков `Имя: значение`
 * @return array{status: ?int, bytes: int, error: ?string}
 */
function httpDownloadToFile(string $url, string $path, array $headers, int $timeoutSeconds, int $maxBytes): array
{
    if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
        return ['status' => null, 'bytes' => 0, 'error' => 'Скачивание баз — только по https.'];
    }

    $file = fopen($path, 'wb');
    if ($file === false) {
        return ['status' => null, 'bytes' => 0, 'error' => "Не удалось открыть {$path} на запись."];
    }

    $handle = curl_init();
    if ($handle === false) {
        fclose($file);
        unlink($path);

        return ['status' => null, 'bytes' => 0, 'error' => 'Не удалось запустить curl.'];
    }

    $bytes = 0;
    $tooLarge = false;
    curl_setopt_array($handle, [
        CURLOPT_URL            => $url,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => HTTP_MAX_REDIRECTS,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => HTTP_CONNECT_TIMEOUT_SECONDS,
        CURLOPT_TIMEOUT        => $timeoutSeconds,
        CURLOPT_USERAGENT      => HTTP_USER_AGENT,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_ENCODING       => '',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_WRITEFUNCTION  => static function ($curl, string $chunk) use ($file, &$bytes, &$tooLarge, $maxBytes): int {
            $bytes += strlen($chunk);
            if ($bytes > $maxBytes) {
                $tooLarge = true;

                return 0; // 0 — curl обрывает загрузку
            }

            return (int) fwrite($file, $chunk);
        },
    ]);
    curl_exec($handle);

    $errno = curl_errno($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = match (true) {
        $tooLarge                     => 'Файл больше предела ' . intdiv($maxBytes, 1024 * 1024) . ' МБ.',
        $errno === 0                  => null,
        $errno === CURL_ERROR_TIMEOUT => 'Источник не ответил вовремя: ' . curl_error($handle) . " (curl {$errno})",
        default                       => curl_error($handle) . " (curl {$errno})",
    };
    curl_close($handle);
    fclose($file);

    if ($error !== null) {
        unlink($path);
    }

    return ['status' => $status > 0 ? $status : null, 'bytes' => $bytes, 'error' => $error];
}

/**
 * Запрос к известному внешнему API (NVD, OSV): GET или POST с JSON-телом, ответ — в память со своим пределом
 * размера. Только https, редиректы — силами curl и только на https. Для адресов из диагностики не используется:
 * там нужен `httpRequest()` с проверкой хоста.
 *
 * @param array<string, mixed>|null $json null — GET; массив — POST с этим телом в JSON
 * @param list<string>              $extraHeaders дополнительные заголовки `Имя: значение` (ключ API и т. п.)
 * @return array{status: ?int, body: string, error: ?string}
 */
function httpApiRequest(
    string $url,
    ?array $json = null,
    int $timeoutSeconds = HTTP_API_TIMEOUT_SECONDS,
    int $maxBytes = HTTP_API_MAX_BODY_BYTES,
    array $extraHeaders = []
): array {
    if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
        return ['status' => null, 'body' => '', 'error' => 'Запросы к API — только по https.'];
    }

    $handle = curl_init();
    if ($handle === false) {
        return ['status' => null, 'body' => '', 'error' => 'Не удалось запустить curl.'];
    }

    $body = '';
    $tooLarge = false;
    $headers = ['Accept: application/json', ...$extraHeaders];
    $options = [
        CURLOPT_URL             => $url,
        CURLOPT_FOLLOWLOCATION  => true,
        CURLOPT_MAXREDIRS       => HTTP_MAX_REDIRECTS,
        CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT  => HTTP_CONNECT_TIMEOUT_SECONDS,
        CURLOPT_TIMEOUT         => $timeoutSeconds,
        CURLOPT_USERAGENT       => HTTP_USER_AGENT,
        CURLOPT_ENCODING        => '',
        CURLOPT_SSL_VERIFYPEER  => true,
        CURLOPT_SSL_VERIFYHOST  => 2,
        CURLOPT_WRITEFUNCTION   => static function ($curl, string $chunk) use (&$body, &$tooLarge, $maxBytes): int {
            if (strlen($body) + strlen($chunk) > $maxBytes) {
                $tooLarge = true;

                return 0; // 0 — curl обрывает загрузку
            }
            $body .= $chunk;

            return strlen($chunk);
        },
    ];
    if ($json !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $headers[] = 'Content-Type: application/json';
    }
    $options[CURLOPT_HTTPHEADER] = $headers;

    curl_setopt_array($handle, $options);
    curl_exec($handle);

    $errno = curl_errno($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = match (true) {
        $tooLarge                     => 'Ответ больше предела ' . intdiv($maxBytes, 1024 * 1024) . ' МБ.',
        $errno === 0                  => null,
        $errno === CURL_ERROR_TIMEOUT => 'Источник не ответил вовремя: ' . curl_error($handle) . " (curl {$errno})",
        default                       => curl_error($handle) . " (curl {$errno})",
    };
    curl_close($handle);

    return ['status' => $status > 0 ? $status : null, 'body' => $error === null ? $body : '', 'error' => $error];
}
