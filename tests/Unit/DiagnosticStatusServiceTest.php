<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/src/Services/DiagnosticStatusService.php';

final class DiagnosticStatusServiceTest extends TestCase
{
    private const PLANNED_STEPS = [
        ['code' => 'availability', 'title' => 'Доступность сайта'],
        ['code' => 'stack', 'title' => 'Определение стека'],
    ];

    /**
     * @return array<string, array{string}>
     */
    public static function invalidIdProvider(): array
    {
        return [
            'пусто'           => [''],
            'ноль'            => ['0'],
            'буквы'           => ['abc'],
            'минус'           => ['-5'],
            'цифры с буквой'  => ['1a'],
            'ведущий ноль'    => ['01'],
            'пробел'          => [' 5'],
            'одиннадцать цифр' => ['12345678901'],
        ];
    }

    #[DataProvider('invalidIdProvider')]
    public function testInvalidIdIsRejected(string $raw): void
    {
        $this->assertNull(parseDiagnosticId($raw));
    }

    public function testValidIdIsParsed(): void
    {
        $this->assertSame(42, parseDiagnosticId('42'));
        $this->assertSame(9999999999, parseDiagnosticId('9999999999'));
    }

    public function testQueuedDiagnosticShowsPlannedSteps(): void
    {
        $status = buildDiagnosticStatus($this->diagnostic('queued', 0, []), self::PLANNED_STEPS);

        $this->assertSame('В очереди', $status['statusLabel']);
        $this->assertFalse($status['isFinished']);
        $this->assertSame(['availability', 'stack'], array_column($status['steps'], 'code'));
        $this->assertSame(['pending', 'pending'], array_column($status['steps'], 'status'));
        $this->assertSame('Ожидает', $status['steps'][0]['statusLabel']);
        $this->assertNull($status['steps'][0]['message']);
    }

    public function testStepsFromDatabaseReplacePlannedSteps(): void
    {
        $steps = [
            $this->step('availability', 'done', null),
            $this->step('stack', 'failed', 'Тайм-аут'),
            $this->step('resources', 'running', ''),
        ];

        $status = buildDiagnosticStatus($this->diagnostic('running', 50, $steps), self::PLANNED_STEPS);

        $this->assertSame(50, $status['progress']);
        $this->assertSame(['availability', 'stack', 'resources'], array_column($status['steps'], 'code'));
        $this->assertSame('Тайм-аут', $status['steps'][1]['message']);
        $this->assertNull($status['steps'][2]['message']);
    }

    /**
     * @return array<string, array{string, string, string, bool}>
     */
    public static function diagnosticStatusProvider(): array
    {
        return [
            'queued'    => ['queued', 'В очереди', 'bg-secondary-lt', false],
            'running'   => ['running', 'Выполняется', 'bg-blue-lt', false],
            'done'      => ['done', 'Готово', 'bg-green-lt', true],
            'failed'    => ['failed', 'Ошибка', 'bg-red-lt', true],
            'cancelled' => ['cancelled', 'Отменена', 'bg-secondary-lt', true],
        ];
    }

    #[DataProvider('diagnosticStatusProvider')]
    public function testDiagnosticStatusLabelBadgeAndFinished(
        string $status,
        string $label,
        string $badge,
        bool $isFinished
    ): void {
        $result = buildDiagnosticStatus($this->diagnostic($status, 0, []), self::PLANNED_STEPS);

        $this->assertSame($label, $result['statusLabel']);
        $this->assertSame($badge, $result['badgeClass']);
        $this->assertSame($isFinished, $result['isFinished']);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function stepStatusProvider(): array
    {
        return [
            'pending' => ['pending', 'Ожидает', 'bg-secondary-lt'],
            'running' => ['running', 'Выполняется', 'bg-blue-lt'],
            'done'    => ['done', 'Готово', 'bg-green-lt'],
            'skipped' => ['skipped', 'Пропущен', 'bg-yellow-lt'],
            'failed'  => ['failed', 'Ошибка', 'bg-red-lt'],
        ];
    }

    #[DataProvider('stepStatusProvider')]
    public function testStepStatusLabelAndBadge(string $status, string $label, string $badge): void
    {
        $result = buildStepStatus($this->step('stack', $status, null));

        $this->assertSame($label, $result['statusLabel']);
        $this->assertSame($badge, $result['badgeClass']);
    }

    public function testFailedDiagnosticKeepsErrorMessage(): void
    {
        $diagnostic = $this->diagnostic('failed', 25, []);
        $diagnostic['error_message'] = 'Диагностика прервана';

        $result = buildDiagnosticStatus($diagnostic, self::PLANNED_STEPS);

        $this->assertSame('Диагностика прервана', $result['errorMessage']);
        $this->assertSame(25, $result['progress']);
    }

    /**
     * @param list<array<string, mixed>> $steps
     *
     * @return array<string, mixed>
     */
    private function diagnostic(string $status, int $progress, array $steps): array
    {
        return [
            'id'            => '7',
            'url'           => 'https://example.com',
            'status'        => $status,
            'progress'      => (string) $progress,
            'error_message' => null,
            'steps'         => $steps,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function step(string $code, string $status, ?string $message): array
    {
        return ['step_code' => $code, 'title' => 'Шаг ' . $code, 'status' => $status, 'message' => $message];
    }
}
