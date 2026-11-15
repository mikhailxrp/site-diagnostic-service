<?php

declare(strict_types=1);

/**
 * Новая диагностика: форма «ссылка + описание проблемы».
 * Ожидает: $title, $activeMenu, $values (url, problem_text), $errors (поле → текст ошибки),
 * $urlMaxLength, $problemMaxLength.
 */

$urlError     = $errors['url'] ?? null;
$problemError = $errors['problem_text'] ?? null;

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
            <div class="col-12 col-lg-8">
                <section class="card" aria-labelledby="diagnostic-form-title">
                    <form method="post" action="/diagnostics" novalidate>
                        <?= csrfField() ?>
                        <div class="card-header">
                            <h2 class="card-title" id="diagnostic-form-title">Какой сайт проверить</h2>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label required" for="diagnostic-url">Ссылка на сайт</label>
                                <input type="text"
                                       inputmode="url"
                                       class="form-control form-control-lg<?= $urlError !== null ? ' is-invalid' : '' ?>"
                                       id="diagnostic-url"
                                       name="url"
                                       value="<?= e($values['url']) ?>"
                                       maxlength="<?= e($urlMaxLength) ?>"
                                       placeholder="shop.ru"
                                       autocomplete="url"
                                       autocapitalize="off"
                                       spellcheck="false"
                                       required
                                       autofocus
                                       aria-describedby="diagnostic-url-hint<?= $urlError !== null ? ' diagnostic-url-error' : '' ?>"
                                       <?= $urlError !== null ? 'aria-invalid="true"' : '' ?>>
                                <?php if ($urlError !== null) : ?>
                                    <div class="invalid-feedback" id="diagnostic-url-error"><?= e($urlError) ?></div>
                                <?php endif; ?>
                                <small class="form-hint" id="diagnostic-url-hint">
                                    Можно без https:// — например, shop.ru или магазин.рф. Локальные адреса не проверяются.
                                </small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="diagnostic-problem">Описание проблемы (необязательно)</label>
                                <textarea class="form-control<?= $problemError !== null ? ' is-invalid' : '' ?>"
                                          id="diagnostic-problem"
                                          name="problem_text"
                                          rows="5"
                                          maxlength="<?= e($problemMaxLength) ?>"
                                          placeholder="Со слов клиента: например, «не оформляется заказ, корзина пустая после обновления»"
                                          aria-describedby="diagnostic-problem-hint<?= $problemError !== null ? ' diagnostic-problem-error' : '' ?>"
                                          <?= $problemError !== null ? 'aria-invalid="true"' : '' ?>><?= e($values['problem_text']) ?></textarea>
                                <?php if ($problemError !== null) : ?>
                                    <div class="invalid-feedback" id="diagnostic-problem-error"><?= e($problemError) ?></div>
                                <?php endif; ?>
                                <small class="form-hint" id="diagnostic-problem-hint">
                                    Не больше <?= e($problemMaxLength) ?> символов.
                                </small>
                            </div>
                        </div>
                        <div class="card-footer text-end">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="ti ti-player-play icon" aria-hidden="true"></i>
                                Запустить диагностику
                            </button>
                        </div>
                    </form>
                </section>
            </div>
        </div>
    </div>
</div>
<?php render('admin/layout/footer'); ?>
