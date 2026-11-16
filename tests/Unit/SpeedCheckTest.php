<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/src/Services/Checks/SpeedCheck.php';

/**
 * Шаг `pagespeed` без сети: пороги Core Web Vitals, выбор источника, находки `speed`, пропуск шага.
 */
final class SpeedCheckTest extends TestCase
{
    private const FIXTURES = ROOT_PATH . '/tests/fixtures/pagespeed';
    private const HOME_URL = 'https://sudar.su/';
    private const HOME_PAGE_ID = 5;
    private const KEY = 'AIzaTestKey_123';

    /** Google: FAST / AVERAGE / SLOW → наша оценка */
    private const GOOGLE_CATEGORIES = [
        'FAST'    => SPEED_RATING_GOOD,
        'AVERAGE' => SPEED_RATING_NEEDS,
        'SLOW'    => SPEED_RATING_POOR,
    ];

    /**
     * @return array<string, mixed>
     */
    private function context(array $home = []): array
    {
        return [
            'diagnostic' => ['id' => 1, 'url' => self::HOME_URL],
            'facts'      => ['availability' => ['home' => $home + [
                'final_url' => self::HOME_URL,
                'status'    => 200,
                'page_id'   => self::HOME_PAGE_ID,
            ]]],
        ];
    }

    /**
     * @param array<string, array{0: ?int, 1: string}> $byStrategy strategy → [status, body]
     */
    private function api(array $byStrategy): callable
    {
        return static function (string $url, array $headers) use ($byStrategy): array {
            $strategy = str_contains($url, 'strategy=mobile') ? 'mobile' : 'desktop';
            [$status, $body] = $byStrategy[$strategy];

            return ['status' => $status, 'body' => $body, 'error' => $status === null ? 'timeout (curl 28)' : null];
        };
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES . '/' . $name);
    }

    /**
     * @param array<string, mixed> $lab
     * @param array<string, mixed>|null $field
     * @return array<string, mixed>
     */
    private function strategyResult(int $score, array $lab, ?array $field = null): array
    {
        return [
            'score'       => $score,
            'lab'         => $lab + ['lcp' => 1000, 'cls' => 0.01, 'tbt' => 50, 'fcp' => 800],
            'field'       => $field,
            'field_scope' => $field !== null ? 'url' : null,
            'warnings'    => [],
        ];
    }

    /**
     * @return iterable<string, array{string, int|float, string}>
     */
    public static function thresholdCases(): iterable
    {
        yield 'LCP ровно 2,5 с — хорошо' => ['lcp', 2500, SPEED_RATING_GOOD];
        yield 'LCP 2501 мс' => ['lcp', 2501, SPEED_RATING_NEEDS];
        yield 'LCP ровно 4 с' => ['lcp', 4000, SPEED_RATING_NEEDS];
        yield 'LCP 4001 мс' => ['lcp', 4001, SPEED_RATING_POOR];
        yield 'CLS ровно 0,1' => ['cls', 0.1, SPEED_RATING_GOOD];
        yield 'CLS 0,101' => ['cls', 0.101, SPEED_RATING_NEEDS];
        yield 'CLS ровно 0,25' => ['cls', 0.25, SPEED_RATING_NEEDS];
        yield 'CLS 0,251' => ['cls', 0.251, SPEED_RATING_POOR];
        yield 'INP 200' => ['inp', 200, SPEED_RATING_GOOD];
        yield 'INP 500' => ['inp', 500, SPEED_RATING_NEEDS];
        yield 'INP 501' => ['inp', 501, SPEED_RATING_POOR];
        yield 'TBT 200' => ['tbt', 200, SPEED_RATING_GOOD];
        yield 'TBT 600' => ['tbt', 600, SPEED_RATING_NEEDS];
        yield 'TBT 601' => ['tbt', 601, SPEED_RATING_POOR];
        yield 'FCP 1800' => ['fcp', 1800, SPEED_RATING_GOOD];
        yield 'FCP 3001' => ['fcp', 3001, SPEED_RATING_POOR];
    }

    #[DataProvider('thresholdCases')]
    public function testThresholdsAreInclusive(string $metric, int|float $value, string $expected): void
    {
        $this->assertSame($expected, rateSpeedMetric($metric, $value));
    }

    public function testScoreScale(): void
    {
        $this->assertSame(SPEED_RATING_GOOD, rateSpeedScore(90));
        $this->assertSame(SPEED_RATING_NEEDS, rateSpeedScore(89));
        $this->assertSame(SPEED_RATING_NEEDS, rateSpeedScore(50));
        $this->assertSame(SPEED_RATING_POOR, rateSpeedScore(49));
    }

    public function testThresholdsMatchGoogleCategoriesInRealResponses(): void
    {
        $checked = 0;
        foreach (['sudar-mobile.json', 'sudar-desktop.json'] as $name) {
            $json = json_decode($this->fixture($name), true);
            foreach (['loadingExperience', 'originLoadingExperience'] as $experience) {
                foreach (PAGESPEED_FIELD_METRICS as $metric => $key) {
                    $data = $json[$experience]['metrics'][$key];
                    $value = $metric === 'cls' ? $data['percentile'] / PAGESPEED_CLS_FIELD_DIVISOR : $data['percentile'];
                    $this->assertSame(
                        self::GOOGLE_CATEGORIES[$data['category']],
                        rateSpeedMetric($metric, $value),
                        "{$name} {$experience} {$key} = {$data['percentile']}"
                    );
                    $checked++;
                }
            }
        }
        $this->assertSame(16, $checked);
    }

    /**
     * @return iterable<string, array{string, int|float, string}>
     */
    public static function formatCases(): iterable
    {
        yield 'секунды' => ['lcp', 2765, '2,8 с'];
        yield 'миллисекунды' => ['inp', 117, '117 мс'];
        yield 'ровно секунда' => ['tbt', 1000, '1,0 с'];
        yield 'CLS три знака' => ['cls', 0.102, '0,102'];
        yield 'CLS без лишних нулей' => ['cls', 0.1, '0,1'];
        yield 'CLS ноль' => ['cls', 0.0, '0'];
    }

    #[DataProvider('formatCases')]
    public function testFormatValue(string $metric, int|float $value, string $expected): void
    {
        $this->assertSame($expected, formatSpeedValue($metric, $value));
    }

    public function testRealSudarResponsesGiveFindingsByFieldDataFirst(): void
    {
        $step = runSpeedCheck($this->context(), self::KEY, $this->api([
            'mobile'  => [200, $this->fixture('sudar-mobile.json')],
            'desktop' => [200, $this->fixture('sudar-desktop.json')],
        ]));

        $this->assertSame('done', $step['status']);
        $this->assertSame('Mobile: 39, Desktop: 55; находок: 3', $step['message']);

        $findings = array_column($step['findings'], null, 'title');
        $this->assertSame([
            'Медленно появляется основное содержимое (LCP)',
            'Содержимое сдвигается при загрузке (CLS)',
            'Низкая оценка скорости PageSpeed',
        ], array_keys($findings));

        // LCP: у пользователей 2,8 с (требует улучшения), хотя в лаборатории 14,3 с — решают пользователи
        $lcp = $findings['Медленно появляется основное содержимое (LCP)'];
        $this->assertSame('low', $lcp['severity']);
        $this->assertSame('speed', $lcp['category']);
        $this->assertSame(self::HOME_URL, $lcp['location']);
        $this->assertSame(self::HOME_PAGE_ID, $lcp['page_id']);
        $this->assertSame(
            'Mobile: 2,8 с — требует улучшения (реальные пользователи); Desktop: 1,2 с — хорошо (реальные пользователи).',
            $lcp['details']
        );
        $this->assertSame(SPEED_SOURCE_FIELD, $lcp['evidence']['strategies']['mobile']['source']);

        $score = $findings['Низкая оценка скорости PageSpeed'];
        $this->assertSame('medium', $score['severity']);
        $this->assertSame('Mobile: 39 из 100; Desktop: 55 из 100.', $score['details']);
        $this->assertSame(['mobile' => 39, 'desktop' => 55], $score['evidence']['scores']);

        // INP у пользователей хороший — находки про отклик нет, хотя TBT в лаборатории плохой
        $this->assertArrayNotHasKey('Медленный отклик на действия (INP)', $findings);
    }

    public function testFactsKeepParsedResultsForSummary(): void
    {
        $step = runSpeedCheck($this->context(), self::KEY, $this->api([
            'mobile'  => [200, $this->fixture('sudar-mobile.json')],
            'desktop' => [400, $this->fixture('error-invalid-key.json')],
        ]));

        $pagespeed = $step['facts']['pagespeed'];
        $this->assertSame(self::HOME_URL, $pagespeed['url']);
        $this->assertSame(39, $pagespeed['mobile']['score']);
        $this->assertNull($pagespeed['desktop']);
        $this->assertSame(['desktop' => 'HTTP 400: API key not valid. Please pass a valid API key.'], $pagespeed['errors']);
    }

    public function testWithoutFieldDataLabDecidesAndTbtReplacesInp(): void
    {
        $findings = buildSpeedFindings(
            ['mobile' => $this->strategyResult(80, ['tbt' => 700]), 'desktop' => $this->strategyResult(95, ['tbt' => 300])],
            self::HOME_URL,
            self::HOME_PAGE_ID
        );

        $this->assertCount(1, $findings);
        $this->assertSame('Медленный отклик на действия (TBT)', $findings[0]['title']);
        $this->assertSame('medium', $findings[0]['severity']); // худшая — mobile «плохо»
        $this->assertSame(SPEED_SOURCE_LAB, $findings[0]['evidence']['strategies']['desktop']['source']);
        $this->assertSame(SPEED_RATING_NEEDS, $findings[0]['evidence']['strategies']['desktop']['rating']);
    }

    public function testMixedSourcesShowBothMetricsInTitle(): void
    {
        $findings = buildSpeedFindings(
            [
                'mobile'  => $this->strategyResult(80, [], ['lcp' => 1000, 'inp' => 300, 'cls' => 0.01, 'fcp' => 900]),
                'desktop' => $this->strategyResult(80, ['tbt' => 250]),
            ],
            self::HOME_URL,
            null
        );

        $this->assertSame(['Медленный отклик на действия (INP / TBT)'], array_column($findings, 'title'));
        $this->assertSame('low', $findings[0]['severity']);
    }

    public function testOneFindingPerIndicatorWithStableFingerprint(): void
    {
        $results = ['mobile' => $this->strategyResult(30, ['lcp' => 5000]), 'desktop' => $this->strategyResult(40, ['lcp' => 4500])];

        $first = buildSpeedFindings($results, self::HOME_URL, 1);
        $second = buildSpeedFindings($results, self::HOME_URL . '#top', 2);

        $this->assertSame(['Медленно появляется основное содержимое (LCP)', 'Низкая оценка скорости PageSpeed'], array_column($first, 'title'));
        $this->assertSame(array_column($first, 'fingerprint'), array_column($second, 'fingerprint'));
        $this->assertNotSame($first[0]['fingerprint'], $first[1]['fingerprint']);
    }

    public function testGoodMetricsGiveNoFindings(): void
    {
        $this->assertSame([], buildSpeedFindings(
            ['mobile' => $this->strategyResult(92, []), 'desktop' => $this->strategyResult(99, [])],
            self::HOME_URL,
            1
        ));
    }

    public function testOneFailedStrategyKeepsStepDone(): void
    {
        $step = runSpeedCheck($this->context(), self::KEY, $this->api([
            'mobile'  => [null, ''],
            'desktop' => [200, $this->fixture('sudar-desktop.json')],
        ]));

        $this->assertSame('done', $step['status']);
        $this->assertSame('Mobile: ошибка — timeout (curl 28), Desktop: 55; находок: 1', $step['message']);
        $this->assertSame(['Содержимое сдвигается при загрузке (CLS)'], array_column($step['findings'], 'title'));
    }

    public function testBothStrategiesFailedSkipsStep(): void
    {
        $step = runSpeedCheck($this->context(), self::KEY, $this->api([
            'mobile'  => [429, $this->fixture('synthetic-429.json')],
            'desktop' => [400, $this->fixture('error-failed-document.json')],
        ]));

        $this->assertSame('skipped', $step['status']);
        $this->assertStringStartsWith(
            'PageSpeed не проверил главную: Mobile — Превышен лимит запросов PageSpeed (429); Desktop — HTTP 400: Lighthouse returned error',
            $step['message']
        );
        $this->assertArrayNotHasKey('facts', $step);
    }

    public function testNoKeySkipsWithoutRequests(): void
    {
        $api = function (): array {
            $this->fail('Без ключа запросов быть не должно');
        };

        $this->assertSame(['status' => 'skipped', 'message' => SPEED_NO_KEY_MESSAGE], runSpeedCheck($this->context(), '  ', $api));
        $this->assertSame(['status' => 'skipped', 'message' => SPEED_BAD_KEY_MESSAGE], runSpeedCheck($this->context(), "a\nb", $api));
    }

    public function testHomeWithoutResponseSkips(): void
    {
        $api = function (): array {
            $this->fail('Главная не ответила — запросов быть не должно');
        };

        $step = runSpeedCheck($this->context(['status' => null]), self::KEY, $api);

        $this->assertSame(['status' => 'skipped', 'message' => SPEED_NO_HOME_MESSAGE], $step);
    }

    public function testResultWithoutScoreInMessage(): void
    {
        $result = $this->strategyResult(50, []);
        $result['score'] = null;

        $this->assertSame('Mobile: без оценки; находок: 0', buildSpeedStepMessage(['mobile' => $result], [], 0));
    }
}
