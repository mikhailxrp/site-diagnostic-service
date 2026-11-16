<?php

declare(strict_types=1);

/**
 * Все маршруты приложения.
 * Формат: 'МЕТОД' => ['/путь' => ['ИмяКонтроллера', 'метод']]
 */

return [
    'GET' => [
        '/'                        => ['HomeController', 'index'],
        '/settings'                => ['SettingsController', 'index'],
        '/history'                 => ['HistoryController', 'index'],
        '/diagnostics/{id}'        => ['DiagnosticController', 'show'],
        '/diagnostics/{id}/status' => ['DiagnosticController', 'status'],
    ],
    'POST' => [
        '/settings'                => ['SettingsController', 'save'],
        '/settings/browser'        => ['SettingsController', 'saveBrowser'],
        '/diagnostics'             => ['DiagnosticController', 'store'],
        '/diagnostics/{id}/delete' => ['HistoryController', 'delete'],
    ],
];
