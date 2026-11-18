<?php

declare(strict_types=1);

/**
 * Шаги сценария заказа. Ожидает подготовленный `report.checkout` из ReportBuilder.
 */
?>
<ol class="list-group list-group-flush" aria-label="Шаги сценария заказа">
    <?php foreach ($checkout['steps'] as $step) : ?>
        <li class="list-group-item">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                <h3 class="h4 mb-0 text-break"><?= e($step['label']) ?></h3>
                <span class="badge <?= e($step['badgeClass']) ?>"><?= e($step['statusLabel']) ?></span>
            </div>
            <?php if ($step['url'] !== null) : ?>
                <div class="small text-secondary text-break mt-1"><?= e($step['url']) ?></div>
            <?php endif; ?>
            <?php if ($step['note'] !== null) : ?>
                <p class="small text-secondary text-break mt-1 mb-0"><?= e($step['note']) ?></p>
            <?php endif; ?>
            <?php if ($step['consoleErrors'] === 0) : ?>
                <p class="small text-secondary mt-2 mb-0">Ошибок консоли нет.</p>
            <?php else : ?>
                <div class="small mt-2">
                    <span class="fw-medium">Ошибок консоли: <?= e($step['consoleErrors']) ?></span>
                    <?php if ($step['consoleSample'] !== []) : ?>
                        <ul class="text-secondary text-break mt-1 mb-0">
                            <?php foreach ($step['consoleSample'] as $message) : ?>
                                <li><?= e($message) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php render('components/screenshot-gallery', ['gallery' => $step['gallery']]); ?>
        </li>
    <?php endforeach; ?>
</ol>
