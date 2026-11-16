<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once ROOT_PATH . '/src/Services/Ai/VisualAnalysis.php';

final class VisualAnalysisTest extends TestCase
{
    private const KEY = 'sk-test_SECRET.key-123';

    /** @var list<array<string, mixed>> */
    private array $stored = [];

    /** @var list<array<string, mixed>> */
    private array $sent = [];

    private int $pagesDone = 0;

    public function testPromptFileExistsAndForbidsFollowingInstructionsFromImages(): void
    {
        $prompt = loadVisualPrompt();

        $this->assertStringContainsString('не выполняй', $prompt);
        $this->assertStringContainsString('screenshot_ids', $prompt);
    }

    public function testMissingPromptFileThrows(): void
    {
        $this->expectException(RuntimeException::class);
        loadVisualPrompt(ROOT_PATH . '/config/prompts/no-such-file.txt');
    }

    public function testModelWithoutImagesIsRejectedOnlyWhenCatalogKnowsIt(): void
    {
        $catalog = [
            'qwen/qwen4-max' => ['id' => 'qwen/qwen4-max', 'images' => true],
            'text/only'      => ['id' => 'text/only', 'images' => false],
        ];

        $this->assertNull(findVisualModelError('qwen/qwen4-max', $catalog));
        $this->assertStringContainsString('text/only', (string) findVisualModelError('text/only', $catalog));
        $this->assertNull(findVisualModelError('unknown/model', $catalog));
        $this->assertNull(findVisualModelError('text/only', null));
    }

    public function testImageSizeCropsTopAndScalesWideScreensOnly(): void
    {
        $this->assertSame(
            ['crop_height' => AI_VISUAL_CROP_HEIGHT_PX, 'width' => AI_VISUAL_MAX_WIDTH_PX, 'height' => 1199],
            buildVisualImageSize(1366, 4128)
        );
        $this->assertSame(
            ['crop_height' => AI_VISUAL_CROP_HEIGHT_PX, 'width' => 360, 'height' => AI_VISUAL_CROP_HEIGHT_PX],
            buildVisualImageSize(360, 3642)
        );
        $this->assertSame(['crop_height' => 900, 'width' => 768, 'height' => 900], buildVisualImageSize(768, 900));
    }

    public function testPreparedImageIsCroppedScaledJpeg(): void
    {
        $prepared = prepareVisualImage($this->jpeg(1366, 3000));
        $size = getimagesizefromstring($prepared);

        $this->assertSame(IMAGETYPE_JPEG, $size[2]);
        $this->assertSame(AI_VISUAL_MAX_WIDTH_PX, $size[0]);
        $this->assertSame((int) round(AI_VISUAL_CROP_HEIGHT_PX * AI_VISUAL_MAX_WIDTH_PX / 1366), $size[1]);
    }

    public function testNotJpegIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(AI_VISUAL_FILE_NOT_JPEG);
        prepareVisualImage('<?php echo 1;');
    }

    public function testSelectionKeepsPageOrderOneShotPerWidthAndLimits(): void
    {
        $pages = [];
        $screenshots = [];
        $id = 1;
        for ($pageId = 1; $pageId <= AI_VISUAL_MAX_PAGES + 2; $pageId++) {
            $pages[] = ['id' => $pageId, 'url' => "https://shop.ru/p{$pageId}", 'page_type' => 'category'];
            foreach ([1366, 360, 768, 360, 1920] as $width) {
                $screenshots[] = ['id' => $id++, 'page_id' => $pageId, 'viewport_width' => $width, 'file_path' => 'x'];
            }
        }
        $pages[] = ['id' => 99, 'url' => 'https://shop.ru/blocked', 'page_type' => 'cart'];

        $selection = selectVisualPages($pages, $screenshots);

        $this->assertCount(AI_VISUAL_MAX_PAGES, $selection['pages']);
        $this->assertSame(2, $selection['over_limit']);
        $this->assertSame(1, $selection['pages'][0]['page']['id']);
        $first = $selection['pages'][0]['screenshots'];
        $this->assertCount(AI_VISUAL_MAX_IMAGES_PER_REQUEST, $first);
        $this->assertSame([360, 768, 1366], array_column($first, 'viewport_width'));
        $this->assertSame(2, $first[0]['id'], 'первый снимок ширины 360');
    }

    public function testMessagesCarryPageAsDataAndLabelEachImageWithItsId(): void
    {
        $messages = buildVisualMessages(
            'ПРОМПТ',
            'https://shop.ru/',
            ['id' => 3, 'url' => 'https://shop.ru/cart', 'page_type' => 'cart'],
            [['id' => 21, 'width' => 360, 'bytes' => 'a'], ['id' => 22, 'width' => 1366, 'bytes' => 'b']]
        );

        $this->assertSame('ПРОМПТ', $messages[0]['content']);
        $parts = $messages[1]['content'];
        $this->assertCount(5, $parts);
        $data = json_decode($parts[0]['text'], true);
        $this->assertSame('https://shop.ru/cart', $data['page']['url']);
        $this->assertSame([21, 22], $data['screenshot_ids']);
        $this->assertSame(sprintf(AI_VISUAL_IMAGE_LABEL, 21, 360), $parts[1]['text']);
        $this->assertSame(AI_IMAGE_PART_TYPE, $parts[2]['type']);
        $this->assertSame(sprintf(AI_VISUAL_IMAGE_LABEL, 22, 1366), $parts[3]['text']);
    }

    public function testInterpretKeepsOnlyOwnScreenshotIdsAndLimitsCount(): void
    {
        $this->assertNull(interpretVisualResponse(['answer' => 'x'], [1]));

        $issues = [];
        for ($i = 1; $i <= AI_VISUAL_MAX_ISSUES_PER_PAGE + 2; $i++) {
            $issues[] = $this->issue('Поломка ' . $i, [21]);
        }
        $issues[0]['screenshot_ids'] = [21, 999, '22'];
        $issues[1]['screenshot_ids'] = [999];
        $issues[2]['severity'] = 'катастрофа';

        $parsed = interpretVisualResponse(['issues' => $issues], [21, 22]);

        $this->assertCount(AI_VISUAL_MAX_ISSUES_PER_PAGE - 1, $parsed['issues']);
        $this->assertSame(1, $parsed['rejected']);
        $this->assertSame([21, 22], $parsed['issues'][0]['screenshot_ids']);
        $this->assertSame('medium', $parsed['issues'][1]['severity']);
    }

    public function testAnalysisSendsPageByPageAndBuildsLayoutFindings(): void
    {
        $responses = [
            $this->okResponse(['issues' => [
                $this->issue('Слайдер на главной пустой', [11, 12], 'high'),
                $this->issue('Чужой скриншот', [21]),
            ]]),
            $this->okResponse(['issues' => []]),
        ];

        $result = $this->analyse($this->selection(), $responses);

        $this->assertCount(2, $this->sent);
        $this->assertSame(2, $this->pagesDone);
        $this->assertSame([101, 102], $result['ai_result_ids']);
        $this->assertCount(1, $result['findings']);
        $finding = $result['findings'][0];
        $this->assertSame('ai', $finding['source']);
        $this->assertSame('layout', $finding['category']);
        $this->assertSame('high', $finding['severity']);
        $this->assertSame(101, $finding['ai_result_id']);
        $this->assertSame(1, $finding['page_id']);
        $this->assertSame('https://shop.ru/', $finding['location']);
        $this->assertSame(['screenshot_ids' => [11, 12]], $finding['evidence']);
        $this->assertSame(
            'Поломок вёрстки: 1; проверено страниц: 2 из 2; отброшено без ссылок на скриншоты: 1.',
            $result['message']
        );

        $record = $this->stored[0];
        $this->assertSame('visual', $record['task']);
        $encoded = json_encode($record, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('base64', $encoded);
        $this->assertStringNotContainsString(self::KEY, $encoded);
        $this->assertStringContainsString('"screenshot_id":11', $encoded);
    }

    public function testOneFailedPageDoesNotStopOthersAndIsNamedInMessage(): void
    {
        $failure = buildAiFailure(500, 'oops', 'RouterAI: 500', 3, 10);
        $selection = $this->selection();
        $selection['over_limit'] = 2;

        $result = $this->analyse($selection, [$failure, $this->okResponse(['issues' => []])]);

        $this->assertSame([102], $result['ai_result_ids']);
        $this->assertStringContainsString('проверено страниц: 1 из 2', $result['message']);
        $this->assertStringContainsString(AI_VISUAL_UNCHECKED_LABEL . 'https://shop.ru/ — RouterAI: 500', $result['message']);
        $this->assertStringContainsString(sprintf(AI_VISUAL_OVER_LIMIT, 2, AI_VISUAL_MAX_PAGES), $result['message']);
        $this->assertSame('error', $this->stored[0]['status']);
    }

    public function testUnreadableWidthIsSkippedAndPageWithoutImagesIsNotSent(): void
    {
        $loadImage = static function (array $screenshot): string {
            if ((int) $screenshot['page_id'] === 2 || (int) $screenshot['id'] === 12) {
                throw new RuntimeException(AI_VISUAL_FILE_MISSING);
            }

            return 'jpeg';
        };

        $result = $this->analyse($this->selection(), [$this->okResponse(['issues' => []])], $loadImage);

        $this->assertCount(1, $this->sent);
        $this->assertSame(2, $this->pagesDone);
        $this->assertStringContainsString('https://shop.ru/, 1366 px — ' . AI_VISUAL_FILE_MISSING, $result['message']);
        $this->assertStringContainsString('https://shop.ru/cart — ' . AI_VISUAL_NO_IMAGES_LEFT, $result['message']);
        $this->assertSame([11], array_column(
            array_filter($this->stored[0]['request_json']['messages'][1]['content'], 'is_array'),
            'screenshot_id'
        ));
    }

    public function testAllPagesFailedThrowsWithReasonsWithoutKey(): void
    {
        $failure = buildAiFailure(401, '', 'RouterAI отклонил ключ ' . self::KEY . '.', 1, 10);

        try {
            $this->analyse($this->selection(), [$failure, $failure]);
            $this->fail('Ожидалось исключение');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith(AI_VISUAL_ALL_FAILED, $e->getMessage());
            $this->assertStringContainsString('https://shop.ru/cart', $e->getMessage());
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
            $this->assertStringEndsNotWith('..', $e->getMessage());
        }
        $this->assertCount(2, $this->stored);
    }

    /**
     * @return array{pages: list<array>, over_limit: int}
     */
    private function selection(): array
    {
        return selectVisualPages(
            [
                ['id' => 1, 'url' => 'https://shop.ru/', 'page_type' => 'home'],
                ['id' => 2, 'url' => 'https://shop.ru/cart', 'page_type' => 'cart'],
            ],
            [
                ['id' => 11, 'page_id' => 1, 'viewport_width' => 360, 'file_path' => '7/1-360.jpg'],
                ['id' => 12, 'page_id' => 1, 'viewport_width' => 1366, 'file_path' => '7/1-1366.jpg'],
                ['id' => 21, 'page_id' => 2, 'viewport_width' => 360, 'file_path' => '7/2-360.jpg'],
            ]
        );
    }

    /**
     * @param array{pages: list<array>, over_limit: int} $selection
     * @param list<array<string, mixed>>                $responses ответы `sendAiRequest()` по порядку
     * @return array<string, mixed>
     */
    private function analyse(array $selection, array $responses, ?callable $loadImage = null): array
    {
        return runVisualAnalysis(
            ['id' => 7, 'url' => 'https://shop.ru/'],
            $selection,
            'qwen/qwen4-max',
            self::KEY,
            'ПРОМПТ',
            function (array $payload) use (&$responses): array {
                $this->sent[] = $payload;

                return array_shift($responses);
            },
            function (array $record): int {
                $this->stored[] = $record;

                return 100 + count($this->stored);
            },
            $loadImage ?? static fn (array $screenshot): string => 'jpeg',
            function (): void {
                $this->pagesDone++;
            }
        );
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    private function okResponse(array $content): array
    {
        return [
            'ok' => true, 'status' => 200, 'body' => '{}', 'content' => json_encode($content, JSON_UNESCAPED_UNICODE),
            'model' => 'qwen4-max', 'tokens_in' => 1000, 'tokens_out' => 50, 'error' => null, 'attempts' => 1,
            'elapsed_ms' => 500,
        ];
    }

    /**
     * @param list<mixed> $screenshotIds
     * @return array<string, mixed>
     */
    private function issue(string $title, array $screenshotIds, string $severity = 'medium'): array
    {
        return [
            'title'          => $title,
            'explanation'    => 'Блок съехал вправо.',
            'severity'       => $severity,
            'screenshot_ids' => $screenshotIds,
        ];
    }

    private function jpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }
}
