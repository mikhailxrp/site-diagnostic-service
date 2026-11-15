<?php

declare(strict_types=1);

/**
 * Выполнение диагностики по шагам: список шагов, цикл с try/catch на шаг, расчёт прогресса.
 * Упавший шаг помечается `failed`, остальные выполняются дальше.
 */

require_once ROOT_PATH . '/src/Models/DiagnosticModel.php';

const HEARTBEAT_STALE_SECONDS = 600; // 10 минут без отметки воркера → диагностика прервана
const STEP_MESSAGE_MAX_LENGTH = 1000; // как diagnostic_steps.message VARCHAR(1000)
const STUB_STEP_DELAY_SECONDS = 2;    // заглушки ждут, чтобы прогресс был виден на экране (таск 6)

const PROGRESS_MAX = 100;

const STEP_STATUS_DONE    = 'done';
const STEP_STATUS_SKIPPED = 'skipped';
const STEP_STATUS_FAILED  = 'failed';

/**
 * Шаги диагностики по порядку. Обработчик возвращает
 * `['status' => 'done'|'skipped', 'message' => ?string]` или бросает исключение.
 *
 * @return list<array{code: string, title: string, handler: callable}>
 */
function getDiagnosticSteps(): array
{
    $stub = static function (): array {
        sleep(STUB_STEP_DELAY_SECONDS);

        return ['status' => STEP_STATUS_DONE, 'message' => null];
    };

    return [
        ['code' => 'availability', 'title' => 'Доступность сайта', 'handler' => $stub],
        ['code' => 'stack',        'title' => 'Определение стека', 'handler' => $stub],
        ['code' => 'resources',    'title' => 'Битые ресурсы',     'handler' => $stub],
        ['code' => 'report',       'title' => 'Сборка отчёта',     'handler' => $stub],
    ];
}

/**
 * Процент выполнения 0–100; 100 — только когда закончены все шаги.
 */
function calculateProgress(int $finished, int $total): int
{
    if ($total <= 0) {
        return PROGRESS_MAX;
    }

    $finished = max(0, min($finished, $total));

    return intdiv($finished * PROGRESS_MAX, $total);
}

function truncateStepMessage(string $message): string
{
    return mb_substr($message, 0, STEP_MESSAGE_MAX_LENGTH);
}

/**
 * Выполняет шаги по порядку, без БД: о каждом шаге сообщает через колбэки.
 * Исключение в шаге → статус `failed` с сообщением, следующие шаги выполняются.
 *
 * @param list<array{code: string, title: string, handler: callable}> $steps
 * @param callable(array $step): void $onStepStart
 * @param callable(array $step, string $status, ?string $message, int $progress, ?Throwable $error): void $onStepFinish
 */
function executeSteps(array $steps, callable $onStepStart, callable $onStepFinish): void
{
    $total = count($steps);
    $finished = 0;

    foreach ($steps as $step) {
        $onStepStart($step);

        $error = null;
        try {
            $result  = ($step['handler'])();
            $status  = ($result['status'] ?? null) === STEP_STATUS_SKIPPED ? STEP_STATUS_SKIPPED : STEP_STATUS_DONE;
            $message = isset($result['message']) ? truncateStepMessage((string) $result['message']) : null;
        } catch (Throwable $e) {
            $error   = $e;
            $status  = STEP_STATUS_FAILED;
            $message = truncateStepMessage($e->getMessage() !== '' ? $e->getMessage() : get_class($e));
        }

        $finished++;
        $onStepFinish($step, $status, $message, calculateProgress($finished, $total), $error);
    }
}

/**
 * Полный прогон диагностики воркером. Ничего не делает, если диагностика не в `queued`.
 *
 * @return bool true — диагностика выполнена этим вызовом
 */
function runDiagnostic(int $id): bool
{
    if (!claimDiagnostic($id)) {
        return false;
    }

    $steps = getDiagnosticSteps();
    createDiagnosticSteps($id, $steps);

    executeSteps(
        $steps,
        static function (array $step) use ($id): void {
            markStepRunning($id, $step['code']);
            touchDiagnosticHeartbeat($id);
        },
        static function (array $step, string $status, ?string $message, int $progress, ?Throwable $error) use ($id): void {
            if ($error !== null) {
                logException($error, ['diagnostic_id' => $id, 'step_code' => $step['code']]);
            }
            finishStep($id, $step['code'], $status, $message);
            updateDiagnosticProgress($id, $progress);
        }
    );

    finishDiagnostic($id, 'done', null);

    return true;
}

/**
 * Для экрана диагностики (таск 6): зависшая без heartbeat диагностика помечается прерванной.
 */
function refreshStaleDiagnostic(int $id): void
{
    failStaleDiagnostic($id, HEARTBEAT_STALE_SECONDS);
}
