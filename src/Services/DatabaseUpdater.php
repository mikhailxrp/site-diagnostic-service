<?php

declare(strict_types=1);

/**
 * Локальные базы (справочник версий, уязвимости Wordfence и retire.js): реестр источников, обновление с атомарной заменой
 * файла, блокировка повторного запуска, чтение рабочей базы или начальной из `data/`.
 * Новая база = запись в `getDatabaseSources()`, `bin/update-databases.php` не меняется.
 */

require_once ROOT_PATH . '/src/Services/VersionSources.php';
require_once ROOT_PATH . '/src/Services/VulnerabilityDatabases.php';

const DATABASES_DIR      = ROOT_PATH . '/storage/databases';
const DATABASES_SEED_DIR = ROOT_PATH . '/data';
const DATABASES_LOCK     = 'update.lock';
const DATABASE_JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

/**
 * Реестр баз: ключ → название, файл, функция обновления `fn(?array $previous, callable $request): array{data,
 * updated, failed}` (упавший источник оставляет прошлые данные в `data`).
 *
 * @return array<string, array{title: string, file: string, update: callable}>
 */
function getDatabaseSources(): array
{
    return [
        'latest_versions' => [
            'title'  => 'База версий',
            'file'   => 'latest-versions.json',
            'update' => static fn (?array $previous, callable $request): array
                => updateLatestVersionsDatabase($previous, $request),
        ],
        'wordfence' => [
            'title'  => 'База уязвимостей WordPress',
            'file'   => 'wordfence-vulnerabilities.json',
            'update' => static fn (?array $previous, callable $request): array => updateWordfenceDatabase(
                env(WORDFENCE_TOKEN_ENV, ''),
                static fn (string $url, string $path, array $headers): array
                    => httpDownloadToFile($url, $path, $headers, WORDFENCE_TIMEOUT_SECONDS, WORDFENCE_MAX_BYTES),
                DATABASES_DIR . '/wordfence-vulnerabilities.json' . WORDFENCE_DOWNLOAD_SUFFIX
            ),
        ],
        'retire' => [
            'title'  => 'База уязвимостей JS',
            'file'   => 'retire-vulnerabilities.json',
            'update' => static fn (?array $previous, callable $request): array => updateRetireDatabase($request),
        ],
    ];
}

/**
 * Обновляет все базы реестра по очереди. Сбой одной базы (исключение, пустой результат) не трогает её файл и не
 * мешает остальным.
 *
 * @param array<string, array{title: string, file: string, update: callable}>|null $sources null — весь реестр
 * @return array<string, array{ok: bool, updated: list<string>, failed: array<string, string>, error: ?string}>
 */
function updateDatabases(callable $request, ?string $dir = null, ?array $sources = null): array
{
    $dir ??= DATABASES_DIR;
    $results = [];

    foreach ($sources ?? getDatabaseSources() as $key => $source) {
        $path = $dir . '/' . $source['file'];
        try {
            $result = ($source['update'])(readDatabaseFile($path), $request);
            if (!is_array($result['data'] ?? null) || ($result['data']['items'] ?? []) === []) {
                throw new RuntimeException('источники не дали ни одной записи');
            }
            saveDatabaseFile($path, $result['data']);
            $results[$key] = [
                'ok'      => true,
                'updated' => $result['updated'] ?? [],
                'failed'  => $result['failed'] ?? [],
                'error'   => null,
            ];
        } catch (Throwable $e) {
            $results[$key] = ['ok' => false, 'updated' => [], 'failed' => [], 'error' => $e->getMessage()];
        }
    }

    return $results;
}

/**
 * JSON во временный файл рядом, затем замена: прерванная запись не оставляет полуфайл.
 *
 * @param array<string, mixed> $data
 */
function saveDatabaseFile(string $path, array $data): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("Не удалось создать папку баз: {$dir}");
    }

    $temp = $path . '.tmp';
    if (file_put_contents($temp, json_encode($data, DATABASE_JSON_FLAGS), LOCK_EX) === false) {
        throw new RuntimeException("Не удалось записать {$temp}");
    }
    if (!rename($temp, $path)) {
        unlink($temp);
        throw new RuntimeException("Не удалось заменить {$path}");
    }
}

/**
 * @return array<string, mixed>|null null — файла нет или он не JSON-объект
 */
function readDatabaseFile(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }

    $data = json_decode((string) file_get_contents($path), true);

    return is_array($data) ? $data : null;
}

/**
 * Рабочая база из `storage/databases/`, иначе начальная из `data/` (в репозитории); null — нет ни той, ни другой.
 *
 * @return array<string, mixed>|null
 */
function loadDatabase(string $key, ?string $dir = null, ?string $seedDir = null): ?array
{
    $source = getDatabaseSources()[$key] ?? null;
    if ($source === null) {
        return null;
    }

    return readDatabaseFile(($dir ?? DATABASES_DIR) . '/' . $source['file'])
        ?? readDatabaseFile(($seedDir ?? DATABASES_SEED_DIR) . '/' . $source['file']);
}

/**
 * Дата базы — самая старая из дат её записей (одна устаревшая запись делает базу устаревшей); у записей дат нет —
 * общая `updated_at` файла; null — дат нет.
 *
 * @param array<string, mixed>|null $database
 */
function getDatabaseDate(?array $database): ?string
{
    $dates = [];
    foreach (is_array($database['items'] ?? null) ? $database['items'] : [] as $item) {
        if (is_array($item) && is_string($item['updated_at'] ?? null) && strtotime($item['updated_at']) !== false) {
            $dates[] = $item['updated_at'];
        }
    }
    if ($dates === []) {
        // У баз уязвимостей записи без дат — дата общая на файл
        $updated = $database['updated_at'] ?? null;

        return is_string($updated) && strtotime($updated) !== false ? $updated : null;
    }

    usort($dates, static fn (string $a, string $b): int => strtotime($a) <=> strtotime($b));

    return $dates[0];
}

/**
 * Блокировка обновления: второй запуск, пока идёт первый, получает null. Снимается при завершении процесса.
 *
 * @return resource|null
 */
function acquireDatabaseLock(?string $dir = null)
{
    $dir ??= DATABASES_DIR;
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("Не удалось создать папку баз: {$dir}");
    }

    $handle = fopen($dir . '/' . DATABASES_LOCK, 'c');
    if ($handle === false) {
        throw new RuntimeException('Не удалось открыть файл блокировки обновления баз.');
    }
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);

        return null;
    }

    return $handle;
}

/**
 * @param resource $handle
 */
function releaseDatabaseLock($handle): void
{
    flock($handle, LOCK_UN);
    fclose($handle);
}
