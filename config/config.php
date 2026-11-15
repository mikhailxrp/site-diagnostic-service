<?php

declare(strict_types=1);

/**
 * Константы и настройки приложения.
 * Секреты — только через .env, не здесь.
 * Ключи API константами не делаются: сервисы читают их через env(), когда нужно.
 */

define('ROOT_PATH', dirname(__DIR__));

require_once ROOT_PATH . '/src/Core/functions.php';

loadEnv(ROOT_PATH . '/.env');

define('APP_NAME', env('APP_NAME', 'Site Diagnostics'));
define('APP_ENV',  env('APP_ENV', 'production'));
define('APP_URL',  env('APP_URL', ''));

// Хранилище файлов (вне public/)
define('STORAGE_PATH',     ROOT_PATH . '/storage');
define('SCREENSHOTS_PATH', STORAGE_PATH . '/screenshots');
define('PDF_PATH',         STORAGE_PATH . '/pdf');

// PHP для фонового воркера
define('PHP_CLI_PATH', env('PHP_CLI_PATH', 'php'));

// RouterAI (адрес не секрет; ключ — ROUTERAI_API_KEY в .env)
define('ROUTERAI_BASE_URL', env('ROUTERAI_BASE_URL', 'https://routerai.ru/api/v1'));

// Логгер
define('LOG_DIR',       env('LOG_DIR',       STORAGE_PATH . '/logs'));
define('LOG_FILE',      env('LOG_FILE',      'app.log'));
define('APP_LOG_LEVEL', env('APP_LOG_LEVEL', 'error'));

require_once ROOT_PATH . '/src/Core/Logger.php';
require_once ROOT_PATH . '/src/Core/Database.php';
