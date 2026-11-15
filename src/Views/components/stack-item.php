<?php

declare(strict_types=1);

/**
 * Один элемент стека. Ожидает: $stackItem (элемент из buildReportStackItem()).
 */
?>
<li class="list-group-item">
    <div class="d-flex flex-wrap align-items-center gap-2">
        <span class="fw-medium text-break"><?= e($stackItem['name']) ?></span>
        <?php if ($stackItem['version'] !== null) : ?>
            <span class="text-secondary"><?= e($stackItem['version']) ?></span>
        <?php endif; ?>
        <span class="badge <?= e($stackItem['badgeClass']) ?> ms-auto"><?= e($stackItem['confidenceLabel']) ?></span>
    </div>
    <?php if ($stackItem['evidence'] !== []) : ?>
        <details>
            <summary class="py-3 cursor-pointer text-secondary small">Источники признака (<?= e(count($stackItem['evidence'])) ?>)</summary>
            <ul class="list-unstyled small mb-0">
                <?php foreach ($stackItem['evidence'] as $signal) : ?>
                    <li class="mb-1 text-break"><span class="text-secondary"><?= e($signal['source']) ?>:</span> <?= e($signal['detail']) ?></li>
                <?php endforeach; ?>
            </ul>
        </details>
    <?php endif; ?>
</li>
