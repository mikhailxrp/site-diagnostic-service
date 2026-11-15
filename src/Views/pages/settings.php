<?php

declare(strict_types=1);

/**
 * Настройки: модели RouterAI (форма) и ключи API из .env (только просмотр).
 * Ожидает: $title, $activeMenu, $modelFields (ключ → label, hint), $values (ключ → значение),
 * $errors (ключ → текст ошибки), $isEmpty (модели ещё не сохранялись),
 * $envKeys (list: name, label, value; value = null — не задан).
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
        <div class="row row-cards">
            <div class="col-12 col-lg-7">
                <section class="card" aria-labelledby="settings-models-title">
                    <form method="post" action="/settings" novalidate>
                        <?= csrfField() ?>
                        <div class="card-header">
                            <h2 class="card-title" id="settings-models-title">Модели ИИ (RouterAI)</h2>
                        </div>
                        <div class="card-body">
                            <?php if ($isEmpty) : ?>
                                <div class="alert alert-info" role="status">
                                    <div class="alert-description">Модели ещё не заданы. Укажите их для всех трёх задач.</div>
                                </div>
                            <?php endif; ?>
                            <?php foreach ($modelFields as $fieldKey => $field) : ?>
                                <?php
                                $fieldId    = 'setting-' . $fieldKey;
                                $fieldError = $errors[$fieldKey] ?? null;
                                ?>
                                <div class="mb-3">
                                    <label class="form-label required" for="<?= e($fieldId) ?>"><?= e($field['label']) ?></label>
                                    <input type="text"
                                           class="form-control form-control-lg<?= $fieldError !== null ? ' is-invalid' : '' ?>"
                                           id="<?= e($fieldId) ?>"
                                           name="<?= e($fieldKey) ?>"
                                           value="<?= e($values[$fieldKey] ?? '') ?>"
                                           maxlength="<?= e(MODEL_NAME_MAX_LENGTH) ?>"
                                           placeholder="например, openai/gpt-4o-mini"
                                           autocomplete="off"
                                           spellcheck="false"
                                           required
                                           aria-describedby="<?= e($fieldId) ?>-hint<?= $fieldError !== null ? ' ' . e($fieldId) . '-error' : '' ?>"
                                           <?= $fieldError !== null ? 'aria-invalid="true"' : '' ?>>
                                    <?php if ($fieldError !== null) : ?>
                                        <div class="invalid-feedback" id="<?= e($fieldId) ?>-error"><?= e($fieldError) ?></div>
                                    <?php endif; ?>
                                    <small class="form-hint" id="<?= e($fieldId) ?>-hint"><?= e($field['hint']) ?></small>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="card-footer text-end">
                            <button type="submit" class="btn btn-primary btn-lg">Сохранить</button>
                        </div>
                    </form>
                </section>
            </div>
            <div class="col-12 col-lg-5">
                <section class="card" aria-labelledby="settings-env-title">
                    <div class="card-header">
                        <h2 class="card-title" id="settings-env-title">Ключи API (.env)</h2>
                    </div>
                    <div class="card-body">
                        <div class="datagrid">
                            <?php foreach ($envKeys as $envKey) : ?>
                                <div class="datagrid-item">
                                    <div class="datagrid-title"><?= e($envKey['label']) ?></div>
                                    <div class="datagrid-content">
                                        <?php if ($envKey['value'] === null) : ?>
                                            <span class="badge bg-secondary-lt">не задан</span>
                                        <?php else : ?>
                                            <span class="badge bg-success-lt">задан</span>
                                            <code class="text-break"><?= e($envKey['value']) ?></code>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <p class="text-secondary mt-3 mb-0">
                            Значения меняются только в файле <code>.env</code> в корне проекта.
                        </p>
                    </div>
                </section>
            </div>
        </div>
    </div>
</div>
<?php render('admin/layout/footer'); ?>
