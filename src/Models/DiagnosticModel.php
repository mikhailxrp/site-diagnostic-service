<?php

declare(strict_types=1);

/**
 * Таблицы `diagnostics` и `diagnostic_steps`: диагностика и шаги её фонового выполнения.
 * Все отметки времени ставит MySQL (NOW()), а не PHP: heartbeat сравнивается по одним часам.
 */

require_once ROOT_PATH . '/src/Core/Database.php';

const DIAGNOSTIC_STALE_MESSAGE = 'Диагностика прервана: воркер перестал отвечать.';
const STEP_STALE_MESSAGE       = 'Шаг прерван: воркер перестал отвечать.';

function createDiagnostic(string $url, string $host, ?string $problemText): int
{
    $pdo = getPdo();
    $statement = $pdo->prepare(
        'INSERT INTO diagnostics (url, host, problem_text) VALUES (:url, :host, :problem_text)'
    );
    $statement->execute(['url' => $url, 'host' => $host, 'problem_text' => $problemText]);

    return (int) $pdo->lastInsertId();
}

/**
 * @return array<string, mixed>|null диагностика + `steps` (по `position`)
 */
function getDiagnosticWithSteps(int $id): ?array
{
    $pdo = getPdo();
    $statement = $pdo->prepare('SELECT * FROM diagnostics WHERE id = :id');
    $statement->execute(['id' => $id]);
    $diagnostic = $statement->fetch();

    if ($diagnostic === false) {
        return null;
    }

    $stepsStatement = $pdo->prepare(
        'SELECT * FROM diagnostic_steps WHERE diagnostic_id = :id ORDER BY position'
    );
    $stepsStatement->execute(['id' => $id]);
    $diagnostic['steps'] = $stepsStatement->fetchAll();

    return $diagnostic;
}

/**
 * Атомарно переводит `queued → running`. false — диагностики нет или её уже взял воркер.
 */
function claimDiagnostic(int $id): bool
{
    $statement = getPdo()->prepare(
        "UPDATE diagnostics
         SET status = 'running', started_at = NOW(), heartbeat_at = NOW()
         WHERE id = :id AND status = 'queued'"
    );
    $statement->execute(['id' => $id]);

    return $statement->rowCount() === 1;
}

/**
 * Создаёт все шаги в статусе `pending` одной транзакцией.
 *
 * @param list<array{code: string, title: string}> $steps
 */
function createDiagnosticSteps(int $diagnosticId, array $steps): void
{
    $pdo = getPdo();
    $statement = $pdo->prepare(
        'INSERT INTO diagnostic_steps (diagnostic_id, step_code, title, position)
         VALUES (:diagnostic_id, :step_code, :title, :position)'
    );

    $pdo->beginTransaction();
    try {
        foreach (array_values($steps) as $index => $step) {
            $statement->execute([
                'diagnostic_id' => $diagnosticId,
                'step_code'     => $step['code'],
                'title'         => $step['title'],
                'position'      => $index + 1,
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function markStepRunning(int $diagnosticId, string $stepCode): void
{
    $statement = getPdo()->prepare(
        "UPDATE diagnostic_steps SET status = 'running', started_at = NOW()
         WHERE diagnostic_id = :diagnostic_id AND step_code = :step_code"
    );
    $statement->execute(['diagnostic_id' => $diagnosticId, 'step_code' => $stepCode]);
}

function finishStep(int $diagnosticId, string $stepCode, string $status, ?string $message): void
{
    $statement = getPdo()->prepare(
        'UPDATE diagnostic_steps SET status = :status, message = :message, finished_at = NOW()
         WHERE diagnostic_id = :diagnostic_id AND step_code = :step_code'
    );
    $statement->execute([
        'status'        => $status,
        'message'       => $message,
        'diagnostic_id' => $diagnosticId,
        'step_code'     => $stepCode,
    ]);
}

/**
 * Прогресс и heartbeat — одной записью.
 */
function updateDiagnosticProgress(int $id, int $progress): void
{
    $statement = getPdo()->prepare(
        'UPDATE diagnostics SET progress = :progress, heartbeat_at = NOW() WHERE id = :id'
    );
    $statement->execute(['progress' => $progress, 'id' => $id]);
}

function touchDiagnosticHeartbeat(int $id): void
{
    $statement = getPdo()->prepare('UPDATE diagnostics SET heartbeat_at = NOW() WHERE id = :id');
    $statement->execute(['id' => $id]);
}

/**
 * Завершает диагностику. `done` выставляет прогресс 100, `failed` оставляет достигнутый.
 */
function finishDiagnostic(int $id, string $status, ?string $errorMessage): void
{
    $statement = getPdo()->prepare(
        "UPDATE diagnostics
         SET status = :status,
             error_message = :error_message,
             progress = IF(:is_done = 1, 100, progress),
             finished_at = NOW(),
             heartbeat_at = NOW()
         WHERE id = :id"
    );
    $statement->execute([
        'status'        => $status,
        'error_message' => $errorMessage,
        'is_done'       => $status === 'done' ? 1 : 0,
        'id'            => $id,
    ]);
}

/**
 * Если воркер молчит дольше порога, помечает диагностику `failed`, а зависший шаг — `failed`.
 * Одной транзакцией. true — диагностика была помечена прерванной.
 */
function failStaleDiagnostic(int $id, int $staleSeconds): bool
{
    $pdo = getPdo();
    $failDiagnostic = $pdo->prepare(
        "UPDATE diagnostics
         SET status = 'failed', error_message = :error_message, finished_at = NOW()
         WHERE id = :id
           AND status IN ('queued', 'running')
           AND COALESCE(heartbeat_at, started_at, created_at) < NOW() - INTERVAL :stale_seconds SECOND"
    );
    $failRunningStep = $pdo->prepare(
        "UPDATE diagnostic_steps
         SET status = 'failed', message = :message, finished_at = NOW()
         WHERE diagnostic_id = :id AND status = 'running'"
    );

    $pdo->beginTransaction();
    try {
        $failDiagnostic->execute([
            'error_message' => DIAGNOSTIC_STALE_MESSAGE,
            'id'            => $id,
            'stale_seconds' => $staleSeconds,
        ]);
        $isStale = $failDiagnostic->rowCount() === 1;

        if ($isStale) {
            $failRunningStep->execute(['message' => STEP_STALE_MESSAGE, 'id' => $id]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return $isStale;
}
