<?php

declare(strict_types=1);

/**
 * Выполнение диагностики по шагам: общий контекст, цикл с try/catch на шаг, остановка проверки, прогресс.
 * Упавший шаг помечается `failed`, остальные выполняются дальше. Шаг может остановить проверку (`stop`):
 * тогда оставшиеся шаги помечаются `skipped` с причиной.
 */

require_once ROOT_PATH . '/src/Models/DiagnosticModel.php';
require_once ROOT_PATH . '/src/Models/FindingModel.php';

const HEARTBEAT_STALE_SECONDS = 600;  // 10 минут без отметки воркера → диагностика прервана
const STEP_MESSAGE_MAX_LENGTH = 1000; // как diagnostic_steps.message VARCHAR(1000)

const PROGRESS_MAX = 100;

const STEP_STATUS_DONE    = 'done';
const STEP_STATUS_SKIPPED = 'skipped';
const STEP_STATUS_FAILED  = 'failed';

const STEP_NOT_IMPLEMENTED_MESSAGE = 'Проверка появится в следующей версии.';
const STEP_STOPPED_MESSAGE         = 'Пропущено: шаг «%s» остановил проверку.';

/**
 * Шаги диагностики по порядку. Обработчик получает контекст и возвращает
 * `['status' => 'done'|'skipped', 'message' => ?string, 'facts' => array, 'findings' => list, 'stop' => bool]`
 * (всё, кроме `status`, необязательно) или бросает исключение.
 *
 * @return list<array{code: string, title: string, handler: callable}>
 */
function getDiagnosticSteps(): array
{
    // До тасков 3–5 настоящих проверок нет: честно «пропущено», а не «готово»
    $notImplemented = static fn (array $context): array => [
        'status'  => STEP_STATUS_SKIPPED,
        'message' => STEP_NOT_IMPLEMENTED_MESSAGE,
    ];

    return [
        ['code' => 'availability', 'title' => 'Доступность сайта', 'handler' => $notImplemented],
        ['code' => 'stack',        'title' => 'Определение стека', 'handler' => $notImplemented],
        ['code' => 'resources',    'title' => 'Битые ресурсы',     'handler' => $notImplemented],
    ];
}

/**
 * Начальный контекст шагов: сама диагностика и пустые факты.
 *
 * @param array<string, mixed> $diagnostic строка `diagnostics`
 * @return array{diagnostic: array{id: int, url: string, host: string, problem_text: ?string}, facts: array}
 */
function buildStepContext(array $diagnostic): array
{
    return [
        'diagnostic' => [
            'id'           => (int) $diagnostic['id'],
            'url'          => (string) $diagnostic['url'],
            'host'         => (string) $diagnostic['host'],
            'problem_text' => isset($diagnostic['problem_text']) ? (string) $diagnostic['problem_text'] : null,
        ],
        'facts' => [],
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
 * Приводит ответ обработчика к одному виду.
 *
 * @return array{status: string, message: ?string, facts: array, findings: list<array>, stop: bool}
 */
function normalizeStepResult(mixed $result): array
{
    $result = is_array($result) ? $result : [];

    return [
        'status'   => ($result['status'] ?? null) === STEP_STATUS_SKIPPED ? STEP_STATUS_SKIPPED : STEP_STATUS_DONE,
        'message'  => isset($result['message']) ? truncateStepMessage((string) $result['message']) : null,
        'facts'    => isset($result['facts']) && is_array($result['facts']) ? $result['facts'] : [],
        'findings' => isset($result['findings']) && is_array($result['findings'])
            ? array_values(array_filter($result['findings'], 'is_array'))
            : [],
        'stop'     => ($result['stop'] ?? false) === true,
    ];
}

/**
 * Выполняет шаги по порядку, без БД: о каждом шаге сообщает через колбэки.
 * Исключение в шаге → `failed` с сообщением, его факты и находки не принимаются, следующие шаги выполняются.
 * `stop` → оставшиеся шаги `skipped` с причиной (`onStepStart` для них не вызывается).
 *
 * @param list<array{code: string, title: string, handler: callable}> $steps
 * @param array<string, mixed> $context начальный контекст (`buildStepContext()`)
 * @param callable(array $step): void $onStepStart
 * @param callable(array $step, string $status, ?string $message, int $progress, ?Throwable $error, list<array> $findings): void $onStepFinish
 * @return array<string, mixed> контекст после всех шагов
 */
function executeSteps(array $steps, array $context, callable $onStepStart, callable $onStepFinish): array
{
    $total = count($steps);
    $finished = 0;
    $stoppedBy = null;

    foreach ($steps as $step) {
        if ($stoppedBy !== null) {
            $finished++;
            $message = truncateStepMessage(sprintf(STEP_STOPPED_MESSAGE, $stoppedBy['title']));
            $onStepFinish($step, STEP_STATUS_SKIPPED, $message, calculateProgress($finished, $total), null, []);
            continue;
        }

        $onStepStart($step);

        $error = null;
        $findings = [];
        try {
            $result   = normalizeStepResult(($step['handler'])($context));
            $status   = $result['status'];
            $message  = $result['message'];
            $findings = $result['findings'];
            $context['facts'][$step['code']] = $result['facts'];

            if ($result['stop']) {
                $stoppedBy = $step;
            }
        } catch (Throwable $e) {
            $error   = $e;
            $status  = STEP_STATUS_FAILED;
            $message = truncateStepMessage($e->getMessage() !== '' ? $e->getMessage() : get_class($e));
        }

        $finished++;
        $onStepFinish($step, $status, $message, calculateProgress($finished, $total), $error, $findings);
    }

    return $context;
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

    $diagnostic = getDiagnosticWithSteps($id);
    if ($diagnostic === null) {
        throw new RuntimeException("Диагностика {$id} пропала после запуска.");
    }

    $steps = getDiagnosticSteps();
    createDiagnosticSteps($id, $steps);

    executeSteps(
        $steps,
        buildStepContext($diagnostic),
        static function (array $step) use ($id): void {
            markStepRunning($id, $step['code']);
            touchDiagnosticHeartbeat($id);
        },
        static function (
            array $step,
            string $status,
            ?string $message,
            int $progress,
            ?Throwable $error,
            array $findings
        ) use ($id): void {
            if ($error !== null) {
                logException($error, ['diagnostic_id' => $id, 'step_code' => $step['code']]);
            }
            foreach ($findings as $finding) {
                insertFinding($id, $finding);
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
