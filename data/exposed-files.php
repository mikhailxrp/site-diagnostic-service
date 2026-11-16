<?php

declare(strict_types=1);

/**
 * Открытые служебные файлы (`Checks/ExposedFilesCheck.php`). Только данные — пополнять без кода.
 *
 * `signatures` — признак содержимого настоящего файла (имя признака попадает в `evidence`, само содержимое — нет):
 *   require  — регулярные выражения PCRE, все должны совпасть;
 *   any      — хотя бы одно должно совпасть (пустой список — не нужно);
 *   not_html — ответ не должен быть HTML-страницей (заглушка «мягкого 404» сайта).
 *   Проверяется только начало файла (`EXPOSED_FILE_MAX_BYTES`).
 *
 * `groups` — что ищем: заголовок и текст находки, серьёзность и пути (от корня сайта) → имя признака.
 */

return [
    'signatures' => [
        'git_head' => [
            'require'  => [],
            'any'      => ['/\Aref: refs\//', '/\A[0-9a-f]{40}\s*\z/'],
            'not_html' => true,
        ],
        'git_config' => [
            'require'  => ['/^\s*\[core\]/m'],
            'any'      => [],
            'not_html' => true,
        ],
        'env' => [
            'require'  => ['/^[A-Z][A-Z0-9_]*=/m'],
            'any'      => [],
            'not_html' => true,
        ],
        'php_source' => [
            'require'  => ['/\A\s*<\?php/'],
            'any'      => ['/DB_(NAME|USER|PASSWORD|HOST)/', '/password/i', '/\$db/i'],
            'not_html' => false,
        ],
        'sql_dump' => [
            'require'  => [],
            'any'      => [
                '/^-- MySQL dump/m',
                '/^-- MariaDB dump/m',
                '/^-- phpMyAdmin SQL Dump/m',
                '/^CREATE TABLE/mi',
                '/^INSERT INTO/mi',
            ],
            'not_html' => true,
        ],
        'archive' => [
            'require'  => [],
            'any'      => ['/\APK\x03\x04/', '/\A\x1f\x8b/', '/\ARar!\x1a\x07/', '/\A7z\xbc\xaf\x27\x1c/'],
            'not_html' => true,
        ],
        'phpinfo' => [
            'require'  => ['/phpinfo\(\)/i', '/PHP Version\s+[0-9]/i'],
            'any'      => [],
            'not_html' => false,
        ],
        'adminer' => [
            'require'  => ['/Adminer/'],
            'any'      => ['/name=["\']auth\[/', '/adminer\.org/i'],
            'not_html' => false,
        ],
        'php_log' => [
            'require'  => [],
            'any'      => [
                '/PHP (Fatal error|Parse error|Warning|Notice|Deprecated|Stack trace):/',
                '/^\[[^\]]+\] \[(\w+:)?(error|warn|notice)\]/m',
            ],
            'not_html' => true,
        ],
    ],

    'groups' => [
        'git' => [
            'title'    => 'Открыта папка .git',
            'details'  => 'Через открытую папку .git скачивается весь исходный код сайта вместе с паролями из '
                . 'конфигов. Закрыть доступ к .git в настройках сервера или убрать папку с сервера.',
            'severity' => 'critical',
            'files'    => ['.git/HEAD' => 'git_head', '.git/config' => 'git_config'],
        ],
        'env' => [
            'title'    => 'Открыт файл с настройками окружения',
            'details'  => 'В .env обычно лежат пароли к базе данных и ключи API. Закрыть доступ к файлу в настройках '
                . 'сервера и сменить пароли и ключи из него.',
            'severity' => 'critical',
            'files'    => ['.env' => 'env', '.env.bak' => 'env'],
        ],
        'config_backup' => [
            'title'    => 'Открыта копия конфига сайта',
            'details'  => 'Копия конфига отдаётся как текст, а не выполняется: в ней пароль к базе данных. Удалить '
                . 'копию с сервера и сменить пароль к базе.',
            'severity' => 'critical',
            'files'    => [
                'wp-config.php.bak'     => 'php_source',
                'wp-config.php~'        => 'php_source',
                'wp-config.php.old'     => 'php_source',
                'configuration.php.bak' => 'php_source',
                'config.php.bak'        => 'php_source',
            ],
        ],
        'sql_dump' => [
            'title'    => 'Открыт дамп базы данных',
            'details'  => 'В дампе базы — заказы, данные клиентов и пароли администраторов. Удалить файл с сервера.',
            'severity' => 'critical',
            'files'    => [
                'backup.sql'   => 'sql_dump',
                'dump.sql'     => 'sql_dump',
                'db.sql'       => 'sql_dump',
                'database.sql' => 'sql_dump',
            ],
        ],
        'archive' => [
            'title'    => 'Открыт архив с копией сайта',
            'details'  => 'Архив сайта или базы скачивает любой посетитель: в нём исходный код и пароли. Удалить '
                . 'архив с сервера.',
            'severity' => 'critical',
            'files'    => ['backup.zip' => 'archive', 'backup.tar.gz' => 'archive', 'site.zip' => 'archive'],
        ],
        'phpinfo' => [
            'title'    => 'Открыта страница phpinfo',
            'details'  => 'phpinfo показывает версии, пути на сервере и настройки PHP — готовая подсказка для взлома. '
                . 'Удалить файл.',
            'severity' => 'high',
            'files'    => ['phpinfo.php' => 'phpinfo', 'info.php' => 'phpinfo'],
        ],
        'adminer' => [
            'title'    => 'Открыт вход в базу данных (Adminer)',
            'details'  => 'Adminer даёт войти в базу данных из браузера; пароль к нему подбирают перебором. Удалить '
                . 'файл или закрыть доступ по IP.',
            'severity' => 'high',
            'files'    => ['adminer.php' => 'adminer'],
        ],
        'log' => [
            'title'    => 'Открыт лог ошибок',
            'details'  => 'Лог ошибок открыт всем: в нём пути на сервере и подробности ошибок. Закрыть доступ к файлу '
                . 'или перенести лог за пределы сайта.',
            'severity' => 'medium',
            'files'    => ['error_log' => 'php_log', 'wp-content/debug.log' => 'php_log'],
        ],
    ],
];
