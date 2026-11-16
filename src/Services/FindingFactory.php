<?php

declare(strict_types=1);

/**
 * Сборка находки от проверок кода (`source = check`) и от ИИ (`source = ai`) в виде строки для `findings`, без БД:
 * серьёзность по категории, `fingerprint` для сравнения диагностик между собой.
 */

const FINDING_SOURCE_CHECK = 'check';
const FINDING_SOURCE_AI    = 'ai';

const FINDING_SEVERITIES = ['critical', 'high', 'medium', 'low', 'info'];

/** Категория → серьёзность по умолчанию (категории — `.docs/database.md`, таблица `findings`) */
const FINDING_DEFAULT_SEVERITY = [
    'availability'    => 'high',
    'ssl'             => 'high',
    'broken_resource' => 'medium',
    'php_error'       => 'high',
    'mixed_content'   => 'high', // активный контент; пассивный (картинки) проверка помечает `low`
    'js_error'        => 'medium',
    'failed_request'  => 'medium',
    'outdated'        => 'medium',
    'vulnerability'   => 'high',
    'exposed_file'    => 'high',
    'seo'             => 'low',
    'speed'           => 'low',
    'checkout'        => 'critical',
    'layout'          => 'medium',
    'hypothesis'      => 'info',
];

const FINDING_TITLE_MAX_LENGTH    = 255;  // как findings.title VARCHAR(255)
const FINDING_LOCATION_MAX_LENGTH = 2048; // как findings.location VARCHAR(2048)

const FINGERPRINT_SEPARATOR = "\n";

/**
 * @param string                    $key      что именно найдено (код ответа, тип ошибки …) — часть fingerprint
 * @param array<string, mixed>|null $evidence сырые данные: код ответа, фрагмент вывода
 * @param string|null               $severity null — по категории
 * @return array{page_id: ?int, source: string, category: string, severity: string, title: string,
 *               details: ?string, location: ?string, evidence: ?array, fingerprint: string}
 * @throws InvalidArgumentException неизвестная категория или серьёзность
 */
function makeFinding(
    string $category,
    string $title,
    ?string $location,
    string $key = '',
    ?string $details = null,
    ?array $evidence = null,
    ?int $pageId = null,
    ?string $severity = null
): array {
    if (!array_key_exists($category, FINDING_DEFAULT_SEVERITY)) {
        throw new InvalidArgumentException("Неизвестная категория находки: {$category}");
    }

    $severity ??= FINDING_DEFAULT_SEVERITY[$category];
    if (!in_array($severity, FINDING_SEVERITIES, true)) {
        throw new InvalidArgumentException("Неизвестная серьёзность находки: {$severity}");
    }

    return [
        'page_id'     => $pageId,
        'source'      => FINDING_SOURCE_CHECK,
        'category'    => $category,
        'severity'    => $severity,
        'title'       => mb_substr($title, 0, FINDING_TITLE_MAX_LENGTH),
        'details'     => $details,
        'location'    => $location !== null ? mb_substr($location, 0, FINDING_LOCATION_MAX_LENGTH) : null,
        'evidence'    => $evidence,
        'fingerprint' => buildFingerprint($category, $location, $key),
    ];
}

/**
 * Находка от ИИ: `source = ai`, ссылки на факты в `evidence` (`{"finding_ids": [..], "stack_ids": [..]}`),
 * `ai_result_id` — ответ ИИ, который её дал. Fingerprint — по заголовку без регистра и лишних пробелов.
 *
 * @param array<string, list<int>> $evidence ссылки на факты (уже проверенные `validateAiEvidence()`)
 * @return array{page_id: ?int, source: string, category: string, severity: string, title: string,
 *               details: ?string, location: ?string, evidence: array, fingerprint: string, ai_result_id: int}
 * @throws InvalidArgumentException неизвестная категория или серьёзность
 */
function makeAiFinding(
    string $category,
    string $title,
    ?string $details,
    string $severity,
    array $evidence,
    int $aiResultId
): array {
    $key = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $title)));
    $finding = makeFinding($category, $title, null, $key, $details, $evidence, null, $severity);

    return ['source' => FINDING_SOURCE_AI, 'ai_result_id' => $aiResultId] + $finding;
}

/**
 * sha1 от `category + нормализованный location + ключ`: одна и та же поломка в разных диагностиках
 * даёт один fingerprint.
 */
function buildFingerprint(string $category, ?string $location, string $key): string
{
    return sha1(implode(FINGERPRINT_SEPARATOR, [$category, normalizeFindingLocation($location), $key]));
}

/**
 * Адрес к одному виду: без пробелов по краям и `#фрагмента`, схема и хост в нижнем регистре.
 * Не адрес (путь к файлу и т. п.) — только trim.
 */
function normalizeFindingLocation(?string $location): string
{
    $location = trim($location ?? '');

    if (preg_match('~^([a-z][a-z0-9+.\-]*://)([^/?#]*)(.*)$~is', $location, $matches) !== 1) {
        return $location;
    }

    $rest = explode('#', $matches[3], 2)[0];

    return strtolower($matches[1]) . strtolower($matches[2]) . $rest;
}
