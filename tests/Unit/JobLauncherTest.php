<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/src/Services/JobLauncher.php';

final class JobLauncherTest extends TestCase
{
    public function testCommandStartsWorkerInBackground(): void
    {
        $command = buildWorkerCommand(
            'C:/OSPanel/modules/PHP-8.3/PHP/php.exe',
            'C:/OSPanel/home/site/bin/worker.php',
            42
        );

        $this->assertSame(
            'start "" /B "C:\OSPanel\modules\PHP-8.3\PHP\php.exe" "C:\OSPanel\home\site\bin\worker.php" 42 > NUL 2>&1',
            $command
        );
    }

    public function testAiOnlyModeAddsFlag(): void
    {
        $command = buildWorkerCommand('C:/php.exe', 'C:/site/bin/worker.php', 42, WORKER_MODE_AI_ONLY);

        $this->assertSame('start "" /B "C:\php.exe" "C:\site\bin\worker.php" 42 --ai > NUL 2>&1', $command);
    }

    public function testFullModeHasNoFlag(): void
    {
        $command = buildWorkerCommand('C:/php.exe', 'C:/site/bin/worker.php', 42, WORKER_MODE_FULL);

        $this->assertSame(buildWorkerCommand('C:/php.exe', 'C:/site/bin/worker.php', 42), $command);
        $this->assertStringNotContainsString('--ai', $command);
    }

    public function testUnknownModeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        buildWorkerCommand('C:/php.exe', 'C:/site/bin/worker.php', 1, 'everything');
    }

    public function testPathsWithSpacesAreQuoted(): void
    {
        $command = buildWorkerCommand('C:/Program Files/PHP/php.exe', 'D:/my sites/bin/worker.php', 7);

        $this->assertStringContainsString('"C:\Program Files\PHP\php.exe"', $command);
        $this->assertStringContainsString('"D:\my sites\bin\worker.php" 7', $command);
    }

    public static function invalidPaths(): array
    {
        return [
            'пустой'          => [''],
            'кавычка'         => ['C:/php"&calc.exe'],
            'перевод строки'  => ["C:/php.exe\n"],
            'переменная cmd'  => ['C:/%TEMP%/php.exe'],
        ];
    }

    #[DataProvider('invalidPaths')]
    public function testInvalidPathIsRejected(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);
        buildWorkerCommand($path, 'C:/site/bin/worker.php', 1);
    }

    public function testNonPositiveIdIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        buildWorkerCommand('C:/php.exe', 'C:/site/bin/worker.php', 0);
    }
}
