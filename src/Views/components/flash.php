<?php

declare(strict_types=1);

/**
 * Флеш-сообщения (успех / предупреждение / ошибка). Каждое выводится один раз: getFlash() удаляет его из сессии.
 */

$flashMessages = array_filter([
    'success' => getFlash('success'),
    'warning' => getFlash('warning'),
    'danger'  => getFlash('error'),
]);
?>
<?php if ($flashMessages !== []) : ?>
    <div class="container-xl pt-3">
        <?php foreach ($flashMessages as $flashType => $flashText) : ?>
            <div class="alert alert-<?= e($flashType) ?> alert-dismissible" role="alert">
                <div class="alert-description"><?= e($flashText) ?></div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Закрыть"></button>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
