<?php

declare(strict_types=1);

/**
 * Таблица `stack_items`: найденный стек диагностики.
 */

require_once ROOT_PATH . '/src/Core/Database.php';

/**
 * @param array{category: string, name: string, version: ?string, confidence: string,
 *              evidence: list<array{source: string, detail: string}>} $item элемент из `detectStack()`
 */
function insertStackItem(int $diagnosticId, array $item): int
{
    $pdo = getPdo();
    $statement = $pdo->prepare(
        'INSERT INTO stack_items (diagnostic_id, category, name, version, confidence, evidence)
         VALUES (:diagnostic_id, :category, :name, :version, :confidence, :evidence)'
    );
    $statement->execute([
        'diagnostic_id' => $diagnosticId,
        'category'      => $item['category'],
        'name'          => $item['name'],
        'version'       => $item['version'],
        'confidence'    => $item['confidence'],
        // Детали из HTML в windows-1251 могут быть не UTF-8 — битые байты заменяются, запись не падает
        'evidence'      => json_encode(
            $item['evidence'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        ),
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Элементы стека диагностики в порядке записи. `evidence` — массив (пустой, если JSON не читается).
 *
 * @return list<array<string, mixed>>
 */
function getStackItemsByDiagnostic(int $diagnosticId): array
{
    $statement = getPdo()->prepare(
        'SELECT id, category, name, version, latest_version, confidence, evidence
         FROM stack_items
         WHERE diagnostic_id = :diagnostic_id
         ORDER BY id'
    );
    $statement->execute(['diagnostic_id' => $diagnosticId]);

    return array_map(
        static function (array $row): array {
            $evidence = is_string($row['evidence']) ? json_decode($row['evidence'], true) : null;
            $row['evidence'] = is_array($evidence) ? $evidence : [];

            return $row;
        },
        $statement->fetchAll()
    );
}
