<?php

declare(strict_types=1);

/**
 * Скриншоты страниц: размеры снимков (вся страница с пределом высоты и миниатюра её верха), имена файлов,
 * сохранение в `storage/screenshots/<diagnostic_id>/`, безопасное получение пути для отдачи, удаление папки
 * диагностики. Сам снимок делает Chrome (`captureBrowserScreenshots()` в `BrowserService.php`).
 */

const SCREENSHOT_BASE_PATH          = ROOT_PATH . '/storage/screenshots';
const SCREENSHOT_MAX_HEIGHT_PX      = 10000; // длиннее — обрезается: у Chrome предел текстуры, файл не раздувается
const SCREENSHOT_JPEG_QUALITY       = 80;
const SCREENSHOT_THUMB_WIDTH_PX     = 320;
const SCREENSHOT_THUMB_HEIGHT_RATIO = 4 / 3;   // миниатюра — верх страницы, высота = ширина × 4/3
const SCREENSHOT_THUMB_JPEG_QUALITY = 70;
const SCREENSHOT_CAPTURE_TIMEOUT_MS = 30000;   // снимок длинной страницы Chrome готовит секунды
const SCREENSHOT_FILE_EXTENSION     = '.jpg';
const SCREENSHOT_THUMB_SUFFIX       = '-thumb';
const SCREENSHOT_DIR_PERMISSIONS    = 0775;

/** Имя файла внутри папки диагностики: `<page_id>-<width>.jpg` или `…-thumb.jpg` */
const SCREENSHOT_FILE_NAME_PATTERN = '~^\d+/\d+-\d+(-thumb)?\.jpg$~';
const SCREENSHOT_ID_PATTERN        = '/^[1-9][0-9]{0,9}$/';

/**
 * id скриншота из адреса: положительное целое, иначе null.
 */
function parseScreenshotId(string $raw): ?int
{
    return preg_match(SCREENSHOT_ID_PATTERN, $raw) === 1 ? (int) $raw : null;
}

/**
 * Область полного снимка: ширина окна, высота содержимого (не меньше окна) с пределом SCREENSHOT_MAX_HEIGHT_PX.
 *
 * @return array{width: int, height: int, truncated: bool}
 */
function buildScreenshotClip(int $width, float $contentHeight, int $windowHeight): array
{
    $height = max((int) ceil($contentHeight), $windowHeight, 1);

    return [
        'width'     => $width,
        'height'    => min($height, SCREENSHOT_MAX_HEIGHT_PX),
        'truncated' => $height > SCREENSHOT_MAX_HEIGHT_PX,
    ];
}

/**
 * Область миниатюры: верх страницы ширина × 4/3 (не выше полного снимка), масштаб — до SCREENSHOT_THUMB_WIDTH_PX.
 *
 * @param array{width: int, height: int} $clip область полного снимка
 * @return array{width: int, height: int, scale: float}
 */
function buildThumbnailClip(array $clip): array
{
    return [
        'width'  => $clip['width'],
        'height' => min((int) round($clip['width'] * SCREENSHOT_THUMB_HEIGHT_RATIO), $clip['height']),
        'scale'  => min(1.0, SCREENSHOT_THUMB_WIDTH_PX / $clip['width']),
    ];
}

/**
 * Путь полного снимка относительно `storage/screenshots/`: `<diagnostic_id>/<page_id>-<width>.jpg`.
 */
function buildScreenshotFilePath(int $diagnosticId, int $pageId, int $width): string
{
    return $diagnosticId . '/' . $pageId . '-' . $width . SCREENSHOT_FILE_EXTENSION;
}

/**
 * Путь миниатюры из пути полного снимка: `12/34-360.jpg` → `12/34-360-thumb.jpg`.
 */
function buildThumbnailFilePath(string $filePath): string
{
    return substr($filePath, 0, -strlen(SCREENSHOT_FILE_EXTENSION)) . SCREENSHOT_THUMB_SUFFIX . SCREENSHOT_FILE_EXTENSION;
}

/**
 * Абсолютный путь файла для отдачи или null: имя не по шаблону, файла нет, `realpath` вне `$baseDir`.
 */
function resolveScreenshotPath(string $filePath, string $baseDir = SCREENSHOT_BASE_PATH): ?string
{
    if (preg_match(SCREENSHOT_FILE_NAME_PATTERN, $filePath) !== 1) {
        return null;
    }

    $realBase = realpath($baseDir);
    $realFile = realpath($baseDir . '/' . $filePath);
    if ($realBase === false || $realFile === false || !is_file($realFile)) {
        return null;
    }

    return str_starts_with($realFile, $realBase . DIRECTORY_SEPARATOR) ? $realFile : null;
}

/**
 * Сохраняет полный снимок и миниатюру. Ошибка записи — исключение, недописанные файлы удаляются.
 *
 * @throws RuntimeException
 */
function saveScreenshotFiles(string $filePath, string $image, string $thumbnail, string $baseDir = SCREENSHOT_BASE_PATH): void
{
    $fullPath = $baseDir . '/' . $filePath;
    $thumbPath = $baseDir . '/' . buildThumbnailFilePath($filePath);
    $dir = dirname($fullPath);

    if (!is_dir($dir) && !mkdir($dir, SCREENSHOT_DIR_PERMISSIONS, true) && !is_dir($dir)) {
        throw new RuntimeException('не удалось создать папку ' . $dir);
    }

    foreach ([$fullPath => $image, $thumbPath => $thumbnail] as $path => $binary) {
        if ($binary === '' || file_put_contents($path, $binary) !== strlen($binary)) {
            foreach ([$fullPath, $thumbPath] as $written) {
                if (is_file($written)) {
                    unlink($written);
                }
            }
            throw new RuntimeException('не удалось записать файл ' . basename($path));
        }
    }
}

/**
 * Удаляет папку скриншотов диагностики (файлы в ней — без вложенных папок).
 *
 * @return bool true — папки больше нет (или не было)
 */
function deleteDiagnosticScreenshots(int $diagnosticId, string $baseDir = SCREENSHOT_BASE_PATH): bool
{
    $dir = $baseDir . '/' . $diagnosticId;
    if (!is_dir($dir)) {
        return true;
    }

    try {
        foreach (new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS) as $item) {
            if ($item->isFile()) {
                unlink($item->getPathname());
            }
        }
        rmdir($dir);
    } catch (Throwable) {
        // файл занят — итог по наличию папки
    }
    clearstatcache(true, $dir);

    return !is_dir($dir);
}
