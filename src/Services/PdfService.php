<?php

declare(strict_types=1);

require_once ROOT_PATH . '/src/Services/BrowserService.php';
require_once ROOT_PATH . '/src/Services/ScreenshotService.php';

const PDF_VERSION_FULL   = 'full';
const PDF_VERSION_CLIENT = 'client';
const PDF_VERSIONS       = [PDF_VERSION_FULL, PDF_VERSION_CLIENT];

const PDF_RANDOM_BYTES          = 8;
const PDF_DIRECTORY_PERMISSIONS = 0775;
const PDF_DOMAIN_MAX_LENGTH     = 100;
const PDF_FALLBACK_DOMAIN       = 'site';
const PDF_FALLBACK_DATE         = 'unknown-date';
const PDF_MAGIC_HEADER          = '%PDF-';
const PDF_EMPTY_IMAGE_URI       = 'data:,';

const PDF_LOCAL_STYLES = [
    '/assets/vendor/tabler/css/tabler.min.css' => '/public/assets/vendor/tabler/css/tabler.min.css',
    '/assets/vendor/tabler/icons/tabler-icons.min.css' => '/public/assets/vendor/tabler/icons/tabler-icons.min.css',
    '/assets/css/admin-theme.css' => '/public/assets/css/admin-theme.css',
    '/assets/css/print.css' => '/public/assets/css/print.css',
];

function normalizePdfVersion(mixed $version): ?string
{
    return is_string($version) && in_array($version, PDF_VERSIONS, true) ? $version : null;
}

/**
 * @param array<string, mixed> $diagnostic
 */
function buildPdfFilename(array $diagnostic, string $version): string
{
    if (normalizePdfVersion($version) === null) {
        throw new InvalidArgumentException('Недопустимая версия PDF.');
    }

    $domain = strtolower((string) ($diagnostic['host'] ?? ''));
    $domain = preg_replace('/[^a-z0-9.-]+/', '-', $domain) ?? '';
    $domain = trim((string) preg_replace('/-+/', '-', $domain), '.-');
    $domain = substr($domain, 0, PDF_DOMAIN_MAX_LENGTH);
    $domain = trim($domain, '.-');
    $domain = $domain !== '' ? $domain : PDF_FALLBACK_DOMAIN;

    $createdAt = (string) ($diagnostic['created_at'] ?? '');
    $date = preg_match('/^(\d{4}-\d{2}-\d{2})/', $createdAt, $matches) === 1
        ? $matches[1]
        : PDF_FALLBACK_DATE;

    return "diagnostic-{$domain}-{$date}-{$version}.pdf";
}

function pathToFileUri(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $encoded = rawurlencode($path);
    $encoded = str_replace(['%3A', '%2F'], [':', '/'], $encoded);

    return 'file://' . (str_starts_with($encoded, '/') ? '' : '/') . $encoded;
}

/**
 * Переводит CSS и снимки на локальные URI. Снимок разрешается только по записи текущей диагностики.
 *
 * @param list<array<string, mixed>> $screenshots
 */
function localizePdfHtml(
    string $html,
    array $screenshots,
    int $diagnosticId,
    string $projectRoot = ROOT_PATH,
    string $screenshotBase = SCREENSHOT_BASE_PATH
): string {
    foreach (PDF_LOCAL_STYLES as $url => $relativePath) {
        $uri = pathToFileUri($projectRoot . $relativePath);
        $html = str_replace(
            ['href="' . $url . '"', "href='" . $url . "'"],
            ['href="' . $uri . '"', "href='" . $uri . "'"],
            $html
        );
    }

    $allowed = [];
    foreach ($screenshots as $screenshot) {
        $id = $screenshot['id'] ?? null;
        $filePath = $screenshot['file_path'] ?? null;
        if (is_int($id) && $id > 0 && is_string($filePath)) {
            $allowed[$id] = $filePath;
        }
    }

    return (string) preg_replace_callback(
        '~(?P<prefix>\bsrc\s*=\s*)(?P<quote>["\'])/screenshots/(?P<path>[^"\']+)(?P=quote)~',
        static function (array $match) use ($allowed, $diagnosticId, $screenshotBase): string {
            $quote = $match['quote'];
            $path = $match['path'];
            $pathMatched = preg_match('~^([1-9][0-9]{0,9})(/thumb)?$~', $path, $parts) === 1;
            $screenshotId = $pathMatched ? (int) $parts[1] : null;
            $thumbnail = $pathMatched && ($parts[2] ?? '') === '/thumb';
            $filePath = $screenshotId !== null ? ($allowed[$screenshotId] ?? null) : null;

            if (
                $filePath === null
                || !str_starts_with($filePath, $diagnosticId . '/')
            ) {
                logWarning('Скриншот не включён в PDF', [
                    'diagnostic_id' => $diagnosticId,
                    'screenshot_id' => $screenshotId,
                    'reason'        => 'not_owned_or_invalid',
                ]);

                return $match['prefix'] . $quote . PDF_EMPTY_IMAGE_URI . $quote;
            }

            $relativePath = $thumbnail ? buildThumbnailFilePath($filePath) : $filePath;
            $resolved = resolveScreenshotPath($relativePath, $screenshotBase);
            if ($resolved === null) {
                logWarning('Скриншот не включён в PDF', [
                    'diagnostic_id' => $diagnosticId,
                    'screenshot_id' => $screenshotId,
                    'reason'        => 'file_not_found_or_unsafe',
                ]);

                return $match['prefix'] . $quote . PDF_EMPTY_IMAGE_URI . $quote;
            }

            return $match['prefix'] . $quote . pathToFileUri($resolved) . $quote;
        },
        $html
    );
}

/**
 * @param array<string, mixed> $data
 */
function renderPdfView(string $view, array $data): string
{
    $bufferLevel = ob_get_level();
    $previousMode = $GLOBALS['pdfExportMode'] ?? null;
    $GLOBALS['pdfExportMode'] = true;
    ob_start();

    try {
        render($view, $data + ['isPdfExport' => true]);

        return (string) ob_get_clean();
    } catch (Throwable $e) {
        while (ob_get_level() > $bufferLevel) {
            ob_end_clean();
        }
        throw $e;
    } finally {
        if ($previousMode === null) {
            unset($GLOBALS['pdfExportMode']);
        } else {
            $GLOBALS['pdfExportMode'] = $previousMode;
        }
    }
}

/**
 * Создаёт PDF и удаляет HTML. Успешный PDF удаляет контроллер после отдачи.
 * $printer — точка для тестирования без запуска Chrome.
 */
function createPdfFile(
    string $html,
    int $diagnosticId,
    string $chromePath,
    string $tempDir = STORAGE_PATH . '/temp',
    string $pdfDir = PDF_PATH,
    ?callable $printer = null
): string {
    ensurePdfDirectory($tempDir);
    ensurePdfDirectory($pdfDir);

    $suffix = bin2hex(random_bytes(PDF_RANDOM_BYTES));
    $htmlPath = $tempDir . "/diagnostic-{$diagnosticId}-{$suffix}.html";
    $pdfPath = $pdfDir . "/diagnostic-{$diagnosticId}-{$suffix}.pdf";
    $printer ??= static function (
        string $chrome,
        string $htmlUri,
        string $outputPath,
        int $ownerId
    ): void {
        printBrowserHtmlToPdf($chrome, $htmlUri, $outputPath, $ownerId);
    };

    try {
        writePdfSourceFile($htmlPath, $html);
        $printer($chromePath, pathToFileUri($htmlPath), $pdfPath, $diagnosticId);
        assertValidPdfFile($pdfPath);

        return $pdfPath;
    } catch (Throwable $e) {
        removePdfTemporaryFile($pdfPath);
        throw $e;
    } finally {
        removePdfTemporaryFile($htmlPath);
    }
}

function ensurePdfDirectory(string $directory): void
{
    if (!is_dir($directory) && !mkdir($directory, PDF_DIRECTORY_PERMISSIONS, true) && !is_dir($directory)) {
        throw new RuntimeException('Не удалось создать папку для экспорта PDF.');
    }
}

function writePdfSourceFile(string $path, string $html): void
{
    $handle = fopen($path, 'x');
    if ($handle === false) {
        throw new RuntimeException('Не удалось создать временный HTML-файл.');
    }

    try {
        $length = strlen($html);
        $written = 0;
        while ($written < $length) {
            $result = fwrite($handle, substr($html, $written));
            if ($result === false || $result === 0) {
                throw new RuntimeException('Не удалось записать временный HTML-файл.');
            }
            $written += $result;
        }
    } finally {
        fclose($handle);
    }
}

function assertValidPdfFile(string $path): void
{
    if (!is_file($path) || filesize($path) === 0) {
        throw new RuntimeException('Chrome не создал PDF-файл.');
    }

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Не удалось открыть созданный PDF-файл.');
    }

    try {
        $header = fread($handle, strlen(PDF_MAGIC_HEADER));
    } finally {
        fclose($handle);
    }

    if ($header !== PDF_MAGIC_HEADER) {
        throw new RuntimeException('Chrome вернул некорректный PDF-файл.');
    }
}

function removePdfTemporaryFile(?string $path): void
{
    if ($path === null || !is_file($path)) {
        return;
    }

    try {
        if (!unlink($path)) {
            logWarning('Не удалось удалить временный файл PDF', ['file' => basename($path)]);
        }
    } catch (Throwable $e) {
        logException($e, ['service' => 'pdf', 'action' => 'cleanup']);
    }
}
