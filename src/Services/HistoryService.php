<?php

declare(strict_types=1);

/**
 * История диагностик: нормализация поискового запроса, пагинация, строки таблицы.
 * Чистые функции без БД.
 */

require_once ROOT_PATH . '/src/Services/DiagnosticStatusService.php';

const HISTORY_PATH             = '/history';
const HISTORY_PER_PAGE         = 20;
const HISTORY_QUERY_MAX_LENGTH = 255;   // как diagnostics.host VARCHAR(255)
const HISTORY_PAGE_MAX         = 100000; // защита от огромных чисел в `?page=`
const HISTORY_PAGINATION_SPAN  = 2;     // сколько соседних страниц показывать слева и справа от текущей

/** Выполняющуюся диагностику удалить нельзя: воркер ещё пишет в её записи (как в deleteDiagnostic()) */
const HISTORY_UNDELETABLE_STATUSES = ['queued', 'running'];

const HISTORY_DB_DATE_FORMAT      = 'Y-m-d H:i:s';
const HISTORY_DISPLAY_DATE_FORMAT = 'd.m.Y H:i';

/**
 * Введённый запрос как в поле поиска: без пробелов по краям, не длиннее предела.
 */
function readHistoryQueryInput(mixed $raw): string
{
    return is_string($raw) ? mb_substr(trim($raw), 0, HISTORY_QUERY_MAX_LENGTH) : '';
}

/**
 * Номер страницы из `?page=`: целое ≥ 1, иначе 1.
 */
function readHistoryPageInput(mixed $raw): int
{
    $page = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => HISTORY_PAGE_MAX]]);

    return $page === false ? 1 : $page;
}

/**
 * Запрос для поиска в `host` / `url`: нижний регистр, без схемы, `www.`, пути; кириллица → punycode
 * (так хранится `host`). `https://www.Sudar.su/catalog/` → `sudar.su`.
 */
function normalizeHistoryQuery(string $raw): string
{
    $query = mb_strtolower(trim($raw), 'UTF-8');
    $query = (string) preg_replace('#^[a-z][a-z0-9+.\-]*://#', '', $query);
    $query = (string) preg_replace('#^//#', '', $query);
    $query = preg_split('#[/?\#]#', $query, 2)[0];

    if (str_starts_with($query, 'www.')) {
        $query = substr($query, strlen('www.'));
    }

    if ($query !== '' && preg_match('/[^\x00-\x7F]/', $query) === 1) {
        $ascii = idn_to_ascii($query, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
        if (is_string($ascii) && $ascii !== '') {
            $query = $ascii;
        }
    }

    return mb_substr($query, 0, HISTORY_QUERY_MAX_LENGTH);
}

/**
 * Адрес страницы истории: `q` и `page` только если не пустые / не первая.
 */
function buildHistoryUrl(string $query, int $page): string
{
    $params = array_filter(
        ['q' => $query, 'page' => $page > 1 ? $page : null],
        static fn (mixed $value): bool => $value !== null && $value !== ''
    );

    return $params === [] ? HISTORY_PATH : HISTORY_PATH . '?' . http_build_query($params);
}

/**
 * Пагинация: текущая страница вне диапазона → ближайшая существующая. В `items` номера страниц
 * и null на месте пропуска: 1 … 4 5 [6] 7 8 … 20.
 *
 * @return array{total: int, perPage: int, pages: int, current: int, offset: int, prev: ?int, next: ?int,
 *               items: list<?int>}
 */
function buildHistoryPagination(int $total, int $page, int $perPage): array
{
    $total   = max(0, $total);
    $pages   = max(1, (int) ceil($total / max(1, $perPage)));
    $current = min(max(1, $page), $pages);

    $items = [];
    $previous = 0;
    for ($number = 1; $number <= $pages; $number++) {
        $isEdge = $number === 1 || $number === $pages;
        $isNear = abs($number - $current) <= HISTORY_PAGINATION_SPAN;
        if (!$isEdge && !$isNear) {
            continue;
        }
        if ($number - $previous > 1) {
            $items[] = null;
        }
        $items[] = $number;
        $previous = $number;
    }

    return [
        'total'   => $total,
        'perPage' => $perPage,
        'pages'   => $pages,
        'current' => $current,
        'offset'  => ($current - 1) * $perPage,
        'prev'    => $current > 1 ? $current - 1 : null,
        'next'    => $current < $pages ? $current + 1 : null,
        'items'   => $items,
    ];
}

/**
 * Ссылки пагинации для View: номер (null — пропуск), адрес, текущая ли.
 *
 * @param array{prev: ?int, next: ?int, current: int, items: list<?int>} $pagination
 * @return array{prev: ?string, next: ?string, links: list<array{number: ?int, url: ?string, active: bool}>}
 */
function buildHistoryPaginationLinks(array $pagination, string $query): array
{
    return [
        'prev'  => $pagination['prev'] !== null ? buildHistoryUrl($query, $pagination['prev']) : null,
        'next'  => $pagination['next'] !== null ? buildHistoryUrl($query, $pagination['next']) : null,
        'links' => array_map(
            static fn (?int $number): array => [
                'number' => $number,
                'url'    => $number !== null ? buildHistoryUrl($query, $number) : null,
                'active' => $number === $pagination['current'],
            ],
            $pagination['items']
        ),
    ];
}

/**
 * Строки таблицы истории: дата для экрана, подпись и бейдж статуса, счётчики находок, можно ли удалить.
 *
 * @param list<array<string, mixed>> $diagnostics из searchDiagnostics()
 * @return list<array{id: int, url: string, host: string, displayHost: string, showUrl: string, deleteUrl: string, createdAt: string,
 *                    statusLabel: string, badgeClass: string, criticalCount: int, highCount: int,
 *                    findingCount: int, isFinished: bool, canDelete: bool}>
 */
function buildHistoryRows(array $diagnostics): array
{
    return array_map(
        static function (array $diagnostic): array {
            $id     = (int) $diagnostic['id'];
            $status = (string) $diagnostic['status'];

            return [
                'id'            => $id,
                'url'           => (string) $diagnostic['url'],
                'host'          => (string) $diagnostic['host'],
                'displayHost'   => formatHistoryHost((string) $diagnostic['host']),
                'showUrl'       => '/diagnostics/' . $id,
                'deleteUrl'     => '/diagnostics/' . $id . '/delete',
                'createdAt'     => formatHistoryDate((string) $diagnostic['created_at']),
                'statusLabel'   => DIAGNOSTIC_STATUS_LABELS[$status] ?? $status,
                'badgeClass'    => DIAGNOSTIC_STATUS_BADGES[$status] ?? UNKNOWN_STATUS_BADGE,
                'criticalCount' => (int) ($diagnostic['critical_count'] ?? 0),
                'highCount'     => (int) ($diagnostic['high_count'] ?? 0),
                'findingCount'  => (int) ($diagnostic['finding_count'] ?? 0),
                'isFinished'    => in_array($status, FINISHED_DIAGNOSTIC_STATUSES, true),
                'canDelete'     => !in_array($status, HISTORY_UNDELETABLE_STATUSES, true),
            ];
        },
        $diagnostics
    );
}

/**
 * Хост для экрана: punycode → кириллица (`xn--80aairftm.xn--p1ai` → `магазин.рф`); не вышло — как есть.
 */
function formatHistoryHost(string $host): string
{
    if (!str_contains($host, 'xn--')) {
        return $host;
    }

    $unicode = idn_to_utf8($host, IDNA_NONTRANSITIONAL_TO_UNICODE, INTL_IDNA_VARIANT_UTS46);

    return is_string($unicode) && $unicode !== '' ? $unicode : $host;
}

/**
 * `2026-11-15 18:29:43` → `15.11.2026 18:29`; нераспознанная строка возвращается как есть.
 */
function formatHistoryDate(string $value): string
{
    $date = DateTimeImmutable::createFromFormat(HISTORY_DB_DATE_FORMAT, $value);

    return $date !== false ? $date->format(HISTORY_DISPLAY_DATE_FORMAT) : $value;
}
