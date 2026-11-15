<?php

declare(strict_types=1);

/**
 * Обновление локальных баз (справочник версий; в таске 7 — уязвимости) из внешних источников.
 *
 * Запуск из консоли или Планировщиком заданий (bin/schedule-update-databases.ps1), из корня проекта:
 *   "C:/OSPanel/modules/PHP-8.3/PHP/php.exe" bin/update-databases.php
 *
 * Базы — в storage/databases/. Упавший источник оставляет прошлые данные. Повторный запуск, пока идёт прошлый,
 * сразу выходит. Итог — в консоль и в storage/logs/update-databases.log, сбои — ещё и в app.log (warning).
 */

const EXIT_OK     = 0;
const EXIT_ERROR  = 1;
const EXIT_LOCKED = 3;

if (PHP_SAPI !== 'cli') {
    echo "Скрипт запускается только из консоли: php bin/update-databases.php\n";
    exit(EXIT_ERROR);
}

require_once dirname(__DIR__) . '/config/config.php';
require_once ROOT_PATH . '/src/Services/HttpClient.php';
require_once ROOT_PATH . '/src/Services/DatabaseUpdater.php';

const UPDATE_DATABASES_LOG = LOG_DIR . '/update-databases.log';

set_time_limit(0);

try {
    $lock = acquireDatabaseLock();
    if ($lock === null) {
        fwrite(STDERR, "Обновление баз уже идёт — пропуск.\n");
        file_put_contents(UPDATE_DATABASES_LOG, '[' . date('c') . "]\nпропуск: обновление уже идёт\n", FILE_APPEND | LOCK_EX);
        exit(EXIT_LOCKED);
    }

    $results = updateDatabases(static fn (string $url): array => httpRequest($url));
    releaseDatabaseLock($lock);
} catch (Throwable $e) {
    logException($e, ['script' => 'bin/update-databases.php']);
    fwrite(STDERR, 'Ошибка обновления баз: ' . $e->getMessage() . "\n");
    exit(EXIT_ERROR);
}

$failedAny = false;
$lines = [];
foreach ($results as $key => $result) {
    $context = ['script' => 'bin/update-databases.php', 'database' => $key] + $result;
    if (!$result['ok']) {
        $failedAny = true;
        $lines[] = "{$key}: НЕ обновлена — {$result['error']} (прошлая база не тронута)";
        logWarning('База не обновлена', $context);
        continue;
    }

    $lines[] = "{$key}: обновлено " . count($result['updated']) . ', ошибок ' . count($result['failed']);
    foreach ($result['failed'] as $source => $error) {
        $lines[] = "  {$source}: {$error} (остались прошлые данные)";
    }
    if ($result['failed'] !== []) {
        logWarning('База обновлена частично', $context);
    }
}

// Итог каждого запуска — отдельным файлом: app.log по уровню из .env (warning) успешные запуски не пишет
$summary = implode("\n", $lines) . "\n";
echo $summary;
file_put_contents(
    UPDATE_DATABASES_LOG,
    '[' . date('c') . "]\n" . $summary,
    FILE_APPEND | LOCK_EX
);

exit($failedAny ? EXIT_ERROR : EXIT_OK);
