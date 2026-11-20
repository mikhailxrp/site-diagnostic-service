<?php

declare(strict_types=1);

/**
 * Таблицы `diagnostics` и `diagnostic_steps`: диагностика и шаги её фонового выполнения.
 * Все отметки времени ставит MySQL (NOW()), а не PHP: heartbeat сравнивается по одним часам.
 */

require_once ROOT_PATH . '/src/Core/Database.php';

const DIAGNOSTIC_STALE_MESSAGE = 'Диагностика прервана: воркер перестал отвечать.';
const STEP_STALE_MESSAGE       = 'Шаг прерван: воркер перестал отвечать.';

/**
 * @param string|null $productUrl адрес товара из формы (null — искать товар автоматически)
 */
function createDiagnostic(
    string $url,
    string $host,
    ?string $problemText,
    ?int $previousId,
    ?string $productUrl = null
): int {
    $pdo = getPdo();
    $statement = $pdo->prepare(
        'INSERT INTO diagnostics (url, host, product_url, problem_text, previous_id)
         VALUES (:url, :host, :product_url, :problem_text, :previous_id)'
    );
    $statement->execute([
        'url'          => $url,
        'host'         => $host,
        'product_url'  => $productUrl,
        'problem_text' => $problemText,
        'previous_id'  => $previousId,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Последняя диагностика этого хоста (без `www.`) — для `previous_id`. null — хост проверяется впервые.
 */
function findLatestDiagnosticIdByHost(string $host): ?int
{
    $statement = getPdo()->prepare('SELECT id FROM diagnostics WHERE host = :host ORDER BY id DESC LIMIT 1');
    $statement->execute(['host' => $host]);
    $id = $statement->fetchColumn();

    return $id === false ? null : (int) $id;
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
 * Ставит завершённую диагностику в очередь на перезапуск ИИ-анализа: атомарно `done → queued`.
 * Heartbeat обновляется, чтобы диагностику в очереди не пометило зависшей.
 * false — диагностики нет или она не в `done` (например, ещё выполняется).
 */
function queueAiRerun(int $id): bool
{
    $statement = getPdo()->prepare(
        "UPDATE diagnostics SET status = 'queued', heartbeat_at = NOW()
         WHERE id = :id AND status = 'done'"
    );
    $statement->execute(['id' => $id]);

    return $statement->rowCount() === 1;
}

/**
 * Откат постановки в очередь, если воркер перезапуска не запустился: `queued → done`.
 * Шаги и находки к этому моменту не тронуты.
 */
function restoreDiagnosticDone(int $id): void
{
    $statement = getPdo()->prepare(
        "UPDATE diagnostics SET status = 'done' WHERE id = :id AND status = 'queued'"
    );
    $statement->execute(['id' => $id]);
}

/**
 * Воркер перезапуска ИИ берёт диагностику: атомарно `queued → running`, `started_at` первого запуска не меняется.
 * false — диагностики нет или её уже взял воркер.
 */
function claimAiRerun(int $id): bool
{
    $statement = getPdo()->prepare(
        "UPDATE diagnostics SET status = 'running', error_message = NULL, heartbeat_at = NOW()
         WHERE id = :id AND status = 'queued'"
    );
    $statement->execute(['id' => $id]);

    return $statement->rowCount() === 1;
}

/**
 * Подготовка перезапуска ИИ одной транзакцией: только для диагностики в статусе `running`.
 * Недостающие ИИ-шаги добавляются в конец списка, существующие сбрасываются в `pending`, старые клиентские
 * тексты очищаются, находки `source = 'ai'` удаляются (история `ai_results` остаётся), прогресс — 0.
 *
 * @param list<array{code: string, title: string}> $steps ИИ-шаги по порядку
 * @return bool false — диагностика уже не в статусе `running` (транзакция откатывается без изменений)
 */
function resetAiSteps(int $id, array $steps, ?PDO $pdo = null): bool
{
    $pdo ??= getPdo();
    $resetProgress = $pdo->prepare(
        "UPDATE diagnostics SET progress = 0, heartbeat_at = NOW() WHERE id = :id AND status = 'running'"
    );
    $maxPosition = $pdo->prepare(
        'SELECT COALESCE(MAX(position), 0) FROM diagnostic_steps WHERE diagnostic_id = :id'
    );
    $findStep = $pdo->prepare(
        'SELECT 1 FROM diagnostic_steps WHERE diagnostic_id = :diagnostic_id AND step_code = :step_code'
    );
    $updateStep = $pdo->prepare(
        "UPDATE diagnostic_steps
         SET status = 'pending', message = NULL, started_at = NULL, finished_at = NULL
         WHERE diagnostic_id = :diagnostic_id AND step_code = :step_code"
    );
    $insertStep = $pdo->prepare(
        'INSERT INTO diagnostic_steps (diagnostic_id, step_code, title, position)
         VALUES (:diagnostic_id, :step_code, :title, :position)'
    );
    $deleteAiFindings = $pdo->prepare(
        "DELETE FROM findings WHERE diagnostic_id = :id AND source = 'ai'"
    );
    $clearClientTexts = $pdo->prepare(
        'UPDATE findings SET client_text = NULL WHERE diagnostic_id = :id'
    );

    $pdo->beginTransaction();
    try {
        $resetProgress->execute(['id' => $id]);
        if ($resetProgress->rowCount() !== 1) {
            $pdo->rollBack();

            return false;
        }

        $maxPosition->execute(['id' => $id]);
        $position = (int) $maxPosition->fetchColumn();

        foreach (array_values($steps) as $index => $step) {
            $findStep->execute([
                'diagnostic_id' => $id,
                'step_code'     => $step['code'],
            ]);
            if ($findStep->fetchColumn() !== false) {
                $updateStep->execute([
                    'diagnostic_id' => $id,
                    'step_code'     => $step['code'],
                ]);
            } else {
                $insertStep->execute([
                    'diagnostic_id' => $id,
                    'step_code'     => $step['code'],
                    'title'         => $step['title'],
                    'position'      => $position + $index + 1,
                ]);
            }
        }
        $clearClientTexts->execute(['id' => $id]);
        $deleteAiFindings->execute(['id' => $id]);
        $pdo->commit();

        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
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

/**
 * Переводит шаг в `running`, только пока сама диагностика остаётся в статусе `running`.
 */
function markStepRunning(int $diagnosticId, string $stepCode, ?PDO $pdo = null): bool
{
    $pdo ??= getPdo();
    $statement = $pdo->prepare(
        "UPDATE diagnostic_steps
         SET status = 'running', started_at = NOW()
         WHERE diagnostic_id = :diagnostic_id
           AND step_code = :step_code
           AND EXISTS (
               SELECT 1 FROM diagnostics WHERE id = :running_diagnostic_id AND status = 'running'
           )"
    );
    $statement->execute([
        'diagnostic_id'         => $diagnosticId,
        'step_code'             => $stepCode,
        'running_diagnostic_id' => $diagnosticId,
    ]);

    return $statement->rowCount() === 1;
}

/**
 * Завершает шаг, только пока сама диагностика остаётся в статусе `running`.
 */
function finishStep(int $diagnosticId, string $stepCode, string $status, ?string $message, ?PDO $pdo = null): bool
{
    $pdo ??= getPdo();
    $statement = $pdo->prepare(
        "UPDATE diagnostic_steps
         SET status = :status, message = :message, finished_at = NOW()
         WHERE diagnostic_id = :diagnostic_id
           AND step_code = :step_code
           AND EXISTS (
               SELECT 1 FROM diagnostics WHERE id = :running_diagnostic_id AND status = 'running'
           )"
    );
    $statement->execute([
        'status'                => $status,
        'message'               => $message,
        'diagnostic_id'         => $diagnosticId,
        'step_code'             => $stepCode,
        'running_diagnostic_id' => $diagnosticId,
    ]);

    return $statement->rowCount() === 1;
}

/**
 * Прогресс и heartbeat — одной записью (только пока диагностика `running`).
 */
function updateDiagnosticProgress(int $id, int $progress, ?PDO $pdo = null): bool
{
    $pdo ??= getPdo();
    $statement = $pdo->prepare(
        "UPDATE diagnostics SET progress = :progress, heartbeat_at = NOW() WHERE id = :id AND status = 'running'"
    );
    $statement->bindValue('progress', $progress, PDO::PARAM_INT);
    $statement->bindValue('id', $id, PDO::PARAM_INT);
    $statement->execute();

    return $statement->rowCount() === 1;
}

/**
 * Дописывает сводку в `summary_json` (JSON_MERGE_PATCH): ключи других шагов и фаз сохраняются.
 * Обновляется только при `status = 'running'`.
 *
 * @param array<string, mixed> $summary
 */
function updateDiagnosticSummary(int $id, array $summary, ?PDO $pdo = null): bool
{
    $json = json_encode(
        $summary,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
    );

    $pdo ??= getPdo();
    $statement = $pdo->prepare(
        "UPDATE diagnostics
         SET summary_json = JSON_MERGE_PATCH(COALESCE(summary_json, JSON_OBJECT()), CAST(:summary AS JSON))
         WHERE id = :id AND status = 'running'"
    );
    $statement->execute(['summary' => $json, 'id' => $id]);

    return $statement->rowCount() === 1;
}

/**
 * Обновляет `heartbeat_at`, только пока диагностика в статусе `running`.
 */
function touchDiagnosticHeartbeat(int $id, ?PDO $pdo = null): bool
{
    $pdo ??= getPdo();
    $statement = $pdo->prepare("UPDATE diagnostics SET heartbeat_at = NOW() WHERE id = :id AND status = 'running'");
    $statement->execute(['id' => $id]);

    return $statement->rowCount() === 1;
}

/**
 * Завершает выполняющуюся диагностику (`running → done|failed`). `done` выставляет прогресс 100,
 * `failed` оставляет достигнутый. false — диагностика уже не в статусе `running` (удалена или прервана).
 */
function finishDiagnostic(int $id, string $status, ?string $errorMessage, ?PDO $pdo = null): bool
{
    $pdo ??= getPdo();
    $statement = $pdo->prepare(
        "UPDATE diagnostics
         SET status = :status,
             error_message = :error_message,
             progress = CASE WHEN :is_done = 1 THEN 100 ELSE progress END,
             finished_at = NOW(),
             heartbeat_at = NOW()
         WHERE id = :id AND status = 'running'"
    );
    $statement->bindValue('status', $status);
    $statement->bindValue('error_message', $errorMessage, $errorMessage === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $statement->bindValue('is_done', $status === 'done' ? 1 : 0, PDO::PARAM_INT);
    $statement->bindValue('id', $id, PDO::PARAM_INT);
    $statement->execute();

    return $statement->rowCount() === 1;
}

/**
 * Помечает `failed` диагностику, которая ещё не стартовала в воркере (`queued → failed`),
 * например при ошибке запуска фонового процесса.
 */
function failQueuedDiagnostic(int $id, string $errorMessage, ?PDO $pdo = null): bool
{
    $pdo ??= getPdo();
    $statement = $pdo->prepare(
        "UPDATE diagnostics
         SET status = 'failed', error_message = :error_message, finished_at = NOW(), heartbeat_at = NOW()
         WHERE id = :id AND status = 'queued'"
    );
    $statement->execute([
        'error_message' => $errorMessage,
        'id'            => $id,
    ]);

    return $statement->rowCount() === 1;
}

/**
 * Помечает активные шаги (`running`) прерванными, если диагностика ещё в статусе `running`.
 */
function failRunningSteps(int $diagnosticId, string $message, ?PDO $pdo = null): int
{
    $pdo ??= getPdo();
    $statement = $pdo->prepare(
        "UPDATE diagnostic_steps
         SET status = 'failed', message = :message, finished_at = NOW()
         WHERE diagnostic_id = :diagnostic_id
           AND status = 'running'
           AND EXISTS (
               SELECT 1 FROM diagnostics WHERE id = :running_diagnostic_id AND status = 'running'
           )"
    );
    $statement->execute([
        'message'               => $message,
        'diagnostic_id'         => $diagnosticId,
        'running_diagnostic_id' => $diagnosticId,
    ]);

    return $statement->rowCount();
}

/**
 * Атомарно переводит выполняющуюся диагностику и её активные шаги (`running`) в `failed`.
 * Ничего не меняет, если диагностика уже завершена, прервана или удалена.
 */
function failRunningDiagnostic(int $id, string $errorMessage, string $stepMessage, ?PDO $pdo = null): bool
{
    $pdo ??= getPdo();
    $failDiagnostic = $pdo->prepare(
        "UPDATE diagnostics
         SET status = 'failed', error_message = :error_message, finished_at = NOW(), heartbeat_at = NOW()
         WHERE id = :id AND status = 'running'"
    );
    $failRunningStep = $pdo->prepare(
        "UPDATE diagnostic_steps
         SET status = 'failed', message = :message, finished_at = NOW()
         WHERE diagnostic_id = :id AND status = 'running'"
    );

    $pdo->beginTransaction();
    try {
        $failDiagnostic->execute([
            'error_message' => $errorMessage,
            'id'            => $id,
        ]);
        $failed = $failDiagnostic->rowCount() === 1;

        if ($failed) {
            $failRunningStep->execute([
                'message' => $stepMessage,
                'id'      => $id,
            ]);
        }
        $pdo->commit();

        return $failed;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
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

/**
 * Условие поиска по части адреса: `host` или `url` содержат строку (`%`, `_` и `\` ищутся буквально).
 * Пустой запрос — все диагностики.
 *
 * @return array{0: string, 1: array<string, string>} SQL-условие и параметры
 */
function buildDiagnosticSearchCondition(string $query): array
{
    if ($query === '') {
        return ['1 = 1', []];
    }

    $pattern = '%' . addcslashes($query, '\%_') . '%';

    return ['(d.host LIKE :host_pattern OR d.url LIKE :url_pattern)', [
        'host_pattern' => $pattern,
        'url_pattern'  => $pattern,
    ]];
}

/**
 * Страница истории: новые сверху, со счётчиками находок (критичные, высокие, всего).
 *
 * @return list<array<string, mixed>>
 */
function searchDiagnostics(string $query, int $limit, int $offset): array
{
    [$condition, $params] = buildDiagnosticSearchCondition($query);

    $statement = getPdo()->prepare(
        "SELECT d.id, d.url, d.host, d.status, d.created_at,
                (SELECT COUNT(*) FROM findings f
                  WHERE f.diagnostic_id = d.id AND f.severity = 'critical') AS critical_count,
                (SELECT COUNT(*) FROM findings f
                  WHERE f.diagnostic_id = d.id AND f.severity = 'high') AS high_count,
                (SELECT COUNT(*) FROM findings f WHERE f.diagnostic_id = d.id) AS finding_count
         FROM diagnostics d
         WHERE {$condition}
         ORDER BY d.id DESC
         LIMIT :limit OFFSET :offset"
    );
    foreach ($params as $name => $value) {
        $statement->bindValue($name, $value);
    }
    $statement->bindValue('limit', $limit, PDO::PARAM_INT);
    $statement->bindValue('offset', $offset, PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

function countDiagnostics(string $query): int
{
    [$condition, $params] = buildDiagnosticSearchCondition($query);

    $statement = getPdo()->prepare("SELECT COUNT(*) FROM diagnostics d WHERE {$condition}");
    $statement->execute($params);

    return (int) $statement->fetchColumn();
}

/**
 * null — диагностики нет.
 */
function getDiagnosticStatus(int $id): ?string
{
    $statement = getPdo()->prepare('SELECT status FROM diagnostics WHERE id = :id');
    $statement->execute(['id' => $id]);
    $status = $statement->fetchColumn();

    return $status === false ? null : (string) $status;
}

/**
 * Удаляет диагностику, если она не выполняется (`queued` / `running` — воркер ещё пишет в её записи;
 * тот же список — HISTORY_UNDELETABLE_STATUSES). Шаги, страницы, стек и находки удаляются каскадом (FK),
 * у следующих диагностик хоста `previous_id` становится NULL. Один DELETE — атомарно.
 *
 * @return bool false — диагностики нет или она выполняется
 */
function deleteDiagnostic(int $id): bool
{
    $statement = getPdo()->prepare(
        "DELETE FROM diagnostics WHERE id = :id AND status NOT IN ('queued', 'running')"
    );
    $statement->execute(['id' => $id]);

    return $statement->rowCount() > 0;
}
