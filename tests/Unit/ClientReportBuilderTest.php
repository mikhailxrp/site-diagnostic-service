<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/src/Services/ClientReportBuilder.php';

final class ClientReportBuilderTest extends TestCase
{
    private const CATEGORIES = [
        'availability',
        'ssl',
        'broken_resource',
        'php_error',
        'mixed_content',
        'js_error',
        'failed_request',
        'outdated',
        'vulnerability',
        'exposed_file',
        'seo',
        'speed',
        'checkout',
        'layout',
        'hypothesis',
    ];

    public function testTemplatesCoverEveryDocumentedFindingCategory(): void
    {
        $templates = loadClientTextTemplates();

        $this->assertSame(self::CATEGORIES, array_keys($templates['categories']));
        foreach ($templates['categories'] as $template) {
            $this->assertCount(3, $template['forms']);
            $this->assertNotSame('', $template['problem']);
            $this->assertNotSame('', $template['impact']);
            $this->assertNotSame('', $template['action']);
        }
    }

    public function testGroupsSameCategoryCountsFormsAndOrdersByHighestSeverity(): void
    {
        $findings = array_fill(0, 12, $this->finding('broken_resource', 'low'));
        $findings[] = $this->finding('vulnerability', 'critical');
        $findings[] = $this->finding('seo', 'medium');

        $report = buildClientReport($this->diagnostic(), $findings);

        $this->assertSame(14, $report['findingCount']);
        $this->assertCount(3, $report['groups']);
        $this->assertSame('1 известная уязвимость', $report['groups'][0]['countLabel']);
        $this->assertSame('1 проблема поисковой оптимизации', $report['groups'][1]['countLabel']);
        $this->assertSame('12 битых ресурсов', $report['groups'][2]['countLabel']);
        $this->assertArrayNotHasKey('category', $report['groups'][0]);
        $this->assertArrayNotHasKey('severity', $report['groups'][0]);
    }

    public function testClientTextOverridesTemplateAndDuplicatesAreRemoved(): void
    {
        $report = buildClientReport($this->diagnostic(), [
            $this->finding('checkout', 'high', 'Покупатель не может открыть форму заказа.'),
            $this->finding('checkout', 'high', '  Покупатель не может открыть форму заказа.  '),
            $this->finding('checkout', 'medium', ''),
            $this->finding('checkout', 'medium', null),
        ]);

        $this->assertSame(
            ['Покупатель не может открыть форму заказа.'],
            $report['groups'][0]['descriptions']
        );
        $this->assertSame('4 проблемы оформления заказа', $report['groups'][0]['countLabel']);

        $fallback = buildClientReport($this->diagnostic(), [$this->finding('checkout', 'medium', null)]);
        $this->assertSame(
            'Покупатель не может нормально пройти один из шагов оформления заказа.',
            $fallback['groups'][0]['descriptions'][0]
        );
    }

    public function testTechnicalFindingFieldsNeverEnterReportData(): void
    {
        $technical = 'https://192.0.2.10/private/error.php PHP 7.4 HTTP 500 Fatal error';
        $finding = $this->finding('php_error', 'high') + [
            'title'    => $technical,
            'details'  => $technical,
            'location' => $technical,
            'evidence' => ['raw' => $technical],
        ];

        $json = json_encode(
            buildClientReport($this->diagnostic(), [$finding]),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        $this->assertStringNotContainsString($technical, $json);
        $this->assertStringNotContainsString('location', $json);
        $this->assertStringNotContainsString('evidence', $json);
        $this->assertStringNotContainsString('details', $json);
    }

    public function testUnknownCategoriesUseSeparateSafeFallbackGroupsWithoutLeakingCodes(): void
    {
        $report = buildClientReport($this->diagnostic(), [
            $this->finding('future_secret_code', 'high'),
            $this->finding('another_internal_code', 'low'),
        ]);
        $json = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->assertCount(2, $report['groups']);
        $this->assertStringNotContainsString('future_secret_code', $json);
        $this->assertStringNotContainsString('another_internal_code', $json);
        $this->assertSame('1 проблема', $report['groups'][0]['countLabel']);
    }

    public function testPartialMetadataIsSafeAndRussianCountFormsHandleTeens(): void
    {
        $diagnostic = array_replace($this->diagnostic(), ['host' => '192.0.2.12', 'status' => 'failed']);
        $findings = array_fill(0, 21, $this->finding('broken_resource', 'low'));

        $report = buildClientReport($diagnostic, $findings);

        $this->assertSame(CLIENT_REPORT_UNKNOWN_SITE, $report['site']);
        $this->assertSame('18.11.2026', $report['date']);
        $this->assertTrue($report['partial']);
        $this->assertSame('21 битый ресурс', $report['groups'][0]['countLabel']);
        $this->assertSame('битых ресурсов', selectClientCountForm(11, ['битый ресурс', 'битых ресурса', 'битых ресурсов']));
        $this->assertSame('битых ресурсов', selectClientCountForm(14, ['битый ресурс', 'битых ресурса', 'битых ресурсов']));
    }

    public function testSummaryComesOnlyFromSuccessfulCurrentClientStep(): void
    {
        $diagnostic = $this->diagnostic() + ['steps' => [[
            'step_code' => 'ai_client', 'status' => 'done', 'started_at' => '2026-11-18 12:00:00',
        ]]];
        $results = [
            $this->clientResult(1, 'ok', '2026-11-18 11:00:00', 'Старый вывод.'),
            $this->clientResult(2, 'ok', '2026-11-18 12:01:00', 'Покупателям трудно оформить заказ.'),
        ];

        $report = buildClientReport($diagnostic, [], null, $results);
        $this->assertSame('Покупателям трудно оформить заказ.', $report['summary']);

        $diagnostic['steps'][0]['status'] = 'failed';
        $this->assertNull(buildClientReport($diagnostic, [], null, $results)['summary']);
    }

    public function testLatestErrorAndUnsafeSummaryDoNotExposeOlderOrTechnicalText(): void
    {
        $diagnostic = $this->diagnostic() + ['steps' => [[
            'step_code' => 'ai_client', 'status' => 'done', 'started_at' => '2026-11-18 12:00:00',
        ]]];
        $withError = [
            $this->clientResult(1, 'ok', '2026-11-18 12:01:00', 'Старый вывод.'),
            $this->clientResult(2, 'error', '2026-11-18 12:02:00', null),
        ];
        $this->assertNull(buildClientReport($diagnostic, [], null, $withError)['summary']);

        $unsafe = [$this->clientResult(3, 'ok', '2026-11-18 12:03:00', 'Ошибка в /var/www/cart.php HTTP 500.')];
        $this->assertNull(buildClientReport($diagnostic, [], null, $unsafe)['summary']);
    }

    /**
     * @return array<string, mixed>
     */
    private function diagnostic(): array
    {
        return [
            'host'       => 'shop.test',
            'status'     => 'done',
            'created_at' => '2026-11-18 12:00:00',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function finding(string $category, string $severity, ?string $clientText = null): array
    {
        return [
            'category'    => $category,
            'severity'    => $severity,
            'client_text' => $clientText,
        ];
    }

    /** @return array<string, mixed> */
    private function clientResult(int $id, string $status, string $createdAt, ?string $summary): array
    {
        return [
            'id' => $id, 'task' => 'client_text', 'status' => $status, 'created_at' => $createdAt,
            'parsed_json' => $summary !== null ? ['summary' => $summary] : null,
        ];
    }
}
