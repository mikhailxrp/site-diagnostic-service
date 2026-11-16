<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once ROOT_PATH . '/src/Services/NvdClient.php';

/**
 * NVD без сети: шаблон CPE, разбор сохранённых настоящих ответов (OpenCart 3.0.3.8, Joomla 4.2.7 — урезан до трёх
 * CVE), сбои запроса.
 */
final class NvdClientTest extends TestCase
{
    private const FIXTURES = ROOT_PATH . '/tests/fixtures/vulnerabilities';
    private const OPENCART_CPE = 'cpe:2.3:a:opencart:opencart:{version}';
    private const JOOMLA_CPE = 'cpe:2.3:a:joomla:joomla\!:{version}';

    private function fixture(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES . '/' . $name);
    }

    /**
     * @param list<array<string, mixed>> $records
     * @return array<string, mixed>
     */
    private function byId(array $records, string $id): array
    {
        foreach ($records as $record) {
            if ($record['ids'][0] === $id) {
                return $record;
            }
        }
        $this->fail("Нет записи {$id}");
    }

    public function testCpeFromTemplate(): void
    {
        $magento = 'cpe:2.3:a:adobe:magento:{version}:{update}:*:*:open_source';

        $this->assertSame('cpe:2.3:a:adobe:magento:2.4.6:p3:*:*:open_source', buildNvdCpe($magento, '2.4.6-p3'));
        $this->assertSame('cpe:2.3:a:adobe:magento:2.4.6:-:*:*:open_source', buildNvdCpe($magento, '2.4.6'), 'без патча — «без обновления»');
        $this->assertSame('cpe:2.3:a:joomla:joomla\!:4.2.7', buildNvdCpe(self::JOOMLA_CPE, '4.2.7'));
    }

    public function testUrlEncodesCpe(): void
    {
        $this->assertSame(
            'https://services.nvd.nist.gov/rest/json/cves/2.0?virtualMatchString=cpe%3A2.3%3Aa%3Ajoomla%3Ajoomla%5C%21%3A4.2.7',
            buildNvdUrl('cpe:2.3:a:joomla:joomla\!:4.2.7')
        );
    }

    public function testOpencartRecordHasIdRangeFixCvssAndLink(): void
    {
        $records = parseNvdResponse($this->fixture('nvd-opencart.json'), [self::OPENCART_CPE]);

        $this->assertCount(7, $records);
        $record = $this->byId($records, 'CVE-2025-1746');
        $this->assertSame(['CVE-2025-1746'], $record['ids']);
        $this->assertSame([['from' => '*', 'to' => '4.1.0.0', 'from_inclusive' => false, 'to_inclusive' => false]], $record['ranges']);
        $this->assertSame(['4.1.0.0'], $record['fixed']);
        $this->assertSame(6.1, $record['cvss']);
        $this->assertSame('https://nvd.nist.gov/vuln/detail/CVE-2025-1746', $record['url']);
        $this->assertSame('NVD', $record['source']);
        $this->assertSame('Cross-Site Scripting vulnerability in OpenCart versions prior to 4.1.0.', $record['title']);
        $this->assertTrue(isVersionVulnerable('3.0.3.8', $record));
    }

    public function testEndIncludingHasNoFixedVersion(): void
    {
        $record = $this->byId(parseNvdResponse($this->fixture('nvd-opencart.json'), [self::OPENCART_CPE]), 'CVE-2025-45892');

        $this->assertSame([['from' => '*', 'to' => '4.1.0.4', 'from_inclusive' => false, 'to_inclusive' => true]], $record['ranges']);
        $this->assertSame([], $record['fixed'], '«до 4.1.0.4 включительно» — исправленная версия неизвестна');
    }

    public function testJoomlaBranchesGiveNearestFix(): void
    {
        $records = parseNvdResponse($this->fixture('nvd-joomla.json'), [self::JOOMLA_CPE]);

        $this->assertCount(3, $records);
        $record = $this->byId($records, 'CVE-2024-21722');
        $this->assertCount(3, $record['ranges'], 'ветки 3.x, 4.x, 5.x');
        $this->assertTrue(isVersionVulnerable('4.2.7', $record));
        $this->assertSame('4.4.3', pickFixedVersion('4.2.7', $record['fixed']));
        $this->assertStringStartsWith('An issue was discovered in Joomla! 4.0.0 through 4.2.7. An improper access check', $this->byId($records, 'CVE-2023-23752')['title']);
    }

    public function testRangesOnlyFromOwnProductAndExactVersionCpe(): void
    {
        $body = json_encode(['vulnerabilities' => [['cve' => [
            'id'             => 'CVE-2000-0001',
            'descriptions'   => [['lang' => 'en', 'value' => 'Test.']],
            'metrics'        => ['cvssMetricV2' => [['type' => 'Primary', 'cvssData' => ['baseScore' => 5]]]],
            'configurations' => [['nodes' => [['cpeMatch' => [
                ['vulnerable' => true, 'criteria' => 'cpe:2.3:a:adobe:magento:2.4.6:p3:*:*:open_source:*:*:*'],
                ['vulnerable' => true, 'criteria' => 'cpe:2.3:a:other:product:*:*:*:*:*:*:*:*', 'versionEndExcluding' => '9.0'],
                ['vulnerable' => false, 'criteria' => 'cpe:2.3:o:linux:linux_kernel:*:*:*:*:*:*:*:*'],
            ]]]]],
        ]]]]);

        $records = parseNvdResponse((string) $body, ['cpe:2.3:a:adobe:magento:{version}:{update}:*:*:open_source']);

        $this->assertSame([['from' => '2.4.6-p3', 'to' => '2.4.6-p3', 'from_inclusive' => true, 'to_inclusive' => true]], $records[0]['ranges']);
        $this->assertSame([], $records[0]['fixed'], 'исправление чужого продукта — не наше');
        $this->assertSame(5.0, $records[0]['cvss']);
    }

    public function testInvalidResponseThrows(): void
    {
        $this->expectException(RuntimeException::class);
        parseNvdResponse('<html>Service Unavailable</html>', [self::OPENCART_CPE]);
    }

    public function testFetchQueriesEveryTemplateAndMergesSameCve(): void
    {
        $urls = [];
        $api = function (string $url, ?array $json) use (&$urls): array {
            $urls[] = $url;
            $this->assertNull($json, 'NVD — GET');

            return ['status' => 200, 'body' => $this->fixture('nvd-joomla.json'), 'error' => null];
        };

        $records = fetchNvdVulnerabilities([self::JOOMLA_CPE, self::JOOMLA_CPE], '4.2.7', $api);

        $this->assertCount(2, $urls);
        $this->assertCount(3, $records, 'одна CVE из двух ответов — одна запись');
    }

    public function testFetchFailureThrows(): void
    {
        foreach ([
            ['status' => null, 'body' => '', 'error' => 'Источник не ответил вовремя'],
            ['status' => 503, 'body' => '', 'error' => null],
        ] as $response) {
            try {
                fetchNvdVulnerabilities([self::OPENCART_CPE], '3.0.3.8', static fn (): array => $response);
                $this->fail('сбой NVD должен бросать исключение');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('NVD', $exception->getMessage());
            }
        }
    }
}
