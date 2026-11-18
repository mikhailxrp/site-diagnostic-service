<?php

declare(strict_types=1);

namespace App\Controllers;

use Throwable;

require_once ROOT_PATH . '/src/Models/DiagnosticModel.php';
require_once ROOT_PATH . '/src/Models/FindingModel.php';
require_once ROOT_PATH . '/src/Models/PageModel.php';
require_once ROOT_PATH . '/src/Models/StackModel.php';
require_once ROOT_PATH . '/src/Models/ScreenshotModel.php';
require_once ROOT_PATH . '/src/Models/AiResultModel.php';
require_once ROOT_PATH . '/src/Services/DiagnosticRunner.php';
require_once ROOT_PATH . '/src/Services/JobLauncher.php';
require_once ROOT_PATH . '/src/Services/DiagnosticStatusService.php';
require_once ROOT_PATH . '/src/Services/UrlValidator.php';
require_once ROOT_PATH . '/src/Services/ReportBuilder.php';
require_once ROOT_PATH . '/src/Services/ClientReportBuilder.php';

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

    /**
     * Перезапуск ИИ-анализа — платные запросы к RouterAI: не больше 3 за 60 секунд.
     */
    private const AI_RERUN_RATE_LIMIT_ACTION         = 'ai_rerun';
    private const AI_RERUN_RATE_LIMIT_MAX_ATTEMPTS   = 3;
    private const AI_RERUN_RATE_LIMIT_WINDOW_SECONDS = 60;

    /** Перезапуск ИИ принимается только у завершённой диагностики */
    private const AI_RERUN_STATUS = 'done';

    /** Как diagnostics.error_message VARCHAR(1000) */
    private const ERROR_MESSAGE_MAX_LENGTH = 1000;

    private const HTTP_NOT_FOUND    = 404;
    private const HTTP_CONFLICT     = 409;
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
            'aiRerunUrl'  => $diagnostic['status'] === self::AI_RERUN_STATUS
                ? '/diagnostics/' . $diagnostic['id'] . '/ai-rerun'
                : null,
            'clientReportUrl' => $report !== null
                ? '/diagnostics/' . $diagnostic['id'] . '/client'
                : null,
        ]);
    }

    /**
     * Клиентская версия готового или частичного отчёта без технических деталей.
     */
    public function client(string $id): void
    {
        $diagnosticId = parseDiagnosticId($id);

        try {
            $diagnostic = $diagnosticId !== null ? $this->loadDiagnostic($diagnosticId) : null;
        } catch (Throwable $e) {
            logException($e, ['diagnostic_id' => $diagnosticId]);
            http_response_code(self::HTTP_SERVER_ERROR);
            $this->renderClientEmpty(
                'Не удалось загрузить отчёт',
                'Ошибка базы данных. Подробности сохранены в журнале.'
            );
            return;
        }

        if ($diagnostic === null) {
            http_response_code(self::HTTP_NOT_FOUND);
            $this->renderClientEmpty('Отчёт не найден', 'Проверьте ссылку: такой диагностики нет.');
            return;
        }

        if (!in_array((string) $diagnostic['status'], REPORT_STATUSES, true)) {
            http_response_code(self::HTTP_CONFLICT);
            $this->renderClientEmpty(
                'Отчёт ещё не готов',
                'Дождитесь завершения диагностики и откройте клиентскую версию снова.',
                '/diagnostics/' . $diagnosticId
            );
            return;
        }

        try {
            $report = buildClientReport(
                $diagnostic,
                getFindingsByDiagnostic($diagnosticId),
                null,
                getAiResultsByDiagnostic($diagnosticId)
            );
        } catch (Throwable $e) {
            logException($e, ['diagnostic_id' => $diagnosticId]);
            http_response_code(self::HTTP_SERVER_ERROR);
            $this->renderClientEmpty(
                'Не удалось собрать отчёт',
                'Попробуйте открыть страницу снова. Подробности сохранены в журнале.',
                '/diagnostics/' . $diagnosticId
            );
            return;
        }

        render('pages/client-report', [
            'title'        => 'Отчёт для клиента',
            'report'       => $report,
            'technicalUrl' => '/diagnostics/' . $diagnosticId,
        ]);
    }

    /**
     * «Перезапустить ИИ-анализ»: завершённая диагностика ставится в очередь, воркер проходит только ИИ-шаги
     * по сохранённым фактам → экран прогресса. Работающая диагностика не принимается.
     */
    public function rerunAi(string $id): void
    {
        requireCsrf();

        $diagnosticId = parseDiagnosticId($id);
        if ($diagnosticId === null) {
            setFlash('error', 'Диагностика не найдена.');
            redirect('/history');
        }
        $diagnosticUrl = '/diagnostics/' . $diagnosticId;

        if (tooManyAttempts(
            self::AI_RERUN_RATE_LIMIT_ACTION,
            self::AI_RERUN_RATE_LIMIT_MAX_ATTEMPTS,
            self::AI_RERUN_RATE_LIMIT_WINDOW_SECONDS
        )) {
            logWarning('Превышен лимит перезапусков ИИ-анализа', [
                'diagnostic_id'  => $diagnosticId,
                'max_attempts'   => self::AI_RERUN_RATE_LIMIT_MAX_ATTEMPTS,
                'window_seconds' => self::AI_RERUN_RATE_LIMIT_WINDOW_SECONDS,
            ]);
            setFlash('error', 'Слишком много перезапусков подряд. Подождите минуту и попробуйте снова.');
            redirect($diagnosticUrl);
        }
        hitRateLimit(self::AI_RERUN_RATE_LIMIT_ACTION, self::AI_RERUN_RATE_LIMIT_WINDOW_SECONDS);

        try {
            $isQueued = queueAiRerun($diagnosticId);
        } catch (Throwable $e) {
            logException($e, ['diagnostic_id' => $diagnosticId]);
            setFlash('error', 'Не удалось перезапустить ИИ-анализ. Подробности в журнале.');
            redirect($diagnosticUrl);
        }

        if (!$isQueued) {
            setFlash('error', 'ИИ-анализ не перезапущен: диагностика ещё выполняется или не завершена.');
            redirect($diagnosticUrl);
        }

        try {
            launchWorker($diagnosticId, WORKER_MODE_AI_ONLY);
        } catch (Throwable $e) {
            logException($e, ['diagnostic_id' => $diagnosticId]);
            $this->restoreAfterRerunLaunchFailed($diagnosticId);
            setFlash('error', 'Не удалось перезапустить ИИ-анализ: ' . $e->getMessage());
        }

        redirect($diagnosticUrl);
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
     * Отчёт из находок, стека, страниц, скриншотов и ответов ИИ. null — не удалось прочитать (ошибка в журнале), прогресс всё равно виден.
     *
     * @param array<string, mixed> $diagnostic строка `diagnostics` + `steps`
     * @return array<string, mixed>|null
     */
    private function loadReport(array $diagnostic): ?array
    {
        $diagnosticId = (int) $diagnostic['id'];

        try {
            $aiResults = getAiResultsByDiagnostic($diagnosticId);

            return buildReport(
                $diagnostic,
                getFindingsByDiagnostic($diagnosticId),
                getStackItemsByDiagnostic($diagnosticId),
                getCheckedPagesByDiagnostic($diagnosticId),
                getScreenshotsByDiagnostic($diagnosticId),
                $aiResults,
                $aiResults !== [] ? $this->loadAiCatalog($diagnosticId) : null
            );
        } catch (Throwable $e) {
            logException($e, ['diagnostic_id' => $diagnosticId]);

            return null;
        }
    }

    /**
     * Модели RouterAI с ценами для стоимости ИИ-блоков: свежий кэш — без сети. Сбой → null, отчёт строится без сумм.
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

    private function restoreAfterRerunLaunchFailed(int $diagnosticId): void
    {
        try {
            restoreDiagnosticDone($diagnosticId);
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

    private function renderClientEmpty(string $emptyTitle, string $emptyText, ?string $technicalUrl = null): void
    {
        render('pages/client-report', [
            'title'        => $emptyTitle,
            'report'       => null,
            'emptyTitle'   => $emptyTitle,
            'emptyText'    => $emptyText,
            'technicalUrl' => $technicalUrl,
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
