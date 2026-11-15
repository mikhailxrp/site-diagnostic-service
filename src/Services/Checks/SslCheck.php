<?php

declare(strict_types=1);

/**
 * SSL-сертификат сайта: получение через `stream_socket_client` (на заранее проверенный публичный IP)
 * и оценка без сети — срок, домены, доверие к цепочке. Ошибки не бросаются, а возвращаются в `error`.
 *
 * Доверие к цепочке определяет вызывающий код по https-запросу curl (он проверяет цепочку по CA-файлу):
 * второе TLS-соединение «с проверкой» оказалось ненадёжным — сайты обрывают частые повторные соединения.
 */

require_once ROOT_PATH . '/src/Services/HttpClient.php';
require_once ROOT_PATH . '/src/Services/FindingFactory.php';

const SSL_DEFAULT_PORT            = 443;
const SSL_CONNECT_TIMEOUT_SECONDS = 10;
const SSL_EXPIRY_WARNING_DAYS     = 14;
const SECONDS_IN_DAY              = 86400;
const SSL_DATE_FORMAT             = 'Y-m-d H:i:s';
const SSL_SAN_DNS_PREFIX          = 'DNS:';
const SSL_WILDCARD_PREFIX         = '*.';
const SSL_CONNECT_ATTEMPTS        = 2; // соединение иногда обрывается сразу — одна повторная попытка

/**
 * Сертификат хоста целиком: получение + оценка.
 *
 * @param bool $trusted цепочка прошла проверку curl (см. описание файла)
 * @return array{facts: array<string, mixed>, findings: list<array>}
 */
function inspectSslCertificate(string $host, bool $trusted, int $port = SSL_DEFAULT_PORT): array
{
    $fetched = fetchPeerCertificate($host, $port);

    if ($fetched['certificate'] === null) {
        return ['facts' => ['host' => $host, 'error' => $fetched['error']], 'findings' => []];
    }

    return evaluateCertificate($fetched['certificate'], $host, $trusted, time());
}

/**
 * Получает сертификат без проверки цепочки — чтобы получить и просроченный, и самоподписанный.
 *
 * @param callable(string $host): list<string>|null $resolver  DNS; null — настоящий
 * @param callable(array $target): array|null       $connector TLS-соединение; null — настоящее
 * @return array{certificate: ?array, error: ?string}
 */
function fetchPeerCertificate(
    string $host,
    int $port = SSL_DEFAULT_PORT,
    ?callable $resolver = null,
    ?callable $connector = null
): array {
    $resolver ??= 'resolveHostIps';
    $connector ??= 'connectTls';

    $target = prepareRequestTarget("https://{$host}:{$port}/", $resolver);
    if (isset($target['error'])) {
        return ['certificate' => null, 'error' => $target['error']];
    }

    $result = ['certificate' => null, 'error' => 'Сертификат не получен.'];
    for ($attempt = 1; $attempt <= SSL_CONNECT_ATTEMPTS; $attempt++) {
        $result = $connector($target) + ['certificate' => null, 'error' => 'Сертификат не получен.'];
        if ($result['certificate'] !== null) {
            return ['certificate' => $result['certificate'], 'error' => null];
        }
    }

    return ['certificate' => null, 'error' => (string) $result['error']];
}

/**
 * Одно TLS-соединение без проверки цепочки. Предупреждения PHP перехватываются и становятся текстом ошибки.
 *
 * @param array{host: string, port: int, ip: string} $target
 * @return array{certificate: ?array, error: ?string} certificate — результат `openssl_x509_parse`
 */
function connectTls(array $target): array
{
    $context = stream_context_create(['ssl' => [
        'peer_name'         => $target['host'],
        'SNI_enabled'       => true,
        'capture_peer_cert' => true,
        'verify_peer'       => false,
        'verify_peer_name'  => false,
        'allow_self_signed' => true,
    ]]);

    $ip = filter_var($target['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
        ? '[' . $target['ip'] . ']'
        : $target['ip'];

    $warning = null;
    set_error_handler(static function (int $level, string $message) use (&$warning): bool {
        $warning = $message;

        return true;
    });

    try {
        $errno = 0;
        $errstr = '';
        $client = stream_socket_client(
            "ssl://{$ip}:{$target['port']}",
            $errno,
            $errstr,
            SSL_CONNECT_TIMEOUT_SECONDS,
            STREAM_CLIENT_CONNECT,
            $context
        );
    } finally {
        restore_error_handler();
    }

    if ($client === false) {
        $error = $errstr !== '' ? $errstr : ($warning ?? 'Не удалось установить TLS-соединение.');

        return ['certificate' => null, 'error' => $error];
    }

    $params = stream_context_get_params($client);
    fclose($client);

    $certificate = $params['options']['ssl']['peer_certificate'] ?? null;
    $parsed = $certificate !== null ? openssl_x509_parse($certificate) : false;

    return $parsed === false
        ? ['certificate' => null, 'error' => 'Сервер не прислал сертификат.']
        : ['certificate' => $parsed, 'error' => null];
}

/**
 * Оценка сертификата без сети.
 *
 * @param array<string, mixed> $parsed результат `openssl_x509_parse`
 * @return array{facts: array<string, mixed>, findings: list<array>}
 */
function evaluateCertificate(array $parsed, string $host, bool $trusted, int $now): array
{
    $validFrom = (int) ($parsed['validFrom_time_t'] ?? 0);
    $validTo = (int) ($parsed['validTo_time_t'] ?? 0);
    $daysLeft = (int) floor(($validTo - $now) / SECONDS_IN_DAY);
    $isExpired = $validTo < $now;

    $altNames = extractCertificateAltNames($parsed);
    $commonName = extractCertificateField($parsed['subject'] ?? [], 'CN');
    $domains = array_values(array_unique(array_filter([...$altNames, $commonName])));
    $domainMatch = certificateMatchesHost($altNames !== [] ? $altNames : array_filter([$commonName]), $host);

    $issuer = extractCertificateField($parsed['issuer'] ?? [], 'O')
        ?? extractCertificateField($parsed['issuer'] ?? [], 'CN');

    $location = "https://{$host}/";
    $validToText = gmdate(SSL_DATE_FORMAT, $validTo);
    $evidence = ['valid_to' => $validToText, 'domains' => $domains, 'issuer' => $issuer];
    $findings = [];

    if ($isExpired) {
        $findings[] = makeFinding(
            'ssl',
            'SSL-сертификат истёк',
            $location,
            'expired',
            "Срок действия сертификата закончился {$validToText} (UTC). Браузеры показывают предупреждение.",
            $evidence,
            null,
            'critical'
        );
    } elseif ($daysLeft < SSL_EXPIRY_WARNING_DAYS) {
        $findings[] = makeFinding(
            'ssl',
            "SSL-сертификат истекает через {$daysLeft} дн.",
            $location,
            'expiring',
            "Срок действия сертификата заканчивается {$validToText} (UTC).",
            $evidence,
            null,
            'medium'
        );
    }

    if (!$domainMatch) {
        $findings[] = makeFinding(
            'ssl',
            'SSL-сертификат выдан на другой домен',
            $location,
            'domain_mismatch',
            "Сертификат не покрывает {$host}. Домены в сертификате: " . implode(', ', $domains) . '.',
            $evidence,
            null,
            'high'
        );
    }

    // Просроченный или выданный на другой домен сертификат и так не проходит проверку — вторая находка не нужна
    if (!$trusted && !$isExpired && $domainMatch) {
        $findings[] = makeFinding(
            'ssl',
            'Цепочка SSL-сертификата не доверена',
            $location,
            'untrusted',
            'Сертификат самоподписанный, выдан неизвестным центром или на сервере не хватает промежуточного сертификата.',
            $evidence,
            null,
            'high'
        );
    }

    return [
        'facts' => [
            'host'         => $host,
            'error'        => null,
            'valid_from'   => gmdate(SSL_DATE_FORMAT, $validFrom),
            'valid_to'     => $validToText,
            'days_left'    => $daysLeft,
            'issuer'       => $issuer,
            'domains'      => $domains,
            'domain_match' => $domainMatch,
            'trusted'      => $trusted,
        ],
        'findings' => $findings,
    ];
}

/**
 * DNS-имена из subjectAltName: `DNS:a.ru, DNS:*.a.ru, IP Address:…` → `['a.ru', '*.a.ru']`.
 *
 * @return list<string>
 */
function extractCertificateAltNames(array $parsed): array
{
    $raw = (string) ($parsed['extensions']['subjectAltName'] ?? '');
    $names = [];

    foreach (explode(',', $raw) as $entry) {
        $entry = trim($entry);
        if (str_starts_with($entry, SSL_SAN_DNS_PREFIX)) {
            $names[] = strtolower(trim(substr($entry, strlen(SSL_SAN_DNS_PREFIX))));
        }
    }

    return array_values(array_filter($names));
}

/**
 * Поле subject/issuer; если полей несколько (массив) — первое.
 */
function extractCertificateField(array $name, string $field): ?string
{
    $value = $name[$field] ?? null;
    if (is_array($value)) {
        $value = $value[0] ?? null;
    }

    return is_string($value) && $value !== '' ? $value : null;
}

/**
 * Покрывает ли сертификат хост. `*.a.ru` подходит к `b.a.ru`, но не к `a.ru` и не к `c.b.a.ru`.
 *
 * @param list<string> $patterns
 */
function certificateMatchesHost(array $patterns, string $host): bool
{
    $host = strtolower($host);

    foreach ($patterns as $pattern) {
        $pattern = strtolower($pattern);

        if ($pattern === $host) {
            return true;
        }

        if (str_starts_with($pattern, SSL_WILDCARD_PREFIX)) {
            $suffix = substr($pattern, 1); // `.a.ru`
            $label = substr($host, 0, -strlen($suffix));
            if (str_ends_with($host, $suffix) && $label !== '' && !str_contains($label, '.')) {
                return true;
            }
        }
    }

    return false;
}
