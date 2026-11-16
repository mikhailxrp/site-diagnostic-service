<?php

declare(strict_types=1);

namespace App\Controllers;

use Throwable;

require_once ROOT_PATH . '/src/Models/DiagnosticModel.php';
require_once ROOT_PATH . '/src/Services/DiagnosticStatusService.php';
require_once ROOT_PATH . '/src/Services/HistoryService.php';
require_once ROOT_PATH . '/src/Services/ScreenshotService.php';

/**
 * История диагностик: список с поиском и пагинацией, удаление.
 */
final class HistoryController
{
    private const HTTP_SERVER_ERROR = 500;

    public function index(): void
    {
        $queryInput = readHistoryQueryInput($_GET['q'] ?? '');
        $page       = readHistoryPageInput($_GET['page'] ?? 1);

        $rows       = [];
        $pagination = buildHistoryPagination(0, 1, HISTORY_PER_PAGE);
        $loadError  = false;

        try {
            $query      = normalizeHistoryQuery($queryInput);
            $pagination = buildHistoryPagination(countDiagnostics($query), $page, HISTORY_PER_PAGE);
            $rows       = buildHistoryRows(searchDiagnostics($query, $pagination['perPage'], $pagination['offset']));
        } catch (Throwable $e) {
            logException($e, ['query' => $queryInput, 'page' => $page]);
            http_response_code(self::HTTP_SERVER_ERROR);
            $loadError = true;
        }

        render('pages/history', [
            'title'           => 'История',
            'activeMenu'      => 'history',
            'query'           => $queryInput,
            'rows'            => $rows,
            'pagination'      => $pagination,
            'paginationLinks' => buildHistoryPaginationLinks($pagination, $queryInput),
            'loadError'       => $loadError,
            'resetUrl'        => HISTORY_PATH,
        ]);
    }

    /**
     * Удаление из истории: только завершённая диагностика, после — возврат на ту же страницу истории.
     */
    public function delete(string $id): void
    {
        requireCsrf();

        $backUrl = buildHistoryUrl(
            readHistoryQueryInput($_POST['q'] ?? ''),
            readHistoryPageInput($_POST['page'] ?? 1)
        );

        $diagnosticId = parseDiagnosticId($id);
        if ($diagnosticId === null) {
            setFlash('error', 'Диагностика не найдена.');
            redirect($backUrl);
        }

        try {
            $status = getDiagnosticStatus($diagnosticId);

            if ($status === null) {
                setFlash('error', 'Диагностика не найдена: возможно, она уже удалена.');
            } elseif (in_array($status, HISTORY_UNDELETABLE_STATUSES, true) || !deleteDiagnostic($diagnosticId)) {
                setFlash('error', 'Нельзя удалить: идёт проверка. Дождитесь её окончания.');
            } else {
                // Записи `screenshots` удалены каскадом, файлы — здесь; не вышло — запись всё равно удалена
                if (!deleteDiagnosticScreenshots($diagnosticId)) {
                    logWarning('Папка скриншотов удалённой диагностики не удалена', ['diagnostic_id' => $diagnosticId]);
                }
                setFlash('success', 'Диагностика #' . $diagnosticId . ' удалена.');
            }
        } catch (Throwable $e) {
            logException($e, ['diagnostic_id' => $diagnosticId]);
            setFlash('error', 'Не удалось удалить диагностику. Подробности в журнале.');
        }

        redirect($backUrl);
    }
}
