<?php

declare(strict_types=1);

/**
 * Таблица `screenshots`: скриншоты страниц на разных ширинах (файлы — в `storage/screenshots/`).
 */

require_once ROOT_PATH . '/src/Core/Database.php';

function insertScreenshot(int $diagnosticId, int $pageId, int $width, string $filePath): int
{
    $pdo = getPdo();
    $statement = $pdo->prepare(
        'INSERT INTO screenshots (diagnostic_id, page_id, viewport_width, file_path)
         VALUES (:diagnostic_id, :page_id, :viewport_width, :file_path)'
    );
    $statement->execute([
        'diagnostic_id'  => $diagnosticId,
        'page_id'        => $pageId,
        'viewport_width' => $width,
        'file_path'      => $filePath,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * @return array{id: int, file_path: string}|null
 */
function getScreenshot(int $id): ?array
{
    $statement = getPdo()->prepare('SELECT id, file_path FROM screenshots WHERE id = :id');
    $statement->execute(['id' => $id]);
    $row = $statement->fetch();

    return $row === false ? null : $row;
}

/**
 * Скриншоты диагностики: по странице, затем по ширине.
 *
 * @return list<array<string, mixed>>
 */
function getScreenshotsByDiagnostic(int $diagnosticId): array
{
    $statement = getPdo()->prepare(
        'SELECT id, page_id, viewport_width, file_path
         FROM screenshots
         WHERE diagnostic_id = :diagnostic_id
         ORDER BY page_id, viewport_width'
    );
    $statement->execute(['diagnostic_id' => $diagnosticId]);

    return $statement->fetchAll();
}
