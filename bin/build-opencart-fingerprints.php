<?php

declare(strict_types=1);

/**
 * Сборка отпечатков версий OpenCart (`data/fingerprints/opencart.json`) из релизов на GitHub: теги
 * `opencart/opencart` (без alpha / beta / rc) и публичные статические файлы каждого релиза.
 *
 * Запуск вручную, когда выходит новый релиз OpenCart (из корня проекта):
 *   "C:/OSPanel/modules/PHP-8.3/PHP/php.exe" bin/build-opencart-fingerprints.php
 *
 * Любой сбой (кроме «файла нет в релизе») — выход без записи: прошлые отпечатки не портятся.
 */

const EXIT_OK    = 0;
const EXIT_ERROR = 1;

if (PHP_SAPI !== 'cli') {
    echo "Скрипт запускается только из консоли: php bin/build-opencart-fingerprints.php\n";
    exit(EXIT_ERROR);
}

require_once dirname(__DIR__) . '/config/config.php';
require_once ROOT_PATH . '/src/Services/HttpClient.php';
require_once ROOT_PATH . '/src/Services/VersionFingerprints.php';

const OPENCART_REPO           = 'opencart/opencart';
const OPENCART_TAGS_URL       = 'https://api.github.com/repos/' . OPENCART_REPO . '/tags?per_page=100&page=%d';
const OPENCART_FILE_URL       = 'https://raw.githubusercontent.com/' . OPENCART_REPO . '/%s/upload/%s';
const OPENCART_FINGERPRINTS   = VERSION_FINGERPRINTS_DIR . '/opencart.json';
const OPENCART_TAGS_MAX_PAGES = 10;
const HTTP_STATUS_NOT_FOUND   = 404;
const HTTP_STATUS_SUCCESS     = 200;

/** Публичные файлы ядра, которые меняются от релиза к релизу и редко правятся на сайтах */
const OPENCART_FINGERPRINT_FILES = [
    'admin/view/javascript/common.js',
    'admin/view/stylesheet/stylesheet.css',
    'catalog/view/javascript/common.js',
    'catalog/view/theme/default/stylesheet/stylesheet.css', // 1.5 – 3.x
    'catalog/view/stylesheet/stylesheet.css',               // 4.x
];

set_time_limit(0);

/**
 * @return list<string> стабильные релизы
 */
function fetchOpencartTags(): array
{
    $tags = [];
    for ($page = 1; $page <= OPENCART_TAGS_MAX_PAGES; $page++) {
        $response = httpApiRequest(sprintf(OPENCART_TAGS_URL, $page));
        if ($response['error'] !== null || $response['status'] !== HTTP_STATUS_SUCCESS) {
            throw new RuntimeException('GitHub: теги не получены — ' . ($response['error'] ?? 'ответ ' . $response['status']));
        }
        $list = json_decode($response['body'], true);
        if (!is_array($list)) {
            throw new RuntimeException('GitHub: теги — не JSON');
        }
        if ($list === []) {
            break;
        }
        foreach ($list as $tag) {
            $name = is_string($tag['name'] ?? null) ? $tag['name'] : '';
            if (isStableVersion($name)) {
                $tags[] = $name;
            }
        }
    }

    return $tags;
}

/**
 * @return array<string, ?string> путь → содержимое; null — файла в релизе нет
 */
function fetchOpencartReleaseFiles(string $tag): array
{
    $bodies = [];
    foreach (OPENCART_FINGERPRINT_FILES as $path) {
        $response = httpApiRequest(sprintf(OPENCART_FILE_URL, rawurlencode($tag), $path));
        $bodies[$path] = match (true) {
            $response['error'] === null && $response['status'] === HTTP_STATUS_SUCCESS   => $response['body'],
            $response['error'] === null && $response['status'] === HTTP_STATUS_NOT_FOUND => null,
            default => throw new RuntimeException("{$tag} {$path}: " . ($response['error'] ?? 'ответ ' . $response['status'])),
        };
    }

    return $bodies;
}

try {
    $tags = fetchOpencartTags();
    if ($tags === []) {
        throw new RuntimeException('GitHub: нет ни одного релиза');
    }

    $releases = [];
    foreach ($tags as $tag) {
        $releases[$tag] = fetchOpencartReleaseFiles($tag);
        fwrite(STDOUT, $tag . ' ');
    }

    saveDatabaseFile(OPENCART_FINGERPRINTS, [
        'source'       => 'https://github.com/' . OPENCART_REPO . ' (теги, папка upload/)',
        'generated_at' => date('c'),
        'versions'     => count($releases),
        'files'        => buildVersionFingerprints($releases),
    ]);
} catch (Throwable $e) {
    logException($e, ['script' => 'bin/build-opencart-fingerprints.php']);
    fwrite(STDERR, "\nОшибка: " . $e->getMessage() . " — отпечатки не изменены.\n");
    exit(EXIT_ERROR);
}

fwrite(STDOUT, "\nГотово: " . count($releases) . ' релизов → ' . OPENCART_FINGERPRINTS . "\n");
exit(EXIT_OK);
