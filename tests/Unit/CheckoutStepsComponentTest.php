<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/src/Services/ReportBuilder.php';

final class CheckoutStepsComponentTest extends TestCase
{
    public function testRendersEscapedStepConsoleAndAccessibleScreenshot(): void
    {
        $checkout = buildReportCheckout([
            'outcome' => 'failed',
            'steps'   => [[
                'code'            => '<b>Шаг</b>',
                'status'          => 'failed',
                'url'             => 'https://shop.ru/<script>',
                'note'            => '<img src=x onerror=alert(1)>',
                'screenshot_id'   => 41,
                'console_errors'  => 1,
                'console_sample'  => ['<script>alert(1)</script>'],
            ]],
        ], [['id' => 41, 'viewport_width' => 1366]]);

        $html = $this->renderComponent($checkout);

        $this->assertStringContainsString('aria-label="Шаги сценария заказа"', $html);
        $this->assertStringContainsString('&lt;b&gt;Шаг&lt;/b&gt;', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('/screenshots/41/thumb', $html);
        $this->assertStringContainsString('id="screenshot-checkout-step-0-41"', $html);
        $this->assertStringContainsString('aria-label="Закрыть"', $html);
        $this->assertStringContainsString('alt="Сценарий заказа', $html);
    }

    public function testRendersEmptyScreenshotAndCalmConsoleState(): void
    {
        $checkout = buildReportCheckout([
            'outcome' => 'reached',
            'steps'   => [['code' => 'product', 'status' => 'done', 'screenshot_id' => 999, 'console_errors' => 0]],
        ], [['id' => 41, 'viewport_width' => 1366]]);

        $html = $this->renderComponent($checkout);

        $this->assertStringContainsString('Скриншот шага не сохранён.', $html);
        $this->assertStringContainsString('Ошибок консоли нет.', $html);
        $this->assertStringNotContainsString('/screenshots/999', $html);
    }

    private function renderComponent(?array $checkout): string
    {
        self::assertNotNull($checkout);
        ob_start();
        render('components/checkout-steps', ['checkout' => $checkout]);

        return (string) ob_get_clean();
    }
}
