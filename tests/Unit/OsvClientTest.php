<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once ROOT_PATH . '/src/Services/OsvClient.php';

/**
 * OSV без сети: тело запроса, разбор сохранённых настоящих ответов (OpenCart 3.0.3.8, Joomla 4.2.7), события
 * диапазонов, сбои запроса.
 */
final class OsvClientTest extends TestCase
{
    private const FIXTURES = ROOT_PATH . '/tests/fixtures/vulnerabilities';

    private function fixture(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES . '/' . $name);
    }

    public function testQueryBody(): void
    {
        $this->assertSame(
            ['package' => ['ecosystem' => 'Packagist', 'name' => 'opencart/opencart'], 'version' => '3.0.3.8'],
            buildOsvQuery('opencart/opencart', '3.0.3.8')
        );
    }

    public function testOpencartRecordsHaveCveFirstAndSeverity(): void
    {
        $records = parseOsvResponse($this->fixture('osv-opencart.json'), 'opencart/opencart');

        $this->assertCount(2, $records);
        [$sql, $ssti] = $records;
        $this->assertSame(['CVE-2024-21514', 'GHSA-7crj-24g3-g7h7'], $sql['ids'], 'CVE — первым, по нему склейка с NVD');
        $this->assertSame([['from' => '*', 'to' => '3.0.3.9', 'from_inclusive' => true, 'to_inclusive' => true]], $sql['ranges']);
        $this->assertSame([], $sql['fixed'], '`last_affected` — не исправление');
        $this->assertSame('high', $sql['severity']);
        $this->assertNull($sql['cvss']);
        $this->assertSame('https://osv.dev/vulnerability/GHSA-7crj-24g3-g7h7', $sql['url']);
        $this->assertSame('OSV', $sql['source']);
        $this->assertSame('SQL injection in opencart', $sql['title']);
        $this->assertSame('medium', $ssti['severity'], 'MODERATE → medium');
        $this->assertSame(['CVE-2024-36694', 'GHSA-xrh7-2gfq-4rcq'], $ssti['ids'], 'чужие алиасы (BIT-…) не нужны');
    }

    public function testJoomlaBranchesAndFixes(): void
    {
        [$record] = parseOsvResponse($this->fixture('osv-joomla.json'), 'joomla/joomla-cms');

        $this->assertSame(['CVE-2025-25227', 'GHSA-6423-85cc-8gf6'], $record['ids']);
        $this->assertSame(['5.2.6', '4.4.13'], $record['fixed']);
        $this->assertTrue(isVersionVulnerable('4.2.7', $record));
        $this->assertSame('4.4.13', pickFixedVersion('4.2.7', $record['fixed']));
    }

    public function testEvents(): void
    {
        $this->assertSame(
            [
                'ranges' => [
                    ['from' => '*', 'to' => '1.0', 'from_inclusive' => true, 'to_inclusive' => false],
                    ['from' => '2.0', 'to' => '*', 'from_inclusive' => true, 'to_inclusive' => true],
                ],
                'fixed' => ['1.0'],
            ],
            parseOsvEvents([['introduced' => '0'], ['fixed' => '1.0'], ['introduced' => '2.0']]),
            'незакрытый диапазон — до последней версии'
        );
    }

    public function testOtherPackageAndGitRangesIgnored(): void
    {
        $body = json_encode(['vulns' => [[
            'id'       => 'GHSA-test',
            'summary'  => 'Test',
            'affected' => [
                ['package' => ['name' => 'other/package'], 'ranges' => [['type' => 'ECOSYSTEM', 'events' => [['introduced' => '0'], ['fixed' => '9.0']]]]],
                ['package' => ['name' => 'OpenCart/OpenCart'], 'ranges' => [
                    ['type' => 'GIT', 'events' => [['introduced' => 'abc123'], ['fixed' => 'def456']]],
                    ['type' => 'ECOSYSTEM', 'events' => [['introduced' => '3.0'], ['fixed' => '3.1']]],
                ]],
            ],
        ]]]);

        [$record] = parseOsvResponse((string) $body, 'opencart/opencart');

        $this->assertSame(['GHSA-test'], $record['ids']);
        $this->assertSame([['from' => '3.0', 'to' => '3.1', 'from_inclusive' => true, 'to_inclusive' => false]], $record['ranges']);
        $this->assertSame(['3.1'], $record['fixed']);
        $this->assertNull($record['severity']);
    }

    public function testEmptyResponseHasNoRecords(): void
    {
        $this->assertSame([], parseOsvResponse('{}', 'opencart/opencart'), 'уязвимостей нет — OSV отвечает пустым объектом');
    }

    public function testFetchPostsQuery(): void
    {
        $api = function (string $url, ?array $json): array {
            $this->assertSame('https://api.osv.dev/v1/query', $url);
            $this->assertSame(buildOsvQuery('joomla/joomla-cms', '4.2.7'), $json);

            return ['status' => 200, 'body' => $this->fixture('osv-joomla.json'), 'error' => null];
        };

        $this->assertCount(1, fetchOsvVulnerabilities('joomla/joomla-cms', '4.2.7', $api));
    }

    public function testFetchFailureThrows(): void
    {
        foreach ([
            ['status' => null, 'body' => '', 'error' => 'Источник не ответил вовремя'],
            ['status' => 500, 'body' => '', 'error' => null],
            ['status' => 200, 'body' => 'not json', 'error' => null],
        ] as $response) {
            try {
                fetchOsvVulnerabilities('opencart/opencart', '3.0.3.8', static fn (): array => $response);
                $this->fail('сбой OSV должен бросать исключение');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('OSV', $exception->getMessage());
            }
        }
    }

    public function testAffectedVersionsOnlyAndAlongsideGitRange(): void
    {
        $body = json_encode(['vulns' => [
            [
                'id'       => 'GHSA-git-and-versions',
                'summary'  => 'PrestaShop advisory',
                'affected' => [[
                    'package'  => ['name' => 'prestashop/prestashop'],
                    'ranges'   => [['type' => 'GIT', 'events' => [['introduced' => 'abc123']]]],
                    'versions' => ['1.7.8.0', 'v1.7.8.2', 'dev-main'],
                ]],
            ],
            [
                'id'       => 'GHSA-versions-only',
                'summary'  => 'Exact versions only',
                'affected' => [[
                    'package'  => ['name' => 'prestashop/prestashop'],
                    'versions' => ['1.7.8.0', '3.0.0-beta.4'],
                ]],
            ],
        ]]);

        $records = parseOsvResponse((string) $body, 'prestashop/prestashop');
        $this->assertCount(2, $records);

        [$withGit, $onlyVersions] = $records;
        $this->assertSame([
            ['from' => '1.7.8.0', 'to' => '1.7.8.0', 'from_inclusive' => true, 'to_inclusive' => true],
            ['from' => '1.7.8.2', 'to' => '1.7.8.2', 'from_inclusive' => true, 'to_inclusive' => true],
        ], $withGit['ranges']);
        $this->assertTrue(isVersionVulnerable('1.7.8.0', $withGit));
        $this->assertTrue(isVersionVulnerable('1.7.8.2', $withGit));
        $this->assertFalse(isVersionVulnerable('1.7.8.1', $withGit));

        $this->assertTrue(isVersionVulnerable('1.7.8.0', $onlyVersions));
        $this->assertTrue(isVersionVulnerable('3.0.0-beta.4', $onlyVersions));
        $this->assertFalse(isVersionVulnerable('1.7.8.1', $onlyVersions));
        $this->assertFalse(isVersionVulnerable('3.0.0', $onlyVersions));
    }

    public function testAffectedVersionsAlreadyCoveredByRangeAreNotDuplicated(): void
    {
        $body = json_encode(['vulns' => [[
            'id'       => 'GHSA-mixed',
            'summary'  => 'Range plus extra exact version',
            'affected' => [[
                'package'  => ['name' => 'prestashop/prestashop'],
                'ranges'   => [['type' => 'ECOSYSTEM', 'events' => [['introduced' => '1.0.0'], ['fixed' => '1.5.0']]]],
                'versions' => ['1.2.0', '2.0.0'],
            ]],
        ]]]);

        [$record] = parseOsvResponse((string) $body, 'prestashop/prestashop');

        $this->assertSame([
            ['from' => '1.0.0', 'to' => '1.5.0', 'from_inclusive' => true, 'to_inclusive' => false],
            ['from' => '2.0.0', 'to' => '2.0.0', 'from_inclusive' => true, 'to_inclusive' => true],
        ], $record['ranges']);
        $this->assertTrue(isVersionVulnerable('1.2.0', $record));
        $this->assertTrue(isVersionVulnerable('2.0.0', $record));
        $this->assertFalse(isVersionVulnerable('1.5.0', $record));
        $this->assertFalse(isVersionVulnerable('2.0.1', $record));
    }
}
