<?php

declare(strict_types=1);

/**
 * ИИ-задача «Проверка вёрстки»: верх страницы на трёх ширинах → визуальные поломки со ссылками на скриншоты.
 * Один запрос на страницу; поломка без допустимой ссылки на скриншот этого запроса отбрасывается; оставшиеся
 * становятся находками `layout` (`source = ai`). Без БД и сети: их передаёт шаг `ai_visual` (`DiagnosticRunner.php`).
 */

require_once ROOT_PATH . '/src/Services/FindingFactory.php';
require_once ROOT_PATH . '/src/Services/ScreenshotService.php';
require_once ROOT_PATH . '/src/Services/Ai/AiTask.php';
require_once ROOT_PATH . '/src/Services/Ai/EvidenceValidator.php';

const AI_TASK_VISUAL          = 'visual';
const AI_VISUAL_MODEL_SETTING = 'model_visual';
const AI_VISUAL_PROMPT_FILE   = ROOT_PATH . '/config/prompts/visual.txt';
const AI_VISUAL_CATEGORY      = 'layout';
const AI_VISUAL_EVIDENCE_KEY  = 'screenshot_ids';

/** Предел страниц и картинок (решение владельца, 16.11.2026) */
const AI_VISUAL_MAX_PAGES              = 10;
const AI_VISUAL_MAX_IMAGES_PER_REQUEST = 3;    // по одной на ширину BROWSER_VIEWPORT_WIDTHS
const AI_VISUAL_MAX_ISSUES_PER_PAGE    = 5;    // как в промпте: лишние отрезаются
const AI_VISUAL_CROP_HEIGHT_PX         = 1600; // ИИ смотрит только верх страницы
const AI_VISUAL_MAX_WIDTH_PX           = 1024; // шире — уменьшается пропорционально
const AI_VISUAL_JPEG_QUALITY           = 75;
const AI_VISUAL_JPEG_MAGIC             = "\xFF\xD8\xFF";
const AI_VISUAL_TEXT_PART_TYPE         = 'text';

const AI_VISUAL_PROMPT_MISSING   = 'Нет файла промпта config/prompts/visual.txt.';
const AI_VISUAL_NO_SCREENSHOTS   = 'Скриншотов нет: шаг «Проверка в браузере» не сделал снимков.';
const AI_VISUAL_NO_IMAGES_MODEL  = 'Модель %s не понимает картинки — выберите другую в настройках («Проверка вёрстки»).';
const AI_VISUAL_IMAGE_LABEL      = 'screenshot_id = %d, ширина %d px';
const AI_VISUAL_FILE_MISSING     = 'файл скриншота не найден';
const AI_VISUAL_FILE_NOT_JPEG    = 'файл скриншота — не JPEG';
const AI_VISUAL_FILE_BROKEN      = 'файл скриншота не раскодировался';
const AI_VISUAL_NO_IMAGES_LEFT   = 'ни один скриншот страницы не прочитался';
const AI_VISUAL_UNCHECKED_LABEL  = 'Не проверено: '; // по этой подписи отчёт показывает предупреждение
const AI_VISUAL_OVER_LIMIT       = 'ещё %d стр. сверх предела %d';
const AI_VISUAL_ALL_FAILED       = 'Не удалось проверить ни одной страницы. ';
const AI_VISUAL_ITEM_SEPARATOR   = '; ';

/** Схема ответа: строгий JSON; число поломок ограничивается в коде */
const AI_VISUAL_SCHEMA = [
    'name'   => 'layout_issues',
    'schema' => [
        'type'                 => 'object',
        'properties'           => [
            'issues' => [
                'type'  => 'array',
                'items' => [
                    'type'                 => 'object',
                    'properties'           => [
                        'title'          => ['type' => 'string'],
                        'explanation'    => ['type' => 'string'],
                        'severity'       => ['type' => 'string', 'enum' => FINDING_SEVERITIES],
                        'screenshot_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                    ],
                    'required'             => ['title', 'explanation', 'severity', 'screenshot_ids'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required'             => ['issues'],
        'additionalProperties' => false,
    ],
];

/**
 * @throws RuntimeException нет файла промпта
 */
function loadVisualPrompt(string $file = AI_VISUAL_PROMPT_FILE): string
{
    $prompt = is_file($file) ? file_get_contents($file) : false;
    if ($prompt === false || trim($prompt) === '') {
        throw new RuntimeException(AI_VISUAL_PROMPT_MISSING);
    }

    return trim($prompt);
}

/**
 * Модель есть в каталоге RouterAI и не понимает картинки → причина; нет каталога или модели в нём → null
 * (запрос всё равно отправляется: ответит сам RouterAI).
 *
 * @param array<string, array<string, mixed>>|null $catalog `loadRouterAiModels()['models']`
 */
function findVisualModelError(string $model, ?array $catalog): ?string
{
    if ($catalog === null || !isset($catalog[$model])) {
        return null;
    }

    return ($catalog[$model]['images'] ?? false) === true ? null : sprintf(AI_VISUAL_NO_IMAGES_MODEL, $model);
}

/**
 * Страницы со скриншотами в порядке `checked_pages.id` (главная первой), не больше AI_VISUAL_MAX_PAGES; у каждой —
 * по одному снимку на ширину (по возрастанию), не больше AI_VISUAL_MAX_IMAGES_PER_REQUEST.
 *
 * @param list<array<string, mixed>> $pages       из getCheckedPagesByDiagnostic()
 * @param list<array<string, mixed>> $screenshots из getScreenshotsByDiagnostic()
 * @return array{pages: list<array{page: array, screenshots: list<array>}>, over_limit: int}
 */
function selectVisualPages(array $pages, array $screenshots): array
{
    $byPage = [];
    foreach ($screenshots as $screenshot) {
        $pageId = (int) $screenshot['page_id'];
        $width = (int) $screenshot['viewport_width'];
        $byPage[$pageId][$width] ??= $screenshot;
    }

    $selected = [];
    foreach ($pages as $page) {
        $shots = $byPage[(int) $page['id']] ?? [];
        if ($shots === []) {
            continue;
        }
        ksort($shots);
        $selected[] = [
            'page'        => $page,
            'screenshots' => array_slice(array_values($shots), 0, AI_VISUAL_MAX_IMAGES_PER_REQUEST),
        ];
    }

    return [
        'pages'      => array_slice($selected, 0, AI_VISUAL_MAX_PAGES),
        'over_limit' => max(0, count($selected) - AI_VISUAL_MAX_PAGES),
    ];
}

/**
 * Размеры картинки для ИИ: верх до AI_VISUAL_CROP_HEIGHT_PX, ширина до AI_VISUAL_MAX_WIDTH_PX (пропорционально).
 *
 * @return array{crop_height: int, width: int, height: int}
 */
function buildVisualImageSize(int $width, int $height): array
{
    $cropHeight = min($height, AI_VISUAL_CROP_HEIGHT_PX);
    if ($width <= AI_VISUAL_MAX_WIDTH_PX) {
        return ['crop_height' => $cropHeight, 'width' => $width, 'height' => $cropHeight];
    }

    return [
        'crop_height' => $cropHeight,
        'width'       => AI_VISUAL_MAX_WIDTH_PX,
        'height'      => max(1, (int) round($cropHeight * AI_VISUAL_MAX_WIDTH_PX / $width)),
    ];
}

/**
 * JPEG снимка → обрезанный и уменьшенный JPEG для ИИ (GD). Память GD освобождается сразу.
 *
 * @throws RuntimeException не JPEG или не раскодировался
 */
function prepareVisualImage(string $jpegBytes): string
{
    if (!str_starts_with($jpegBytes, AI_VISUAL_JPEG_MAGIC)) {
        throw new RuntimeException(AI_VISUAL_FILE_NOT_JPEG);
    }
    $source = imagecreatefromstring($jpegBytes);
    if ($source === false) {
        throw new RuntimeException(AI_VISUAL_FILE_BROKEN);
    }

    $size = buildVisualImageSize(imagesx($source), imagesy($source));
    $target = imagecreatetruecolor($size['width'], $size['height']);
    try {
        imagecopyresampled(
            $target,
            $source,
            0,
            0,
            0,
            0,
            $size['width'],
            $size['height'],
            imagesx($source),
            $size['crop_height']
        );

        ob_start();
        $written = imagejpeg($target, null, AI_VISUAL_JPEG_QUALITY);
        $bytes = (string) ob_get_clean();
    } finally {
        unset($source, $target);
    }
    if (!$written || $bytes === '') {
        throw new RuntimeException(AI_VISUAL_FILE_BROKEN);
    }

    return $bytes;
}

/**
 * Файл снимка из `storage/screenshots/` → картинка для ИИ.
 *
 * @param array<string, mixed> $screenshot строка `screenshots` (с `file_path`)
 * @throws RuntimeException файла нет или он не читается
 */
function loadVisualImage(array $screenshot): string
{
    $path = resolveScreenshotPath((string) ($screenshot['file_path'] ?? ''));
    $bytes = $path !== null && is_file($path) ? file_get_contents($path) : false;
    if ($bytes === false) {
        throw new RuntimeException(AI_VISUAL_FILE_MISSING);
    }

    return prepareVisualImage($bytes);
}

/**
 * Системный промпт отдельно; во втором сообщении — страница одним JSON (данные), затем по каждой картинке подпись
 * с её `screenshot_id` и ширину, и сама картинка.
 *
 * @param array<string, mixed>                              $page строка `checked_pages`
 * @param list<array{id: int, width: int, bytes: string}> $images
 * @return list<array{role: string, content: string|list<array>}>
 */
function buildVisualMessages(string $systemPrompt, string $siteUrl, array $page, array $images): array
{
    $parts = [[
        'type' => AI_VISUAL_TEXT_PART_TYPE,
        'text' => json_encode([
            'site'             => $siteUrl,
            'page'             => ['url' => (string) $page['url'], 'type' => $page['page_type'] ?? null],
            'top_height_px'    => AI_VISUAL_CROP_HEIGHT_PX,
            'screenshot_ids'   => array_column($images, 'id'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    ]];
    foreach ($images as $image) {
        $parts[] = ['type' => AI_VISUAL_TEXT_PART_TYPE, 'text' => sprintf(AI_VISUAL_IMAGE_LABEL, $image['id'], $image['width'])];
        $parts[] = buildAiImagePart($image['bytes']);
    }

    return [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $parts],
    ];
}

/**
 * Ответ модели → поломки с допустимыми ссылками и число отброшенных. Нет списка `issues` → null (не по схеме).
 *
 * @param array<string, mixed> $decoded
 * @param list<int>            $screenshotIds id картинок этого запроса
 * @return array{issues: list<array{title: string, explanation: string, severity: string,
 *               screenshot_ids: list<int>}>, rejected: int}|null
 */
function interpretVisualResponse(array $decoded, array $screenshotIds): ?array
{
    $issues = $decoded['issues'] ?? null;
    if (!is_array($issues) || !array_is_list($issues)) {
        return null;
    }

    $normalized = [];
    $untitled = 0;
    foreach (array_slice($issues, 0, AI_VISUAL_MAX_ISSUES_PER_PAGE) as $issue) {
        $item = is_array($issue) ? normalizeVisualIssue($issue) : null;
        if ($item === null) {
            $untitled++;
            continue;
        }
        $normalized[] = $item;
    }

    $checked = validateAiEvidence($normalized, [AI_VISUAL_EVIDENCE_KEY => $screenshotIds]);

    return ['issues' => $checked['items'], 'rejected' => $checked['rejected'] + $untitled];
}

/**
 * @param array<string, mixed> $issue
 * @return array<string, mixed>|null без заголовка — null
 */
function normalizeVisualIssue(array $issue): ?array
{
    $title = is_string($issue['title'] ?? null) ? trim($issue['title']) : '';
    if ($title === '') {
        return null;
    }
    $severity = $issue['severity'] ?? null;

    return [
        'title'                => $title,
        'explanation'          => is_string($issue['explanation'] ?? null) ? trim($issue['explanation']) : '',
        'severity'             => in_array($severity, FINDING_SEVERITIES, true)
            ? $severity
            : FINDING_DEFAULT_SEVERITY[AI_VISUAL_CATEGORY],
        AI_VISUAL_EVIDENCE_KEY => $issue[AI_VISUAL_EVIDENCE_KEY] ?? [],
    ];
}

/**
 * Поломки → находки `layout` страницы: объяснение — в `details`, ссылки на скриншоты — в `evidence`.
 *
 * @param list<array<string, mixed>> $issues из interpretVisualResponse()
 * @param array<string, mixed>       $page   строка `checked_pages`
 * @return list<array<string, mixed>>
 */
function buildVisualFindings(array $issues, int $aiResultId, array $page): array
{
    return array_map(static fn (array $issue): array => makeAiFinding(
        AI_VISUAL_CATEGORY,
        $issue['title'],
        $issue['explanation'] !== '' ? $issue['explanation'] : null,
        $issue['severity'],
        [AI_VISUAL_EVIDENCE_KEY => $issue[AI_VISUAL_EVIDENCE_KEY]],
        $aiResultId,
        (string) $page['url'],
        (int) $page['id']
    ), $issues);
}

/**
 * «Поломок вёрстки: 3; проверено страниц: 4 из 5; отброшено без ссылок на скриншоты: 1. Не проверено: адрес —
 * причина; ещё 2 стр. сверх предела 10.»
 *
 * @param list<string> $unchecked «адрес — причина»
 */
function buildVisualStepMessage(int $issues, int $checkedPages, int $sentPages, int $rejected, array $unchecked, int $overLimit): string
{
    $message = "Поломок вёрстки: {$issues}; проверено страниц: {$checkedPages} из {$sentPages}";
    if ($rejected > 0) {
        $message .= "; отброшено без ссылок на скриншоты: {$rejected}";
    }
    $message .= '.';

    $notes = buildVisualUncheckedNote($unchecked, $overLimit);

    return $notes !== null ? $message . ' ' . $notes : $message;
}

/**
 * @param list<string> $unchecked
 */
function buildVisualUncheckedNote(array $unchecked, int $overLimit): ?string
{
    if ($overLimit > 0) {
        $unchecked[] = sprintf(AI_VISUAL_OVER_LIMIT, $overLimit, AI_VISUAL_MAX_PAGES);
    }

    // Причины часто сами кончаются точкой («…в .env.») — без двойной точки в конце
    return $unchecked === []
        ? null
        : AI_VISUAL_UNCHECKED_LABEL . rtrim(implode(AI_VISUAL_ITEM_SEPARATOR, $unchecked), '.') . '.';
}

/**
 * Проверка вёрстки целиком: по запросу на страницу → `ai_results` → находки. Ошибка одной страницы не мешает
 * остальным; не проверена ни одна — исключение (`failed`).
 *
 * @param array{id: int, url: string}             $diagnostic
 * @param array{pages: list<array>, over_limit: int} $selection из selectVisualPages()
 * @param callable(array $payload): array          $send
 * @param callable(array $record): int             $store
 * @param callable(array $screenshot): string      $loadImage строка `screenshots` → JPEG для ИИ
 * @param callable(): void                         $onPage    после каждой страницы (heartbeat)
 * @return array{findings: list<array>, message: string, ai_result_ids: list<int>}
 * @throws RuntimeException не проверена ни одна страница
 */
function runVisualAnalysis(
    array $diagnostic,
    array $selection,
    string $model,
    #[\SensitiveParameter] string $key,
    string $systemPrompt,
    callable $send,
    callable $store,
    callable $loadImage,
    callable $onPage
): array {
    $findings = [];
    $resultIds = [];
    $unchecked = [];
    $rejected = 0;
    $issueCount = 0;

    foreach ($selection['pages'] as $item) {
        $page = $item['page'];
        $url = (string) $page['url'];

        $images = [];
        foreach ($item['screenshots'] as $screenshot) {
            $width = (int) $screenshot['viewport_width'];
            try {
                $images[] = ['id' => (int) $screenshot['id'], 'width' => $width, 'bytes' => $loadImage($screenshot)];
            } catch (Throwable $e) {
                $unchecked[] = "{$url}, {$width} px — {$e->getMessage()}";
            }
        }
        if ($images === []) {
            $unchecked[] = "{$url} — " . AI_VISUAL_NO_IMAGES_LEFT;
            $onPage();
            continue;
        }

        $screenshotIds = array_column($images, 'id');
        try {
            $result = runAiTask(
                (int) $diagnostic['id'],
                AI_TASK_VISUAL,
                $model,
                buildVisualMessages($systemPrompt, (string) $diagnostic['url'], $page, $images),
                AI_VISUAL_SCHEMA,
                $key,
                $send,
                static fn (array $decoded): ?array => interpretVisualResponse($decoded, $screenshotIds),
                $store,
                $screenshotIds
            );
            $issues = $result['parsed']['issues'];
            $findings = array_merge($findings, buildVisualFindings($issues, $result['ai_result_id'], $page));
            $resultIds[] = $result['ai_result_id'];
            $rejected += $result['parsed']['rejected'];
            $issueCount += count($issues);
        } catch (RuntimeException $e) {
            $unchecked[] = "{$url} — {$e->getMessage()}";
        }
        unset($images);
        $onPage();
    }

    if ($resultIds === []) {
        throw new RuntimeException(
            AI_VISUAL_ALL_FAILED . (buildVisualUncheckedNote($unchecked, $selection['over_limit']) ?? '')
        );
    }

    return [
        'findings'      => $findings,
        'message'       => buildVisualStepMessage(
            $issueCount,
            count($resultIds),
            count($selection['pages']),
            $rejected,
            $unchecked,
            $selection['over_limit']
        ),
        'ai_result_ids' => $resultIds,
    ];
}
