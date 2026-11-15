<?php

declare(strict_types=1);

/**
 * История диагностик: поиск, таблица, пагинация, удаление через модальное окно Tabler (history.js).
 * Ожидает: $title, $activeMenu, $query (введённый запрос), $rows (buildHistoryRows()),
 * $pagination (buildHistoryPagination()), $paginationLinks (buildHistoryPaginationLinks()), $loadError, $resetUrl.
 */

render('admin/layout/header', ['title' => $title, 'activeMenu' => $activeMenu]);
?>
<div class="page-header d-print-none">
    <div class="container-xl">
        <div class="row g-2 align-items-center">
            <div class="col">
                <h1 class="page-title"><?= e($title) ?></h1>
            </div>
            <div class="col-auto">
                <a href="/" class="btn btn-primary">
                    <i class="ti ti-plus icon" aria-hidden="true"></i>
                    Новая диагностика
                </a>
            </div>
        </div>
    </div>
</div>
<div class="page-body">
    <div class="container-xl">
        <section class="card" aria-labelledby="history-title">
            <div class="card-header">
                <div class="row g-2 align-items-center w-100">
                    <div class="col-12 col-md">
                        <h2 class="card-title" id="history-title">
                            Диагностики
                            <?php if (!$loadError) : ?>
                                <span class="badge bg-secondary-lt ms-1"><?= e($pagination['total']) ?></span>
                            <?php endif; ?>
                        </h2>
                    </div>
                    <div class="col-12 col-md-auto">
                        <form method="get" action="<?= e($resetUrl) ?>" role="search" aria-label="Поиск по истории">
                            <div class="input-group input-group-lg">
                                <input type="search"
                                       class="form-control"
                                       name="q"
                                       value="<?= e($query) ?>"
                                       placeholder="Домен, например sudar.su"
                                       aria-label="Адрес сайта"
                                       autocomplete="off"
                                       autocapitalize="off"
                                       spellcheck="false">
                                <button type="submit" class="btn btn-icon" aria-label="Найти">
                                    <i class="ti ti-search icon" aria-hidden="true"></i>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <?php if ($loadError) : ?>
                <div class="card-body">
                    <div class="alert alert-danger mb-0" role="alert">
                        <div class="alert-description">Не удалось загрузить историю. Подробности в журнале.</div>
                    </div>
                </div>
            <?php elseif ($rows === []) : ?>
                <div class="card-body">
                    <div class="empty">
                        <div class="empty-icon">
                            <i class="ti <?= $query === '' ? 'ti-history' : 'ti-search' ?> icon" aria-hidden="true"></i>
                        </div>
                        <?php if ($query === '') : ?>
                            <p class="empty-title">Диагностик пока нет</p>
                            <p class="empty-subtitle text-secondary">Запустите первую проверку сайта — она появится здесь.</p>
                        <?php else : ?>
                            <p class="empty-title">Ничего не найдено</p>
                            <p class="empty-subtitle text-secondary text-break">По запросу «<?= e($query) ?>» диагностик нет.</p>
                        <?php endif; ?>
                        <div class="empty-action d-flex flex-wrap justify-content-center gap-2">
                            <?php if ($query !== '') : ?>
                                <a href="<?= e($resetUrl) ?>" class="btn">Сбросить поиск</a>
                            <?php endif; ?>
                            <a href="/" class="btn btn-primary">Новая диагностика</a>
                        </div>
                    </div>
                </div>
            <?php else : ?>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th scope="col">Сайт</th>
                                <th scope="col" class="d-none d-md-table-cell">Дата</th>
                                <th scope="col">Статус</th>
                                <th scope="col" class="d-none d-md-table-cell">Находки</th>
                                <th scope="col" class="w-1"><span class="visually-hidden">Действия</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row) : ?>
                                <tr>
                                    <td>
                                        <a href="<?= e($row['showUrl']) ?>" class="fw-medium text-break"><?= e($row['displayHost']) ?></a>
                                        <div class="text-secondary small text-break d-none d-sm-block"><?= e($row['url']) ?></div>
                                        <div class="text-secondary small d-md-none"><?= e($row['createdAt']) ?></div>
                                    </td>
                                    <td class="d-none d-md-table-cell text-nowrap"><?= e($row['createdAt']) ?></td>
                                    <td><span class="badge <?= e($row['badgeClass']) ?>"><?= e($row['statusLabel']) ?></span></td>
                                    <td class="d-none d-md-table-cell">
                                        <?php if (!$row['isFinished']) : ?>
                                            <span class="text-secondary">—</span>
                                        <?php elseif ($row['findingCount'] === 0) : ?>
                                            <span class="text-secondary">нет</span>
                                        <?php else : ?>
                                            <div class="d-flex flex-wrap align-items-center gap-1">
                                                <?php if ($row['criticalCount'] > 0) : ?>
                                                    <span class="badge bg-red text-red-fg" title="Критичные"><?= e($row['criticalCount']) ?> крит.</span>
                                                <?php endif; ?>
                                                <?php if ($row['highCount'] > 0) : ?>
                                                    <span class="badge bg-orange text-orange-fg" title="Высокие"><?= e($row['highCount']) ?> выс.</span>
                                                <?php endif; ?>
                                                <span class="text-secondary small">всего <?= e($row['findingCount']) ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($row['canDelete']) : ?>
                                            <button type="button"
                                                    class="btn btn-ghost-danger btn-icon btn-lg"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#history-delete-modal"
                                                    data-delete-url="<?= e($row['deleteUrl']) ?>"
                                                    data-delete-site="<?= e($row['displayHost']) ?>"
                                                    data-delete-date="<?= e($row['createdAt']) ?>"
                                                    aria-label="Удалить диагностику <?= e($row['displayHost']) ?> от <?= e($row['createdAt']) ?>">
                                                <i class="ti ti-trash icon" aria-hidden="true"></i>
                                            </button>
                                        <?php else : ?>
                                            <button type="button"
                                                    class="btn btn-ghost-secondary btn-icon btn-lg"
                                                    title="Идёт проверка — удалить нельзя"
                                                    aria-label="Удалить нельзя: идёт проверка"
                                                    disabled>
                                                <i class="ti ti-trash-off icon" aria-hidden="true"></i>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($pagination['pages'] > 1) : ?>
                    <div class="card-footer d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <p class="m-0 text-secondary small">
                            Страница <?= e($pagination['current']) ?> из <?= e($pagination['pages']) ?>
                        </p>
                        <nav aria-label="Страницы истории">
                            <ul class="pagination pagination-lg m-0">
                                <li class="page-item<?= $paginationLinks['prev'] === null ? ' disabled' : '' ?>">
                                    <?php if ($paginationLinks['prev'] !== null) : ?>
                                        <a class="page-link" href="<?= e($paginationLinks['prev']) ?>" aria-label="Предыдущая страница">
                                            <i class="ti ti-chevron-left icon" aria-hidden="true"></i>
                                        </a>
                                    <?php else : ?>
                                        <span class="page-link" aria-hidden="true"><i class="ti ti-chevron-left icon"></i></span>
                                    <?php endif; ?>
                                </li>
                                <?php foreach ($paginationLinks['links'] as $link) : ?>
                                    <?php if ($link['number'] === null) : ?>
                                        <li class="page-item disabled d-none d-sm-block"><span class="page-link" aria-hidden="true">…</span></li>
                                    <?php elseif ($link['active']) : ?>
                                        <li class="page-item active">
                                            <span class="page-link" aria-current="page"><?= e($link['number']) ?></span>
                                        </li>
                                    <?php else : ?>
                                        <li class="page-item d-none d-sm-block">
                                            <a class="page-link" href="<?= e($link['url']) ?>"><?= e($link['number']) ?></a>
                                        </li>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <li class="page-item<?= $paginationLinks['next'] === null ? ' disabled' : '' ?>">
                                    <?php if ($paginationLinks['next'] !== null) : ?>
                                        <a class="page-link" href="<?= e($paginationLinks['next']) ?>" aria-label="Следующая страница">
                                            <i class="ti ti-chevron-right icon" aria-hidden="true"></i>
                                        </a>
                                    <?php else : ?>
                                        <span class="page-link" aria-hidden="true"><i class="ti ti-chevron-right icon"></i></span>
                                    <?php endif; ?>
                                </li>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </div>
</div>

<div class="modal modal-blur fade" id="history-delete-modal" tabindex="-1" aria-labelledby="history-delete-title" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered" role="document">
        <div class="modal-content">
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
            <div class="modal-status bg-danger"></div>
            <div class="modal-body text-center py-4">
                <i class="ti ti-alert-triangle icon mb-2 text-danger icon-lg" aria-hidden="true"></i>
                <h3 id="history-delete-title">Удалить диагностику?</h3>
                <p class="text-secondary text-break mb-0">
                    <span class="fw-medium" data-delete-site></span> от <span data-delete-date></span>:
                    отчёт, стек и находки удалятся без возможности восстановления.
                </p>
            </div>
            <div class="modal-footer">
                <div class="row g-2 w-100 m-0">
                    <div class="col">
                        <button type="button" class="btn w-100" data-bs-dismiss="modal">Отмена</button>
                    </div>
                    <div class="col">
                        <form method="post" action="" data-delete-form>
                            <?= csrfField() ?>
                            <input type="hidden" name="q" value="<?= e($query) ?>">
                            <input type="hidden" name="page" value="<?= e($pagination['current']) ?>">
                            <button type="submit" class="btn btn-danger w-100">Удалить</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script type="module" src="/assets/js/history.js"></script>
<?php render('admin/layout/footer'); ?>
