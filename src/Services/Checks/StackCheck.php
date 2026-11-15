<?php

declare(strict_types=1);

/**
 * Шаг `stack` без БД: стек по главной из фактов шага `availability`, затем — только для найденных CMS
 * без известной версии — служебные файлы для версии. Сеть передаётся параметром (`$request`).
 * Находок нет: устаревшие версии и уязвимости — Фаза 2.
 */

require_once ROOT_PATH . '/src/Services/HtmlParser.php';
require_once ROOT_PATH . '/src/Services/StackDetector.php';

const STACK_NO_HOME_MESSAGE = 'Нет данных главной страницы: шаг «Доступность сайта» не выполнен.';
const STACK_NOT_FOUND_MESSAGE = 'Стек по главной странице не определён.';
const HTTP_STATUS_OK = 200;

/**
 * @param array<string, mixed>         $context контекст шага (`buildStepContext()`)
 * @param callable(string $url): array $request результат как у `httpRequest()`
 * @param array<string, mixed>|null    $signatures правила; null — `data/signatures.php`
 * @return array{status: string, message: string, facts: array, findings: list<array>}
 */
function runStackCheck(array $context, callable $request, ?array $signatures = null): array
{
    $home = $context['facts']['availability']['home'] ?? null;
    if (!is_array($home) || ($home['status'] ?? null) === null) {
        return ['status' => 'skipped', 'message' => STACK_NO_HOME_MESSAGE, 'facts' => [], 'findings' => []];
    }

    $signatures ??= loadStackSignatures();
    $pageUrl = (string) ($home['final_url'] ?? $context['diagnostic']['url']);
    $parsed = parseHtml((string) ($home['body'] ?? ''), $pageUrl);
    $items = collectStackItems($home, $parsed, $signatures);

    $probes = [];
    foreach (listPlatformsWithoutVersion($items) as $platformKey) {
        foreach (buildVersionProbeUrls($platformKey, $pageUrl, $signatures) as $probe) {
            $response = $request($probe['url']);
            $version = ($response['status'] ?? null) === HTTP_STATUS_OK
                ? extractVersionFromProbe((string) $response['body'], $probe['version_pattern'])
                : null;

            $probes[] = [
                'url'     => $probe['url'],
                'status'  => $response['status'] ?? null,
                'version' => $version,
                'error'   => $response['error'] ?? null,
            ];

            if ($version !== null) {
                $items = applyVersionProbe($items, $platformKey, $probe['url'], $version);
                break;
            }
        }
    }

    $items = finalizeStackItems($items);

    return [
        'status'   => 'done',
        'message'  => buildStackMessage($items),
        'facts'    => [
            'items'   => $items,
            'probes'  => $probes,
            // Страница-заглушка защиты от ботов или обрезанный HTML — стек может быть неполным
            'limited' => !empty($home['possible_bot_protection']) || !empty($home['truncated']),
        ],
        'findings' => [],
    ];
}

/**
 * Ключи найденных CMS, у которых версия ещё неизвестна.
 *
 * @return list<string>
 */
function listPlatformsWithoutVersion(array $items): array
{
    $keys = [];
    foreach ($items as $item) {
        if ($item['category'] === 'cms' && $item['key'] !== null && $item['version'] === null) {
            $keys[] = $item['key'];
        }
    }

    return $keys;
}

/**
 * «WordPress 6.4.2; найдено элементов: 12» — CMS с наибольшей уверенностью идёт первой (`finalizeStackItems()`).
 */
function buildStackMessage(array $items): string
{
    if ($items === []) {
        return STACK_NOT_FOUND_MESSAGE;
    }

    $parts = [];
    foreach ($items as $item) {
        if ($item['category'] === 'cms') {
            $parts[] = trim($item['name'] . ' ' . ($item['version'] ?? ''));
            break;
        }
    }
    $parts[] = 'найдено элементов: ' . count($items);

    return implode('; ', $parts);
}
