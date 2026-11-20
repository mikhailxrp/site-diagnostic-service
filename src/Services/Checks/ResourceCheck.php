<?php

declare(strict_types=1);

/**
 * Шаг `resources` без БД: ресурсы главной и найденных страниц магазина (скрипты, стили, картинки, шрифты, иконки,
 * фреймы) и `url()` из inline CSS и из скачанных CSS проверяются запросами, каждый адрес — один раз; каждый битый
 * ресурс — одна находка `broken_resource` со списком страниц.
 * Сеть передаётся параметром (`$request`). PHP-ошибки и mixed content — `PhpErrorCheck.php`,
 * `MixedContentCheck.php`, их собирает шаг в `DiagnosticRunner.php`.
 */

require_once ROOT_PATH . '/src/Services/HtmlParser.php';
require_once ROOT_PATH . '/src/Services/FindingFactory.php';

const RESOURCES_NO_HOME_MESSAGE = 'Нет данных главной страницы: шаг «Доступность сайта» не выполнен.';

const RESOURCE_TYPE_SCRIPT = 'script';
const RESOURCE_TYPE_STYLE  = 'style';
const RESOURCE_TYPE_IMAGE  = 'image';
const RESOURCE_TYPE_FONT   = 'font';
const RESOURCE_TYPE_ICON   = 'icon';
const RESOURCE_TYPE_FRAME  = 'frame';
const RESOURCE_TYPE_OTHER  = 'other';

const RESOURCE_TYPE_LABELS = [
    RESOURCE_TYPE_SCRIPT => 'скрипт',
    RESOURCE_TYPE_STYLE  => 'файл стилей',
    RESOURCE_TYPE_IMAGE  => 'картинка',
    RESOURCE_TYPE_FONT   => 'шрифт',
    RESOURCE_TYPE_ICON   => 'иконка',
    RESOURCE_TYPE_FRAME  => 'фрейм',
    RESOURCE_TYPE_OTHER  => 'файл',
];

/** `rel` ссылок `<link>`, которые браузер загружает (preconnect, canonical и т. п. — не ресурсы) */
const RESOURCE_ICON_RELS    = ['icon', 'apple-touch-icon', 'apple-touch-icon-precomposed', 'mask-icon'];
const RESOURCE_PRELOAD_RELS = ['preload', 'modulepreload'];

const RESOURCE_FONT_EXTENSIONS  = ['woff', 'woff2', 'ttf', 'otf', 'eot'];
const RESOURCE_IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp'];
const RESOURCE_STYLE_EXTENSIONS = ['css'];
const RESOURCE_SCRIPT_EXTENSIONS = ['js', 'mjs'];

const RESOURCE_BROKEN_STATUS_MIN = 400;
const RESOURCE_OK_STATUS = 200;
const RESOURCE_NO_RESPONSE_SEVERITY = 'low'; // сторонние CDN иногда просто тормозят
const RESOURCE_NO_RESPONSE_KEY = 'no_response';
const RESOURCE_FONT_FALLBACK_SEVERITY = 'low'; // запасной формат шрифта при woff2: браузер его не загружает
const RESOURCE_MODERN_FONT_EXTENSION = 'woff2';

const RESOURCE_CSS_MAX_DEPTH = 1; // CSS страницы — 0, его @import — 1; @import внутри импортированного не разбирается
const RESOURCE_PROGRESS_EVERY = 10; // раз в столько проверенных ресурсов — `onProgress` (heartbeat воркера)
const RESOURCE_EXISTENCE_PROBE_BYTES = 1; // минимальный Range-запрос при отказе HEAD
const RESOURCE_RANGE_NOT_SATISFIABLE_STATUS = 416; // пустой файл (0 байт) отвечает 416 на Range: bytes=0-0
const RESOURCE_MAX_PER_DIAGNOSTIC = 500; // жёсткий лимит уникальных ресурсов за диагностику (решение владельца, 20.11.2026)

/**
 * Ресурсы всех проверяемых страниц (`collectResourcePages()`) с общим кэшем: адрес, подключённый на нескольких
 * страницах (или в общем CSS), запрашивается один раз. Битый ресурс — одна находка: `page_id` — первая страница,
 * все страницы — `evidence.pages`. Заглушки защиты от ботов (`bot_protection`) не проверяются.
 *
 * @param array<string, mixed>                                          $context      контекст шага (`buildStepContext()`)
 * @param callable(string $url, string $method, ?int $maxBytes=): array $request      результат как у `httpRequest()`
 * @param callable(int $checked): void|null                             $onProgress   вызывается каждые RESOURCE_PROGRESS_EVERY ресурсов
 * @return array{status: string, message: string, facts: array, findings: list<array>}
 */
function runResourceCheck(
    array $context,
    callable $request,
    ?callable $onProgress = null,
    int $maxResources = RESOURCE_MAX_PER_DIAGNOSTIC
): array {
    $home = $context['facts']['availability']['home'] ?? null;
    if (!is_array($home) || ($home['status'] ?? null) === null) {
        return ['status' => 'skipped', 'message' => RESOURCES_NO_HOME_MESSAGE, 'facts' => [], 'findings' => []];
    }

    $pages = collectResourcePages($context);
    // адрес → ['resource' => …, 'checked' => ?array, 'pages' => [индекс страницы => true], 'parents' => [адрес CSS => true]]
    $entries = [];
    $checkedCount = 0;
    $skippedByLimit = 0;

    foreach ($pages as $index => $page) {
        if ($page['bot_protection'] !== null) {
            continue;
        }

        // Очередь пополняется по ходу: в скачанных CSS находятся шрифты, картинки и @import
        $queue = [];
        foreach (collectPageResources(parseHtml($page['body'], $page['url']), $page['url']) as $resource) {
            enqueueResourceEntry($entries, $queue, $resource, $index, null, $maxResources, $skippedByLimit);
        }

        for ($i = 0; $i < count($queue); $i++) {
            $resource = $entries[$queue[$i]]['resource'];
            $isStyle = $resource['type'] === RESOURCE_TYPE_STYLE;
            $parseCss = $isStyle && $resource['css_depth'] <= RESOURCE_CSS_MAX_DEPTH;
            $checked = checkResource($resource['url'], $parseCss, $request);
            $cssBody = $parseCss ? $checked['body'] : '';
            unset($checked['body']);
            $entries[$resource['url']]['checked'] = $checked;
            $checkedCount++;

            if ($parseCss && $checked['status'] === RESOURCE_OK_STATUS && $cssBody !== '') {
                $cssUrl = $checked['final_url'] ?? $resource['url'];
                foreach (extractCssResources($cssBody, $cssUrl, $resource['url'], $resource['css_depth'] + 1) as $nested) {
                    enqueueResourceEntry($entries, $queue, $nested, null, $resource['url'], $maxResources, $skippedByLimit);
                }
            }
            unset($cssBody);

            if ($onProgress !== null && $checkedCount % RESOURCE_PROGRESS_EVERY === 0) {
                $onProgress($checkedCount);
            }
        }
    }

    $resources = [];
    $findings = [];
    foreach ($entries as $url => $entry) {
        $pageIndexes = resolveResourcePageIndexes((string) $url, $entries);
        $fact = makeResourceFact($entry['resource'], $entry['checked'], array_map(
            static fn (int $index): string => $pages[$index]['url'],
            $pageIndexes
        ));
        $resources[] = $fact;

        if (isBrokenResource($fact)) {
            $findings[] = makeBrokenResourceFinding($fact, $pages[$pageIndexes[0] ?? 0]['page_id'] ?? null);
        }
    }

    $pageFacts = array_map(
        static fn (array $page): array => [
            'url'            => $page['url'],
            'types'          => $page['types'],
            'page_id'        => $page['page_id'],
            'checked'        => $page['bot_protection'] === null,
            'bot_protection' => $page['bot_protection'],
        ],
        $pages
    );
    $blocked = count(array_filter($pageFacts, static fn (array $page): bool => !$page['checked']));

    return [
        'status'   => 'done',
        'message'  => buildResourcesStepMessage(
            count($pages) - $blocked,
            $blocked,
            count($resources),
            count($findings),
            0,
            0,
            $skippedByLimit
        ),
        'facts'    => [
            'pages'            => $pageFacts,
            'resources'        => $resources,
            // Возможная защита от ботов на главной, обрезанный HTML или предел числа ресурсов
            'limited'          => !empty($home['possible_bot_protection'])
                || in_array(true, array_column($pages, 'truncated'), true)
                || $skippedByLimit > 0,
            'skipped_by_limit' => $skippedByLimit,
        ],
        'findings' => $findings,
    ];
}

/**
 * Добавляет уникальный ресурс в `$entries` и `$queue` с учётом лимита, всегда сохраняя привязки к страницам/CSS.
 *
 * @param array<string, array{resource: array, checked: ?array, pages: array<int, true>, parents: array<string, true>}> $entries
 * @param list<string>                                                                                                   $queue
 * @param array<string, mixed>                                                                                           $resource
 */
function enqueueResourceEntry(
    array &$entries,
    array &$queue,
    array $resource,
    ?int $pageIndex,
    ?string $parentUrl,
    int $maxResources,
    int &$skippedByLimit
): void {
    $url = $resource['url'];
    if (!isset($entries[$url])) {
        if (count($entries) >= max(0, $maxResources)) {
            $skippedByLimit++;

            return;
        }
        $entries[$url] = ['resource' => $resource, 'checked' => null, 'pages' => [], 'parents' => []];
        $queue[] = $url;
    }

    if ($pageIndex !== null) {
        $entries[$url]['pages'][$pageIndex] = true;
    }
    if ($parentUrl !== null) {
        $entries[$url]['parents'][$parentUrl] = true;
    }
}

/**
 * Страницы для проверки: главная из шага `availability`, затем найденные шагом `crawl` (категория, товар,
 * корзина, оформление). Страница без ответа пропускается; одна страница под несколькими типами (корзина =
 * оформление) — один раз, с перечнем типов.
 *
 * @param array<string, mixed> $context
 * @return list<array{url: string, types: list<string>, page_id: ?int, body: string, truncated: bool,
 *                    bot_protection: ?array}>
 */
function collectResourcePages(array $context): array
{
    $candidates = [];
    $home = $context['facts']['availability']['home'] ?? null;
    if (is_array($home)) {
        $candidates['home'] = $home;
    }
    $crawlPages = $context['facts']['crawl']['pages'] ?? [];
    foreach (is_array($crawlPages) ? $crawlPages : [] as $type => $page) {
        if (is_array($page) && $type !== 'home') {
            $candidates[(string) $type] = $page;
        }
    }

    $pages = [];
    $byUrl = [];
    foreach ($candidates as $type => $page) {
        if (($page['status'] ?? null) === null) {
            continue;
        }

        $url = (string) (($page['final_url'] ?? '') !== '' ? $page['final_url'] : ($page['url'] ?? ''));
        $key = normalizeFindingLocation($url);
        if (isset($byUrl[$key])) {
            $pages[$byUrl[$key]]['types'][] = (string) $type;
            continue;
        }

        $byUrl[$key] = count($pages);
        $pages[] = [
            'url'            => $url,
            'types'          => [(string) $type],
            'page_id'        => isset($page['page_id']) ? (int) $page['page_id'] : null,
            'body'           => (string) ($page['body'] ?? ''),
            'truncated'      => !empty($page['truncated']),
            'bot_protection' => is_array($page['bot_protection'] ?? null) ? $page['bot_protection'] : null,
        ];
    }

    return $pages;
}

/**
 * Индексы страниц, где подключён ресурс: напрямую и через CSS, в котором он найден (по цепочке @import).
 *
 * @param array<string, array{pages: array<int, true>, parents: array<string, true>}> $entries
 * @param array<string, true>                                                          $visited
 * @return list<int> по возрастанию
 */
function resolveResourcePageIndexes(string $url, array $entries, array $visited = []): array
{
    if (isset($visited[$url]) || !isset($entries[$url])) {
        return [];
    }
    $visited[$url] = true;

    $indexes = array_keys($entries[$url]['pages']);
    foreach (array_keys($entries[$url]['parents']) as $parent) {
        $indexes = [...$indexes, ...resolveResourcePageIndexes((string) $parent, $entries, $visited)];
    }

    $indexes = array_values(array_unique($indexes));
    sort($indexes);

    return $indexes;
}

/**
 * @param array{url: string, type: string, found_in: string, fallback: bool} $resource
 * @param array<string, mixed>                                               $checked результат `checkResource()`
 * @param list<string>                                                       $pageUrls
 * @return array<string, mixed>
 */
function makeResourceFact(array $resource, array $checked, array $pageUrls): array
{
    return [
        'url'      => $resource['url'],
        'type'     => $resource['type'],
        'found_in' => $resource['found_in'],
        'fallback' => $resource['fallback'],
        'method'   => $checked['method'],
        'status'   => $checked['status'],
        'time_ms'  => $checked['time_ms'],
        'error'    => $checked['error'],
        'pages'    => $pageUrls,
    ];
}

/**
 * Ресурсы из разобранного HTML без дублей. `found_in` — где ресурс подключён (адрес страницы или CSS).
 *
 * @return list<array{url: string, type: string, found_in: string, css_depth: int, fallback: bool}>
 */
function collectPageResources(array $parsed, string $pageUrl): array
{
    $resources = [];
    $add = static function (string $url, string $type, bool $fallback = false) use (&$resources, $pageUrl): void {
        $url = normalizeResourceUrl($url);
        if (!isset($resources[$url])) {
            $resources[$url] = makeResourceEntry($url, $type, $pageUrl, 0, $fallback);
        }
    };

    foreach ($parsed['styles'] as $url) {
        $add($url, RESOURCE_TYPE_STYLE);
    }
    foreach ($parsed['scripts'] as $url) {
        $add($url, RESOURCE_TYPE_SCRIPT);
    }
    foreach ($parsed['links'] as $link) {
        $type = detectLinkResourceType($link['rel'], $link['url']);
        if ($type !== null) {
            $add($link['url'], $type);
        }
    }
    foreach ($parsed['images'] as $url) {
        $add($url, RESOURCE_TYPE_IMAGE);
    }
    foreach ($parsed['iframes'] as $url) {
        $add($url, RESOURCE_TYPE_FRAME);
    }
    foreach ($parsed['inline_styles'] as $css) {
        foreach (extractCssResources($css, $parsed['base_url'], $pageUrl, 0) as $resource) {
            $add($resource['url'], $resource['type'], $resource['fallback']);
        }
    }

    return array_values($resources);
}

/**
 * Тип ресурса по `rel` ссылки `<link>`; null — ссылка ничего не загружает (canonical, preconnect …).
 * У preload тип — по расширению файла (атрибут `as` парсер не отдаёт).
 */
function detectLinkResourceType(string $rel, string $url): ?string
{
    $tokens = preg_split('/\s+/', strtolower(trim($rel))) ?: [];

    if (array_intersect($tokens, RESOURCE_ICON_RELS) !== []) {
        return RESOURCE_TYPE_ICON;
    }
    if (array_intersect($tokens, RESOURCE_PRELOAD_RELS) !== []) {
        return detectResourceTypeByUrl($url);
    }

    return null;
}

/**
 * Тип по расширению файла в пути; неизвестное расширение → `other`.
 */
function detectResourceTypeByUrl(string $url): string
{
    $extension = getResourceExtension($url);

    return match (true) {
        in_array($extension, RESOURCE_FONT_EXTENSIONS, true)   => RESOURCE_TYPE_FONT,
        in_array($extension, RESOURCE_IMAGE_EXTENSIONS, true)  => RESOURCE_TYPE_IMAGE,
        in_array($extension, RESOURCE_STYLE_EXTENSIONS, true)  => RESOURCE_TYPE_STYLE,
        in_array($extension, RESOURCE_SCRIPT_EXTENSIONS, true) => RESOURCE_TYPE_SCRIPT,
        default                                                => RESOURCE_TYPE_OTHER,
    };
}

/**
 * `url()` и `@import` из CSS; адреса — относительно `$baseUrl` (адрес самого CSS или `<base>` страницы).
 * `$depth` — глубина найденных стилей (`@import`): разбирается ли он дальше, решает `runResourceCheck()`.
 * `url()` внутри `@font-face` — шрифты; если в блоке есть woff2, остальные форматы помечены `fallback`
 * (браузер берёт woff2 и до них не доходит). Комментарии пропускаются.
 *
 * @return list<array{url: string, type: string, found_in: string, css_depth: int, fallback: bool}>
 */
function extractCssResources(string $css, string $baseUrl, string $foundIn, int $depth): array
{
    $css = preg_replace('~/\*.*?\*/~s', '', $css) ?? $css;
    $resources = [];
    $add = static function (string $value, ?string $type, bool $fallback = false) use (&$resources, $baseUrl, $foundIn, $depth): void {
        $url = resolveHtmlUrl($baseUrl, $value);
        if ($url === null) {
            return;
        }
        $url = normalizeResourceUrl($url);
        if (!isset($resources[$url])) {
            $resources[$url] = makeResourceEntry($url, $type ?? detectResourceTypeByUrl($url), $foundIn, $depth, $fallback);
        }
    };

    if (preg_match_all('~@import\s+(?:url\(\s*)?([\'"]?)([^\'")\s;]+)\1~i', $css, $imports) > 0) {
        foreach ($imports[2] as $value) {
            $add($value, RESOURCE_TYPE_STYLE);
        }
    }

    if (preg_match_all('~@font-face\s*\{[^}]*\}~i', $css, $fontFaces) > 0) {
        foreach ($fontFaces[0] as $block) {
            $values = extractCssUrls($block);
            $hasModern = in_array(RESOURCE_MODERN_FONT_EXTENSION, array_map('getResourceExtension', $values), true);
            foreach ($values as $value) {
                $add($value, RESOURCE_TYPE_FONT, $hasModern && getResourceExtension($value) !== RESOURCE_MODERN_FONT_EXTENSION);
            }
        }
    }

    foreach (extractCssUrls($css) as $value) {
        $add($value, null);
    }

    return array_values($resources);
}

/**
 * @return array{url: string, type: string, found_in: string, css_depth: int, fallback: bool}
 */
function makeResourceEntry(string $url, string $type, string $foundIn, int $cssDepth, bool $fallback): array
{
    return ['url' => $url, 'type' => $type, 'found_in' => $foundIn, 'css_depth' => $cssDepth, 'fallback' => $fallback];
}

/**
 * Значения всех `url(...)` в CSS как есть, без кавычек.
 *
 * @return list<string>
 */
function extractCssUrls(string $css): array
{
    if (preg_match_all('~url\(\s*([\'"]?)(.*?)\1\s*\)~is', $css, $matches) === 0) {
        return [];
    }

    return $matches[2];
}

function getResourceExtension(string $url): string
{
    return strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
}

/**
 * Пустая строка запроса — тот же файл (`font.eot?#iefix` → `font.eot?` → `font.eot`).
 */
function normalizeResourceUrl(string $url): string
{
    return str_ends_with($url, '?') ? substr($url, 0, -1) : $url;
}

/**
 * HEAD; неудача (4xx/5xx или нет ответа) перепроверяется через GET с `Range` на 1 байт — многие серверы отвечают
 * на HEAD 404/405/418 или не отвечают вовсе, хотя GET работает; на 416 (пустой файл) — один повтор без Range.
 * `$needBody` (разбираемый CSS) — сразу полный GET. Если тело не нужно, в возврате оно всегда пустое.
 *
 * @param callable(string $url, string $method, ?int $maxBytes=): array $request
 * @return array{method: string, status: ?int, time_ms: int, error: ?string, final_url: ?string, body: string}
 */
function checkResource(string $url, bool $needBody, callable $request): array
{
    $method = $needBody ? 'GET' : 'HEAD';
    $response = $request($url, $method, null);

    $status = $response['status'] ?? null;
    if ($method === 'HEAD' && ($status === null || $status >= RESOURCE_BROKEN_STATUS_MIN)) {
        $method = 'GET';
        $response = $request($url, $method, RESOURCE_EXISTENCE_PROBE_BYTES);

        if (($response['status'] ?? null) === RESOURCE_RANGE_NOT_SATISFIABLE_STATUS) {
            $response = $request($url, $method, null);
        }
    }

    return [
        'method'    => $method,
        'status'    => $response['status'] ?? null,
        'time_ms'   => (int) ($response['time_ms'] ?? 0),
        'error'     => $response['error'] ?? null,
        'final_url' => $response['final_url'] ?? null,
        'body'      => $needBody ? (string) ($response['body'] ?? '') : '',
    ];
}

function isBrokenResource(array $resource): bool
{
    return $resource['status'] === null || $resource['status'] >= RESOURCE_BROKEN_STATUS_MIN;
}

/**
 * @param array{url: string, type: string, found_in: string, fallback: bool, method: string, status: ?int,
 *               error: ?string, pages?: list<string>} $resource
 */
function makeBrokenResourceFinding(array $resource, ?int $pageId): array
{
    $label = RESOURCE_TYPE_LABELS[$resource['type']] ?? RESOURCE_TYPE_LABELS[RESOURCE_TYPE_OTHER];
    $status = $resource['status'];
    $foundIn = $resource['found_in'];
    $severity = $status === null ? RESOURCE_NO_RESPONSE_SEVERITY : null;

    if ($status === null) {
        $title = "Не загружается {$label}: нет ответа";
        $details = "Ресурс не ответил: {$resource['error']}. Где подключено: {$foundIn}.";
    } else {
        $title = "Не загружается {$label}: код {$status}";
        $details = "Ресурс отвечает кодом {$status}. Где подключено: {$foundIn}.";
    }

    $pageCount = count($resource['pages'] ?? []);
    if ($pageCount > 1) {
        $details .= " Встречается на страницах: {$pageCount}.";
    }

    if ($resource['fallback']) {
        $severity = RESOURCE_FONT_FALLBACK_SEVERITY;
        $details .= ' Это запасной формат шрифта: современные браузеры берут woff2 из того же @font-face.';
    }

    return makeFinding(
        'broken_resource',
        $title,
        $resource['url'],
        $status === null ? RESOURCE_NO_RESPONSE_KEY : (string) $status,
        $details,
        [
            'type'     => $resource['type'],
            'status'   => $status,
            'method'   => $resource['method'],
            'error'    => $resource['error'],
            'found_in' => $foundIn,
            'fallback' => $resource['fallback'],
            'pages'    => $resource['pages'] ?? [],
        ],
        $pageId,
        $severity
    );
}

/**
 * «Проверено страниц: 3, не удалось: 1; ресурсов: 48, битых: 3; PHP-ошибок: 0; mixed content: 2»
 * («не удалось» — только если были страницы-заглушки защиты от ботов).
 */
function buildResourcesStepMessage(
    int $pages,
    int $blocked,
    int $checked,
    int $broken,
    int $phpErrors,
    int $mixedContent,
    int $skippedByLimit = 0
): string {
    $pagesPart = $blocked > 0 ? "Проверено страниц: {$pages}, не удалось: {$blocked}" : "Проверено страниц: {$pages}";
    $resourcesPart = $skippedByLimit > 0
        ? "ресурсов: {$checked} (предел, пропущено: {$skippedByLimit}), битых: {$broken}"
        : "ресурсов: {$checked}, битых: {$broken}";

    return "{$pagesPart}; {$resourcesPart}; PHP-ошибок: {$phpErrors}; mixed content: {$mixedContent}";
}
