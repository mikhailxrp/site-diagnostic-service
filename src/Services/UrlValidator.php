<?php

declare(strict_types=1);

/**
 * Проверка формы новой диагностики без БД: нормализация ссылки, запрет локальных и частных адресов,
 * описание проблемы. DNS передаётся параметром (`$resolver`), чтобы тесты не ходили в сеть.
 */

const SITE_URL_MAX_LENGTH     = 2048; // как diagnostics.url VARCHAR(2048)
const PROBLEM_TEXT_MAX_LENGTH = 5000;

const DEFAULT_URL_SCHEME  = 'https';
const ALLOWED_URL_SCHEMES = ['http', 'https'];
const LOCAL_HOST_NAMES    = ['localhost'];
const LOCAL_HOST_SUFFIX   = '.localhost';
const WWW_PREFIX          = 'www.';

/** Поля формы: имя поля → значение по умолчанию */
const DIAGNOSTIC_FORM_FIELDS = ['url' => '', 'product_url' => '', 'problem_text' => ''];

/** Путь главной: такой «адрес товара» не принимается */
const HOME_PAGE_PATH = '/';

/**
 * Приводит ссылку к виду `https://host[:port]/path[?query]`: схема по умолчанию https,
 * хост в нижнем регистре и punycode, без фрагмента, пустой путь → `/`.
 *
 * @return array{url: string, host: string}|array{error: string} host — без `[]` для IPv6
 */
function normalizeSiteUrl(string $raw): array
{
    $raw = trim($raw);

    if ($raw === '') {
        return ['error' => 'Укажите ссылку на сайт.'];
    }
    if (preg_match('/[\s\x00-\x1F\x7F]/u', $raw) === 1) {
        return ['error' => 'В ссылке не должно быть пробелов и служебных символов.'];
    }

    $withScheme = addDefaultScheme($raw);
    if ($withScheme === null) {
        return ['error' => 'Поддерживаются только ссылки http:// и https://.'];
    }

    $parts = parse_url($withScheme);
    if ($parts === false || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
        return ['error' => 'Не получается разобрать ссылку. Пример: shop.ru или https://shop.ru/catalog.'];
    }

    $scheme = strtolower($parts['scheme']);
    if (!in_array($scheme, ALLOWED_URL_SCHEMES, true)) {
        return ['error' => 'Поддерживаются только ссылки http:// и https://.'];
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        return ['error' => 'Ссылка не должна содержать логин и пароль.'];
    }

    $host = normalizeHostName($parts['host']);
    if ($host === null) {
        return ['error' => 'Некорректное имя сайта. Пример: shop.ru.'];
    }

    $urlHost = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? '[' . $host . ']' : $host;
    $url = $scheme . '://' . $urlHost
        . (isset($parts['port']) ? ':' . $parts['port'] : '')
        . (isset($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/')
        . (isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '');

    if (strlen($url) > SITE_URL_MAX_LENGTH) {
        return ['error' => 'Ссылка слишком длинная: не больше ' . SITE_URL_MAX_LENGTH . ' символов.'];
    }

    return ['url' => $url, 'host' => $host];
}

/**
 * `shop.ru` и `//shop.ru` → `https://…`; `shop.ru:8080` — это порт, а не схема.
 * null — указана другая схема (`ftp:`, `mailto:`, `javascript:` …).
 */
function addDefaultScheme(string $raw): ?string
{
    if (str_contains($raw, '://')) {
        return $raw;
    }
    if (str_starts_with($raw, '//')) {
        return DEFAULT_URL_SCHEME . ':' . $raw;
    }
    if (preg_match('/^[a-z][a-z0-9+.\-]*:(?![0-9]+(?:[\/?#]|$))/i', $raw) === 1) {
        return null;
    }

    return DEFAULT_URL_SCHEME . '://' . $raw;
}

/**
 * Нижний регистр, кириллица → punycode, IPv6 без скобок. null — имя некорректно.
 */
function normalizeHostName(string $host): ?string
{
    if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
        $ip = substr($host, 1, -1);

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? strtolower($ip) : null;
    }

    $host = rtrim(mb_strtolower($host, 'UTF-8'), '.');
    if ($host === '') {
        return null;
    }

    $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
    if ($ascii === false || $ascii === '') {
        return null;
    }
    if (filter_var($ascii, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
        return null;
    }

    return $ascii;
}

/**
 * Хост без `www.` — для `diagnostics.host` и связи с прошлыми диагностиками.
 */
function extractBaseHost(string $host): string
{
    $host = strtolower($host);

    return str_starts_with($host, WWW_PREFIX) && strlen($host) > strlen(WWW_PREFIX)
        ? substr($host, strlen(WWW_PREFIX))
        : $host;
}

/**
 * true — адрес из интернета: не частный, не служебный (loopback, link-local, CGNAT, документация …).
 */
function isPublicIp(string $ip): bool
{
    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE
    ) !== false;
}

/**
 * Проверяет, что хост — публичный сайт. null — всё в порядке, иначе текст ошибки.
 *
 * @param callable(string $host): list<string> $resolver IP-адреса хоста (пусто — не резолвится)
 */
function validateSiteHost(string $host, callable $resolver): ?string
{
    if (in_array($host, LOCAL_HOST_NAMES, true) || str_ends_with($host, LOCAL_HOST_SUFFIX)) {
        return 'Локальные адреса проверять нельзя.';
    }

    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return isPublicIp($host) ? null : 'Адреса локальной сети и служебные IP проверять нельзя.';
    }

    if (!str_contains($host, '.')) {
        return 'Укажите домен целиком, например shop.ru.';
    }

    $ips = $resolver($host);
    if ($ips === []) {
        return 'Сайт не найден: у домена нет IP-адреса. Проверьте, нет ли опечатки.';
    }

    foreach ($ips as $ip) {
        if (!isPublicIp($ip)) {
            return 'Домен указывает на адрес локальной сети — такой сайт проверять нельзя.';
        }
    }

    return null;
}

/**
 * Настоящий DNS: IPv4 и IPv6 (AAAA). Без предупреждений PHP, если домена нет или AAAA временно недоступен.
 *
 * @param callable(string $host): list<string>|null $ipv4Lookup null — `gethostbynamel()`
 * @param callable(string $host): array|false|null  $aaaaLookup null — `dns_get_record(..., DNS_AAAA)`
 * @return list<string>
 */
function resolveHostIps(string $host, ?callable $ipv4Lookup = null, ?callable $aaaaLookup = null): array
{
    $ips = $ipv4Lookup !== null ? $ipv4Lookup($host) : (gethostbynamel($host) ?: []);

    set_error_handler(static fn (): bool => true);
    try {
        $records = ($aaaaLookup !== null ? $aaaaLookup($host) : dns_get_record($host, DNS_AAAA)) ?: [];
    } catch (Throwable) {
        // временный сбой AAAA не отменяет проверку по A-записям
        $records = [];
    } finally {
        restore_error_handler();
    }

    foreach ($records as $record) {
        if (isset($record['ipv6']) && is_string($record['ipv6'])) {
            $ips[] = $record['ipv6'];
        }
    }

    return array_values(array_unique($ips));
}

/**
 * Значения полей формы как строки (для повторного показа формы).
 *
 * @param array<string, mixed> $input
 * @return array{url: string, product_url: string, problem_text: string}
 */
function readDiagnosticFormValues(array $input): array
{
    $values = [];
    foreach (DIAGNOSTIC_FORM_FIELDS as $field => $default) {
        $value = $input[$field] ?? $default;
        $values[$field] = is_string($value) ? trim($value) : $default;
    }

    return $values;
}

/**
 * Необязательный адрес товара: формат как у ссылки на сайт, тот же сайт (хост без `www.`), не главная.
 * Если хост сайта неизвестен (ссылка на сайт с ошибкой) — проверяется только формат.
 *
 * @return array{url: ?string, error: ?string} url — null, если поле пустое или с ошибкой
 */
function validateProductUrl(string $raw, ?string $siteHost): array
{
    if (trim($raw) === '') {
        return ['url' => null, 'error' => null];
    }

    $normalized = normalizeSiteUrl($raw);
    if (isset($normalized['error'])) {
        return ['url' => null, 'error' => $normalized['error']];
    }

    if ($siteHost !== null && extractBaseHost($normalized['host']) !== $siteHost) {
        return ['url' => null, 'error' => 'Адрес товара должен быть с того же сайта: ' . $siteHost . '.'];
    }

    $parts = parse_url($normalized['url']);
    if (($parts['path'] ?? HOME_PAGE_PATH) === HOME_PAGE_PATH && !isset($parts['query'])) {
        return ['url' => null, 'error' => 'Укажите страницу товара, а не главную.'];
    }

    return ['url' => $normalized['url'], 'error' => null];
}

/**
 * Проверка формы новой диагностики.
 *
 * @param array<string, mixed> $input
 * @param callable(string $host): list<string> $resolver
 * @return array{
 *     values: array{url: string, product_url: string, problem_text: string},
 *     errors: array<string, string>,
 *     url: ?string,
 *     host: ?string,
 *     productUrl: ?string,
 *     problemText: ?string
 * } host — без `www.`; productUrl и problemText — null, если поле пустое
 */
function validateDiagnosticInput(array $input, callable $resolver): array
{
    $values = readDiagnosticFormValues($input);
    $errors = [];
    $url = null;
    $host = null;

    $normalized = normalizeSiteUrl($values['url']);
    if (isset($normalized['error'])) {
        $errors['url'] = $normalized['error'];
    } else {
        $hostError = validateSiteHost($normalized['host'], $resolver);
        if ($hostError !== null) {
            $errors['url'] = $hostError;
        } else {
            $url = $normalized['url'];
            $host = extractBaseHost($normalized['host']);
        }
    }

    $product = validateProductUrl($values['product_url'], $host);
    if ($product['error'] !== null) {
        $errors['product_url'] = $product['error'];
    }

    $problemText = $values['problem_text'] === '' ? null : $values['problem_text'];
    if ($problemText !== null) {
        if (!mb_check_encoding($problemText, 'UTF-8')) {
            $errors['problem_text'] = 'В описании есть недопустимые символы.';
        } elseif (mb_strlen($problemText, 'UTF-8') > PROBLEM_TEXT_MAX_LENGTH) {
            $errors['problem_text'] = 'Описание слишком длинное: не больше ' . PROBLEM_TEXT_MAX_LENGTH . ' символов.';
        }
    }

    if ($errors !== []) {
        return [
            'values'      => $values,
            'errors'      => $errors,
            'url'         => null,
            'host'        => null,
            'productUrl'  => null,
            'problemText' => null,
        ];
    }

    return [
        'values'      => $values,
        'errors'      => [],
        'url'         => $url,
        'host'        => $host,
        'productUrl'  => $product['url'],
        'problemText' => $problemText,
    ];
}
