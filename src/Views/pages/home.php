<?php

declare(strict_types=1);

/**
 * Новая диагностика. Пока заглушка — форма появится в Фазе 1.
 * Ожидает: $title, $activeMenu.
 */

render('admin/layout/header', ['title' => $title, 'activeMenu' => $activeMenu]);
?>
<div class="page-header d-print-none">
    <div class="container-xl">
        <h1 class="page-title"><?= e($title) ?></h1>
    </div>
</div>
<div class="page-body">
    <div class="container-xl">
        <section class="card">
            <div class="card-body">
                <p class="text-secondary mb-0">Форма запуска диагностики появится в Фазе 1.</p>
            </div>
        </section>
    </div>
</div>
<?php render('admin/layout/footer'); ?>
