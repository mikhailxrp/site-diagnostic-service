<?php

declare(strict_types=1);

/**
 * Экран диагностики: общий прогресс, статусы шагов, отчёт. Обновляется опросом (diagnostic-progress.js),
 * по завершении страница перезагружается и отчёт выводит сервер.
 * Ожидает: $title, $activeMenu, $diagnostic (результат buildDiagnosticStatus() или null — не найдена / ошибка),
 * $statusUrl, $hasReport, $report (результат buildReport() или null), $reportError (если $diagnostic есть),
 * $emptyText (если $diagnostic = null).
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
                <div class="col-12" data-result-report<?= $hasReport ? '' : ' hidden' ?>>
                    <?php if ($reportError) : ?>
                        <div class="alert alert-danger mb-0" role="alert">
                            <div class="alert-description">Не удалось загрузить отчёт. Подробности в журнале.</div>
                        </div>
                    <?php elseif ($report === null) : ?>
                        <p class="text-secondary mb-0">Готовлю отчёт…</p>
                    <?php else : ?>
                        <?php if ($report['partial']) : ?>
                            <div class="alert alert-warning" role="status">
                                <div class="alert-description">
                                    Отчёт неполный: диагностика прервана, показано то, что успело собраться.
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="row row-cards">
                            <div class="col-12 col-lg-6">
                                <section class="card" aria-labelledby="report-summary-title">
                                    <div class="card-header">
                                        <h2 class="card-title" id="report-summary-title">Сводка</h2>
                                    </div>
                                    <?php if ($report['productUrl'] !== null) : ?>
                                        <div class="card-body border-bottom">
                                            <div class="datagrid-title">Адрес товара (указан вручную)</div>
                                            <div class="text-break"><?= e($report['productUrl']) ?></div>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($report['summary'] === null) : ?>
                                        <div class="card-body">
                                            <p class="text-secondary mb-0">Нет данных: доступность сайта не проверена.</p>
                                        </div>
                                    <?php else : ?>
                                        <div class="card-body">
                                            <?php foreach ($report['summary']['warnings'] as $warning) : ?>
                                                <div class="alert alert-warning" role="status">
                                                    <div class="alert-description"><?= e($warning) ?></div>
                                                </div>
                                            <?php endforeach; ?>
                                            <dl class="datagrid mb-0">
                                                <?php foreach ($report['summary']['rows'] as $row) : ?>
                                                    <div class="datagrid-item">
                                                        <dt class="datagrid-title"><?= e($row['label']) ?></dt>
                                                        <dd class="datagrid-content text-break mb-0">
                                                            <?php if ($row['badgeClass'] !== null) : ?>
                                                                <span class="badge <?= e($row['badgeClass']) ?> text-wrap text-start"><?= e($row['value']) ?></span>
                                                            <?php else : ?>
                                                                <?= e($row['value']) ?>
                                                            <?php endif; ?>
                                                        </dd>
                                                    </div>
                                                <?php endforeach; ?>
                                            </dl>
                                        </div>
                                        <?php if ($report['summary']['redirects'] !== []) : ?>
                                            <ul class="list-group list-group-flush">
                                                <li class="list-group-header d-block" role="heading" aria-level="3">Цепочка редиректов главной</li>
                                                <?php foreach ($report['summary']['redirects'] as $redirect) : ?>
                                                    <li class="list-group-item small text-break">
                                                        <span class="badge bg-yellow-lt"><?= e($redirect['status']) ?></span>
                                                        <?= e($redirect['url']) ?> → <?= e($redirect['location']) ?>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                        <?php if ($report['summary']['variants'] !== []) : ?>
                                            <ul class="list-group list-group-flush">
                                                <li class="list-group-header d-block" role="heading" aria-level="3">Варианты адреса</li>
                                                <?php foreach ($report['summary']['variants'] as $variant) : ?>
                                                    <li class="list-group-item small">
                                                        <div class="d-flex flex-wrap align-items-center gap-2">
                                                            <span class="text-break"><?= e($variant['url']) ?></span>
                                                            <span class="badge <?= e($variant['badgeClass']) ?> text-wrap text-start ms-auto"><?= e($variant['value']) ?></span>
                                                        </div>
                                                        <?php if ($variant['finalUrl'] !== null) : ?>
                                                            <div class="text-secondary text-break">→ <?= e($variant['finalUrl']) ?></div>
                                                        <?php endif; ?>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </section>
                            </div>
                            <div class="col-12 col-lg-6">
                                <section class="card" aria-labelledby="report-stack-title">
                                    <div class="card-header">
                                        <h2 class="card-title" id="report-stack-title">Стек</h2>
                                    </div>
                                    <?php if ($report['stackGroups'] === []) : ?>
                                        <div class="card-body">
                                            <p class="text-secondary mb-0">Стек определить не удалось.</p>
                                        </div>
                                    <?php else : ?>
                                        <?php foreach ($report['stackGroups'] as $stackGroup) : ?>
                                            <ul class="list-group list-group-flush">
                                                <li class="list-group-header d-block" role="heading" aria-level="3"><?= e($stackGroup['label']) ?></li>
                                                <?php foreach ($stackGroup['items'] as $stackItem) : ?>
                                                    <?php render('components/stack-item', ['stackItem' => $stackItem]); ?>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    <?php if ($report['databases'] !== []) : ?>
                                        <div class="card-footer">
                                            <?php foreach ($report['databases'] as $database) : ?>
                                                <div class="small text-secondary"><?= e($database['title']) ?> — от <?= e($database['date']) ?></div>
                                            <?php endforeach; ?>
                                            <?php if ($report['databasesStale']) : ?>
                                                <div class="alert alert-warning mt-2 mb-0" role="alert"><?= e($report['databasesWarning']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </section>
                            </div>
                            <div class="col-12">
                                <section class="card" aria-labelledby="report-pages-title">
                                    <div class="card-header">
                                        <h2 class="card-title" id="report-pages-title">Проверенные страницы</h2>
                                    </div>
                                    <?php if ($report['pages'] === []) : ?>
                                        <div class="card-body">
                                            <p class="text-secondary mb-0">Нет данных: страницы сайта не проверены.</p>
                                        </div>
                                    <?php else : ?>
                                        <ul class="list-group list-group-flush">
                                            <?php foreach ($report['pages'] as $page) : ?>
                                                <li class="list-group-item">
                                                    <div class="row align-items-center g-2">
                                                        <div class="col">
                                                            <div class="d-flex flex-wrap align-items-center gap-2">
                                                                <span class="fw-medium"><?= e($page['label']) ?></span>
                                                                <?php if ($page['blocked'] !== null) : ?>
                                                                    <span class="badge <?= e($report['blockedBadge']['badgeClass']) ?>"><?= e($report['blockedBadge']['label']) ?></span>
                                                                <?php endif; ?>
                                                            </div>
                                                            <?php if ($page['found']) : ?>
                                                                <div class="small text-break"><?= e($page['url']) ?></div>
                                                                <div class="text-secondary small">
                                                                    <?= e($page['foundBy']) ?>
                                                                    <?php if ($page['responseMs'] !== null) : ?>
                                                                        · <?= e($page['responseMs']) ?> мс
                                                                    <?php endif; ?>
                                                                </div>
                                                                <?php if ($page['blocked'] !== null) : ?>
                                                                    <div class="text-secondary small text-break"><?= e($page['blocked']) ?></div>
                                                                <?php else : ?>
                                                                    <?php render('components/screenshot-gallery', ['gallery' => $page]); ?>
                                                                <?php endif; ?>
                                                            <?php else : ?>
                                                                <div class="text-secondary small text-break"><?= e($page['reason']) ?></div>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="col-auto">
                                                            <span class="badge <?= e($page['badgeClass']) ?>"><?= e($page['status']) ?></span>
                                                        </div>
                                                    </div>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </section>
                            </div>
                            <div class="col-12">
                                <section class="card" aria-labelledby="report-findings-title">
                                    <div class="card-header">
                                        <h2 class="card-title" id="report-findings-title">Находки</h2>
                                        <div class="card-actions">
                                            <span class="badge bg-secondary-lt"><?= e($report['findingCount']) ?></span>
                                        </div>
                                    </div>
                                    <?php if ($report['findingGroups'] === []) : ?>
                                        <div class="card-body">
                                            <div class="empty">
                                                <div class="empty-icon"><i class="ti ti-circle-check icon text-green" aria-hidden="true"></i></div>
                                                <p class="empty-title">Проблем не найдено</p>
                                                <?php if ($report['partial']) : ?>
                                                    <p class="empty-subtitle text-secondary">Отчёт неполный: проверено не всё.</p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php else : ?>
                                        <div class="card-header">
                                            <ul class="nav nav-tabs card-header-tabs" data-bs-toggle="tabs" role="tablist">
                                                <li class="nav-item" role="presentation">
                                                    <a href="#findings-by-severity" class="nav-link py-3 active" data-bs-toggle="tab"
                                                       role="tab" aria-controls="findings-by-severity" aria-selected="true">По серьёзности</a>
                                                </li>
                                                <li class="nav-item" role="presentation">
                                                    <a href="#findings-by-page" class="nav-link py-3" data-bs-toggle="tab"
                                                       role="tab" aria-controls="findings-by-page" aria-selected="false" tabindex="-1">По страницам</a>
                                                </li>
                                            </ul>
                                        </div>
                                        <div class="tab-content">
                                            <div class="tab-pane active show" id="findings-by-severity" role="tabpanel">
                                                <?php foreach ($report['findingGroups'] as $findingGroup) : ?>
                                                    <ul class="list-group list-group-flush">
                                                        <li class="list-group-header d-flex align-items-center gap-2" role="heading" aria-level="3">
                                                            <?= e($findingGroup['label']) ?>
                                                            <span class="badge <?= e($findingGroup['badgeClass']) ?>"><?= e($findingGroup['count']) ?></span>
                                                        </li>
                                                        <?php foreach ($findingGroup['items'] as $finding) : ?>
                                                            <?php render('components/finding', ['finding' => $finding]); ?>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                <?php endforeach; ?>
                                            </div>
                                            <div class="tab-pane" id="findings-by-page" role="tabpanel">
                                                <?php foreach ($report['findingPageGroups'] as $pageGroup) : ?>
                                                    <ul class="list-group list-group-flush">
                                                        <li class="list-group-header d-flex flex-wrap align-items-center gap-2" role="heading" aria-level="3">
                                                            <?= e($pageGroup['title']) ?>
                                                            <span class="badge bg-secondary-lt"><?= e($pageGroup['count']) ?></span>
                                                            <?php if ($pageGroup['blocked'] !== null) : ?>
                                                                <span class="badge <?= e($report['blockedBadge']['badgeClass']) ?>"><?= e($report['blockedBadge']['label']) ?></span>
                                                            <?php endif; ?>
                                                        </li>
                                                        <?php if ($pageGroup['url'] !== null || $pageGroup['blocked'] !== null || $pageGroup['items'] === []) : ?>
                                                            <li class="list-group-item small text-secondary text-break">
                                                                <?php if ($pageGroup['url'] !== null) : ?>
                                                                    <div><?= e($pageGroup['url']) ?></div>
                                                                <?php endif; ?>
                                                                <?php if ($pageGroup['blocked'] !== null) : ?>
                                                                    <div><?= e($pageGroup['blocked']) ?></div>
                                                                <?php elseif ($pageGroup['items'] === []) : ?>
                                                                    <div>Находок нет</div>
                                                                <?php endif; ?>
                                                            </li>
                                                        <?php endif; ?>
                                                        <?php foreach ($pageGroup['items'] as $finding) : ?>
                                                            <?php render('components/finding', ['finding' => $finding]); ?>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </section>
                            </div>
                            <?php if ($report['uncheckedSteps'] !== []) : ?>
                                <div class="col-12">
                                    <section class="card" aria-labelledby="report-unchecked-title">
                                        <div class="card-header">
                                            <h2 class="card-title" id="report-unchecked-title">Не удалось проверить</h2>
                                        </div>
                                        <ul class="list-group list-group-flush">
                                            <?php foreach ($report['uncheckedSteps'] as $step) : ?>
                                                <li class="list-group-item">
                                                    <div class="row align-items-center g-2">
                                                        <div class="col">
                                                            <div class="fw-medium"><?= e($step['title']) ?></div>
                                                            <?php if ($step['message'] !== null) : ?>
                                                                <div class="text-secondary small text-break"><?= e($step['message']) ?></div>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="col-auto">
                                                            <span class="badge <?= e($step['badgeClass']) ?>"><?= e($step['statusLabel']) ?></span>
                                                        </div>
                                                    </div>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </section>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <script type="module" src="/assets/js/diagnostic-progress.js"></script>
<?php endif; ?>
<?php render('admin/layout/footer'); ?>
