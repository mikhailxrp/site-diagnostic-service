<?php

declare(strict_types=1);

/**
 * Все маршруты приложения.
 * Формат: 'МЕТОД' => ['/путь' => ['ИмяКонтроллера', 'метод']]
 */

return [
    'GET' => [
        '/'         => ['HomeController', 'index'],
        '/settings' => ['SettingsController', 'index'],
    ],
    'POST' => [
        '/settings' => ['SettingsController', 'save'],
    ],
];
