<?php

declare(strict_types=1);

namespace Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/src/Models/DiagnosticModel.php';

/**
 * Тесты оптимистичной блокировки и транзакций `DiagnosticModel` на in-memory SQLite.
 */
final class DiagnosticModelTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $this->pdo->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'));

        $this->pdo->exec(
            "CREATE TABLE diagnostics (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                url           TEXT NOT NULL,
                host          TEXT NOT NULL,
                product_url   TEXT NULL,
                problem_text  TEXT NULL,
                status        TEXT NOT NULL DEFAULT 'queued',
                progress      INTEGER NOT NULL DEFAULT 0,
                error_message TEXT NULL,
                previous_id   INTEGER NULL,
                summary_json  TEXT NULL,
                started_at    TEXT NULL,
                finished_at   TEXT NULL,
                heartbeat_at  TEXT NULL,
                created_at    TEXT DEFAULT CURRENT_TIMESTAMP
            )"
        );

        $this->pdo->exec(
            'CREATE TABLE diagnostic_steps (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                diagnostic_id INTEGER NOT NULL,
                step_code     TEXT NOT NULL,
                title         TEXT NOT NULL,
                position      INTEGER NOT NULL,
                status        TEXT NOT NULL DEFAULT \'pending\',
                message       TEXT NULL,
                started_at    TEXT NULL,
                finished_at   TEXT NULL,
                UNIQUE (diagnostic_id, step_code)
            )'
        );

        $this->pdo->exec(
            'CREATE TABLE findings (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                diagnostic_id INTEGER NOT NULL,
                page_id       INTEGER NULL,
                source        TEXT NOT NULL,
                category      TEXT NOT NULL,
                severity      TEXT NOT NULL,
                title         TEXT NOT NULL,
                details       TEXT NULL,
                client_text   TEXT NULL,
                location      TEXT NULL,
                evidence      TEXT NULL,
                fingerprint   TEXT NOT NULL,
                ai_result_id  INTEGER NULL,
                created_at    TEXT DEFAULT CURRENT_TIMESTAMP
            )'
        );
    }

    private function insertDiagnostic(
        string $status,
        int $progress = 0,
        ?string $errorMessage = null,
        ?string $heartbeatAt = null
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO diagnostics (url, host, status, progress, error_message, heartbeat_at)
             VALUES (:url, :host, :status, :progress, :error_message, :heartbeat_at)'
        );
        $statement->execute([
            'url'           => 'https://shop.test/',
            'host'          => 'shop.test',
            'status'        => $status,
            'progress'      => $progress,
            'error_message' => $errorMessage,
            'heartbeat_at'  => $heartbeatAt,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertStep(
        int $diagnosticId,
        string $code,
        int $position,
        string $status = 'pending',
        ?string $message = null
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO diagnostic_steps (diagnostic_id, step_code, title, position, status, message, started_at, finished_at)
             VALUES (:diagnostic_id, :step_code, :title, :position, :status, :message, :started_at, :finished_at)'
        );
        $statement->execute([
            'diagnostic_id' => $diagnosticId,
            'step_code'     => $code,
            'title'         => 'Шаг ' . $code,
            'position'      => $position,
            'status'        => $status,
            'message'       => $message,
            'started_at'    => $status !== 'pending' ? '2026-11-20 10:00:00' : null,
            'finished_at'   => in_array($status, ['done', 'failed', 'skipped'], true) ? '2026-11-20 10:01:00' : null,
        ]);
    }

    private function fetchDiagnostic(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM diagnostics WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function fetchStepsByCode(int $diagnosticId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM diagnostic_steps WHERE diagnostic_id = :id ORDER BY position');
        $statement->execute(['id' => $diagnosticId]);
        $steps = [];
        foreach ($statement->fetchAll() as $row) {
            $steps[$row['step_code']] = $row;
        }

        return $steps;
    }

    public function testFinishDiagnosticUpdatesOnlyRunningAndSetsProgress100ForDone(): void
    {
        $runningDoneId = $this->insertDiagnostic('running', 45);
        $this->assertTrue(finishDiagnostic($runningDoneId, 'done', null, $this->pdo));
        $doneRow = $this->fetchDiagnostic($runningDoneId);
        $this->assertSame('done', $doneRow['status']);
        $this->assertSame(100, (int) $doneRow['progress']);
        $this->assertNull($doneRow['error_message']);
        $this->assertNotNull($doneRow['finished_at']);

        $runningFailedId = $this->insertDiagnostic('running', 42);
        $this->assertTrue(finishDiagnostic($runningFailedId, 'failed', 'Сбой воркера', $this->pdo));
        $failedRow = $this->fetchDiagnostic($runningFailedId);
        $this->assertSame('failed', $failedRow['status']);
        $this->assertSame(42, (int) $failedRow['progress']);
        $this->assertSame('Сбой воркера', $failedRow['error_message']);

        // Запись, уже помеченная stale как failed, не перезаписывается обратно в done
        $staleFailedId = $this->insertDiagnostic('failed', 35, DIAGNOSTIC_STALE_MESSAGE);
        $this->assertFalse(finishDiagnostic($staleFailedId, 'done', null, $this->pdo));
        $staleRow = $this->fetchDiagnostic($staleFailedId);
        $this->assertSame('failed', $staleRow['status']);
        $this->assertSame(35, (int) $staleRow['progress']);
        $this->assertSame(DIAGNOSTIC_STALE_MESSAGE, $staleRow['error_message']);

        $queuedId = $this->insertDiagnostic('queued', 0);
        $this->assertFalse(finishDiagnostic($queuedId, 'done', null, $this->pdo));
        $this->assertSame('queued', $this->fetchDiagnostic($queuedId)['status']);

        $this->assertFalse(finishDiagnostic(999999, 'done', null, $this->pdo));
    }

    public function testTouchDiagnosticHeartbeatUpdatesOnlyRunningEvenRepeatedInSameSecond(): void
    {
        $runningId = $this->insertDiagnostic('running', 10, null, '2026-11-20 10:00:00');
        $this->assertTrue(touchDiagnosticHeartbeat($runningId, $this->pdo));
        $firstHeartbeat = $this->fetchDiagnostic($runningId)['heartbeat_at'];
        $this->assertNotSame('2026-11-20 10:00:00', $firstHeartbeat);
        $this->assertTrue(touchDiagnosticHeartbeat($runningId, $this->pdo));

        foreach (['failed', 'done', 'queued'] as $status) {
            $id = $this->insertDiagnostic($status, 10, null, '2026-11-20 10:00:00');
            $this->assertFalse(touchDiagnosticHeartbeat($id, $this->pdo), $status);
            $this->assertSame('2026-11-20 10:00:00', $this->fetchDiagnostic($id)['heartbeat_at']);
        }

        $this->assertFalse(touchDiagnosticHeartbeat(999999, $this->pdo));
    }

    public function testUpdateDiagnosticProgressUpdatesOnlyRunning(): void
    {
        $runningId = $this->insertDiagnostic('running', 20);
        $this->assertTrue(updateDiagnosticProgress($runningId, 55, $this->pdo));
        $this->assertSame(55, (int) $this->fetchDiagnostic($runningId)['progress']);

        $failedId = $this->insertDiagnostic('failed', 30, DIAGNOSTIC_STALE_MESSAGE);
        $this->assertFalse(updateDiagnosticProgress($failedId, 90, $this->pdo));
        $this->assertSame(30, (int) $this->fetchDiagnostic($failedId)['progress']);
    }

    public function testMarkStepRunningAndFinishStepRequireRunningDiagnostic(): void
    {
        $runningId = $this->insertDiagnostic('running', 10);
        $this->insertStep($runningId, 'seo', 1, 'pending');

        $this->assertTrue(markStepRunning($runningId, 'seo', $this->pdo));
        $steps = $this->fetchStepsByCode($runningId);
        $this->assertSame('running', $steps['seo']['status']);
        $this->assertNotNull($steps['seo']['started_at']);

        $this->assertTrue(finishStep($runningId, 'seo', 'done', 'Готово', $this->pdo));
        $steps = $this->fetchStepsByCode($runningId);
        $this->assertSame('done', $steps['seo']['status']);
        $this->assertSame('Готово', $steps['seo']['message']);
        $this->assertNotNull($steps['seo']['finished_at']);

        $failedId = $this->insertDiagnostic('failed', 10, DIAGNOSTIC_STALE_MESSAGE);
        $this->insertStep($failedId, 'seo', 1, 'pending');
        $this->insertStep($failedId, 'resources', 2, 'running');

        $this->assertFalse(markStepRunning($failedId, 'seo', $this->pdo));
        $this->assertFalse(finishStep($failedId, 'resources', 'done', 'Затёрло', $this->pdo));
        $failedSteps = $this->fetchStepsByCode($failedId);
        $this->assertSame('pending', $failedSteps['seo']['status']);
        $this->assertSame('running', $failedSteps['resources']['status']);
        $this->assertNull($failedSteps['resources']['message']);

        $this->assertFalse(markStepRunning(999999, 'seo', $this->pdo));
        $this->assertFalse(finishStep(999999, 'seo', 'done', 'Нет', $this->pdo));
    }

    public function testResetAiStepsRunsWhenRunningAndRollsBackWhenNotRunning(): void
    {
        $runningId = $this->insertDiagnostic('running', 75);
        $this->insertStep($runningId, 'availability', 1, 'done', 'OK');
        $this->insertStep($runningId, 'ai_problem', 2, 'done', 'Старое сообщение');

        $this->pdo->exec(
            "INSERT INTO findings (diagnostic_id, source, category, severity, title, client_text, fingerprint)
             VALUES
                ({$runningId}, 'check', 'seo', 'low', 'Нет title', 'Старый клиентский текст', 'fp1'),
                ({$runningId}, 'ai', 'ai_problem', 'medium', 'ИИ гипотеза', 'Текст ИИ', 'fp2')"
        );

        $resetResult = resetAiSteps($runningId, [
            ['code' => 'ai_problem', 'title' => 'Разбор проблемы (ИИ)'],
            ['code' => 'ai_summary', 'title' => 'Итоговый вывод (ИИ)'],
        ], $this->pdo);

        $this->assertTrue($resetResult);
        $this->assertSame(0, (int) $this->fetchDiagnostic($runningId)['progress']);

        $steps = $this->fetchStepsByCode($runningId);
        $this->assertSame('done', $steps['availability']['status']);
        $this->assertSame('pending', $steps['ai_problem']['status']);
        $this->assertSame(2, (int) $steps['ai_problem']['position']);
        $this->assertNull($steps['ai_problem']['message']);
        $this->assertNull($steps['ai_problem']['started_at']);
        $this->assertNull($steps['ai_problem']['finished_at']);
        $this->assertSame('pending', $steps['ai_summary']['status']);
        $this->assertSame(4, (int) $steps['ai_summary']['position']);

        $findings = $this->pdo->query("SELECT source, client_text FROM findings WHERE diagnostic_id = {$runningId}")->fetchAll();
        $this->assertCount(1, $findings);
        $this->assertSame('check', $findings[0]['source']);
        $this->assertNull($findings[0]['client_text']);

        // Для failed диагностики транзакция откатывается и не удаляет данные
        $failedId = $this->insertDiagnostic('failed', 80, 'Ошибка');
        $this->insertStep($failedId, 'ai_problem', 1, 'done', 'Готово');
        $this->pdo->exec(
            "INSERT INTO findings (diagnostic_id, source, category, severity, title, client_text, fingerprint)
             VALUES ({$failedId}, 'ai', 'ai_problem', 'medium', 'ИИ гипотеза', 'Текст ИИ', 'fp3')"
        );

        $this->assertFalse(resetAiSteps($failedId, [
            ['code' => 'ai_problem', 'title' => 'Разбор проблемы (ИИ)'],
            ['code' => 'ai_summary', 'title' => 'Итоговый вывод (ИИ)'],
        ], $this->pdo));

        $this->assertSame(80, (int) $this->fetchDiagnostic($failedId)['progress']);
        $failedSteps = $this->fetchStepsByCode($failedId);
        $this->assertSame('done', $failedSteps['ai_problem']['status']);
        $this->assertSame('Готово', $failedSteps['ai_problem']['message']);
        $this->assertArrayNotHasKey('ai_summary', $failedSteps);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM findings WHERE diagnostic_id = {$failedId}")->fetchColumn());
    }

    public function testFailQueuedDiagnosticUpdatesOnlyQueued(): void
    {
        $queuedId = $this->insertDiagnostic('queued', 0);
        $runningId = $this->insertDiagnostic('running', 20);
        $doneId = $this->insertDiagnostic('done', 100);

        $this->assertTrue(failQueuedDiagnostic($queuedId, 'Не удалось запустить воркер', $this->pdo));
        $row = $this->fetchDiagnostic($queuedId);
        $this->assertSame('failed', $row['status']);
        $this->assertSame('Не удалось запустить воркер', $row['error_message']);

        $this->assertFalse(failQueuedDiagnostic($runningId, 'Не должно сработать', $this->pdo));
        $this->assertSame('running', $this->fetchDiagnostic($runningId)['status']);

        $this->assertFalse(failQueuedDiagnostic($doneId, 'Не должно сработать', $this->pdo));
        $this->assertSame('done', $this->fetchDiagnostic($doneId)['status']);
    }

    public function testFailRunningDiagnosticAndFailRunningSteps(): void
    {
        $runningId = $this->insertDiagnostic('running', 40);
        $this->insertStep($runningId, 'availability', 1, 'done', 'OK');
        $this->insertStep($runningId, 'resources', 2, 'running');
        $this->insertStep($runningId, 'seo', 3, 'pending');

        $this->assertTrue(failRunningDiagnostic(
            $runningId,
            'Воркер аварийно завершился: недостаточно памяти.',
            'Шаг прерван: воркеру не хватило памяти.',
            $this->pdo
        ));

        $diag = $this->fetchDiagnostic($runningId);
        $this->assertSame('failed', $diag['status']);
        $this->assertSame(40, (int) $diag['progress']);
        $this->assertSame('Воркер аварийно завершился: недостаточно памяти.', $diag['error_message']);

        $steps = $this->fetchStepsByCode($runningId);
        $this->assertSame('done', $steps['availability']['status']);
        $this->assertSame('OK', $steps['availability']['message']);
        $this->assertSame('failed', $steps['resources']['status']);
        $this->assertSame('Шаг прерван: воркеру не хватило памяти.', $steps['resources']['message']);
        $this->assertSame('pending', $steps['seo']['status']);

        // Повторный вызов на уже failed диагностике ничего не меняет
        $this->assertFalse(failRunningDiagnostic($runningId, 'Другое', 'Другой шаг', $this->pdo));
        $this->assertSame('Воркер аварийно завершился: недостаточно памяти.', $this->fetchDiagnostic($runningId)['error_message']);
        $this->assertSame(0, failRunningSteps($runningId, 'Не должно перезаписать', $this->pdo));
        $this->assertSame('Шаг прерван: воркеру не хватило памяти.', $this->fetchStepsByCode($runningId)['resources']['message']);

        $secondRunningId = $this->insertDiagnostic('running', 25);
        $this->insertStep($secondRunningId, 'stack', 1, 'running');
        $this->insertStep($secondRunningId, 'seo', 2, 'pending');
        $this->assertSame(1, failRunningSteps($secondRunningId, 'Остановлен', $this->pdo));
        $secondSteps = $this->fetchStepsByCode($secondRunningId);
        $this->assertSame('failed', $secondSteps['stack']['status']);
        $this->assertSame('Остановлен', $secondSteps['stack']['message']);
        $this->assertSame('pending', $secondSteps['seo']['status']);
    }
}
