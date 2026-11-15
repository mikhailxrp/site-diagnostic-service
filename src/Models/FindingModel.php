<?php

declare(strict_types=1);

/**
 * Таблица `findings`: находки (поломки и проблемы) диагностики.
 */

require_once ROOT_PATH . '/src/Core/Database.php';

/**
 * @param array{page_id: ?int, source: string, category: string, severity: string, title: string,
 *              details: ?string, location: ?string, evidence: ?array, fingerprint: string} $finding
 *        строка из `makeFinding()`
 */
function insertFinding(int $diagnosticId, array $finding): int
{
    $pdo = getPdo();
    $statement = $pdo->prepare(
        'INSERT INTO findings
            (diagnostic_id, page_id, source, category, severity, title, details, location, evidence, fingerprint)
         VALUES
            (:diagnostic_id, :page_id, :source, :category, :severity, :title, :details, :location, :evidence, :fingerprint)'
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
        'evidence'      => $finding['evidence'] !== null
            ? json_encode($finding['evidence'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            : null,
        'fingerprint'   => $finding['fingerprint'],
    ]);

    return (int) $pdo->lastInsertId();
}
