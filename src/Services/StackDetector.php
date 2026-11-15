<?php

declare(strict_types=1);

/**
 * Определение стека по заголовкам, cookies и разобранному HTML главной — без сети и БД.
 * Правила — `data/signatures.php`. Каждый признак даёт «вес»; сумма весов разных признаков → уверенность.
 *
 * Внутренний элемент (до `finalizeStackItems()`):
 * `key` (ключ платформы из сигнатур), `parent` (ключ CMS для темы/плагина), `category`, `name`, `version`,
 * `signals` (ключ признака → вес; один и тот же признак не считается дважды), `evidence` (источник + деталь).
 */

const STACK_SIGNATURES_FILE = ROOT_PATH . '/data/signatures.php';

const STACK_WEIGHT_STRONG = 3; // мета generator, служебный файл с версией, явный заголовок сервера / CDN
const STACK_WEIGHT_HEADER = 2; // заголовок, характерный для CMS
const STACK_WEIGHT_FIRST  = 2; // первый файл библиотеки / темы / плагина, счётчик аналитики
const STACK_WEIGHT_WEAK   = 1; // путь, cookie, маркер в HTML, повторный файл

const STACK_CONFIDENCE_HIGH_SCORE   = 3;
const STACK_CONFIDENCE_MEDIUM_SCORE = 2;

const STACK_EVIDENCE_MAX_ITEMS         = 5;   // в БД — не больше 5 источников на элемент
const STACK_EVIDENCE_DETAIL_MAX_LENGTH = 255;
const STACK_NAME_MAX_LENGTH            = 150; // как stack_items.name
const STACK_VERSION_MAX_LENGTH         = 50;  // как stack_items.version

const STACK_VERSION_PATTERN    = '\d+(?:\.\d+)+';
const STACK_VERSION_QUERY_KEYS = ['ver', 'v', 'version'];

const STACK_CATEGORY_ORDER = ['cms', 'plugin', 'theme', 'server', 'php', 'cdn', 'js_library', 'analytics', 'other'];

/** Категории, у которых версия из `?ver=` может оказаться версией CMS (WordPress подставляет её по умолчанию) */
const STACK_INHERITED_VERSION_CATEGORIES = ['theme', 'plugin', 'js_library'];

/**
 * @return array<string, mixed>
 */
function loadStackSignatures(): array
{
    return require STACK_SIGNATURES_FILE;
}

/**
 * @param array<string, mixed> $home   факты главной (`facts.availability.home`): `headers`, `body`
 * @param array<string, mixed> $parsed результат `parseHtml()`
 * @return list<array{key: ?string, parent: ?string, category: string, name: string, version: ?string,
 *                    confidence: string, evidence: list<array{source: string, detail: string}>}>
 */
function detectStack(array $home, array $parsed, array $signatures): array
{
    return finalizeStackItems(collectStackItems($home, $parsed, $signatures));
}

/**
 * @return array<string, array<string, mixed>> внутренние элементы по id
 */
function collectStackItems(array $home, array $parsed, array $signatures): array
{
    $headers = is_array($home['headers'] ?? null) ? $home['headers'] : [];
    $body = (string) ($home['body'] ?? '');
    $urls = collectResourceUrls($parsed);

    $items = detectServerStack([], $headers, $signatures);
    $items = detectPlatforms($items, $headers, $parsed, $urls, $body, $signatures['platforms'] ?? []);
    $items = detectThemesAndPlugins($items, $urls, $signatures['platforms'] ?? []);
    $items = detectLibraries($items, [...$parsed['scripts'], ...$parsed['styles']], $signatures['libraries'] ?? []);

    return detectAnalytics($items, $body, $signatures['analytics'] ?? []);
}

/**
 * Все адреса ресурсов страницы без повторов.
 *
 * @return list<string>
 */
function collectResourceUrls(array $parsed): array
{
    return array_values(array_unique([
        ...$parsed['scripts'],
        ...$parsed['styles'],
        ...array_column($parsed['links'], 'url'),
        ...$parsed['images'],
    ]));
}

/**
 * Добавляет признак элементу (создаёт элемент, если его нет). Признак с тем же ключом не суммируется;
 * версия заполняется, только если ещё неизвестна; источники — без повторов, не больше `STACK_EVIDENCE_MAX_ITEMS`.
 *
 * @param array<string, mixed> $base category, name, key?, parent?
 */
function addStackSignal(
    array $items,
    array $base,
    string $signalKey,
    int $weight,
    string $source,
    string $detail,
    ?string $version = null
): array {
    $id = stackItemId($base['category'], $base['name']);
    $item = $items[$id] ?? [
        'key'      => $base['key'] ?? null,
        'parent'   => $base['parent'] ?? null,
        'category' => $base['category'],
        'name'     => $base['name'],
        'version'  => null,
        'signals'  => [],
        'evidence' => [],
    ];

    $item['signals'][$signalKey] = max($item['signals'][$signalKey] ?? 0, $weight);
    $item['version'] ??= ($version !== null && $version !== '' ? $version : null);

    $detail = mb_substr($detail, 0, STACK_EVIDENCE_DETAIL_MAX_LENGTH);
    $evidenceKey = $source . ':' . $detail;
    if (!isset($item['evidence'][$evidenceKey]) && count($item['evidence']) < STACK_EVIDENCE_MAX_ITEMS) {
        $item['evidence'][$evidenceKey] = ['source' => $source, 'detail' => $detail];
    }

    $items[$id] = $item;

    return $items;
}

function stackItemId(string $category, string $name): string
{
    return $category . '|' . mb_strtolower($name);
}

/**
 * Сумма весов признаков → `high` / `medium` / `low`.
 */
function rateStackConfidence(int $score): string
{
    return match (true) {
        $score >= STACK_CONFIDENCE_HIGH_SCORE   => 'high',
        $score >= STACK_CONFIDENCE_MEDIUM_SCORE => 'medium',
        default                                 => 'low',
    };
}

// ─── Сервер, PHP, CDN ──────────────────────────────────────────────────────────

/**
 * @param array<string, list<string>> $headers имена в нижнем регистре
 */
function detectServerStack(array $items, array $headers, array $signatures): array
{
    $serverNames = $signatures['server_names'] ?? [];
    $cdnServers = $signatures['cdn_server_values'] ?? [];

    foreach ($headers['server'] ?? [] as $value) {
        $detail = 'server: ' . $value;
        if (preg_match('~^([A-Za-z][\w.\-]*)(?:/(\S+))?~', $value, $match) === 1) {
            $token = strtolower($match[1]);
            $version = extractVersionString($match[2] ?? '');

            $items = isset($cdnServers[$token])
                ? addStackSignal($items, ['category' => 'cdn', 'name' => $cdnServers[$token]], 'header:server', STACK_WEIGHT_STRONG, 'header', $detail)
                : addStackSignal($items, ['category' => 'server', 'name' => $serverNames[$token] ?? $match[1]], 'header:server', STACK_WEIGHT_STRONG, 'header', $detail, $version);
        }
        if (preg_match('~\bPHP/(' . STACK_VERSION_PATTERN . ')~i', $value, $php) === 1) {
            $items = addStackSignal($items, ['category' => 'php', 'name' => 'PHP'], 'header:server', STACK_WEIGHT_STRONG, 'header', $detail, $php[1]);
        }
    }

    foreach ($headers['x-powered-by'] ?? [] as $value) {
        $detail = 'x-powered-by: ' . $value;
        if (preg_match('~\bPHP(?:/(' . STACK_VERSION_PATTERN . '))?~i', $value, $php) === 1) {
            $items = addStackSignal($items, ['category' => 'php', 'name' => 'PHP'], 'header:x-powered-by', STACK_WEIGHT_STRONG, 'header', $detail, $php[1] ?? null);
            continue;
        }
        $name = mb_substr(trim($value), 0, STACK_NAME_MAX_LENGTH);
        if ($name !== '') {
            $items = addStackSignal($items, ['category' => 'other', 'name' => $name], 'header:x-powered-by', STACK_WEIGHT_STRONG, 'header', $detail);
        }
    }

    if (in_array('PHPSESSID', parseCookieNames($headers), true)) {
        $items = addStackSignal($items, ['category' => 'php', 'name' => 'PHP'], 'cookie:PHPSESSID', STACK_WEIGHT_WEAK, 'cookie', 'PHPSESSID');
    }

    foreach ($signatures['cdn_headers'] ?? [] as $header => $name) {
        $value = $headers[$header][0] ?? null;
        if ($value !== null) {
            $items = addStackSignal($items, ['category' => 'cdn', 'name' => $name], 'header:' . $header, STACK_WEIGHT_STRONG, 'header', $header . ': ' . $value);
        }
    }

    return $items;
}

/**
 * Имена cookies из `Set-Cookie`.
 *
 * @return list<string>
 */
function parseCookieNames(array $headers): array
{
    $names = [];
    foreach ($headers['set-cookie'] ?? [] as $cookie) {
        $name = trim(explode('=', (string) $cookie, 2)[0]);
        if ($name !== '') {
            $names[] = $name;
        }
    }

    return array_values(array_unique($names));
}

// ─── CMS и платформы ───────────────────────────────────────────────────────────

/**
 * @param list<string> $urls
 */
function detectPlatforms(array $items, array $headers, array $parsed, array $urls, string $body, array $platforms): array
{
    $cookieNames = parseCookieNames($headers);
    $generators = getMetaContents($parsed, 'generator');

    foreach ($platforms as $key => $platform) {
        $base = ['key' => $key, 'category' => $platform['category'], 'name' => $platform['name']];
        $id = stackItemId($base['category'], $base['name']);

        foreach ($platform['headers'] ?? [] as $header => $pattern) {
            foreach ($headers[$header] ?? [] as $value) {
                if (preg_match($pattern, $value) === 1) {
                    $items = addStackSignal($items, $base, 'header:' . $header, STACK_WEIGHT_HEADER, 'header', $header . ': ' . $value);
                    break;
                }
            }
        }

        foreach ($platform['cookies'] ?? [] as $index => $pattern) {
            $cookie = findFirstMatch($cookieNames, $pattern);
            if ($cookie !== null) {
                $items = addStackSignal($items, $base, 'cookie:' . $index, STACK_WEIGHT_WEAK, 'cookie', $cookie);
            }
        }

        if (isset($platform['generator'])) {
            foreach ($generators as $generator) {
                if (preg_match($platform['generator'], $generator, $match) === 1) {
                    $items = addStackSignal($items, $base, 'generator', STACK_WEIGHT_STRONG, 'meta', 'generator: ' . $generator, $match[1] ?? null);
                    break;
                }
            }
        }

        foreach ($platform['urls'] ?? [] as $index => $pattern) {
            $url = findFirstMatch($urls, $pattern);
            if ($url !== null) {
                $items = addStackSignal($items, $base, 'url:' . $index, STACK_WEIGHT_WEAK, 'url', $url);
            }
        }

        foreach ($platform['html'] ?? [] as $index => $pattern) {
            if (preg_match($pattern, $body, $match) === 1) {
                $items = addStackSignal($items, $base, 'html:' . $index, STACK_WEIGHT_WEAK, 'html', $match[0]);
            }
        }

        // Версия по адресам ядра — только если платформа уже найдена; отдельным признаком не считается
        if (isset($items[$id])) {
            foreach ($platform['version_urls'] ?? [] as $pattern) {
                foreach ($urls as $url) {
                    if (preg_match($pattern, $url, $match) === 1) {
                        $items = addStackSignal($items, $base, 'version_url', 0, 'url', $url, $match[1]);
                        break 2;
                    }
                }
            }
        }
    }

    return $items;
}

/**
 * @return list<string>
 */
function getMetaContents(array $parsed, string $name): array
{
    $contents = [];
    foreach ($parsed['meta'] as $meta) {
        if ($meta['name'] === $name && $meta['content'] !== '') {
            $contents[] = $meta['content'];
        }
    }

    return $contents;
}

/**
 * @param list<string> $values
 */
function findFirstMatch(array $values, string $pattern): ?string
{
    foreach ($values as $value) {
        if (preg_match($pattern, $value) === 1) {
            return $value;
        }
    }

    return null;
}

/**
 * Темы и плагины по путям — только для найденных CMS. Плагин-платформа (WooCommerce) сливается
 * с уже найденным элементом по `plugin_slug`.
 *
 * @param list<string> $urls
 */
function detectThemesAndPlugins(array $items, array $urls, array $platforms): array
{
    $pluginPlatforms = [];
    foreach ($platforms as $key => $platform) {
        if (isset($platform['plugin_slug'])) {
            $pluginPlatforms[strtolower($platform['plugin_slug'])] = ['key' => $key, 'name' => $platform['name']];
        }
    }

    foreach ($platforms as $key => $platform) {
        if ($platform['category'] !== 'cms' || !isset($items[stackItemId('cms', $platform['name'])])) {
            continue;
        }

        $kinds = [
            'theme'  => [$platform['themes'] ?? [], $platform['theme_ignore'] ?? []],
            'plugin' => [$platform['plugins'] ?? [], $platform['plugin_ignore'] ?? []],
        ];

        foreach ($kinds as $category => [$patterns, $ignore]) {
            foreach ($urls as $url) {
                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $url, $match) !== 1) {
                        continue;
                    }
                    $name = rawurldecode($match[1]);
                    if ($name === '' || isIgnoredStackName($name, $ignore)) {
                        continue;
                    }

                    $platformPlugin = $category === 'plugin' ? ($pluginPlatforms[strtolower($name)] ?? null) : null;
                    $base = $platformPlugin !== null
                        ? ['key' => $platformPlugin['key'], 'category' => 'plugin', 'name' => $platformPlugin['name']]
                        : ['category' => $category, 'name' => $name, 'parent' => $key];

                    $weight = isset($items[stackItemId($base['category'], $base['name'])]) ? STACK_WEIGHT_WEAK : STACK_WEIGHT_FIRST;
                    $version = !empty($platform['asset_versions']) ? extractQueryVersion($url) : null;
                    $items = addStackSignal($items, $base, 'url:' . $url, $weight, 'url', $url, $version);
                    break;
                }
            }
        }
    }

    return $items;
}

/**
 * @param list<string> $ignorePatterns
 */
function isIgnoredStackName(string $name, array $ignorePatterns): bool
{
    foreach ($ignorePatterns as $pattern) {
        if (preg_match($pattern, $name) === 1) {
            return true;
        }
    }

    return false;
}

// ─── Библиотеки и аналитика ────────────────────────────────────────────────────

/**
 * @param list<string> $urls адреса скриптов и стилей
 */
function detectLibraries(array $items, array $urls, array $libraries): array
{
    foreach (array_values(array_unique($urls)) as $url) {
        $path = (string) parse_url($url, PHP_URL_PATH);

        foreach ($libraries as $library) {
            $match = null;
            foreach ($library['files'] as $pattern) {
                if (preg_match($pattern, $path, $found) === 1) {
                    $match = $found;
                    break;
                }
            }
            if ($match === null) {
                continue;
            }

            $base = ['category' => 'js_library', 'name' => $library['name']];
            $weight = isset($items[stackItemId('js_library', $library['name'])]) ? STACK_WEIGHT_WEAK : STACK_WEIGHT_FIRST;
            $version = ($match[1] ?? '') !== '' ? $match[1] : extractUrlVersion($url);
            $items = addStackSignal($items, $base, 'url:' . $url, $weight, 'url', $url, $version);
            break;
        }
    }

    return $items;
}

function detectAnalytics(array $items, string $body, array $analytics): array
{
    foreach ($analytics as $counter) {
        foreach ($counter['patterns'] as $index => $pattern) {
            if (preg_match($pattern, $body, $match) === 1) {
                $base = ['category' => 'analytics', 'name' => $counter['name']];
                $items = addStackSignal($items, $base, 'html:' . $index, STACK_WEIGHT_FIRST, 'html', $match[0]);
            }
        }
    }

    return $items;
}

// ─── Версии ────────────────────────────────────────────────────────────────────

/**
 * Версия из `?ver=` / `?v=` / `?version=`, иначе из пути (`/3.6.0/`, `@3.6.0/`).
 */
function extractUrlVersion(string $url): ?string
{
    $version = extractQueryVersion($url);
    if ($version !== null) {
        return $version;
    }

    $path = (string) parse_url($url, PHP_URL_PATH);
    if (preg_match('~[@/]v?(' . STACK_VERSION_PATTERN . ')/~', $path, $match) === 1) {
        return $match[1];
    }

    return null;
}

/**
 * Версия только из `?ver=` / `?v=` / `?version=`.
 */
function extractQueryVersion(string $url): ?string
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    foreach (STACK_VERSION_QUERY_KEYS as $key) {
        $version = is_string($query[$key] ?? null) ? extractVersionString($query[$key], true) : null;
        if ($version !== null) {
            return $version;
        }
    }

    return null;
}

/**
 * `1.18.0` из `1.18.0`, `1.18.0-ubuntu`; `$exact` — строка целиком должна быть версией.
 */
function extractVersionString(string $value, bool $exact = false): ?string
{
    $pattern = $exact ? '~^(' . STACK_VERSION_PATTERN . ')$~' : '~^(' . STACK_VERSION_PATTERN . ')~';

    return preg_match($pattern, trim($value), $match) === 1 ? $match[1] : null;
}

// ─── Служебные файлы для версии ────────────────────────────────────────────────

/**
 * Адреса служебных файлов CMS от корня сайта. Неизвестная CMS или без правил → пустой список.
 *
 * @return list<array{url: string, version_pattern: string}>
 */
function buildVersionProbeUrls(string $cms, string $baseUrl, array $signatures): array
{
    $parts = parse_url($baseUrl);
    if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
        return [];
    }

    $origin = strtolower($parts['scheme']) . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    $probes = [];
    foreach ($signatures['platforms'][$cms]['probes'] ?? [] as $probe) {
        $probes[] = ['url' => $origin . '/' . ltrim($probe['path'], '/'), 'version_pattern' => $probe['version']];
    }

    return $probes;
}

function extractVersionFromProbe(string $body, string $pattern): ?string
{
    return preg_match($pattern, $body, $match) === 1 && ($match[1] ?? '') !== '' ? $match[1] : null;
}

/**
 * Версия из служебного файла — сильный признак найденной платформы.
 */
function applyVersionProbe(array $items, string $platformKey, string $url, string $version): array
{
    foreach ($items as $item) {
        if ($item['key'] === $platformKey) {
            $base = ['key' => $platformKey, 'category' => $item['category'], 'name' => $item['name']];

            return addStackSignal($items, $base, 'probe', STACK_WEIGHT_STRONG, 'probe', $url . ' → ' . $version, $version);
        }
    }

    return $items;
}

// ─── Итог ──────────────────────────────────────────────────────────────────────

/**
 * Внутренние элементы → список для фактов и БД: уверенность по сумме весов, длины под колонки
 * `stack_items`, порядок — по категории, затем по уверенности. Версия темы / плагина / библиотеки,
 * совпавшая с версией CMS, сбрасывается: это версия ядра из `?ver=`, а не своя.
 */
function finalizeStackItems(array $items): array
{
    $cmsVersions = [];
    foreach ($items as $item) {
        if ($item['category'] === 'cms' && $item['version'] !== null) {
            $cmsVersions[] = $item['version'];
        }
    }

    $result = [];
    foreach ($items as $item) {
        $version = $item['version'];
        if (in_array($item['category'], STACK_INHERITED_VERSION_CATEGORIES, true) && in_array($version, $cmsVersions, true)) {
            $version = null;
        }

        $score = array_sum($item['signals']);
        $result[] = [
            'key'        => $item['key'],
            'parent'     => $item['parent'],
            'category'   => $item['category'],
            'name'       => mb_substr($item['name'], 0, STACK_NAME_MAX_LENGTH),
            'version'    => $version !== null ? mb_substr($version, 0, STACK_VERSION_MAX_LENGTH) : null,
            'confidence' => rateStackConfidence($score),
            'evidence'   => array_values($item['evidence']),
            'score'      => $score,
        ];
    }

    $order = array_flip(STACK_CATEGORY_ORDER);
    usort($result, static fn (array $a, array $b): int => [$order[$a['category']] ?? PHP_INT_MAX, -$a['score'], $a['name']]
        <=> [$order[$b['category']] ?? PHP_INT_MAX, -$b['score'], $b['name']]);

    return array_map(static function (array $item): array {
        unset($item['score']);

        return $item;
    }, $result);
}
