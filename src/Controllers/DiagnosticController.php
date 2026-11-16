<?php

declare(strict_types=1);

namespace App\Controllers;

use Throwable;

require_once ROOT_PATH . '/src/Models/DiagnosticModel.php';
require_once ROOT_PATH . '/src/Models/FindingModel.php';
require_once ROOT_PATH . '/src/Models/PageModel.php';
require_once ROOT_PATH . '/src/Models/StackModel.php';
require_once ROOT_PATH . '/src/Models/ScreenshotModel.php';
require_once ROOT_PATH . '/src/Services/DiagnosticRunner.php';
require_once ROOT_PATH . '/src/Services/JobLauncher.php';
require_once ROOT_PATH . '/src/Services/DiagnosticStatusService.php';
require_once ROOT_PATH . '/src/Services/UrlValidator.php';
require_once ROOT_PATH . '/src/Services/ReportBuilder.php';

/**
 * Запуск диагностики из формы, экран прогресса с отчётом и JSON для опроса.
 */
final class DiagnosticController
{
    /** Ключ сессии: ошибки полей и ввод после неудачной отправки (на один показ, читает HomeController) */
    public const FORM_STATE_KEY = 'diagnostic_form';

    /**
     * Не больше 5 запусков за 60 секунд: каждый запуск — отдельный процесс воркера
     * (позже — Chrome и платные запросы к ИИ). Успешный запуск счётчик не сбрасывает.
     */
    private const RATE_LIMIT_ACTION         = 'diagnostic_start';
    private const RATE_LIMIT_MAX_ATTEMPTS   = 5;
    private const RATE_LIMIT_WINDOW_SECONDS = 60;

    /** Как diagnostics.error_message VARCHAR(1000) */
    private const ERROR_MESSAGE_MAX_LENGTH = 1000;

    private const HTTP_NOT_FOUND    = 404;
    private const HTTP_SERVER_ERROR = 500;

    public function show(string $id): void
    {
        $diagnosticId = parseDiagnosticId($id);

        try {
            $row = $diagnosticId !== null ? $this->loadDiagnostic($diagnosticId) : null;
        } catch (Throwable $e) {
            logException($e, ['diagnostic_id' => $diagnosticId]);
            http_response_code(self::HTTP_SERVER_ERROR);
            $this->renderEmpty('Не удалось загрузить диагностику', 'Ошибка базы данных. Подробности в журнале.');
            return;
        }

        if ($row === null) {
            http_response_code(self::HTTP_NOT_FOUND);
            $this->renderEmpty('Диагностика не найдена', 'Проверьте ссылку: такой диагностики нет.');
            return;
        }

        $diagnostic = buildDiagnosticStatus($row, getDiagnosticSteps());
        $hasReport  = in_array($diagnostic['status'], REPORT_STATUSES, true);
        $report     = $hasReport ? $this->loadReport($row) : null;

        render('pages/diagnostic', [
            'title'       => 'Диагностика #' . $diagnostic['id'],
            'activeMenu'  => 'new',
            'diagnostic'  => $diagnostic,
            'statusUrl'   => '/diagnostics/' . $diagnostic['id'] . '/status',
            'hasReport'   => $hasReport,
            'report'      => $report,
            'reportError' => $hasReport && $report === null,
        ]);
    }

    public function status(string $id): void
    {
        $diagnosticId = parseDiagnosticId($id);

        try {
            $diagnostic = $diagnosticId !== null ? $this->loadStatus($diagnosticId) : null;
        } catch (Throwable $e) {
            logException($e, ['diagnostic_id' => $diagnosticId]);
            $this->sendJson(self::HTTP_SERVER_ERROR, ['error' => 'server_error']);
            return;
        }

        if ($diagnostic === null) {
            $this->sendJson(self::HTTP_NOT_FOUND, ['error' => 'not_found']);
            return;
        }

        $this->sendJson(200, $diagnostic);
    }

    /**
     * Форма «Новая диагностика» (главная): проверка ссылки → запись → запуск воркера → экран прогресса.
     */
    public function store(): void
    {
        requireCsrf();

        if (tooManyAttempts(self::RATE_LIMIT_ACTION, self::RATE_LIMIT_MAX_ATTEMPTS, self::RATE_LIMIT_WINDOW_SECONDS)) {
            logWarning('Превышен лимит запусков диагностики', [
                'max_attempts'   => self::RATE_LIMIT_MAX_ATTEMPTS,
                'window_seconds' => self::RATE_LIMIT_WINDOW_SECONDS,
            ]);
            $this->backToForm(
                readDiagnosticFormValues($_POST),
                [],
                'Слишком много запусков подряд. Подождите минуту и попробуйте снова.'
            );
        }
        hitRateLimit(self::RATE_LIMIT_ACTION, self::RATE_LIMIT_WINDOW_SECONDS);

        $form = validateDiagnosticInput($_POST, 'resolveHostIps');
        if ($form['errors'] !== []) {
            $this->backToForm($form['values'], $form['errors'], 'Диагностика не запущена: исправьте отмеченные поля.');
        }

        try {
            $previousId   = findLatestDiagnosticIdByHost($form['host']);
            $diagnosticId = createDiagnostic(
                $form['url'],
                $form['host'],
                $form['problemText'],
                $previousId,
                $form['productUrl']
            );
        } catch (Throwable $e) {
            logException($e);
            $this->backToForm($form['values'], [], 'Не удалось создать диагностику. Подробности в журнале.');
        }

        try {
            launchWorker($diagnosticId);
        } catch (Throwable $e) {
            logException($e, ['diagnostic_id' => $diagnosticId]);
            $this->markLaunchFailed($diagnosticId, $e->getMessage());
            setFlash('error', 'Не удалось запустить диагностику: ' . $e->getMessage());
        }

        redirect('/diagnostics/' . $diagnosticId);
    }

    /**
     * Зависшая без heartbeat диагностика помечается прерванной ещё до показа.
     *
     * @return array<string, mixed>|null строка `diagnostics` + `steps`
     */
    private function loadDiagnostic(int $diagnosticId): ?array
    {
        refreshStaleDiagnostic($diagnosticId);

        return getDiagnosticWithSteps($diagnosticId);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadStatus(int $diagnosticId): ?array
    {
        $diagnostic = $this->loadDiagnostic($diagnosticId);

        return $diagnostic !== null ? buildDiagnosticStatus($diagnostic, getDiagnosticSteps()) : null;
    }

    /**
     * Отчёт из находок, стека и страниц. null — не удалось прочитать (ошибка в журнале), прогресс всё равно виден.
     *
     * @param array<string, mixed> $diagnostic строка `diagnostics` + `steps`
     * @return array<string, mixed>|null
     */
    private function loadReport(array $diagnostic): ?array
    {
        $diagnosticId = (int) $diagnostic['id'];

        try {
            return buildReport(
                $diagnostic,
                getFindingsByDiagnostic($diagnosticId),
                getStackItemsByDiagnostic($diagnosticId),
                getCheckedPagesByDiagnostic($diagnosticId),
                getScreenshotsByDiagnostic($diagnosticId)
            );
        } catch (Throwable $e) {
            logException($e, ['diagnostic_id' => $diagnosticId]);

            return null;
        }
    }

    /**
     * Возврат на форму с введёнными значениями, ошибками полей и флешем. Не возвращается (redirect).
     *
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    private function backToForm(array $values, array $errors, string $flash): never
    {
        $_SESSION[self::FORM_STATE_KEY] = ['values' => $values, 'errors' => $errors];
        setFlash('error', $flash);
        redirect('/');
        exit;
    }

    private function markLaunchFailed(int $diagnosticId, string $reason): void
    {
        $message = mb_substr('Не удалось запустить воркер: ' . $reason, 0, self::ERROR_MESSAGE_MAX_LENGTH);

        try {
            finishDiagnostic($diagnosticId, 'failed', $message);
        } catch (Throwable $e) {
            logException($e, ['diagnostic_id' => $diagnosticId]);
        }
    }

    private function renderEmpty(string $emptyTitle, string $emptyText): void
    {
        render('pages/diagnostic', [
            'title'      => $emptyTitle,
            'activeMenu' => 'new',
            'diagnostic' => null,
            'emptyText'  => $emptyText,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function sendJson(int $statusCode, array $data): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
