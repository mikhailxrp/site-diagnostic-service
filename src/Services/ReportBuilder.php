<?php

declare(strict_types=1);

/**
 * Данные отчёта для экрана диагностики: сводка доступности и SSL, стек, находки по серьёзности,
 * шаги, которые не удалось проверить. Чистые функции без БД — все подписи и классы бейджей Tabler здесь,
 * View только выводит готовые строки.
 */

require_once ROOT_PATH . '/src/Services/DiagnosticStatusService.php';
require_once ROOT_PATH . '/src/Services/FindingFactory.php';
require_once ROOT_PATH . '/src/Services/Checks/SpeedCheck.php';

/** Статусы диагностики, для которых строится отчёт; при `failed` — частичный (то, что успело собраться) */
const REPORT_STATUSES         = ['done', 'failed'];
const REPORT_PARTIAL_STATUSES = ['failed'];

/** Шаги, попадающие в «Не удалось проверить»; при прерванной диагностике — ещё и незавершённые */
const REPORT_UNCHECKED_STEP_STATUSES  = ['failed', 'skipped'];
const REPORT_UNFINISHED_STEP_STATUSES = ['pending', 'running'];
const REPORT_UNFINISHED_STEP_MESSAGE  = 'Не выполнен: диагностика прервана.';

/** Порядок групп совпадает с FINDING_SEVERITIES */
const REPORT_SEVERITY_LABELS = [
    'critical' => 'Критично',
    'high'     => 'Высокая',
    'medium'   => 'Средняя',
    'low'      => 'Низкая',
    'info'     => 'Информация',
];

const REPORT_SEVERITY_BADGES = [
    'critical' => 'bg-red text-red-fg',
    'high'     => 'bg-orange text-orange-fg',
    'medium'   => 'bg-yellow text-yellow-fg',
    'low'      => 'bg-azure-lt',
    'info'     => 'bg-secondary-lt',
];

const REPORT_FINDING_CATEGORY_LABELS = [
    'availability'    => 'Доступность',
    'ssl'             => 'SSL-сертификат',
    'broken_resource' => 'Битый ресурс',
    'php_error'       => 'Ошибка PHP',
    'mixed_content'   => 'Смешанный контент',
    'js_error'        => 'Ошибка JS',
    'failed_request'  => 'Упавший запрос',
    'outdated'        => 'Устаревшая версия',
    'vulnerability'   => 'Уязвимость',
    'exposed_file'    => 'Открытый файл',
    'seo'             => 'SEO',
    'speed'           => 'Скорость',
    'checkout'        => 'Оформление заказа',
    'layout'          => 'Вёрстка',
    'hypothesis'      => 'Гипотеза',
];

/** Порядок групп стека на экране */
const REPORT_STACK_CATEGORY_LABELS = [
    'cms'        => 'CMS',
    'theme'      => 'Тема',
    'plugin'     => 'Плагины и модули',
    'server'     => 'Сервер',
    'php'        => 'PHP',
    'cdn'        => 'CDN',
    'js_library' => 'JS-библиотеки',
    'analytics'  => 'Аналитика',
    'other'      => 'Другое',
];

const REPORT_CONFIDENCE_LABELS = [
    'high'   => 'Уверенно',
    'medium' => 'Вероятно',
    'low'    => 'Предположительно',
];

const REPORT_CONFIDENCE_BADGES = [
    'high'   => 'bg-green-lt',
    'medium' => 'bg-yellow-lt',
    'low'    => 'bg-secondary-lt',
];

const REPORT_EVIDENCE_SOURCE_LABELS = [
    'header' => 'Заголовок ответа',
    'cookie' => 'Cookie',
    'meta'   => 'Мета-тег',
    'html'   => 'HTML страницы',
    'url'    => 'Адрес ресурса',
    'probe'  => 'Служебный файл',
];

/** Типы проверенных страниц в порядке вывода */
const REPORT_PAGE_TYPE_LABELS = [
    'home'     => 'Главная',
    'category' => 'Категория',
    'product'  => 'Товар',
    'cart'     => 'Корзина',
    'checkout' => 'Оформление заказа',
];

const REPORT_FOUND_BY_LABELS = [
    'start'          => 'адрес из формы',
    'cms_pattern'    => 'по шаблону CMS',
    'link_heuristic' => 'по ссылкам',
    'manual'         => 'указана вручную',
];

const REPORT_PAGE_NOT_FOUND_LABEL  = 'не найдена';
const REPORT_PAGE_NOT_FOUND_REASON = 'страница не найдена';
const REPORT_PAGE_NO_RESPONSE      = 'нет ответа';

/** Страница-заглушка защиты от ботов (`checked_pages.blocked`) */
const REPORT_PAGE_BLOCKED_LABEL    = 'не удалось проверить';
const REPORT_PAGE_BLOCKED_TITLE    = 'защита от ботов';
const REPORT_PAGE_BLOCKED_REASON   = 'Вместо страницы ответила заглушка защиты от ботов (%s): ресурсы и ошибки не проверены.';
const REPORT_PAGE_BLOCKED_DETAIL   = ' Признак: %s.';
const REPORT_BADGE_BLOCKED         = 'bg-orange-lt';

/** Скриншоты в строке страницы: файлы отдаёт ScreenshotController */
const REPORT_SCREENSHOT_URL        = '/screenshots/%d';
const REPORT_SCREENSHOT_THUMB_URL  = '/screenshots/%d/thumb';
const REPORT_SCREENSHOT_ALT        = '%s, %d px';
const REPORT_SCREENSHOT_THUMB_ALT  = '%s, %d px, миниатюра';
const REPORT_SCREENSHOT_MODAL_ID   = 'screenshot-%s-%d'; // тип в id: один снимок бывает в двух строках
const REPORT_SCREENSHOTS_EMPTY     = 'Скриншотов нет.';
const REPORT_BROWSER_STEP_CODE     = 'browser';

/** Группа «По страницам» для находок без страницы (SSL, стек и т. п.) */
const REPORT_NO_PAGE_GROUP_TITLE = 'Сайт целиком';
const REPORT_PAGE_TYPES_SEPARATOR = ', ';

/** Варианты адреса из шага `availability` (ключи — AVAILABILITY_VARIANT_*) */
const REPORT_VARIANT_LABELS = [
    'http'      => 'http://',
    'https'     => 'https://',
    'http_www'  => 'http://www.',
    'https_www' => 'https://www.',
];

const REPORT_HTTP_OK_MIN       = 200;
const REPORT_HTTP_REDIRECT_MIN = 300;
const REPORT_HTTP_ERROR_MIN    = 400;

const REPORT_BADGE_GOOD    = 'bg-green-lt';
const REPORT_BADGE_WARNING = 'bg-yellow-lt';
const REPORT_BADGE_BAD     = 'bg-red-lt';
const REPORT_BADGE_NEUTRAL = 'bg-secondary-lt';

/** Сертификат, который истекает раньше, — предупреждение (как в SslCheck: < 14 дней → `medium`) */
const REPORT_SSL_WARNING_DAYS = 14;

/** Строки SEO в сводке (`summary_json.seo`, шаг `seo`); робот из SEO_ROBOTS_AGENTS → подпись */
const REPORT_ROBOTS_AGENT_LABELS = ['*' => 'всех роботов', 'yandex' => 'Яндекса', 'googlebot' => 'Google'];
const REPORT_SEO_BLOCKED_VALUE   = 'не удалось проверить (защита от ботов)';

/** Версии стека (шаг `outdated`): бейдж по находке `outdated` с `evidence.stack_item_id` */
const REPORT_OUTDATED_LABEL       = 'устарела';
const REPORT_UNSUPPORTED_LABEL    = 'ветка не поддерживается';
const REPORT_OUTDATED_BADGE       = 'bg-yellow-lt';
const REPORT_UNSUPPORTED_BADGE    = 'bg-red-lt';
const REPORT_DATABASE_STALE_DAYS  = 14; // база старше на момент диагностики — Планировщик не обновлял её
const REPORT_DATABASE_NO_DATE     = 'дата неизвестна';
const REPORT_DATABASE_STALE_WARNING = 'База не обновлялась больше %d дней на момент диагностики — проверьте задачу '
    . 'Планировщика «Site Diagnostics - обновление баз».';
const REPORT_SECONDS_PER_DAY      = 86400;

/** Карточка «Скорость» (`summary_json.pagespeed`, шаг `pagespeed`) */
const REPORT_SPEED_RATING_BADGES = [
    SPEED_RATING_GOOD  => REPORT_BADGE_GOOD,
    SPEED_RATING_NEEDS => REPORT_BADGE_WARNING,
    SPEED_RATING_POOR  => REPORT_BADGE_BAD,
];
const REPORT_SPEED_LAB_METRICS = [
    'lcp' => 'LCP — основное содержимое',
    'cls' => 'CLS — сдвиги макета',
    'tbt' => 'TBT — блокировка потока',
    'fcp' => 'FCP — первая отрисовка',
];
const REPORT_SPEED_FIELD_METRICS = [
    'lcp' => 'LCP — основное содержимое',
    'inp' => 'INP — отклик на действия',
    'cls' => 'CLS — сдвиги макета',
    'fcp' => 'FCP — первая отрисовка',
];
const REPORT_SPEED_NO_VALUE      = '—';
const REPORT_SPEED_ORIGIN_NOTE   = '%s: по самой главной у Google данных нет — показаны данные по всему сайту.';
const REPORT_SPEED_ERROR         = '%s: не удалось проверить — %s';
const REPORT_SPEED_WARNING       = '%s: %s';
const REPORT_SPEED_LAB_TABLE_ID   = 'report-speed-lab';
const REPORT_SPEED_LAB_TITLE      = 'Лаборатория (Lighthouse, один прогон на серверах Google)';
const REPORT_SPEED_LAB_EMPTY      = 'Lighthouse не вернул лабораторных метрик.';
const REPORT_SPEED_FIELD_TABLE_ID = 'report-speed-field';
const REPORT_SPEED_FIELD_TITLE    = 'Реальные пользователи (Chrome UX Report, 28 дней)';
const REPORT_SPEED_FIELD_EMPTY    = 'У Google нет данных реальных пользователей по этому сайту.';

const REPORT_JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    | JSON_INVALID_UTF8_SUBSTITUTE;

/** Якоря для ссылок «Опирается на»: находка (на вкладке «По серьёзности») и элемент стека */
const REPORT_FINDING_ANCHOR = 'finding-%d';
const REPORT_STACK_ANCHOR   = 'stack-%d';

/** Блок «Разбор жалобы (ИИ)»: шаг `ai_problem`, ответ ИИ `problem`, находки `hypothesis` */
const REPORT_AI_PROBLEM_STEP_CODE  = 'ai_problem';
const REPORT_AI_PROBLEM_TASK       = 'problem';
const REPORT_AI_HYPOTHESIS_CATEGORY = 'hypothesis';
const REPORT_AI_STATE_DONE         = 'done';
const REPORT_AI_STATE_FAILED       = 'failed';
const REPORT_AI_STATE_SKIPPED      = 'skipped';
const REPORT_AI_RESULT_ERROR       = 'error';
const REPORT_AI_NOT_RUN_MESSAGE    = 'ИИ-анализ не выполнен.';
const REPORT_AI_DATE_FORMAT        = 'd.m.Y H:i';
const REPORT_AI_NO_TOKENS          = '—';
const REPORT_AI_EVIDENCE_KEYS      = ['finding_ids', 'stack_ids'];

/**
 * @param array<string, mixed>       $diagnostic строка `diagnostics` + `steps` (как из getDiagnosticWithSteps())
 * @param list<array<string, mixed>> $findings   из getFindingsByDiagnostic()
 * @param list<array<string, mixed>> $stackItems из getStackItemsByDiagnostic()
 * @param list<array<string, mixed>> $pages      из getCheckedPagesByDiagnostic()
 * @param list<array<string, mixed>> $screenshots из getScreenshotsByDiagnostic()
 * @param list<array<string, mixed>> $aiResults  из getAiResultsByDiagnostic()
 * @return array{partial: bool, productUrl: ?string, summary: ?array, pagespeed: ?array, pages: list<array>,
 *               findingCount: int,
 *               findingGroups: list<array>, findingPageGroups: list<array>, blockedBadge: array,
 *               stackGroups: list<array>, databases: list<array>, databasesStale: bool, databasesWarning: string,
 *               uncheckedSteps: list<array>, aiProblem: ?array}
 */
function buildReport(
    array $diagnostic,
    array $findings,
    array $stackItems,
    array $pages,
    array $screenshots = [],
    array $aiResults = []
): array {
    // Находки ИИ — в своих карточках: в «Находках» только факты
    $aiFindings = array_values(array_filter($findings, 'isReportAiFinding'));
    $findings = array_values(array_filter($findings, static fn (array $finding): bool => !isReportAiFinding($finding)));
    $partial = in_array((string) $diagnostic['status'], REPORT_PARTIAL_STATUSES, true);
    $summary = decodeReportSummary($diagnostic['summary_json'] ?? null);
    $blocked = buildBlockedPageReasons($pages, $summary['blocked'] ?? null);
    $databases = buildReportDatabases($summary['databases'] ?? null, $diagnostic['created_at'] ?? null);
    $pageUrls = buildPageUrlMap($pages);

    return [
        'partial'           => $partial,
        'productUrl'        => isset($diagnostic['product_url']) && $diagnostic['product_url'] !== ''
            ? (string) $diagnostic['product_url']
            : null,
        'summary'           => buildReportSummary($summary, findHomeBlockedReason($pages, $blocked)),
        'pagespeed'         => buildReportPageSpeed($summary['pagespeed'] ?? null),
        'pages'             => buildReportPages(
            $pages,
            $summary['pages'] ?? null,
            $blocked,
            buildPageScreenshotMap($screenshots, $pageUrls),
            findBrowserStepNote($diagnostic['steps'] ?? [])
        ),
        'findingCount'      => count($findings),
        'findingGroups'     => buildReportFindingGroups($findings, $pageUrls),
        'findingPageGroups' => buildReportFindingsByPage($findings, $pages, $blocked),
        'blockedBadge'      => ['label' => REPORT_PAGE_BLOCKED_LABEL, 'badgeClass' => REPORT_BADGE_BLOCKED],
        'stackGroups'       => buildReportStackGroups($stackItems, buildOutdatedStackMap($findings)),
        'databases'         => $databases,
        'databasesStale'    => in_array(true, array_column($databases, 'stale'), true),
        'databasesWarning'  => sprintf(REPORT_DATABASE_STALE_WARNING, REPORT_DATABASE_STALE_DAYS),
        'uncheckedSteps'    => buildReportUncheckedSteps($diagnostic['steps'] ?? [], $partial),
        'aiProblem'         => buildReportAiProblem($diagnostic, $aiFindings, $findings, $stackItems, $aiResults),
    ];
}

/**
 * `summary_json` из БД: строка JSON (или уже массив). NULL, пустой или битый JSON → null.
 *
 * @return array<string, mixed>|null
 */
function decodeReportSummary(mixed $summaryJson): ?array
{
    if (is_array($summaryJson)) {
        return $summaryJson;
    }
    if (!is_string($summaryJson) || $summaryJson === '') {
        return null;
    }

    $decoded = json_decode($summaryJson, true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * Сводка: строки «подпись → значение», варианты адреса, цепочка редиректов главной, предупреждения.
 * null — сводки нет (шаг доступности не дошёл до записи).
 *
 * @param array<string, mixed>|null $summary
 * @param string|null               $homeBlocked причина, если главная — заглушка защиты от ботов
 * @return array{rows: list<array>, variants: list<array>, redirects: list<array>, warnings: list<string>}|null
 */
function buildReportSummary(?array $summary, ?string $homeBlocked = null): ?array
{
    $availability = $summary['availability'] ?? null;
    if (!is_array($availability) || !is_array($availability['home'] ?? null)) {
        return null;
    }

    $home = $availability['home'];

    $rows = [
        buildReportResponseRow('Ответ главной', $home),
        [
            'label'      => 'Итоговый адрес',
            'value'      => (string) ($home['final_url'] ?? $home['url'] ?? ''),
            'badgeClass' => null,
        ],
        [
            'label'      => 'Время ответа',
            'value'      => isset($availability['response_ms']) ? $availability['response_ms'] . ' мс' : 'нет данных',
            'badgeClass' => null,
        ],
        buildReportFlagRow('Редирект на https', $availability['https_redirect'] ?? null, 'есть', 'нет'),
        buildReportFlagRow(
            'www и без www',
            $availability['www_consistent'] ?? null,
            'один и тот же сайт',
            'разные сайты'
        ),
        ...buildReportSslRows($summary['ssl'] ?? null),
        ...buildReportSeoRows($summary['seo'] ?? null),
    ];

    $warnings = [];
    if ($homeBlocked !== null) {
        $warnings[] = 'Главная: ' . $homeBlocked;
    } elseif (!empty($home['possible_bot_protection'])) {
        $warnings[] = 'Возможна защита от ботов (ответ ' . ($home['status'] ?? '—')
            . '): часть проверок могла увидеть не настоящую страницу.';
    }
    if (!empty($home['truncated'])) {
        $warnings[] = 'HTML главной обрезан по размеру: проверены не все ресурсы.';
    }

    return [
        'rows'      => $rows,
        'variants'  => buildReportVariants(is_array($availability['variants'] ?? null) ? $availability['variants'] : []),
        'redirects' => buildReportRedirects(is_array($home['redirects'] ?? null) ? $home['redirects'] : []),
        'warnings'  => $warnings,
    ];
}

/**
 * @param array<string, mixed> $response сводка ответа (`summarizeResponse()`)
 * @return array{label: string, value: string, badgeClass: ?string}
 */
function buildReportResponseRow(string $label, array $response): array
{
    $status = $response['status'] ?? null;

    if (!is_int($status)) {
        $error = (string) ($response['error'] ?? '');

        return [
            'label'      => $label,
            'value'      => $error !== '' ? 'нет ответа: ' . $error : 'нет ответа',
            'badgeClass' => REPORT_BADGE_BAD,
        ];
    }

    return ['label' => $label, 'value' => (string) $status, 'badgeClass' => httpStatusBadge($status)];
}

function httpStatusBadge(int $status): string
{
    return match (true) {
        $status >= REPORT_HTTP_ERROR_MIN    => REPORT_BADGE_BAD,
        $status >= REPORT_HTTP_REDIRECT_MIN => REPORT_BADGE_WARNING,
        $status >= REPORT_HTTP_OK_MIN       => REPORT_BADGE_GOOD,
        default                             => REPORT_BADGE_NEUTRAL,
    };
}

/**
 * @return array{label: string, value: string, badgeClass: string}
 */
function buildReportFlagRow(string $label, mixed $flag, string $yes, string $no): array
{
    return match ($flag) {
        true    => ['label' => $label, 'value' => $yes, 'badgeClass' => REPORT_BADGE_GOOD],
        false   => ['label' => $label, 'value' => $no, 'badgeClass' => REPORT_BADGE_BAD],
        default => ['label' => $label, 'value' => 'не проверено', 'badgeClass' => REPORT_BADGE_NEUTRAL],
    };
}

/**
 * Карточка «Скорость»: оценки mobile / desktop, лабораторные метрики, данные реальных пользователей,
 * предупреждения Lighthouse и ошибки стратегий. Шага не было или ни одна стратегия не удалась → null.
 *
 * @param mixed $pagespeed `summary_json.pagespeed`: url, mobile, desktop (`parsePageSpeedResponse()`), errors
 * @return array{url: string, lighthouseVersion: ?string,
 *               scores: list<array{label: string, value: string, ratingLabel: ?string, badgeClass: string}>,
 *               alerts: list<string>,
 *               tables: list<array{id: string, title: string, columns: list<string>, rows: list<array>, empty: string,
 *                                  note: ?string}>}|null
 */
function buildReportPageSpeed(mixed $pagespeed): ?array
{
    if (!is_array($pagespeed)) {
        return null;
    }

    $results = [];
    foreach (PAGESPEED_STRATEGIES as $strategy) {
        $results[$strategy] = is_array($pagespeed[$strategy] ?? null) ? $pagespeed[$strategy] : null;
    }
    if (array_filter($results) === []) {
        return null;
    }

    $errors = is_array($pagespeed['errors'] ?? null) ? $pagespeed['errors'] : [];
    $columns = [];
    $originColumns = [];
    $scores = [];
    $alerts = [];
    $version = null;

    foreach ($results as $strategy => $result) {
        $label = SPEED_STRATEGY_LABELS[$strategy];
        $columns[] = $label;
        if (($result['field_scope'] ?? null) === PAGESPEED_FIELD_SCOPE_ORIGIN) {
            $originColumns[] = $label;
        }
        $scores[] = buildReportSpeedScore($label, $result['score'] ?? null);

        if ($result === null) {
            $alerts[] = sprintf(REPORT_SPEED_ERROR, $label, (string) ($errors[$strategy] ?? PAGESPEED_ERROR_NO_RESPONSE));
            continue;
        }
        foreach ($result['warnings'] ?? [] as $warning) {
            $alerts[] = sprintf(REPORT_SPEED_WARNING, $label, (string) $warning);
        }
        $version ??= is_string($result['lighthouse_version'] ?? null) ? $result['lighthouse_version'] : null;
    }

    $hasField = array_filter(array_column(array_filter($results), 'field')) !== [];

    return [
        'url'               => (string) ($pagespeed['url'] ?? ''),
        'lighthouseVersion' => $version,
        'scores'            => $scores,
        'alerts'            => $alerts,
        'tables'            => [
            [
                'id'      => REPORT_SPEED_LAB_TABLE_ID,
                'title'   => REPORT_SPEED_LAB_TITLE,
                'columns' => $columns,
                'rows'    => buildReportSpeedRows($results, 'lab', REPORT_SPEED_LAB_METRICS),
                'empty'   => REPORT_SPEED_LAB_EMPTY,
                'note'    => null,
            ],
            [
                'id'      => REPORT_SPEED_FIELD_TABLE_ID,
                'title'   => REPORT_SPEED_FIELD_TITLE,
                'columns' => $columns,
                'rows'    => $hasField ? buildReportSpeedRows($results, 'field', REPORT_SPEED_FIELD_METRICS) : [],
                'empty'   => REPORT_SPEED_FIELD_EMPTY,
                // Подпись под таблицей, а не в заголовке колонки: длинный заголовок не помещается на 360 px
                'note'    => $hasField && $originColumns !== []
                    ? sprintf(REPORT_SPEED_ORIGIN_NOTE, implode(', ', $originColumns))
                    : null,
            ],
        ],
    ];
}

/**
 * @return array{label: string, value: string, ratingLabel: ?string, badgeClass: string}
 */
function buildReportSpeedScore(string $label, mixed $score): array
{
    if (!is_int($score)) {
        return ['label' => $label, 'value' => REPORT_SPEED_NO_VALUE, 'ratingLabel' => null,
            'badgeClass' => REPORT_BADGE_NEUTRAL];
    }
    $rating = rateSpeedScore($score);

    return [
        'label'       => $label,
        'value'       => (string) $score,
        'ratingLabel' => SPEED_RATING_LABELS[$rating],
        'badgeClass'  => REPORT_SPEED_RATING_BADGES[$rating],
    ];
}

/**
 * Строки таблицы метрик: показатель → ячейка по каждой стратегии (нет значения → null).
 *
 * @param array<string, ?array>  $results стратегия → результат
 * @param string                 $source  `lab` | `field`
 * @param array<string, string>  $metrics показатель → подпись
 * @return list<array{label: string, cells: list<?array{value: string, ratingLabel: string, badgeClass: string}>}>
 */
function buildReportSpeedRows(array $results, string $source, array $metrics): array
{
    $rows = [];
    foreach ($metrics as $metric => $label) {
        $cells = [];
        foreach ($results as $result) {
            $value = $result[$source][$metric] ?? null;
            if (!is_int($value) && !is_float($value)) {
                $cells[] = null;
                continue;
            }
            $rating = rateSpeedMetric($metric, $value);
            $cells[] = [
                'value'       => formatSpeedValue($metric, $value),
                'ratingLabel' => SPEED_RATING_LABELS[$rating],
                'badgeClass'  => REPORT_SPEED_RATING_BADGES[$rating],
            ];
        }
        $rows[] = ['label' => $label, 'cells' => $cells];
    }

    return $rows;
}

/**
 * robots.txt, sitemap.xml и число адресов в sitemap. Шага `seo` не было (старая диагностика) → строк нет.
 *
 * @param mixed $seo `summary_json.seo`: `robots`, `sitemap` (факты SeoCheck без тел ответов)
 * @return list<array{label: string, value: string, badgeClass: ?string}>
 */
function buildReportSeoRows(mixed $seo): array
{
    if (!is_array($seo) || !is_array($seo['robots'] ?? null) || !is_array($seo['sitemap'] ?? null)) {
        return [];
    }

    $robots = $seo['robots'];
    $sitemap = $seo['sitemap'];
    $robotsCode = isset($robots['status']) ? (string) $robots['status'] : 'нет ответа';
    $sitemapCode = isset($sitemap['status']) ? (string) $sitemap['status'] : 'нет ответа';
    $disallow = is_array($robots['disallow_all'] ?? null) ? $robots['disallow_all'] : [];

    $robotsRow = match ($robots['state'] ?? null) {
        'ok'      => $disallow !== []
            ? ['закрыт для ' . implode(', ', array_map(
                static fn (string $agent): string => REPORT_ROBOTS_AGENT_LABELS[$agent] ?? $agent,
                $disallow
            )), REPORT_BADGE_BAD]
            : ["есть ({$robotsCode})", REPORT_BADGE_GOOD],
        'missing' => ["нет ({$robotsCode})", REPORT_BADGE_WARNING],
        'blocked' => [REPORT_SEO_BLOCKED_VALUE, REPORT_BADGE_NEUTRAL],
        default   => ["ошибка ({$robotsCode})", REPORT_BADGE_BAD],
    };

    $sitemapUrl = (string) ($sitemap['url'] ?? '');
    $sitemapRow = match ($sitemap['state'] ?? null) {
        'ok'      => ["{$sitemapUrl} ({$sitemapCode})", null],
        'empty'   => ["{$sitemapUrl} — пустой", REPORT_BADGE_WARNING],
        'missing' => ['не найден', REPORT_BADGE_WARNING],
        'blocked' => [REPORT_SEO_BLOCKED_VALUE, REPORT_BADGE_NEUTRAL],
        default   => ["{$sitemapUrl} — ошибка ({$sitemapCode})", REPORT_BADGE_BAD],
    };

    $rows = [
        ['label' => 'robots.txt', 'value' => $robotsRow[0], 'badgeClass' => $robotsRow[1]],
        ['label' => 'sitemap.xml', 'value' => $sitemapRow[0], 'badgeClass' => $sitemapRow[1]],
    ];

    if (in_array($sitemap['state'] ?? null, ['ok', 'empty'], true)) {
        $rows[] = ['label' => 'Адресов в sitemap', 'value' => buildSitemapCountValue($sitemap), 'badgeClass' => null];
    }

    return $rows;
}

/**
 * «1520», «не меньше 41000 (файл обрезан по размеру)», «индекс из 12 файлов, в первом — 5000 адресов».
 *
 * @param array<string, mixed> $sitemap
 */
function buildSitemapCountValue(array $sitemap): string
{
    $urls = isset($sitemap['urls_count']) ? (int) $sitemap['urls_count'] : null;
    $truncated = !empty($sitemap['truncated']);
    $urlsText = match (true) {
        $urls === null => 'не удалось посчитать',
        $truncated     => "не меньше {$urls} (файл обрезан по размеру)",
        default        => (string) $urls,
    };

    if (empty($sitemap['is_index'])) {
        return $urlsText;
    }

    return 'индекс из ' . (int) ($sitemap['sitemaps_count'] ?? 0) . " файлов, в первом — {$urlsText} адресов";
}

/**
 * @param mixed $ssl факты SslCheck: `error` или срок, издатель, домены
 * @return list<array{label: string, value: string, badgeClass: ?string}>
 */
function buildReportSslRows(mixed $ssl): array
{
    if (!is_array($ssl)) {
        return [['label' => 'SSL-сертификат', 'value' => 'нет данных', 'badgeClass' => REPORT_BADGE_NEUTRAL]];
    }

    $error = (string) ($ssl['error'] ?? '');
    if ($error !== '') {
        return [['label' => 'SSL-сертификат', 'value' => 'не получен: ' . $error, 'badgeClass' => REPORT_BADGE_BAD]];
    }

    $daysLeft = isset($ssl['days_left']) ? (int) $ssl['days_left'] : null;
    $validTo  = (string) ($ssl['valid_to'] ?? '—');

    [$validity, $badge] = match (true) {
        $daysLeft === null                    => ['действует до ' . $validTo, REPORT_BADGE_NEUTRAL],
        $daysLeft < 0                         => ['истёк ' . $validTo, REPORT_BADGE_BAD],
        $daysLeft < REPORT_SSL_WARNING_DAYS   => [
            'действует до ' . $validTo . ' (осталось ' . $daysLeft . ' дн.)',
            REPORT_BADGE_WARNING,
        ],
        default                               => [
            'действует до ' . $validTo . ' (осталось ' . $daysLeft . ' дн.)',
            REPORT_BADGE_GOOD,
        ],
    };

    return [
        ['label' => 'SSL-сертификат', 'value' => $validity, 'badgeClass' => $badge],
        ['label' => 'Кем выдан', 'value' => (string) ($ssl['issuer'] ?? '—'), 'badgeClass' => null],
        buildReportFlagRow('Домен в сертификате', $ssl['domain_match'] ?? null, 'совпадает', 'не совпадает'),
        buildReportFlagRow('Цепочка доверия', $ssl['trusted'] ?? null, 'доверенная', 'не доверенная'),
    ];
}

/**
 * @param array<string, mixed> $variants ключ варианта → сводка ответа
 * @return list<array{label: string, url: string, value: string, badgeClass: string, finalUrl: ?string}>
 */
function buildReportVariants(array $variants): array
{
    $result = [];

    foreach ($variants as $key => $variant) {
        if (!is_array($variant)) {
            continue;
        }

        $row = buildReportResponseRow((string) (REPORT_VARIANT_LABELS[$key] ?? $key), $variant);
        $url = (string) ($variant['url'] ?? '');
        $finalUrl = (string) ($variant['final_url'] ?? '');

        $result[] = [
            'label'      => $row['label'],
            'url'        => $url,
            'value'      => $row['value'],
            'badgeClass' => (string) $row['badgeClass'],
            'finalUrl'   => $finalUrl !== '' && $finalUrl !== $url ? $finalUrl : null,
        ];
    }

    return $result;
}

/**
 * @param list<mixed> $redirects шаги цепочки HttpClient: `url`, `status`, `location`
 * @return list<array{status: string, url: string, location: string}>
 */
function buildReportRedirects(array $redirects): array
{
    $result = [];

    foreach ($redirects as $redirect) {
        if (!is_array($redirect)) {
            continue;
        }
        $result[] = [
            'status'   => (string) ($redirect['status'] ?? '—'),
            'url'      => (string) ($redirect['url'] ?? ''),
            'location' => (string) ($redirect['location'] ?? ''),
        ];
    }

    return $result;
}

/**
 * Проверенные страницы по типам в фиксированном порядке. Не найденная показывается с причиной, только если
 * шаг поиска страниц отработал (`summary_json.pages` есть): у старых диагностик — только найденные.
 *
 * @param list<array<string, mixed>> $pages      строки `checked_pages`
 * @param mixed                      $crawlFacts `summary_json.pages`: `missing` — тип → причина
 * @param array<int, string>         $blocked    id страницы → причина «не удалось проверить» (`buildBlockedPageReasons()`)
 * @param array<string, list<array{id: int, width: int}>> $screenshots адрес страницы → снимки (`buildPageScreenshotMap()`)
 * @param ?string                    $browserNote почему снимков нет (сообщение пропущенного шага `browser`)
 * @return list<array{type: string, label: string, found: bool, url: ?string, status: string, badgeClass: string,
 *                    foundBy: ?string, responseMs: ?int, reason: ?string, blocked: ?string,
 *                    screenshots: list<array>, screenshotsEmpty: string, screenshotsNote: ?string}>
 */
function buildReportPages(
    array $pages,
    mixed $crawlFacts,
    array $blocked = [],
    array $screenshots = [],
    ?string $browserNote = null
): array {
    $byType = [];
    foreach ($pages as $page) {
        if (isset($page['page_type'])) {
            $byType[(string) $page['page_type']] ??= $page;
        }
    }

    $crawled = is_array($crawlFacts);
    $missing = $crawled && is_array($crawlFacts['missing'] ?? null) ? $crawlFacts['missing'] : [];

    $rows = [];
    foreach (REPORT_PAGE_TYPE_LABELS as $type => $label) {
        $page = $byType[$type] ?? null;
        if ($page !== null) {
            $status = isset($page['http_code']) ? (int) $page['http_code'] : null;
            $foundBy = (string) $page['found_by'];
            $rows[] = [
                'type'       => $type,
                'label'      => $label,
                'found'      => true,
                'url'        => (string) $page['url'],
                'status'     => $status !== null ? (string) $status : REPORT_PAGE_NO_RESPONSE,
                'badgeClass' => $status !== null ? httpStatusBadge($status) : REPORT_BADGE_BAD,
                'foundBy'    => REPORT_FOUND_BY_LABELS[$foundBy] ?? $foundBy,
                'responseMs' => isset($page['response_ms']) ? (int) $page['response_ms'] : null,
                'reason'     => null,
                'blocked'    => isset($page['id']) ? ($blocked[(int) $page['id']] ?? null) : null,
                'screenshots'      => buildReportScreenshots(
                    $screenshots[normalizeFindingLocation((string) $page['url'])] ?? [],
                    $type,
                    $label
                ),
                'screenshotsEmpty' => REPORT_SCREENSHOTS_EMPTY,
                'screenshotsNote'  => $browserNote,
            ];
            continue;
        }

        if (!$crawled || $type === 'home') {
            continue;
        }

        $rows[] = [
            'type'       => $type,
            'label'      => $label,
            'found'      => false,
            'url'        => null,
            'status'     => REPORT_PAGE_NOT_FOUND_LABEL,
            'badgeClass' => REPORT_BADGE_WARNING,
            'foundBy'    => null,
            'responseMs' => null,
            'reason'     => is_string($missing[$type] ?? null) ? $missing[$type] : REPORT_PAGE_NOT_FOUND_REASON,
            'blocked'    => null,
            'screenshots'      => [],
            'screenshotsEmpty' => REPORT_SCREENSHOTS_EMPTY,
            'screenshotsNote'  => null,
        ];
    }

    return $rows;
}

/**
 * Снимки по адресу страницы (`normalizeFindingLocation()`): один адрес может быть и главной, и каталогом —
 * снимки сделаны один раз (на первую страницу), а показываются в обеих строках.
 *
 * @param list<array<string, mixed>> $screenshots строки `screenshots` (id, page_id, viewport_width)
 * @param array<int, string>         $pageUrls    id страницы → адрес (`buildPageUrlMap()`)
 * @return array<string, list<array{id: int, width: int}>>
 */
function buildPageScreenshotMap(array $screenshots, array $pageUrls): array
{
    $map = [];
    foreach ($screenshots as $screenshot) {
        $url = $pageUrls[(int) $screenshot['page_id']] ?? null;
        if ($url !== null) {
            $map[normalizeFindingLocation($url)][] = [
                'id'    => (int) $screenshot['id'],
                'width' => (int) $screenshot['viewport_width'],
            ];
        }
    }
    foreach ($map as &$items) {
        usort($items, static fn (array $a, array $b): int => $a['width'] <=> $b['width']);
    }
    unset($items);

    return $map;
}

/**
 * Готовые для View снимки строки страницы: адреса файла и миниатюры, подписи, `alt`, id модального окна.
 *
 * @param list<array{id: int, width: int}> $screenshots
 * @return list<array{width: int, caption: string, url: string, thumbUrl: string, alt: string, thumbAlt: string,
 *                    modalId: string}>
 */
function buildReportScreenshots(array $screenshots, string $type, string $label): array
{
    return array_map(static fn (array $screenshot): array => [
        'width'    => $screenshot['width'],
        'caption'  => $screenshot['width'] . ' px',
        'url'      => sprintf(REPORT_SCREENSHOT_URL, $screenshot['id']),
        'thumbUrl' => sprintf(REPORT_SCREENSHOT_THUMB_URL, $screenshot['id']),
        'alt'      => sprintf(REPORT_SCREENSHOT_ALT, $label, $screenshot['width']),
        'thumbAlt' => sprintf(REPORT_SCREENSHOT_THUMB_ALT, $label, $screenshot['width']),
        'modalId'  => sprintf(REPORT_SCREENSHOT_MODAL_ID, $type, $screenshot['id']),
    ], $screenshots);
}

/**
 * Почему снимков нет: сообщение шага `browser`, если он пропущен или упал; иначе null.
 *
 * @param list<array<string, mixed>> $steps строки `diagnostic_steps`
 */
function findBrowserStepNote(array $steps): ?string
{
    foreach ($steps as $step) {
        if (
            ($step['step_code'] ?? null) === REPORT_BROWSER_STEP_CODE
            && in_array((string) $step['status'], REPORT_UNCHECKED_STEP_STATUSES, true)
        ) {
            return isset($step['message']) && $step['message'] !== '' ? (string) $step['message'] : null;
        }
    }

    return null;
}

/**
 * @param list<array<string, mixed>> $pages
 * @return array<int, string> id страницы → адрес
 */
function buildPageUrlMap(array $pages): array
{
    $map = [];
    foreach ($pages as $page) {
        $map[(int) $page['id']] = (string) $page['url'];
    }

    return $map;
}

/**
 * Группы по серьёзности (critical → info), пустые не выводятся.
 *
 * @param list<array<string, mixed>> $findings
 * @param array<int, string>         $pageUrls
 * @return list<array{severity: string, label: string, badgeClass: string, count: int, items: list<array>}>
 */
function buildReportFindingGroups(array $findings, array $pageUrls): array
{
    $bySeverity = array_fill_keys(array_keys(REPORT_SEVERITY_LABELS), []);

    foreach ($findings as $finding) {
        $severity = (string) $finding['severity'];
        if (!array_key_exists($severity, $bySeverity)) {
            $severity = 'info';
        }
        $bySeverity[$severity][] = buildReportFinding($finding, $pageUrls);
    }

    $groups = [];
    foreach ($bySeverity as $severity => $items) {
        if ($items === []) {
            continue;
        }
        $groups[] = [
            'severity'   => $severity,
            'label'      => REPORT_SEVERITY_LABELS[$severity],
            'badgeClass' => REPORT_SEVERITY_BADGES[$severity],
            'count'      => count($items),
            'items'      => $items,
        ];
    }

    return $groups;
}

/**
 * @param array<string, mixed> $finding
 * @param array<int, string>   $pageUrls
 * @return array{title: string, severityLabel: string, badgeClass: string, categoryLabel: string,
 *               location: ?string, line: ?string, widths: ?string, pageUrl: ?string, pageUrls: list<string>,
 *               details: ?string, evidenceJson: ?string, anchorId: ?string}
 */
function buildReportFinding(array $finding, array $pageUrls): array
{
    $severity = (string) $finding['severity'];
    $category = (string) $finding['category'];
    $location = isset($finding['location']) && $finding['location'] !== '' ? (string) $finding['location'] : null;

    // Найдено на нескольких страницах — список вместо одной строки «Страница:»
    $findingPages = getFindingPageUrls($finding, $pageUrls);
    $manyPages = count($findingPages) > 1;
    $pageUrl = !$manyPages ? ($findingPages[0] ?? null) : null;

    $details  = isset($finding['details']) && $finding['details'] !== '' ? (string) $finding['details'] : null;
    $evidence = $finding['evidence'] ?? null;
    $evidenceJson = is_array($evidence) && $evidence !== [] ? json_encode($evidence, REPORT_JSON_FLAGS) : false;

    return [
        'title'         => (string) $finding['title'],
        'severityLabel' => REPORT_SEVERITY_LABELS[$severity] ?? $severity,
        'badgeClass'    => REPORT_SEVERITY_BADGES[$severity] ?? REPORT_BADGE_NEUTRAL,
        'categoryLabel' => REPORT_FINDING_CATEGORY_LABELS[$category] ?? $category,
        'location'      => $location,
        'line'          => $category === 'js_error' && is_array($evidence) ? formatReportFindingLine($evidence) : null,
        'widths'        => is_array($evidence) ? formatReportFindingWidths($evidence['widths'] ?? null) : null,
        // Страница не повторяется, если находка и есть сама страница (например, «главная не отвечает»)
        'pageUrl'       => $pageUrl !== null && $pageUrl !== $location ? $pageUrl : null,
        'pageUrls'      => $manyPages ? $findingPages : [],
        'details'       => $details,
        'evidenceJson'  => $evidenceJson !== false ? $evidenceJson : null,
        'anchorId'      => isset($finding['id']) ? sprintf(REPORT_FINDING_ANCHOR, (int) $finding['id']) : null,
    ];
}

/**
 * «строка 12, столбец 5» из `evidence` ошибки JS; без строки — null.
 *
 * @param array<string, mixed> $evidence
 */
function formatReportFindingLine(array $evidence): ?string
{
    $line = $evidence['line'] ?? null;
    if (!is_int($line)) {
        return null;
    }
    $column = $evidence['column'] ?? null;

    return 'строка ' . $line . (is_int($column) ? ', столбец ' . $column : '');
}

/**
 * «360, 768 px» из `evidence.widths` (находки из браузера); нет ширин — null.
 */
function formatReportFindingWidths(mixed $widths): ?string
{
    if (!is_array($widths)) {
        return null;
    }
    $widths = array_values(array_filter($widths, 'is_int'));

    return $widths !== [] ? implode(', ', $widths) . ' px' : null;
}

/**
 * Адреса страниц находки: `evidence.pages` (находка с нескольких страниц, таск 4), иначе страница по `page_id`.
 *
 * @param array<string, mixed> $finding
 * @param array<int, string>   $pageUrls id страницы → адрес
 * @return list<string>
 */
function getFindingPageUrls(array $finding, array $pageUrls): array
{
    $evidencePages = is_array($finding['evidence'] ?? null) ? ($finding['evidence']['pages'] ?? null) : null;
    if (is_array($evidencePages)) {
        $urls = array_values(array_unique(array_filter($evidencePages, 'is_string')));
        if ($urls !== []) {
            return $urls;
        }
    }

    $pageId = isset($finding['page_id']) ? (int) $finding['page_id'] : null;

    return $pageId !== null && isset($pageUrls[$pageId]) ? [$pageUrls[$pageId]] : [];
}

/**
 * Причины «не удалось проверить» по страницам с `blocked = 1`: защита и признак из `summary_json.blocked`
 * (у старых записей без сводки — общая формулировка).
 *
 * @param list<array<string, mixed>> $pages
 * @param mixed                      $blockedSummary `summary_json.blocked`: id страницы → {title, reason}
 * @return array<int, string> id страницы → причина
 */
function buildBlockedPageReasons(array $pages, mixed $blockedSummary): array
{
    $summary = is_array($blockedSummary) ? $blockedSummary : [];
    $reasons = [];
    foreach ($pages as $page) {
        if (empty($page['blocked']) || !isset($page['id'])) {
            continue;
        }

        $id = (int) $page['id'];
        $info = is_array($summary[$id] ?? null) ? $summary[$id] : [];
        $title = is_string($info['title'] ?? null) && $info['title'] !== '' ? $info['title'] : REPORT_PAGE_BLOCKED_TITLE;
        $reason = sprintf(REPORT_PAGE_BLOCKED_REASON, $title);
        if (is_string($info['reason'] ?? null) && $info['reason'] !== '') {
            $reason .= sprintf(REPORT_PAGE_BLOCKED_DETAIL, $info['reason']);
        }
        $reasons[$id] = $reason;
    }

    return $reasons;
}

/**
 * @param list<array<string, mixed>> $pages
 * @param array<int, string>         $blocked
 */
function findHomeBlockedReason(array $pages, array $blocked): ?string
{
    foreach ($pages as $page) {
        if (($page['page_type'] ?? null) === 'home' && isset($page['id'])) {
            return $blocked[(int) $page['id']] ?? null;
        }
    }

    return null;
}

/**
 * Находки по страницам: группа на каждый адрес из `checked_pages` (типы — в порядке REPORT_PAGE_TYPE_LABELS,
 * корзина и оформление с одним адресом — одна группа), находка с нескольких страниц — в каждой группе;
 * находки без страницы — в группе «Сайт целиком» (если есть). Внутри группы — от критичных к информации.
 *
 * @param list<array<string, mixed>> $findings отсортированы по серьёзности (getFindingsByDiagnostic())
 * @param list<array<string, mixed>> $pages
 * @param array<int, string>         $blocked  id страницы → причина «не удалось проверить»
 * @return list<array{title: string, url: ?string, blocked: ?string, count: int, items: list<array>}>
 */
function buildReportFindingsByPage(array $findings, array $pages, array $blocked = []): array
{
    $typeOrder = array_flip(array_keys(REPORT_PAGE_TYPE_LABELS));
    usort(
        $pages,
        static fn (array $a, array $b): int => [$typeOrder[$a['page_type'] ?? ''] ?? PHP_INT_MAX, (int) ($a['id'] ?? 0)]
            <=> [$typeOrder[$b['page_type'] ?? ''] ?? PHP_INT_MAX, (int) ($b['id'] ?? 0)]
    );

    $groups = [];
    foreach ($pages as $page) {
        $url = (string) $page['url'];
        $type = (string) ($page['page_type'] ?? '');
        $label = REPORT_PAGE_TYPE_LABELS[$type] ?? null;
        $reason = isset($page['id']) ? ($blocked[(int) $page['id']] ?? null) : null;

        if (!isset($groups[$url])) {
            $groups[$url] = ['labels' => [], 'url' => $url, 'blocked' => null, 'items' => []];
        }
        if ($label !== null && !in_array($label, $groups[$url]['labels'], true)) {
            $groups[$url]['labels'][] = $label;
        }
        $groups[$url]['blocked'] ??= $reason;
    }

    $pageUrls = buildPageUrlMap($pages);
    $noPage = [];
    foreach ($findings as $finding) {
        $item = buildReportFinding($finding, $pageUrls);
        $placed = false;
        foreach (getFindingPageUrls($finding, $pageUrls) as $url) {
            if (isset($groups[$url])) {
                $groups[$url]['items'][] = $item;
                $placed = true;
            }
        }
        if (!$placed) {
            $noPage[] = $item;
        }
    }

    $result = [];
    foreach ($groups as $group) {
        $result[] = [
            'title'   => $group['labels'] !== [] ? implode(REPORT_PAGE_TYPES_SEPARATOR, $group['labels']) : $group['url'],
            'url'     => $group['url'],
            'blocked' => $group['blocked'],
            'count'   => count($group['items']),
            'items'   => $group['items'],
        ];
    }
    if ($noPage !== []) {
        $result[] = [
            'title'   => REPORT_NO_PAGE_GROUP_TITLE,
            'url'     => null,
            'blocked' => null,
            'count'   => count($noPage),
            'items'   => $noPage,
        ];
    }

    return $result;
}

/**
 * Группы стека в порядке REPORT_STACK_CATEGORY_LABELS; неизвестная категория — в «Другое».
 *
 * @param list<array<string, mixed>> $stackItems
 * @return list<array{category: string, label: string, items: list<array>}>
 */
function buildReportStackGroups(array $stackItems, array $outdated = []): array
{
    $byCategory = array_fill_keys(array_keys(REPORT_STACK_CATEGORY_LABELS), []);

    foreach ($stackItems as $item) {
        $category = (string) $item['category'];
        if (!array_key_exists($category, $byCategory)) {
            $category = 'other';
        }
        $byCategory[$category][] = buildReportStackItem($item, $outdated[(int) ($item['id'] ?? 0)] ?? null);
    }

    $groups = [];
    foreach ($byCategory as $category => $items) {
        if ($items !== []) {
            $groups[] = ['category' => $category, 'label' => REPORT_STACK_CATEGORY_LABELS[$category], 'items' => $items];
        }
    }

    return $groups;
}

/**
 * @param array<string, mixed> $item
 * @param bool|null            $unsupported находка `outdated` по элементу: true — ветка без поддержки,
 *                                          false — устарела, null — находки нет
 * @return array{name: string, version: ?string, latestVersion: ?string, statusLabel: ?string, statusBadge: ?string,
 *               confidenceLabel: string, badgeClass: string, evidence: list<array{source: string, detail: string}>,
 *               anchorId: ?string}
 */
function buildReportStackItem(array $item, ?bool $unsupported = null): array
{
    $confidence = (string) $item['confidence'];
    $version    = isset($item['version']) && $item['version'] !== '' ? (string) $item['version'] : null;

    $evidence = [];
    // В БД evidence — объект «ключ → признак» (дедупликация в StackDetector), порядок сохраняется
    foreach (is_array($item['evidence'] ?? null) ? $item['evidence'] : [] as $signal) {
        if (!is_array($signal)) {
            continue;
        }
        $source = (string) ($signal['source'] ?? '');
        $evidence[] = [
            'source' => REPORT_EVIDENCE_SOURCE_LABELS[$source] ?? $source,
            'detail' => (string) ($signal['detail'] ?? ''),
        ];
    }

    return [
        'name'            => (string) $item['name'],
        'version'         => $version,
        'latestVersion'   => isset($item['latest_version']) && $item['latest_version'] !== ''
            ? (string) $item['latest_version']
            : null,
        'statusLabel'     => match ($unsupported) {
            true    => REPORT_UNSUPPORTED_LABEL,
            false   => REPORT_OUTDATED_LABEL,
            default => null,
        },
        'statusBadge'     => match ($unsupported) {
            true    => REPORT_UNSUPPORTED_BADGE,
            false   => REPORT_OUTDATED_BADGE,
            default => null,
        },
        'confidenceLabel' => REPORT_CONFIDENCE_LABELS[$confidence] ?? $confidence,
        'badgeClass'      => REPORT_CONFIDENCE_BADGES[$confidence] ?? REPORT_BADGE_NEUTRAL,
        'evidence'        => $evidence,
        'anchorId'        => isset($item['id']) ? sprintf(REPORT_STACK_ANCHOR, (int) $item['id']) : null,
    ];
}

/**
 * id элемента стека → ветка без поддержки (true) / устарела (false) — по находкам `outdated`.
 *
 * @param list<array<string, mixed>> $findings
 * @return array<int, bool>
 */
function buildOutdatedStackMap(array $findings): array
{
    $map = [];
    foreach ($findings as $finding) {
        $evidence = is_array($finding['evidence'] ?? null) ? $finding['evidence'] : [];
        if (($finding['category'] ?? null) === 'outdated' && isset($evidence['stack_item_id'])) {
            $map[(int) $evidence['stack_item_id']] = !empty($evidence['unsupported']);
        }
    }

    return $map;
}

/**
 * Базы, которыми пользовалась диагностика (`summary_json.databases`: ключ → название, дата): дата для экрана и
 * признак «старше REPORT_DATABASE_STALE_DAYS на момент диагностики». Нет шага `outdated` → пусто.
 *
 * @return list<array{title: string, date: string, stale: bool}>
 */
function buildReportDatabases(mixed $databases, mixed $diagnosticCreatedAt): array
{
    $at = is_string($diagnosticCreatedAt) ? strtotime($diagnosticCreatedAt) : false;
    $at = $at !== false ? $at : time();

    $rows = [];
    foreach (is_array($databases) ? $databases : [] as $database) {
        if (!is_array($database)) {
            continue;
        }
        $updated = is_string($database['updated_at'] ?? null) ? strtotime($database['updated_at']) : false;
        $rows[] = [
            'title' => (string) ($database['title'] ?? ''),
            'date'  => $updated !== false ? date('d.m.Y', $updated) : REPORT_DATABASE_NO_DATE,
            'stale' => $updated === false || ($at - $updated) > REPORT_DATABASE_STALE_DAYS * REPORT_SECONDS_PER_DAY,
        ];
    }

    return $rows;
}

/**
 * «Не удалось проверить»: шаги `failed` / `skipped`; при частичном отчёте — и незавершённые.
 *
 * @param list<array<string, mixed>> $steps строки `diagnostic_steps`
 * @return list<array{title: string, statusLabel: string, badgeClass: string, message: ?string}>
 */
function buildReportUncheckedSteps(array $steps, bool $partial): array
{
    $statuses = $partial
        ? [...REPORT_UNCHECKED_STEP_STATUSES, ...REPORT_UNFINISHED_STEP_STATUSES]
        : REPORT_UNCHECKED_STEP_STATUSES;

    $result = [];
    foreach ($steps as $step) {
        if (!in_array((string) $step['status'], $statuses, true)) {
            continue;
        }

        $status  = buildStepStatus($step);
        $message = $status['message'];
        if ($message === null && in_array($status['status'], REPORT_UNFINISHED_STEP_STATUSES, true)) {
            $message = REPORT_UNFINISHED_STEP_MESSAGE;
        }

        $result[] = [
            'title'       => $status['title'],
            'statusLabel' => $status['statusLabel'],
            'badgeClass'  => $status['badgeClass'],
            'message'     => $message,
        ];
    }

    return $result;
}

/**
 * @param array<string, mixed> $finding
 */
function isReportAiFinding(array $finding): bool
{
    return ($finding['source'] ?? FINDING_SOURCE_CHECK) === FINDING_SOURCE_AI;
}

/**
 * Блок «Разбор жалобы (ИИ)». Нет шага `ai_problem` (диагностика до фазы 4) → null, карточки нет.
 * Шаг `done` — гипотезы последнего ответа со ссылками на факты; `failed` — ошибка; `skipped` / не выполнен —
 * причина.
 *
 * @param array<string, mixed>       $diagnostic строка `diagnostics` + `steps`
 * @param list<array<string, mixed>> $aiFindings находки `source = ai`
 * @param list<array<string, mixed>> $findings   находки проверок (на них ведут ссылки)
 * @param list<array<string, mixed>> $stackItems
 * @param list<array<string, mixed>> $aiResults
 * @return array{state: string, message: ?string, complaint: ?string, hypotheses: list<array>, rejected: int,
 *               meta: ?array}|null
 */
function buildReportAiProblem(
    array $diagnostic,
    array $aiFindings,
    array $findings,
    array $stackItems,
    array $aiResults
): ?array {
    $step = null;
    foreach ($diagnostic['steps'] ?? [] as $candidate) {
        if (($candidate['step_code'] ?? null) === REPORT_AI_PROBLEM_STEP_CODE) {
            $step = $candidate;
        }
    }
    if ($step === null) {
        return null;
    }

    $aiResult = findLatestAiResult($aiResults, REPORT_AI_PROBLEM_TASK);
    $state = match ((string) $step['status']) {
        REPORT_AI_STATE_DONE   => REPORT_AI_STATE_DONE,
        REPORT_AI_STATE_FAILED => REPORT_AI_STATE_FAILED,
        default                => REPORT_AI_STATE_SKIPPED,
    };
    $message = isset($step['message']) && $step['message'] !== '' ? (string) $step['message'] : null;
    if ($state === REPORT_AI_STATE_FAILED && ($aiResult['status'] ?? null) === REPORT_AI_RESULT_ERROR) {
        $message = (string) ($aiResult['error_message'] ?? $message);
    }

    $hypotheses = [];
    if ($state === REPORT_AI_STATE_DONE) {
        $resultId = $aiResult !== null ? (int) $aiResult['id'] : null;
        $targets = buildReportEvidenceTargets($findings, $stackItems);
        foreach ($aiFindings as $finding) {
            $fromResult = $resultId === null || (int) ($finding['ai_result_id'] ?? 0) === $resultId;
            if (($finding['category'] ?? null) === REPORT_AI_HYPOTHESIS_CATEGORY && $fromResult) {
                $hypotheses[] = buildReportHypothesis($finding, $targets);
            }
        }
    }
    $complaint = trim((string) ($diagnostic['problem_text'] ?? ''));
    $parsed = is_array($aiResult['parsed_json'] ?? null) ? $aiResult['parsed_json'] : [];

    return [
        'state'      => $state,
        'message'    => $state === REPORT_AI_STATE_DONE ? null : ($message ?? REPORT_AI_NOT_RUN_MESSAGE),
        'complaint'  => $complaint !== '' ? $complaint : null,
        'hypotheses' => $hypotheses,
        'rejected'   => is_int($parsed['rejected'] ?? null) ? $parsed['rejected'] : 0,
        'meta'       => $aiResult !== null ? buildReportAiMeta($aiResult) : null,
    ];
}

/**
 * Последний ответ ИИ по задаче (после перезапуска их несколько).
 *
 * @param list<array<string, mixed>> $aiResults по порядку записи
 * @return array<string, mixed>|null
 */
function findLatestAiResult(array $aiResults, string $task): ?array
{
    $latest = null;
    foreach ($aiResults as $aiResult) {
        if (($aiResult['task'] ?? null) === $task) {
            $latest = $aiResult;
        }
    }

    return $latest;
}

/**
 * Куда ведут ссылки: `finding_ids` → заголовок находки, `stack_ids` → название и версия.
 *
 * @param list<array<string, mixed>> $findings
 * @param list<array<string, mixed>> $stackItems
 * @return array{finding_ids: array<int, array{href: string, label: string}>,
 *               stack_ids: array<int, array{href: string, label: string}>}
 */
function buildReportEvidenceTargets(array $findings, array $stackItems): array
{
    $targets = ['finding_ids' => [], 'stack_ids' => []];
    foreach ($findings as $finding) {
        if (isset($finding['id'])) {
            $id = (int) $finding['id'];
            $targets['finding_ids'][$id] = [
                'href'  => '#' . sprintf(REPORT_FINDING_ANCHOR, $id),
                'label' => (string) $finding['title'],
            ];
        }
    }
    foreach ($stackItems as $item) {
        if (isset($item['id'])) {
            $id = (int) $item['id'];
            $version = isset($item['version']) && $item['version'] !== '' ? ' ' . $item['version'] : '';
            $targets['stack_ids'][$id] = [
                'href'  => '#' . sprintf(REPORT_STACK_ANCHOR, $id),
                'label' => (string) $item['name'] . $version,
            ];
        }
    }

    return $targets;
}

/**
 * @param array<string, mixed>                                          $finding находка `hypothesis`
 * @param array<string, array<int, array{href: string, label: string}>> $targets
 * @return array{title: string, severityLabel: string, badgeClass: string, details: ?string,
 *               references: list<array{href: string, label: string}>}
 */
function buildReportHypothesis(array $finding, array $targets): array
{
    $severity = (string) $finding['severity'];
    $evidence = is_array($finding['evidence'] ?? null) ? $finding['evidence'] : [];

    $references = [];
    foreach (REPORT_AI_EVIDENCE_KEYS as $key) {
        foreach (is_array($evidence[$key] ?? null) ? $evidence[$key] : [] as $id) {
            // Находку или элемент стека могли удалить: ссылки в никуда не выводятся
            if (is_int($id) && isset($targets[$key][$id])) {
                $references[] = $targets[$key][$id];
            }
        }
    }

    return [
        'title'         => (string) $finding['title'],
        'severityLabel' => REPORT_SEVERITY_LABELS[$severity] ?? $severity,
        'badgeClass'    => REPORT_SEVERITY_BADGES[$severity] ?? REPORT_BADGE_NEUTRAL,
        'details'       => isset($finding['details']) && $finding['details'] !== '' ? (string) $finding['details'] : null,
        'references'    => $references,
    ];
}

/**
 * Подпись под ИИ-блоком: модель, дата, токены.
 *
 * @param array<string, mixed> $aiResult строка `ai_results`
 * @return array{model: string, date: ?string, tokensIn: string, tokensOut: string}
 */
function buildReportAiMeta(array $aiResult): array
{
    $created = is_string($aiResult['created_at'] ?? null) ? strtotime($aiResult['created_at']) : false;

    return [
        'model'     => (string) $aiResult['model'],
        'date'      => $created !== false ? date(REPORT_AI_DATE_FORMAT, $created) : null,
        'tokensIn'  => isset($aiResult['tokens_in']) ? (string) $aiResult['tokens_in'] : REPORT_AI_NO_TOKENS,
        'tokensOut' => isset($aiResult['tokens_out']) ? (string) $aiResult['tokens_out'] : REPORT_AI_NO_TOKENS,
    ];
}
