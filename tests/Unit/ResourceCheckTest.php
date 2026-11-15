<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/src/Services/DiagnosticRunner.php';

/**
 * Без сети: главная и CSS — из фикстур, ответы ресурсов — заготовки.
 */
final class ResourceCheckTest extends TestCase
{
    private const FIXTURES = ROOT_PATH . '/tests/fixtures/resources/';
    private const PAGE_ID = 7;

    /** @var list<string> «METHOD url» в порядке запросов */
    private array $requested = [];

    /**
     * @param array<string, mixed> $home поля главной поверх обычных
     */
    private function context(?string $html, array $home = []): array
    {
        $context = buildStepContext(['id' => 1, 'url' => 'https://shop.test/', 'host' => 'shop.test', 'problem_text' => null]);
        if ($html === null) {
            return $context;
        }

        $context['facts']['availability']['home'] = $home + [
            'url'                     => 'https://shop.test/',
            'final_url'               => 'https://shop.test/',
            'status'                  => 200,
            'headers'                 => [],
            'body'                    => $html,
            'truncated'               => false,
            'possible_bot_protection' => false,
            'page_id'                 => self::PAGE_ID,
        ];

        return $context;
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES . $name);
    }

    /**
     * @param array<string, array{status?: ?int, body?: string, error?: ?string}> $responses
     *        «METHOD url» или «url» → ответ; остальные адреса — 200 без тела
     */
    private function request(array $responses = []): callable
    {
        return function (string $url, string $method) use ($responses): array {
            $this->requested[] = "{$method} {$url}";
            $response = $responses["{$method} {$url}"] ?? $responses[$url] ?? [];

            return [
                'final_url' => $url,
                'status'    => array_key_exists('status', $response) ? $response['status'] : 200,
                'headers'   => [],
                'body'      => $response['body'] ?? '',
                'truncated' => false,
                'redirects' => [],
                'time_ms'   => 5,
                'error'     => $response['error'] ?? null,
            ];
        };
    }

    /**
     * @return array<string, array{status?: ?int, body?: string, error?: ?string}>
     */
    private function pageResponses(): array
    {
        return [
            'https://shop.test/css/main.css'       => ['body' => $this->fixture('main.css')],
            'https://shop.test/css/parts/grid.css' => ['body' => $this->fixture('grid.css')],
            'https://shop.test/css/missing.css'    => ['status' => 404],
            'https://shop.test/js/broken.js'       => ['status' => 500],
            'https://shop.test/fonts/shop.ttf'     => ['status' => 404],
            'https://shop.test/img/lazy.jpg'       => ['status' => null, 'error' => 'Тайм-аут соединения'],
            'HEAD https://shop.test/img/lazy.jpg'  => ['status' => null, 'error' => 'Тайм-аут соединения'],
        ];
    }

    /**
     * @return list<string>
     */
    private function urls(array $result): array
    {
        return array_column($result['facts']['resources'], 'url');
    }

    public function testNoHomeIsSkippedWithReason(): void
    {
        $result = runResourceCheck($this->context(null), $this->request());

        $this->assertSame('skipped', $result['status']);
        $this->assertSame(RESOURCES_NO_HOME_MESSAGE, $result['message']);
        $this->assertSame([], $this->requested);
    }

    public function testHomeWithoutResponseIsSkipped(): void
    {
        $result = runResourceCheck($this->context('', ['status' => null]), $this->request());

        $this->assertSame('skipped', $result['status']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function brokenHtmlProvider(): array
    {
        return [
            'пусто'           => [''],
            'мусор'           => ["<<<>>>\x00\x01<script"],
            'незакрытые теги' => ['<html><head><link rel="stylesheet" href="/a.css"><div><p>'],
        ];
    }

    #[DataProvider('brokenHtmlProvider')]
    public function testBrokenHtmlDoesNotThrow(string $html): void
    {
        $result = runResourceCheck($this->context($html), $this->request());

        $this->assertSame('done', $result['status']);
        $this->assertSame([], $result['findings']);
    }

    public function testCollectsAllResourceKindsWithoutDuplicates(): void
    {
        $result = runResourceCheck($this->context($this->fixture('page.html')), $this->request($this->pageResponses()));
        $urls = $this->urls($result);

        $expected = [
            'https://shop.test/css/main.css',
            'https://shop.test/css/missing.css',
            'https://shop.test/js/app.js',
            'https://shop.test/js/broken.js',
            'https://shop.test/favicon.ico',
            'https://shop.test/fonts/preload.woff2',
            'https://shop.test/img/logo.png',
            'https://shop.test/img/placeholder.gif',
            'https://shop.test/img/lazy.jpg',
            'https://video.test/embed/1',
            'https://shop.test/img/hero.jpg',
            'https://shop.test/img/banner.webp',
            // из main.css — относительно адреса CSS
            'https://shop.test/css/parts/grid.css',
            'https://shop.test/fonts/shop.woff2',
            'https://shop.test/fonts/shop.ttf',
            'https://shop.test/fonts/icons.eot', // `icons.eot?#iefix` — тот же файл
            'https://shop.test/fonts/icons.svg',
            'https://shop.test/img/sprite.svg',
            // из grid.css (импорт): url() разбирается, @import внутри — только проверяется
            'https://shop.test/css/parts/deeper.css',
            'https://shop.test/css/parts/grid-bg.png',
        ];
        $this->assertEqualsCanonicalizing($expected, $urls);
        $this->assertSame($urls, array_values(array_unique($urls)));

        // canonical, preconnect, #gradient, data:, закомментированный url() — не ресурсы
        $this->assertNotContains('https://shop.test/', $urls);
        $this->assertNotContains('https://cdn.test', $urls);
        $this->assertNotContains('https://shop.test/img/commented.png', $urls);
    }

    public function testDuplicateUrlIsRequestedOnce(): void
    {
        runResourceCheck($this->context($this->fixture('page.html')), $this->request($this->pageResponses()));

        $appRequests = array_filter($this->requested, static fn (string $r): bool => str_ends_with($r, '/js/app.js'));
        $this->assertCount(1, $appRequests);
    }

    public function testResourceTypes(): void
    {
        $result = runResourceCheck($this->context($this->fixture('page.html')), $this->request($this->pageResponses()));
        $types = array_column($result['facts']['resources'], 'type', 'url');

        $this->assertSame('style', $types['https://shop.test/css/main.css']);
        $this->assertSame('script', $types['https://shop.test/js/app.js']);
        $this->assertSame('icon', $types['https://shop.test/favicon.ico']);
        $this->assertSame('font', $types['https://shop.test/fonts/preload.woff2']);
        $this->assertSame('font', $types['https://shop.test/fonts/shop.woff2']);
        $this->assertSame('image', $types['https://shop.test/img/sprite.svg']);
        $this->assertSame('font', $types['https://shop.test/fonts/icons.svg']); // svg внутри @font-face
        $this->assertSame('frame', $types['https://video.test/embed/1']);
        $this->assertSame('style', $types['https://shop.test/css/parts/deeper.css']);
    }

    public function testNestedResourcesRememberWhereTheyWereFound(): void
    {
        $result = runResourceCheck($this->context($this->fixture('page.html')), $this->request($this->pageResponses()));
        $foundIn = array_column($result['facts']['resources'], 'found_in', 'url');

        $this->assertSame('https://shop.test/', $foundIn['https://shop.test/img/hero.jpg']);
        $this->assertSame('https://shop.test/css/main.css', $foundIn['https://shop.test/fonts/shop.ttf']);
        $this->assertSame('https://shop.test/css/parts/grid.css', $foundIn['https://shop.test/css/parts/grid-bg.png']);
    }

    public function testImportedCssIsNotParsedFurther(): void
    {
        runResourceCheck(
            $this->context($this->fixture('page.html')),
            $this->request($this->pageResponses() + [
                'https://shop.test/css/parts/deeper.css' => ['body' => '.x { background: url(too-deep.png); }'],
            ])
        );

        $this->assertNotContains('HEAD https://shop.test/css/parts/too-deep.png', $this->requested);
        $this->assertNotContains('GET https://shop.test/css/parts/too-deep.png', $this->requested);
    }

    public function testEachBrokenResourceIsSeparateFinding(): void
    {
        $result = runResourceCheck($this->context($this->fixture('page.html')), $this->request($this->pageResponses()));
        $findings = array_column($result['findings'], null, 'location');

        $this->assertCount(4, $findings);

        $css = $findings['https://shop.test/css/missing.css'];
        $this->assertSame('broken_resource', $css['category']);
        $this->assertSame('medium', $css['severity']);
        $this->assertSame(404, $css['evidence']['status']);
        $this->assertSame('style', $css['evidence']['type']);
        $this->assertSame(self::PAGE_ID, $css['page_id']);
        $this->assertStringContainsString('404', $css['title']);

        $this->assertSame(500, $findings['https://shop.test/js/broken.js']['evidence']['status']);
        $this->assertSame('https://shop.test/css/main.css', $findings['https://shop.test/fonts/shop.ttf']['evidence']['found_in']);
    }

    public function testNoResponseIsLowSeverityFinding(): void
    {
        $result = runResourceCheck($this->context($this->fixture('page.html')), $this->request($this->pageResponses()));
        $findings = array_column($result['findings'], null, 'location');
        $lazy = $findings['https://shop.test/img/lazy.jpg'];

        $this->assertSame('low', $lazy['severity']);
        $this->assertNull($lazy['evidence']['status']);
        $this->assertSame('Тайм-аут соединения', $lazy['evidence']['error']);
        // HEAD без ответа → повтор через GET
        $this->assertContains('HEAD https://shop.test/img/lazy.jpg', $this->requested);
        $this->assertContains('GET https://shop.test/img/lazy.jpg', $this->requested);
    }

    public function testFallbackFontFormatIsLowSeverity(): void
    {
        $result = runResourceCheck(
            $this->context($this->fixture('page.html')),
            $this->request($this->pageResponses() + ['https://shop.test/fonts/icons.eot' => ['status' => 404]])
        );
        $findings = array_column($result['findings'], null, 'location');

        // в @font-face есть woff2 — ttf браузер не грузит
        $ttf = $findings['https://shop.test/fonts/shop.ttf'];
        $this->assertSame('low', $ttf['severity']);
        $this->assertTrue($ttf['evidence']['fallback']);
        $this->assertStringContainsString('запасной формат', (string) $ttf['details']);

        // woff2 нет — eot настоящая поломка
        $eot = $findings['https://shop.test/fonts/icons.eot'];
        $this->assertSame('medium', $eot['severity']);
        $this->assertFalse($eot['evidence']['fallback']);
    }

    /**
     * @return array<string, array{?int}>
     */
    public static function headFailureProvider(): array
    {
        return [
            '404'        => [404],
            '405'        => [405],
            '418'        => [418],
            'нет ответа' => [null],
        ];
    }

    #[DataProvider('headFailureProvider')]
    public function testHeadFailureIsConfirmedWithGet(?int $headStatus): void
    {
        $result = runResourceCheck(
            $this->context('<img src="/a.png" alt="">'),
            $this->request(['HEAD https://shop.test/a.png' => ['status' => $headStatus, 'error' => 'HEAD не прошёл']])
        );

        $this->assertSame(['HEAD https://shop.test/a.png', 'GET https://shop.test/a.png'], $this->requested);
        $this->assertSame([], $result['findings']);
    }

    public function testBrokenOnBothMethodsIsFindingWithGet(): void
    {
        $result = runResourceCheck($this->context('<img src="/a.png" alt="">'), $this->request(['https://shop.test/a.png' => ['status' => 404]]));

        $this->assertCount(1, $result['findings']);
        $this->assertSame('GET', $result['findings'][0]['evidence']['method']);
    }

    public function testHeadRejectedIsRetriedWithGet(): void
    {
        $html = '<img src="/a.png" alt="">';
        $result = runResourceCheck(
            $this->context($html),
            $this->request(['HEAD https://shop.test/a.png' => ['status' => 405]])
        );

        $this->assertSame(['HEAD https://shop.test/a.png', 'GET https://shop.test/a.png'], $this->requested);
        $this->assertSame([], $result['findings']);
        $this->assertSame('GET', $result['facts']['resources'][0]['method']);
        $this->assertSame(200, $result['facts']['resources'][0]['status']);
    }

    public function testWorkingResourceIsCheckedWithHeadOnly(): void
    {
        runResourceCheck($this->context('<script src="/a.js"></script>'), $this->request());

        $this->assertSame(['HEAD https://shop.test/a.js'], $this->requested);
    }

    public function testStylesheetIsAlwaysFetchedWithGet(): void
    {
        runResourceCheck($this->context('<link rel="stylesheet" href="/a.css">'), $this->request());

        $this->assertSame(['GET https://shop.test/a.css'], $this->requested);
    }

    public function testBrokenStylesheetIsNotParsed(): void
    {
        runResourceCheck(
            $this->context('<link rel="stylesheet" href="/a.css">'),
            $this->request(['https://shop.test/a.css' => ['status' => 404, 'body' => '.x { background: url(/b.png); }']])
        );

        $this->assertSame(['GET https://shop.test/a.css'], $this->requested);
    }

    public function testProgressIsReported(): void
    {
        $html = '';
        for ($i = 1; $i <= 25; $i++) {
            $html .= "<img src=\"/{$i}.png\" alt=\"\">";
        }
        $calls = [];

        runResourceCheck($this->context($html), $this->request(), static function (int $checked) use (&$calls): void {
            $calls[] = $checked;
        });

        $this->assertSame([10, 20], $calls);
    }

    public function testBotProtectionMarksFactsLimited(): void
    {
        $result = runResourceCheck(
            $this->context('<img src="/a.png" alt="">', ['status' => 403, 'possible_bot_protection' => true]),
            $this->request()
        );

        $this->assertSame('done', $result['status']);
        $this->assertTrue($result['facts']['limited']);
    }

    public function testStepAddsPhpErrorsAndMixedContent(): void
    {
        $html = '<img src="http://shop.test/a.png" alt="">'
            . '<b>Warning</b>:  Undefined index: id in <b>/var/www/index.php</b> on line <b>3</b>';
        $context = $this->context($html);
        $result = addPageErrorFindings(
            runResourceCheck($context, $this->request()),
            $context['facts']['availability']['home']
        );

        $this->assertSame(['php_error', 'mixed_content'], array_column($result['findings'], 'category'));
        $this->assertSame('Проверено ресурсов: 1, битых: 0; PHP-ошибок: 1; mixed content: 1', $result['message']);
        foreach ($result['findings'] as $finding) {
            $this->assertSame(self::PAGE_ID, $finding['page_id']);
        }
    }

    public function testStepKeepsSkippedResult(): void
    {
        $skipped = runResourceCheck($this->context(null), $this->request());

        $this->assertSame($skipped, addPageErrorFindings($skipped, []));
    }
}
