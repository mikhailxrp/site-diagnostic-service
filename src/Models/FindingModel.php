<?php

declare(strict_types=1);

/**
 * Таблица `findings`: находки (поломки и проблемы) диагностики.
 */

require_once ROOT_PATH . '/src/Core/Database.php';

/**
 * @param array{page_id: ?int, source: string, category: string, severity: string, title: string,
 *              details: ?string, location: ?string, evidence: ?array, fingerprint: string, ai_result_id?: ?int} $finding
 *        строка из `makeFinding()` или `makeAiFinding()` (у неё есть `ai_result_id`)
 */
function insertFinding(int $diagnosticId, array $finding): int
{
    $pdo = getPdo();
    $statement = $pdo->prepare(
        'INSERT INTO findings
            (diagnostic_id, page_id, source, category, severity, title, details, location, evidence, fingerprint,
             ai_result_id)
         VALUES
            (:diagnostic_id, :page_id, :source, :category, :severity, :title, :details, :location, :evidence, :fingerprint,
             :ai_result_id)'
    );
    $statement->execute([
        'diagnostic_id' => $diagnosticId,
        'page_id'       => $finding['page_id'],
        'source'        => $finding['source'],
        'category'      => $finding['category'],
        'severity'      => $finding['severity'],
        'title'         => $finding['title'],
        'details'       => $finding['details'],
        'location'      => $finding['location'],
        'evidence'      => encodeFindingEvidence($finding['evidence']),
        'fingerprint'   => $finding['fingerprint'],
        'ai_result_id'  => $finding['ai_result_id'] ?? null,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Кодирует `evidence` в JSON: невалидные байты UTF-8 (например, с сайтов в windows-1251) заменяются.
 *
 * @param array<string, mixed>|null $evidence
 */
function encodeFindingEvidence(?array $evidence): ?string
{
    return $evidence !== null
        ? json_encode(
            $evidence,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        )
        : null;
}

/**
 * Находки диагностики: от критичных к информации, внутри — по порядку записи. `evidence` — массив или null.
 *
 * @return list<array<string, mixed>>
 */
function getFindingsByDiagnostic(int $diagnosticId): array
{
    $statement = getPdo()->prepare(
        "SELECT id, page_id, source, category, severity, title, details, client_text, location, evidence, fingerprint, ai_result_id
         FROM findings
         WHERE diagnostic_id = :diagnostic_id
         ORDER BY FIELD(severity, 'critical', 'high', 'medium', 'low', 'info'), id"
    );
    $statement->execute(['diagnostic_id' => $diagnosticId]);

    return array_map(
        static function (array $row): array {
            $evidence = is_string($row['evidence']) ? json_decode($row['evidence'], true) : null;
            $row['evidence'] = is_array($evidence) ? $evidence : null;

            return $row;
        },
        $statement->fetchAll()
    );
}

/**
 * Полностью заменяет клиентские тексты одной диагностики. Пропущенные id остаются с `NULL`, id другой
 * диагностики не обновляются. Транзакция не допускает частично применённый ответ ИИ.
 *
 * @param list<array{finding_id: int, text: string}> $texts
 */
function replaceFindingClientTexts(int $diagnosticId, array $texts): void
{
    $pdo = getPdo();
    $clear = $pdo->prepare('UPDATE findings SET client_text = NULL WHERE diagnostic_id = :diagnostic_id');
    $update = $pdo->prepare(
        'UPDATE findings
         SET client_text = :client_text
         WHERE id = :finding_id AND diagnostic_id = :diagnostic_id'
    );

    $pdo->beginTransaction();
    try {
        $clear->execute(['diagnostic_id' => $diagnosticId]);
        foreach ($texts as $item) {
            $update->execute([
                'client_text'  => $item['text'],
                'finding_id'   => $item['finding_id'],
                'diagnostic_id' => $diagnosticId,
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
