<?php

declare(strict_types=1);

/**
 * Версия CMS по отпечаткам публичных статических файлов, без сети и БД: md5 содержимого файла → версии релизов,
 * в которых файл был именно таким. Версии по нескольким файлам пересекаются; файл, изменённый на сайте (своя тема,
 * правка, страница защиты от ботов), не совпадает ни с одним релизом и не учитывается.
 *
 * Отпечатки — `data/fingerprints/<имя>.json` (`files`: путь → хэш → версии), имя — ключ `fingerprints` платформы
 * в `data/signatures.php`. Собирает их `bin/build-opencart-fingerprints.php` из файлов релизов.
 */

require_once ROOT_PATH . '/src/Services/DatabaseUpdater.php';
require_once ROOT_PATH . '/src/Services/VersionService.php';

const VERSION_FINGERPRINTS_DIR = ROOT_PATH . '/data/fingerprints';
const VERSION_FINGERPRINT_NAME_PATTERN = '~^[a-z0-9-]+$~';
const VERSION_FINGERPRINT_BOM = "\xEF\xBB\xBF";

/**
 * @return array<string, array<string, list<string>>>|null путь → хэш → версии; null — нет файла или имя не то
 */
function loadVersionFingerprints(string $name, ?string $dir = null): ?array
{
    if (preg_match(VERSION_FINGERPRINT_NAME_PATTERN, $name) !== 1) {
        return null;
    }

    $data = readDatabaseFile(($dir ?? VERSION_FINGERPRINTS_DIR) . '/' . $name . '.json');
    $files = $data['files'] ?? null;

    return is_array($files) && $files !== [] ? $files : null;
}

/**
 * Хэш содержимого без различий, которые вносит выкладка: переводы строк Windows, BOM.
 */
function hashFingerprintBody(string $body): string
{
    if (str_starts_with($body, VERSION_FINGERPRINT_BOM)) {
        $body = substr($body, strlen(VERSION_FINGERPRINT_BOM));
    }

    return md5(str_replace("\r\n", "\n", $body));
}

/**
 * Версии, с которыми совпали все узнанные файлы; файлы с неизвестным хэшем не учитываются.
 *
 * @param array<string, array<string, list<string>>> $fingerprints путь → хэш → версии
 * @param array<string, string>                      $hashes       путь → хэш файла сайта
 * @return array{versions: list<string>, matched: list<string>} matched — пути узнанных файлов; ничего не узнано —
 *         оба списка пусты; узнано, но релизы разные — versions пуст
 */
function matchVersionFingerprints(array $fingerprints, array $hashes): array
{
    $versions = null;
    $matched = [];
    foreach ($hashes as $path => $hash) {
        $known = $fingerprints[$path][$hash] ?? null;
        if (!is_array($known) || $known === []) {
            continue;
        }
        $matched[] = $path;
        $versions = $versions === null ? $known : array_values(array_intersect($versions, $known));
    }

    $versions ??= [];
    usort($versions, 'compareVersions');

    return ['versions' => array_values(array_unique($versions)), 'matched' => $matched];
}

/**
 * «3.0.3.8» или «3.0.3.3–3.0.3.7» (список упорядочен по возрастанию).
 *
 * @param list<string> $versions
 */
function formatVersionCandidates(array $versions): string
{
    if (count($versions) <= 1) {
        return $versions[0] ?? '';
    }

    return $versions[0] . '–' . $versions[count($versions) - 1];
}

/**
 * Отпечатки из файлов релизов: версия → путь → содержимое (null — файла в релизе нет).
 *
 * @param array<string, array<string, ?string>> $releases
 * @return array<string, array<string, list<string>>> путь → хэш → версии (по возрастанию)
 */
function buildVersionFingerprints(array $releases): array
{
    $files = [];
    foreach ($releases as $version => $bodies) {
        foreach ($bodies as $path => $body) {
            if ($body !== null) {
                $files[$path][hashFingerprintBody($body)][] = (string) $version;
            }
        }
    }

    foreach ($files as $path => $hashes) {
        foreach ($hashes as $hash => $versions) {
            usort($versions, 'compareVersions');
            $files[$path][$hash] = $versions;
        }
        ksort($files[$path]);
    }
    ksort($files);

    return $files;
}
