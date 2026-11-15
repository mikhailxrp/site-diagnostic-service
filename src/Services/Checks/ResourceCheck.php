<?php

declare(strict_types=1);

/**
 * Шаг `resources` без БД: ресурсы главной (скрипты, стили, картинки, шрифты, иконки, фреймы) и `url()` из
 * inline CSS и из скачанных CSS проверяются запросами; каждый битый ресурс — находка `broken_resource`.
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

/**
 * @param array<string, mixed>                         $context    контекст шага (`buildStepContext()`)
 * @param callable(string $url, string $method): array $request    результат как у `httpRequest()`
 * @param callable(int $checked): void|null            $onProgress вызывается каждые RESOURCE_PROGRESS_EVERY ресурсов
 * @return array{status: string, message: string, facts: array, findings: list<array>}
 */
function runResourceCheck(array $context, callable $request, ?callable $onProgress = null): array
{
    $home = $context['facts']['availability']['home'] ?? null;
    if (!is_array($home) || ($home['status'] ?? null) === null) {
        return ['status' => 'skipped', 'message' => RESOURCES_NO_HOME_MESSAGE, 'facts' => [], 'findings' => []];
    }

    $pageUrl = (string) ($home['final_url'] ?? $context['diagnostic']['url']);
    $pageId = isset($home['page_id']) ? (int) $home['page_id'] : null;
    $parsed = parseHtml((string) ($home['body'] ?? ''), $pageUrl);

    // Очередь пополняется по ходу: в скачанных CSS находятся шрифты, картинки и @import
    $queue = collectPageResources($parsed, $pageUrl);
    $seen = array_fill_keys(array_column($queue, 'url'), true);
    $resources = [];
    $findings = [];

    for ($i = 0; $i < count($queue); $i++) {
        $resource = $queue[$i];
        $isStyle = $resource['type'] === RESOURCE_TYPE_STYLE;
        $checked = checkResource($resource['url'], $isStyle, $request);

        if ($isStyle && $resource['css_depth'] <= RESOURCE_CSS_MAX_DEPTH && $checked['status'] === RESOURCE_OK_STATUS) {
            $cssUrl = $checked['final_url'] ?? $resource['url'];
            foreach (extractCssResources($checked['body'], $cssUrl, $resource['url'], $resource['css_depth'] + 1) as $nested) {
                if (!isset($seen[$nested['url']])) {
                    $seen[$nested['url']] = true;
                    $queue[] = $nested;
                }
            }
        }

        $fact = [
            'url'      => $resource['url'],
            'type'     => $resource['type'],
            'found_in' => $resource['found_in'],
            'fallback' => $resource['fallback'],
            'method'   => $checked['method'],
            'status'   => $checked['status'],
            'time_ms'  => $checked['time_ms'],
            'error'    => $checked['error'],
        ];
        $resources[] = $fact;

        if (isBrokenResource($fact)) {
            $findings[] = makeBrokenResourceFinding($fact, $pageId);
        }

        if ($onProgress !== null && count($resources) % RESOURCE_PROGRESS_EVERY === 0) {
            $onProgress(count($resources));
        }
    }

    return [
        'status'   => 'done',
        'message'  => buildResourcesStepMessage(count($resources), count($findings), 0, 0),
        'facts'    => [
            'page_url'  => $pageUrl,
            'resources' => $resources,
            // Страница-заглушка защиты от ботов или обрезанный HTML — проверены не все ресурсы
            'limited'   => !empty($home['possible_bot_protection']) || !empty($home['truncated']),
        ],
        'findings' => $findings,
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
 * HEAD; неудача (4xx/5xx или нет ответа) перепроверяется через GET — многие серверы и сервисы отвечают
 * на HEAD 404/405/418 или не отвечают вовсе, хотя GET работает. `$needBody` (CSS) — сразу GET.
 *
 * @param callable(string $url, string $method): array $request
 * @return array{method: string, status: ?int, time_ms: int, error: ?string, final_url: ?string, body: string}
 */
function checkResource(string $url, bool $needBody, callable $request): array
{
    $method = $needBody ? 'GET' : 'HEAD';
    $response = $request($url, $method);

    $status = $response['status'] ?? null;
    if ($method === 'HEAD' && ($status === null || $status >= RESOURCE_BROKEN_STATUS_MIN)) {
        $method = 'GET';
        $response = $request($url, $method);
    }

    return [
        'method'    => $method,
        'status'    => $response['status'] ?? null,
        'time_ms'   => (int) ($response['time_ms'] ?? 0),
        'error'     => $response['error'] ?? null,
        'final_url' => $response['final_url'] ?? null,
        'body'      => (string) ($response['body'] ?? ''),
    ];
}

function isBrokenResource(array $resource): bool
{
    return $resource['status'] === null || $resource['status'] >= RESOURCE_BROKEN_STATUS_MIN;
}

/**
 * @param array{url: string, type: string, found_in: string, fallback: bool, method: string, status: ?int,
 *               error: ?string} $resource
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
        ],
        $pageId,
        $severity
    );
}

/**
 * «Проверено ресурсов: 48, битых: 3; PHP-ошибок: 0; mixed content: 2».
 */
function buildResourcesStepMessage(int $checked, int $broken, int $phpErrors, int $mixedContent): string
{
    return "Проверено ресурсов: {$checked}, битых: {$broken}; PHP-ошибок: {$phpErrors}; mixed content: {$mixedContent}";
}
