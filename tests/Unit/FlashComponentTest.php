<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class FlashComponentTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function renderFlash(): string
    {
        ob_start();
        render('components/flash');
        return trim((string) ob_get_clean());
    }

    public function testRendersNothingWithoutMessages(): void
    {
        $_SESSION = [];

        $this->assertSame('', $this->renderFlash());
    }

    public function testRendersSuccessAndErrorAlerts(): void
    {
        $_SESSION['flash'] = ['success' => 'Сохранено', 'error' => 'Ошибка'];

        $html = $this->renderFlash();

        $this->assertStringContainsString('alert-success', $html);
        $this->assertStringContainsString('Сохранено', $html);
        $this->assertStringContainsString('alert-danger', $html);
        $this->assertStringContainsString('Ошибка', $html);
    }

    public function testRendersWarningAlert(): void
    {
        $_SESSION['flash'] = ['warning' => 'Список моделей недоступен'];

        $html = $this->renderFlash();

        $this->assertStringContainsString('alert-warning', $html);
        $this->assertStringContainsString('Список моделей недоступен', $html);
    }

    public function testEscapesMessageHtml(): void
    {
        $_SESSION['flash'] = ['error' => '<script>alert(1)</script>'];

        $html = $this->renderFlash();

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testMessageIsShownOnlyOnce(): void
    {
        $_SESSION['flash'] = ['success' => 'Сохранено'];

        $this->renderFlash();

        $this->assertSame('', $this->renderFlash());
    }
}
