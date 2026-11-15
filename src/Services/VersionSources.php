<?php

declare(strict_types=1);

/**
 * Актуальные версии из внешних источников: npm, Packagist, GitHub releases, ядро WordPress, ветки PHP с php.net,
 * плагины WordPress. Сеть передаётся параметром (`$request` — как `httpRequest()`), разбор — чистые функции.
 * Справочник `latest-versions` собирает `updateLatestVersionsDatabase()`: упавший источник оставляет прошлые данные.
 */

require_once ROOT_PATH . '/src/Services/VersionService.php';

const VERSION_SOURCES_FILE = ROOT_PATH . '/data/version-sources.php';

const VERSION_NPM_URL           = 'https://registry.npmjs.org/%s/latest';
const VERSION_PACKAGIST_URL     = 'https://repo.packagist.org/p2/%s.json';
const VERSION_GITHUB_URL        = 'https://api.github.com/repos/%s/releases?per_page=50';
const VERSION_WORDPRESS_URL     = 'https://api.wordpress.org/core/version-check/1.7/';
const VERSION_PHP_URL           = 'https://www.php.net/releases/active.php';
const VERSION_WP_PLUGIN_URL     = 'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information'
    . '&request%%5Bslug%%5D=%s&request%%5Bfields%%5D%%5Bsections%%5D=0';

const VERSION_SOURCE_WP_PLUGIN = 'wp-plugin';

const WP_PLUGIN_FOUND     = 'found';
const WP_PLUGIN_NOT_FOUND = 'not_found';
const WP_PLUGIN_ERROR     = 'error';

/** slug плагина WordPress: только такие отправляются в API и кладутся в кэш как имя файла */
const WP_PLUGIN_SLUG_PATTERN = '~^[a-z0-9][a-z0-9_-]{0,99}$~';

/**
 * @return array<string, array<string, string>>
 */
function loadVersionSources(): array
{
    $sources = require VERSION_SOURCES_FILE;
    if (!is_array($sources)) {
        throw new RuntimeException('Файл источников версий повреждён: ' . VERSION_SOURCES_FILE);
    }

    return $sources;
}

/**
 * Справочник версий: по каждому ключу из `data/version-sources.php` (кроме плагинов WordPress) — последняя версия
 * (у PHP ещё и ветки) и дата. Источник упал — его прошлая запись остаётся, ключ попадает в `failed`.
 *
 * @param array<string, mixed>|null $previous прошлый справочник
 * @param array<string, array>|null $sources  null — из файла
 * @return array{data: array, updated: list<string>, failed: array<string, string>}
 */
function updateLatestVersionsDatabase(?array $previous, callable $request, ?array $sources = null): array
{
    $sources ??= loadVersionSources();
    $previousItems = is_array($previous['items'] ?? null) ? $previous['items'] : [];
    $items = [];
    $updated = [];
    $failed = [];

    foreach ($sources as $key => $source) {
        if (($source['source'] ?? '') === VERSION_SOURCE_WP_PLUGIN) {
            continue;
        }

        try {
            $item = fetchLatestVersion($source, $request);
            $items[$key] = $item + ['source' => $source['source'], 'updated_at' => date(DATE_ATOM)];
            $updated[] = (string) $key;
        } catch (Throwable $e) {
            $failed[(string) $key] = $e->getMessage();
            if (isset($previousItems[$key])) {
                $items[$key] = $previousItems[$key];
            }
        }
    }

    return [
        'data'    => ['updated_at' => date(DATE_ATOM), 'items' => $items],
        'updated' => $updated,
        'failed'  => $failed,
    ];
}

/**
 * @param array<string, string> $source строка из `data/version-sources.php`
 * @return array{latest: string, branches?: array<string, string>}
 * @throws RuntimeException источник не ответил или ответ не разобрался
 */
function fetchLatestVersion(array $source, callable $request): array
{
    return match ($source['source'] ?? '') {
        'npm'       => ['latest' => parseNpmLatest(fetchVersionSource(sprintf(VERSION_NPM_URL, $source['package']), $request))],
        'packagist' => ['latest' => parsePackagistLatest(
            fetchVersionSource(sprintf(VERSION_PACKAGIST_URL, $source['package']), $request),
            $source['package']
        )],
        'github'    => ['latest' => parseGithubLatest(fetchVersionSource(sprintf(VERSION_GITHUB_URL, $source['repo']), $request))],
        'wordpress' => ['latest' => parseWordpressCoreLatest(fetchVersionSource(VERSION_WORDPRESS_URL, $request))],
        'php'       => parsePhpActiveBranches(fetchVersionSource(VERSION_PHP_URL, $request)),
        default     => throw new RuntimeException('Неизвестный источник версий: ' . ($source['source'] ?? '—')),
    };
}

/**
 * Тело ответа 2xx; иначе исключение с понятной причиной.
 */
function fetchVersionSource(string $url, callable $request): string
{
    $response = $request($url);
    $status = $response['status'] ?? null;
    if (!is_int($status) || $status < 200 || $status >= 300) {
        $reason = $status === null ? ($response['error'] ?? 'нет ответа') : "код {$status}";
        throw new RuntimeException("{$url}: {$reason}");
    }
    if (!empty($response['truncated'])) {
        throw new RuntimeException("{$url}: ответ обрезан по размеру");
    }

    return (string) ($response['body'] ?? '');
}

/**
 * @return array<mixed>
 */
function decodeVersionJson(string $body, string $what): array
{
    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException("{$what}: ответ не JSON");
    }

    return $data;
}

function requireVersion(?string $version, string $what): string
{
    if ($version === null) {
        throw new RuntimeException("{$what}: в ответе нет стабильной версии");
    }

    return $version;
}

/**
 * npm `/<пакет>/latest` → `version`.
 */
function parseNpmLatest(string $body): string
{
    $data = decodeVersionJson($body, 'npm');
    $version = is_string($data['version'] ?? null) ? $data['version'] : '';

    return requireVersion(isStableVersion($version) ? normalizeVersion($version) : null, 'npm');
}

/**
 * Packagist p2 → самая новая стабильная из `packages[<пакет>][].version`.
 */
function parsePackagistLatest(string $body, string $package): string
{
    $data = decodeVersionJson($body, 'Packagist');
    $versions = [];
    foreach ($data['packages'][$package] ?? [] as $release) {
        if (is_array($release) && is_string($release['version'] ?? null)) {
            $versions[] = $release['version'];
        }
    }

    return requireVersion(pickLatestStableVersion($versions), 'Packagist');
}

/**
 * GitHub releases → самая новая стабильная (без черновиков и пред-релизов). Берётся максимум, а не первый:
 * патчи старых веток (Magento 2.4.6-p13) публикуются позже новой ветки.
 */
function parseGithubLatest(string $body): string
{
    $versions = [];
    foreach (decodeVersionJson($body, 'GitHub') as $release) {
        if (!is_array($release) || !empty($release['draft']) || !empty($release['prerelease'])) {
            continue;
        }
        if (is_string($release['tag_name'] ?? null)) {
            $versions[] = $release['tag_name'];
        }
    }

    return requireVersion(pickLatestStableVersion($versions), 'GitHub');
}

/**
 * api.wordpress.org/core/version-check → `offers[].version`, самая новая.
 */
function parseWordpressCoreLatest(string $body): string
{
    $versions = [];
    foreach (decodeVersionJson($body, 'WordPress')['offers'] ?? [] as $offer) {
        if (is_array($offer) && is_string($offer['version'] ?? null)) {
            $versions[] = $offer['version'];
        }
    }

    return requireVersion(pickLatestStableVersion($versions), 'WordPress');
}

/**
 * php.net `releases/active.php` → поддерживаемые ветки `{"8": {"8.2": {"version": "8.2.35"}}}`.
 *
 * @return array{latest: string, branches: array<string, string>}
 */
function parsePhpActiveBranches(string $body): array
{
    $branches = [];
    foreach (decodeVersionJson($body, 'php.net') as $major) {
        foreach (is_array($major) ? $major : [] as $branch => $release) {
            $version = is_array($release) && is_string($release['version'] ?? null) ? normalizeVersion($release['version']) : null;
            if ($version !== null) {
                $branches[(string) $branch] = $version;
            }
        }
    }

    $latest = requireVersion(pickLatestStableVersion(array_values($branches)), 'php.net');
    uksort($branches, 'version_compare');

    return ['latest' => $latest, 'branches' => $branches];
}

/**
 * Версия плагина из каталога WordPress. `not_found` — плагина нет в каталоге (платный или закрыт).
 *
 * @return array{status: string, version: ?string}
 */
function fetchWordpressPluginVersion(string $slug, callable $request): array
{
    if (preg_match(WP_PLUGIN_SLUG_PATTERN, $slug) !== 1) {
        return ['status' => WP_PLUGIN_NOT_FOUND, 'version' => null];
    }

    $response = $request(sprintf(VERSION_WP_PLUGIN_URL, rawurlencode($slug)));

    return parseWordpressPluginInfo($response);
}

/**
 * @param array<string, mixed> $response
 * @return array{status: string, version: ?string}
 */
function parseWordpressPluginInfo(array $response): array
{
    $status = $response['status'] ?? null;
    $data = json_decode((string) ($response['body'] ?? ''), true);

    if (is_array($data) && is_string($data['version'] ?? null) && normalizeVersion($data['version']) !== null) {
        return ['status' => WP_PLUGIN_FOUND, 'version' => normalizeVersion($data['version'])];
    }
    // Каталог отвечает 404 / `{"error": "Plugin not found."}` / `{"error": "closed"}` — плагина там нет
    if ($status === 404 || (is_array($data) && isset($data['error']))) {
        return ['status' => WP_PLUGIN_NOT_FOUND, 'version' => null];
    }

    return ['status' => WP_PLUGIN_ERROR, 'version' => null];
}
