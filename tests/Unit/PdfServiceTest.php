<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

require_once ROOT_PATH . '/src/Core/Logger.php';
require_once ROOT_PATH . '/src/Services/PdfService.php';

final class PdfServiceTest extends TestCase
{
    private string $baseDir;
    private string $tempDir;
    private string $pdfDir;
    private string $screenshotDir;

    public static function setUpBeforeClass(): void
    {
        if (!defined('APP_NAME')) {
            define('APP_NAME', 'Site Diagnostics');
        }
        if (!defined('STORAGE_PATH')) {
            define('STORAGE_PATH', ROOT_PATH . '/storage');
        }
        if (!defined('PDF_PATH')) {
            define('PDF_PATH', ROOT_PATH . '/storage/pdf');
        }
    }

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/sd-pdf-test-' . bin2hex(random_bytes(4));
        $this->tempDir = $this->baseDir . '/temp';
        $this->pdfDir = $this->baseDir . '/pdf';
        $this->screenshotDir = $this->baseDir . '/screenshots';
        mkdir($this->tempDir, 0775, true);
        mkdir($this->pdfDir, 0775, true);
        mkdir($this->screenshotDir, 0775, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->baseDir)) {
            removeDirectoryTree($this->baseDir);
        }
    }

    public function testVersionAllowlistIsStrict(): void
    {
        $this->assertSame(PDF_VERSION_FULL, normalizePdfVersion('full'));
        $this->assertSame(PDF_VERSION_CLIENT, normalizePdfVersion('client'));
        $this->assertNull(normalizePdfVersion('FULL'));
        $this->assertNull(normalizePdfVersion('full.pdf'));
        $this->assertNull(normalizePdfVersion(''));
        $this->assertNull(normalizePdfVersion(['full']));
        $this->assertNull(normalizePdfVersion(null));
    }

    public function testFilenameUsesDiagnosticDateVersionAndSafeAsciiDomain(): void
    {
        $this->assertSame(
            'diagnostic-shop.ru-2026-11-18-full.pdf',
            buildPdfFilename(['host' => 'shop.ru', 'created_at' => '2026-11-18 12:30:00'], 'full')
        );
        $this->assertSame(
            'diagnostic-shop.ru-x-test-2026-01-02-client.pdf',
            buildPdfFilename(['host' => ' Shop.ru/\\"' . "\r\n" . 'X-Test', 'created_at' => '2026-01-02'], 'client')
        );

        $filename = buildPdfFilename(['host' => '***', 'created_at' => null], 'full');
        $this->assertSame('diagnostic-site-unknown-date-full.pdf', $filename);
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x20"\'\/\\\\]/', $filename);
    }

    public function testInvalidFilenameVersionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        buildPdfFilename(['host' => 'shop.test', 'created_at' => '2026-11-18'], 'full&name=evil');
    }

    public function testWindowsFileUriEncodesSpacesCyrillicPercentAndHash(): void
    {
        $this->assertSame(
            'file:///C:/OS%20Panel/%D0%94%D0%BE%D0%BC%20%25%23/report%20%231.html',
            pathToFileUri('C:\\OS Panel\\Дом %#\\report #1.html')
        );
        $this->assertSame('file:///tmp/report.html', pathToFileUri('/tmp/report.html'));
    }

    public function testResourcesAndOnlyOwnedExistingScreenshotsBecomeLocalUris(): void
    {
        $owned = '17/2-360.jpg';
        $foreign = '18/3-360.jpg';
        $this->createScreenshot($owned);
        $this->createScreenshot($foreign);

        $html = <<<'HTML'
<link rel="stylesheet" href="/assets/vendor/tabler/css/tabler.min.css">
<link rel="stylesheet" href="/assets/css/admin-theme.css">
<link rel="stylesheet" href="/assets/css/print.css">
<img src="/screenshots/11">
<img src="/screenshots/11/thumb">
<img src="/screenshots/12">
<img src="/screenshots/99/thumb">
<img src="/screenshots/not-a-number">
HTML;
        $localized = localizePdfHtml(
            $html,
            [
                ['id' => 11, 'file_path' => $owned],
                ['id' => 12, 'file_path' => $foreign],
            ],
            17,
            'C:\\OS Panel\\Сайт %#',
            $this->screenshotDir
        );

        $this->assertStringContainsString(
            'href="file:///C:/OS%20Panel/%D0%A1%D0%B0%D0%B9%D1%82%20%25%23/public/assets/css/print.css"',
            $localized
        );
        $this->assertStringContainsString(pathToFileUri((string) realpath($this->screenshotDir . '/' . $owned)), $localized);
        $this->assertStringContainsString(
            pathToFileUri((string) realpath($this->screenshotDir . '/' . buildThumbnailFilePath($owned))),
            $localized
        );
        $this->assertStringNotContainsString('src="/screenshots/', $localized);
        $this->assertStringNotContainsString("src='/screenshots/", $localized);
        $this->assertStringNotContainsString('http://', $localized);
        $this->assertStringNotContainsString('https://', $localized);
        $this->assertSame(3, substr_count($localized, 'src="data:,"'));
    }

    public function testUnsafeScreenshotPathIsNotRead(): void
    {
        $this->createScreenshot('18/3-360.jpg');
        $localized = localizePdfHtml(
            '<img src="/screenshots/12">',
            [['id' => 12, 'file_path' => '17/../../18/3-360.jpg']],
            17,
            ROOT_PATH,
            $this->screenshotDir
        );

        $this->assertSame('<img src="data:,">', $localized);
    }

    public function testSuccessfulCreationRemovesHtmlAndReturnsValidPdf(): void
    {
        $htmlUri = null;
        $pdfPath = createPdfFile(
            '<html lang="ru"><body>Кириллица</body></html>',
            17,
            'unused-chrome',
            $this->tempDir,
            $this->pdfDir,
            static function (string $chrome, string $uri, string $output, int $ownerId) use (&$htmlUri): void {
                TestCase::assertSame('unused-chrome', $chrome);
                TestCase::assertSame(17, $ownerId);
                TestCase::assertStringStartsWith('file://', $uri);
                $htmlUri = $uri;
                file_put_contents($output, '%PDF-test');
            }
        );

        $this->assertFileExists($pdfPath);
        $this->assertSame([], glob($this->tempDir . '/*.html') ?: []);
        $this->assertStringStartsWith('file://', (string) $htmlUri);

        removePdfTemporaryFile($pdfPath);
        $this->assertFileDoesNotExist($pdfPath);
    }

    public function testPrintFailureRemovesBothFiles(): void
    {
        try {
            createPdfFile(
                '<html></html>',
                17,
                'broken-chrome',
                $this->tempDir,
                $this->pdfDir,
                static function (string $chrome, string $uri, string $output): void {
                    file_put_contents($output, '%PDF-partial');
                    throw new RuntimeException('Смоделированный сбой печати');
                }
            );
            $this->fail('Ожидалось исключение печати.');
        } catch (RuntimeException $e) {
            $this->assertSame('Смоделированный сбой печати', $e->getMessage());
        }

        $this->assertSame([], glob($this->tempDir . '/*.html') ?: []);
        $this->assertSame([], glob($this->pdfDir . '/*.pdf') ?: []);
    }

    public function testInvalidPdfIsRejectedAndRemoved(): void
    {
        try {
            createPdfFile(
                '<html></html>',
                17,
                'broken-chrome',
                $this->tempDir,
                $this->pdfDir,
                static function (string $chrome, string $uri, string $output): void {
                    file_put_contents($output, 'not-a-pdf');
                }
            );
            $this->fail('Ожидалось исключение проверки PDF.');
        } catch (RuntimeException $e) {
            $this->assertSame('Chrome вернул некорректный PDF-файл.', $e->getMessage());
        }

        $this->assertSame([], glob($this->tempDir . '/*.html') ?: []);
        $this->assertSame([], glob($this->pdfDir . '/*.pdf') ?: []);
    }

    public function testRenderRestoresOutputBufferAndGlobalModeAfterFailure(): void
    {
        $level = ob_get_level();
        $GLOBALS['pdfExportMode'] = 'previous';

        try {
            renderPdfView('missing/view', []);
            $this->fail('Ожидалось исключение рендера.');
        } catch (Throwable) {
            $this->assertSame($level, ob_get_level());
            $this->assertSame('previous', $GLOBALS['pdfExportMode']);
        } finally {
            unset($GLOBALS['pdfExportMode']);
        }
    }

    public function testRouteIsRegisteredAsGet(): void
    {
        $routes = require ROOT_PATH . '/config/routes.php';

        $this->assertSame(['ExportController', 'download'], $routes['GET']['/diagnostics/{id}/pdf'] ?? null);
        $this->assertArrayNotHasKey('/diagnostics/{id}/pdf', $routes['POST']);
    }

    private function createScreenshot(string $relativePath): void
    {
        $path = $this->screenshotDir . '/' . $relativePath;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, 'jpeg-test');
        file_put_contents($this->screenshotDir . '/' . buildThumbnailFilePath($relativePath), 'jpeg-thumb');
    }
}
