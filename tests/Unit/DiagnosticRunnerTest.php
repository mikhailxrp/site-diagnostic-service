<?php

declare(strict_types=1);

namespace Tests\Unit;

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

    public function testCrawlRunsAfterStackAndBeforeResources(): void
    {
        $this->assertSame(['availability', 'stack', 'crawl', 'resources'], array_column(getDiagnosticSteps(), 'code'));
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
}
