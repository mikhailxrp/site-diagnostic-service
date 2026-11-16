<?php

declare(strict_types=1);

/**
 * NVD (National Vulnerability Database) без БД: уязвимости ядра CMS по CPE + версии
 * (`virtualMatchString` — NVD сам сверяет версию с диапазонами), разбор ответа в записи формата
 * `VulnerabilityService.php`. Без API-ключа: лимит 5 запросов / 30 с, у сайта одна CMS — хватает.
 *
 * Шаблон CPE — `nvd_cpe` в `data/version-sources.php`: `{version}` — версия без патча, `{update}` — патч Magento
 * (`2.4.6-p3` → `2.4.6` и `p3`; без патча — `-`, «без обновления»).
 */

require_once ROOT_PATH . '/src/Services/VulnerabilityService.php';

const NVD_API_URL      = 'https://services.nvd.nist.gov/rest/json/cves/2.0?virtualMatchString=%s';
const NVD_DETAIL_URL   = 'https://nvd.nist.gov/vuln/detail/%s';
const NVD_SOURCE_TITLE = 'NVD';
const NVD_HTTP_OK      = 200;

/** Порядок метрик CVSS: новая версия важнее, внутри версии — оценка NVD (Primary) важнее остальных */
const NVD_CVSS_METRICS = ['cvssMetricV40', 'cvssMetricV31', 'cvssMetricV30', 'cvssMetricV2'];

/** Части CPE 2.3: `cpe:2.3:part:vendor:product:version:update:…` */
const NVD_CPE_VENDOR  = 3;
const NVD_CPE_PRODUCT = 4;
const NVD_CPE_VERSION = 5;
const NVD_CPE_UPDATE  = 6;

/** Пустые значения части CPE: `*` — любое, `-` — нет */
const NVD_CPE_EMPTY = ['*', '-', ''];

/**
 * @return string CPE для запроса: `cpe:2.3:a:adobe:magento:2.4.6:p3:*:*:open_source`
 */
function buildNvdCpe(string $template, string $version): string
{
    [$base, $update] = array_pad(explode('-', $version, 2), 2, null);

    return strtr($template, ['{version}' => $base, '{update}' => $update ?? '-']);
}

function buildNvdUrl(string $cpe): string
{
    return sprintf(NVD_API_URL, rawurlencode($cpe));
}

/**
 * Уязвимости версии по всем шаблонам CPE (у Magento их два: до и после покупки Adobe). Любой сбой — исключение:
 * неполный ответ NVD в кэш не попадает.
 *
 * @param list<string>                                              $templates
 * @param callable(string $url, ?array $json): array{status: ?int, body: string, error: ?string} $api
 * @return list<array<string, mixed>>
 */
function fetchNvdVulnerabilities(array $templates, string $version, callable $api): array
{
    $records = [];
    foreach ($templates as $template) {
        $response = $api(buildNvdUrl(buildNvdCpe($template, $version)), null);
        if (($response['error'] ?? null) !== null) {
            throw new RuntimeException('NVD: ' . $response['error']);
        }
        if (($response['status'] ?? null) !== NVD_HTTP_OK) {
            throw new RuntimeException('NVD ответил ' . ($response['status'] ?? 'без статуса'));
        }
        $records = [...$records, ...parseNvdResponse((string) $response['body'], $templates)];
    }

    return mergeVulnerabilitiesByCve($records);
}

/**
 * @param list<string> $templates шаблоны CPE: диапазоны берутся только из записей того же vendor:product
 * @return list<array<string, mixed>>
 */
function parseNvdResponse(string $body, array $templates): array
{
    $data = json_decode($body, true);
    if (!is_array($data) || !is_array($data['vulnerabilities'] ?? null)) {
        throw new RuntimeException('NVD: ответ не JSON или без списка уязвимостей');
    }

    $products = array_map(static fn (string $template): string => getCpeProduct($template), $templates);
    $records = [];
    foreach ($data['vulnerabilities'] as $entry) {
        $cve = is_array($entry['cve'] ?? null) ? $entry['cve'] : null;
        $id = is_string($cve['id'] ?? null) ? $cve['id'] : null;
        if ($cve === null || $id === null) {
            continue;
        }

        $ranges = [];
        $fixed = [];
        foreach (is_array($cve['configurations'] ?? null) ? $cve['configurations'] : [] as $configuration) {
            foreach (is_array($configuration['nodes'] ?? null) ? $configuration['nodes'] : [] as $node) {
                foreach (is_array($node['cpeMatch'] ?? null) ? $node['cpeMatch'] : [] as $match) {
                    $criteria = is_string($match['criteria'] ?? null) ? $match['criteria'] : '';
                    if (empty($match['vulnerable']) || !in_array(getCpeProduct($criteria), $products, true)) {
                        continue;
                    }
                    $ranges[] = buildNvdRange($match);
                    if (is_string($match['versionEndExcluding'] ?? null)) {
                        $fixed[] = $match['versionEndExcluding'];
                    }
                }
            }
        }

        $records[] = [
            'ids'    => [$id],
            'title'  => shortenVulnerabilityTitle(pickNvdDescription($cve)),
            'ranges' => array_values(array_unique($ranges, SORT_REGULAR)),
            'fixed'  => array_values(array_unique($fixed)),
            'cvss'   => pickNvdCvss($cve),
            'url'    => sprintf(NVD_DETAIL_URL, $id),
            'source' => NVD_SOURCE_TITLE,
        ];
    }

    return $records;
}

/**
 * `vendor:product` из CPE (`joomla:joomla\!`).
 */
function getCpeProduct(string $cpe): string
{
    $parts = explode(':', $cpe);

    return strtolower(($parts[NVD_CPE_VENDOR] ?? '') . ':' . ($parts[NVD_CPE_PRODUCT] ?? ''));
}

/**
 * Диапазон из `cpeMatch`: границы `versionStart*` / `versionEnd*`; без границ — версия из самого CPE (одна версия)
 * или все версии (`*`).
 *
 * @param array<string, mixed> $match
 * @return array{from: string, to: string, from_inclusive: bool, to_inclusive: bool}
 */
function buildNvdRange(array $match): array
{
    $from = $match['versionStartIncluding'] ?? $match['versionStartExcluding'] ?? null;
    $to = $match['versionEndIncluding'] ?? $match['versionEndExcluding'] ?? null;
    if (is_string($from) || is_string($to)) {
        return [
            'from'           => is_string($from) ? $from : VULNERABILITY_ANY_VERSION,
            'to'             => is_string($to) ? $to : VULNERABILITY_ANY_VERSION,
            'from_inclusive' => isset($match['versionStartIncluding']),
            'to_inclusive'   => isset($match['versionEndIncluding']),
        ];
    }

    $parts = explode(':', (string) ($match['criteria'] ?? ''));
    $version = $parts[NVD_CPE_VERSION] ?? VULNERABILITY_ANY_VERSION;
    if (in_array($version, NVD_CPE_EMPTY, true)) {
        return ['from' => VULNERABILITY_ANY_VERSION, 'to' => VULNERABILITY_ANY_VERSION, 'from_inclusive' => true, 'to_inclusive' => true];
    }
    $update = $parts[NVD_CPE_UPDATE] ?? '*';
    if (!in_array($update, NVD_CPE_EMPTY, true)) {
        $version .= '-' . $update;
    }

    return ['from' => $version, 'to' => $version, 'from_inclusive' => true, 'to_inclusive' => true];
}

/**
 * @param array<string, mixed> $cve
 */
function pickNvdDescription(array $cve): string
{
    foreach (is_array($cve['descriptions'] ?? null) ? $cve['descriptions'] : [] as $description) {
        if (($description['lang'] ?? null) === 'en' && is_string($description['value'] ?? null)) {
            return $description['value'];
        }
    }

    return '';
}

/**
 * Базовая оценка CVSS: новая версия метрики важнее, внутри версии — оценка самого NVD (Primary).
 *
 * @param array<string, mixed> $cve
 */
function pickNvdCvss(array $cve): ?float
{
    foreach (NVD_CVSS_METRICS as $metric) {
        $list = is_array($cve['metrics'][$metric] ?? null) ? $cve['metrics'][$metric] : [];
        usort($list, static fn (array $a, array $b): int
            => (($b['type'] ?? '') === 'Primary') <=> (($a['type'] ?? '') === 'Primary'));
        foreach ($list as $entry) {
            $score = $entry['cvssData']['baseScore'] ?? null;
            if (is_int($score) || is_float($score)) {
                return (float) $score;
            }
        }
    }

    return null;
}
