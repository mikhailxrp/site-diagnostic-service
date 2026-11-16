<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once ROOT_PATH . '/src/Services/ScreenshotService.php';

final class ScreenshotServiceTest extends TestCase
{
    private const WINDOW_HEIGHT = 768;

    private string $baseDir;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/sd-screenshot-test-' . bin2hex(random_bytes(4));
        mkdir($this->baseDir);
    }

    protected function tearDown(): void
    {
        foreach ([$this->baseDir . '/outside.jpg'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        deleteDiagnosticScreenshots(12, $this->baseDir);
        if (is_dir($this->baseDir)) {
            rmdir($this->baseDir);
        }
    }

    public function testClipFollowsContentHeight(): void
    {
        $this->assertSame(
            ['width' => 360, 'height' => 2401, 'truncated' => false],
            buildScreenshotClip(360, 2400.4, self::WINDOW_HEIGHT)
        );
    }

    public function testShortPageIsCapturedAtWindowHeight(): void
    {
        $this->assertSame(
            ['width' => 1366, 'height' => self::WINDOW_HEIGHT, 'truncated' => false],
            buildScreenshotClip(1366, 300.0, self::WINDOW_HEIGHT)
        );
    }

    public function testVeryLongPageIsTruncatedAtLimit(): void
    {
        $clip = buildScreenshotClip(768, 250000.0, self::WINDOW_HEIGHT);

        $this->assertSame(SCREENSHOT_MAX_HEIGHT_PX, $clip['height']);
        $this->assertTrue($clip['truncated']);
        $this->assertFalse(buildScreenshotClip(768, (float) SCREENSHOT_MAX_HEIGHT_PX, self::WINDOW_HEIGHT)['truncated']);
    }

    public function testThumbnailIsTopOfPageScaledToThumbWidth(): void
    {
        $thumb = buildThumbnailClip(['width' => 1366, 'height' => 5000]);

        $this->assertSame(1366, $thumb['width']);
        $this->assertSame(1821, $thumb['height']);
        $this->assertEqualsWithDelta(SCREENSHOT_THUMB_WIDTH_PX, $thumb['width'] * $thumb['scale'], 0.001);
    }

    public function testThumbnailIsNotTallerThanScreenshot(): void
    {
        $this->assertSame(768, buildThumbnailClip(['width' => 1366, 'height' => 768])['height']);
    }

    public function testFilePaths(): void
    {
        $this->assertSame('12/34-360.jpg', buildScreenshotFilePath(12, 34, 360));
        $this->assertSame('12/34-360-thumb.jpg', buildThumbnailFilePath('12/34-360.jpg'));
    }

    public function testScreenshotIdParsing(): void
    {
        $this->assertSame(5, parseScreenshotId('5'));
        $this->assertNull(parseScreenshotId('0'));
        $this->assertNull(parseScreenshotId('abc'));
        $this->assertNull(parseScreenshotId('5.jpg'));
    }

    public function testSavedFilesAreResolvedInsideBaseDir(): void
    {
        saveScreenshotFiles('12/34-360.jpg', 'image', 'thumb', $this->baseDir);

        $this->assertSame('image', file_get_contents((string) resolveScreenshotPath('12/34-360.jpg', $this->baseDir)));
        $this->assertSame('thumb', file_get_contents((string) resolveScreenshotPath('12/34-360-thumb.jpg', $this->baseDir)));
        $this->assertNull(resolveScreenshotPath('12/34-768.jpg', $this->baseDir), 'файла нет');
    }

    public function testPathOutsideBaseDirIsRejected(): void
    {
        file_put_contents($this->baseDir . '/outside.jpg', 'secret');
        saveScreenshotFiles('12/34-360.jpg', 'image', 'thumb', $this->baseDir);

        $this->assertNull(resolveScreenshotPath('../outside.jpg', $this->baseDir));
        $this->assertNull(resolveScreenshotPath('12/../../outside.jpg', $this->baseDir));
        $this->assertNull(resolveScreenshotPath('/etc/passwd', $this->baseDir));
        $this->assertNull(resolveScreenshotPath('C:/Windows/win.ini', $this->baseDir));
        $this->assertNull(resolveScreenshotPath('12/34-360.php', $this->baseDir));
        $this->assertNull(resolveScreenshotPath('12/34-360.jpg', $this->baseDir . '/12'), 'другая папка-основа');
    }

    public function testEmptyImageIsNotSaved(): void
    {
        try {
            saveScreenshotFiles('12/34-360.jpg', 'image', '', $this->baseDir);
            $this->fail('ожидалось исключение');
        } catch (RuntimeException) {
            $this->assertFileDoesNotExist($this->baseDir . '/12/34-360.jpg');
        }
    }

    public function testDeleteRemovesDiagnosticFolderOnly(): void
    {
        saveScreenshotFiles('12/34-360.jpg', 'image', 'thumb', $this->baseDir);
        saveScreenshotFiles('13/35-360.jpg', 'image', 'thumb', $this->baseDir);

        $this->assertTrue(deleteDiagnosticScreenshots(12, $this->baseDir));
        $this->assertDirectoryDoesNotExist($this->baseDir . '/12');
        $this->assertFileExists($this->baseDir . '/13/35-360.jpg');
        $this->assertTrue(deleteDiagnosticScreenshots(99, $this->baseDir), 'папки нет — не ошибка');

        deleteDiagnosticScreenshots(13, $this->baseDir);
    }
}
