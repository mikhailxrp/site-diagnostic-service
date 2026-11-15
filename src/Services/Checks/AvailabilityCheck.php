<?php

declare(strict_types=1);

/**
 * Проверка доступности без БД: главная по адресу из диагностики, 4 варианта корня (http/https, www/без www),
 * сертификат. Сеть передаётся параметрами (`$request`, `$inspectSsl`), чтобы тесты не ходили в интернет.
 * Главная не открылась (нет ответа, тайм-аут, петля редиректов) → находка `critical` и остановка проверки:
 * остальные варианты не запрашиваются, чтобы зависший сайт не держал воркер.
 */

require_once ROOT_PATH . '/src/Services/UrlValidator.php';
require_once ROOT_PATH . '/src/Services/FindingFactory.php';

/** Коды, за которыми часто стоит защита от ботов: только в факты, разбор — Фаза 2 */
const AVAILABILITY_BOT_PROTECTION_CODES = [403, 429, 503];
const HTTP_ERROR_STATUS_MIN = 400;

/** CURLE_PEER_FAILED_VERIFICATION: curl не доверяет цепочке сертификата */
const CURL_ERROR_PEER_VERIFICATION = 60;

const AVAILABILITY_VARIANT_HTTP      = 'http';
const AVAILABILITY_VARIANT_HTTPS     = 'https';
const AVAILABILITY_VARIANT_HTTP_WWW  = 'http_www';
const AVAILABILITY_VARIANT_HTTPS_WWW = 'https_www';

/**
 * @return array<string, string> ключ варианта → адрес корня
 */
function buildAvailabilityVariants(string $host): array
{
    $host = extractBaseHost($host);

    return [
        AVAILABILITY_VARIANT_HTTP      => "http://{$host}/",
        AVAILABILITY_VARIANT_HTTPS     => "https://{$host}/",
        AVAILABILITY_VARIANT_HTTP_WWW  => 'http://' . WWW_PREFIX . "{$host}/",
        AVAILABILITY_VARIANT_HTTPS_WWW => 'https://' . WWW_PREFIX . "{$host}/",
    ];
}

/**
 * @param array<string, mixed>                $context   контекст шага (`buildStepContext()`)
 * @param callable(string $url): array        $request   результат как у `httpRequest()`
 * @param callable(string $host, bool $trusted): array $inspectSsl результат как у `inspectSslCertificate()`
 * @return array{status: string, message: string, facts: array, summary: array, findings: list<array>, stop: bool}
 */
function runAvailabilityCheck(array $context, callable $request, callable $inspectSsl): array
{
    $url = (string) $context['diagnostic']['url'];
    $host = (string) $context['diagnostic']['host'];

    $homeResponse = $request($url);
    $home = buildHomeFacts($url, $homeResponse);
    $sslHost = (string) (parse_url($home['final_url'], PHP_URL_HOST) ?: $host);

    if ($homeResponse['error'] !== null) {
        $ssl = isHttpsUrl($home['final_url']) ? $inspectSsl($sslHost, detectCertificateTrust([$home], $sslHost)) : null;
        $findings = [makeHomeUnavailableFinding($homeResponse, $url), ...($ssl['findings'] ?? [])];

        return [
            'status'   => 'done',
            'message'  => 'Сайт не открывается: ' . $homeResponse['error'],
            'facts'    => ['home' => $home, 'variants' => [], 'ssl' => $ssl['facts'] ?? null],
            'summary'  => buildAvailabilitySummary($home, [], null, null, $ssl['facts'] ?? null),
            'findings' => $findings,
            'stop'     => true,
        ];
    }

    $responses = [$url => $homeResponse];
    $variants = [];
    foreach (buildAvailabilityVariants($host) as $key => $variantUrl) {
        $responses[$variantUrl] ??= $request($variantUrl);
        $variants[$key] = summarizeResponse($variantUrl, $responses[$variantUrl]);
    }

    $ssl = $inspectSsl($sslHost, detectCertificateTrust([$home, ...array_values($variants)], $sslHost));
    $httpsRedirect = detectHttpsRedirect($variants);
    $wwwConsistent = detectWwwConsistency($variants);
    $rootHost = extractBaseHost($host);

    $findings = array_values(array_filter([
        makeHomeStatusFinding($home),
        $httpsRedirect === false ? makeNoHttpsRedirectFinding($rootHost) : null,
        !hasWorkingHttps($variants, $home) && ($ssl['facts']['error'] ?? null) !== null
            ? makeHttpsUnavailableFinding($rootHost, (string) $ssl['facts']['error'])
            : null,
        $wwwConsistent === false ? makeWwwMismatchFinding($rootHost, $variants) : null,
    ]));

    return [
        'status'   => 'done',
        'message'  => buildAvailabilityMessage($home),
        'facts'    => ['home' => $home, 'variants' => $variants, 'ssl' => $ssl['facts']],
        'summary'  => buildAvailabilitySummary($home, $variants, $httpsRedirect, $wwwConsistent, $ssl['facts']),
        'findings' => [...$findings, ...$ssl['findings']],
        'stop'     => false,
    ];
}

/**
 * Ответ без тела и заголовков — для вариантов и сводки.
 *
 * @return array{url: string, final_url: string, status: ?int, redirects: list<array>, time_ms: int, error: ?string}
 */
function summarizeResponse(string $url, array $response): array
{
    return [
        'url'       => $url,
        'final_url' => (string) $response['final_url'],
        'status'    => $response['status'],
        'redirects' => $response['redirects'],
        'time_ms'   => (int) $response['time_ms'],
        'error'     => $response['error'],
    ];
}

/**
 * Главная целиком — HTML и заголовки нужны шагам стека и ресурсов.
 */
function buildHomeFacts(string $url, array $response): array
{
    $status = $response['status'];

    return summarizeResponse($url, $response) + [
        'headers'                 => $response['headers'],
        'body'                    => $response['body'],
        'truncated'               => (bool) $response['truncated'],
        'possible_bot_protection' => in_array($status, AVAILABILITY_BOT_PROTECTION_CODES, true),
    ];
}

function isHttpsUrl(string $url): bool
{
    return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
}

function hasResponse(array $variant): bool
{
    return $variant['status'] !== null && $variant['error'] === null;
}

/**
 * Доверие к цепочке по запросам curl (он проверяет её по CA-файлу): хоть один https-ответ получен → true;
 * curl отказал с ошибкой проверки сертификата → false; иначе (тайм-аут, нет https) судить не по чему → true,
 * чтобы не выдумывать находку.
 *
 * Учитываются только запросы к хосту сертификата: начатые по https (дальше мог быть редирект на http)
 * или закончившиеся на https.
 *
 * @param list<array> $responses
 */
function detectCertificateTrust(array $responses, string $host): bool
{
    $host = strtolower($host);
    $isHttpsToHost = static fn (string $url): bool => isHttpsUrl($url)
        && strtolower((string) parse_url($url, PHP_URL_HOST)) === $host;

    $https = array_filter(
        $responses,
        static fn (array $response): bool => $isHttpsToHost($response['url']) || $isHttpsToHost($response['final_url'])
    );

    foreach ($https as $response) {
        if (hasResponse($response)) {
            return true;
        }
    }

    foreach ($https as $response) {
        if (isCertificateError($response['error'])) {
            return false;
        }
    }

    return true;
}

function isCertificateError(?string $error): bool
{
    return str_contains((string) $error, '(curl ' . CURL_ERROR_PEER_VERIFICATION . ')');
}

/**
 * @param array<string, array> $variants
 */
function hasWorkingHttps(array $variants, array $home): bool
{
    if (isHttpsUrl($home['final_url']) && hasResponse($home)) {
        return true;
    }

    foreach ([AVAILABILITY_VARIANT_HTTPS, AVAILABILITY_VARIANT_HTTPS_WWW] as $key) {
        if (isset($variants[$key]) && hasResponse($variants[$key])) {
            return true;
        }
    }

    return false;
}

/**
 * true — открывающиеся http-варианты ведут на https; false — хоть один остаётся на http, хотя https работает;
 * null — сравнивать нечего.
 *
 * @param array<string, array> $variants
 */
function detectHttpsRedirect(array $variants): ?bool
{
    $httpsWorks = false;
    foreach ([AVAILABILITY_VARIANT_HTTPS, AVAILABILITY_VARIANT_HTTPS_WWW] as $key) {
        $httpsWorks = $httpsWorks || (isset($variants[$key]) && hasResponse($variants[$key]));
    }

    $checked = false;
    foreach ([AVAILABILITY_VARIANT_HTTP, AVAILABILITY_VARIANT_HTTP_WWW] as $key) {
        if (!isset($variants[$key]) || !hasResponse($variants[$key])) {
            continue;
        }
        $checked = true;
        if (!isHttpsUrl($variants[$key]['final_url'])) {
            return $httpsWorks ? false : null;
        }
    }

    return $checked ? true : null;
}

/**
 * true — www и без www приводят на один хост; false — на разные; null — один из вариантов не открывается.
 *
 * @param array<string, array> $variants
 */
function detectWwwConsistency(array $variants): ?bool
{
    $plain = pickFinalHost($variants, [AVAILABILITY_VARIANT_HTTPS, AVAILABILITY_VARIANT_HTTP]);
    $www = pickFinalHost($variants, [AVAILABILITY_VARIANT_HTTPS_WWW, AVAILABILITY_VARIANT_HTTP_WWW]);

    if ($plain === null || $www === null) {
        return null;
    }

    return $plain === $www;
}

/**
 * Итоговый хост первого открывшегося варианта из списка.
 *
 * @param array<string, array> $variants
 * @param list<string>         $keys
 */
function pickFinalHost(array $variants, array $keys): ?string
{
    foreach ($keys as $key) {
        if (isset($variants[$key]) && hasResponse($variants[$key])) {
            $host = parse_url($variants[$key]['final_url'], PHP_URL_HOST);

            return is_string($host) ? strtolower($host) : null;
        }
    }

    return null;
}

function makeHomeUnavailableFinding(array $response, string $url): array
{
    [$title, $key] = match (true) {
        isCertificateError($response['error']) => ['Сайт не открывается: браузер не доверяет SSL-сертификату', 'certificate_error'],
        $response['status'] === null            => ['Сайт не отвечает', 'no_response'],
        default                                 => ['Главная страница не открывается', 'broken_redirect'],
    };

    return makeFinding(
        'availability',
        $title,
        $url,
        $key,
        (string) $response['error'],
        ['status' => $response['status'], 'redirects' => $response['redirects'], 'time_ms' => $response['time_ms']],
        null,
        'critical'
    );
}

/**
 * 4xx/5xx на главной → находка; коды защиты от ботов — нет.
 */
function makeHomeStatusFinding(array $home): ?array
{
    $status = $home['status'];

    if ($status === null || $status < HTTP_ERROR_STATUS_MIN || $home['possible_bot_protection']) {
        return null;
    }

    return makeFinding(
        'availability',
        "Главная страница отвечает кодом {$status}",
        $home['final_url'],
        (string) $status,
        'Вместо страницы сервер возвращает ошибку: покупатели и поисковики видят её же.',
        ['status' => $status, 'redirects' => $home['redirects']],
        null,
        'high'
    );
}

function makeNoHttpsRedirectFinding(string $host): array
{
    return makeFinding(
        'ssl',
        'Нет редиректа с http на https',
        "http://{$host}/",
        'no_https_redirect',
        'Сайт открывается по незащищённому http, хотя https работает: браузер помечает страницу «Не защищено».',
        null,
        null,
        'medium'
    );
}

function makeHttpsUnavailableFinding(string $host, string $error): array
{
    return makeFinding(
        'ssl',
        'Сайт не открывается по https',
        "https://{$host}/",
        'https_unavailable',
        $error,
        null,
        null,
        'high'
    );
}

/**
 * @param array<string, array> $variants
 */
function makeWwwMismatchFinding(string $host, array $variants): array
{
    $finals = array_map(static fn (array $variant): string => $variant['final_url'], $variants);

    return makeFinding(
        'seo',
        'Сайт с www и без www — разные адреса',
        "https://{$host}/",
        'www_mismatch',
        'Нет редиректа на один основной адрес: поисковики видят две копии сайта.',
        ['final_urls' => $finals],
        null,
        'medium'
    );
}

function buildAvailabilityMessage(array $home): string
{
    $message = "Главная: код {$home['status']}, {$home['time_ms']} мс.";

    return $home['possible_bot_protection'] ? $message . ' Возможно, защита от ботов.' : $message;
}

/**
 * Сводка для `diagnostics.summary_json` — без HTML и заголовков.
 */
function buildAvailabilitySummary(
    array $home,
    array $variants,
    ?bool $httpsRedirect,
    ?bool $wwwConsistent,
    ?array $ssl
): array {
    return [
        'availability' => [
            'home'           => summarizeResponse($home['url'], $home) + [
                'truncated'               => $home['truncated'],
                'possible_bot_protection' => $home['possible_bot_protection'],
            ],
            'response_ms'    => $home['time_ms'],
            'variants'       => $variants,
            'https_redirect' => $httpsRedirect,
            'www_consistent' => $wwwConsistent,
        ],
        'ssl' => $ssl,
    ];
}

/**
 * Находкам по главной (адрес совпадает) проставляет `page_id`.
 *
 * @param list<array>  $findings
 * @param list<string> $homeUrls адрес из диагностики и итоговый адрес главной
 * @return list<array>
 */
function assignPageIdToFindings(array $findings, int $pageId, array $homeUrls): array
{
    $normalized = array_map('normalizeFindingLocation', $homeUrls);

    return array_map(
        static function (array $finding) use ($pageId, $normalized): array {
            if (in_array(normalizeFindingLocation($finding['location']), $normalized, true)) {
                $finding['page_id'] = $pageId;
            }

            return $finding;
        },
        $findings
    );
}
