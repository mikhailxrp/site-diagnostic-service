<?php

declare(strict_types=1);

/**
 * Скриншоты страницы: миниатюры по ширинам, крупно — модальное окно Tabler.
 * Ожидает: $gallery (строка из buildReportPages(): screenshots, screenshotsEmpty, screenshotsNote).
 */
?>
<?php if ($gallery['screenshots'] === []) : ?>
    <div class="text-secondary small mt-2">
        <?= e($gallery['screenshotsEmpty']) ?>
        <?php if ($gallery['screenshotsNote'] !== null) : ?>
            <span class="text-break"><?= e($gallery['screenshotsNote']) ?></span>
        <?php endif; ?>
    </div>
<?php else : ?>
    <ul class="row g-2 list-unstyled mt-2 mb-0" aria-label="Скриншоты страницы">
        <?php foreach ($gallery['screenshots'] as $screenshot) : ?>
            <li class="col-4 col-md-3 col-xl-2">
                <button type="button" class="btn btn-ghost-secondary d-block w-100 h-auto p-1"
                        data-bs-toggle="modal" data-bs-target="#<?= e($screenshot['modalId']) ?>"
                        aria-label="Открыть крупно: <?= e($screenshot['alt']) ?>">
                    <img src="<?= e($screenshot['thumbUrl']) ?>" alt="<?= e($screenshot['thumbAlt']) ?>"
                         class="img-thumbnail d-block w-100" loading="lazy">
                    <span class="d-block small text-secondary mt-1"><?= e($screenshot['caption']) ?></span>
                </button>
                <div class="modal modal-blur fade" id="<?= e($screenshot['modalId']) ?>" tabindex="-1"
                     aria-labelledby="<?= e($screenshot['modalId']) ?>-title" aria-hidden="true">
                    <div class="modal-dialog modal-xl modal-dialog-scrollable">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h3 class="modal-title" id="<?= e($screenshot['modalId']) ?>-title"><?= e($screenshot['alt']) ?></h3>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
                            </div>
                            <div class="modal-body p-0">
                                <img src="<?= e($screenshot['url']) ?>" alt="<?= e($screenshot['alt']) ?>"
                                     class="d-block img-fluid mx-auto" loading="lazy">
                            </div>
                            <div class="modal-footer">
                                <a href="<?= e($screenshot['url']) ?>" class="btn" target="_blank" rel="noopener">Открыть файл</a>
                                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Закрыть</button>
                            </div>
                        </div>
                    </div>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
