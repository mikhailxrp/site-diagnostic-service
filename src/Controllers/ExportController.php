<?php

declare(strict_types=1);

namespace App\Controllers;

use RuntimeException;
use Throwable;

require_once ROOT_PATH . '/src/Models/DiagnosticModel.php';
require_once ROOT_PATH . '/src/Models/FindingModel.php';
require_once ROOT_PATH . '/src/Models/PageModel.php';
require_once ROOT_PATH . '/src/Models/StackModel.php';
require_once ROOT_PATH . '/src/Models/ScreenshotModel.php';
require_once ROOT_PATH . '/src/Models/AiResultModel.php';
require_once ROOT_PATH . '/src/Models/SettingsModel.php';
require_once ROOT_PATH . '/src/Services/DiagnosticRunner.php';
require_once ROOT_PATH . '/src/Services/ReportBuilder.php';
require_once ROOT_PATH . '/src/Services/ClientReportBuilder.php';
require_once ROOT_PATH . '/src/Services/SettingsService.php';
require_once ROOT_PATH . '/src/Services/PdfService.php';

final class ExportController
{
    public const RATE_LIMIT_ACTION         = 'pdf_export';
    public const RATE_LIMIT_MAX_ATTEMPTS   = 5;
    public const RATE_LIMIT_WINDOW_SECONDS = 60;

    public function download(string $id): void
    {
        $diagnosticId = parseDiagnosticId($id);
        $version = normalizePdfVersion($_GET['version'] ?? null);

        if ($diagnosticId === null) {
            $this->fail('/history', 'Диагностика не найдена.');
        }
        $diagnosticUrl = '/diagnostics/' . $diagnosticId;
        if ($version === null) {
            $this->fail($diagnosticUrl, 'Неизвестная версия PDF. Выберите полный или клиентский отчёт.');
        }

        try {
            refreshStaleDiagnostic($diagnosticId);
            $diagnostic = getDiagnosticWithSteps($diagnosticId);
        } catch (Throwable $e) {
            logException($e, ['diagnostic_id' => $diagnosticId]);
            $this->fail($diagnosticUrl, 'Не удалось загрузить диагностику для PDF. Подробности в журнале.');
        }

        if ($diagnostic === null) {
            $this->fail('/history', 'Диагностика не найдена.');
        }
        if (!in_array((string) $diagnostic['status'], REPORT_STATUSES, true)) {
            $this->fail($diagnosticUrl, 'PDF будет доступен после завершения диагностики.');
        }

        $reportUrl = $version === PDF_VERSION_CLIENT ? $diagnosticUrl . '/client' : $diagnosticUrl;

        if (!$this->takePdfRateLimitAttempt($diagnosticId, $version)) {
            $this->fail($reportUrl, 'Слишком много экспортов подряд. Подождите минуту.');
        }

        closeSessionWrite();

        try {
            $screenshots = $version === PDF_VERSION_FULL ? getScreenshotsByDiagnostic($diagnosticId) : [];
            $viewData = $this->buildViewData($diagnostic, $version);
            $html = renderPdfView($version === PDF_VERSION_CLIENT ? 'pages/client-report' : 'pages/diagnostic', $viewData);
            $html = localizePdfHtml($html, $screenshots, $diagnosticId);
        } catch (Throwable $e) {
            logException($e, ['diagnostic_id' => $diagnosticId, 'pdf_version' => $version]);
            $this->fail($reportUrl, 'Не удалось подготовить отчёт для PDF. Подробности в журнале.');
        }

        $pdfPath = null;
        $exportFailed = false;

        try {
            $chromePath = resolveChromePath(getSetting(CHROME_PATH_SETTING));
            $pdfPath = createPdfFile($html, $diagnosticId, $chromePath);
            $this->sendPdf($pdfPath, buildPdfFilename($diagnostic, $version));
        } catch (Throwable $e) {
            logException($e, ['diagnostic_id' => $diagnosticId, 'pdf_version' => $version]);
            $exportFailed = true;
        } finally {
            removePdfTemporaryFile($pdfPath);
        }

        if ($exportFailed) {
            $this->fail(
                $reportUrl,
                'Не удалось создать PDF: Chrome недоступен или печать завершилась ошибкой. Подробности в журнале.'
            );
        }
    }

    /**
     * @param array<string, mixed> $diagnostic
     * @return array<string, mixed>
     */
    private function buildViewData(array $diagnostic, string $version): array
    {
        $diagnosticId = (int) $diagnostic['id'];
        $findings = getFindingsByDiagnostic($diagnosticId);
        $aiResults = getAiResultsByDiagnostic($diagnosticId);

        if ($version === PDF_VERSION_CLIENT) {
            return [
                'title'        => 'Отчёт для клиента',
                'report'       => buildClientReport($diagnostic, $findings, null, $aiResults),
                'technicalUrl' => null,
                'clientPdfUrl' => null,
            ];
        }

        $report = buildReport(
            $diagnostic,
            $findings,
            getStackItemsByDiagnostic($diagnosticId),
            getCheckedPagesByDiagnostic($diagnosticId),
            getScreenshotsByDiagnostic($diagnosticId),
            $aiResults,
            $aiResults !== [] ? $this->loadAiCatalog($diagnosticId) : null
        );

        return [
            'title'           => 'Диагностика #' . $diagnosticId,
            'activeMenu'      => 'new',
            'diagnostic'      => buildDiagnosticStatus($diagnostic, getDiagnosticSteps()),
            'statusUrl'       => null,
            'hasReport'       => true,
            'report'          => $report,
            'reportError'     => false,
            'aiRerunUrl'      => null,
            'clientReportUrl' => null,
            'fullPdfUrl'      => null,
            'printDate'       => (string) ($diagnostic['created_at'] ?? ''),
        ];
    }

    /**
     * Ошибка каталога цен RouterAI не отменяет экспорт, как и обычный технический отчёт.
     *
     * @return array<string, array>|null
     */
    private function loadAiCatalog(int $diagnosticId): ?array
    {
        try {
            return loadRouterAiModels()['models'];
        } catch (Throwable $e) {
            logException($e, ['diagnostic_id' => $diagnosticId]);

            return null;
        }
    }

    private function sendPdf(string $path, string $filename): void
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('PDF-файл недоступен для скачивания.');
        }

        $length = filesize($path);
        if ($length === false || $length === 0) {
            throw new RuntimeException('PDF-файл недоступен для скачивания.');
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . $length);
        header('Cache-Control: no-store');
        readfile($path);
    }

    private function takePdfRateLimitAttempt(int $diagnosticId, string $version): bool
    {
        if (takeRateLimitAttempt(
            self::RATE_LIMIT_ACTION,
            self::RATE_LIMIT_MAX_ATTEMPTS,
            self::RATE_LIMIT_WINDOW_SECONDS
        )) {
            return true;
        }

        logWarning('Превышен лимит экспорта PDF', [
            'diagnostic_id'  => $diagnosticId,
            'pdf_version'    => $version,
            'max_attempts'   => self::RATE_LIMIT_MAX_ATTEMPTS,
            'window_seconds' => self::RATE_LIMIT_WINDOW_SECONDS,
        ]);

        return false;
    }

    private function fail(string $path, string $message): never
    {
        setFlash('error', $message);
        redirect($path);
    }
}
