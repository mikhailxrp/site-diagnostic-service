<?php

declare(strict_types=1);

/**
 * Проверка URL запросов Chrome на этапе перехвата (без браузера): защита от SSRF через
 * локальные/приватные адреса и опасные схемы, строгий режим `file:///` + `data:` для печати PDF
 * и TTL-кэш DNS-резолвера, чтобы не повторять A/AAAA-запросы для каждой картинки одной страницы.
 */

require_once ROOT_PATH . '/src/Services/UrlValidator.php';

const BROWSER_ALLOWED_NETWORK_SCHEMES = ['http', 'https', 'ws', 'wss'];
const BROWSER_SAFE_LOCAL_SCHEMES      = ['data', 'blob'];
const BROWSER_SAFE_ABOUT_URLS         = ['about:blank', 'about:srcdoc'];
const BROWSER_PDF_FILE_PREFIX         = 'file:///';
const BROWSER_PDF_UNC_PREFIX          = 'file:////';
const BROWSER_DNS_CACHE_TTL_SECONDS   = 15;
const BROWSER_DNS_CACHE_MAX_HOSTS     = 256;
const BROWSER_LOG_URL_MAX_LENGTH      = 300;
const BROWSER_MIN_PORT                = 1;
const BROWSER_MAX_PORT                = 65535;

/**
 * Разрешён ли запрос страницы/подресурса в Chrome: публичные http/https/ws/wss, data/blob и about:blank|srcdoc.
 * При сбое резолвера закрывается безопасно (fail-closed -> false).
 *
 * @param callable(string $host): list<string> $resolver
 */
function isBrowserRequestAllowed(string $url, callable $resolver): bool
{
    $scheme = extractBrowserUrlScheme($url);
    if ($scheme === null) {
        return false;
    }

    if (in_array($scheme, BROWSER_SAFE_LOCAL_SCHEMES, true)) {
        return true;
    }
    if ($scheme === 'about') {
        return isSafeBrowserAboutUrl($url);
    }
    if (!in_array($scheme, BROWSER_ALLOWED_NETWORK_SCHEMES, true)) {
        return false;
    }

    if (preg_match('/[\s\x00-\x1F\x7F]/u', $url) !== 0) {
        return false;
    }

    $schemePrefix = $scheme . '://';
    if (!str_starts_with(strtolower($url), $schemePrefix)) {
        return false;
    }

    $afterScheme = substr($url, strlen($schemePrefix));
    $authority = preg_split('/[\/?#]/', $afterScheme, 2)[0] ?? '';
    if ($authority === '' || str_contains($authority, '\\') || str_contains($authority, '@')) {
        return false;
    }

    $parts = parse_url($url);
    if ($parts === false || !isset($parts['host']) || $parts['host'] === '') {
        return false;
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        return false;
    }
    if (isset($parts['port']) && ((int) $parts['port'] < BROWSER_MIN_PORT || (int) $parts['port'] > BROWSER_MAX_PORT)) {
        return false;
    }

    $host = normalizeHostName((string) $parts['host']);
    if ($host === null) {
        return false;
    }

    try {
        return validateSiteHost($host, $resolver) === null;
    } catch (Throwable) {
        return false;
    }
}

/**
 * Разрешён ли запрос при печати PDF: только локальные `file:///` (без UNC и `..`), `data:` и `about:blank|srcdoc`.
 */
function isBrowserPdfRequestAllowed(string $url): bool
{
    $scheme = extractBrowserUrlScheme($url);
    if ($scheme === null) {
        return false;
    }

    if ($scheme === 'data') {
        return true;
    }
    if ($scheme === 'about') {
        return isSafeBrowserAboutUrl($url);
    }
    if ($scheme !== 'file') {
        return false;
    }

    if (preg_match('/[\x00-\x1F\x7F]/u', $url) !== 0) {
        return false;
    }

    $lowerUrl = strtolower($url);
    if (!str_starts_with($lowerUrl, BROWSER_PDF_FILE_PREFIX) || str_starts_with($lowerUrl, BROWSER_PDF_UNC_PREFIX)) {
        return false;
    }

    $parts = parse_url($url);
    if ($parts === false || ($parts['host'] ?? '') !== '' || !isset($parts['path']) || $parts['path'] === '') {
        return false;
    }

    $decodedPath = rawurldecode((string) $parts['path']);
    if (
        str_contains($decodedPath, "\0")
        || str_contains($decodedPath, '\\')
        || str_starts_with($decodedPath, '//')
        || preg_match('~(?:^|/)\.\.(?:/|$)~', $decodedPath) === 1
    ) {
        return false;
    }

    return true;
}

/**
 * Кэширующая обёртка для DNS-резолвера на время прохода страницы (не кэширует исключения).
 *
 * @param callable(string $host): list<string> $resolver
 * @param callable(): int|null                 $clock
 * @return callable(string $host): list<string>
 */
function createCachedBrowserResolver(
    callable $resolver,
    int $ttlSeconds = BROWSER_DNS_CACHE_TTL_SECONDS,
    ?callable $clock = null
): callable {
    $clock ??= static fn (): int => time();
    $cache = [];

    return static function (string $host) use ($resolver, $ttlSeconds, $clock, &$cache): array {
        $now = (int) $clock();
        if (isset($cache[$host]) && ($now - $cache[$host]['ts']) < $ttlSeconds) {
            return $cache[$host]['ips'];
        }

        $ips = $resolver($host);
        if (!isset($cache[$host]) && count($cache) >= BROWSER_DNS_CACHE_MAX_HOSTS) {
            array_shift($cache);
        }
        $cache[$host] = ['ips' => $ips, 'ts' => $now];

        return $ips;
    };
}

/**
 * Безопасный вид адреса для лога: без query, фрагмента и длинных `data:`-вложений.
 */
function sanitizeBrowserLogUrl(string $url): string
{
    if (preg_match('/^data:/i', $url) === 1) {
        return 'data:...';
    }

    $stripped = (string) preg_replace('~[?#].*$~s', '', $url);

    return mb_substr(mb_scrub($stripped, 'UTF-8'), 0, BROWSER_LOG_URL_MAX_LENGTH, 'UTF-8');
}

function extractBrowserUrlScheme(string $url): ?string
{
    if ($url === '' || preg_match('/^([a-z][a-z0-9+.\-]*):/i', $url, $match) !== 1) {
        return null;
    }

    return strtolower($match[1]);
}

function isSafeBrowserAboutUrl(string $url): bool
{
    $base = strtolower(explode('#', explode('?', $url, 2)[0], 2)[0]);

    return in_array($base, BROWSER_SAFE_ABOUT_URLS, true);
}
