<?php

declare(strict_types=1);

/**
 * Одна находка отчёта. Ожидает: $finding (элемент из buildReportFinding()).
 */
?>
<li class="list-group-item">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
        <span class="badge <?= e($finding['badgeClass']) ?>"><?= e($finding['severityLabel']) ?></span>
        <span class="text-secondary small"><?= e($finding['categoryLabel']) ?></span>
    </div>
    <h4 class="mb-1 text-break"><?= e($finding['title']) ?></h4>
    <?php if ($finding['location'] !== null) : ?>
        <div class="small text-break"><span class="text-secondary">Где:</span> <?= e($finding['location']) ?></div>
    <?php endif; ?>
    <?php if ($finding['pageUrl'] !== null) : ?>
        <div class="small text-break"><span class="text-secondary">Страница:</span> <?= e($finding['pageUrl']) ?></div>
    <?php endif; ?>
    <?php if ($finding['pageUrls'] !== []) : ?>
        <div class="small text-secondary">Страницы (<?= e(count($finding['pageUrls'])) ?>):</div>
        <ul class="small mb-0 ps-3">
            <?php foreach ($finding['pageUrls'] as $pageUrl) : ?>
                <li class="text-break"><?= e($pageUrl) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if ($finding['details'] !== null) : ?>
        <p class="text-secondary mt-2 mb-0 text-break"><?= nl2br(e($finding['details']), false) ?></p>
    <?php endif; ?>
    <?php if ($finding['evidenceJson'] !== null) : ?>
        <details class="mt-1">
            <summary class="py-3 cursor-pointer text-secondary">Сырые данные</summary>
            <pre class="mb-0"><code><?= e($finding['evidenceJson']) ?></code></pre>
        </details>
    <?php endif; ?>
</li>
