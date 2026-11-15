<?php

declare(strict_types=1);

/**
 * Шаг `crawl` без БД: ищет категорию, товар, корзину и оформление заказа. Сеть — параметром (`$request`).
 *
 * Сайт целиком не обходится: запрашиваются только ссылки-кандидаты, похожие на нужный тип страницы
 * (data/shop-patterns.php), по убыванию похожести и до первой подтверждённой. Лимитов числа страниц нет
 * (решение владельца) — их ограничивает само число подходящих ссылок.
 */

require_once ROOT_PATH . '/src/Services/HtmlParser.php';
require_once ROOT_PATH . '/src/Services/FindingFactory.php';
require_once ROOT_PATH . '/src/Services/UrlValidator.php';

const SHOP_PATTERNS_FILE = ROOT_PATH . '/data/shop-patterns.php';

const CRAWL_PAGE_TYPES = ['category', 'product', 'cart', 'checkout'];

const CRAWL_FOUND_BY_PATTERN = 'cms_pattern';
const CRAWL_FOUND_BY_LINKS   = 'link_heuristic';
const CRAWL_FOUND_BY_MANUAL  = 'manual';

/** Вес признаков ссылки: адрес по шаблону платформы весит больше общего */
const CRAWL_SCORE_PLATFORM       = 3;
const CRAWL_SCORE_GENERIC        = 2;
const CRAWL_SCORE_CATEGORY_TEXT  = 2; // «Каталог» в тексте ссылки
const CRAWL_SCORE_CATEGORY_CLASS = 1; // класс меню — слабый признак: в меню много не-каталожных ссылок
const CRAWL_SCORE_PRODUCT_CLASS  = 1; // общий класс `product…` — только в дополнение к адресу
const CRAWL_MIN_LINK_SCORE       = 2; // ссылки слабее не запрашиваются

/** На странице категории не меньше стольких разных ссылок на товары */
const CRAWL_CATEGORY_MIN_PRODUCT_LINKS = 3;

const CRAWL_HTTP_OK_MIN    = 200;
const CRAWL_HTTP_OK_MAX    = 299;
const CRAWL_HTTP_ERROR_MIN = 400;
const CRAWL_HTTP_SERVER_ERROR_MIN = 500;
const CRAWL_BOT_PROTECTION_CODES  = [403, 429, 503];

const CRAWL_NO_HOME_MESSAGE = 'Нет данных главной страницы — искать страницы магазина не от чего.';

const CRAWL_REASON_HOME_UNAVAILABLE = 'главная ответила %s — ссылки на ней недоступны';
const CRAWL_REASON_BOT_PROTECTION   = 'ответ %d — возможна защита от ботов';
const CRAWL_REASON_JS_CATALOG       = 'каталог строится скриптом — нужна проверка в браузере (Фаза 3)';
const CRAWL_REASON_NO_LINKS         = 'нет подходящих ссылок';
const CRAWL_REASON_NOT_CONFIRMED    = 'проверено ссылок: %d — ни одна не похожа на %s';
const CRAWL_REASON_NO_CATEGORY      = 'нет подходящих ссылок на главной, категория не найдена';
const CRAWL_REASON_PATHS            = 'типовые адреса не подошли (проверено: %d), ссылок на %s нет';

const CRAWL_TYPE_NAMES = [
    'category' => 'категорию',
    'product'  => 'товар',
    'cart'     => 'корзину',
    'checkout' => 'оформление заказа',
];

const CRAWL_TYPE_LABELS = [
    'category' => 'категория',
    'product'  => 'товар',
    'cart'     => 'корзина',
    'checkout' => 'оформление',
];

/**
 * @param array<string, mixed>      $context  контекст шагов: `diagnostic` (+ `product_url`), `facts.availability.home`,
 *                                            `facts.stack.items`
 * @param callable(string $url): array $request ответ как у httpRequest()
 * @param array<string, mixed>|null $patterns правила (null — data/shop-patterns.php)
 * @param callable(int $requests): void|null $onProgress после каждого запроса
 * @return array{status: string, message: string, facts: array, findings: list<array>}
 */
function crawlShopPages(array $context, callable $request, ?array $patterns = null, ?callable $onProgress = null): array
{
    $home = $context['facts']['availability']['home'] ?? null;
    if (!is_array($home)) {
        return ['status' => 'skipped', 'message' => CRAWL_NO_HOME_MESSAGE, 'facts' => [], 'findings' => []];
    }

    $patterns ??= loadShopPatterns();
    $platforms = detectShopPlatforms($context['facts']['stack']['items'] ?? [], array_keys($patterns['platforms']));
    $rules = buildShopRules($patterns, $platforms);

    $siteHost = (string) $context['diagnostic']['host'];
    $homeUrl = (string) (($home['final_url'] ?? '') !== '' ? $home['final_url'] : $home['url']);
    $homeStatus = $home['status'] ?? null;
    $homeBody = (string) ($home['body'] ?? '');
    $homeOk = isSuccessfulStatus($homeStatus);

    $crawl = [
        'request'    => $request,
        'onProgress' => $onProgress,
        'responses'  => [],
        'requests'   => 0,
        'blocked'    => null, // первый код защиты от ботов на кандидатах
    ];

    $pages = [];
    $missing = [];
    $findings = [];
    $tried = ['category' => 0, 'product' => 0];

    $homeParsed = $homeOk ? parseHtml($homeBody, $homeUrl) : parseHtml('', $homeUrl);
    $exclude = [normalizeCrawlUrl($homeUrl)];
    $isJsCatalog = matchesAnyPattern($homeBody, $rules['js_catalog']);

    $productUrl = $context['diagnostic']['product_url'] ?? null;
    if (is_string($productUrl) && $productUrl !== '') {
        $response = fetchCrawlPage($crawl, $productUrl);
        $pages['product'] = buildCrawlPage('product', $productUrl, $response, CRAWL_FOUND_BY_MANUAL);
        $finding = buildManualProductFinding($productUrl, $response);
        if ($finding !== null) {
            $findings[] = $finding;
        }
    }

    $categoryParsed = null;
    if ($homeOk) {
        foreach (rankCrawlLinks($homeParsed['anchors'], 'category', $rules, $siteHost, $exclude) as $candidate) {
            if (isset($pages['category']) && isset($pages['product'])) {
                break;
            }
            $tried['category']++;
            $response = fetchCrawlPage($crawl, $candidate['url']);
            if (!isSuccessfulStatus($response['status'])) {
                continue;
            }
            $parsed = parseHtml((string) $response['body'], (string) $response['final_url']);
            $type = classifyShopPage((string) $response['body'], $parsed, $rules, $siteHost);

            if ($type === 'category' && !isset($pages['category'])) {
                $pages['category'] = buildCrawlPage('category', $candidate['url'], $response, $candidate['found_by']);
                $categoryParsed = $parsed;
                break;
            }
            if ($type === 'product' && !isset($pages['product'])) {
                $pages['product'] = buildCrawlPage('product', $candidate['url'], $response, $candidate['found_by']);
            }
        }
    }

    if (!isset($pages['product']) && $homeOk) {
        $sources = array_values(array_filter([$categoryParsed, $homeParsed]));
        $anchors = array_merge(...array_map(static fn (array $parsed): array => $parsed['anchors'], $sources));
        foreach (rankCrawlLinks($anchors, 'product', $rules, $siteHost, $exclude) as $candidate) {
            $tried['product']++;
            $response = fetchCrawlPage($crawl, $candidate['url']);
            if (!isSuccessfulStatus($response['status'])) {
                continue;
            }
            $parsed = parseHtml((string) $response['body'], (string) $response['final_url']);
            if (classifyShopPage((string) $response['body'], $parsed, $rules, $siteHost) === 'product') {
                $pages['product'] = buildCrawlPage('product', $candidate['url'], $response, $candidate['found_by']);
                break;
            }
        }
    }

    $linkSources = array_merge($homeParsed['anchors'], $categoryParsed['anchors'] ?? []);
    foreach (['cart', 'checkout'] as $type) {
        $found = findServicePage($crawl, $type, $rules, $homeUrl, $siteHost, $linkSources);
        if ($found['page'] !== null) {
            $pages[$type] = $found['page'];
        } else {
            $missing[$type] = sprintf(CRAWL_REASON_PATHS, $found['tried'], CRAWL_TYPE_NAMES[$type]);
        }
    }

    foreach (['category', 'product'] as $type) {
        if (isset($pages[$type])) {
            continue;
        }
        $missing[$type] = explainMissingCatalogPage(
            $type,
            $tried[$type],
            $homeStatus,
            $isJsCatalog,
            $crawl['blocked'],
            isset($pages['category'])
        );
    }

    $missing = array_intersect_key(array_merge(array_flip(CRAWL_PAGE_TYPES), $missing), $missing);

    return [
        'status'   => 'done',
        'message'  => buildCrawlMessage($pages, $missing, $crawl['requests']),
        'facts'    => [
            'platforms' => $platforms,
            'pages'     => $pages,
            'missing'   => $missing,
            'requests'  => $crawl['requests'],
        ],
        'findings' => $findings,
    ];
}

/**
 * @return array<string, mixed>
 */
function loadShopPatterns(): array
{
    $patterns = require SHOP_PATTERNS_FILE;
    if (!is_array($patterns)) {
        throw new RuntimeException('Файл правил поиска страниц повреждён: ' . SHOP_PATTERNS_FILE);
    }

    return $patterns;
}

/**
 * Ключи платформ из найденного стека, для которых есть правила. WooCommerce — раньше WordPress.
 *
 * @param list<array<string, mixed>> $stackItems
 * @param list<string>               $knownKeys
 * @return list<string>
 */
function detectShopPlatforms(array $stackItems, array $knownKeys): array
{
    $keys = [];
    foreach ($stackItems as $item) {
        $key = $item['key'] ?? null;
        if (is_string($key) && in_array($key, $knownKeys, true) && in_array($item['category'] ?? '', ['cms', 'plugin'], true)) {
            $keys[] = $key;
        }
    }

    usort($keys, static fn (string $a, string $b): int => ($a === 'woocommerce' ? 0 : 1) <=> ($b === 'woocommerce' ? 0 : 1));

    return array_values(array_unique($keys));
}

/**
 * Правила для найденных платформ + общие. Пути корзины и оформления: сначала платформы, потом общие.
 *
 * @param array<string, mixed> $patterns
 * @param list<string>         $platforms
 * @return array<string, mixed>
 */
function buildShopRules(array $patterns, array $platforms): array
{
    $generic = $patterns['generic'];
    $rules = [
        'platform' => ['category_link' => [], 'product_link' => [], 'category_link_class' => [], 'product_link_class' => []],
        'generic'  => $generic,
        'paths'    => ['cart' => [], 'checkout' => []],
        'js_catalog' => $generic['js_catalog'] ?? [],
        'pages'    => $patterns['pages'],
        'skip_extensions' => $patterns['skip_extensions'],
    ];

    foreach ($platforms as $key) {
        $platform = $patterns['platforms'][$key];
        foreach (array_keys($rules['platform']) as $field) {
            $rules['platform'][$field] = [...$rules['platform'][$field], ...($platform[$field] ?? [])];
        }
        foreach (['cart', 'checkout'] as $type) {
            foreach ($platform[$type] ?? [] as $path) {
                $rules['paths'][$type][$path] = CRAWL_FOUND_BY_PATTERN;
            }
        }
        $rules['js_catalog'] = [...$rules['js_catalog'], ...($platform['js_catalog'] ?? [])];
    }

    foreach (['cart', 'checkout'] as $type) {
        foreach ($generic[$type] ?? [] as $path) {
            $rules['paths'][$type][$path] ??= CRAWL_FOUND_BY_LINKS;
        }
    }

    // Признаки JS-каталога Tilda проверяются и без найденной платформы: стек мог её не распознать
    foreach ($patterns['platforms'] as $platform) {
        $rules['js_catalog'] = [...$rules['js_catalog'], ...($platform['js_catalog'] ?? [])];
    }
    $rules['js_catalog'] = array_values(array_unique($rules['js_catalog']));

    return $rules;
}

/**
 * Ссылки-кандидаты на категорию или товар по убыванию похожести (при равенстве — по порядку на странице).
 * Только свой хост, без файлов, без исключённых адресов, без дублей; слабее CRAWL_MIN_LINK_SCORE — не берутся.
 *
 * @param list<array{url: string, text: string, class?: string}> $anchors
 * @param array<string, mixed> $rules
 * @param list<string>         $exclude нормализованные адреса
 * @return list<array{url: string, score: int, found_by: string}>
 */
function rankCrawlLinks(array $anchors, string $type, array $rules, string $siteHost, array $exclude): array
{
    $candidates = [];
    $position = 0;

    foreach ($anchors as $anchor) {
        $url = (string) $anchor['url'];
        $key = normalizeCrawlUrl($url);
        if (isset($candidates[$key]) || in_array($key, $exclude, true) || !isCrawlableUrl($url, $siteHost, $rules['skip_extensions'])) {
            continue;
        }

        [$score, $foundBy] = scoreCrawlLink($anchor, $type, $rules);
        if ($score < CRAWL_MIN_LINK_SCORE) {
            continue;
        }

        $candidates[$key] = ['url' => $url, 'score' => $score, 'found_by' => $foundBy, 'position' => $position++];
    }

    $candidates = array_values($candidates);
    usort($candidates, static fn (array $a, array $b): int => [-$a['score'], $a['position']] <=> [-$b['score'], $b['position']]);

    return array_map(
        static fn (array $candidate): array => ['url' => $candidate['url'], 'score' => $candidate['score'], 'found_by' => $candidate['found_by']],
        $candidates
    );
}

/**
 * @param array{url: string, text: string, class?: string} $anchor
 * @param array<string, mixed>                             $rules
 * @return array{0: int, 1: string} похожесть и `found_by`
 */
function scoreCrawlLink(array $anchor, string $type, array $rules): array
{
    $subject = crawlUrlSubject((string) $anchor['url']);
    $class = (string) ($anchor['class'] ?? '');
    $text = (string) $anchor['text'];

    $platformScore = matchesAnyPattern($subject, $rules['platform'][$type . '_link'])
        || ($class !== '' && matchesAnyPattern($class, $rules['platform'][$type . '_link_class']))
        ? CRAWL_SCORE_PLATFORM
        : 0;

    $genericScore = matchesAnyPattern($subject, $rules['generic'][$type . '_link'] ?? []) ? CRAWL_SCORE_GENERIC : 0;
    if ($type === 'category') {
        $genericScore += matchesAnyPattern($text, $rules['generic']['category_link_text'] ?? []) ? CRAWL_SCORE_CATEGORY_TEXT : 0;
        $genericScore += $class !== '' && matchesAnyPattern($class, $rules['generic']['category_link_class'] ?? [])
            ? CRAWL_SCORE_CATEGORY_CLASS
            : 0;
    } else {
        $genericScore += $class !== '' && matchesAnyPattern($class, $rules['generic']['product_link_class'] ?? [])
            ? CRAWL_SCORE_PRODUCT_CLASS
            : 0;
    }

    return [$platformScore + $genericScore, $platformScore > 0 ? CRAWL_FOUND_BY_PATTERN : CRAWL_FOUND_BY_LINKS];
}

/**
 * Тип страницы по содержимому: `product`, `category` или null.
 * Порядок важен: у товара часто есть блок «похожие товары» (много ссылок), у категории — много разметок Product.
 *
 * @param array<string, mixed> $parsed результат parseHtml()
 * @param array<string, mixed> $rules
 */
function classifyShopPage(string $html, array $parsed, array $rules, string $siteHost): ?string
{
    if ($html === '') {
        return null;
    }

    if (matchesAnyPattern($html, $rules['pages']['product_strong'])) {
        return 'product';
    }

    $markupCount = 0;
    foreach ($rules['pages']['product_markup'] as $pattern) {
        $markupCount += (int) preg_match_all($pattern, $html);
    }
    if ($markupCount === 1) {
        return 'product';
    }

    $productLinks = count(rankCrawlLinks($parsed['anchors'], 'product', $rules, $siteHost, [normalizeCrawlUrl($parsed['base_url'])]));
    if ($productLinks >= CRAWL_CATEGORY_MIN_PRODUCT_LINKS) {
        return 'category';
    }

    // «В корзину» есть и на категориях, и на главной: без похожего на товар адреса это не товар
    $urlLooksLikeProduct = scoreCrawlLink(['url' => $parsed['base_url'], 'text' => ''], 'product', $rules)[0]
        >= CRAWL_MIN_LINK_SCORE;

    return $urlLooksLikeProduct && matchesAnyPattern($html, $rules['pages']['add_to_cart']) ? 'product' : null;
}

/**
 * Корзина или оформление: типовые пути (платформа → общие), затем ссылки со страниц.
 * Засчитывается ответ 2xx своего сайта, не главная, и в HTML есть признак страницы («мягкий 404» с кодом 200
 * не проходит).
 *
 * @param array<string, mixed>                        $crawl
 * @param array<string, mixed>                        $rules
 * @param list<array{url: string, text: string}>      $anchors
 * @return array{page: ?array, tried: int}
 */
function findServicePage(array &$crawl, string $type, array $rules, string $homeUrl, string $siteHost, array $anchors): array
{
    $origin = buildCrawlOrigin($homeUrl);
    $candidates = [];
    foreach ($rules['paths'][$type] as $path => $foundBy) {
        $candidates[normalizeCrawlUrl($origin . $path)] = ['url' => $origin . $path, 'found_by' => $foundBy];
    }

    foreach ($anchors as $anchor) {
        $url = (string) $anchor['url'];
        $key = normalizeCrawlUrl($url);
        if (isset($candidates[$key]) || !isCrawlableUrl($url, $siteHost, $rules['skip_extensions'])) {
            continue;
        }
        $byUrl = matchesAnyPattern(crawlUrlSubject($url), $rules['generic'][$type . '_link'] ?? []);
        $byText = matchesAnyPattern((string) $anchor['text'], $rules['generic'][$type . '_link_text'] ?? []);
        if ($byUrl || $byText) {
            $candidates[$key] = ['url' => $url, 'found_by' => CRAWL_FOUND_BY_LINKS];
        }
    }

    $homeKey = normalizeCrawlUrl($homeUrl);
    $tried = 0;
    foreach ($candidates as $candidate) {
        $tried++;
        $response = fetchCrawlPage($crawl, $candidate['url']);
        $finalUrl = (string) ($response['final_url'] ?: $candidate['url']);

        if (
            isSuccessfulStatus($response['status'])
            && normalizeCrawlUrl($finalUrl) !== $homeKey
            && isSameSiteHost($finalUrl, $siteHost)
            && matchesAnyPattern((string) $response['body'], $rules['pages'][$type])
        ) {
            return ['page' => buildCrawlPage($type, $candidate['url'], $response, $candidate['found_by']), 'tried' => $tried];
        }
    }

    return ['page' => null, 'tried' => $tried];
}

/**
 * Запрос с кэшем: один адрес — один запрос за шаг. Запоминает первый код защиты от ботов.
 *
 * @param array<string, mixed> $crawl
 * @return array<string, mixed> ответ как у httpRequest()
 */
function fetchCrawlPage(array &$crawl, string $url): array
{
    $key = normalizeCrawlUrl($url);
    if (isset($crawl['responses'][$key])) {
        return $crawl['responses'][$key];
    }

    $response = ($crawl['request'])($url) + [
        'final_url' => $url, 'status' => null, 'headers' => [], 'body' => '', 'truncated' => false,
        'redirects' => [], 'time_ms' => 0, 'error' => null,
    ];
    $crawl['responses'][$key] = $response;
    $crawl['requests']++;

    if ($crawl['blocked'] === null && in_array($response['status'], CRAWL_BOT_PROTECTION_CODES, true)) {
        $crawl['blocked'] = $response['status'];
    }
    if ($crawl['onProgress'] !== null) {
        ($crawl['onProgress'])($crawl['requests']);
    }

    return $response;
}

/**
 * Найденная страница для фактов: адреса, код, время, как найдена; HTML и заголовки — для следующих шагов.
 *
 * @param array<string, mixed> $response
 * @return array<string, mixed>
 */
function buildCrawlPage(string $type, string $url, array $response, string $foundBy): array
{
    return [
        'type'      => $type,
        'url'       => $url,
        'final_url' => (string) ($response['final_url'] ?: $url),
        'status'    => $response['status'],
        'time_ms'   => (int) $response['time_ms'],
        'error'     => $response['error'],
        'found_by'  => $foundBy,
        'headers'   => $response['headers'],
        'body'      => (string) $response['body'],
        'truncated' => (bool) $response['truncated'],
    ];
}

/**
 * Товар, указанный вручную, не открылся или ответил ошибкой — это поломка: 4xx → `medium`, 5xx и нет ответа → `high`.
 *
 * @param array<string, mixed> $response
 * @return array<string, mixed>|null
 */
function buildManualProductFinding(string $url, array $response): ?array
{
    $status = $response['status'];
    if (isSuccessfulStatus($status) || (is_int($status) && $status < CRAWL_HTTP_ERROR_MIN)) {
        return null;
    }

    $title = is_int($status)
        ? 'Страница товара отвечает кодом ' . $status
        : 'Страница товара не открылась' . ($response['error'] ? ': ' . $response['error'] : '');
    $severity = is_int($status) && $status < CRAWL_HTTP_SERVER_ERROR_MIN ? 'medium' : 'high';

    return makeFinding(
        'availability',
        $title,
        $url,
        'product_page',
        'Адрес товара указан вручную в форме диагностики.',
        ['status' => $status, 'error' => $response['error'], 'final_url' => $response['final_url']],
        null,
        $severity
    );
}

function explainMissingCatalogPage(
    string $type,
    int $tried,
    mixed $homeStatus,
    bool $isJsCatalog,
    ?int $blocked,
    bool $categoryFound
): string {
    if (!isSuccessfulStatus($homeStatus)) {
        return in_array($homeStatus, CRAWL_BOT_PROTECTION_CODES, true)
            ? sprintf(CRAWL_REASON_BOT_PROTECTION, $homeStatus)
            : sprintf(CRAWL_REASON_HOME_UNAVAILABLE, is_int($homeStatus) ? (string) $homeStatus : 'без ответа');
    }
    if ($isJsCatalog) {
        return CRAWL_REASON_JS_CATALOG;
    }
    if ($blocked !== null) {
        return sprintf(CRAWL_REASON_BOT_PROTECTION, $blocked);
    }
    if ($tried > 0) {
        return sprintf(CRAWL_REASON_NOT_CONFIRMED, $tried, CRAWL_TYPE_NAMES[$type]);
    }

    return $type === 'product' && !$categoryFound ? CRAWL_REASON_NO_CATEGORY : CRAWL_REASON_NO_LINKS;
}

/**
 * @param array<string, array> $pages
 * @param array<string, string> $missing
 */
function buildCrawlMessage(array $pages, array $missing, int $requests): string
{
    $label = static fn (string $type): string => CRAWL_TYPE_LABELS[$type];
    $parts = [];

    $found = array_values(array_filter(CRAWL_PAGE_TYPES, static fn (string $type): bool => isset($pages[$type])));
    if ($found !== []) {
        $parts[] = 'Найдено: ' . implode(', ', array_map($label, $found));
    }
    if ($missing !== []) {
        $parts[] = 'не найдено: ' . implode(', ', array_map($label, array_keys($missing)));
    }
    $parts[] = 'запросов: ' . $requests;

    return implode('; ', $parts) . '.';
}

function isSuccessfulStatus(mixed $status): bool
{
    return is_int($status) && $status >= CRAWL_HTTP_OK_MIN && $status <= CRAWL_HTTP_OK_MAX;
}

/**
 * Свой хост (без `www.`), http(s), не файл.
 *
 * @param list<string> $skipExtensions
 */
function isCrawlableUrl(string $url, string $siteHost, array $skipExtensions): bool
{
    if (!isSameSiteHost($url, $siteHost)) {
        return false;
    }

    $extension = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

    return $extension === '' || !in_array($extension, $skipExtensions, true);
}

function isSameSiteHost(string $url, string $siteHost): bool
{
    $host = parse_url($url, PHP_URL_HOST);

    return is_string($host) && extractBaseHost($host) === $siteHost;
}

/**
 * Ключ для дублей: без фрагмента, хост в нижнем регистре, без `www.`, без завершающего `/` у пути.
 */
function normalizeCrawlUrl(string $url): string
{
    $parts = parse_url(explode('#', $url, 2)[0]);
    if ($parts === false || !isset($parts['host'])) {
        return $url;
    }

    $path = rtrim($parts['path'] ?? '', '/');

    return extractBaseHost(strtolower($parts['host'])) . ($path === '' ? '/' : $path)
        . (isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '');
}

/**
 * Часть адреса, по которой работают шаблоны: путь + `?query`.
 */
function crawlUrlSubject(string $url): string
{
    $path = (string) (parse_url($url, PHP_URL_PATH) ?? '/');
    $query = parse_url($url, PHP_URL_QUERY);

    return ($path === '' ? '/' : $path) . (is_string($query) && $query !== '' ? '?' . $query : '');
}

/**
 * `https://www.shop.ru/catalog/` → `https://www.shop.ru`.
 */
function buildCrawlOrigin(string $url): string
{
    $parts = parse_url($url);

    return strtolower((string) ($parts['scheme'] ?? 'https')) . '://' . (string) ($parts['host'] ?? '')
        . (isset($parts['port']) ? ':' . $parts['port'] : '');
}

/**
 * @param list<string> $patterns
 */
function matchesAnyPattern(string $subject, array $patterns): bool
{
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $subject) === 1) {
            return true;
        }
    }

    return false;
}
