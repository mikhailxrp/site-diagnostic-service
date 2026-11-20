<?php

declare(strict_types=1);

/**
 * Сценарий заказа в Chrome без окна (шаг `checkout`): товар → «В корзину» → корзина → форма оформления, ширина
 * CHECKOUT_VIEWPORT_WIDTH, одна вкладка (сессия и корзина сохраняются), на каждом шаге — снимок и ошибки консоли.
 *
 * Заказ не отправляется:
 * - кнопки оформления, оплаты и «купить в 1 клик» не нажимаются никогда (стоп-лист в data/checkout-selectors.php);
 *   оформление открывается переходом по адресу, поля формы не заполняются;
 * - весь сценарий идёт под перехватом сети (`Fetch` на вкладке и дочерних целях через `Target.setAutoAttach`,
 *   обход Service Worker и запрет новых окон `--block-new-web-contents`): «заказные» адреса и GET-запросы к ним
 *   блокируются всегда, на стадии добавления действует default-DENY (пропускаются только запросы добавления в корзину
 *   по URL/телу POST без признаков заказа), а с шага оформления — все изменяющие запросы;
 * - все адреса проверяются через `isBrowserRequestAllowed()` (защита от SSRF);
 * - если перехват не включился — сценарий не начинается; ошибка в обработчике перехвата — запрос блокируется;
 * - перехват не отключается до закрытия вкладки, чтобы `sendBeacon` / `keepalive` на `pagehide` тоже блокировались.
 *
 * Решения (какую кнопку нажать, был ли сигнал добавления, корзина пуста или нет, есть ли форма, блокировать ли
 * запрос) — чистые функции без браузера: их покрывают тесты. Страница читается одним скриптом
 * (`buildCheckoutProbeScript()`), который только собирает данные.
 */

use HeadlessChromium\Browser;
use HeadlessChromium\Communication\Message;
use HeadlessChromium\Page;

require_once ROOT_PATH . '/src/Services/BrowserService.php';
require_once ROOT_PATH . '/src/Services/ShopCrawler.php';
require_once ROOT_PATH . '/src/Services/Checks/CheckoutCheck.php';
require_once ROOT_PATH . '/src/Services/Checks/BrowserConsoleCheck.php';
require_once ROOT_PATH . '/src/Services/Checks/BotProtectionCheck.php';

const CHECKOUT_SELECTORS_FILE = ROOT_PATH . '/data/checkout-selectors.php';

const CHECKOUT_VIEWPORT_WIDTH       = BROWSER_WINDOW_WIDTH; // 1366: десктоп, как у владельца магазина
const CHECKOUT_STEP_TIMEOUT_MS      = 20000; // загрузка страницы шага до готовности DOM
const CHECKOUT_ADD_FIRST_CHECK_MS   = 3000;  // после нажатия «В корзину» — первая проверка сигнала
const CHECKOUT_ADD_WAIT_MS          = 8000;  // всего ждём сигнал добавления не дольше
const CHECKOUT_EVAL_TIMEOUT_MS      = 5000;  // скрипт чтения страницы
const CHECKOUT_MAX_URL_ATTEMPTS     = 3;     // адресов корзины / оформления пробуется не больше
const CHECKOUT_PAGE_TEXT_MAX_CHARS  = 20000; // видимый текст страницы для проверок по тексту
const CHECKOUT_PAGE_HTML_MAX_CHARS  = 200000; // HTML нужен только в памяти для распознавания страницы защиты
const CHECKOUT_MAX_CANDIDATES       = 300;   // кнопок-кандидатов со страницы
const CHECKOUT_MAX_LINKS            = 1000;  // ссылок со страницы
const CHECKOUT_BUTTON_TEXT_MAX      = 40;    // длиннее — не кнопка, а блок текста
const CHECKOUT_MIN_CONTACT_FIELDS   = 3;     // форма обратного звонка — 2 поля, оформление — больше
const CHECKOUT_CONSOLE_SAMPLE       = 2;     // образцов ошибок консоли на шаг
const CHECKOUT_CONSOLE_MESSAGE_MAX_CHARS = 150;
const CHECKOUT_BLOCKED_CONSOLE_TEXT = 'ERR_BLOCKED_BY_CLIENT'; // след нашего перехвата, не ошибка сайта
const CHECKOUT_REQUEST_LOG_MAX      = 50;    // записей лога перехвата (только изменяющие запросы)
const CHECKOUT_RESPONSES_MAX        = 200;   // ответов, записанных после нажатия «В корзину»
const CHECKOUT_PUMP_INTERVAL_US     = 50000;

const CHECKOUT_MUTATING_METHODS          = ['POST', 'PUT', 'PATCH', 'DELETE'];
const CHECKOUT_BLOCK_REASON              = 'BlockedByClient';
const CHECKOUT_DOCUMENT_TYPE             = 'Document';
const CHECKOUT_SCRIPT_TYPE               = 'Script';
const CHECKOUT_STATIC_GET_TYPES          = ['Stylesheet', 'Font', 'Image', 'Media'];
const CHECKOUT_API_TYPES                 = ['XHR', 'Fetch'];
const CHECKOUT_HTTP_OK_MIN               = 200;
const CHECKOUT_HTTP_OK_MAX               = 299;
const CHECKOUT_CART_REQUEST_PATTERN      = '~cart|basket|korzin~i';
const CHECKOUT_POST_DATA_MAX_BYTES       = 16384;
const CHECKOUT_STATIC_SCRIPT_EXT_PATTERN = '~\.m?js$~i';
const CHECKOUT_INSTANT_ORDER_PATTERN     = '~one[-_]?click|1[-_]?click|buy1click|(?:quick|fast)[-_]?(?:order|buy)~i';
const CHECKOUT_URL_ORDER_ATTR_PATTERN    = '~(?:/|\?|[=&])(?:order|zakaz|oformlen|payment|pay)(?:[/_.?&=#-]|$)~i';
const CHECKOUT_DIRECT_CHECKOUT_ADD_PATTERN = '~/checkout/\?~i';

const CHECKOUT_ACTION_BLOCKED = 'blocked';
const CHECKOUT_ACTION_PASSED  = 'passed';

/** Порядок стадий: стадия только растёт, «оформление» не откатывается */
const CHECKOUT_STAGES = [CHECKOUT_STEP_PRODUCT, CHECKOUT_STEP_ADD, CHECKOUT_STEP_CART, CHECKOUT_STEP_CHECKOUT];

const CHECKOUT_SIGNAL_NAVIGATION = 'navigation';
const CHECKOUT_SIGNAL_COUNTER    = 'counter';
const CHECKOUT_SIGNAL_MESSAGE    = 'message';
const CHECKOUT_SIGNAL_REQUEST    = 'request';

const CHECKOUT_CART_ITEMS    = 'items';
const CHECKOUT_CART_EMPTY    = 'empty';
const CHECKOUT_CART_UNKNOWN  = 'unknown';  // похоже на корзину, товар не распознан — идём дальше
const CHECKOUT_CART_NOT_CART = 'not_cart'; // не корзина («мягкий 404»)

const CHECKOUT_NOTE_INLINE        = 'корзина во всплывающем окне на странице';
const CHECKOUT_NOTE_ITEMS_UNKNOWN = 'товар в корзине не распознан';
const CHECKOUT_NOTE_NO_SIGNAL     = 'нет сигнала добавления';
const CHECKOUT_NOTE_FORM_IN_CART  = 'форма оформления на странице корзины';

const CHECKOUT_PROBE_FAILED    = 'страница не прочиталась';
const CHECKOUT_NO_CART_URL     = 'адрес корзины не найден';
const CHECKOUT_NOT_CART_PAGE   = 'страница не похожа на корзину';
const CHECKOUT_NO_CHECKOUT_URL = 'адрес оформления не найден';

const CHECKOUT_CSS_KEYS = ['add_to_cart', 'cart_count', 'cart_item', 'cart_empty', 'checkout_form', 'out_of_stock', 'variants'];
const CHECKOUT_TEXT_KEYS = ['add_to_cart_texts', 'forbidden_texts', 'forbidden_attrs', 'added_texts', 'cart_empty_texts',
    'out_of_stock_texts'];

const CHECKOUT_PROBE_PLACEHOLDER = '__CHECKOUT_CONFIG__';

/** Скрипт чтения страницы: видимые совпадения селекторов, кнопки-кандидаты (помечаются `data-sd-candidate`), ссылки,
 *  формы с числом контактных полей, видимый текст. Ничего не нажимает и не меняет, кроме пометок. */
const CHECKOUT_PROBE_SCRIPT = <<<'JS'
(() => {
  const cfg = __CHECKOUT_CONFIG__;
  const norm = (s) => String(s || '').replace(/\s+/g, ' ').trim().toLowerCase().replace(/ё/g, 'е');
  const all = (sel) => { try { return Array.from(document.querySelectorAll(sel)); } catch (e) { return []; } };
  const visible = (el) => {
    if (!(el.offsetWidth || el.offsetHeight || el.getClientRects().length)) return false;
    const style = getComputedStyle(el);
    return style.visibility !== 'hidden' && style.display !== 'none';
  };
  const counts = {};
  for (const [name, list] of Object.entries(cfg.selectors)) {
    counts[name] = list.reduce((sum, sel) => sum + all(sel).filter(visible).length, 0);
  }
  const cartCount = cfg.selectors.cart_count.flatMap(all).map((el) => norm(el.textContent)).join('|');
  all('[data-sd-candidate]').forEach((el) => el.removeAttribute('data-sd-candidate'));
  const seen = new Set();
  const candidates = [];
  const attrNames = ['class', 'id', 'name', 'onclick', 'href', 'title', 'aria-label', 'data-action', 'formaction', 'value', 'data-url', 'data-href', 'data-target'];
  const push = (el, bySelector) => {
    if (seen.has(el) || candidates.length >= cfg.maxCandidates) return;
    seen.add(el);
    const index = candidates.length;
    el.setAttribute('data-sd-candidate', String(index));
    const className = typeof el.className === 'string' ? el.className : '';
    const formEl = el.closest('form');
    const formAction = el.getAttribute('formaction') || (formEl ? (formEl.getAttribute('action') || '') : '');
    candidates.push({
      index,
      text: norm(el.innerText || el.value || el.getAttribute('aria-label') || el.title).slice(0, 100),
      attrs: norm(attrNames.map((a) => el.getAttribute(a) || '').join(' ')).slice(0, 500),
      form_action: norm(formAction).slice(0, 200),
      by_selector: bySelector,
      visible: visible(el),
      disabled: !!(el.disabled || el.getAttribute('aria-disabled') === 'true' || /(^|\s)disabled(\s|$)/.test(className)),
    });
  };
  cfg.selectors.add_to_cart.flatMap(all).forEach((el) => push(el, true));
  all('button, a, input[type="submit"], input[type="button"], [role="button"]').forEach((el) => {
    const text = norm(el.innerText || el.value);
    if (text !== '' && text.length <= cfg.maxButtonText) push(el, false);
  });
  const links = all('a[href]').slice(0, cfg.maxLinks).map((a) => ({
    href: a.href,
    text: norm(a.innerText || a.title || a.getAttribute('aria-label')).slice(0, 100),
  }));
  const contact = new RegExp(cfg.contactPattern, 'i');
  const skipTypes = ['hidden', 'submit', 'button', 'checkbox', 'radio', 'password', 'search', 'image', 'reset', 'file'];
  const forms = all('form').map((form) => ({
    contact_fields: Array.from(form.querySelectorAll('input, textarea, select')).filter((el) => !skipTypes.includes(el.type)
      && visible(el)
      && contact.test([el.name, el.id, el.type, el.placeholder, el.getAttribute('autocomplete'), el.getAttribute('aria-label')].join(' '))
    ).length,
  }));
  return {
    url: location.href,
    ready: document.readyState,
    text: norm(document.body ? document.body.innerText : '').slice(0, cfg.maxText),
    html: (document.documentElement ? document.documentElement.outerHTML : '').slice(0, cfg.maxHtml),
    counts,
    cart_count: cartCount,
    candidates,
    links,
    forms,
  };
})()
JS;

const CHECKOUT_CLICK_SCRIPT = <<<'JS'
(() => {
  const el = document.querySelector('[data-sd-candidate="%d"]');
  if (!el) return false;
  el.scrollIntoView({ block: 'center' });
  el.click();
  return true;
})()
JS;

// ---------------------------------------------------------------------------------------------------------------
// Правила и цель сценария (без браузера)
// ---------------------------------------------------------------------------------------------------------------

/**
 * @return array<string, mixed>
 */
function loadCheckoutSelectors(): array
{
    $selectors = require CHECKOUT_SELECTORS_FILE;
    if (!is_array($selectors)) {
        throw new RuntimeException('Файл правил сценария заказа повреждён: ' . CHECKOUT_SELECTORS_FILE);
    }

    return $selectors;
}

/**
 * Правила сценария для платформ сайта: CSS-селекторы платформ, затем общие; тексты, шаблоны запросов; адреса
 * корзины / оформления и признаки ссылок — из правил обхода (`buildShopRules()`).
 *
 * @param array<string, mixed> $selectors   data/checkout-selectors.php
 * @param array<string, mixed> $shopPatterns data/shop-patterns.php
 * @param list<string>         $platforms   ключи платформ
 * @return array<string, mixed>
 */
function buildCheckoutRules(array $selectors, array $shopPatterns, array $platforms): array
{
    $css = array_fill_keys(CHECKOUT_CSS_KEYS, []);
    $inline = false;
    foreach ($platforms as $key) {
        $platform = $selectors['platforms'][$key] ?? null;
        if (!is_array($platform)) {
            continue;
        }
        foreach (CHECKOUT_CSS_KEYS as $name) {
            $css[$name] = [...$css[$name], ...($platform[$name] ?? [])];
        }
        $inline = $inline || ($platform['inline_cart'] ?? false) === true;
    }
    foreach (CHECKOUT_CSS_KEYS as $name) {
        $css[$name] = array_values(array_unique([...$css[$name], ...($selectors['generic'][$name] ?? [])]));
    }

    $texts = [];
    foreach (CHECKOUT_TEXT_KEYS as $name) {
        $texts[$name] = $selectors['generic'][$name] ?? [];
    }

    $shopRules = buildShopRules($shopPatterns, array_values(array_filter(
        $platforms,
        static fn (string $key): bool => isset($shopPatterns['platforms'][$key])
    )));
    $generic = $shopRules['generic'];

    return [
        'css'      => $css,
        'texts'    => $texts,
        'requests' => [
            'order'             => $selectors['requests']['order'] ?? [],
            'add_to_cart'       => $selectors['requests']['add_to_cart'] ?? [],
            'checkout_document' => $selectors['requests']['checkout_document'] ?? [],
            'add_to_cart_body'  => $selectors['requests']['add_to_cart_body'] ?? [],
            'order_body'        => $selectors['requests']['order_body'] ?? [],
        ],
        'contact_field_pattern' => (string) ($selectors['contact_field_pattern'] ?? ''),
        'inline_cart' => $inline,
        'paths'    => $shopRules['paths'],
        'links'    => [
            CHECKOUT_STEP_CART     => ['url' => $generic['cart_link'] ?? [], 'text' => $generic['cart_link_text'] ?? []],
            CHECKOUT_STEP_CHECKOUT => ['url' => $generic['checkout_link'] ?? [], 'text' => $generic['checkout_link_text'] ?? []],
        ],
        'pages'    => $shopRules['pages'],
    ];
}

/**
 * Платформы — из шага `crawl`, без него — по стеку.
 *
 * @param array<string, mixed> $context
 * @return list<string>
 */
function detectCheckoutPlatforms(array $context, array $knownKeys): array
{
    $platforms = $context['facts']['crawl']['platforms'] ?? null;
    if (is_array($platforms)) {
        return array_values(array_filter($platforms, 'is_string'));
    }

    return detectShopPlatforms($context['facts']['stack']['items'] ?? [], $knownKeys);
}

/**
 * С чего начинать: товар из шага `crawl` (туда уже попадает «Адрес товара» из формы), без фактов обхода — сам
 * `product_url`. Корзина и оформление из обхода — если ответили 2xx и не закрыты защитой от ботов.
 *
 * @param array<string, mixed> $context
 * @return array{url: ?string, found_by: ?string, cart: ?array{url: string, found_by: string},
 *               checkout: ?array{url: string, found_by: string}, skip: ?string, detail: ?string}
 */
function selectCheckoutTarget(array $context): array
{
    $target = ['url' => null, 'found_by' => null, 'cart' => null, 'checkout' => null, 'skip' => null, 'detail' => null];
    $pages = $context['facts']['crawl']['pages'] ?? [];
    $pages = is_array($pages) ? $pages : [];

    foreach ([CHECKOUT_STEP_CART, CHECKOUT_STEP_CHECKOUT] as $type) {
        $page = $pages[$type] ?? null;
        if (is_array($page) && isSuccessfulStatus($page['status'] ?? null) && ($page['bot_protection'] ?? null) === null) {
            $target[$type] = ['url' => crawlPageUrl($page), 'found_by' => (string) ($page['found_by'] ?? CRAWL_FOUND_BY_PATTERN)];
        }
    }

    $product = $pages['product'] ?? null;
    if (is_array($product)) {
        if (is_array($product['bot_protection'] ?? null)) {
            return ['skip' => CHECKOUT_REASON_BOT_PROTECTION, 'detail' => (string) ($product['bot_protection']['title'] ?? '')]
                + $target;
        }
        if (!isSuccessfulStatus($product['status'] ?? null)) {
            $status = $product['status'] ?? null;

            return ['skip' => CHECKOUT_REASON_PRODUCT_NOT_OPENED,
                'detail' => is_int($status) ? 'ответ ' . $status : 'нет ответа'] + $target;
        }

        return ['url' => crawlPageUrl($product), 'found_by' => (string) ($product['found_by'] ?? CRAWL_FOUND_BY_LINKS)]
            + $target;
    }

    $productUrl = $context['diagnostic']['product_url'] ?? null;
    if (is_string($productUrl) && $productUrl !== '') {
        return ['url' => $productUrl, 'found_by' => CRAWL_FOUND_BY_MANUAL] + $target;
    }

    return ['skip' => CHECKOUT_REASON_NO_PRODUCT] + $target;
}

/**
 * @param array<string, mixed> $page страница из фактов `crawl`
 */
function crawlPageUrl(array $page): string
{
    return (string) (($page['final_url'] ?? '') !== '' ? $page['final_url'] : $page['url']);
}

/**
 * Адреса корзины или оформления по порядку: из обхода → ссылки на текущей странице (по адресу или тексту) → типовые
 * пути платформы и общие. Только свой сайт, без повторов.
 *
 * @param array{url: string, found_by: string}|null $crawled
 * @param list<array{href: string, text: string}>   $links
 * @return list<array{url: string, found_by: string}>
 */
function buildCheckoutUrlCandidates(
    string $type,
    ?array $crawled,
    array $links,
    array $rules,
    string $origin,
    string $siteHost
): array {
    $candidates = [];
    $seen = [];
    $add = static function (string $url, string $foundBy) use (&$candidates, &$seen, $siteHost): void {
        $url = explode('#', $url, 2)[0];
        $key = normalizeCrawlUrl($url);
        if ($url === '' || isset($seen[$key]) || !isSameSiteHost($url, $siteHost)) {
            return;
        }
        $seen[$key] = true;
        $candidates[] = ['url' => $url, 'found_by' => $foundBy];
    };

    if ($crawled !== null) {
        $add($crawled['url'], $crawled['found_by']);
    }
    foreach ($links as $link) {
        $href = (string) ($link['href'] ?? '');
        if (!str_starts_with($href, 'http')) {
            continue;
        }
        if (matchesAnyPattern(crawlUrlSubject($href), $rules['links'][$type]['url'])
            || matchesAnyPattern((string) ($link['text'] ?? ''), $rules['links'][$type]['text'])) {
            $add($href, CRAWL_FOUND_BY_LINKS);
        }
    }
    foreach ($rules['paths'][$type] ?? [] as $path => $foundBy) {
        $add($origin . $path, $foundBy);
    }

    return $candidates;
}

// ---------------------------------------------------------------------------------------------------------------
// Перехват запросов (без браузера)
// ---------------------------------------------------------------------------------------------------------------

/**
 * Стадия только растёт: открытое оформление не «откатывается» до корзины.
 */
function raiseCheckoutStage(string $current, string $next): string
{
    $order = array_flip(CHECKOUT_STAGES);

    return ($order[$next] ?? 0) > ($order[$current] ?? 0) ? $next : $current;
}

/**
 * Извлекает тело POST/PUT/PATCH-запроса из параметров CDP `Fetch.requestPaused`, ограничивая размер.
 *
 * @param array<string, mixed> $request
 */
function extractCheckoutRequestPostData(array $request): ?string
{
    $postData = $request['postData'] ?? null;
    if (is_string($postData) && $postData !== '') {
        return substr($postData, 0, CHECKOUT_POST_DATA_MAX_BYTES);
    }

    $entries = $request['postDataEntries'] ?? null;
    if (!is_array($entries)) {
        return null;
    }

    $combined = '';
    foreach ($entries as $entry) {
        if (!is_array($entry) || !is_string($entry['bytes'] ?? null)) {
            continue;
        }
        $decoded = base64_decode($entry['bytes'], true);
        if ($decoded === false || $decoded === '') {
            continue;
        }
        $remaining = CHECKOUT_POST_DATA_MAX_BYTES - strlen($combined);
        if ($remaining <= 0) {
            break;
        }
        $combined .= substr($decoded, 0, $remaining);
    }

    return $combined !== '' ? $combined : null;
}

/**
 * Безопасные для GET статические ресурсы и документы оформления (но не быстрый заказ / 1 клик и не PHP/RPC-обработчики).
 */
function isStaticCheckoutAssetGet(string $url, string $resourceType): bool
{
    if ($resourceType === CHECKOUT_DOCUMENT_TYPE) {
        return preg_match(CHECKOUT_INSTANT_ORDER_PATTERN, crawlUrlSubject($url)) !== 1;
    }
    if (in_array($resourceType, CHECKOUT_STATIC_GET_TYPES, true)) {
        return true;
    }
    if ($resourceType === CHECKOUT_SCRIPT_TYPE) {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return preg_match(CHECKOUT_STATIC_SCRIPT_EXT_PATTERN, $path) === 1;
    }

    return false;
}

/**
 * Решение по перехваченному запросу.
 * - Документ оформления → стадия «оформление».
 * - GET: блокируются адреса быстрого заказа и любые «заказные» запросы, кроме статических ассетов и самих страниц оформления.
 * - Изменяющие запросы: на стадии оформления или на «заказной» URL блокируются всегда; на ранних стадиях действует
 *   default-DENY — пропускаются только запросы с явным признаком добавления в корзину (в URL или теле) и без признаков заказа в теле.
 *
 * @param array<string, list<string>> $patterns
 * @return array{block: bool, stage: string}
 */
function decideCheckoutRequest(
    string $method,
    string $url,
    string $resourceType,
    string $stage,
    array $patterns,
    ?string $postData = null
): array {
    $subject = crawlUrlSubject($url);
    if ($resourceType === CHECKOUT_DOCUMENT_TYPE && matchesAnyPattern($subject, $patterns['checkout_document'])) {
        $stage = raiseCheckoutStage($stage, CHECKOUT_STEP_CHECKOUT);
    }

    $isOrderUrl = matchesAnyPattern($subject, $patterns['order'])
        && !matchesAnyPattern($subject, $patterns['add_to_cart']);
    $isMutating = in_array(strtoupper($method), CHECKOUT_MUTATING_METHODS, true);

    if (!$isMutating) {
        $isInstantDoc = $resourceType === CHECKOUT_DOCUMENT_TYPE
            && preg_match(CHECKOUT_INSTANT_ORDER_PATTERN, $subject) === 1;
        $block = $isInstantDoc || ($isOrderUrl && !isStaticCheckoutAssetGet($url, $resourceType));

        return ['block' => $block, 'stage' => $stage];
    }

    if ($stage === CHECKOUT_STEP_CHECKOUT || $isOrderUrl) {
        return ['block' => true, 'stage' => $stage];
    }

    $post = $postData !== null ? substr($postData, 0, CHECKOUT_POST_DATA_MAX_BYTES) : '';
    $looksLikeAdd = matchesAnyPattern($subject, $patterns['add_to_cart'])
        || ($post !== '' && matchesAnyPattern($post, $patterns['add_to_cart_body'] ?? []));
    $looksLikeOrder = $post !== '' && matchesAnyPattern($post, $patterns['order_body'] ?? []);

    return ['block' => !$looksLikeAdd || $looksLikeOrder, 'stage' => $stage];
}

/**
 * Пустая статистика перехвата.
 *
 * @return array{checkout_sent: int, blocked: int, passed: int, dropped: int, errors: int, log: list<array>}
 */
function createCheckoutRequestStats(): array
{
    return ['checkout_sent' => 0, 'blocked' => 0, 'passed' => 0, 'dropped' => 0, 'errors' => 0, 'log' => []];
}

/**
 * Учёт перехваченного запроса. В лог — все изменяющие запросы и заблокированные не-изменяющие запросы:
 * метод, адрес без query, стадия, итог.
 *
 * @param array<string, mixed> $stats из createCheckoutRequestStats()
 * @return array<string, mixed>
 */
function recordCheckoutRequest(array $stats, string $method, string $url, string $stage, bool $blocked): array
{
    $method = strtoupper($method);
    $isMutating = in_array($method, CHECKOUT_MUTATING_METHODS, true);
    if (!$isMutating && !$blocked) {
        return $stats;
    }

    if ($blocked) {
        $stats['blocked']++;
    } else {
        $stats['passed']++;
        if ($stage === CHECKOUT_STEP_CHECKOUT) {
            $stats['checkout_sent']++;
        }
    }

    if (count($stats['log']) >= CHECKOUT_REQUEST_LOG_MAX) {
        $stats['dropped']++;

        return $stats;
    }
    $stats['log'][] = [
        'stage'  => $stage,
        'method' => $method,
        'url'    => stripCheckoutUrlQuery($url),
        'action' => $blocked ? CHECKOUT_ACTION_BLOCKED : CHECKOUT_ACTION_PASSED,
    ];

    return $stats;
}

/**
 * Адрес без query и фрагмента: в query бывают токены и данные формы.
 */
function stripCheckoutUrlQuery(string $url): string
{
    return (string) preg_replace('~[?#].*$~s', '', $url);
}

// ---------------------------------------------------------------------------------------------------------------
// Разбор страницы (без браузера)
// ---------------------------------------------------------------------------------------------------------------

/**
 * Ответ скрипта чтения → массив с гарантированными ключами (битый ответ — пустая страница).
 *
 * @return array{url: string, ready: string, text: string, html: string, counts: array<string, int>, cart_count: string,
 *               candidates: list<array>, links: list<array>, forms: list<array{contact_fields: int}>}
 */
function normalizeCheckoutProbe(mixed $raw): array
{
    $raw = is_array($raw) ? $raw : [];
    $counts = [];
    foreach (CHECKOUT_CSS_KEYS as $name) {
        $counts[$name] = (int) ($raw['counts'][$name] ?? 0);
    }

    $candidates = [];
    foreach (is_array($raw['candidates'] ?? null) ? $raw['candidates'] : [] as $candidate) {
        if (is_array($candidate) && isset($candidate['index'])) {
            $candidates[] = [
                'index'       => (int) $candidate['index'],
                'text'        => (string) ($candidate['text'] ?? ''),
                'attrs'       => (string) ($candidate['attrs'] ?? ''),
                'form_action' => (string) ($candidate['form_action'] ?? ''),
                'by_selector' => ($candidate['by_selector'] ?? false) === true,
                'visible'     => ($candidate['visible'] ?? false) === true,
                'disabled'    => ($candidate['disabled'] ?? false) === true,
            ];
        }
    }

    return [
        'url'        => (string) ($raw['url'] ?? ''),
        'ready'      => (string) ($raw['ready'] ?? ''),
        'text'       => (string) ($raw['text'] ?? ''),
        'html'       => (string) ($raw['html'] ?? ''),
        'counts'     => $counts,
        'cart_count' => (string) ($raw['cart_count'] ?? ''),
        'candidates' => $candidates,
        'links'      => array_values(array_filter(
            is_array($raw['links'] ?? null) ? $raw['links'] : [],
            static fn (mixed $link): bool => is_array($link) && is_string($link['href'] ?? null)
        )),
        'forms'      => array_map(
            static fn (mixed $form): array => ['contact_fields' => is_array($form) ? (int) ($form['contact_fields'] ?? 0) : 0],
            is_array($raw['forms'] ?? null) ? array_values($raw['forms']) : []
        ),
    ];
}

/**
 * Опасен ли `action` родительской формы (или `formaction` кнопки): быстрый заказ, прямой переход на `/checkout/?`
 * или признаки заказа/оплаты при отсутствии шаблона добавления в корзину.
 */
function isCheckoutFormActionForbidden(string $formAction, array $rules): bool
{
    if ($formAction === '') {
        return false;
    }
    if (
        preg_match(CHECKOUT_INSTANT_ORDER_PATTERN, $formAction) === 1
        || preg_match(CHECKOUT_DIRECT_CHECKOUT_ADD_PATTERN, $formAction) === 1
    ) {
        return true;
    }
    if (matchesAnyPattern($formAction, $rules['requests']['add_to_cart'] ?? [])) {
        return false;
    }

    return matchesAnyPattern($formAction, $rules['texts']['forbidden_attrs'] ?? [])
        || matchesAnyPattern($formAction, $rules['requests']['order'] ?? [])
        || matchesAnyPattern($formAction, $rules['requests']['order_body'] ?? []);
}

/**
 * Кнопку нельзя нажимать: текст, атрибуты или `action` родительской формы ведут на оформление, оплату
 * или покупку в 1 клик.
 *
 * @param array{text: string, attrs: string, form_action?: string} $candidate
 */
function isForbiddenCheckoutButton(array $candidate, array $rules): bool
{
    if (
        matchesAnyPattern($candidate['text'], $rules['texts']['forbidden_texts'])
        || matchesAnyPattern($candidate['attrs'], $rules['texts']['forbidden_attrs'])
        || isCheckoutFormActionForbidden((string) ($candidate['form_action'] ?? ''), $rules)
    ) {
        return true;
    }

    // Кнопка с общим текстом «купить», у которой href/data-* указывает на заказ/оплату (и не на корзину), опасна
    if (
        $candidate['text'] === 'купить'
        && preg_match(CHECKOUT_URL_ORDER_ATTR_PATTERN, $candidate['attrs']) === 1
        && !matchesAnyPattern($candidate['attrs'], $rules['requests']['add_to_cart'] ?? [])
    ) {
        return true;
    }

    return false;
}

function isAddToCartText(string $text, array $rules): bool
{
    return $text !== '' && matchesAnyPattern($text, $rules['texts']['add_to_cart_texts']);
}

/**
 * Кнопка «В корзину»: только видимые и не из стоп-листа. Сначала активная по селектору (основная кнопка карточки
 * выше блоков «похожие товары»), потом активная по тексту, иначе — неактивная (для причины «неактивна»).
 *
 * @param list<array<string, mixed>> $candidates
 * @return array<string, mixed>|null
 */
function chooseAddToCartButton(array $candidates, array $rules): ?array
{
    $usable = array_values(array_filter(
        $candidates,
        static fn (array $candidate): bool => $candidate['visible'] && !isForbiddenCheckoutButton($candidate, $rules)
    ));
    $matches = array_values(array_filter(
        $usable,
        static fn (array $candidate): bool => $candidate['by_selector'] || isAddToCartText($candidate['text'], $rules)
    ));

    foreach ([true, false] as $bySelector) {
        foreach ($matches as $candidate) {
            if (!$candidate['disabled'] && ($candidate['by_selector'] === $bySelector)) {
                return $candidate;
            }
        }
    }

    return $matches[0] ?? null;
}

/**
 * Страница товара: обязательные варианты → пропуск; кнопки нет или она неактивна → «нет в наличии» (пропуск) или
 * поломка; иначе — кнопка, которую нажать.
 *
 * @param array<string, mixed> $probe из normalizeCheckoutProbe()
 * @return array{button: ?array, reason: ?string}
 */
function analyzeCheckoutProductPage(array $probe, array $rules): array
{
    if ($probe['counts']['variants'] > 0) {
        return ['button' => null, 'reason' => CHECKOUT_REASON_VARIANTS];
    }

    $button = chooseAddToCartButton($probe['candidates'], $rules);
    if ($button === null || $button['disabled']) {
        if (isCheckoutOutOfStock($probe, $rules)) {
            return ['button' => null, 'reason' => CHECKOUT_REASON_OUT_OF_STOCK];
        }

        return ['button' => null, 'reason' => $button === null ? CHECKOUT_REASON_BUTTON_NOT_FOUND : CHECKOUT_REASON_BUTTON_DISABLED];
    }

    return ['button' => $button, 'reason' => null];
}

function isCheckoutOutOfStock(array $probe, array $rules): bool
{
    return $probe['counts']['out_of_stock'] > 0 || matchesAnyPattern($probe['text'], $rules['texts']['out_of_stock_texts']);
}

/**
 * Защита могла появиться только в Chrome, хотя HTTP-обход получил настоящую страницу. Заголовков ответа здесь нет,
 * поэтому используются признаки HTML из общего справочника `data/bot-protection.php`.
 *
 * @param array<string, mixed> $probe
 * @return array{provider: string, title: string, reason: string}|null
 */
function detectCheckoutBotProtection(array $probe): ?array
{
    return detectBotProtection(['body' => (string) ($probe['html'] ?? '')]);
}

/**
 * Сигнал, что товар добавился: переход на другую страницу, изменился счётчик корзины, появилось сообщение
 * «добавлен в корзину», или после нажатия ответил 2xx запрос добавления. Нет сигнала — null.
 *
 * @param array<string, mixed> $before    страница до нажатия
 * @param array<string, mixed> $after     после
 * @param list<array{url: string, status: int, type: string, method: string}> $responses ответы после нажатия
 */
function detectAddToCartSignal(array $before, array $after, array $responses, array $rules): ?string
{
    if ($after['url'] !== '' && normalizeCrawlUrl($after['url']) !== normalizeCrawlUrl($before['url'])) {
        return CHECKOUT_SIGNAL_NAVIGATION;
    }
    if ($after['cart_count'] !== '' && $after['cart_count'] !== $before['cart_count']) {
        return CHECKOUT_SIGNAL_COUNTER;
    }
    if (countCheckoutPatternMatches($after['text'], $rules['texts']['added_texts'])
        > countCheckoutPatternMatches($before['text'], $rules['texts']['added_texts'])) {
        return CHECKOUT_SIGNAL_MESSAGE;
    }

    foreach ($responses as $response) {
        $ok = $response['status'] >= CHECKOUT_HTTP_OK_MIN && $response['status'] <= CHECKOUT_HTTP_OK_MAX;
        $subject = crawlUrlSubject($response['url']);
        $isAdd = matchesAnyPattern($subject, $rules['requests']['add_to_cart'])
            || (in_array($response['type'], CHECKOUT_API_TYPES, true) && preg_match(CHECKOUT_CART_REQUEST_PATTERN, $subject) === 1);
        if ($ok && $isAdd) {
            return CHECKOUT_SIGNAL_REQUEST;
        }
    }

    return null;
}

/**
 * @param list<string> $patterns
 */
function countCheckoutPatternMatches(string $text, array $patterns): int
{
    $count = 0;
    foreach ($patterns as $pattern) {
        $count += (int) preg_match_all($pattern, $text);
    }

    return $count;
}

/**
 * Страница корзины: есть строка товара / «корзина пуста» / похоже на корзину, но товар не распознан / не корзина.
 *
 * @param array<string, mixed> $probe
 */
function classifyCheckoutCart(array $probe, array $rules): string
{
    if ($probe['counts']['cart_item'] > 0) {
        return CHECKOUT_CART_ITEMS;
    }
    if ($probe['counts']['cart_empty'] > 0 || matchesAnyPattern($probe['text'], $rules['texts']['cart_empty_texts'])) {
        return CHECKOUT_CART_EMPTY;
    }

    return matchesAnyPattern($probe['text'], $rules['pages']['cart'] ?? []) ? CHECKOUT_CART_UNKNOWN : CHECKOUT_CART_NOT_CART;
}

/**
 * Форма оформления: видимый блок формы платформы или форма с CHECKOUT_MIN_CONTACT_FIELDS видимыми контактными
 * полями (форма обратного звонка в подвале — два поля, её не считаем).
 *
 * @param array<string, mixed> $probe
 */
function hasCheckoutForm(array $probe): bool
{
    if ($probe['counts']['checkout_form'] > 0) {
        return true;
    }
    foreach ($probe['forms'] as $form) {
        if ($form['contact_fields'] >= CHECKOUT_MIN_CONTACT_FIELDS) {
            return true;
        }
    }

    return false;
}

/**
 * Ошибки скриптов шага (исключения, console.error, ошибки Log) → число и первые CHECKOUT_CONSOLE_SAMPLE сообщений.
 *
 * @param list<array<string, mixed>> $events события `applyBrowserEvent()`
 * @return array{count: int, sample: list<string>}
 */
function summarizeCheckoutConsole(array $events, int $dropped = 0): array
{
    $messages = [];
    foreach ($events as $event) {
        $message = (string) ($event['message'] ?? '');
        if (in_array($event['kind'] ?? '', [BROWSER_EVENT_EXCEPTION, BROWSER_EVENT_CONSOLE, BROWSER_EVENT_LOG], true)
            && !str_contains($message, CHECKOUT_BLOCKED_CONSOLE_TEXT)) {
            $messages[] = mb_substr($message, 0, CHECKOUT_CONSOLE_MESSAGE_MAX_CHARS);
        }
    }

    return ['count' => count($messages) + $dropped, 'sample' => array_slice($messages, 0, CHECKOUT_CONSOLE_SAMPLE)];
}

/**
 * Скрипт чтения страницы с настройками.
 */
function buildCheckoutProbeScript(array $rules): string
{
    $config = json_encode([
        'selectors'      => $rules['css'],
        'contactPattern' => $rules['contact_field_pattern'],
        'maxText'        => CHECKOUT_PAGE_TEXT_MAX_CHARS,
        'maxHtml'        => CHECKOUT_PAGE_HTML_MAX_CHARS,
        'maxCandidates'  => CHECKOUT_MAX_CANDIDATES,
        'maxLinks'       => CHECKOUT_MAX_LINKS,
        'maxButtonText'  => CHECKOUT_BUTTON_TEXT_MAX,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    return str_replace(CHECKOUT_PROBE_PLACEHOLDER, $config, CHECKOUT_PROBE_SCRIPT);
}

/**
 * Шаг сценария для результата (`screenshot` — двоичный снимок, его сохраняет воркер).
 *
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function makeCheckoutStep(string $code, string $status, ?string $url, ?string $foundBy, array $extra = []): array
{
    return array_merge([
        'code'           => $code,
        'status'         => $status,
        'url'            => $url,
        'found_by'       => $foundBy,
        'page_type'      => CHECKOUT_STEP_PAGE_TYPES[$code],
        'opened'         => false,
        'dom_ms'         => null,
        'console'        => [],
        'console_errors' => 0,
        'note'           => null,
    ], $extra);
}

/**
 * Итог прохода.
 *
 * @param list<array<string, mixed>> $steps
 * @return array{outcome: string, reason: ?string, detail: ?string, signal: ?string, steps: list<array>}
 */
function finishCheckoutWalk(array $steps, string $outcome, ?string $reason, ?string $detail = null, ?string $signal = null): array
{
    return ['outcome' => $outcome, 'reason' => $reason, 'detail' => $detail, 'signal' => $signal, 'steps' => $steps];
}

// ---------------------------------------------------------------------------------------------------------------
// Проход в браузере
// ---------------------------------------------------------------------------------------------------------------

/**
 * Параметры запуска Chrome для сценария заказа: отключаем авто-разрешение всплывающих окон и блокируем
 * открытие новых вкладок (`--block-new-web-contents`), чтобы запросы не обходили перехват вкладки.
 *
 * @return array<string, mixed>
 */
function buildCheckoutBrowserOptions(): array
{
    return [
        'excludedSwitches' => [BROWSER_DISABLE_POPUP_BLOCKING_SWITCH],
        'customFlags'      => [BROWSER_BLOCK_NEW_CONTENTS_FLAG],
    ];
}

/**
 * Проход сценария в уже запущенном Chrome. Не бросает исключений: ошибка — `outcome = skipped`, причина
 * `scenario_error`. Вкладка закрывается без отключения перехвата (браузер закрывает вызывающий).
 *
 * @param array{url: string, found_by: string, cart: ?array, checkout: ?array} $target из selectCheckoutTarget()
 * @param array<string, mixed>  $rules     из buildCheckoutRules()
 * @param callable(): void|null $onProgress после каждой загрузки страницы (heartbeat)
 * @param callable(string $host): list<string>|null $resolver
 * @return array{outcome: string, reason: ?string, detail: ?string, signal: ?string, steps: list<array>,
 *               requests: array<string, mixed>}
 */
function runCheckoutScenario(
    Browser $browser,
    array $target,
    array $rules,
    string $siteHost,
    ?callable $onProgress = null,
    ?callable $resolver = null
): array {
    $cachedResolver = createCachedBrowserResolver($resolver ?? 'resolveHostIps');
    $run = [
        'stage'             => CHECKOUT_STEP_PRODUCT,
        'requests'          => createCheckoutRequestStats(),
        'events'            => createBrowserEventState(null),
        'recording'         => false,
        'methods'           => [],
        'responses'         => [],
        'ssrf_blocked_main' => false,
    ];
    if (!isBrowserRequestAllowed((string) ($target['url'] ?? ''), $cachedResolver)) {
        logWarning('Сценарий заказа: стартовый адрес заблокирован защитой от SSRF', [
            'url' => sanitizeBrowserLogUrl((string) ($target['url'] ?? '')),
        ]);

        return [
            'outcome'  => CHECKOUT_OUTCOME_SKIPPED,
            'reason'   => CHECKOUT_REASON_PRODUCT_NOT_OPENED,
            'detail'   => BROWSER_SSRF_BLOCKED_ERROR,
            'signal'   => null,
            'steps'    => [],
            'requests' => $run['requests'],
        ];
    }

    $connection = $browser->getConnection();
    $wasStrict = $connection->isStrict();
    // Некоторые сайты передают в CDP-событии Fetch.requestPaused строку, которую библиотека не может декодировать
    // как JSON. Событие уже снято с сокета; пропущенный paused-запрос остаётся приостановленным и не уходит наружу.
    // Строгий режим вернётся перед закрытием отдельного браузера сценария.
    $connection->setStrict(false);
    $result = ['outcome' => CHECKOUT_OUTCOME_SKIPPED, 'reason' => CHECKOUT_REASON_ERROR, 'detail' => null, 'signal' => null,
        'steps' => []];
    $page = null;
    $cleanupInterception = null;

    try {
        $page = $browser->createPage();
        $cleanupInterception = attachCheckoutListeners($page, $run, $rules, $cachedResolver);
        $result = walkCheckoutScenario($page, $run, $target, $rules, $siteHost, $onProgress ?? static function (): void {
        });
    } catch (Throwable $e) {
        logException($e, ['service' => 'checkout', 'action' => 'scenario']);
        $result['outcome'] = CHECKOUT_OUTCOME_SKIPPED;
        $result['reason'] = CHECKOUT_REASON_ERROR;
        $result['detail'] = $e->getMessage() !== '' ? $e->getMessage() : get_class($e);
    } finally {
        // Fetch.disable не вызываем: перехват остаётся активным во время pagehide/unload/sendBeacon до закрытия вкладки.
        if ($page !== null) {
            try {
                $page->close();
            } catch (Throwable) {
                // вкладка закроется вместе с браузером
            }
        }
        if ($cleanupInterception !== null) {
            $cleanupInterception();
        }
        $connection->setStrict($wasStrict);
    }

    return $result + ['requests' => $run['requests']];
}

/**
 * Подписки вкладки: перехват запросов (включается сразу, включая дочерние цели и обход Service Worker —
 * без него сценарий не идёт), диалоги, ошибки консоли, ответы после нажатия «В корзину».
 *
 * @param array<string, mixed>                      $run      состояние сценария (по ссылке)
 * @param callable(string $host): list<string>|null $resolver
 * @return callable(): void функция очистки слушателей и дочерних сессий (без `Fetch.disable`)
 */
function attachCheckoutListeners(Page $page, array &$run, array $rules, ?callable $resolver = null): callable
{
    $effectiveResolver = $resolver ?? createCachedBrowserResolver('resolveHostIps');
    $session = $page->getSession();
    $mainFrameId = $page->getFrameManager()->getMainFrame()->getFrameId();
    $run['events'] = createBrowserEventState($mainFrameId);

    $session->on('method:Page.javascriptDialogOpening', static function (array $params) use ($session): void {
        try {
            // beforeunload — уходим со страницы; alert / confirm / prompt — отмена
            $session->sendMessage(new Message('Page.handleJavaScriptDialog', ['accept' => ($params['type'] ?? '') === 'beforeunload']));
        } catch (Throwable) {
            // вкладка дождётся тайм-аута шага
        }
    });

    foreach (BROWSER_COLLECTED_EVENTS as $method) {
        $session->on('method:' . $method, static function (array $params) use (&$run, $method): void {
            $run['events'] = applyBrowserEvent($run['events'], $method, $params);
            if (!$run['recording']) {
                return;
            }
            if ($method === 'Network.requestWillBeSent') {
                $run['methods'][(string) ($params['requestId'] ?? '')] = strtoupper((string) ($params['request']['method'] ?? 'GET'));
            } elseif ($method === 'Network.responseReceived' && count($run['responses']) < CHECKOUT_RESPONSES_MAX) {
                $run['responses'][] = [
                    'url'    => (string) ($params['response']['url'] ?? ''),
                    'status' => (int) ($params['response']['status'] ?? 0),
                    'type'   => (string) ($params['type'] ?? ''),
                    'method' => $run['methods'][(string) ($params['requestId'] ?? '')] ?? 'GET',
                ];
            }
        });
    }

    $session->sendMessageSync(new Message('Log.enable'), BROWSER_COMMAND_TIMEOUT_MS);
    $page->setDeviceMetricsOverride(buildViewportOverride(CHECKOUT_VIEWPORT_WIDTH))->await(BROWSER_COMMAND_TIMEOUT_MS);

    return attachBrowserRequestInterception(
        $page,
        static function (array $params) use (&$run, $rules, $effectiveResolver, $mainFrameId): bool {
            $request = is_array($params['request'] ?? null) ? $params['request'] : [];
            $method = (string) ($request['method'] ?? 'GET');
            $url = (string) ($request['url'] ?? '');
            $resourceType = (string) ($params['resourceType'] ?? '');
            $frameId = $params['frameId'] ?? null;

            if (!isBrowserRequestAllowed($url, $effectiveResolver)) {
                logWarning('Сценарий заказа: запрос заблокирован защитой от SSRF', [
                    'url'           => sanitizeBrowserLogUrl($url),
                    'resource_type' => $resourceType,
                ]);
                $run['requests'] = recordCheckoutRequest($run['requests'], $method, $url, $run['stage'], true);
                if ($resourceType === CHECKOUT_DOCUMENT_TYPE && $frameId === $mainFrameId) {
                    $run['ssrf_blocked_main'] = true;
                }

                return false;
            }

            $postData = extractCheckoutRequestPostData($request);
            $decision = decideCheckoutRequest(
                $method,
                $url,
                $resourceType,
                $run['stage'],
                $rules['requests'],
                $postData
            );
            $run['stage'] = $decision['stage'];
            $run['requests'] = recordCheckoutRequest($run['requests'], $method, $url, $run['stage'], $decision['block']);

            return !$decision['block'];
        },
        static function () use (&$run): void {
            $run['requests']['errors']++;
        }
    );
}

/**
 * Шаги сценария по порядку; первый сбой завершает проход.
 *
 * @param array<string, mixed> $run состояние сценария (по ссылке)
 * @return array{outcome: string, reason: ?string, detail: ?string, signal: ?string, steps: list<array>}
 */
function walkCheckoutScenario(Page $page, array &$run, array $target, array $rules, string $siteHost, callable $onProgress): array
{
    $steps = [];
    $origin = buildCrawlOrigin($target['url']);

    // 1. Товар
    $opened = openCheckoutStepPage($page, $run, CHECKOUT_STEP_PRODUCT, $target['url'], $target['found_by'], $rules);
    $onProgress();
    $steps[] = $opened['step'];
    if ($opened['probe'] === null) {
        $steps[0]['status'] = CHECKOUT_STATUS_SKIPPED;

        return finishCheckoutWalk($steps, CHECKOUT_OUTCOME_SKIPPED, CHECKOUT_REASON_PRODUCT_NOT_OPENED, $opened['error']);
    }
    $product = $opened['probe'];
    $botProtection = detectCheckoutBotProtection($product);
    if ($botProtection !== null) {
        $steps[0]['status'] = CHECKOUT_STATUS_SKIPPED;

        return finishCheckoutWalk(
            $steps,
            CHECKOUT_OUTCOME_SKIPPED,
            CHECKOUT_REASON_BOT_PROTECTION,
            $botProtection['title']
        );
    }
    $analysis = analyzeCheckoutProductPage($product, $rules);
    if (in_array($analysis['reason'], [CHECKOUT_REASON_VARIANTS, CHECKOUT_REASON_OUT_OF_STOCK], true)) {
        $steps[0]['status'] = CHECKOUT_STATUS_SKIPPED;

        return finishCheckoutWalk($steps, CHECKOUT_OUTCOME_SKIPPED, $analysis['reason']);
    }

    // 2. В корзину
    if ($analysis['button'] === null) {
        $steps[] = makeCheckoutStep(CHECKOUT_STEP_ADD, CHECKOUT_STATUS_FAILED, $product['url'], $target['found_by']);

        return finishCheckoutWalk($steps, CHECKOUT_OUTCOME_FAILED, $analysis['reason']);
    }
    $run['stage'] = raiseCheckoutStage($run['stage'], CHECKOUT_STEP_ADD);
    $added = clickAddToCart($page, $run, $product, $analysis['button'], $rules);
    $onProgress();
    $signal = $added['signal'];
    $steps[] = array_merge(
        makeCheckoutStep(CHECKOUT_STEP_ADD, CHECKOUT_STATUS_DONE, $added['probe']['url'] ?: $product['url'], $target['found_by'], [
            'opened' => true,
            'note'   => $signal === null ? CHECKOUT_NOTE_NO_SIGNAL : null,
        ]),
        takeCheckoutStepEvidence($page, $run)
    );

    // 3. Корзина
    $run['stage'] = raiseCheckoutStage($run['stage'], CHECKOUT_STEP_CART);
    $cart = openCheckoutCart($page, $run, $target, $added['probe'], $rules, $origin, $siteHost, $onProgress);
    if ($cart['step'] !== null) {
        $steps[] = $cart['step'];
    }
    if ($cart['state'] === CHECKOUT_CART_NOT_CART) {
        return finishCheckoutWalk($steps, CHECKOUT_OUTCOME_FAILED, CHECKOUT_REASON_CART_NOT_FOUND, $cart['error'], $signal);
    }
    if ($cart['state'] === CHECKOUT_CART_EMPTY) {
        $steps[count($steps) - 1]['status'] = CHECKOUT_STATUS_FAILED;
        if ($signal === null) {
            $steps[count($steps) - 2]['status'] = CHECKOUT_STATUS_FAILED;

            return finishCheckoutWalk($steps, CHECKOUT_OUTCOME_FAILED, CHECKOUT_REASON_NOT_ADDED, null, $signal);
        }

        return finishCheckoutWalk($steps, CHECKOUT_OUTCOME_FAILED, CHECKOUT_REASON_CART_EMPTY, null, $signal);
    }

    // 4. Оформление: с этого момента блокируются все изменяющие запросы
    $run['stage'] = raiseCheckoutStage($run['stage'], CHECKOUT_STEP_CHECKOUT);
    $checkout = openCheckoutForm($page, $run, $target, $cart['probe'], $rules, $origin, $siteHost, $onProgress);
    if ($checkout['step'] !== null) {
        $steps[] = $checkout['step'];
    }
    if ($checkout['found']) {
        return finishCheckoutWalk($steps, CHECKOUT_OUTCOME_REACHED, null, null, $signal);
    }

    return finishCheckoutWalk(
        $steps,
        CHECKOUT_OUTCOME_FAILED,
        $checkout['opened'] ? CHECKOUT_REASON_NO_FORM : CHECKOUT_REASON_CHECKOUT_NOT_FOUND,
        $checkout['error'],
        $signal
    );
}

/**
 * Открывает страницу шага, читает её, снимает и забирает ошибки консоли. Не открылась — `probe = null`.
 *
 * @param array<string, mixed> $run
 * @return array{step: array, probe: ?array, error: ?string}
 */
function openCheckoutStepPage(Page $page, array &$run, string $code, string $url, string $foundBy, array $rules): array
{
    $run['events'] = createBrowserEventState($run['events']['main_frame_id']);
    $run['ssrf_blocked_main'] = false;
    $navigation = navigateBrowserPage(
        $page,
        $url,
        CHECKOUT_STEP_TIMEOUT_MS,
        static function () use (&$run): bool {
            return !empty($run['ssrf_blocked_main']);
        }
    );
    $step = makeCheckoutStep($code, CHECKOUT_STATUS_DONE, $url, $foundBy, ['dom_ms' => $navigation['dom_ms'] ?? null]);

    if (!empty($run['ssrf_blocked_main'])) {
        $step['status'] = CHECKOUT_STATUS_FAILED;

        return ['step' => array_merge($step, takeCheckoutConsole($run)), 'probe' => null, 'error' => BROWSER_SSRF_BLOCKED_ERROR];
    }

    if ($navigation['status'] !== BROWSER_PAGE_OPENED) {
        $step['status'] = CHECKOUT_STATUS_FAILED;

        return ['step' => array_merge($step, takeCheckoutConsole($run)), 'probe' => null, 'error' => $navigation['error'] ?? null];
    }

    $probe = probeCheckoutPage($page, $rules);
    if ($probe === null) {
        $step['status'] = CHECKOUT_STATUS_FAILED;

        return ['step' => array_merge($step, takeCheckoutConsole($run)), 'probe' => null, 'error' => CHECKOUT_PROBE_FAILED];
    }
    $step['url'] = $probe['url'] !== '' ? $probe['url'] : $url;
    $step['opened'] = true;

    return ['step' => array_merge($step, takeCheckoutStepEvidence($page, $run)), 'probe' => $probe, 'error' => null];
}

/**
 * Нажимает выбранную кнопку и ждёт сигнал: первая проверка через CHECKOUT_ADD_FIRST_CHECK_MS, вторая — к
 * CHECKOUT_ADD_WAIT_MS. Если страница ушла на другой адрес — ждём её загрузку.
 *
 * @param array<string, mixed> $run
 * @return array{probe: array, signal: ?string}
 */
function clickAddToCart(Page $page, array &$run, array $before, array $button, array $rules): array
{
    $run['events'] = createBrowserEventState($run['events']['main_frame_id']);
    $run['responses'] = [];
    $run['methods'] = [];
    $run['recording'] = true;

    try {
        $page->evaluate(sprintf(CHECKOUT_CLICK_SCRIPT, $button['index']))->getReturnValue(CHECKOUT_EVAL_TIMEOUT_MS);
    } catch (Throwable) {
        // нажатие увело страницу до ответа скрипта — проверим по сигналу
    }

    $after = $before;
    $signal = null;
    foreach ([CHECKOUT_ADD_FIRST_CHECK_MS, CHECKOUT_ADD_WAIT_MS - CHECKOUT_ADD_FIRST_CHECK_MS] as $waitMs) {
        pumpCheckoutEvents($page, $waitMs);
        waitCheckoutPageReady($page, CHECKOUT_STEP_TIMEOUT_MS);
        $after = probeCheckoutPage($page, $rules) ?? $after;
        $signal = detectAddToCartSignal($before, $after, $run['responses'], $rules);
        if ($signal !== null) {
            break;
        }
    }
    $run['recording'] = false;

    return ['probe' => $after, 'signal' => $signal];
}

/**
 * Корзина: адреса по порядку (не больше CHECKOUT_MAX_URL_ATTEMPTS), первый, похожий на корзину. У платформ со
 * всплывающей корзиной и без адресов — текущая страница.
 *
 * @param array<string, mixed> $run
 * @return array{state: string, step: ?array, probe: ?array, error: ?string}
 */
function openCheckoutCart(
    Page $page,
    array &$run,
    array $target,
    array $current,
    array $rules,
    string $origin,
    string $siteHost,
    callable $onProgress
): array {
    $candidates = buildCheckoutUrlCandidates(CHECKOUT_STEP_CART, $target['cart'], $current['links'], $rules, $origin, $siteHost);
    if ($candidates === [] && $rules['inline_cart']) {
        $state = classifyCheckoutCart($current, $rules);
        $step = array_merge(makeCheckoutStep(CHECKOUT_STEP_CART, CHECKOUT_STATUS_DONE, $current['url'], CRAWL_FOUND_BY_PATTERN, [
            'opened' => true, 'note' => CHECKOUT_NOTE_INLINE,
        ]), takeCheckoutStepEvidence($page, $run));

        return ['state' => $state === CHECKOUT_CART_NOT_CART ? CHECKOUT_CART_UNKNOWN : $state, 'step' => $step,
            'probe' => $current, 'error' => null];
    }

    $last = ['state' => CHECKOUT_CART_NOT_CART, 'step' => null, 'probe' => null, 'error' => CHECKOUT_NO_CART_URL];
    foreach (array_slice($candidates, 0, CHECKOUT_MAX_URL_ATTEMPTS) as $candidate) {
        $opened = openCheckoutStepPage($page, $run, CHECKOUT_STEP_CART, $candidate['url'], $candidate['found_by'], $rules);
        $onProgress();
        if ($opened['probe'] === null) {
            if ($last['step'] === null) {
                $last = ['state' => CHECKOUT_CART_NOT_CART, 'step' => $opened['step'], 'probe' => null, 'error' => $opened['error']];
            }
            continue;
        }

        $state = classifyCheckoutCart($opened['probe'], $rules);
        $step = $opened['step'];
        if ($state === CHECKOUT_CART_NOT_CART) {
            $step['status'] = CHECKOUT_STATUS_FAILED;
            // Если ни один адрес не окажется корзиной, доказательством остаётся первый (самый приоритетный) адрес.
            if ($last['probe'] === null) {
                $last = ['state' => $state, 'step' => $step, 'probe' => $opened['probe'], 'error' => CHECKOUT_NOT_CART_PAGE];
            }
            continue;
        }
        if ($state === CHECKOUT_CART_UNKNOWN) {
            $step['note'] = CHECKOUT_NOTE_ITEMS_UNKNOWN;
        }

        return ['state' => $state, 'step' => $step, 'probe' => $opened['probe'], 'error' => null];
    }

    return $last;
}

/**
 * Оформление: адреса по порядку (не больше CHECKOUT_MAX_URL_ATTEMPTS), первый с формой. Переход — по адресу,
 * ничего не нажимается. У всплывающей корзины без адресов — форма на текущей странице.
 *
 * @param array<string, mixed> $run
 * @return array{found: bool, opened: bool, step: ?array, error: ?string}
 */
function openCheckoutForm(
    Page $page,
    array &$run,
    array $target,
    array $cart,
    array $rules,
    string $origin,
    string $siteHost,
    callable $onProgress
): array {
    $candidates = buildCheckoutUrlCandidates(CHECKOUT_STEP_CHECKOUT, $target['checkout'], $cart['links'], $rules, $origin, $siteHost);
    // Форма прямо на странице корзины (Мегагрупп и др.) или всплывающая корзина без адресов — оформление здесь же
    $onCartPage = hasCheckoutForm($cart);
    if ($onCartPage || ($candidates === [] && $rules['inline_cart'])) {
        $step = array_merge(makeCheckoutStep(
            CHECKOUT_STEP_CHECKOUT,
            $onCartPage ? CHECKOUT_STATUS_DONE : CHECKOUT_STATUS_FAILED,
            $cart['url'],
            CRAWL_FOUND_BY_LINKS,
            ['opened' => true, 'note' => $onCartPage ? CHECKOUT_NOTE_FORM_IN_CART : CHECKOUT_NOTE_INLINE]
        ), takeCheckoutStepEvidence($page, $run));

        return ['found' => $onCartPage, 'opened' => true, 'step' => $step, 'error' => null];
    }

    $last = ['found' => false, 'opened' => false, 'step' => null, 'error' => CHECKOUT_NO_CHECKOUT_URL];
    foreach (array_slice($candidates, 0, CHECKOUT_MAX_URL_ATTEMPTS) as $candidate) {
        $opened = openCheckoutStepPage($page, $run, CHECKOUT_STEP_CHECKOUT, $candidate['url'], $candidate['found_by'], $rules);
        $onProgress();
        if ($opened['probe'] === null) {
            $last = ['found' => false, 'opened' => $last['opened'], 'step' => $last['step'] ?? $opened['step'],
                'error' => $opened['error']];
            continue;
        }
        if (hasCheckoutForm($opened['probe'])) {
            return ['found' => true, 'opened' => true, 'step' => $opened['step'], 'error' => null];
        }

        $step = $opened['step'];
        $step['status'] = CHECKOUT_STATUS_FAILED;
        // Адреса упорядочены по доверию: обход → ссылки страницы → шаблоны. Для находки сохраняем первый открытый.
        if (!$last['opened']) {
            $last = ['found' => false, 'opened' => true, 'step' => $step, 'error' => null];
        }
    }

    return $last;
}

/**
 * Читает страницу скриптом. Ошибка (страница в процессе перехода и т. п.) — null.
 */
function probeCheckoutPage(Page $page, array $rules): ?array
{
    try {
        $raw = $page->evaluate(buildCheckoutProbeScript($rules))->getReturnValue(CHECKOUT_EVAL_TIMEOUT_MS);
    } catch (Throwable) {
        return null;
    }

    return is_array($raw) ? normalizeCheckoutProbe($raw) : null;
}

/**
 * Ждёт `document.readyState = complete` после перехода, начатого скриптом сайта, не дольше `$timeoutMs`.
 */
function waitCheckoutPageReady(Page $page, int $timeoutMs): void
{
    $started = hrtime(true);
    while (intdiv(hrtime(true) - $started, 1000000) < $timeoutMs) {
        try {
            if ($page->evaluate('document.readyState')->getReturnValue(CHECKOUT_EVAL_TIMEOUT_MS) === 'complete') {
                return;
            }
        } catch (Throwable) {
            // контекст страницы пересоздаётся при переходе
        }
        pumpCheckoutEvents($page, intdiv(CHECKOUT_PUMP_INTERVAL_US, 1000));
    }
}

/**
 * Читает события DevTools `$ms` миллисекунд: перехват и ошибки консоли обрабатываются только при чтении.
 */
function pumpCheckoutEvents(Page $page, int $ms): void
{
    $started = hrtime(true);
    $connection = $page->getSession()->getConnection();
    while (intdiv(hrtime(true) - $started, 1000000) < $ms) {
        $connection->readData();
        usleep(CHECKOUT_PUMP_INTERVAL_US);
    }
}

/**
 * Снимок шага (ошибка снимка — `screenshot_error`, шаг продолжается) и его ошибки консоли.
 *
 * @param array<string, mixed> $run
 * @return array<string, mixed>
 */
function takeCheckoutStepEvidence(Page $page, array &$run): array
{
    $evidence = [];
    try {
        $evidence['screenshot'] = captureBrowserScreenshots($page, CHECKOUT_VIEWPORT_WIDTH);
    } catch (Throwable $e) {
        $evidence['screenshot_error'] = $e->getMessage() !== '' ? $e->getMessage() : get_class($e);
    }

    return $evidence + takeCheckoutConsole($run);
}

/**
 * Ошибки консоли с начала шага; состояние сбрасывается для следующего шага.
 *
 * @param array<string, mixed> $run
 * @return array{console: list<string>, console_errors: int}
 */
function takeCheckoutConsole(array &$run): array
{
    $console = summarizeCheckoutConsole($run['events']['events'], $run['events']['dropped']);
    $run['events'] = createBrowserEventState($run['events']['main_frame_id']);

    return ['console' => $console['sample'], 'console_errors' => $console['count']];
}
