<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/src/Services/ClientReportBuilder.php';

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

    private function renderClientView(?array $report, string $emptyTitle = '', string $emptyText = ''): string
    {
        ob_start();
        render('pages/client-report', [
            'title'        => 'Отчёт для клиента',
            'report'       => $report,
            'emptyTitle'   => $emptyTitle,
            'emptyText'    => $emptyText,
            'technicalUrl' => $report !== null ? '/diagnostics/17' : null,
        ]);

        return (string) ob_get_clean();
    }

    private function renderDiagnosticView(?string $clientReportUrl): string
    {
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

        ob_start();
        render('pages/diagnostic', [
            'title'           => 'Диагностика #17',
            'activeMenu'      => 'new',
            'diagnostic'      => $diagnostic,
            'statusUrl'       => '/diagnostics/17/status',
            'hasReport'       => true,
            'report'          => $report,
            'reportError'     => false,
            'aiRerunUrl'      => null,
            'clientReportUrl' => $clientReportUrl,
        ]);

        return (string) ob_get_clean();
    }
}
