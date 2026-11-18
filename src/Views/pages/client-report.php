<?php

declare(strict_types=1);

/**
 * Клиентская версия отчёта.
 * Ожидает: $title, $report (результат buildClientReport() или null),
 * $emptyTitle / $emptyText при $report = null, $technicalUrl — ссылка на технический отчёт или null.
 */

render('admin/layout/header', [
    'title'          => $title,
    'activeMenu'     => null,
    'showNavigation' => false,
]);
?>
<?php if ($report === null) : ?>
    <div class="page-body">
        <div class="container-xl">
            <section class="empty" aria-labelledby="client-report-empty-title">
                <div class="empty-icon"><i class="ti ti-file-alert icon" aria-hidden="true"></i></div>
                <h1 class="empty-title" id="client-report-empty-title"><?= e($emptyTitle) ?></h1>
                <p class="empty-subtitle text-secondary"><?= e($emptyText) ?></p>
                <div class="empty-action d-print-none">
                    <a href="<?= e($technicalUrl ?? '/') ?>" class="btn btn-primary btn-lg">
                        <?= $technicalUrl !== null ? 'К техническому отчёту' : 'На главную' ?>
                    </a>
                </div>
            </section>
        </div>
    </div>
<?php else : ?>
    <div class="page-header">
        <div class="container-xl">
            <div class="row g-2 align-items-center">
                <div class="col-12 col-sm">
                    <div class="page-pretitle">Отчёт по состоянию сайта</div>
                    <h1 class="page-title text-break"><?= e($report['site']) ?></h1>
                    <div class="text-secondary mt-1">Дата проверки: <?= e($report['date']) ?></div>
                </div>
                <?php if ($technicalUrl !== null) : ?>
                    <div class="col-12 col-sm-auto d-print-none">
                        <a href="<?= e($technicalUrl) ?>" class="btn btn-outline-secondary btn-lg w-100">
                            <i class="ti ti-tool icon" aria-hidden="true"></i>
                            Технический отчёт
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="page-body">
        <div class="container-xl">
            <?php if ($report['partial']) : ?>
                <div class="alert alert-warning" role="status">
                    <div class="alert-icon"><i class="ti ti-alert-triangle icon" aria-hidden="true"></i></div>
                    <div>
                        <h2 class="alert-heading">Отчёт неполный</h2>
                        <div class="alert-description">Проверка завершилась раньше времени. Ниже показано только то, что удалось проверить.</div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($report['summary'] !== null) : ?>
                <section class="card mb-3" aria-labelledby="client-report-summary-title">
                    <div class="card-body">
                        <h2 class="card-title" id="client-report-summary-title">Главное по результатам проверки</h2>
                        <p class="text-break mb-0"><?= nl2br(e($report['summary']), false) ?></p>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($report['groups'] === []) : ?>
                <section class="card" aria-labelledby="client-report-result-title">
                    <div class="card-body">
                        <div class="empty">
                            <div class="empty-icon"><i class="ti ti-circle-check icon text-green" aria-hidden="true"></i></div>
                            <h2 class="empty-title" id="client-report-result-title">Проблем не найдено</h2>
                            <p class="empty-subtitle text-secondary">Во время проверки заметных проблем не обнаружено.</p>
                        </div>
                    </div>
                </section>
            <?php else : ?>
                <div class="row row-cards" aria-label="Обнаруженные проблемы">
                    <?php foreach ($report['groups'] as $index => $group) : ?>
                        <div class="col-12">
                            <section class="card" aria-labelledby="client-report-group-<?= e($index) ?>">
                                <div class="card-header">
                                    <div class="row g-2 align-items-center w-100">
                                        <div class="col-12 col-sm">
                                            <h2 class="card-title text-break" id="client-report-group-<?= e($index) ?>"><?= e($group['countLabel']) ?></h2>
                                        </div>
                                        <div class="col-12 col-sm-auto">
                                            <span class="badge <?= e($group['badgeClass']) ?>"><?= e($group['priorityLabel']) ?></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <h3 class="h4">Что произошло</h3>
                                    <?php foreach ($group['descriptions'] as $description) : ?>
                                        <p class="text-break"><?= nl2br(e($description), false) ?></p>
                                    <?php endforeach; ?>
                                    <div class="row g-3 mt-1">
                                        <div class="col-12 col-md-6">
                                            <h3 class="h4">Чем это грозит</h3>
                                            <p class="text-secondary mb-0 text-break"><?= e($group['impact']) ?></p>
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <h3 class="h4">Что делать</h3>
                                            <p class="text-secondary mb-0 text-break"><?= e($group['action']) ?></p>
                                        </div>
                                    </div>
                                </div>
                            </section>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
<?php render('admin/layout/footer'); ?>
