<?php

declare(strict_types=1);

/**
 * Таблица `checked_pages`: проверенные страницы сайта.
 */

require_once ROOT_PATH . '/src/Core/Database.php';

/**
 * @param array{url: string, page_type: string, found_by: string, http_code?: ?int, response_ms?: ?int,
 *              blocked?: bool} $page
 */
function insertCheckedPage(int $diagnosticId, array $page): int
{
    $pdo = getPdo();
    $statement = $pdo->prepare(
        'INSERT INTO checked_pages (diagnostic_id, url, page_type, found_by, http_code, response_ms, blocked)
         VALUES (:diagnostic_id, :url, :page_type, :found_by, :http_code, :response_ms, :blocked)'
    );
    $statement->execute([
        'diagnostic_id' => $diagnosticId,
        'url'           => $page['url'],
        'page_type'     => $page['page_type'],
        'found_by'      => $page['found_by'],
        'http_code'     => $page['http_code'] ?? null,
        'response_ms'   => $page['response_ms'] ?? null,
        'blocked'       => !empty($page['blocked']) ? 1 : 0,
    ]);

    return (int) $pdo->lastInsertId();
}
