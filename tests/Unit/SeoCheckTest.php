<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/src/Services/DiagnosticRunner.php';
require_once ROOT_PATH . '/src/Services/ReportBuilder.php';

/**
 * Без сети: robots.txt, sitemap и страницы — из фикстур, ответы — заготовки. Серьёзности сверяются с SEO_SEVERITY,
 * чтобы правка таблицы не ломала тесты.
 */
final class SeoCheckTest extends TestCase
{
    private const FIXTURES = ROOT_PATH . '/tests/fixtures/seo/';
    private const HOME = 'https://shop.test/';
    private const HOME_PAGE_ID = 7;

    /** @var list<string> адреса в порядке запросов */
    private array $requested = [];

    private function fixture(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES . $name);
    }

    /**
     * @param array<string, mixed>                                      $home  поля главной поверх обычных
     * @param array<string, array{0: string, 1?: array<string, mixed>}> $crawl тип → [HTML, поля поверх обычных]
     */
    private function context(string $homeHtml, array $home = [], array $crawl = []): array
    {
        $context = buildStepContext(['id' => 1, 'url' => self::HOME, 'host' => 'shop.test', 'problem_text' => null]);
        $context['facts']['availability']['home'] = $home + [
            'url'       => self::HOME,
            'final_url' => self::HOME,
            'status'    => 200,
            'headers'   => [],
            'body'      => $homeHtml,
            'page_id'   => self::HOME_PAGE_ID,
        ];

        $pageId = self::HOME_PAGE_ID;
        foreach ($crawl as $type => $page) {
            $url = self::HOME . "{$type}/";
            $context['facts']['crawl']['pages'][$type] = ($page[1] ?? []) + [
                'url'       => $url,
                'final_url' => $url,
                'status'    => 200,
                'headers'   => [],
                'body'      => $page[0],
                'page_id'   => ++$pageId,
            ];
        }

        return $context;
    }

    /**
     * @param array<string, array{status?: ?int, body?: string, headers?: array, truncated?: bool}> $responses
     *        адрес → ответ; robots.txt по умолчанию — обычный, остальные адреса — 404
     */
    private function request(array $responses = []): callable
    {
        $responses += [
            self::HOME . 'robots.txt'       => ['body' => $this->fixture('robots-ok.txt')],
            self::HOME . 'sitemap-main.xml' => ['body' => $this->fixture('sitemap.xml')],
        ];

        return function (string $url) use ($responses): array {
            $this->requested[] = $url;
            $response = $responses[$url] ?? ['status' => 404];

            return [
                'final_url' => $url,
                'status'    => array_key_exists('status', $response) ? $response['status'] : 200,
                'headers'   => $response['headers'] ?? [],
                'body'      => $response['body'] ?? '',
                'truncated' => $response['truncated'] ?? false,
                'redirects' => [],
                'time_ms'   => 5,
                'error'     => ($response['status'] ?? 200) === null ? 'Тайм-аут соединения' : null,
            ];
        };
    }

    private function check(array $context, array $responses = []): array
    {
        return runSeoCheck($context, $this->request($responses));
    }

    /**
     * @return list<string>
     */
    private function titles(array $result): array
    {
        return array_column($result['findings'], 'title');
    }

    private function findingByTitle(array $result, string $needle): array
    {
        foreach ($result['findings'] as $finding) {
            if (str_contains($finding['title'], $needle)) {
                return $finding;
            }
        }
        $this->fail("Нет находки «{$needle}», есть: " . implode('; ', $this->titles($result)));
    }

    public function testNoHomeIsSkipped(): void
    {
        $context = buildStepContext(['id' => 1, 'url' => self::HOME, 'host' => 'shop.test', 'problem_text' => null]);

        $result = $this->check($context);

        $this->assertSame('skipped', $result['status']);
        $this->assertSame([], $this->requested);
    }

    public function testHealthySiteHasNoFindings(): void
    {
        $result = $this->check($this->context($this->fixture('page-ok.html')));

        $this->assertSame([], $result['findings']);
        $this->assertSame('ok', $result['facts']['robots']['state']);
        $this->assertSame(['https://shop.test/sitemap-main.xml'], $result['facts']['robots']['sitemaps']);
        $this->assertSame('ok', $result['facts']['sitemap']['state']);
        $this->assertSame('robots', $result['facts']['sitemap']['source']);
        $this->assertSame(3, $result['facts']['sitemap']['urls_count']);
        // sitemap из robots.txt используется вместо типового адреса
        $this->assertNotContains(self::HOME . 'sitemap.xml', $this->requested);
        $this->assertSame('robots.txt: есть; sitemap: 3 адресов; страниц: 1; находок: 0', $result['message']);
    }

    public function testDisallowAllIsHigh(): void
    {
        $result = $this->check(
            $this->context($this->fixture('page-ok.html')),
            [self::HOME . 'robots.txt' => ['body' => $this->fixture('robots-disallow-all.txt')]]
        );

        $finding = $this->findingByTitle($result, 'закрыт от поисковиков');
        $this->assertSame(SEO_SEVERITY['robots_disallow_all'], $finding['severity']);
        $this->assertSame('high', $finding['severity']);
        $this->assertSame(['*'], $finding['evidence']['agents']);
    }

    public function testDisallowForYandexAndGoogleNamesRobots(): void
    {
        $result = $this->check(
            $this->context($this->fixture('page-ok.html')),
            [self::HOME . 'robots.txt' => ['body' => $this->fixture('robots-yandex-google.txt')]]
        );

        $finding = $this->findingByTitle($result, 'закрыт от поисковиков');
        $this->assertSame(['yandex', 'googlebot'], $finding['evidence']['agents']);
        $this->assertStringContainsString('Яндекс', $finding['title']);
        $this->assertStringContainsString('Google', $finding['title']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function allowedRobots(): array
    {
        return [
            'частичный запрет и пустой Disallow' => ['robots-ok.txt'],
            'Disallow: / отменён Allow: /'       => ['robots-allow-root.txt'],
        ];
    }

    #[DataProvider('allowedRobots')]
    public function testPartialDisallowIsNotFinding(string $fixture): void
    {
        $result = $this->check(
            $this->context($this->fixture('page-ok.html')),
            [self::HOME . 'robots.txt' => ['body' => $this->fixture($fixture)]]
        );

        $this->assertSame([], $result['facts']['robots']['disallow_all']);
        $this->assertSame([], array_filter($this->titles($result), static fn (string $t): bool => str_contains($t, 'robots')));
    }

    /**
     * @return array<string, array{array, string, string}>
     */
    public static function brokenRobots(): array
    {
        return [
            '404'             => [['status' => 404], 'missing', 'robots_missing'],
            'HTML вместо txt' => [['body' => '<!DOCTYPE html><html><body>Страница не найдена</body></html>'], 'missing', 'robots_missing'],
            'text/html'       => [['body' => 'oops', 'headers' => ['content-type' => ['text/html; charset=utf-8']]], 'missing', 'robots_missing'],
            '503'             => [['status' => 503], 'error', 'robots_error'],
            'нет ответа'      => [['status' => null], 'error', 'robots_error'],
        ];
    }

    #[DataProvider('brokenRobots')]
    public function testMissingOrBrokenRobots(array $response, string $state, string $severityKey): void
    {
        $result = $this->check($this->context($this->fixture('page-ok.html')), [self::HOME . 'robots.txt' => $response]);

        $this->assertSame($state, $result['facts']['robots']['state']);
        $finding = $this->findingByTitle($result, 'robots.txt');
        $this->assertSame(SEO_SEVERITY[$severityKey], $finding['severity']);
        $this->assertSame(self::HOME . 'robots.txt', $finding['location']);
    }

    public function testRobotsBehindBotProtectionIsNotChecked(): void
    {
        $stub = (string) file_get_contents(ROOT_PATH . '/tests/fixtures/bot-protection/cloudflare-challenge.html');
        $result = $this->check(
            $this->context($this->fixture('page-ok.html')),
            [self::HOME . 'robots.txt' => ['status' => 403, 'body' => $stub]]
        );

        $this->assertSame('blocked', $result['facts']['robots']['state']);
        $this->assertSame([], array_filter($this->titles($result), static fn (string $t): bool => str_contains($t, 'robots')));
    }

    public function testNoSitemapAnywhereIsLow(): void
    {
        $result = $this->check(
            $this->context($this->fixture('page-ok.html')),
            [self::HOME . 'robots.txt' => ['body' => "User-agent: *\nDisallow: /admin/\n"]]
        );

        $this->assertContains(self::HOME . 'sitemap.xml', $this->requested);
        $this->assertSame('missing', $result['facts']['sitemap']['state']);
        $this->assertSame(SEO_SEVERITY['sitemap_missing'], $this->findingByTitle($result, 'Нет карты сайта')['severity']);
    }

    public function testDefaultSitemapAsHtmlPageIsMissing(): void
    {
        $result = $this->check($this->context($this->fixture('page-ok.html')), [
            self::HOME . 'robots.txt'  => ['body' => "User-agent: *\nDisallow:\n"],
            self::HOME . 'sitemap.xml' => ['body' => '<!DOCTYPE html><html><body>Главная</body></html>'],
        ]);

        $this->assertSame('missing', $result['facts']['sitemap']['state']);
        $this->assertSame(SEO_SEVERITY['sitemap_missing'], $this->findingByTitle($result, 'Нет карты сайта')['severity']);
    }

    public function testSitemapFromRobotsThatFailsIsError(): void
    {
        $result = $this->check(
            $this->context($this->fixture('page-ok.html')),
            [self::HOME . 'sitemap-main.xml' => ['status' => 404]]
        );

        $this->assertSame('error', $result['facts']['sitemap']['state']);
        $finding = $this->findingByTitle($result, 'Карта сайта не открывается');
        $this->assertSame(SEO_SEVERITY['sitemap_error'], $finding['severity']);
        $this->assertStringContainsString('robots.txt ссылается', (string) $finding['details']);
    }

    public function testBrokenSitemapXmlIsError(): void
    {
        $result = $this->check(
            $this->context($this->fixture('page-ok.html')),
            [self::HOME . 'sitemap-main.xml' => ['body' => $this->fixture('sitemap-broken.xml')]]
        );

        $this->assertSame('error', $result['facts']['sitemap']['state']);
        $this->assertSame(SEO_SEVERITY['sitemap_error'], $this->findingByTitle($result, 'не XML-sitemap')['severity']);
    }

    public function testEmptySitemapIsLow(): void
    {
        $result = $this->check(
            $this->context($this->fixture('page-ok.html')),
            [self::HOME . 'sitemap-main.xml' => ['body' => $this->fixture('sitemap-empty.xml')]]
        );

        $this->assertSame('empty', $result['facts']['sitemap']['state']);
        $this->assertSame(SEO_SEVERITY['sitemap_empty'], $this->findingByTitle($result, 'пустая')['severity']);
    }

    /**
     * Регрессия sudar.su: в индексе `<loc>Array</loc>` — находка, адреса считаются по первому правильному файлу.
     */
    public function testNonUrlLocInSitemapIsFindingAndSkippedForCounting(): void
    {
        $result = $this->check($this->context($this->fixture('page-ok.html')), [
            self::HOME . 'sitemap-main.xml'    => ['body' => $this->fixture('sitemap-index-bad-loc.xml')],
            self::HOME . 'sitemap-catalog.xml' => ['body' => $this->fixture('sitemap.xml')],
        ]);

        $sitemap = $result['facts']['sitemap'];
        $this->assertSame('ok', $sitemap['state']);
        $this->assertSame(2, $sitemap['sitemaps_count']);
        $this->assertSame(self::HOME . 'sitemap-catalog.xml', $sitemap['first_url']);
        $this->assertSame(3, $sitemap['urls_count']);
        $this->assertNotContains('Array', $this->requested);

        $finding = $this->findingByTitle($result, 'записи вместо адресов');
        $this->assertSame(SEO_SEVERITY['sitemap_bad_loc'], $finding['severity']);
        $this->assertSame(['Array'], $finding['evidence']['bad_locs']);
    }

    public function testSitemapIndexCountsFilesAndUrlsOfFirstOnly(): void
    {
        $result = $this->check($this->context($this->fixture('page-ok.html')), [
            self::HOME . 'sitemap-main.xml'     => ['body' => $this->fixture('sitemap-index.xml')],
            self::HOME . 'sitemap-catalog.xml'  => ['body' => $this->fixture('sitemap.xml')],
        ]);

        $sitemap = $result['facts']['sitemap'];
        $this->assertSame('ok', $sitemap['state']);
        $this->assertTrue($sitemap['is_index']);
        $this->assertSame(2, $sitemap['sitemaps_count']);
        $this->assertSame(3, $sitemap['urls_count']);
        $this->assertSame(self::HOME . 'sitemap-catalog.xml', $sitemap['first_url']);
        $this->assertNotContains(self::HOME . 'sitemap-products.xml', $this->requested);
    }

    public function testGzipSitemapIsRead(): void
    {
        $result = $this->check($this->context($this->fixture('page-ok.html')), [
            self::HOME . 'robots.txt'      => ['body' => "User-agent: *\nDisallow:\nSitemap: https://shop.test/sitemap.xml.gz\n"],
            self::HOME . 'sitemap.xml.gz'  => ['body' => (string) gzencode($this->fixture('sitemap.xml'))],
        ]);

        $this->assertSame('ok', $result['facts']['sitemap']['state']);
        $this->assertSame(3, $result['facts']['sitemap']['urls_count']);
    }

    public function testTruncatedSitemapIsCountedWithoutXmlParsing(): void
    {
        $partial = substr($this->fixture('sitemap.xml'), 0, (int) strpos($this->fixture('sitemap.xml'), '</urlset>'));
        $result = $this->check(
            $this->context($this->fixture('page-ok.html')),
            [self::HOME . 'sitemap-main.xml' => ['body' => $partial, 'truncated' => true]]
        );

        $this->assertSame('ok', $result['facts']['sitemap']['state']);
        $this->assertTrue($result['facts']['sitemap']['truncated']);
        $this->assertSame(3, $result['facts']['sitemap']['urls_count']);
    }

    public function testNoindexAndNofollowOnHome(): void
    {
        $result = $this->check($this->context($this->fixture('page-noindex.html')));

        $noindex = $this->findingByTitle($result, 'Главная закрыта от индексации');
        $this->assertSame(SEO_SEVERITY['noindex_home'], $noindex['severity']);
        $this->assertSame('high', $noindex['severity']);
        $this->assertSame(self::HOME_PAGE_ID, $noindex['page_id']);
        $this->assertSame(SEO_SEVERITY['nofollow'], $this->findingByTitle($result, 'nofollow')['severity']);
    }

    public function testXRobotsTagNoindexOnHomeIsHigh(): void
    {
        $result = $this->check($this->context(
            $this->fixture('page-ok.html'),
            ['headers' => ['x-robots-tag' => ['noindex']]]
        ));

        $finding = $this->findingByTitle($result, 'Главная закрыта от индексации');
        $this->assertSame(SEO_SEVERITY['noindex_home'], $finding['severity']);
        $this->assertStringContainsString('X-Robots-Tag', (string) $finding['details']);
    }

    public function testNoindexOnProductIsLowerThanOnHome(): void
    {
        $result = $this->check($this->context(
            $this->fixture('page-ok.html'),
            [],
            ['product' => [$this->fixture('page-noindex.html')]]
        ));

        $finding = $this->findingByTitle($result, 'Страница закрыта от индексации');
        $this->assertSame(SEO_SEVERITY['noindex_page'], $finding['severity']);
        $this->assertSame(self::HOME_PAGE_ID + 1, $finding['page_id']);
        // nofollow на товаре не проверяется (SEO_PAGE_RULES)
        $this->assertSame([], array_filter($this->titles($result), static fn (string $t): bool => str_contains($t, 'nofollow')));
    }

    public function testCartAndCheckoutAreNotChecked(): void
    {
        $result = $this->check($this->context($this->fixture('page-ok.html'), [], [
            'cart'     => [$this->fixture('page-noindex.html')],
            'checkout' => [$this->fixture('page-no-title.html')],
        ]));

        $this->assertSame([], $result['findings']);
        $this->assertCount(3, $result['facts']['pages']);
    }

    public function testPagesWithErrorOrBotProtectionAreSkipped(): void
    {
        $result = $this->check($this->context($this->fixture('page-ok.html'), [], [
            'product'  => [$this->fixture('page-no-title.html'), ['status' => 404]],
            'category' => [$this->fixture('page-noindex.html'),
                ['bot_protection' => ['provider' => 'cloudflare', 'title' => 'Cloudflare', 'reason' => 'x']]],
        ]));

        $this->assertSame([], $result['findings']);
        $this->assertSame([['home']], array_column($result['facts']['pages'], 'types'));
    }

    public function testMissingAndDuplicateTitlesAndDescriptions(): void
    {
        $result = $this->check($this->context($this->fixture('page-ok.html'), [], [
            'category' => [$this->fixture('page-ok.html')],
            'product'  => [$this->fixture('page-no-title.html')],
        ]));

        $duplicate = $this->findingByTitle($result, 'Одинаковый title');
        $this->assertSame(SEO_SEVERITY['title_duplicate'], $duplicate['severity']);
        $this->assertSame([self::HOME, self::HOME . 'category/'], $duplicate['evidence']['pages']);
        $this->assertSame(self::HOME_PAGE_ID, $duplicate['page_id']);

        $missing = $this->findingByTitle($result, 'Нет заголовка');
        $this->assertSame(SEO_SEVERITY['title_missing'], $missing['severity']);
        $this->assertSame([self::HOME . 'product/'], $missing['evidence']['pages']);

        $description = $this->findingByTitle($result, 'Нет описания');
        $this->assertSame(SEO_SEVERITY['description_missing'], $description['severity']);
        $this->assertSame([self::HOME . 'product/'], $description['evidence']['pages']);
    }

    public function testForeignCanonicalIsMediumAndWwwVariantIsNot(): void
    {
        $result = $this->check($this->context(
            $this->fixture('page-ok.html'), // canonical на https://www.shop.test/ — тот же сайт
            [],
            ['product' => [$this->fixture('page-foreign-canonical.html')]]
        ));

        $this->assertCount(1, $result['findings']);
        $finding = $this->findingByTitle($result, 'canonical ведёт на другой сайт');
        $this->assertSame(SEO_SEVERITY['canonical_foreign'], $finding['severity']);
        $this->assertSame('https://old-dev-studio.test/catalog/divan-klassik/', $finding['evidence']['canonical']);
    }

    public function testWwwIsNotCheckedHere(): void
    {
        $result = $this->check($this->context(
            $this->fixture('page-ok.html'),
            ['url' => 'https://shop.test/', 'final_url' => 'https://www.shop.test/']
        ), [
            'https://www.shop.test/robots.txt'       => ['body' => $this->fixture('robots-ok.txt')],
        ]);

        $this->assertContains('https://www.shop.test/robots.txt', $this->requested);
        $this->assertSame([], array_filter($this->titles($result), static fn (string $t): bool => str_contains($t, 'www')));
    }

    public function testStepSummaryRowsAreBuiltFromFacts(): void
    {
        $result = $this->check($this->context($this->fixture('page-ok.html')));
        $rows = buildReportSeoRows(['robots' => $result['facts']['robots'], 'sitemap' => $result['facts']['sitemap']]);

        $this->assertSame(['robots.txt', 'sitemap.xml', 'Адресов в sitemap'], array_column($rows, 'label'));
        $this->assertSame('3', $rows[2]['value']);
    }
}
