<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once ROOT_PATH . '/src/Services/DatabaseUpdater.php';

/**
 * Реестр баз, атомарная запись, сохранность прошлой базы, блокировка — во временной папке, без сети.
 */
final class DatabaseUpdaterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sd-databases-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    private function noNetwork(): callable
    {
        return static fn (string $url): array => throw new RuntimeException("Сеть в тесте: {$url}");
    }

    /**
     * Тестовый источник в реестре — так же добавятся базы уязвимостей таска 7.
     */
    private function source(callable $update): array
    {
        return ['title' => 'Тестовая база', 'file' => 'test.json', 'update' => $update];
    }

    public function testNewSourceIsUpdatedThroughRegistry(): void
    {
        $results = updateDatabases($this->noNetwork(), $this->dir, [
            'test' => $this->source(static fn (?array $previous, callable $request): array => [
                'data'    => ['items' => ['a' => ['latest' => '1.0.0', 'updated_at' => '2026-11-16T10:00:00+03:00']]],
                'updated' => ['a'],
                'failed'  => [],
            ]),
        ]);

        $this->assertTrue($results['test']['ok']);
        $this->assertSame('1.0.0', readDatabaseFile($this->dir . '/test.json')['items']['a']['latest']);
        $this->assertFileDoesNotExist($this->dir . '/test.json.tmp');
    }

    public function testPreviousDataIsPassedToUpdate(): void
    {
        saveDatabaseFile($this->dir . '/test.json', ['items' => ['old' => ['latest' => '0.9']]]);
        $seen = null;

        updateDatabases($this->noNetwork(), $this->dir, [
            'test' => $this->source(static function (?array $previous) use (&$seen): array {
                $seen = $previous;

                return ['data' => ['items' => ['new' => ['latest' => '1.0']]], 'updated' => ['new'], 'failed' => []];
            }),
        ]);

        $this->assertSame('0.9', $seen['items']['old']['latest']);
    }

    public function testFailedOrEmptyUpdateDoesNotTouchOldFileOthersStillUpdate(): void
    {
        $old = ['items' => ['a' => ['latest' => '1.0.0']]];
        saveDatabaseFile($this->dir . '/broken.json', $old);
        saveDatabaseFile($this->dir . '/empty.json', $old);

        $results = updateDatabases($this->noNetwork(), $this->dir, [
            'broken' => ['title' => 'Сломанная', 'file' => 'broken.json',
                'update' => static fn (): array => throw new RuntimeException('источник упал')],
            'empty'  => ['title' => 'Пустая', 'file' => 'empty.json',
                'update' => static fn (): array => ['data' => ['items' => []], 'updated' => [], 'failed' => []]],
            'ok'     => $this->source(static fn (): array => ['data' => ['items' => ['b' => ['latest' => '2.0']]]]),
        ]);

        $this->assertFalse($results['broken']['ok']);
        $this->assertSame('источник упал', $results['broken']['error']);
        $this->assertFalse($results['empty']['ok']);
        $this->assertTrue($results['ok']['ok']);
        $this->assertSame($old, readDatabaseFile($this->dir . '/broken.json'));
        $this->assertSame($old, readDatabaseFile($this->dir . '/empty.json'));
    }

    public function testLoadDatabaseFallsBackToSeed(): void
    {
        $seed = $this->dir . '/seed';
        mkdir($seed);
        saveDatabaseFile($seed . '/latest-versions.json', ['items' => ['x' => ['latest' => 'seed']]]);

        $this->assertSame('seed', loadDatabase('latest_versions', $this->dir, $seed)['items']['x']['latest']);

        saveDatabaseFile($this->dir . '/latest-versions.json', ['items' => ['x' => ['latest' => 'work']]]);
        $this->assertSame('work', loadDatabase('latest_versions', $this->dir, $seed)['items']['x']['latest']);
        $this->assertNull(loadDatabase('unknown', $this->dir, $seed));

        unlink($seed . '/latest-versions.json');
        rmdir($seed);
    }

    public function testDatabaseDateIsOldestItemDate(): void
    {
        $this->assertSame('2026-11-01T10:00:00+03:00', getDatabaseDate(['items' => [
            'a' => ['updated_at' => '2026-11-16T10:00:00+03:00'],
            'b' => ['updated_at' => '2026-11-01T10:00:00+03:00'],
            'c' => ['updated_at' => 'мусор'],
        ]]));
        $this->assertNull(getDatabaseDate(null));
        $this->assertNull(getDatabaseDate(['items' => []]));
    }

    public function testSecondLockIsRefused(): void
    {
        $first = acquireDatabaseLock($this->dir);
        $this->assertNotNull($first);

        $this->assertNull(acquireDatabaseLock($this->dir));

        releaseDatabaseLock($first);
        $again = acquireDatabaseLock($this->dir);
        $this->assertNotNull($again);
        releaseDatabaseLock($again);
    }

    public function testRegistryEntriesAreComplete(): void
    {
        foreach (getDatabaseSources() as $key => $source) {
            $this->assertNotSame('', $source['title'], $key);
            $this->assertStringEndsWith('.json', $source['file'], $key);
            $this->assertIsCallable($source['update'], $key);
        }
    }

    public function testSeedDatabaseInRepositoryIsReadable(): void
    {
        $seed = readDatabaseFile(DATABASES_SEED_DIR . '/latest-versions.json');

        $this->assertNotNull($seed);
        $this->assertNotNull(getDatabaseDate($seed));
        $this->assertArrayHasKey('jquery', $seed['items']);
    }
}
