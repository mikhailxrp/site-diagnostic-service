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

    // Фаза 1: проверенные страницы сайта
    'checked_pages' => <<<SQL
        CREATE TABLE IF NOT EXISTS checked_pages (
            id            INT NOT NULL AUTO_INCREMENT,
            diagnostic_id INT NOT NULL,
            url           VARCHAR(2048) NOT NULL,
            page_type     ENUM('home','category','product','cart','checkout','other') NOT NULL,
            found_by      ENUM('start','cms_pattern','link_heuristic','manual') NOT NULL,
            http_code     SMALLINT UNSIGNED NULL,
            response_ms   INT UNSIGNED NULL,
            blocked       TINYINT(1) NOT NULL DEFAULT 0,
            created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            CONSTRAINT fk_checked_pages_diagnostic FOREIGN KEY (diagnostic_id)
                REFERENCES diagnostics (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,

    // Фаза 1: находки от проверок и (с фазы 4) от ИИ.
    // FK ai_result_id → ai_results добавляется ниже, в $foreignKeys (фаза 4): ai_results создаётся после findings
    'findings' => <<<SQL
        CREATE TABLE IF NOT EXISTS findings (
            id            INT NOT NULL AUTO_INCREMENT,
            diagnostic_id INT NOT NULL,
            page_id       INT NULL,
            source        ENUM('check','ai') NOT NULL,
            category      VARCHAR(50) NOT NULL,
            severity      ENUM('critical','high','medium','low','info') NOT NULL,
            title         VARCHAR(255) NOT NULL,
            details       TEXT NULL,
            client_text   TEXT NULL,
            location      VARCHAR(2048) NULL,
            evidence      JSON NULL,
            fingerprint   CHAR(40) NOT NULL,
            ai_result_id  INT NULL,
            created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_findings_diagnostic_severity (diagnostic_id, severity),
            INDEX idx_findings_fingerprint (fingerprint),
            CONSTRAINT fk_findings_diagnostic FOREIGN KEY (diagnostic_id)
                REFERENCES diagnostics (id) ON DELETE CASCADE,
            CONSTRAINT fk_findings_page FOREIGN KEY (page_id)
                REFERENCES checked_pages (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,

    // Фаза 1: найденный стек (latest_version заполняется в фазе 2)
    'stack_items' => <<<SQL
        CREATE TABLE IF NOT EXISTS stack_items (
            id             INT NOT NULL AUTO_INCREMENT,
            diagnostic_id  INT NOT NULL,
            category       ENUM('cms','server','php','cdn','js_library','theme','plugin','analytics','other') NOT NULL,
            name           VARCHAR(150) NOT NULL,
            version        VARCHAR(50) NULL,
            latest_version VARCHAR(50) NULL,
            confidence     ENUM('high','medium','low') NOT NULL,
            evidence       JSON NOT NULL,
            created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_stack_items_diagnostic (diagnostic_id),
            CONSTRAINT fk_stack_items_diagnostic FOREIGN KEY (diagnostic_id)
                REFERENCES diagnostics (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,

    // Фаза 3: скриншоты страниц; файлы — в storage/screenshots/, file_path — относительно этой папки
    'screenshots' => <<<SQL
        CREATE TABLE IF NOT EXISTS screenshots (
            id             INT NOT NULL AUTO_INCREMENT,
            diagnostic_id  INT NOT NULL,
            page_id        INT NOT NULL,
            viewport_width SMALLINT UNSIGNED NOT NULL,
            file_path      VARCHAR(255) NOT NULL,
            created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            CONSTRAINT fk_screenshots_diagnostic FOREIGN KEY (diagnostic_id)
                REFERENCES diagnostics (id) ON DELETE CASCADE,
            CONSTRAINT fk_screenshots_page FOREIGN KEY (page_id)
                REFERENCES checked_pages (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,

    // Фаза 4: ответы ИИ по задачам; ключ API в request_json не пишется, картинки — только id скриншотов
    'ai_results' => <<<SQL
        CREATE TABLE IF NOT EXISTS ai_results (
            id            INT NOT NULL AUTO_INCREMENT,
            diagnostic_id INT NOT NULL,
            task          ENUM('problem','visual','summary','client_text') NOT NULL,
            model         VARCHAR(150) NOT NULL,
            status        ENUM('ok','error') NOT NULL,
            request_json  JSON NULL,
            response_text MEDIUMTEXT NULL,
            parsed_json   JSON NULL,
            error_message VARCHAR(1000) NULL,
            tokens_in     INT UNSIGNED NULL,
            tokens_out    INT UNSIGNED NULL,
            created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            CONSTRAINT fk_ai_results_diagnostic FOREIGN KEY (diagnostic_id)
                REFERENCES diagnostics (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
];

// ─── Внешние ключи к таблицам из более поздних фаз (ALTER TABLE, только если ключа ещё нет) ───

$foreignKeys = [
    // Фаза 4: какой ответ ИИ породил находку
    'fk_findings_ai_result' => [
        'table' => 'findings',
        'sql'   => 'ALTER TABLE findings ADD CONSTRAINT fk_findings_ai_result FOREIGN KEY (ai_result_id)
                    REFERENCES ai_results (id) ON DELETE SET NULL',
    ],
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

    $foreignKeyExists = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    foreach ($foreignKeys as $constraintName => $foreignKey) {
        $foreignKeyExists->execute([$foreignKey['table'], $constraintName]);
        if ((int) $foreignKeyExists->fetchColumn() > 0) {
            echo "  = {$constraintName}: уже есть\n";
            continue;
        }

        $pdo->exec($foreignKey['sql']);
        echo "  + {$constraintName}: добавлен\n";
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

echo "Готово: таблиц — " . count($tables) . ", внешних ключей — " . count($foreignKeys) . ".\n";
exit(EXIT_OK);
