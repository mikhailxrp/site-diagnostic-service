<?php

declare(strict_types=1);

/**
 * Сравнение двух диагностик. Ожидает готовые $before, $after и $comparison из CompareController.
 */

render('admin/layout/header', ['title' => $title, 'activeMenu' => $activeMenu]);

$factGroups = [
    ['key' => 'fixed', 'title' => 'Исправлено', 'icon' => 'ti-circle-check', 'statusClass' => 'bg-green'],
    ['key' => 'remaining', 'title' => 'Осталось', 'icon' => 'ti-equal', 'statusClass' => 'bg-yellow'],
    ['key' => 'appeared', 'title' => 'Появилось', 'icon' => 'ti-alert-circle', 'statusClass' => 'bg-red'],
];
?>
<div class="page-header">
    <div class="container-xl">
        <div class="row g-2 align-items-center">
            <div class="col">
                <div class="page-pretitle">История диагностик</div>
                <h1 class="page-title"><?= e($title) ?></h1>
            </div>
            <div class="col-auto">
                <a href="<?= e($after['reportUrl']) ?>" class="btn btn-outline-primary btn-lg">
                    <i class="ti ti-arrow-left icon" aria-hidden="true"></i>
                    К новому отчёту
                </a>
            </div>
        </div>
    </div>
</div>

<div class="page-body">
    <div class="container-xl">
        <div class="row row-cards">
            <div class="col-12 col-lg-6">
                <section class="card h-100" aria-labelledby="compare-before-title">
                    <div class="card-header">
                        <div>
                            <div class="text-secondary small">Было · диагностика #<?= e($before['id']) ?></div>
                            <h2 class="card-title text-break" id="compare-before-title"><?= e($before['url']) ?></h2>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="text-secondary mb-3">Дата: <?= e($before['createdAt']) ?></div>
                        <a href="<?= e($before['reportUrl']) ?>" class="btn btn-outline-secondary btn-lg">
                            Открыть прошлый отчёт
                        </a>
                    </div>
                </section>
            </div>
            <div class="col-12 col-lg-6">
                <section class="card h-100" aria-labelledby="compare-after-title">
                    <div class="card-header">
                        <div>
                            <div class="text-secondary small">Стало · диагностика #<?= e($after['id']) ?></div>
                            <h2 class="card-title text-break" id="compare-after-title"><?= e($after['url']) ?></h2>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="text-secondary mb-3">Дата: <?= e($after['createdAt']) ?></div>
                        <a href="<?= e($after['reportUrl']) ?>" class="btn btn-outline-secondary btn-lg">
                            Открыть новый отчёт
                        </a>
                    </div>
                </section>
            </div>

            <?php if ($before['partial'] || $after['partial']) : ?>
                <div class="col-12">
                    <div class="alert alert-warning mb-0" role="alert">
                        <div class="alert-icon"><i class="ti ti-alert-triangle icon" aria-hidden="true"></i></div>
                        <div class="alert-description">
                            Сравнение частичное:
                            <?php if ($before['partial'] && $after['partial']) : ?>оба запуска завершились с ошибкой<?php elseif ($before['partial']) : ?>прошлый запуск завершился с ошибкой<?php else : ?>новый запуск завершился с ошибкой<?php endif; ?>.
                            Показаны только данные, которые успели собраться.
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!$comparison['hasChanges']) : ?>
                <div class="col-12">
                    <div class="alert alert-success mb-0" role="status">
                        <div class="alert-icon"><i class="ti ti-circle-check icon" aria-hidden="true"></i></div>
                        <div class="alert-description">Проверяемые находки и известные версии стека не изменились.</div>
                    </div>
                </div>
            <?php endif; ?>

            <?php foreach ($factGroups as $group) : ?>
                <div class="col-12 col-lg-4">
                    <section class="card h-100" aria-labelledby="compare-<?= e($group['key']) ?>-title">
                        <div class="card-status-top <?= e($group['statusClass']) ?>"></div>
                        <div class="card-header">
                            <h2 class="card-title" id="compare-<?= e($group['key']) ?>-title">
                                <i class="ti <?= e($group['icon']) ?> icon" aria-hidden="true"></i>
                                <?= e($group['title']) ?>
                            </h2>
                            <div class="card-actions">
                                <span class="badge bg-secondary-lt"><?= e(count($comparison[$group['key']])) ?></span>
                            </div>
                        </div>
                        <?php if ($comparison[$group['key']] === []) : ?>
                            <div class="card-body">
                                <p class="text-secondary mb-0">В этой группе находок нет.</p>
                            </div>
                        <?php else : ?>
                            <ul class="list-group list-group-flush">
                                <?php foreach ($comparison[$group['key']] as $finding) : ?>
                                    <li class="list-group-item">
                                        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                            <span class="badge <?= e($finding['badgeClass']) ?>"><?= e($finding['severityLabel']) ?></span>
                                            <span class="text-secondary small"><?= e($finding['categoryLabel']) ?></span>
                                        </div>
                                        <h3 class="h4 mb-1 text-break"><?= e($finding['title']) ?></h3>
                                        <?php if ($finding['location'] !== null) : ?>
                                            <div class="small text-break"><span class="text-secondary">Где:</span> <?= e($finding['location']) ?></div>
                                        <?php endif; ?>
                                        <?php if ($finding['details'] !== null) : ?>
                                            <p class="text-secondary mt-2 mb-0 text-break"><?= nl2br(e($finding['details']), false) ?></p>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </section>
                </div>
            <?php endforeach; ?>

            <div class="col-12">
                <section class="card" aria-labelledby="compare-stack-title">
                    <div class="card-header">
                        <h2 class="card-title" id="compare-stack-title">Изменения стека</h2>
                    </div>
                    <?php if (
                        $comparison['stack']['versionChanges'] === []
                        && $comparison['stack']['addedPlugins'] === []
                        && $comparison['stack']['removedPlugins'] === []
                    ) : ?>
                        <div class="card-body">
                            <p class="text-secondary mb-0">Известные версии и список найденных плагинов не изменились.</p>
                        </div>
                    <?php else : ?>
                        <div class="card-body">
                            <div class="row g-4">
                                <div class="col-12 col-lg-4">
                                    <h3 class="h4">Изменились версии</h3>
                                    <?php if ($comparison['stack']['versionChanges'] === []) : ?>
                                        <p class="text-secondary mb-0">Изменений нет.</p>
                                    <?php else : ?>
                                        <ul class="list-group list-group-flush">
                                            <?php foreach ($comparison['stack']['versionChanges'] as $item) : ?>
                                                <li class="list-group-item px-0">
                                                    <div class="fw-medium text-break"><?= e($item['name']) ?></div>
                                                    <div class="small text-secondary"><?= e($item['categoryLabel']) ?></div>
                                                    <div class="mt-1"><code><?= e($item['beforeVersion']) ?></code> → <code><?= e($item['afterVersion']) ?></code></div>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </div>
                                <div class="col-12 col-lg-4">
                                    <h3 class="h4">Новые плагины</h3>
                                    <?php if ($comparison['stack']['addedPlugins'] === []) : ?>
                                        <p class="text-secondary mb-0">Новых плагинов нет.</p>
                                    <?php else : ?>
                                        <ul class="list-group list-group-flush">
                                            <?php foreach ($comparison['stack']['addedPlugins'] as $item) : ?>
                                                <li class="list-group-item px-0 text-break">
                                                    <?= e($item['name']) ?><?php if ($item['version'] !== null) : ?> <span class="text-secondary"><?= e($item['version']) ?></span><?php endif; ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </div>
                                <div class="col-12 col-lg-4">
                                    <h3 class="h4">Исчезнувшие плагины</h3>
                                    <?php if ($comparison['stack']['removedPlugins'] === []) : ?>
                                        <p class="text-secondary mb-0">Исчезнувших плагинов нет.</p>
                                    <?php else : ?>
                                        <ul class="list-group list-group-flush">
                                            <?php foreach ($comparison['stack']['removedPlugins'] as $item) : ?>
                                                <li class="list-group-item px-0 text-break">
                                                    <?= e($item['name']) ?><?php if ($item['version'] !== null) : ?> <span class="text-secondary"><?= e($item['version']) ?></span><?php endif; ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </section>
            </div>

            <div class="col-12">
                <section class="card" aria-labelledby="compare-ai-title">
                    <div class="card-status-top bg-purple"></div>
                    <div class="card-header">
                        <h2 class="card-title" id="compare-ai-title">Выводы ИИ</h2>
                        <div class="card-actions"><span class="badge bg-purple-lt">Не сопоставляются</span></div>
                    </div>
                    <div class="card-body border-bottom">
                        <p class="text-secondary mb-0">Формулировки ИИ могут меняться между запусками, поэтому они показаны рядом, но не считаются исправленными или новыми.</p>
                    </div>
                    <div class="card-body">
                        <div class="row g-4">
                            <?php foreach ([['title' => 'Было', 'items' => $comparison['aiBefore']], ['title' => 'Стало', 'items' => $comparison['aiAfter']]] as $aiGroup) : ?>
                                <div class="col-12 col-lg-6">
                                    <h3 class="h4"><?= e($aiGroup['title']) ?></h3>
                                    <?php if ($aiGroup['items'] === []) : ?>
                                        <p class="text-secondary mb-0">ИИ-находок нет.</p>
                                    <?php else : ?>
                                        <ul class="list-group list-group-flush">
                                            <?php foreach ($aiGroup['items'] as $finding) : ?>
                                                <li class="list-group-item px-0">
                                                    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                                        <span class="badge <?= e($finding['badgeClass']) ?>"><?= e($finding['severityLabel']) ?></span>
                                                        <span class="text-secondary small"><?= e($finding['categoryLabel']) ?></span>
                                                    </div>
                                                    <div class="fw-medium text-break"><?= e($finding['title']) ?></div>
                                                    <?php if ($finding['location'] !== null) : ?>
                                                        <div class="small text-break"><span class="text-secondary">Где:</span> <?= e($finding['location']) ?></div>
                                                    <?php endif; ?>
                                                    <?php if ($finding['details'] !== null) : ?>
                                                        <p class="text-secondary mt-2 mb-0 text-break"><?= nl2br(e($finding['details']), false) ?></p>
                                                    <?php endif; ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </div>
</div>
<?php render('admin/layout/footer'); ?>
