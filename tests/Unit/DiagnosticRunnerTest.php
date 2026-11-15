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

    /** @var list<array{code: string, status: string, message: ?string, progress: int, hasError: bool}> */
    private array $finished = [];

    private function step(string $code, callable $handler): array
    {
        return ['code' => $code, 'title' => $code, 'handler' => $handler];
    }

    private function done(): callable
    {
        return static fn (): array => ['status' => 'done', 'message' => null];
    }

    private function runSteps(array $steps): void
    {
        executeSteps(
            $steps,
            function (array $step): void {
                $this->events[] = 'start:' . $step['code'];
            },
            function (array $step, string $status, ?string $message, int $progress, ?\Throwable $error): void {
                $this->events[] = 'finish:' . $step['code'];
                $this->finished[] = [
                    'code'     => $step['code'],
                    'status'   => $status,
                    'message'  => $message,
                    'progress' => $progress,
                    'hasError' => $error !== null,
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
        $this->assertFalse($this->finished[2]['hasError']);
        $this->assertSame(100, $this->finished[2]['progress']);
    }

    public function testExceptionWithoutMessageUsesClassName(): void
    {
        $this->runSteps([$this->step('a', static fn () => throw new RuntimeException())]);

        $this->assertSame('RuntimeException', $this->finished[0]['message']);
    }

    public function testSkippedStep(): void
    {
        $this->runSteps([
            $this->step('skip', static fn (): array => ['status' => 'skipped', 'message' => 'Нет ключа PageSpeed']),
        ]);

        $this->assertSame('skipped', $this->finished[0]['status']);
        $this->assertSame('Нет ключа PageSpeed', $this->finished[0]['message']);
    }

    public function testUnknownResultStatusCountsAsDone(): void
    {
        $this->runSteps([$this->step('a', static fn (): array => ['status' => 'whatever'])]);

        $this->assertSame('done', $this->finished[0]['status']);
        $this->assertNull($this->finished[0]['message']);
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
        foreach ($steps as $step) {
            $this->assertIsCallable($step['handler']);
            $this->assertNotSame('', $step['title']);
        }
    }
}
