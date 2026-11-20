<?php

declare(strict_types=1);

/**
 * Шаг `seo` без БД: robots.txt, sitemap.xml и SEO-признаки проверенных страниц (title, description, canonical,
 * noindex / nofollow в meta и `X-Robots-Tag`). Сеть передаётся параметром (`$request`).
 * www / без www не проверяется: это находка шага `availability` (Фаза 1).
 *
 * Серьёзности — SEO_SEVERITY, что проверяется на каком типе страницы — SEO_PAGE_RULES: чтобы поменять правило,
 * достаточно одной строки там.
 */

require_once ROOT_PATH . '/src/Services/HtmlParser.php';
require_once ROOT_PATH . '/src/Services/HttpClient.php';
require_once ROOT_PATH . '/src/Services/FindingFactory.php';
require_once ROOT_PATH . '/src/Services/Checks/BotProtectionCheck.php';

/** Серьёзность каждой SEO-находки */
const SEO_SEVERITY = [
    'robots_missing'      => 'low',    // нет robots.txt (404 или HTML вместо текста)
    'robots_error'        => 'medium', // robots.txt отвечает 5xx или не отвечает
    'robots_disallow_all' => 'high',   // `Disallow: /` для всех / Yandex / Googlebot
    'sitemap_missing'     => 'low',    // sitemap не найден
    'sitemap_error'       => 'medium', // sitemap из robots не открывается, 5xx, битый XML
    'sitemap_empty'       => 'low',    // в sitemap нет адресов
    'sitemap_bad_loc'     => 'medium', // в sitemap не адреса (`<loc>Array</loc>` — ошибка кода сайта)
    'title_missing'       => 'low',
    'title_duplicate'     => 'low',
    'description_missing' => 'low',
    'canonical_foreign'   => 'medium', // canonical ведёт на другой сайт
    'noindex_home'        => 'high',   // главная закрыта от индексации
    'noindex_page'        => 'medium', // категория / товар закрыты от индексации
    'nofollow'            => 'medium', // поисковик не идёт по ссылкам страницы
];

/** Тип страницы → какие проверки на нём выполняются (корзине и оформлению можно быть закрытыми и без title) */
const SEO_PAGE_RULES = [
    'home'     => ['title', 'description', 'canonical', 'noindex', 'nofollow'],
    'category' => ['title', 'description', 'canonical', 'noindex', 'nofollow'],
    'product'  => ['title', 'description', 'canonical', 'noindex'],
    'cart'     => [],
    'checkout' => [],
];

/** Роботы, полный запрет которых в robots.txt — находка (`*` — все роботы) */
const SEO_ROBOTS_AGENTS = ['*' => 'все роботы', 'yandex' => 'Яндекс', 'googlebot' => 'Google'];
const SEO_ROBOTS_ROOT_PATHS = ['/', '/*'];

/** meta name, в которых указывают индексацию */
const SEO_ROBOTS_META_NAMES = ['robots', 'yandex', 'googlebot'];

const SEO_STATE_OK      = 'ok';
const SEO_STATE_MISSING = 'missing';
const SEO_STATE_ERROR   = 'error';
const SEO_STATE_EMPTY   = 'empty';
const SEO_STATE_BLOCKED = 'blocked';

const SEO_SOURCE_ROBOTS  = 'robots';
const SEO_SOURCE_DEFAULT = 'default';

const SEO_HTTP_OK_MIN       = 200;
const SEO_HTTP_REDIRECT_MIN = 300;
const SEO_HTTP_SERVER_ERROR = 500;
const SEO_HTML_SNIFF_BYTES  = 512; // начало ответа, по которому видно HTML вместо robots.txt
const SEO_GZIP_MAGIC              = "\x1f\x8b";
const SEO_SITEMAP_MAX_DECODED_BYTES = HTTP_MAX_BODY_BYTES; // 5 МБ после распаковки (как у HTTP-клиента)
const SEO_MAX_SITEMAP_CANDIDATES  = 5; // лимит директив Sitemap: из robots.txt (решение владельца, 20.11.2026)
const SEO_BAD_LOC_EXAMPLES        = 5; // сколько неправильных записей sitemap показать в находке

const SEO_NO_HOME_MESSAGE = 'Нет данных главной страницы: шаг «Доступность сайта» не выполнен.';

/**
 * @param array<string, mixed>                $context  контекст шага (`buildStepContext()`)
 * @param callable(string $url): array        $request  результат как у `httpRequest()`
 * @param array<string, mixed>|null           $botRules правила заглушек (null — из `data/bot-protection.php`)
 * @return array{status: string, message: string, facts: array, findings: list<array>}
 */
function runSeoCheck(array $context, callable $request, ?array $botRules = null): array
{
    $home = $context['facts']['availability']['home'] ?? null;
    if (!is_array($home) || ($home['status'] ?? null) === null) {
        return ['status' => 'skipped', 'message' => SEO_NO_HOME_MESSAGE, 'facts' => [], 'findings' => []];
    }

    $homeUrl = (string) (($home['final_url'] ?? '') !== '' ? $home['final_url'] : $context['diagnostic']['url']);
    $origin = buildSeoOrigin($homeUrl);

    $robots = checkRobotsTxt($origin, $request, $botRules);
    $sitemap = checkSitemap($origin, $robots['facts']['sitemaps'], $request, $botRules);
    $pages = collectSeoPages($context);
    $pageFindings = checkSeoPages($pages, $homeUrl);

    $findings = [...$robots['findings'], ...$sitemap['findings'], ...$pageFindings];

    return [
        'status'   => 'done',
        'message'  => buildSeoStepMessage($robots['facts'], $sitemap['facts'], count($pages), count($findings)),
        'facts'    => [
            'robots'  => $robots['facts'],
            'sitemap' => $sitemap['facts'],
            'pages'   => array_map(
                static fn (array $page): array => ['url' => $page['url'], 'types' => $page['types']],
                $pages
            ),
        ],
        'findings' => $findings,
    ];
}

/**
 * robots.txt: доступность и полный запрет для всех / Яндекса / Google.
 *
 * @return array{facts: array, findings: list<array>}
 */
function checkRobotsTxt(string $origin, callable $request, ?array $botRules): array
{
    $url = $origin . '/robots.txt';
    $response = $request($url);
    $status = $response['status'] ?? null;
    $facts = ['url' => $url, 'status' => $status, 'state' => SEO_STATE_OK, 'disallow_all' => [], 'sitemaps' => []];

    if ($status !== null && detectBotProtection($response, $botRules) !== null) {
        return ['facts' => ['state' => SEO_STATE_BLOCKED] + $facts, 'findings' => []];
    }

    if ($status === null || $status >= SEO_HTTP_SERVER_ERROR) {
        $reason = $status === null ? 'не отвечает: ' . ($response['error'] ?? 'нет ответа') : "отвечает кодом {$status}";

        return [
            'facts'    => ['state' => SEO_STATE_ERROR] + $facts,
            'findings' => [makeSeoFinding(
                'robots_error',
                "robots.txt {$reason}",
                $url,
                "Поисковики не могут прочитать правила обхода сайта: robots.txt {$reason}.",
                ['status' => $status, 'error' => $response['error'] ?? null]
            )],
        ];
    }

    $body = (string) ($response['body'] ?? '');
    if (!isSuccessfulSeoStatus($status) || looksLikeHtml($response)) {
        $what = isSuccessfulSeoStatus($status) ? 'вместо файла отдаётся HTML-страница' : "ответ {$status}";

        return [
            'facts'    => ['state' => SEO_STATE_MISSING] + $facts,
            'findings' => [makeSeoFinding(
                'robots_missing',
                'Нет robots.txt',
                $url,
                "Файла robots.txt нет ({$what}): поисковики обходят всё подряд, включая служебные страницы.",
                ['status' => $status]
            )],
        ];
    }

    $parsed = parseRobotsTxt($body);
    $facts['sitemaps'] = $parsed['sitemaps'];
    $facts['disallow_all'] = findRobotsDisallowAll($parsed['groups']);

    $findings = [];
    if ($facts['disallow_all'] !== []) {
        $labels = array_map(static fn (string $agent): string => SEO_ROBOTS_AGENTS[$agent], $facts['disallow_all']);
        $findings[] = makeSeoFinding(
            'robots_disallow_all',
            'Сайт закрыт от поисковиков в robots.txt: ' . implode(', ', $labels),
            $url,
            'В robots.txt стоит «Disallow: /» для: ' . implode(', ', $labels)
                . '. Эти поисковики не индексируют сайт, он пропадает из выдачи.',
            ['agents' => $facts['disallow_all']]
        );
    }

    return ['facts' => $facts, 'findings' => $findings];
}

/**
 * Группы правил и Sitemap из robots.txt. Несколько `User-agent` подряд — одна группа; `User-agent` после правил
 * начинает новую. Комментарии (`#`) и неизвестные директивы пропускаются.
 *
 * @return array{groups: list<array{agents: list<string>, rules: list<array{type: string, path: string}>}>,
 *               sitemaps: list<string>}
 */
function parseRobotsTxt(string $text, int $maxSitemaps = SEO_MAX_SITEMAP_CANDIDATES): array
{
    $groups = [];
    $sitemaps = [];
    $seenSitemaps = [];
    $current = null;
    $maxSitemaps = max(1, $maxSitemaps);

    foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
        $line = trim(explode('#', $line, 2)[0]);
        if ($line === '' || !str_contains($line, ':')) {
            continue;
        }

        [$key, $value] = array_map('trim', explode(':', $line, 2));
        $key = strtolower($key);

        if ($key === 'sitemap') {
            if ($value !== '' && !isset($seenSitemaps[$value]) && count($sitemaps) < $maxSitemaps) {
                $seenSitemaps[$value] = true;
                $sitemaps[] = $value;
            }
            continue;
        }

        if ($key === 'user-agent') {
            if ($current === null || $groups[$current]['rules'] !== []) {
                $groups[] = ['agents' => [], 'rules' => []];
                $current = count($groups) - 1;
            }
            $groups[$current]['agents'][] = strtolower($value);
            continue;
        }

        if (($key === 'disallow' || $key === 'allow') && $current !== null) {
            $groups[$current]['rules'][] = ['type' => $key, 'path' => $value];
        }
    }

    return ['groups' => $groups, 'sitemaps' => $sitemaps];
}

/**
 * Роботы из SEO_ROBOTS_AGENTS, которым запрещён весь сайт: `Disallow: /` без `Allow: /` в той же группе.
 * Пустой `Disallow:` ничего не запрещает.
 *
 * @param list<array{agents: list<string>, rules: list<array{type: string, path: string}>}> $groups
 * @return list<string> ключи SEO_ROBOTS_AGENTS
 */
function findRobotsDisallowAll(array $groups): array
{
    $blocked = [];
    foreach (array_keys(SEO_ROBOTS_AGENTS) as $agent) {
        foreach ($groups as $group) {
            if (!in_array($agent, $group['agents'], true)) {
                continue;
            }

            $disallowRoot = false;
            $allowRoot = false;
            foreach ($group['rules'] as $rule) {
                if (in_array($rule['path'], SEO_ROBOTS_ROOT_PATHS, true)) {
                    $disallowRoot = $disallowRoot || $rule['type'] === 'disallow';
                    $allowRoot = $allowRoot || $rule['type'] === 'allow';
                }
            }

            if ($disallowRoot && !$allowRoot) {
                $blocked[] = $agent;
                break;
            }
        }
    }

    return $blocked;
}

/**
 * sitemap: адреса из robots.txt, иначе `/sitemap.xml`; читается первый ответивший 2xx. Индекс — число вложенных
 * файлов и адреса только в первом вложенном (решение владельца: все не скачиваются).
 *
 * @param list<string> $robotsSitemaps
 * @return array{facts: array, findings: list<array>}
 */
function checkSitemap(
    string $origin,
    array $robotsSitemaps,
    callable $request,
    ?array $botRules,
    int $maxCandidates = SEO_MAX_SITEMAP_CANDIDATES
): array {
    $source = $robotsSitemaps !== [] ? SEO_SOURCE_ROBOTS : SEO_SOURCE_DEFAULT;
    $candidates = $robotsSitemaps !== []
        ? array_slice($robotsSitemaps, 0, max(1, $maxCandidates))
        : [$origin . '/sitemap.xml'];
    $facts = [
        'url'            => $candidates[0],
        'status'         => null,
        'state'          => SEO_STATE_MISSING,
        'source'         => $source,
        'is_index'       => false,
        'sitemaps_count' => null,
        'urls_count'     => null,
        'first_url'      => null,
        'truncated'      => false,
    ];

    $response = null;
    foreach ($candidates as $url) {
        $response = $request($url);
        $facts['url'] = $url;
        $facts['status'] = $response['status'] ?? null;
        if (isSuccessfulSeoStatus($facts['status'])) {
            break;
        }
    }

    $status = $facts['status'];
    if ($status !== null && detectBotProtection($response, $botRules) !== null) {
        return ['facts' => ['state' => SEO_STATE_BLOCKED] + $facts, 'findings' => []];
    }

    if (!isSuccessfulSeoStatus($status)) {
        // Типовой /sitemap.xml ответил 404 — карты просто нет; адрес из robots не открылся — поломка
        $missing = $source === SEO_SOURCE_DEFAULT && $status !== null && $status < SEO_HTTP_SERVER_ERROR;
        $code = $status === null ? 'нет ответа' : "код {$status}";

        return [
            'facts'    => ['state' => $missing ? SEO_STATE_MISSING : SEO_STATE_ERROR] + $facts,
            'findings' => [$missing
                ? makeSeoFinding(
                    'sitemap_missing',
                    'Нет карты сайта sitemap.xml',
                    $facts['url'],
                    "Карты сайта нет ни в robots.txt, ни по типовому адресу ({$code}): новые страницы поисковики находят дольше.",
                    ['status' => $status]
                )
                : makeSeoFinding(
                    'sitemap_error',
                    "Карта сайта не открывается: {$code}",
                    $facts['url'],
                    $source === SEO_SOURCE_ROBOTS
                        ? "robots.txt ссылается на карту сайта, а она отвечает: {$code}."
                        : "Карта сайта по типовому адресу отвечает: {$code}.",
                    ['status' => $status, 'error' => $response['error'] ?? null]
                ),
            ],
        ];
    }

    $parsed = parseSitemap($response);
    if ($parsed === null && $source === SEO_SOURCE_DEFAULT && looksLikeHtml($response)) {
        // Типовой адрес отдал HTML-страницу («мягкий 404») — карты нет
        return [
            'facts'    => ['state' => SEO_STATE_MISSING] + $facts,
            'findings' => [makeSeoFinding(
                'sitemap_missing',
                'Нет карты сайта sitemap.xml',
                $facts['url'],
                'Карты сайта нет ни в robots.txt, ни по типовому адресу (там HTML-страница): новые страницы поисковики находят дольше.',
                ['status' => $status]
            )],
        ];
    }
    if ($parsed === null) {
        return [
            'facts'    => ['state' => SEO_STATE_ERROR] + $facts,
            'findings' => [makeSeoFinding(
                'sitemap_error',
                'Карта сайта — не XML-sitemap',
                $facts['url'],
                'Файл карты сайта не разбирается как XML-sitemap (битый XML или другая страница): поисковики его не примут.',
                ['status' => $status]
            )],
        ];
    }

    $facts['truncated'] = $parsed['truncated'];
    $badLocs = array_values(array_filter($parsed['locs'], static fn (string $loc): bool => !isSitemapUrl($loc)));
    $goodLocs = array_values(array_filter($parsed['locs'], 'isSitemapUrl'));
    $findings = [];
    if ($badLocs !== []) {
        $examples = array_slice(array_values(array_unique($badLocs)), 0, SEO_BAD_LOC_EXAMPLES);
        $findings[] = makeSeoFinding(
            'sitemap_bad_loc',
            'В карте сайта записи вместо адресов: «' . implode('», «', $examples) . '»',
            $facts['url'],
            'В <loc> карты сайта не адреса страниц (' . count($badLocs) . ' шт.): так бывает, когда код, '
                . 'который строит карту, сломан. Поисковики такие записи отбрасывают.',
            ['bad_locs' => $examples, 'count' => count($badLocs)],
            null,
            'bad_loc'
        );
    }

    if ($parsed['is_index']) {
        $facts['is_index'] = true;
        $facts['sitemaps_count'] = count($parsed['locs']);
        $facts['first_url'] = $goodLocs[0] ?? null;
        if ($facts['first_url'] !== null) {
            $child = $request($facts['first_url']);
            $childParsed = isSuccessfulSeoStatus($child['status'] ?? null) ? parseSitemap($child) : null;
            $facts['urls_count'] = $childParsed !== null && !$childParsed['is_index'] ? count($childParsed['locs']) : null;
            $facts['truncated'] = $facts['truncated'] || ($childParsed['truncated'] ?? false);
        }
    } else {
        $facts['urls_count'] = count($parsed['locs']);
    }

    $empty = $parsed['is_index'] ? $facts['sitemaps_count'] === 0 : $facts['urls_count'] === 0;
    if ($empty) {
        return [
            'facts'    => ['state' => SEO_STATE_EMPTY] + $facts,
            'findings' => [makeSeoFinding(
                'sitemap_empty',
                'Карта сайта пустая',
                $facts['url'],
                'В карте сайта нет ни одного адреса: поисковикам она ничего не даёт.',
                ['status' => $status]
            )],
        ];
    }

    return ['facts' => ['state' => SEO_STATE_OK] + $facts, 'findings' => $findings];
}

/**
 * Запись `<loc>` — абсолютный адрес http(s) с хостом (а не «Array», путь или пустая строка).
 */
function isSitemapUrl(string $loc): bool
{
    return preg_match('~^https?://[^/\s]+~i', $loc) === 1;
}

/**
 * Распаковывает gzip-тело sitemap с жёстким пределом размера (защита от gzip-бомбы).
 * null — повреждённый поток или размер после распаковки превышает `$maxBytes`.
 */
function decodeSitemapGzip(string $body, int $maxBytes = SEO_SITEMAP_MAX_DECODED_BYTES): ?string
{
    set_error_handler(static fn (): bool => true);
    try {
        $decoded = gzdecode($body, $maxBytes + 1);
    } catch (Throwable) {
        $decoded = false;
    } finally {
        restore_error_handler();
    }

    if ($decoded === false || strlen($decoded) > $maxBytes) {
        return null;
    }

    return $decoded;
}

/**
 * XML-sitemap → тип и адреса `<loc>`. Сжатый gzip распаковывается с пределом `SEO_SITEMAP_MAX_DECODED_BYTES`.
 * Обрезанный по размеру ответ не разбирается как XML (он заведомо неполный) — адреса считаются по тегам.
 * null — не sitemap, превышен лимит распаковки или битый XML.
 *
 * @param array<string, mixed> $response
 * @return array{is_index: bool, locs: list<string>, truncated: bool}|null
 */
function parseSitemap(array $response): ?array
{
    $body = (string) ($response['body'] ?? '');
    $truncated = !empty($response['truncated']);

    if (str_starts_with($body, SEO_GZIP_MAGIC)) {
        $decoded = decodeSitemapGzip($body);
        if ($decoded === null) {
            return null;
        }
        $body = $decoded;
    }

    if (trim($body) === '') {
        return null;
    }

    if ($truncated) {
        $isIndex = stripos($body, '<sitemapindex') !== false;
        if (!$isIndex && stripos($body, '<urlset') === false) {
            return null;
        }
        preg_match_all('~<loc>\s*([^<\s]+)\s*</loc>~i', $body, $matches);

        return ['is_index' => $isIndex, 'locs' => $matches[1], 'truncated' => true];
    }

    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $loaded = $document->loadXML($body, LIBXML_NONET | LIBXML_NOCDATA);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $root = $loaded ? $document->documentElement : null;
    $rootName = $root !== null ? strtolower($root->localName) : '';
    if (!in_array($rootName, ['urlset', 'sitemapindex'], true)) {
        return null;
    }

    $locs = [];
    foreach ($document->getElementsByTagName('loc') as $loc) {
        $value = trim($loc->textContent);
        if ($value !== '') {
            $locs[] = $value;
        }
    }

    return ['is_index' => $rootName === 'sitemapindex', 'locs' => $locs, 'truncated' => false];
}

/**
 * Страницы для SEO: главная + найденные шагом `crawl`; без заглушек защиты, без ответов не 2xx; одна страница под
 * несколькими типами — один раз.
 *
 * @param array<string, mixed> $context
 * @return list<array{url: string, types: list<string>, page_id: ?int, headers: array, parsed: array}>
 */
function collectSeoPages(array $context): array
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
        if (!isSuccessfulSeoStatus($page['status'] ?? null) || is_array($page['bot_protection'] ?? null)) {
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
            'url'     => $url,
            'types'   => [(string) $type],
            'page_id' => isset($page['page_id']) ? (int) $page['page_id'] : null,
            'headers' => is_array($page['headers'] ?? null) ? $page['headers'] : [],
            'parsed'  => parseHtml((string) ($page['body'] ?? ''), $url),
        ];
    }

    return $pages;
}

/**
 * Проверки страниц по SEO_PAGE_RULES. Нет title / description и одинаковый title — по одной находке со списком
 * страниц; canonical, noindex, nofollow — по находке на страницу.
 *
 * @param list<array{url: string, types: list<string>, page_id: ?int, headers: array, parsed: array}> $pages
 * @return list<array>
 */
function checkSeoPages(array $pages, string $homeUrl): array
{
    $siteHost = stripWwwHost((string) parse_url($homeUrl, PHP_URL_HOST));
    $missingTitle = [];
    $missingDescription = [];
    $titles = [];
    $findings = [];

    foreach ($pages as $page) {
        $checks = getSeoChecksForTypes($page['types']);
        $parsed = $page['parsed'];

        if (in_array('title', $checks, true)) {
            $title = trim((string) ($parsed['title'] ?? ''));
            if ($title === '') {
                $missingTitle[] = $page;
            } else {
                $titles[mb_strtolower($title)][] = ['page' => $page, 'title' => $title];
            }
        }

        if (in_array('description', $checks, true) && trim(getMetaContent($parsed, 'description')) === '') {
            $missingDescription[] = $page;
        }

        if (in_array('canonical', $checks, true)) {
            $canonical = findCanonicalUrl($parsed);
            $canonicalHost = $canonical !== null ? stripWwwHost((string) parse_url($canonical, PHP_URL_HOST)) : '';
            if ($canonical !== null && $canonicalHost !== '' && $canonicalHost !== $siteHost) {
                $findings[] = makeSeoFinding(
                    'canonical_foreign',
                    'canonical ведёт на другой сайт',
                    $page['url'],
                    "У страницы canonical {$canonical}: поисковик считает её копией чужой страницы и не показывает в выдаче.",
                    ['canonical' => $canonical, 'pages' => [$page['url']]],
                    $page['page_id'],
                    'canonical:' . $canonicalHost
                );
            }
        }

        $directives = findRobotsDirectives($parsed, $page['headers']);
        $isHome = in_array('home', $page['types'], true);

        if (in_array('noindex', $checks, true) && $directives['noindex'] !== null) {
            $findings[] = makeSeoFinding(
                $isHome ? 'noindex_home' : 'noindex_page',
                ($isHome ? 'Главная' : 'Страница') . ' закрыта от индексации (noindex)',
                $page['url'],
                "Поисковики не добавляют страницу в выдачу: noindex в {$directives['noindex']}.",
                ['source' => $directives['noindex'], 'pages' => [$page['url']]],
                $page['page_id'],
                'noindex'
            );
        }

        if (in_array('nofollow', $checks, true) && $directives['nofollow'] !== null) {
            $findings[] = makeSeoFinding(
                'nofollow',
                'Поисковикам запрещено идти по ссылкам страницы (nofollow)',
                $page['url'],
                "Поисковики не переходят по ссылкам этой страницы: nofollow в {$directives['nofollow']}. "
                    . 'Каталог и товары с неё индексируются хуже.',
                ['source' => $directives['nofollow'], 'pages' => [$page['url']]],
                $page['page_id'],
                'nofollow'
            );
        }
    }

    if ($missingTitle !== []) {
        $findings[] = makeSeoPagesFinding(
            'title_missing',
            'Нет заголовка страницы (title)',
            $missingTitle,
            'Пустой или отсутствующий <title>: в выдаче вместо названия будет случайный текст страницы.'
        );
    }

    foreach ($titles as $same) {
        $samePages = array_column($same, 'page');
        if (count($samePages) < 2) {
            continue;
        }
        $title = $same[0]['title'];
        $findings[] = makeSeoPagesFinding(
            'title_duplicate',
            "Одинаковый title у разных страниц: «{$title}»",
            $samePages,
            'У разных страниц один и тот же заголовок: поисковик не понимает, какую показывать по запросу.',
            mb_strtolower($title)
        );
    }

    if ($missingDescription !== []) {
        $findings[] = makeSeoPagesFinding(
            'description_missing',
            'Нет описания страницы (meta description)',
            $missingDescription,
            'Без meta description поисковик сам выбирает кусок текста для сниппета — обычно неудачный.'
        );
    }

    return $findings;
}

/**
 * Проверки для страницы с этими типами (объединение SEO_PAGE_RULES).
 *
 * @param list<string> $types
 * @return list<string>
 */
function getSeoChecksForTypes(array $types): array
{
    $checks = [];
    foreach ($types as $type) {
        $checks = [...$checks, ...(SEO_PAGE_RULES[$type] ?? [])];
    }

    return array_values(array_unique($checks));
}

/**
 * Где стоит noindex / nofollow: «meta robots», «meta yandex», «заголовке X-Robots-Tag»; null — нет.
 * `none` = noindex + nofollow.
 *
 * @return array{noindex: ?string, nofollow: ?string}
 */
function findRobotsDirectives(array $parsed, array $headers): array
{
    $sources = [];
    foreach ($parsed['meta'] ?? [] as $meta) {
        if (in_array($meta['name'], SEO_ROBOTS_META_NAMES, true)) {
            $sources['meta ' . $meta['name']] = $meta['content'];
        }
    }
    foreach ($headers['x-robots-tag'] ?? [] as $value) {
        $sources['заголовке X-Robots-Tag'] = ($sources['заголовке X-Robots-Tag'] ?? '') . ',' . $value;
    }

    $result = ['noindex' => null, 'nofollow' => null];
    foreach ($sources as $source => $content) {
        $tokens = array_map('trim', preg_split('/[,\s:]+/', strtolower((string) $content)) ?: []);
        $none = in_array('none', $tokens, true);
        if ($result['noindex'] === null && ($none || in_array('noindex', $tokens, true))) {
            $result['noindex'] = $source;
        }
        if ($result['nofollow'] === null && ($none || in_array('nofollow', $tokens, true))) {
            $result['nofollow'] = $source;
        }
    }

    return $result;
}

function getMetaContent(array $parsed, string $name): string
{
    foreach ($parsed['meta'] ?? [] as $meta) {
        if ($meta['name'] === $name) {
            return (string) $meta['content'];
        }
    }

    return '';
}

/**
 * Первый `<link rel="canonical">` (адрес уже абсолютный); null — нет.
 */
function findCanonicalUrl(array $parsed): ?string
{
    foreach ($parsed['links'] ?? [] as $link) {
        if (in_array('canonical', preg_split('/\s+/', (string) $link['rel']) ?: [], true)) {
            return (string) $link['url'];
        }
    }

    return null;
}

function stripWwwHost(string $host): string
{
    $host = strtolower(rtrim($host, '.'));

    return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
}

function buildSeoOrigin(string $url): string
{
    $parts = parse_url($url);
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';

    return strtolower(($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '')) . $port;
}

function isSuccessfulSeoStatus(mixed $status): bool
{
    return is_int($status) && $status >= SEO_HTTP_OK_MIN && $status < SEO_HTTP_REDIRECT_MIN;
}

/**
 * robots.txt — «мягкий 404»: сайт отдал HTML-страницу (по Content-Type или по началу ответа).
 *
 * @param array<string, mixed> $response
 */
function looksLikeHtml(array $response): bool
{
    $contentType = strtolower((string) ($response['headers']['content-type'][0] ?? ''));
    if (str_contains($contentType, 'text/html')) {
        return true;
    }

    $start = strtolower(ltrim(substr((string) ($response['body'] ?? ''), 0, SEO_HTML_SNIFF_BYTES)));

    return str_starts_with($start, '<!doctype html') || str_contains($start, '<html');
}

/**
 * Находка `seo` по ключу SEO_SEVERITY; `key` (часть fingerprint) — сам ключ, уточнённый `$detailKey`.
 *
 * @param array<string, mixed>|null $evidence
 */
function makeSeoFinding(
    string $key,
    string $title,
    string $location,
    string $details,
    ?array $evidence = null,
    ?int $pageId = null,
    string $detailKey = ''
): array {
    return makeFinding(
        'seo',
        $title,
        $location,
        $detailKey !== '' ? "{$key}|{$detailKey}" : $key,
        $details,
        $evidence,
        $pageId,
        SEO_SEVERITY[$key]
    );
}

/**
 * Одна находка на несколько страниц: `page_id` и адрес — первой, все адреса — `evidence.pages`.
 *
 * @param list<array{url: string, page_id: ?int}> $pages
 */
function makeSeoPagesFinding(string $key, string $title, array $pages, string $details, string $detailKey = ''): array
{
    $urls = array_column($pages, 'url');

    return makeSeoFinding(
        $key,
        $title,
        $urls[0],
        $details . (count($urls) > 1 ? ' Страниц: ' . count($urls) . '.' : ''),
        ['pages' => $urls],
        $pages[0]['page_id'],
        $detailKey
    );
}

/**
 * «robots.txt: есть; sitemap: 1520 адресов; страниц: 3; находок: 2».
 */
function buildSeoStepMessage(array $robots, array $sitemap, int $pages, int $findings): string
{
    $robotsText = match ($robots['state']) {
        SEO_STATE_OK      => $robots['disallow_all'] !== [] ? 'закрыт для поисковиков' : 'есть',
        SEO_STATE_MISSING => 'нет',
        SEO_STATE_BLOCKED => 'не удалось проверить',
        default           => 'ошибка',
    };
    $sitemapText = match ($sitemap['state']) {
        SEO_STATE_OK      => $sitemap['is_index']
            ? "индекс из {$sitemap['sitemaps_count']} файлов"
            : "{$sitemap['urls_count']} адресов",
        SEO_STATE_MISSING => 'нет',
        SEO_STATE_EMPTY   => 'пустой',
        SEO_STATE_BLOCKED => 'не удалось проверить',
        default           => 'ошибка',
    };

    return "robots.txt: {$robotsText}; sitemap: {$sitemapText}; страниц: {$pages}; находок: {$findings}";
}
