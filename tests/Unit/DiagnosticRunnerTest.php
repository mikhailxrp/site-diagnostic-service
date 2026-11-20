<?php

declare(strict_types=1);

namespace Tests\Unit;

use DiagnosticNoLongerRunningException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once ROOT_PATH . '/src/Services/DiagnosticRunner.php';

final class DiagnosticRunnerTest extends TestCase
{
    /** @var list<string> */
    private array $events = [];

    /** @var list<array{code: string, status: string, message: ?string, progress: int, hasError: bool, findings: list<array>}> */
    private array $finished = [];

    private function step(string $code, callable $handler): array
    {
        return ['code' => $code, 'title' => 'Шаг ' . $code, 'handler' => $handler];
    }

    private function done(): callable
    {
        return static fn (array $context): array => ['status' => 'done', 'message' => null];
    }

    private function context(): array
    {
        return buildStepContext([
            'id'           => '7',
            'url'          => 'https://sudar.su/',
            'host'         => 'sudar.su',
            'problem_text' => null,
        ]);
    }

    private function runSteps(array $steps): array
    {
        return executeSteps(
            $steps,
            $this->context(),
            function (array $step): void {
                $this->events[] = 'start:' . $step['code'];
            },
            function (array $step, string $status, ?string $message, int $progress, ?\Throwable $error, array $findings): void {
                $this->events[] = 'finish:' . $step['code'];
                $this->finished[] = [
                    'code'     => $step['code'],
                    'status'   => $status,
                    'message'  => $message,
                    'progress' => $progress,
                    'hasError' => $error !== null,
                    'findings' => $findings,
                ];
            }
        );
    }

    public static function progressCases(): array
    {
        return [
            'ничего не сделано'   => [0, 4, 0],
            'один из четырёх'     => [1, 4, 25],
            'два из трёх'         => [2, 3, 66],
            'почти всё — не 100'  => [199, 200, 99],
            'всё сделано'         => [4, 4, 100],
            'больше, чем шагов'   => [5, 4, 100],
            'отрицательное'       => [-1, 4, 0],
            'нет шагов'           => [0, 0, 100],
        ];
    }

    public function testAiStepsAreOnlyAiStepsInOrder(): void
    {
        $this->assertSame(
            ['ai_problem', 'ai_visual', 'ai_summary', 'ai_client'],
            array_column(getAiSteps(), 'code')
        );
    }

    public function testAiRerunDoesNotRunSiteSteps(): void
    {
        $siteHandlers = [
            'runAvailabilityStep', 'runStackStep', 'runOutdatedStep', 'runVulnerabilitiesStep', 'runCrawlStep',
            'runResourcesStep', 'runSeoStep', 'runBrowserStep', 'runPageSpeedStep',
        ];

        $this->assertSame(
            ['runAiProblemStep', 'runAiVisualStep', 'runAiSummaryStep', 'runAiClientStep'],
            array_column(getAiSteps(), 'handler')
        );
        $this->assertSame([], array_intersect($siteHandlers, array_column(getAiSteps(), 'handler')));
    }

    public function testAiStepsMatchFullListEntries(): void
    {
        $full = array_column(getDiagnosticSteps(), null, 'code');

        foreach (getAiSteps() as $step) {
            $this->assertSame($full[$step['code']], $step);
        }
    }

    public function testAiClientWithoutKeyIsSkippedBeforeDatabaseOrNetwork(): void
    {
        $previousEnv = $_ENV['ROUTERAI_API_KEY'] ?? null;
        $previousProcess = getenv('ROUTERAI_API_KEY');
        unset($_ENV['ROUTERAI_API_KEY']);
        putenv('ROUTERAI_API_KEY');

        try {
            $result = runAiClientStep(['diagnostic' => ['id' => 7]]);
        } finally {
            if ($previousEnv !== null) {
                $_ENV['ROUTERAI_API_KEY'] = $previousEnv;
            }
            if ($previousProcess !== false) {
                putenv('ROUTERAI_API_KEY=' . $previousProcess);
            }
        }

        $this->assertSame(STEP_STATUS_SKIPPED, $result['status']);
        $this->assertSame(AI_ERROR_NO_KEY, $result['message']);
    }

    #[DataProvider('progressCases')]
    public function testCalculateProgress(int $finished, int $total, int $expected): void
    {
        $this->assertSame($expected, calculateProgress($finished, $total));
    }

    public function testBuildStepContext(): void
    {
        $context = $this->context();

        $this->assertSame(
            ['id' => 7, 'url' => 'https://sudar.su/', 'host' => 'sudar.su', 'product_url' => null, 'problem_text' => null],
            $context['diagnostic']
        );
        $this->assertSame([], $context['facts']);
    }

    public function testStepsRunInOrder(): void
    {
        $this->runSteps([
            $this->step('a', $this->done()),
            $this->step('b', $this->done()),
            $this->step('c', $this->done()),
        ]);

        $this->assertSame(
            ['start:a', 'finish:a', 'start:b', 'finish:b', 'start:c', 'finish:c'],
            $this->events
        );
        $this->assertSame([33, 66, 100], array_column($this->finished, 'progress'));
    }

    public function testHandlerReceivesDiagnosticInContext(): void
    {
        $seenHost = null;
        $this->runSteps([
            $this->step('a', static function (array $context) use (&$seenHost): array {
                $seenHost = $context['diagnostic']['host'];

                return ['status' => 'done'];
            }),
        ]);

        $this->assertSame('sudar.su', $seenHost);
    }

    public function testFactsAreVisibleToNextStep(): void
    {
        $seenFacts = null;
        $context = $this->runSteps([
            $this->step('availability', static fn (array $context): array => [
                'status' => 'done',
                'facts'  => ['http_code' => 200],
            ]),
            $this->step('stack', static function (array $context) use (&$seenFacts): array {
                $seenFacts = $context['facts'];

                return ['status' => 'done'];
            }),
        ]);

        $this->assertSame(['availability' => ['http_code' => 200]], $seenFacts);
        $this->assertSame(['http_code' => 200], $context['facts']['availability']);
        $this->assertSame([], $context['facts']['stack']);
    }

    public function testStopSkipsRemainingStepsWithReason(): void
    {
        $this->runSteps([
            $this->step('availability', static fn (array $context): array => [
                'status'  => 'done',
                'message' => 'Сайт не отвечает',
                'stop'    => true,
            ]),
            $this->step('stack', static fn () => throw new RuntimeException('не должен выполняться')),
            $this->step('resources', static fn () => throw new RuntimeException('не должен выполняться')),
        ]);

        $this->assertSame(
            ['start:availability', 'finish:availability', 'finish:stack', 'finish:resources'],
            $this->events
        );
        $this->assertSame(['done', 'skipped', 'skipped'], array_column($this->finished, 'status'));
        $this->assertSame('Сайт не отвечает', $this->finished[0]['message']);
        $this->assertSame('Пропущено: шаг «Шаг availability» остановил проверку.', $this->finished[1]['message']);
        $this->assertFalse($this->finished[1]['hasError']);
        $this->assertSame([33, 66, 100], array_column($this->finished, 'progress'));
    }

    public function testStopMustBeTrueBoolean(): void
    {
        $this->runSteps([
            $this->step('a', static fn (array $context): array => ['status' => 'done', 'stop' => 'yes']),
            $this->step('b', $this->done()),
        ]);

        $this->assertSame(['done', 'done'], array_column($this->finished, 'status'));
    }

    public function testFindingsArePassedForSaving(): void
    {
        $finding = ['category' => 'ssl', 'title' => 'Сертификат истёк'];
        $this->runSteps([
            $this->step('a', static fn (array $context): array => ['status' => 'done', 'findings' => [$finding]]),
            $this->step('b', $this->done()),
        ]);

        $this->assertSame([$finding], $this->finished[0]['findings']);
        $this->assertSame([], $this->finished[1]['findings']);
    }

    public function testFailedStepDoesNotStopOthers(): void
    {
        $this->runSteps([
            $this->step('a', $this->done()),
            $this->step('broken', static fn () => throw new RuntimeException('Сайт не ответил')),
            $this->step('c', $this->done()),
        ]);

        $this->assertSame(['done', 'failed', 'done'], array_column($this->finished, 'status'));
        $this->assertSame('Сайт не ответил', $this->finished[1]['message']);
        $this->assertTrue($this->finished[1]['hasError']);
        $this->assertSame([], $this->finished[1]['findings']);
        $this->assertFalse($this->finished[2]['hasError']);
        $this->assertSame(100, $this->finished[2]['progress']);
    }

    public function testFailedStepFactsAreNotAccepted(): void
    {
        $context = $this->runSteps([
            $this->step('broken', static fn () => throw new RuntimeException('Сбой')),
        ]);

        $this->assertArrayNotHasKey('broken', $context['facts']);
    }

    public function testExceptionWithoutMessageUsesClassName(): void
    {
        $this->runSteps([$this->step('a', static fn () => throw new RuntimeException())]);

        $this->assertSame('RuntimeException', $this->finished[0]['message']);
    }

    public function testSkippedStep(): void
    {
        $this->runSteps([
            $this->step('skip', static fn (array $context): array => ['status' => 'skipped', 'message' => 'Нет ключа PageSpeed']),
        ]);

        $this->assertSame('skipped', $this->finished[0]['status']);
        $this->assertSame('Нет ключа PageSpeed', $this->finished[0]['message']);
    }

    public function testUnknownResultStatusCountsAsDone(): void
    {
        $this->runSteps([$this->step('a', static fn (array $context): array => ['status' => 'whatever'])]);

        $this->assertSame('done', $this->finished[0]['status']);
        $this->assertNull($this->finished[0]['message']);
    }

    public function testNonArrayResultCountsAsDone(): void
    {
        $this->runSteps([$this->step('a', static fn (array $context) => null)]);

        $this->assertSame('done', $this->finished[0]['status']);
        $this->assertSame([], $this->finished[0]['findings']);
    }

    public function testLongMessageIsTruncated(): void
    {
        $longMessage = str_repeat('ж', STEP_MESSAGE_MAX_LENGTH + 50);
        $this->runSteps([$this->step('a', static fn () => throw new RuntimeException($longMessage))]);

        $this->assertSame(STEP_MESSAGE_MAX_LENGTH, mb_strlen($this->finished[0]['message']));
    }

    public function testDefaultStepsHaveUniqueCodesAndHandlers(): void
    {
        $steps = getDiagnosticSteps();
        $codes = array_column($steps, 'code');

        $this->assertNotEmpty($steps);
        $this->assertSame($codes, array_unique($codes));
        $this->assertNotContains('report', $codes);
        foreach ($steps as $step) {
            $this->assertIsCallable($step['handler']);
            $this->assertNotSame('', $step['title']);
        }
    }

    public function testStepsOrder(): void
    {
        $this->assertSame(
            ['availability', 'stack', 'outdated', 'vulnerabilities', 'crawl', 'resources', 'seo', 'exposed_files', 'browser',
                'checkout', 'pagespeed', 'ai_problem', 'ai_visual', 'ai_summary', 'ai_client'],
            array_column(getDiagnosticSteps(), 'code')
        );
    }

    public function testContextCarriesProductUrl(): void
    {
        $this->assertNull($this->context()['diagnostic']['product_url']);

        $context = buildStepContext([
            'id'          => '8',
            'url'         => 'https://sudar.su/',
            'host'        => 'sudar.su',
            'product_url' => 'https://sudar.su/catalog/item/',
        ]);
        $this->assertSame('https://sudar.su/catalog/item/', $context['diagnostic']['product_url']);
    }

    public function testBlockedPagesSummaryKeepsOnlyProtectedPages(): void
    {
        $summary = buildBlockedPagesSummary([
            12 => ['provider' => 'cloudflare', 'title' => 'Cloudflare', 'reason' => 'заголовок cf-mitigated: challenge'],
            13 => null,
        ]);

        $this->assertSame(
            '{"12":{"title":"Cloudflare","reason":"заголовок cf-mitigated: challenge"}}',
            json_encode($summary, JSON_UNESCAPED_UNICODE)
        );
        $this->assertSame('{}', json_encode(buildBlockedPagesSummary([5 => null])));
    }

    public function testDifferentFindingsAreNotMergedAcrossPages(): void
    {
        $first = makeFinding('mixed_content', 'A', 'http://shop.test/a.js', 'http', null, ['type' => 'script'], 1);
        $second = makeFinding('mixed_content', 'B', 'http://shop.test/b.js', 'http', null, ['type' => 'script'], 2);

        $merged = mergePageFindings([
            ['finding' => $first, 'page_url' => 'https://shop.test/'],
            ['finding' => $second, 'page_url' => 'https://shop.test/c/'],
            ['finding' => $first, 'page_url' => 'https://shop.test/c/'],
        ]);

        $this->assertSame(['A', 'B'], array_column($merged, 'title'));
        $this->assertSame(['https://shop.test/', 'https://shop.test/c/'], $merged[0]['evidence']['pages']);
        $this->assertSame('script', $merged[0]['evidence']['type']);
        $this->assertSame(1, $merged[0]['page_id']);
    }

    public function testFindingSaveFailureMarksStepFailedAndContinuesLoop(): void
    {
        $savedFindings = [];
        $persistedSteps = [];
        $progressUpdates = [];
        $logged = [];

        $badFinding = makeFinding('vulnerability', 'Плохая находка', null, 'bad');
        $okFinding1 = makeFinding('vulnerability', 'Вторая находка', null, 'ok1');
        $okFinding2 = makeFinding('seo', 'SEO находка', null, 'ok2');

        $saveFinding = static function (array $finding) use (&$savedFindings): void {
            if ($finding['title'] === 'Плохая находка') {
                throw new RuntimeException('SQLSTATE[22001]: Data too long');
            }
            $savedFindings[] = $finding['title'];
        };

        $steps = [
            $this->step('vulnerabilities', static fn (array $context): array => [
                'status'   => STEP_STATUS_DONE,
                'message'  => 'Найдено 2',
                'findings' => [$badFinding, $okFinding1],
            ]),
            $this->step('seo', static fn (array $context): array => [
                'status'   => STEP_STATUS_DONE,
                'message'  => 'Готово',
                'findings' => [$okFinding2],
            ]),
        ];

        executeSteps(
            $steps,
            $this->context(),
            static function (array $step): void {},
            static function (
                array $step,
                string $status,
                ?string $message,
                int $progress,
                ?\Throwable $error,
                array $findings
            ) use ($saveFinding, &$persistedSteps, &$progressUpdates, &$logged): void {
                finalizeStepPersistence(
                    7,
                    $step,
                    $status,
                    $message,
                    $progress,
                    $error,
                    $findings,
                    $saveFinding,
                    static function (string $stepCode, string $stepStatus, ?string $stepMessage) use (&$persistedSteps): void {
                        $persistedSteps[] = [
                            'code'    => $stepCode,
                            'status'  => $stepStatus,
                            'message' => $stepMessage,
                        ];
                    },
                    static function (int $stepProgress) use (&$progressUpdates): void {
                        $progressUpdates[] = $stepProgress;
                    },
                    static function (\Throwable $e, array $context) use (&$logged): void {
                        $logged[] = ['message' => $e->getMessage(), 'context' => $context];
                    }
                );
            }
        );

        $this->assertCount(1, $logged);
        $this->assertSame('SQLSTATE[22001]: Data too long', $logged[0]['message']);
        $this->assertSame(['diagnostic_id' => 7, 'step_code' => 'vulnerabilities'], $logged[0]['context']);

        $this->assertSame(['Вторая находка', 'SEO находка'], $savedFindings);
        $this->assertSame([
            [
                'code'    => 'vulnerabilities',
                'status'  => STEP_STATUS_FAILED,
                'message' => 'Не удалось сохранить находки: SQLSTATE[22001]: Data too long',
            ],
            [
                'code'    => 'seo',
                'status'  => STEP_STATUS_DONE,
                'message' => 'Готово',
            ],
        ], $persistedSteps);
        $this->assertSame([50, 100], $progressUpdates);
    }

    public function testWithHeartbeatThrottlesByClockAndPingsAfterSlowRequest(): void
    {
        $now = 0;
        $touches = [];
        $requests = [];

        $wrapped = withHeartbeat(
            static function (string $url, int $duration) use (&$now, &$requests): string {
                $requests[] = $url;
                $now += $duration;

                return 'ok:' . $url;
            },
            7,
            static function (int $id) use (&$touches, &$now): bool {
                $touches[] = [$id, $now];

                return true;
            },
            static function () use (&$now): int {
                return $now;
            },
            30
        );

        // t=0: первый пинг до запроса, запрос быстрый (0с), после него < 30с — второй пинг не идёт
        $this->assertSame('ok:u1', $wrapped('u1', 0));
        // t=10 (<30): пинг пропущен до и после
        $now = 10;
        $this->assertSame('ok:u2', $wrapped('u2', 19)); // t=29 (<30)
        // t=30 (>=30): пинг до запроса; запрос быстрый (5с -> t=35 <60)
        $now = 30;
        $this->assertSame('ok:u3', $wrapped('u3', 5));
        // t=40 (<60): до запроса пинг пропущен, но запрос шёл 35с -> t=75 (>=60), пинг срабатывает после запроса
        $now = 40;
        $this->assertSame('ok:u4', $wrapped('u4', 35));

        $this->assertSame(['u1', 'u2', 'u3', 'u4'], $requests);
        $this->assertSame([[7, 0], [7, 30], [7, 75]], $touches);
    }

    public function testWithHeartbeatAbortsWhenDiagnosticStopsRunning(): void
    {
        $touchCalls = 0;
        $requestCalls = 0;

        $wrapped = withHeartbeat(
            static function () use (&$requestCalls): string {
                $requestCalls++;

                return 'ok';
            },
            15,
            static function () use (&$touchCalls): bool {
                $touchCalls++;

                return false;
            },
            static fn (): int => 100
        );

        $caught = false;
        try {
            $wrapped();
        } catch (DiagnosticNoLongerRunningException $e) {
            $caught = true;
            $this->assertSame(15, $e->diagnosticId);
        }
        $this->assertTrue($caught);
        $this->assertSame(1, $touchCalls);
        $this->assertSame(0, $requestCalls);

        // Повторный вызов уже помеченной обёртки сразу бросает исключение без лишних запросов к БД/сети
        try {
            $wrapped();
            $this->fail('Ожидалось DiagnosticNoLongerRunningException');
        } catch (DiagnosticNoLongerRunningException $e) {
            $this->assertSame(15, $e->diagnosticId);
        }
        $this->assertSame(1, $touchCalls);
        $this->assertSame(0, $requestCalls);
    }

    public function testExecuteStepsPropagatesNoLongerRunningWithoutRunningSubsequentSteps(): void
    {
        $events = [];
        $nextCalled = false;

        $steps = [
            $this->step('first', static function () use (&$events): array {
                $events[] = 'handler:first';
                throw new DiagnosticNoLongerRunningException(7);
            }),
            $this->step('second', static function () use (&$nextCalled): array {
                $nextCalled = true;

                return ['status' => STEP_STATUS_DONE];
            }),
        ];

        $this->expectException(DiagnosticNoLongerRunningException::class);
        try {
            executeSteps(
                $steps,
                $this->context(),
                static function (array $step) use (&$events): void {
                    $events[] = 'start:' . $step['code'];
                },
                static function (array $step) use (&$events): void {
                    $events[] = 'finish:' . $step['code'];
                }
            );
        } finally {
            $this->assertSame(['start:first', 'handler:first'], $events);
            $this->assertFalse($nextCalled);
        }
    }

    public function testFinalizeStepPersistencePropagatesNoLongerRunningWithoutUpdatingStep(): void
    {
        $finishCalled = false;
        $progressCalled = false;

        $this->expectException(DiagnosticNoLongerRunningException::class);
        try {
            finalizeStepPersistence(
                7,
                ['code' => 'seo', 'title' => 'SEO'],
                STEP_STATUS_DONE,
                'OK',
                50,
                null,
                [makeFinding('seo', 'Нет title', null, 'title_missing')],
                static function (): void {
                    throw new DiagnosticNoLongerRunningException(7);
                },
                static function () use (&$finishCalled): void {
                    $finishCalled = true;
                },
                static function () use (&$progressCalled): void {
                    $progressCalled = true;
                },
                static function (): void {}
            );
        } finally {
            $this->assertFalse($finishCalled);
            $this->assertFalse($progressCalled);
        }
    }

    public function testWorkerFatalErrorDetectionAndMessages(): void
    {
        $this->assertFalse(isFatalWorkerError(null));
        $this->assertFalse(isFatalWorkerError(['type' => E_WARNING, 'message' => 'warn']));
        $this->assertTrue(isFatalWorkerError(['type' => E_ERROR, 'message' => 'Allowed memory size of 134217728 bytes exhausted']));
        $this->assertTrue(isFatalWorkerError(['type' => E_CORE_ERROR, 'message' => 'Core error']));
        $this->assertTrue(isFatalWorkerError(['type' => E_COMPILE_ERROR, 'message' => 'Compile error']));
        $this->assertTrue(isFatalWorkerError(['type' => E_PARSE, 'message' => 'Parse error']));

        $oom = buildWorkerFatalMessages(['type' => E_ERROR, 'message' => 'Allowed memory size of 134217728 bytes exhausted']);
        $this->assertSame(WORKER_FATAL_OOM_DIAGNOSTIC_MESSAGE, $oom['diagnostic']);
        $this->assertSame(WORKER_FATAL_OOM_STEP_MESSAGE, $oom['step']);

        $other = buildWorkerFatalMessages(['type' => E_ERROR, 'message' => 'Call to undefined function foo()']);
        $this->assertSame(WORKER_FATAL_DIAGNOSTIC_MESSAGE, $other['diagnostic']);
        $this->assertSame(WORKER_FATAL_STEP_MESSAGE, $other['step']);
    }

    public function testHandleWorkerFatalErrorUpdatesDiagnosticAndSurvivesDbFailures(): void
    {
        $logged = [];
        $captured = null;

        $ok = handleWorkerFatalError(
            42,
            ['type' => E_ERROR, 'message' => 'Allowed memory size of 100 bytes exhausted', 'file' => 'worker.php', 'line' => 10],
            static function (int $id, string $diagMsg, string $stepMsg) use (&$captured): bool {
                $captured = ['id' => $id, 'diag' => $diagMsg, 'step' => $stepMsg];

                return true;
            },
            static function (string $msg, array $ctx) use (&$logged): void {
                $logged[] = ['msg' => $msg, 'ctx' => $ctx];
            }
        );

        $this->assertTrue($ok);
        $this->assertSame([
            'id'   => 42,
            'diag' => WORKER_FATAL_OOM_DIAGNOSTIC_MESSAGE,
            'step' => WORKER_FATAL_OOM_STEP_MESSAGE,
        ], $captured);
        $this->assertCount(1, $logged);
        $this->assertSame('Фатальная ошибка воркера', $logged[0]['msg']);

        // При исключении при обновлении БД shutdown-обработчик не бросает исключений наружу
        $logged = [];
        $failed = handleWorkerFatalError(
            42,
            ['type' => E_ERROR, 'message' => 'Fatal error', 'file' => 'x.php', 'line' => 1],
            static function (): bool {
                throw new RuntimeException('MySQL server has gone away');
            },
            static function (string $msg, array $ctx) use (&$logged): void {
                $logged[] = ['msg' => $msg, 'ctx' => $ctx];
            }
        );

        $this->assertFalse($failed);
        $this->assertCount(2, $logged);
        $this->assertStringContainsString('MySQL server has gone away', $logged[1]['ctx']['error']);
    }
}
