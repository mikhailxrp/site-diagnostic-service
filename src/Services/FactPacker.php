<?php

declare(strict_types=1);

/**
 * Пакет фактов для ИИ из того, что диагностика сохранила в БД (находки проверок, стек, страницы, `summary_json`),
 * без БД и сети. У находок и элементов стека — их id в БД: только на них ИИ может ссылаться. Длинные тексты
 * обрезаются; пакет не длиннее FACT_PACK_MAX_CHARS — сначала серьёзные находки, не поместившиеся отрезаются
 * (их число — в `omitted_findings`).
 */

require_once ROOT_PATH . '/src/Services/FindingFactory.php';

const FACT_PACK_MAX_CHARS     = 60000; // ≈ 20 тыс. токенов: крупный магазин помещается, запрос стоит рубли
const FACT_TEXT_MAX_CHARS     = 500;   // заголовок, подробности, адрес находки
const FACT_EVIDENCE_MAX_CHARS = 600;   // сырые данные находки (JSON строкой): стеки вызовов бывают длинными
const FACT_SECTION_MAX_CHARS  = 2000;  // раздел сводки (`availability`, `ssl`, `seo`, `pagespeed` …)
const FACT_TRUNCATED_MARK     = '…';
const FACT_SUMMARY_SKIPPED    = ['databases']; // даты баз уязвимостей — не о сайте
const FACT_JSON_FLAGS         = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

/**
 * @param list<array<string, mixed>> $findings   из getFindingsByDiagnostic() (находки ИИ пропускаются)
 * @param list<array<string, mixed>> $stackItems из getStackItemsByDiagnostic()
 * @param list<array<string, mixed>> $pages      из getCheckedPagesByDiagnostic()
 * @param array<string, mixed>|null  $summary    разобранный `summary_json`
 * @return array{pack: array{findings: list<array>, omitted_findings: int, stack: list<array>, pages: list<array>,
 *               summary: array<string, mixed>}, finding_ids: list<int>, stack_ids: list<int>}
 */
function buildFactPack(
    array $findings,
    array $stackItems,
    array $pages,
    ?array $summary,
    int $maxChars = FACT_PACK_MAX_CHARS
): array {
    $pageUrls = [];
    foreach ($pages as $page) {
        $pageUrls[(int) $page['id']] = (string) $page['url'];
    }

    $findingFacts = array_map(
        static fn (array $finding): array => packFindingFact($finding, $pageUrls),
        sortFindingsForPack(array_values(array_filter(
            $findings,
            static fn (array $finding): bool => ($finding['source'] ?? FINDING_SOURCE_CHECK) === FINDING_SOURCE_CHECK
        )))
    );

    $pack = [
        'findings'         => [],
        'omitted_findings' => 0,
        'stack'            => array_map('packStackFact', $stackItems),
        'pages'            => array_map('packPageFact', $pages),
        'summary'          => packSummaryFacts($summary),
    ];

    // Находки по одной, пока помещаются: первая не поместившаяся обрывает список, чтобы не было «дыр» в серьёзности
    $length = getFactJsonLength($pack);
    foreach ($findingFacts as $fact) {
        $factLength = getFactJsonLength($fact) + 1; // запятая между элементами
        if ($length + $factLength > $maxChars) {
            break;
        }
        $pack['findings'][] = $fact;
        $length += $factLength;
    }
    $pack['omitted_findings'] = count($findingFacts) - count($pack['findings']);

    $pack = fitFactPack($pack, $maxChars);

    return [
        'pack'        => $pack,
        'finding_ids' => array_column($pack['findings'], 'id'),
        'stack_ids'   => array_column($pack['stack'], 'id'),
    ];
}

/**
 * Пакет всё ещё длиннее предела (число в `omitted_findings` выросло, огромный стек) — отрезаются находки с конца,
 * затем элементы стека.
 *
 * @param array<string, mixed> $pack
 * @return array<string, mixed>
 */
function fitFactPack(array $pack, int $maxChars): array
{
    while (getFactJsonLength($pack) > $maxChars) {
        if ($pack['findings'] !== []) {
            array_pop($pack['findings']);
            $pack['omitted_findings']++;
        } elseif ($pack['stack'] !== []) {
            array_pop($pack['stack']);
        } else {
            break;
        }
    }

    return $pack;
}

/**
 * От критичных к информации, внутри — по id (порядок записи).
 *
 * @param list<array<string, mixed>> $findings
 * @return list<array<string, mixed>>
 */
function sortFindingsForPack(array $findings): array
{
    $order = array_flip(FINDING_SEVERITIES);
    usort(
        $findings,
        static fn (array $a, array $b): int => [$order[$a['severity'] ?? ''] ?? PHP_INT_MAX, (int) ($a['id'] ?? 0)]
            <=> [$order[$b['severity'] ?? ''] ?? PHP_INT_MAX, (int) ($b['id'] ?? 0)]
    );

    return $findings;
}

/**
 * @param array<string, mixed> $finding
 * @param array<int, string>   $pageUrls id страницы → адрес
 * @return array{id: int, category: string, severity: string, title: string, location: ?string, page: ?string,
 *               details: ?string, evidence: ?string}
 */
function packFindingFact(array $finding, array $pageUrls): array
{
    $pageId = isset($finding['page_id']) ? (int) $finding['page_id'] : null;
    $evidence = $finding['evidence'] ?? null;
    $evidenceJson = is_array($evidence) && $evidence !== [] ? json_encode($evidence, FACT_JSON_FLAGS) : false;

    return [
        'id'       => (int) $finding['id'],
        'category' => (string) $finding['category'],
        'severity' => (string) $finding['severity'],
        'title'    => truncateFactText((string) $finding['title'], FACT_TEXT_MAX_CHARS),
        'location' => packOptionalFactText($finding['location'] ?? null),
        'page'     => $pageId !== null ? ($pageUrls[$pageId] ?? null) : null,
        'details'  => packOptionalFactText($finding['details'] ?? null),
        'evidence' => $evidenceJson !== false ? truncateFactText($evidenceJson, FACT_EVIDENCE_MAX_CHARS) : null,
    ];
}

/**
 * @param array<string, mixed> $item
 * @return array{id: int, category: string, name: string, version: ?string, latest_version: ?string,
 *               confidence: string}
 */
function packStackFact(array $item): array
{
    return [
        'id'             => (int) $item['id'],
        'category'       => (string) $item['category'],
        'name'           => (string) $item['name'],
        'version'        => packOptionalFactText($item['version'] ?? null),
        'latest_version' => packOptionalFactText($item['latest_version'] ?? null),
        'confidence'     => (string) ($item['confidence'] ?? ''),
    ];
}

/**
 * Страница — контекст без id: ИИ ссылается на находки и стек, а не на страницы.
 *
 * @param array<string, mixed> $page
 * @return array{type: string, url: string, http_code: ?int, response_ms: ?int, blocked: bool}
 */
function packPageFact(array $page): array
{
    return [
        'type'        => (string) ($page['page_type'] ?? ''),
        'url'         => truncateFactText((string) $page['url'], FACT_TEXT_MAX_CHARS),
        'http_code'   => isset($page['http_code']) ? (int) $page['http_code'] : null,
        'response_ms' => isset($page['response_ms']) ? (int) $page['response_ms'] : null,
        'blocked'     => !empty($page['blocked']),
    ];
}

/**
 * Разделы сводки как есть; раздел длиннее FACT_SECTION_MAX_CHARS — обрезанной строкой JSON.
 *
 * @param array<string, mixed>|null $summary
 * @return array<string, mixed>
 */
function packSummaryFacts(?array $summary): array
{
    $sections = [];
    foreach ($summary ?? [] as $name => $section) {
        if (in_array($name, FACT_SUMMARY_SKIPPED, true)) {
            continue;
        }
        $json = (string) json_encode($section, FACT_JSON_FLAGS);
        $sections[$name] = mb_strlen($json) > FACT_SECTION_MAX_CHARS
            ? truncateFactText($json, FACT_SECTION_MAX_CHARS)
            : $section;
    }

    return $sections;
}

function packOptionalFactText(mixed $text): ?string
{
    return is_string($text) && $text !== '' ? truncateFactText($text, FACT_TEXT_MAX_CHARS) : null;
}

function truncateFactText(string $text, int $maxChars): string
{
    return mb_strlen($text) > $maxChars
        ? mb_substr($text, 0, $maxChars - mb_strlen(FACT_TRUNCATED_MARK)) . FACT_TRUNCATED_MARK
        : $text;
}

/**
 * Длина в символах — так пакет и уходит в запрос (FACT_JSON_FLAGS, без экранирования кириллицы).
 */
function getFactJsonLength(mixed $value): int
{
    return mb_strlen((string) json_encode($value, FACT_JSON_FLAGS));
}

/**
 * Пакет фактов строкой JSON — для сообщения модели.
 *
 * @param array<string, mixed> $data
 */
function encodeFactJson(array $data): string
{
    return json_encode($data, FACT_JSON_FLAGS | JSON_THROW_ON_ERROR);
}
