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
                <p class="text-secondary">Форма запуска диагностики появится в Фазе 1.</p>
                <form method="post" action="/diagnostics/start">
                    <?= csrfField() ?>
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="ti ti-player-play icon" aria-hidden="true"></i>
                        Тестовый запуск (заглушка)
                    </button>
                </form>
            </div>
        </section>
    </div>
</div>
<?php render('admin/layout/footer'); ?>
