<?php

declare(strict_types=1);

/**
 * Откуда брать актуальную версию для элемента стека (шаг `outdated`, `bin/update-databases.php`).
 * Только данные — новая библиотека или CMS добавляется одной строкой.
 *
 * Ключ — `key` элемента стека из data/signatures.php (`php` — элемент категории `php`).
 *   source    — `npm` (package), `packagist` (package), `github` (repo, релизы без alpha / beta / rc),
 *               `wordpress` (ядро), `php` (ветки с php.net), `wp-plugin` (slug; онлайн при диагностике, не в базе);
 *   eol_below — необязательно: версия ниже этой — ветка без поддержки (у PHP ветки берутся с php.net);
 *   retire    — необязательно: имя библиотеки в базе уязвимостей retire.js (шаг `vulnerabilities`).
 * Плагины WordPress без строки здесь сверяются по имени (slug) автоматически.
 */

return [
    'wordpress'      => ['source' => 'wordpress'],
    'joomla'         => ['source' => 'github', 'repo' => 'joomla/joomla-cms', 'eol_below' => '5.0.0'],
    'magento'        => ['source' => 'github', 'repo' => 'magento/magento2', 'eol_below' => '2.4.0'],
    'prestashop'     => ['source' => 'packagist', 'package' => 'prestashop/prestashop', 'eol_below' => '1.7.0'],
    'opencart'       => ['source' => 'packagist', 'package' => 'opencart/opencart'],
    'php'            => ['source' => 'php'],
    'woocommerce'    => ['source' => 'wp-plugin', 'slug' => 'woocommerce'],
    'jquery'         => ['source' => 'npm', 'package' => 'jquery', 'eol_below' => '3.0.0', 'retire' => 'jquery'],
    'jquery-migrate' => ['source' => 'npm', 'package' => 'jquery-migrate', 'retire' => 'jquery-migrate'],
    'jquery-ui'      => ['source' => 'npm', 'package' => 'jquery-ui', 'retire' => 'jquery-ui'],
    'bootstrap'      => ['source' => 'npm', 'package' => 'bootstrap', 'eol_below' => '5.0.0', 'retire' => 'bootstrap'],
    'vue'            => ['source' => 'npm', 'package' => 'vue', 'eol_below' => '3.0.0', 'retire' => 'vue'],
    'react'          => ['source' => 'npm', 'package' => 'react', 'retire' => 'react'],
    'swiper'         => ['source' => 'npm', 'package' => 'swiper', 'retire' => 'swiper'],
    'slick'          => ['source' => 'npm', 'package' => 'slick-carousel'],
    'fancybox'       => ['source' => 'npm', 'package' => '@fancyapps/ui'],
    'lodash'         => ['source' => 'npm', 'package' => 'lodash', 'eol_below' => '4.0.0', 'retire' => 'lodash'],
];
