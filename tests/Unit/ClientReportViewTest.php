<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/src/Services/ClientReportBuilder.php';
require_once ROOT_PATH . '/src/Services/PdfService.php';

final class ClientReportViewTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        session_save_path(ROOT_PATH . '/storage/temp');
        if (!defined('APP_NAME')) {
            define('APP_NAME', 'Site Diagnostics');
        }
    }

    public function testClientViewEscapesDynamicTextHidesMenuAndTechnicalFields(): void
    {
        $technical = 'https://192.0.2.10/private.php HTTP 500 Fatal error';
        $report = buildClientReport(
            [
                'host' => '<script>alert("host")</script>.test', 'status' => 'done',
                'created_at' => '2026-11-18 12:00:00', 'steps' => [[
                    'step_code' => 'ai_client', 'status' => 'done', 'started_at' => '2026-11-18 12:00:00',
                ]],
            ],
            [[
                'category'    => 'checkout',
                'severity'    => 'critical',
                'client_text' => '<img src=x onerror=alert(1)>',
                'title'       => $technical,
                'details'     => $technical,
                'location'    => $technical,
                'evidence'    => ['raw' => $technical],
            ]],
            null,
            [[
                'id' => 8, 'task' => 'client_text', 'status' => 'ok', 'created_at' => '2026-11-18 12:01:00',
                'parsed_json' => ['summary' => '<img src=x onerror=alert("summary")>'],
            ]]
        );

        $html = $this->renderClientView($report);

        $this->assertStringContainsString('&lt;script&gt;alert(&quot;host&quot;)&lt;/script&gt;.test', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(&quot;summary&quot;)&gt;', $html);
        $this->assertStringContainsString('Главное по результатам проверки', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString($technical, $html);
        $this->assertStringNotContainsString('id="navbar-menu"', $html);
        $this->assertStringNotContainsString('Новая диагностика', $html);
        $this->assertStringContainsString('Технический отчёт', $html);
        $this->assertStringContainsString('d-print-none', $html);
    }

    public function testPartialAndEmptyStatesAreRendered(): void
    {
        $partial = buildClientReport(
            ['host' => 'shop.test', 'status' => 'failed', 'created_at' => '2026-11-18 12:00:00'],
            [['category' => 'speed', 'severity' => 'medium', 'client_text' => null]]
        );

        $this->assertStringContainsString('Отчёт неполный', $this->renderClientView($partial));

        $empty = buildClientReport(
            ['host' => 'shop.test', 'status' => 'done', 'created_at' => '2026-11-18 12:00:00'],
            []
        );
        $this->assertStringContainsString('Проблем не найдено', $this->renderClientView($empty));

        $error = $this->renderClientView(null, 'Отчёт не найден', 'Проверьте ссылку.');
        $this->assertStringContainsString('Отчёт не найден', $error);
        $this->assertStringContainsString('Проверьте ссылку.', $error);
    }

    public function testDefaultHeaderStillContainsMainMenu(): void
    {
        ob_start();
        render('admin/layout/header', ['title' => 'Проверка', 'activeMenu' => 'new']);
        render('admin/layout/footer');
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('id="navbar-menu"', $html);
        $this->assertStringContainsString('Новая диагностика', $html);
    }

    public function testTechnicalReportLinkOnlyRendersWhenUrlIsProvided(): void
    {
        $withLink = $this->renderDiagnosticView('/diagnostics/17/client');
        $withoutLink = $this->renderDiagnosticView(null);

        $this->assertStringContainsString('href="/diagnostics/17/client"', $withLink);
        $this->assertStringContainsString('Клиентская версия', $withLink);
        $this->assertStringNotContainsString('Клиентская версия', $withoutLink);
    }

    public function testClientRouteIsRegisteredAsGet(): void
    {
        $routes = require ROOT_PATH . '/config/routes.php';

        $this->assertSame(
            ['DiagnosticController', 'client'],
            $routes['GET']['/diagnostics/{id}/client'] ?? null
        );
        $this->assertArrayNotHasKey('/diagnostics/{id}/client', $routes['POST']);
    }

    public function testPdfButtonsRenderOnlyWithReportUrls(): void
    {
        $withLinks = $this->renderDiagnosticView('/diagnostics/17/client', '/diagnostics/17/pdf?version=full');
        $withoutLinks = $this->renderDiagnosticView(null);

        $this->assertStringContainsString('PDF полный', $withLinks);
        $this->assertStringContainsString('href="/diagnostics/17/pdf?version=full"', $withLinks);
        $this->assertStringNotContainsString('PDF полный', $withoutLinks);

        $report = buildClientReport(
            ['host' => 'shop.test', 'status' => 'done', 'created_at' => '2026-11-18 12:00:00'],
            []
        );
        $client = $this->renderClientView($report);
        $empty = $this->renderClientView(null, 'Отчёт не найден', 'Нет данных.');

        $this->assertStringContainsString('PDF для клиента', $client);
        $this->assertStringContainsString('href="/diagnostics/17/pdf?version=client"', $client);
        $this->assertStringNotContainsString('PDF для клиента', $empty);
    }

    public function testClientPdfModeHasNoNavigationButtonsOrScripts(): void
    {
        $report = buildClientReport(
            ['host' => 'shop.test', 'status' => 'failed', 'created_at' => '2026-11-18 12:00:00'],
            [['category' => 'speed', 'severity' => 'medium', 'client_text' => null]]
        );
        $html = renderPdfView('pages/client-report', [
            'title' => 'Отчёт для клиента',
            'report' => $report,
            'technicalUrl' => null,
            'clientPdfUrl' => null,
        ]);

        $this->assertStringContainsString('class="pdf-export"', $html);
        $this->assertStringContainsString('/assets/css/print.css', $html);
        $this->assertStringContainsString('Отчёт неполный', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<button', $html);
        $this->assertStringNotContainsString('navbar', $html);
        $this->assertStringNotContainsString('PDF для клиента', $html);
        $this->assertStringNotContainsString('Технический отчёт', $html);
    }

    public function testFullPdfModeExpandsFindingsAndRemovesInteractiveElements(): void
    {
        $html = $this->renderDiagnosticView(null, null, true, true);

        $this->assertStringContainsString('Полный технический отчёт', $html);
        $this->assertStringContainsString('Дата проверки: 2026-11-18 12:00:00', $html);
        $this->assertStringContainsString('По серьёзности', $html);
        $this->assertStringContainsString('По страницам', $html);
        $this->assertStringContainsString('Опасная находка', $html);
        $this->assertStringNotContainsString('Ход диагностики', $html);
        $this->assertStringNotContainsString('nav-tabs', $html);
        $this->assertStringNotContainsString('tab-pane', $html);
        $this->assertStringNotContainsString('data-bs-toggle="tab"', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringNotContainsString('PDF полный', $html);
    }

    public function testPdfGalleryUsesFullImagesWithoutButtonsModalsOrThumbnails(): void
    {
        $html = renderPdfView('components/screenshot-gallery', [
            'gallery' => [
                'screenshots' => [[
                    'url' => '/screenshots/11',
                    'thumbUrl' => '/screenshots/11/thumb',
                    'alt' => 'Главная, 360 px',
                    'thumbAlt' => 'Главная, 360 px, миниатюра',
                    'caption' => '360 px',
                    'modalId' => 'screenshot-home-11',
                ]],
                'screenshotsEmpty' => 'Скриншотов нет.',
                'screenshotsNote' => null,
            ],
        ]);

        $this->assertStringContainsString('src="/screenshots/11"', $html);
        $this->assertStringContainsString('<figcaption', $html);
        $this->assertStringContainsString('Главная, 360 px', $html);
        $this->assertStringNotContainsString('/thumb', $html);
        $this->assertStringNotContainsString('<button', $html);
        $this->assertStringNotContainsString('modal', $html);
        $this->assertStringNotContainsString('loading="lazy"', $html);
    }

    private function renderClientView(?array $report, string $emptyTitle = '', string $emptyText = ''): string
    {
        ob_start();
        render('pages/client-report', [
            'title'        => 'Отчёт для клиента',
            'report'       => $report,
            'emptyTitle'   => $emptyTitle,
            'emptyText'    => $emptyText,
            'technicalUrl' => $report !== null ? '/diagnostics/17' : null,
            'clientPdfUrl' => $report !== null ? '/diagnostics/17/pdf?version=client' : null,
        ]);

        return (string) ob_get_clean();
    }

    private function renderDiagnosticView(
        ?string $clientReportUrl,
        ?string $fullPdfUrl = null,
        bool $pdfExport = false,
        bool $withFindings = false
    ): string {
        $report = [
            'partial'           => false,
            'productUrl'        => null,
            'summary'           => null,
            'checkout'          => null,
            'pagespeed'         => null,
            'pages'             => [],
            'findingCount'      => 0,
            'findingGroups'     => [],
            'findingPageGroups' => [],
            'blockedBadge'      => ['label' => '', 'badgeClass' => ''],
            'stackGroups'       => [],
            'databases'         => [],
            'databasesStale'    => false,
            'databasesWarning'  => '',
            'uncheckedSteps'    => [],
            'aiProblem'         => null,
            'aiVisual'          => null,
            'aiSummary'         => null,
            'aiSpending'        => null,
        ];

        if ($withFindings) {
            $finding = [
                'anchorId' => 'finding-1',
                'badgeClass' => 'bg-red-lt',
                'severityLabel' => 'Высокая',
                'categoryLabel' => 'SEO',
                'title' => 'Опасная находка',
                'location' => null,
                'line' => null,
                'widths' => null,
                'pageUrl' => null,
                'pageUrls' => [],
                'details' => null,
                'evidenceJson' => null,
            ];
            $report['findingCount'] = 1;
            $report['findingGroups'] = [[
                'label' => 'Высокая', 'count' => 1, 'badgeClass' => 'bg-red-lt', 'items' => [$finding],
            ]];
            $report['findingPageGroups'] = [[
                'title' => 'Сайт целиком', 'count' => 1, 'url' => null, 'blocked' => null, 'items' => [$finding],
            ]];
        }

        $diagnostic = [
            'id'           => 17,
            'url'          => 'https://shop.test/',
            'status'       => 'done',
            'statusLabel'  => 'Готово',
            'badgeClass'   => 'bg-green-lt',
            'progress'     => 100,
            'errorMessage' => null,
            'isFinished'   => true,
            'steps'        => [],
        ];
        $data = [
            'title'           => 'Диагностика #17',
            'activeMenu'      => 'new',
            'diagnostic'      => $diagnostic,
            'statusUrl'       => '/diagnostics/17/status',
            'hasReport'       => true,
            'report'          => $report,
            'reportError'     => false,
            'aiRerunUrl'      => null,
            'clientReportUrl' => $clientReportUrl,
            'fullPdfUrl'      => $fullPdfUrl,
            'printDate'       => '2026-11-18 12:00:00',
        ];

        if ($pdfExport) {
            return renderPdfView('pages/diagnostic', $data);
        }

        ob_start();
        render('pages/diagnostic', $data);

        return (string) ob_get_clean();
    }
}
