<?php

declare(strict_types=1);

/**
 * Состояние и подпись ИИ-блока отчёта (внутри `card`, после содержимого). Ожидает: $aiBlock — `state`
 * (done / skipped / failed), `message` (причина для skipped / failed), `meta` (модель, дата, токены, стоимость
 * `cost` — null, если цена неизвестна; или meta null).
 */
?>
<?php if ($aiBlock['state'] === 'skipped') : ?>
    <div class="card-body">
        <div class="alert alert-info mb-0" role="status">
            <div class="alert-description text-break"><?= e($aiBlock['message']) ?></div>
        </div>
    </div>
<?php elseif ($aiBlock['state'] === 'failed') : ?>
    <div class="card-body">
        <div class="alert alert-danger mb-0" role="alert">
            <h3 class="alert-heading h4">ИИ-анализ не удался</h3>
            <div class="alert-description text-break"><?= e($aiBlock['message']) ?></div>
        </div>
    </div>
<?php endif; ?>
<?php if ($aiBlock['meta'] !== null) : ?>
    <div class="card-footer small text-secondary">
        Модель: <?= e($aiBlock['meta']['model']) ?><?php if ($aiBlock['meta']['date'] !== null) : ?> · <?= e($aiBlock['meta']['date']) ?><?php endif; ?>
        · токены <?= e($aiBlock['meta']['tokensIn']) ?> / <?= e($aiBlock['meta']['tokensOut']) ?>
        · <?= e($aiBlock['meta']['cost'] ?? 'стоимость неизвестна') ?>
    </div>
<?php endif; ?>
