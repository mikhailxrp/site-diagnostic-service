<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/src/Services/DiagnosticComparer.php';

final class DiagnosticComparerTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_save_path(ROOT_PATH . '/storage/temp');
        }
        if (!defined('APP_NAME')) {
            define('APP_NAME', 'Site Diagnostics');
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    public function testFactsAreClassifiedByFingerprintAndRemainingUsesCurrentData(): void
    {
        $fixed = sha1('fixed');
        $remaining = sha1('remaining');
        $appeared = sha1('appeared');

        $comparison = buildDiagnosticComparison([
            $this->finding(1, $fixed, 'Старая ошибка', 'high'),
            $this->finding(2, $remaining, 'Старый заголовок', 'low'),
            $this->finding(3, $remaining, 'Дубль', 'critical'),
        ], [
            $this->finding(8, $remaining, 'Актуальный заголовок', 'critical', 'Новые детали'),
            $this->finding(9, $appeared, 'Новая ошибка', 'medium'),
        ], [], []);

        $this->assertSame(['Старая ошибка'], array_column($comparison['fixed'], 'title'));
        $this->assertSame(['Актуальный заголовок'], array_column($comparison['remaining'], 'title'));
        $this->assertSame('Новые детали', $comparison['remaining'][0]['details']);
        $this->assertSame(['Новая ошибка'], array_column($comparison['appeared'], 'title'));
        $this->assertTrue($comparison['hasChanges']);
    }

    public function testFindingsAreSortedBySeverityThenTitleAndInvalidFingerprintsAreSkipped(): void
    {
        $comparison = buildDiagnosticComparison([], [
            $this->finding(1, sha1('low'), 'Бета', 'low'),
            $this->finding(2, sha1('critical-z'), 'Ядро', 'critical'),
            $this->finding(3, sha1('critical-a'), 'Альфа', 'critical'),
            $this->finding(4, 'invalid', 'Не показывать', 'critical'),
        ], [], []);

        $this->assertSame(['Альфа', 'Ядро', 'Бета'], array_column($comparison['appeared'], 'title'));
        $this->assertSame(1, $comparison['invalidFingerprintCount']);
    }

    public function testAiFindingsAreKeptOutsideFactGroups(): void
    {
        $beforeAi = $this->finding(11, sha1('ai-before'), 'Гипотеза была', 'high', source: 'ai');
        $afterAi = $this->finding(12, sha1('ai-after'), 'Гипотеза стала', 'medium', source: 'ai');

        $comparison = buildDiagnosticComparison([$beforeAi], [$afterAi], [], []);

        $this->assertSame([], $comparison['fixed']);
        $this->assertSame([], $comparison['remaining']);
        $this->assertSame([], $comparison['appeared']);
        $this->assertSame(['Гипотеза была'], array_column($comparison['aiBefore'], 'title'));
        $this->assertSame(['Гипотеза стала'], array_column($comparison['aiAfter'], 'title'));
        $this->assertFalse($comparison['hasChanges']);
    }

    public function testStackShowsVersionChangesAndAddedOrRemovedPlugins(): void
    {
        $comparison = buildDiagnosticComparison([], [], [
            $this->stack('cms', 'OpenCart', '3.0.3.8'),
            $this->stack('plugin', 'Old Module', '1.0'),
            $this->stack('plugin', 'Duplicate', null),
            $this->stack('plugin', ' duplicate ', '2.0'),
            $this->stack('server', 'nginx', null),
        ], [
            $this->stack('cms', ' opencart ', '4.1.0.4'),
            $this->stack('plugin', 'New Module', '1.1'),
            $this->stack('plugin', 'Duplicate', '2.0'),
            $this->stack('server', 'nginx', '1.26'),
        ]);

        $this->assertSame(['opencart'], array_column($comparison['stack']['versionChanges'], 'name'));
        $this->assertSame('3.0.3.8', $comparison['stack']['versionChanges'][0]['beforeVersion']);
        $this->assertSame('4.1.0.4', $comparison['stack']['versionChanges'][0]['afterVersion']);
        $this->assertSame(['New Module'], array_column($comparison['stack']['addedPlugins'], 'name'));
        $this->assertSame(['Old Module'], array_column($comparison['stack']['removedPlugins'], 'name'));
        $this->assertTrue($comparison['hasChanges']);
    }

    public function testIdenticalFactsAndStackHaveNoChanges(): void
    {
        $finding = $this->finding(1, sha1('same'), 'Одинаково', 'low');
        $stack = [$this->stack('cms', 'WordPress', '6.8')];

        $comparison = buildDiagnosticComparison([$finding], [$finding], $stack, $stack);

        $this->assertFalse($comparison['hasChanges']);
        $this->assertCount(1, $comparison['remaining']);
        $this->assertSame([], $comparison['stack']['versionChanges']);
    }

    public function testRoutesUseGetForComparisonAndPostForRepeat(): void
    {
        $routes = require ROOT_PATH . '/config/routes.php';

        $this->assertSame(['CompareController', 'index'], $routes['GET']['/compare'] ?? null);
        $this->assertSame(
            ['DiagnosticController', 'repeat'],
            $routes['POST']['/diagnostics/{id}/repeat'] ?? null
        );
        $this->assertArrayNotHasKey('/compare', $routes['POST']);
        $this->assertArrayNotHasKey('/diagnostics/{id}/repeat', $routes['GET']);
    }

    public function testCompareViewEscapesValuesAndShowsPartialAndEmptyStates(): void
    {
        $comparison = buildDiagnosticComparison([], [], [], []);
        $comparison['aiAfter'] = [[
            'id' => 1, 'severity' => 'high', 'severityLabel' => 'Высокая', 'badgeClass' => 'bg-orange',
            'categoryLabel' => 'Гипотеза', 'title' => '<script>alert(1)</script>',
            'details' => '<img src=x onerror=alert(2)>', 'location' => 'https://shop.test/?x=<bad>',
        ]];

        $html = $this->renderCompareView($comparison, true);

        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(2)&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('Сравнение частичное', $html);
        $this->assertStringContainsString('Проверяемые находки и известные версии стека не изменились', $html);
        $this->assertStringContainsString('В этой группе находок нет', $html);
        $this->assertStringContainsString('Не сопоставляются', $html);
    }

    public function testDiagnosticActionsContainCsrfAndAreAbsentFromPdfMode(): void
    {
        $html = $this->renderDiagnosticActions(false);
        $pdf = $this->renderDiagnosticActions(true);

        $this->assertStringContainsString('action="/diagnostics/17/repeat"', $html);
        $this->assertStringContainsString('name="_csrf"', $html);
        $this->assertStringContainsString('Повторить диагностику', $html);
        $this->assertStringContainsString('href="/compare?a=16&amp;b=17"', $html);
        $this->assertStringContainsString('Сравнить с прошлой', $html);
        $this->assertStringNotContainsString('Повторить диагностику', $pdf);
        $this->assertStringNotContainsString('Сравнить с прошлой', $pdf);
    }

    /** @return array<string, mixed> */
    private function finding(
        int $id,
        string $fingerprint,
        string $title,
        string $severity,
        ?string $details = null,
        string $source = 'check'
    ): array {
        return [
            'id' => $id,
            'fingerprint' => $fingerprint,
            'title' => $title,
            'severity' => $severity,
            'details' => $details,
            'source' => $source,
            'category' => $source === 'ai' ? 'hypothesis' : 'broken_resource',
            'location' => 'https://shop.test/' . $id,
        ];
    }

    /** @return array<string, mixed> */
    private function stack(string $category, string $name, ?string $version): array
    {
        return ['category' => $category, 'name' => $name, 'version' => $version];
    }

    /** @param array<string, mixed> $comparison */
    private function renderCompareView(array $comparison, bool $partial): string
    {
        ob_start();
        render('pages/compare', [
            'title' => 'Сравнение диагностик',
            'activeMenu' => 'history',
            'before' => [
                'id' => 16, 'url' => 'https://shop.test/', 'status' => $partial ? 'failed' : 'done',
                'createdAt' => '2026-11-18 10:00:00', 'reportUrl' => '/diagnostics/16', 'partial' => $partial,
            ],
            'after' => [
                'id' => 17, 'url' => 'https://shop.test/', 'status' => 'done',
                'createdAt' => '2026-11-19 10:00:00', 'reportUrl' => '/diagnostics/17', 'partial' => false,
            ],
            'comparison' => $comparison,
        ]);

        return (string) ob_get_clean();
    }

    private function renderDiagnosticActions(bool $isPdfExport): string
    {
        ob_start();
        render('pages/diagnostic', [
            'title' => 'Диагностика #17',
            'activeMenu' => 'new',
            'diagnostic' => [
                'id' => 17, 'url' => 'https://shop.test/', 'status' => 'done', 'statusLabel' => 'Готово',
                'badgeClass' => 'bg-green-lt', 'progress' => 100, 'errorMessage' => null,
                'isFinished' => true, 'steps' => [],
            ],
            'statusUrl' => '/diagnostics/17/status',
            'hasReport' => false,
            'report' => null,
            'reportError' => false,
            'repeatUrl' => '/diagnostics/17/repeat',
            'compareUrl' => '/compare?a=16&b=17',
            'clientReportUrl' => null,
            'fullPdfUrl' => null,
            'isPdfExport' => $isPdfExport,
            'printDate' => '2026-11-19 10:00:00',
        ]);

        return (string) ob_get_clean();
    }
}
