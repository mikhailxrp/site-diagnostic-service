<?php

declare(strict_types=1);

/**
 * Mixed content: ресурсы по http на https-странице, без сети и БД — по списку ресурсов шага `resources`.
 * Активный контент (скрипты, стили, фреймы, шрифты) браузер блокирует → `high`;
 * пассивный (картинки, иконки) загружается с предупреждением → `low`.
 */

require_once ROOT_PATH . '/src/Services/FindingFactory.php';
require_once ROOT_PATH . '/src/Services/Checks/ResourceCheck.php';

const MIXED_CONTENT_PASSIVE_TYPES = [RESOURCE_TYPE_IMAGE, RESOURCE_TYPE_ICON];
const MIXED_CONTENT_ACTIVE_SEVERITY  = 'high';
const MIXED_CONTENT_PASSIVE_SEVERITY = 'low';
const MIXED_CONTENT_KEY = 'http';

/**
 * @param list<array{url: string, type: string, found_in?: string}> $resources
 * @return list<array> находки `mixed_content`; страница не https → пусто
 */
function findMixedContent(string $pageUrl, array $resources, ?int $pageId = null): array
{
    if (strtolower((string) parse_url($pageUrl, PHP_URL_SCHEME)) !== 'https') {
        return [];
    }

    $findings = [];
    foreach ($resources as $resource) {
        if (strtolower((string) parse_url($resource['url'], PHP_URL_SCHEME)) !== 'http') {
            continue;
        }
        $findings[] = makeMixedContentFinding($resource, $pageUrl, $pageId);
    }

    return $findings;
}

/**
 * @param array{url: string, type: string, found_in?: string} $resource
 */
function makeMixedContentFinding(array $resource, string $pageUrl, ?int $pageId): array
{
    $passive = in_array($resource['type'], MIXED_CONTENT_PASSIVE_TYPES, true);
    $label = RESOURCE_TYPE_LABELS[$resource['type']] ?? RESOURCE_TYPE_LABELS[RESOURCE_TYPE_OTHER];
    $foundIn = $resource['found_in'] ?? $pageUrl;

    $details = $passive
        ? "На https-странице {$label} подключается по http: браузер показывает предупреждение «Не защищено»."
        : "На https-странице {$label} подключается по http: браузер его заблокирует, часть сайта не будет работать.";

    return makeFinding(
        'mixed_content',
        "Mixed content: {$label} по http",
        $resource['url'],
        MIXED_CONTENT_KEY,
        $details . " Где подключено: {$foundIn}.",
        ['type' => $resource['type'], 'passive' => $passive, 'found_in' => $foundIn],
        $pageId,
        $passive ? MIXED_CONTENT_PASSIVE_SEVERITY : MIXED_CONTENT_ACTIVE_SEVERITY
    );
}
