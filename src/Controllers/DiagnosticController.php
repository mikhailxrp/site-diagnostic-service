<?php

declare(strict_types=1);

namespace App\Controllers;

use Throwable;

require_once ROOT_PATH . '/src/Models/DiagnosticModel.php';
require_once ROOT_PATH . '/src/Services/DiagnosticRunner.php';
require_once ROOT_PATH . '/src/Services/JobLauncher.php';
require_once ROOT_PATH . '/src/Services/DiagnosticStatusService.php';

/**
 * Экран прогресса диагностики, JSON для опроса и временный запуск диагностики-заглушки.
 */
final class DiagnosticController
{
    /** Временный запуск (до формы фазы 1): шаги — заглушки, сайт не запрашивается */
    private const TEST_URL  = 'https://example.com';
    private const TEST_HOST = 'example.com';

    /** Как diagnostics.error_message VARCHAR(1000) */
    private const ERROR_MESSAGE_MAX_LENGTH = 1000;

    private const HTTP_NOT_FOUND    = 404;
    private const HTTP_SERVER_ERROR = 500;

    public function show(string $id): void
    {
        $diagnosticId = parseDiagnosticId($id);

        try {
            $diagnostic = $diagnosticId !== null ? $this->loadStatus($diagnosticId) : null;
        } catch (Throwable $e) {
            logException($e, ['diagnostic_id' => $diagnosticId]);
            http_response_code(self::HTTP_SERVER_ERROR);
            $this->renderEmpty('Не удалось загрузить диагностику', 'Ошибка базы данных. Подробности в журнале.');
            return;
        }

        if ($diagnostic === null) {
            http_response_code(self::HTTP_NOT_FOUND);
            $this->renderEmpty('Диагностика не найдена', 'Проверьте ссылку: такой диагностики нет.');
            return;
        }

        render('pages/diagnostic', [
            'title'      => 'Диагностика #' . $diagnostic['id'],
            'activeMenu' => 'new',
            'diagnostic' => $diagnostic,
            'statusUrl'  => '/diagnostics/' . $diagnostic['id'] . '/status',
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
     * Временная кнопка «Тестовый запуск» на главной — уберётся с формой фазы 1.
     */
    public function start(): void
    {
        requireCsrf();

        try {
            $diagnosticId = createDiagnostic(self::TEST_URL, self::TEST_HOST, null);
        } catch (Throwable $e) {
            logException($e);
            setFlash('error', 'Не удалось создать диагностику. Подробности в журнале.');
            redirect('/');
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
     * @return array<string, mixed>|null
     */
    private function loadStatus(int $diagnosticId): ?array
    {
        refreshStaleDiagnostic($diagnosticId);
        $diagnostic = getDiagnosticWithSteps($diagnosticId);

        return $diagnostic !== null ? buildDiagnosticStatus($diagnostic, getDiagnosticSteps()) : null;
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
