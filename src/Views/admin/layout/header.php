<?php

declare(strict_types=1);

/**
 * Общий макет (начало): <head>, шапка с меню, открывает <main>.
 * Ожидает: $title — заголовок страницы, $activeMenu — ключ активного пункта меню,
 * $showNavigation — выводить главное меню (по умолчанию true).
 */

$showNavigation = $showNavigation ?? true;

$menuItems = [
    'new'      => ['href' => '/', 'icon' => 'ti-stethoscope', 'label' => 'Новая диагностика'],
    'history'  => ['href' => '/history', 'icon' => 'ti-history', 'label' => 'История'],
    'settings' => ['href' => '/settings', 'icon' => 'ti-settings', 'label' => 'Настройки'],
];
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> — <?= e(APP_NAME) ?></title>
    <link rel="icon" href="data:,">
    <link rel="stylesheet" href="/assets/vendor/tabler/css/tabler.min.css">
    <link rel="stylesheet" href="/assets/vendor/tabler/icons/tabler-icons.min.css">
    <link rel="stylesheet" href="/assets/css/admin-theme.css">
    <script src="/assets/vendor/tabler/js/tabler.min.js" defer></script>
</head>
<body>
<div class="page">
    <header class="navbar navbar-expand-md d-print-none">
        <div class="container-xl">
            <?php if ($showNavigation) : ?>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbar-menu"
                        aria-controls="navbar-menu" aria-expanded="false" aria-label="Открыть меню">
                    <span class="navbar-toggler-icon"></span>
                </button>
            <?php endif; ?>
            <a href="/" class="navbar-brand navbar-brand-autodark pe-0 pe-md-3"><?= e(APP_NAME) ?></a>
            <?php if ($showNavigation) : ?>
                <nav class="collapse navbar-collapse" id="navbar-menu" aria-label="Главное меню">
                    <ul class="navbar-nav">
                        <?php foreach ($menuItems as $menuKey => $menuItem) : ?>
                            <?php $isActive = $menuKey === $activeMenu; ?>
                            <li class="nav-item<?= $isActive ? ' active' : '' ?>">
                                <a class="nav-link" href="<?= e($menuItem['href']) ?>"<?= $isActive ? ' aria-current="page"' : '' ?>>
                                    <span class="nav-link-icon"><i class="ti <?= e($menuItem['icon']) ?>" aria-hidden="true"></i></span>
                                    <span class="nav-link-title"><?= e($menuItem['label']) ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </header>
    <main class="page-wrapper">
        <?php render('components/flash'); ?>
