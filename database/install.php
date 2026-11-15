<?php

declare(strict_types=1);

/**
 * Установка БД: создаёт таблицы приложения.
 *
 * Запуск только из консоли, из корня проекта:
 *   "C:/OSPanel/modules/PHP-8.3/PHP/php.exe" database/install.php
 *
 * Скрипт идемпотентен: CREATE TABLE IF NOT EXISTS, повторный запуск данные не трогает.
 * Таблицы добавляются в той фазе, где впервые нужны (см. `.docs/database.md`, «Сводка»).
 * Таблицы `users` нет: входа нет (решение владельца, 15.11.2026).
 */

const EXIT_OK    = 0;
const EXIT_ERROR = 1;

if (PHP_SAPI !== 'cli') {
    echo "Скрипт запускается только из консоли: php database/install.php\n";
    exit(EXIT_ERROR);
}

require_once dirname(__DIR__) . '/config/config.php';

// ─── Таблицы (порядок важен: сначала те, на которые ссылаются внешние ключи) ───

$tables = [
    // Фаза 0: настройки «ключ → значение»; ключи API здесь не хранятся — они в .env
    'settings' => <<<SQL
        CREATE TABLE IF NOT EXISTS settings (
            setting_key   VARCHAR(64) NOT NULL,
            setting_value TEXT NULL,
            updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
];

// ─── Установка ─────────────────────────────────────────────────────────────────

try {
    $pdo = getPdo();
    $tableExists = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );

    foreach ($tables as $tableName => $createSql) {
        $tableExists->execute([$tableName]);
        $alreadyExists = (int) $tableExists->fetchColumn() > 0;

        $pdo->exec($createSql);

        echo $alreadyExists ? "  = {$tableName}: уже есть\n" : "  + {$tableName}: создана\n";
    }
} catch (Throwable $e) {
    logException($e, ['script' => 'database/install.php']);
    fwrite(
        STDERR,
        "Ошибка установки БД. Проверьте, что MySQL запущен и переменные DB_* в .env верны.\n"
        . "Подробности: storage/logs/app.log\n"
    );
    exit(EXIT_ERROR);
}

echo "Готово: таблиц — " . count($tables) . ".\n";
exit(EXIT_OK);
