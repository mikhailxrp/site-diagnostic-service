<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\ExportController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

require_once ROOT_PATH . '/src/Controllers/ExportController.php';

final class ExportControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        clearRateLimit(ExportController::RATE_LIMIT_ACTION);
    }

    protected function tearDown(): void
    {
        clearRateLimit(ExportController::RATE_LIMIT_ACTION);
        parent::tearDown();
    }

    public function testSendPdfThrowsRuntimeExceptionForMissingFile(): void
    {
        $controller = new ExportController();
        $method = new ReflectionMethod(ExportController::class, 'sendPdf');
        $missingPath = sys_get_temp_dir() . '/sd-missing-' . bin2hex(random_bytes(4)) . '.pdf';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PDF-файл недоступен для скачивания.');

        $method->invoke($controller, $missingPath, 'report.pdf');
    }

    public function testSendPdfThrowsRuntimeExceptionForEmptyFile(): void
    {
        $controller = new ExportController();
        $method = new ReflectionMethod(ExportController::class, 'sendPdf');
        $emptyPath = sys_get_temp_dir() . '/sd-empty-' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents($emptyPath, '');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('PDF-файл недоступен для скачивания.');

            $method->invoke($controller, $emptyPath, 'report.pdf');
        } finally {
            if (is_file($emptyPath)) {
                unlink($emptyPath);
            }
        }
    }

    public function testPdfRateLimitRejectsSixthAttemptWithinWindow(): void
    {
        $controller = new ExportController();
        $method = new ReflectionMethod(ExportController::class, 'takePdfRateLimitAttempt');

        for ($i = 0; $i < ExportController::RATE_LIMIT_MAX_ATTEMPTS; $i++) {
            $this->assertTrue($method->invoke($controller, 12, PDF_VERSION_FULL));
        }

        $this->assertFalse($method->invoke($controller, 12, PDF_VERSION_FULL));
    }
}
