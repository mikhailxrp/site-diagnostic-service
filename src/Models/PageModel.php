<?php

declare(strict_types=1);

/**
 * Таблица `checked_pages`: проверенные страницы сайта.
 */

require_once ROOT_PATH . '/src/Core/Database.php';

const CHECKED_PAGE_URL_MAX_LENGTH = 2048; // как checked_pages.url VARCHAR(2048)

/**
 * Очищает адрес от невалидных байтов UTF-8 и усекает до длины колонки `checked_pages.url`.
 */
function normalizeCheckedPageUrl(string $url): string
{
    return mb_substr(mb_scrub($url, 'UTF-8'), 0, CHECKED_PAGE_URL_MAX_LENGTH, 'UTF-8');
}

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
        'url'           => normalizeCheckedPageUrl($page['url']),
        'page_type'     => $page['page_type'],
        'found_by'      => $page['found_by'],
        'http_code'     => $page['http_code'] ?? null,
        'response_ms'   => $page['response_ms'] ?? null,
        'blocked'       => !empty($page['blocked']) ? 1 : 0,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Проверенные страницы диагностики в порядке записи.
 *
 * @return list<array<string, mixed>>
 */
function getCheckedPagesByDiagnostic(int $diagnosticId): array
{
    $statement = getPdo()->prepare(
        'SELECT id, url, page_type, found_by, http_code, response_ms, blocked
         FROM checked_pages
         WHERE diagnostic_id = :diagnostic_id
         ORDER BY id'
    );
    $statement->execute(['diagnostic_id' => $diagnosticId]);

    return $statement->fetchAll();
}
