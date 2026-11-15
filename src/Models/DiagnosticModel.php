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

/**
 * Дописывает сводку в `summary_json` (JSON_MERGE_PATCH): ключи других шагов и фаз сохраняются.
 *
 * @param array<string, mixed> $summary
 */
function updateDiagnosticSummary(int $id, array $summary): void
{
    $json = json_encode(
        $summary,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
    );

    $statement = getPdo()->prepare(
        "UPDATE diagnostics
         SET summary_json = JSON_MERGE_PATCH(COALESCE(summary_json, JSON_OBJECT()), CAST(:summary AS JSON))
         WHERE id = :id"
    );
    $statement->execute(['summary' => $json, 'id' => $id]);
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
