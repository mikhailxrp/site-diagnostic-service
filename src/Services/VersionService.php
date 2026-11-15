<?php

declare(strict_types=1);

/**
 * Номера версий без сети и БД: нормализация (`v1.2`, `1.12.4-aem`, `2.4.7-p3`), стабильность, сравнение, ветки.
 */

/** Номер версии: 2–4 числовые части, необязательный патч `-pN` (Magento); `20231105` или хэш — не версия */
const VERSION_PATTERN = '~^v?(\d{1,4}(?:\.\d{1,6}){1,3})(?:[-.]?(p\d{1,3}))?(?![\d.])~i';
const VERSION_UNSTABLE_PATTERN = '~(alpha|beta|\brc|-rc|rc\d|dev|preview|snapshot|nightly)~i';

/**
 * Номер версии без лишнего: `v3.6.0` → `3.6.0`, `1.12.4-aem` → `1.12.4`, `2.4.7-p3` → `2.4.7-p3`; не версия → null.
 */
function normalizeVersion(?string $version): ?string
{
    $version = trim((string) $version);
    if ($version === '' || preg_match(VERSION_PATTERN, $version, $match) !== 1) {
        return null;
    }

    return $match[1] . (isset($match[2]) && $match[2] !== '' ? '-' . strtolower($match[2]) : '');
}

/**
 * Стабильный релиз: не alpha / beta / rc / dev; патчи `-pN` стабильные.
 */
function isStableVersion(string $version): bool
{
    return preg_match(VERSION_UNSTABLE_PATTERN, $version) !== 1 && normalizeVersion($version) !== null;
}

/**
 * <0, 0, >0 как у version_compare; обе версии нормализуются.
 */
function compareVersions(string $a, string $b): int
{
    return version_compare(normalizeVersion($a) ?? $a, normalizeVersion($b) ?? $b);
}

/**
 * Самая новая стабильная версия из списка (порядок любой); null — стабильных нет.
 *
 * @param list<string> $versions
 */
function pickLatestStableVersion(array $versions): ?string
{
    $latest = null;
    foreach ($versions as $version) {
        if (!isStableVersion((string) $version)) {
            continue;
        }
        $normalized = (string) normalizeVersion((string) $version);
        if ($latest === null || compareVersions($normalized, $latest) > 0) {
            $latest = $normalized;
        }
    }

    return $latest;
}

function getMajorVersion(string $version): int
{
    return (int) explode('.', normalizeVersion($version) ?? $version)[0];
}

/**
 * Ветка «мажор.минор»: `8.2.35` → `8.2`.
 */
function getMinorBranch(string $version): string
{
    $parts = explode('.', (string) preg_replace('~-p\d+$~', '', normalizeVersion($version) ?? $version));

    return $parts[0] . '.' . ($parts[1] ?? '0');
}
