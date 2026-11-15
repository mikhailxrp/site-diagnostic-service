<?php

declare(strict_types=1);

/**
 * Экран диагностики: общий прогресс, статусы шагов, итог. Обновляется опросом (diagnostic-progress.js).
 * Ожидает: $title, $activeMenu, $diagnostic (результат buildDiagnosticStatus() или null — не найдена / ошибка),
 * $statusUrl (если $diagnostic есть), $emptyText (если $diagnostic = null).
 */

render('admin/layout/header', ['title' => $title, 'activeMenu' => $activeMenu]);
?>
<?php if ($diagnostic === null) : ?>
    <div class="page-body">
        <div class="container-xl">
            <section class="empty" aria-labelledby="diagnostic-empty-title">
                <div class="empty-icon"><i class="ti ti-zoom-cancel icon" aria-hidden="true"></i></div>
                <h1 class="empty-title" id="diagnostic-empty-title"><?= e($title) ?></h1>
                <p class="empty-subtitle text-secondary"><?= e($emptyText) ?></p>
                <div class="empty-action">
                    <a href="/" class="btn btn-primary btn-lg">На главную</a>
                </div>
            </section>
        </div>
    </div>
<?php else : ?>
    <div class="page-header d-print-none">
        <div class="container-xl">
            <div class="row g-2 align-items-center">
                <div class="col">
                    <div class="page-pretitle"><?= e($title) ?></div>
                    <h1 class="page-title text-break"><?= e($diagnostic['url']) ?></h1>
                </div>
                <div class="col-auto">
                    <span class="badge <?= e($diagnostic['badgeClass']) ?>" data-diagnostic-status><?= e($diagnostic['statusLabel']) ?></span>
                </div>
            </div>
        </div>
    </div>
    <div class="page-body">
        <div class="container-xl">
            <div class="row row-cards" data-diagnostic
                 data-status-url="<?= e($statusUrl) ?>"
                 data-finished="<?= $diagnostic['isFinished'] ? '1' : '0' ?>">
                <div class="col-12">
                    <section class="card" aria-labelledby="diagnostic-progress-title">
                        <div class="card-header">
                            <h2 class="card-title" id="diagnostic-progress-title">Ход диагностики</h2>
                            <div class="card-actions">
                                <span class="fw-medium" id="diagnostic-progress-text" data-progress-text aria-live="polite"><?= e($diagnostic['progress']) ?>%</span>
                            </div>
                        </div>
                        <div class="card-body">
                            <progress class="progress" max="100" value="<?= e($diagnostic['progress']) ?>"
                                      aria-labelledby="diagnostic-progress-title diagnostic-progress-text"
                                      data-progress><?= e($diagnostic['progress']) ?>%</progress>
                            <div class="alert alert-warning mt-3 mb-0" role="status" data-poll-warning hidden>
                                <div class="alert-description" data-poll-warning-text></div>
                            </div>
                        </div>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($diagnostic['steps'] as $step) : ?>
                                <li class="list-group-item" data-step="<?= e($step['code']) ?>">
                                    <div class="row align-items-center g-2">
                                        <div class="col">
                                            <div class="fw-medium"><?= e($step['title']) ?></div>
                                            <div class="text-secondary small text-break" data-step-message<?= $step['message'] === null ? ' hidden' : '' ?>><?= e($step['message']) ?></div>
                                        </div>
                                        <div class="col-auto">
                                            <span class="badge <?= e($step['badgeClass']) ?>" data-step-badge><?= e($step['statusLabel']) ?></span>
                                        </div>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </section>
                </div>
                <div class="col-12" data-result-failed<?= $diagnostic['status'] === 'failed' ? '' : ' hidden' ?>>
                    <div class="alert alert-danger mb-0" role="alert">
                        <div class="alert-icon"><i class="ti ti-alert-triangle icon" aria-hidden="true"></i></div>
                        <div>
                            <h2 class="alert-heading">Диагностика завершилась с ошибкой</h2>
                            <div class="alert-description text-break" data-error-message><?= e($diagnostic['errorMessage']) ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-12" data-result-done<?= $diagnostic['status'] === 'done' ? '' : ' hidden' ?>>
                    <section class="card" aria-labelledby="diagnostic-report-title">
                        <div class="card-header">
                            <h2 class="card-title" id="diagnostic-report-title">Отчёт</h2>
                        </div>
                        <div class="card-body">
                            <p class="text-secondary mb-0">Отчёт появится в Фазе 1.</p>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </div>
    <script type="module" src="/assets/js/diagnostic-progress.js"></script>
<?php endif; ?>
<?php render('admin/layout/footer'); ?>
