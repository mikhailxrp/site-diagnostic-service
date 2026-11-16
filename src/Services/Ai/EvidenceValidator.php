<?php

declare(strict_types=1);

/**
 * Проверка ссылок ИИ-ответа на факты (главный принцип PRD): ИИ толкует собранные факты, а не придумывает находки.
 * Ссылка допустима, только если id есть в пакете фактов этой диагностики; элемент ответа без единой допустимой
 * ссылки отбрасывается. Без БД.
 */

/**
 * @param list<mixed>              $items   элементы ответа модели (гипотезы, поломки вёрстки, шаги плана)
 * @param array<string, list<int>> $allowed ключ ссылки (`finding_ids`, `stack_ids` …) → допустимые id
 * @return array{items: list<array>, rejected: int} элементы с одними допустимыми ссылками; сколько отброшено
 */
function validateAiEvidence(array $items, array $allowed): array
{
    $valid = [];
    $rejected = 0;
    foreach ($items as $item) {
        if (!is_array($item)) {
            $rejected++;
            continue;
        }

        $references = 0;
        foreach ($allowed as $key => $allowedIds) {
            $item[$key] = filterAiEvidenceIds($item[$key] ?? null, $allowedIds);
            $references += count($item[$key]);
        }

        if ($references === 0) {
            $rejected++;
            continue;
        }
        $valid[] = $item;
    }

    return ['items' => $valid, 'rejected' => $rejected];
}

/**
 * Только допустимые id, без повторов, в порядке ответа. Число строкой («12») допускается: модели так иногда пишут.
 *
 * @param list<int> $allowedIds
 * @return list<int>
 */
function filterAiEvidenceIds(mixed $ids, array $allowedIds): array
{
    if (!is_array($ids)) {
        return [];
    }

    $allowedSet = array_flip($allowedIds);
    $result = [];
    foreach ($ids as $id) {
        if (is_string($id) && ctype_digit($id)) {
            $id = (int) $id;
        }
        if (is_int($id) && isset($allowedSet[$id]) && !in_array($id, $result, true)) {
            $result[] = $id;
        }
    }

    return $result;
}
