<?php

declare(strict_types=1);

namespace App\Controllers;

/**
 * Главная = «Новая диагностика». Пока заглушка — форма появится в Фазе 1.
 */
final class HomeController
{
    public function index(): void
    {
        render('pages/home', [
            'title'      => 'Новая диагностика',
            'activeMenu' => 'new',
        ]);
    }
}
