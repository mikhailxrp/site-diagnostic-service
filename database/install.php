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

    // Фаза 0: одна диагностика сайта
    'diagnostics' => <<<SQL
        CREATE TABLE IF NOT EXISTS diagnostics (
            id            INT NOT NULL AUTO_INCREMENT,
            url           VARCHAR(2048) NOT NULL,
            host          VARCHAR(255) NOT NULL,
            product_url   VARCHAR(2048) NULL,
            problem_text  TEXT NULL,
            status        ENUM('queued','running','done','failed','cancelled') NOT NULL DEFAULT 'queued',
            progress      TINYINT UNSIGNED NOT NULL DEFAULT 0,
            error_message VARCHAR(1000) NULL,
            previous_id   INT NULL,
            summary_json  JSON NULL,
            started_at    DATETIME NULL,
            finished_at   DATETIME NULL,
            heartbeat_at  DATETIME NULL,
            created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_diagnostics_host (host),
            INDEX idx_diagnostics_status (status),
            INDEX idx_diagnostics_created_at (created_at),
            CONSTRAINT fk_diagnostics_previous FOREIGN KEY (previous_id)
                REFERENCES diagnostics (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,

    // Фаза 0: шаги фонового выполнения диагностики
    'diagnostic_steps' => <<<SQL
        CREATE TABLE IF NOT EXISTS diagnostic_steps (
            id            INT NOT NULL AUTO_INCREMENT,
            diagnostic_id INT NOT NULL,
            step_code     VARCHAR(50) NOT NULL,
            title         VARCHAR(150) NOT NULL,
            position      SMALLINT UNSIGNED NOT NULL,
            status        ENUM('pending','running','done','skipped','failed') NOT NULL DEFAULT 'pending',
            message       VARCHAR(1000) NULL,
            started_at    DATETIME NULL,
            finished_at   DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_diagnostic_steps_code (diagnostic_id, step_code),
            CONSTRAINT fk_diagnostic_steps_diagnostic FOREIGN KEY (diagnostic_id)
                REFERENCES diagnostics (id) ON DELETE CASCADE
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
