<?php

declare(strict_types=1);

/**
 * OSV (osv.dev) без БД: уязвимости пакета Packagist (ядро CMS) по версии — `POST /v1/query`, OSV сам сверяет
 * версию с диапазонами. Разбор ответа в записи формата `VulnerabilityService.php`: идентификаторы — CVE из
 * `aliases` первыми (по ним склейка с NVD), затем GHSA; CVSS в OSV — только вектор, поэтому серьёзность берётся
 * из `database_specific.severity` (GitHub Advisory).
 */

require_once ROOT_PATH . '/src/Services/VulnerabilityService.php';

const OSV_QUERY_URL    = 'https://api.osv.dev/v1/query';
const OSV_DETAIL_URL   = 'https://osv.dev/vulnerability/%s';
const OSV_ECOSYSTEM    = 'Packagist';
const OSV_SOURCE_TITLE = 'OSV';
const OSV_HTTP_OK      = 200;

/** Типы диапазонов OSV, версии в которых сравнимы с версией сайта (`GIT` — коммиты, не версии) */
const OSV_VERSION_RANGE_TYPES = ['ECOSYSTEM', 'SEMVER'];
/** `introduced: "0"` — уязвимы все версии с самой первой */
const OSV_FIRST_VERSION = '0';

/** Серьёзность GitHub Advisory → наша */
const OSV_SEVERITY_MAP = ['CRITICAL' => 'critical', 'HIGH' => 'high', 'MODERATE' => 'medium', 'MEDIUM' => 'medium', 'LOW' => 'low'];


/**
 * @return array{package: array{ecosystem: string, name: string}, version: string}
 */
function buildOsvQuery(string $package, string $version): array
{
    return ['package' => ['ecosystem' => OSV_ECOSYSTEM, 'name' => $package], 'version' => $version];
}

/**
 * Любой сбой — исключение: неполный ответ OSV в кэш не попадает.
 *
 * @param callable(string $url, ?array $json): array{status: ?int, body: string, error: ?string} $api
 * @return list<array<string, mixed>>
 */
function fetchOsvVulnerabilities(string $package, string $version, callable $api): array
{
    $response = $api(OSV_QUERY_URL, buildOsvQuery($package, $version));
    if (($response['error'] ?? null) !== null) {
        throw new RuntimeException('OSV: ' . $response['error']);
    }
    if (($response['status'] ?? null) !== OSV_HTTP_OK) {
        throw new RuntimeException('OSV ответил ' . ($response['status'] ?? 'без статуса'));
    }

    return parseOsvResponse((string) $response['body'], $package);
}

/**
 * @return list<array<string, mixed>>
 */
function parseOsvResponse(string $body, string $package): array
{
    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException('OSV: ответ не JSON');
    }

    $records = [];
    foreach (is_array($data['vulns'] ?? null) ? $data['vulns'] : [] as $vuln) {
        $id = is_string($vuln['id'] ?? null) ? $vuln['id'] : null;
        if ($id === null) {
            continue;
        }

        $aliases = array_values(array_filter(is_array($vuln['aliases'] ?? null) ? $vuln['aliases'] : [], 'is_string'));
        $cves = array_values(array_filter($aliases, static fn (string $alias): bool => preg_match(VULNERABILITY_CVE_PATTERN, $alias) === 1));

        $ranges = [];
        $fixed = [];
        foreach (is_array($vuln['affected'] ?? null) ? $vuln['affected'] : [] as $affected) {
            if (strcasecmp((string) ($affected['package']['name'] ?? ''), $package) !== 0) {
                continue;
            }
            foreach (is_array($affected['ranges'] ?? null) ? $affected['ranges'] : [] as $range) {
                if (!in_array($range['type'] ?? null, OSV_VERSION_RANGE_TYPES, true)) {
                    continue;
                }
                $parsed = parseOsvEvents(is_array($range['events'] ?? null) ? $range['events'] : []);
                $ranges = [...$ranges, ...$parsed['ranges']];
                $fixed = [...$fixed, ...$parsed['fixed']];
            }
        }

        $severity = strtoupper((string) ($vuln['database_specific']['severity'] ?? ''));
        $summary = is_string($vuln['summary'] ?? null) && trim($vuln['summary']) !== ''
            ? $vuln['summary']
            : (string) ($vuln['details'] ?? '');

        $records[] = [
            'ids'      => array_values(array_unique([...$cves, $id])),
            'title'    => shortenVulnerabilityTitle($summary),
            'ranges'   => array_values(array_unique($ranges, SORT_REGULAR)),
            'fixed'    => array_values(array_unique($fixed)),
            'cvss'     => null,
            'severity' => OSV_SEVERITY_MAP[$severity] ?? null,
            'url'      => sprintf(OSV_DETAIL_URL, $id),
            'source'   => OSV_SOURCE_TITLE,
        ];
    }

    return $records;
}

/**
 * События диапазона OSV по порядку: `introduced` открывает диапазон, `fixed` закрывает (не включая),
 * `last_affected` — закрывает включительно; незакрытый диапазон — до последней версии.
 *
 * @param list<array<string, mixed>> $events
 * @return array{ranges: list<array>, fixed: list<string>}
 */
function parseOsvEvents(array $events): array
{
    $ranges = [];
    $fixed = [];
    $from = null;
    foreach ($events as $event) {
        if (is_string($event['introduced'] ?? null)) {
            $from = $event['introduced'] === OSV_FIRST_VERSION ? VULNERABILITY_ANY_VERSION : $event['introduced'];
            continue;
        }
        $end = $event['fixed'] ?? $event['last_affected'] ?? null;
        if ($from === null || !is_string($end)) {
            continue;
        }
        $isFixed = isset($event['fixed']);
        $ranges[] = ['from' => $from, 'to' => $end, 'from_inclusive' => true, 'to_inclusive' => !$isFixed];
        if ($isFixed) {
            $fixed[] = $end;
        }
        $from = null;
    }
    if ($from !== null) {
        $ranges[] = ['from' => $from, 'to' => VULNERABILITY_ANY_VERSION, 'from_inclusive' => true, 'to_inclusive' => true];
    }

    return ['ranges' => $ranges, 'fixed' => $fixed];
}
