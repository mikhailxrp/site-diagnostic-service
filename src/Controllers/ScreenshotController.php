<?php

declare(strict_types=1);

namespace App\Controllers;

use Throwable;

require_once ROOT_PATH . '/src/Models/ScreenshotModel.php';
require_once ROOT_PATH . '/src/Services/ScreenshotService.php';

/**
 * Отдача файлов скриншотов из `storage/screenshots/` (вне `public/`): только по записи в БД и только если файл
 * лежит внутри папки скриншотов. Любой отказ — 404 без подробностей, причина — в журнале.
 */
final class ScreenshotController
{
    private const HTTP_NOT_FOUND    = 404;
    private const HTTP_SERVER_ERROR = 500;
    private const CACHE_CONTROL     = 'Cache-Control: private, max-age=86400'; // файл снимка не меняется

    public function show(string $id): void
    {
        $this->send($id, false);
    }

    public function thumb(string $id): void
    {
        $this->send($id, true);
    }

    private function send(string $id, bool $thumbnail): void
    {
        closeSessionWrite();

        $screenshotId = parseScreenshotId($id);
        if ($screenshotId === null) {
            $this->notFound();
            return;
        }

        try {
            $screenshot = getScreenshot($screenshotId);
        } catch (Throwable $e) {
            logException($e, ['screenshot_id' => $screenshotId]);
            http_response_code(self::HTTP_SERVER_ERROR);
            return;
        }

        if ($screenshot === null) {
            $this->notFound();
            return;
        }

        $filePath = (string) $screenshot['file_path'];
        $path = resolveScreenshotPath($thumbnail ? buildThumbnailFilePath($filePath) : $filePath);
        if ($path === null) {
            logWarning('Файл скриншота не отдан: нет файла или путь вне папки скриншотов', [
                'screenshot_id' => $screenshotId,
                'file_path'     => $filePath,
                'thumbnail'     => $thumbnail,
            ]);
            $this->notFound();
            return;
        }

        header('Content-Type: image/jpeg');
        header('Content-Length: ' . filesize($path));
        header(self::CACHE_CONTROL);
        readfile($path);
    }

    private function notFound(): void
    {
        http_response_code(self::HTTP_NOT_FOUND);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Скриншот не найден.';
    }
}
