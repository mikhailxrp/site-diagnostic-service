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
