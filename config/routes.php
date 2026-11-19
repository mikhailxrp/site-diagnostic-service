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
        '/diagnostics/{id}/client' => ['DiagnosticController', 'client'],
        '/diagnostics/{id}/pdf'    => ['ExportController', 'download'],
        '/diagnostics/{id}/status' => ['DiagnosticController', 'status'],
        '/screenshots/{id}'        => ['ScreenshotController', 'show'],
        '/screenshots/{id}/thumb'  => ['ScreenshotController', 'thumb'],
    ],
    'POST' => [
        '/settings'                => ['SettingsController', 'save'],
        '/settings/browser'        => ['SettingsController', 'saveBrowser'],
        '/diagnostics'             => ['DiagnosticController', 'store'],
        '/diagnostics/{id}/delete' => ['HistoryController', 'delete'],
        '/diagnostics/{id}/ai-rerun' => ['DiagnosticController', 'rerunAi'],
    ],
];
