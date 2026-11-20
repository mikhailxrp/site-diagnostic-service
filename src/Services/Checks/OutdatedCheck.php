<?php

declare(strict_types=1);

/**
 * Шаг `outdated` без БД: версии найденного стека (CMS, PHP, JS-библиотеки, плагины WordPress) сравниваются со
 * справочником `latest_versions` (`DatabaseUpdater.php`); плагины WordPress — онлайн с кэшем на диске.
 * Находка `outdated` — версия ниже актуальной или ветка без поддержки. Без версии находки нет.
 *
 * Серьёзности — OUTDATED_SEVERITY (категория стека → «устарела» / «ветка без поддержки»): правка одной строкой.
 */

require_once ROOT_PATH . '/src/Services/DatabaseUpdater.php';
require_once ROOT_PATH . '/src/Services/FindingFactory.php';
require_once ROOT_PATH . '/src/Services/DiagnosticNoLongerRunningException.php';

const OUTDATED_SEVERITY = [
    'cms'        => ['outdated' => 'medium', 'unsupported' => 'high'],
    'php'        => ['outdated' => 'low', 'unsupported' => 'high'],
    'js_library' => ['outdated' => 'low', 'unsupported' => 'medium'],
    'plugin'     => ['outdated' => 'medium', 'unsupported' => 'medium'],
];

const OUTDATED_DATABASE_KEY = 'latest_versions';
const OUTDATED_WP_PARENT    = 'wordpress';
const OUTDATED_PHP_KEY      = 'php';

const OUTDATED_PLUGIN_CACHE_DIR           = ROOT_PATH . '/storage/cache/wp-plugins';
const OUTDATED_PLUGIN_CACHE_TTL           = 7 * 24 * 3600; // неделя: каталог плагинов меняется не чаще
const WP_PLUGIN_LOOKUP_MAX_PER_DIAGNOSTIC = 30;            // лимит онлайн-запросов к каталогу WP (решение владельца, 20.11.2026)

const OUTDATED_NO_STACK_MESSAGE = 'Нет данных стека: шаг «Определение стека» не выполнен.';

/**
 * @param array<string, mixed>       $context  контекст шага; `facts.stack.items[].stack_item_id` — id в `stack_items`
 * @param callable(string $url): array $request для плагинов WordPress
 * @param array<string, mixed>|null  $database справочник версий; null — `loadDatabase()`
 * @param array<string, array>|null  $sources  null — `data/version-sources.php`
 * @return array{status: string, message: string, facts: array, findings: list<array>}
 */
function runOutdatedCheck(
    array $context,
    callable $request,
    ?array $database = null,
    ?array $sources = null,
    ?string $cacheDir = null,
    ?int $now = null,
    int $maxPluginLookups = WP_PLUGIN_LOOKUP_MAX_PER_DIAGNOSTIC
): array {
    $items = $context['facts']['stack']['items'] ?? null;
    if (!is_array($items)) {
        return ['status' => 'skipped', 'message' => OUTDATED_NO_STACK_MESSAGE, 'facts' => [], 'findings' => []];
    }

    $database ??= loadDatabase(OUTDATED_DATABASE_KEY);
    $sources ??= loadVersionSources();
    $cacheDir ??= OUTDATED_PLUGIN_CACHE_DIR;
    $now ??= time();
    $databaseDate = getDatabaseDate($database);

    $latest = [];
    $findings = [];
    $compared = 0;
    $onlineLookups = 0;
    $skippedLookups = 0;
    $sessionLookups = [];

    foreach ($items as $item) {
        $version = normalizeVersion(is_string($item['version'] ?? null) ? $item['version'] : null);
        $target = resolveVersionTarget($item, $sources);
        if ($version === null || $target === null) {
            continue;
        }

        if ($target['source'] === VERSION_SOURCE_WP_PLUGIN) {
            $slug = $target['slug'];
            if (preg_match(WP_PLUGIN_SLUG_PATTERN, $slug) !== 1) {
                continue;
            }
            if (array_key_exists($slug, $sessionLookups)) {
                $known = $sessionLookups[$slug];
            } else {
                $cached = readWordpressPluginCache($slug, $cacheDir, $now);
                if ($cached['hit']) {
                    $known = $cached['latest'] !== null ? ['latest' => $cached['latest']] : null;
                    $sessionLookups[$slug] = $known;
                } elseif ($onlineLookups >= max(0, $maxPluginLookups)) {
                    $skippedLookups++;
                    continue;
                } else {
                    $onlineLookups++;
                    $known = fetchWordpressPluginCached($slug, $request, $cacheDir, $now);
                    $sessionLookups[$slug] = $known;
                }
            }
        } else {
            $known = $database['items'][$target['key']] ?? null;
        }

        $latestVersion = is_array($known) && is_string($known['latest'] ?? null) ? $known['latest'] : null;
        if ($latestVersion === null) {
            continue;
        }

        $compared++;
        // Версия сайта новее справочника (сборка до релиза) — «актуальная: X» ниже неё только сбивала бы с толку
        if (isset($item['stack_item_id']) && compareVersions($version, $latestVersion) <= 0) {
            $latest[(int) $item['stack_item_id']] = $latestVersion;
        }

        $verdict = judgeVersion($version, $latestVersion, $known, $target);
        if ($verdict !== null) {
            $findings[] = makeOutdatedFinding($item, $version, $latestVersion, $verdict, $databaseDate);
        }
    }

    return [
        'status'   => 'done',
        'message'  => buildOutdatedMessage($compared, count($findings), $database !== null, $databaseDate, $skippedLookups),
        'facts'    => [
            'latest'    => $latest,
            'databases' => [OUTDATED_DATABASE_KEY => [
                'title'      => getDatabaseSources()[OUTDATED_DATABASE_KEY]['title'],
                'updated_at' => $databaseDate,
            ]],
            'plugin_lookups_skipped' => $skippedLookups,
        ],
        'findings' => $findings,
    ];
}

/**
 * Откуда брать актуальную версию элемента: строка `data/version-sources.php` по `key`, PHP — по категории, плагин
 * WordPress — по имени (slug). null — источника нет (Битрикс, Tilda, тема, сервер …).
 *
 * @param array<string, mixed>  $item
 * @param array<string, array> $sources
 * @return array{key: string, source: string, slug?: string, eol_below?: string, category: string}|null
 */
function resolveVersionTarget(array $item, array $sources): ?array
{
    $category = (string) ($item['category'] ?? '');
    $key = is_string($item['key'] ?? null) ? $item['key'] : null;
    if ($category === 'php') {
        $key = OUTDATED_PHP_KEY;
    }

    if ($key !== null && isset($sources[$key])) {
        return ['key' => $key, 'category' => $category] + $sources[$key];
    }

    if ($category === 'plugin' && ($item['parent'] ?? null) === OUTDATED_WP_PARENT) {
        $slug = strtolower((string) ($item['name'] ?? ''));

        return ['key' => $slug, 'category' => $category, 'source' => VERSION_SOURCE_WP_PLUGIN, 'slug' => $slug];
    }

    return null;
}

/**
 * null — версия актуальна. Ветка без поддержки: PHP — ветки нет среди поддерживаемых на php.net; остальное — ниже
 * `eol_below`.
 *
 * @param array<string, mixed> $known  запись справочника (`latest`, у PHP — `branches`)
 * @param array<string, mixed> $target
 * @return array{unsupported: bool, major_behind: bool, branch_latest: ?string}|null
 */
function judgeVersion(string $version, string $latest, array $known, array $target): ?array
{
    $branches = is_array($known['branches'] ?? null) ? $known['branches'] : [];
    $branch = getMinorBranch($version);
    $branchLatest = is_string($branches[$branch] ?? null) ? $branches[$branch] : null;

    if ($branches !== []) {
        $unsupported = $branchLatest === null && compareVersions($version, $latest) < 0;
    } else {
        $eolBelow = $target['eol_below'] ?? null;
        $unsupported = is_string($eolBelow) && compareVersions($version, $eolBelow) < 0;
    }

    // В поддерживаемой ветке PHP сравнение — с последней версией этой ветки, а не с самой новой веткой
    $outdated = $branchLatest !== null ? compareVersions($version, $branchLatest) < 0 : compareVersions($version, $latest) < 0;
    if (!$unsupported && !$outdated) {
        return null;
    }

    return [
        'unsupported'   => $unsupported,
        'major_behind'  => getMajorVersion($version) < getMajorVersion($latest),
        'branch_latest' => $branchLatest,
    ];
}

/**
 * @param array<string, mixed> $item
 * @param array{unsupported: bool, major_behind: bool, branch_latest: ?string} $verdict
 */
function makeOutdatedFinding(array $item, string $version, string $latest, array $verdict, ?string $databaseDate): array
{
    $category = (string) $item['category'];
    $name = (string) $item['name'];
    $severities = OUTDATED_SEVERITY[$category] ?? OUTDATED_SEVERITY['js_library'];

    if ($verdict['unsupported']) {
        $title = "{$name} {$version}: ветка не поддерживается (актуальная {$latest})";
        $details = "Версия {$version} из ветки, которая больше не получает исправлений безопасности. Актуальная — {$latest}.";
    } else {
        $title = "{$name} {$version} устарел (актуальная {$latest})";
        $details = $verdict['branch_latest'] !== null
            ? "В ветке " . getMinorBranch($version) . " вышла {$verdict['branch_latest']}, самая новая версия — {$latest}."
            : "Актуальная версия — {$latest}.";
        if ($verdict['major_behind']) {
            $details .= ' Отстала на мажорную версию: обновление может потребовать правок сайта.';
        }
    }

    return makeFinding(
        'outdated',
        $title,
        null,
        strtolower($category . '|' . $name . '|' . $version),
        $details,
        [
            'stack_item_id' => isset($item['stack_item_id']) ? (int) $item['stack_item_id'] : null,
            'name'          => $name,
            'version'       => $version,
            'latest'        => $latest,
            'branch_latest' => $verdict['branch_latest'],
            'unsupported'   => $verdict['unsupported'],
            'major_behind'  => $verdict['major_behind'],
            'database_date' => $databaseDate,
        ],
        null,
        $severities[$verdict['unsupported'] ? 'unsupported' : 'outdated']
    );
}

/**
 * Читает кэш каталога плагинов WordPress.
 * `hit = true` — свежий кэш найден (`latest` может быть `null`, если плагина нет в каталоге).
 *
 * @return array{hit: bool, latest: ?string}
 */
function readWordpressPluginCache(string $slug, string $cacheDir, int $now): array
{
    if (preg_match(WP_PLUGIN_SLUG_PATTERN, $slug) !== 1) {
        return ['hit' => true, 'latest' => null];
    }

    $cacheFile = $cacheDir . '/' . $slug . '.json';
    $cached = readDatabaseFile($cacheFile);
    if ($cached !== null && ($now - (int) ($cached['fetched_at'] ?? 0)) < OUTDATED_PLUGIN_CACHE_TTL) {
        return ['hit' => true, 'latest' => is_string($cached['version'] ?? null) ? $cached['version'] : null];
    }

    return ['hit' => false, 'latest' => null];
}

/**
 * Запрашивает версию плагина WordPress в каталоге и при штатном ответе сохраняет в кэш.
 *
 * @return array{latest: string}|null
 */
function fetchWordpressPluginCached(string $slug, callable $request, string $cacheDir, int $now): ?array
{
    try {
        $info = fetchWordpressPluginVersion($slug, $request);
    } catch (DiagnosticNoLongerRunningException $e) {
        throw $e;
    } catch (Throwable) {
        return null; // каталог недоступен — без находки, в кэш не пишется
    }
    if ($info['status'] === WP_PLUGIN_ERROR) {
        return null;
    }

    $cacheFile = $cacheDir . '/' . $slug . '.json';
    try {
        saveDatabaseFile($cacheFile, ['fetched_at' => $now, 'status' => $info['status'], 'version' => $info['version']]);
    } catch (Throwable) {
        // кэш не записался — в следующий раз каталог спросят снова
    }

    return $info['version'] !== null ? ['latest' => $info['version']] : null;
}

/**
 * Версия плагина из каталога WordPress с кэшем: ответ «есть» и «нет в каталоге» хранится неделю, сбой сети — нет.
 *
 * @return array{latest: string}|null null — плагина нет в каталоге или каталог не ответил
 */
function lookupWordpressPlugin(string $slug, callable $request, string $cacheDir, int $now): ?array
{
    if (preg_match(WP_PLUGIN_SLUG_PATTERN, $slug) !== 1) {
        return null;
    }

    $cached = readWordpressPluginCache($slug, $cacheDir, $now);
    if ($cached['hit']) {
        return $cached['latest'] !== null ? ['latest' => $cached['latest']] : null;
    }

    return fetchWordpressPluginCached($slug, $request, $cacheDir, $now);
}

function buildOutdatedMessage(
    int $compared,
    int $outdated,
    bool $hasDatabase,
    ?string $databaseDate,
    int $skippedPluginLookups = 0
): string {
    $database = match (true) {
        !$hasDatabase          => 'база версий не загружена',
        $databaseDate === null => 'дата базы версий неизвестна',
        default                => 'база версий от ' . date('d.m.Y', (int) strtotime($databaseDate)),
    };

    $message = "Сравнено версий: {$compared}, устарело: {$outdated}; {$database}";
    if ($skippedPluginLookups > 0) {
        $message .= "; пропущено по лимиту онлайн-проверки плагинов: {$skippedPluginLookups}";
    }

    return $message;
}
