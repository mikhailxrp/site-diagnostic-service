<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/src/Services/ReportBuilder.php';

final class ReportBuilderTest extends TestCase
{
    private const HOME_PAGE_ID = 7;
    private const HOME_URL     = 'https://shop.ru/';

    public function testFindingsAreGroupedBySeverityInOrderWithCounters(): void
    {
        $report = buildReport($this->diagnostic(), [
            $this->finding('low', 'Низкая 1'),
            $this->finding('critical', 'Критичная'),
            $this->finding('info', 'Инфо'),
            $this->finding('low', 'Низкая 2'),
        ], [], []);

        $this->assertSame(4, $report['findingCount']);
        $this->assertSame(['critical', 'low', 'info'], array_column($report['findingGroups'], 'severity'));
        $this->assertSame([1, 2, 1], array_column($report['findingGroups'], 'count'));
        $this->assertSame(['Низкая 1', 'Низкая 2'], array_column($report['findingGroups'][1]['items'], 'title'));
        $this->assertSame('Критично', $report['findingGroups'][0]['label']);
    }

    public function testFindingShowsLocationPageAndEvidence(): void
    {
        $finding = $this->finding('medium', 'Битый CSS', [
            'category' => 'broken_resource',
            'location' => 'https://shop.ru/style.css',
            'page_id'  => self::HOME_PAGE_ID,
            'details'  => 'Ответ 404',
            'evidence' => ['status' => 404, 'type' => 'style'],
        ]);

        $report = buildReport($this->diagnostic(), [$finding], [], [$this->homePage()]);
        $item = $report['findingGroups'][0]['items'][0];

        $this->assertSame('https://shop.ru/style.css', $item['location']);
        $this->assertSame(self::HOME_URL, $item['pageUrl']);
        $this->assertSame('Битый ресурс', $item['categoryLabel']);
        $this->assertSame('Ответ 404', $item['details']);
        $this->assertStringContainsString('"status": 404', (string) $item['evidenceJson']);
    }

    public function testPageIsNotRepeatedWhenFindingIsThePageItself(): void
    {
        $finding = $this->finding('critical', 'Главная не отвечает', [
            'location' => self::HOME_URL,
            'page_id'  => self::HOME_PAGE_ID,
        ]);

        $item = buildReport($this->diagnostic(), [$finding], [], [$this->homePage()])['findingGroups'][0]['items'][0];

        $this->assertNull($item['pageUrl']);
        $this->assertNull($item['evidenceJson']);
    }

    public function testEmptyReportHasNoGroups(): void
    {
        $report = buildReport($this->diagnostic(), [], [], []);

        $this->assertSame(0, $report['findingCount']);
        $this->assertSame([], $report['findingGroups']);
        $this->assertSame([], $report['stackGroups']);
        $this->assertSame([], $report['uncheckedSteps']);
        $this->assertFalse($report['partial']);
    }

    public function testStackIsGroupedByCategoryWithEvidenceSources(): void
    {
        $items = [
            [
                'category'   => 'js_library',
                'name'       => 'jQuery',
                'version'    => '3.6.0',
                'confidence' => 'medium',
                'evidence'   => ['url:/jquery.js' => ['source' => 'url', 'detail' => '/jquery-3.6.0.min.js']],
            ],
            [
                'category'   => 'cms',
                'name'       => 'WordPress',
                'version'    => null,
                'confidence' => 'high',
                'evidence'   => [
                    'meta:gen'   => ['source' => 'meta', 'detail' => 'generator: WordPress'],
                    'html:path'  => ['source' => 'html', 'detail' => '/wp-content/'],
                ],
            ],
        ];

        $groups = buildReport($this->diagnostic(), [], $items, [])['stackGroups'];

        $this->assertSame(['cms', 'js_library'], array_column($groups, 'category'));
        $wordpress = $groups[0]['items'][0];
        $this->assertNull($wordpress['version']);
        $this->assertSame('Уверенно', $wordpress['confidenceLabel']);
        $this->assertSame(
            [
                ['source' => 'Мета-тег', 'detail' => 'generator: WordPress'],
                ['source' => 'HTML страницы', 'detail' => '/wp-content/'],
            ],
            $wordpress['evidence']
        );
        $this->assertSame('3.6.0', $groups[1]['items'][0]['version']);
    }

    public function testFailedAndSkippedStepsAreUnchecked(): void
    {
        $diagnostic = $this->diagnostic('done', [
            $this->step('availability', 'done'),
            $this->step('stack', 'failed', 'Таймаут'),
            $this->step('resources', 'skipped', 'Пропущено: шаг остановил проверку.'),
        ]);

        $unchecked = buildReport($diagnostic, [], [], [])['uncheckedSteps'];

        $this->assertSame(['Шаг stack', 'Шаг resources'], array_column($unchecked, 'title'));
        $this->assertSame('Таймаут', $unchecked[0]['message']);
        $this->assertSame('Ошибка', $unchecked[0]['statusLabel']);
        $this->assertSame('Пропущен', $unchecked[1]['statusLabel']);
    }

    public function testFailedDiagnosticGivesPartialReportWithUnfinishedSteps(): void
    {
        $diagnostic = $this->diagnostic('failed', [
            $this->step('availability', 'done'),
            $this->step('stack', 'running'),
            $this->step('resources', 'pending'),
        ]);
        $finding = $this->finding('high', 'SSL истекает');

        $report = buildReport($diagnostic, [$finding], [], []);

        $this->assertTrue($report['partial']);
        $this->assertSame(1, $report['findingCount']);
        $this->assertSame(['Шаг stack', 'Шаг resources'], array_column($report['uncheckedSteps'], 'title'));
        $this->assertSame(REPORT_UNFINISHED_STEP_MESSAGE, $report['uncheckedSteps'][1]['message']);
    }

    public function testDoneDiagnosticDoesNotListPendingSteps(): void
    {
        $diagnostic = $this->diagnostic('done', [$this->step('stack', 'pending')]);

        $this->assertSame([], buildReport($diagnostic, [], [], [])['uncheckedSteps']);
    }

    public function testPagesAreListedInOrderWithMissingReasons(): void
    {
        $diagnostic = $this->diagnostic();
        $diagnostic['summary_json'] = json_encode(['pages' => ['missing' => [
            'cart'     => 'типовые адреса не подошли (проверено: 6), ссылок на корзину нет',
            'checkout' => 'ответ 403 — возможна защита от ботов',
        ]]]);
        $pages = [
            ['id' => 2, 'url' => 'https://shop.ru/catalog/item/', 'page_type' => 'product', 'found_by' => 'manual',
                'http_code' => 404, 'response_ms' => 80],
            ['id' => 1, 'url' => self::HOME_URL, 'page_type' => 'home', 'found_by' => 'start',
                'http_code' => 200, 'response_ms' => 120],
            ['id' => 3, 'url' => 'https://shop.ru/catalog/', 'page_type' => 'category', 'found_by' => 'cms_pattern',
                'http_code' => 200, 'response_ms' => 90],
        ];

        $rows = buildReport($diagnostic, [], [], $pages)['pages'];

        $this->assertSame(['home', 'category', 'product', 'cart', 'checkout'], array_column($rows, 'type'));
        $this->assertSame([true, true, true, false, false], array_column($rows, 'found'));
        $this->assertSame('указана вручную', $rows[2]['foundBy']);
        $this->assertSame('404', $rows[2]['status']);
        $this->assertSame(REPORT_BADGE_BAD, $rows[2]['badgeClass']);
        $this->assertSame('по шаблону CMS', $rows[1]['foundBy']);
        $this->assertSame(REPORT_PAGE_NOT_FOUND_LABEL, $rows[3]['status']);
        $this->assertStringContainsString('ссылок на корзину нет', (string) $rows[3]['reason']);
        $this->assertStringContainsString('защита от ботов', (string) $rows[4]['reason']);
    }

    public function testOldDiagnosticWithoutCrawlShowsOnlyFoundPages(): void
    {
        $pages = [['id' => 1, 'url' => self::HOME_URL, 'page_type' => 'home', 'found_by' => 'start',
            'http_code' => 200, 'response_ms' => 120]];

        $rows = buildReport($this->diagnostic(), [], [], $pages)['pages'];

        $this->assertSame(['home'], array_column($rows, 'type'));
        $this->assertSame([], buildReport($this->diagnostic(), [], [], [])['pages']);
    }

    public function testBlockedPageIsMarkedWithProtectionName(): void
    {
        $diagnostic = $this->diagnostic();
        $diagnostic['summary_json'] = json_encode([
            'pages'   => ['missing' => []],
            'blocked' => ['2' => ['title' => 'Cloudflare', 'reason' => 'заголовок cf-mitigated: challenge']],
        ]);
        $pages = [
            ['id' => 1, 'url' => self::HOME_URL, 'page_type' => 'home', 'found_by' => 'start',
                'http_code' => 200, 'response_ms' => 120, 'blocked' => 0],
            ['id' => 2, 'url' => 'https://shop.ru/item/', 'page_type' => 'product', 'found_by' => 'manual',
                'http_code' => 403, 'response_ms' => 80, 'blocked' => 1],
            ['id' => 3, 'url' => 'https://shop.ru/cat/', 'page_type' => 'category', 'found_by' => 'cms_pattern',
                'http_code' => 503, 'response_ms' => 80, 'blocked' => 1],
        ];

        $report = buildReport($diagnostic, [], [], $pages);
        $rows = array_column($report['pages'], null, 'type');

        $this->assertNull($rows['home']['blocked']);
        $this->assertStringContainsString('Cloudflare', (string) $rows['product']['blocked']);
        $this->assertStringContainsString('cf-mitigated', (string) $rows['product']['blocked']);
        // Старая запись без сводки — общая формулировка
        $this->assertStringContainsString(REPORT_PAGE_BLOCKED_TITLE, (string) $rows['category']['blocked']);
        $this->assertSame(REPORT_PAGE_BLOCKED_LABEL, $report['blockedBadge']['label']);
    }

    public function testHomeBlockedReplacesPossibleProtectionWarning(): void
    {
        $diagnostic = $this->diagnostic();
        $diagnostic['summary_json'] = json_encode([
            'availability' => ['home' => ['url' => self::HOME_URL, 'final_url' => self::HOME_URL, 'status' => 403,
                'possible_bot_protection' => true, 'truncated' => false]],
            'blocked'      => ['1' => ['title' => 'DDoS-Guard', 'reason' => 'x']],
        ]);
        $pages = [['id' => 1, 'url' => self::HOME_URL, 'page_type' => 'home', 'found_by' => 'start',
            'http_code' => 403, 'response_ms' => 120, 'blocked' => 1]];

        $warnings = buildReport($diagnostic, [], [], $pages)['summary']['warnings'];

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('DDoS-Guard', $warnings[0]);
    }

    public function testJsErrorShowsLineAndWidths(): void
    {
        $finding = $this->finding('medium', 'Ошибка JS: Uncaught TypeError', [
            'category' => 'js_error',
            'location' => 'https://shop.ru/js/app.js',
            'page_id'  => self::HOME_PAGE_ID,
            'evidence' => ['line' => 42, 'column' => 15, 'widths' => [360, 1366]],
        ]);
        $phpError = $this->finding('high', 'Fatal error', [
            'category' => 'php_error',
            'location' => self::HOME_URL,
            'page_id'  => self::HOME_PAGE_ID,
            'evidence' => ['line' => 7, 'file' => '/var/www/index.php'],
        ]);

        $report = buildReport($this->diagnostic(), [$finding, $phpError], [], [$this->homePage()]);
        $items = [];
        foreach ($report['findingGroups'] as $group) {
            foreach ($group['items'] as $item) {
                $items[$item['title']] = $item;
            }
        }

        $this->assertSame('строка 42, столбец 15', $items['Ошибка JS: Uncaught TypeError']['line']);
        $this->assertSame('360, 1366 px', $items['Ошибка JS: Uncaught TypeError']['widths']);
        // Строка PHP-ошибки — внутри сервера, а «Где» у неё — страница: не подписывается
        $this->assertNull($items['Fatal error']['line']);
        $this->assertNull($items['Fatal error']['widths']);
    }

    public function testFindingFromSeveralPagesListsThem(): void
    {
        $urls = [self::HOME_URL, 'https://shop.ru/cat/'];
        $finding = $this->finding('medium', 'Битая картинка', [
            'category' => 'broken_resource',
            'location' => 'https://shop.ru/a.png',
            'page_id'  => self::HOME_PAGE_ID,
            'evidence' => ['status' => 404, 'pages' => $urls],
        ]);
        $single = $this->finding('medium', 'Одна страница', [
            'location' => 'https://shop.ru/b.png',
            'page_id'  => self::HOME_PAGE_ID,
            'evidence' => ['status' => 404, 'pages' => [self::HOME_URL]],
        ]);

        $items = buildReport($this->diagnostic(), [$finding, $single], [], [$this->homePage()])['findingGroups'][0]['items'];

        $this->assertSame($urls, $items[0]['pageUrls']);
        $this->assertNull($items[0]['pageUrl']);
        $this->assertSame([], $items[1]['pageUrls']);
        $this->assertSame(self::HOME_URL, $items[1]['pageUrl']);
    }

    public function testFindingsAreGroupedByPage(): void
    {
        $pages = [
            ['id' => 4, 'url' => 'https://shop.ru/cart/', 'page_type' => 'checkout', 'found_by' => 'cms_pattern', 'blocked' => 0],
            ['id' => 3, 'url' => 'https://shop.ru/cart/', 'page_type' => 'cart', 'found_by' => 'cms_pattern', 'blocked' => 0],
            ['id' => 2, 'url' => 'https://shop.ru/cat/', 'page_type' => 'category', 'found_by' => 'cms_pattern', 'blocked' => 1],
            ['id' => 1, 'url' => self::HOME_URL, 'page_type' => 'home', 'found_by' => 'start', 'blocked' => 0],
        ];
        $findings = [
            $this->finding('high', 'На двух страницах', ['page_id' => 1,
                'evidence' => ['pages' => [self::HOME_URL, 'https://shop.ru/cart/']]]),
            $this->finding('medium', 'Только главная', ['page_id' => 1]),
            $this->finding('low', 'SSL', ['category' => 'ssl']),
            // Старая находка без evidence.pages — по page_id
            $this->finding('low', 'Корзина', ['page_id' => 3, 'evidence' => ['status' => 404]]),
        ];

        $groups = buildReport($this->diagnostic(), $findings, [], $pages)['findingPageGroups'];

        $this->assertSame(
            ['Главная', 'Категория', 'Корзина, Оформление заказа', REPORT_NO_PAGE_GROUP_TITLE],
            array_column($groups, 'title')
        );
        $this->assertSame(['На двух страницах', 'Только главная'], array_column($groups[0]['items'], 'title'));
        $this->assertSame(0, $groups[1]['count']);
        $this->assertNotNull($groups[1]['blocked']);
        $this->assertSame(['На двух страницах', 'Корзина'], array_column($groups[2]['items'], 'title'));
        $this->assertSame(['SSL'], array_column($groups[3]['items'], 'title'));
        $this->assertNull($groups[3]['url']);
    }

    public function testNoPagesGivesOnlySiteWideGroup(): void
    {
        $groups = buildReport($this->diagnostic(), [$this->finding('high', 'Нет ответа')], [], [])['findingPageGroups'];

        $this->assertSame([REPORT_NO_PAGE_GROUP_TITLE], array_column($groups, 'title'));
        $this->assertSame([], buildReport($this->diagnostic(), [], [], [])['findingPageGroups']);
    }

    public function testSeoRowsAreShownOnlyAfterSeoStep(): void
    {
        $availability = ['home' => ['url' => self::HOME_URL, 'final_url' => self::HOME_URL, 'status' => 200]];
        $diagnostic = $this->diagnostic();
        $diagnostic['summary_json'] = json_encode(['availability' => $availability]);

        $labels = array_column(buildReport($diagnostic, [], [], [])['summary']['rows'], 'label');
        $this->assertNotContains('robots.txt', $labels);

        $diagnostic['summary_json'] = json_encode(['availability' => $availability, 'seo' => [
            'robots'  => ['url' => self::HOME_URL . 'robots.txt', 'status' => 200, 'state' => 'ok',
                'disallow_all' => ['*', 'yandex'], 'sitemaps' => []],
            'sitemap' => ['url' => self::HOME_URL . 'sitemap.xml', 'status' => 200, 'state' => 'ok',
                'is_index' => true, 'sitemaps_count' => 12, 'urls_count' => 5000, 'truncated' => false],
        ]]);
        $rows = array_column(buildReport($diagnostic, [], [], [])['summary']['rows'], null, 'label');

        $this->assertSame('закрыт для всех роботов, Яндекса', $rows['robots.txt']['value']);
        $this->assertSame(REPORT_BADGE_BAD, $rows['robots.txt']['badgeClass']);
        $this->assertSame(self::HOME_URL . 'sitemap.xml (200)', $rows['sitemap.xml']['value']);
        $this->assertSame('индекс из 12 файлов, в первом — 5000 адресов', $rows['Адресов в sitemap']['value']);
    }

    public function testSeoRowsForMissingBlockedAndTruncated(): void
    {
        $rows = buildReportSeoRows([
            'robots'  => ['status' => 404, 'state' => 'missing', 'disallow_all' => []],
            'sitemap' => ['url' => self::HOME_URL . 'sitemap.xml', 'status' => 404, 'state' => 'missing'],
        ]);
        $this->assertSame(['нет (404)', 'не найден'], array_column($rows, 'value'));

        $rows = buildReportSeoRows([
            'robots'  => ['status' => 403, 'state' => 'blocked'],
            'sitemap' => ['url' => self::HOME_URL . 's.xml', 'status' => 200, 'state' => 'ok', 'is_index' => false,
                'urls_count' => 41000, 'truncated' => true],
        ]);
        $this->assertSame(REPORT_SEO_BLOCKED_VALUE, $rows[0]['value']);
        $this->assertSame('не меньше 41000 (файл обрезан по размеру)', $rows[2]['value']);
        $this->assertSame([], buildReportSeoRows(['robots' => 'битое']));
    }

    public function testStackItemShowsLatestVersionAndOutdatedBadge(): void
    {
        $stack = [
            ['id' => 5, 'category' => 'js_library', 'name' => 'jQuery', 'version' => '1.12.4', 'latest_version' => '4.0.0',
                'confidence' => 'high', 'evidence' => []],
            ['id' => 6, 'category' => 'js_library', 'name' => 'Swiper', 'version' => '11.0.0', 'latest_version' => '14.4.0',
                'confidence' => 'high', 'evidence' => []],
            ['id' => 7, 'category' => 'js_library', 'name' => 'Lodash', 'version' => '4.18.1', 'latest_version' => '4.18.1',
                'confidence' => 'high', 'evidence' => []],
            ['id' => 8, 'category' => 'js_library', 'name' => 'Vue.js', 'version' => null, 'latest_version' => null,
                'confidence' => 'low', 'evidence' => []],
        ];
        $findings = [
            $this->finding('medium', 'jQuery', ['category' => 'outdated', 'evidence' => ['stack_item_id' => 5, 'unsupported' => true]]),
            $this->finding('low', 'Swiper', ['category' => 'outdated', 'evidence' => ['stack_item_id' => 6, 'unsupported' => false]]),
        ];

        $items = buildReport($this->diagnostic(), $findings, $stack, [])['stackGroups'][0]['items'];

        $this->assertSame([REPORT_UNSUPPORTED_LABEL, REPORT_OUTDATED_LABEL, null, null], array_column($items, 'statusLabel'));
        $this->assertSame(['4.0.0', '14.4.0', '4.18.1', null], array_column($items, 'latestVersion'));
        $this->assertSame(REPORT_UNSUPPORTED_BADGE, $items[0]['statusBadge']);
    }

    public function testDatabasesDateAndStaleWarning(): void
    {
        $diagnostic = $this->diagnostic();
        $diagnostic['created_at'] = '2026-11-16 12:00:00';
        $diagnostic['summary_json'] = json_encode(['databases' => [
            'latest_versions' => ['title' => 'База версий', 'updated_at' => '2026-11-10T10:00:00+03:00'],
        ]]);

        $report = buildReport($diagnostic, [], [], []);
        $this->assertSame([['title' => 'База версий', 'date' => '10.11.2026', 'stale' => false]], $report['databases']);
        $this->assertFalse($report['databasesStale']);

        $diagnostic['summary_json'] = json_encode(['databases' => [
            'latest_versions' => ['title' => 'База версий', 'updated_at' => '2026-10-20T10:00:00+03:00'],
            'wordfence'       => ['title' => 'Уязвимости WordPress', 'updated_at' => null],
        ]]);
        $report = buildReport($diagnostic, [], [], []);
        $this->assertSame([true, true], array_column($report['databases'], 'stale'));
        $this->assertSame(REPORT_DATABASE_NO_DATE, $report['databases'][1]['date']);
        $this->assertTrue($report['databasesStale']);
        $this->assertStringContainsString('Планировщика', $report['databasesWarning']);

        // Старая диагностика без шага outdated
        $this->assertSame([], buildReport($this->diagnostic(), [], [], [])['databases']);
    }

    public function testProductUrlIsShownOnlyWhenSet(): void
    {
        $diagnostic = $this->diagnostic();
        $this->assertNull(buildReport($diagnostic, [], [], [])['productUrl']);

        $diagnostic['product_url'] = 'https://shop.ru/product/1';
        $this->assertSame('https://shop.ru/product/1', buildReport($diagnostic, [], [], [])['productUrl']);

        $diagnostic['product_url'] = '';
        $this->assertNull(buildReport($diagnostic, [], [], [])['productUrl']);
    }

    public function testBrokenOrMissingSummaryGivesNoData(): void
    {
        foreach ([null, '', '{broken', '[]', '{"ssl": null}'] as $summaryJson) {
            $diagnostic = $this->diagnostic();
            $diagnostic['summary_json'] = $summaryJson;

            $this->assertNull(buildReport($diagnostic, [], [], [])['summary'], var_export($summaryJson, true));
        }
    }

    public function testSummaryRowsVariantsRedirectsAndWarnings(): void
    {
        $diagnostic = $this->diagnostic();
        $diagnostic['summary_json'] = json_encode([
            'availability' => [
                'home' => [
                    'url'                     => 'http://shop.ru/',
                    'final_url'               => self::HOME_URL,
                    'status'                  => 403,
                    'redirects'               => [['url' => 'http://shop.ru/', 'status' => 301, 'location' => self::HOME_URL]],
                    'time_ms'                 => 120,
                    'error'                   => null,
                    'truncated'               => false,
                    'possible_bot_protection' => true,
                ],
                'response_ms'    => 120,
                'variants'       => [
                    'http'  => ['url' => 'http://shop.ru/', 'final_url' => self::HOME_URL, 'status' => 403, 'error' => null],
                    'https' => ['url' => self::HOME_URL, 'final_url' => '', 'status' => null, 'error' => 'timeout'],
                ],
                'https_redirect' => true,
                'www_consistent' => null,
            ],
            'ssl' => ['error' => null, 'valid_to' => '2026-11-20', 'days_left' => 5, 'issuer' => 'R3',
                'domain_match' => true, 'trusted' => false],
        ]);

        $summary = buildReport($diagnostic, [], [], [])['summary'];
        $rows = array_column($summary['rows'], null, 'label');

        $this->assertSame('403', $rows['Ответ главной']['value']);
        $this->assertSame(REPORT_BADGE_BAD, $rows['Ответ главной']['badgeClass']);
        $this->assertSame('120 мс', $rows['Время ответа']['value']);
        $this->assertSame('есть', $rows['Редирект на https']['value']);
        $this->assertSame('не проверено', $rows['www и без www']['value']);
        $this->assertSame(REPORT_BADGE_WARNING, $rows['SSL-сертификат']['badgeClass']);
        $this->assertStringContainsString('осталось 5 дн.', $rows['SSL-сертификат']['value']);
        $this->assertSame('не доверенная', $rows['Цепочка доверия']['value']);

        $this->assertCount(1, $summary['redirects']);
        $this->assertSame('301', $summary['redirects'][0]['status']);
        $this->assertSame(self::HOME_URL, $summary['variants'][0]['finalUrl']);
        $this->assertSame('нет ответа: timeout', $summary['variants'][1]['value']);
        $this->assertCount(1, $summary['warnings']);
    }

    public function testSslErrorAndExpiredCertificate(): void
    {
        $this->assertSame('не получен: refused', buildReportSslRows(['error' => 'refused'])[0]['value']);
        $this->assertSame('нет данных', buildReportSslRows(null)[0]['value']);

        $expired = buildReportSslRows(['error' => null, 'valid_to' => '2026-01-01', 'days_left' => -3]);
        $this->assertSame(REPORT_BADGE_BAD, $expired[0]['badgeClass']);
        $this->assertStringStartsWith('истёк', $expired[0]['value']);
    }

    /**
     * @param list<array<string, mixed>> $steps
     * @return array<string, mixed>
     */
    private function diagnostic(string $status = 'done', array $steps = []): array
    {
        return ['id' => 1, 'status' => $status, 'summary_json' => null, 'steps' => $steps];
    }

    /**
     * @return array<string, mixed>
     */
    private function step(string $code, string $status, ?string $message = null): array
    {
        return ['step_code' => $code, 'title' => 'Шаг ' . $code, 'status' => $status, 'message' => $message];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function finding(string $severity, string $title, array $overrides = []): array
    {
        return $overrides + [
            'page_id'  => null,
            'category' => 'availability',
            'severity' => $severity,
            'title'    => $title,
            'details'  => null,
            'location' => null,
            'evidence' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function homePage(): array
    {
        return ['id' => self::HOME_PAGE_ID, 'url' => self::HOME_URL];
    }
}
