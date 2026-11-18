<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/src/Services/FactPacker.php';
require_once ROOT_PATH . '/src/Services/Checks/CheckoutCheck.php';

final class FactPackerTest extends TestCase
{
    public function testPackHasIdsOfFindingsAndStackAndPageUrls(): void
    {
        $result = buildFactPack(
            [$this->finding(11, 'high', ['page_id' => 3, 'evidence' => ['status' => 500]])],
            [['id' => 5, 'category' => 'cms', 'name' => 'OpenCart', 'version' => '3.0.2.0', 'latest_version' => '4.1.0.0',
              'confidence' => 'high', 'evidence' => []]],
            [['id' => 3, 'url' => 'https://shop.ru/', 'page_type' => 'home', 'http_code' => 200, 'response_ms' => 300,
              'blocked' => 0]],
            ['availability' => ['response_ms' => 300], 'databases' => ['x' => ['title' => 'база']]]
        );

        $this->assertSame([11], $result['finding_ids']);
        $this->assertSame([5], $result['stack_ids']);
        $fact = $result['pack']['findings'][0];
        $this->assertSame('https://shop.ru/', $fact['page']);
        $this->assertSame('{"status":500}', $fact['evidence']);
        $this->assertSame('OpenCart', $result['pack']['stack'][0]['name']);
        $this->assertSame(['type' => 'home', 'url' => 'https://shop.ru/', 'http_code' => 200, 'response_ms' => 300,
            'blocked' => false], $result['pack']['pages'][0]);
        $this->assertSame(['availability' => ['response_ms' => 300]], $result['pack']['summary']);
        $this->assertSame(0, $result['pack']['omitted_findings']);
    }

    public function testAiFindingsAreNotFacts(): void
    {
        $result = buildFactPack([
            $this->finding(1, 'high'),
            $this->finding(2, 'info', ['source' => 'ai', 'category' => 'hypothesis']),
        ], [], [], null);

        $this->assertSame([1], $result['finding_ids']);
    }

    public function testSevereFindingsGoFirst(): void
    {
        $result = buildFactPack([
            $this->finding(1, 'low'),
            $this->finding(2, 'critical'),
            $this->finding(3, 'medium'),
            $this->finding(4, 'critical'),
        ], [], [], null);

        $this->assertSame([2, 4, 3, 1], $result['finding_ids']);
    }

    public function testPackIsLimitedAndLowSeverityIsOmitted(): void
    {
        $findings = [];
        for ($id = 1; $id <= 200; $id++) {
            $findings[] = $this->finding($id, $id <= 5 ? 'critical' : 'low', ['details' => str_repeat('д', 400)]);
        }
        $maxChars = 5000;

        $result = buildFactPack($findings, [], [], null, $maxChars);

        $this->assertLessThanOrEqual($maxChars, getFactJsonLength($result['pack']));
        $this->assertSame([1, 2, 3, 4, 5], array_slice($result['finding_ids'], 0, 5));
        $this->assertGreaterThan(0, $result['pack']['omitted_findings']);
        $this->assertSame(200, count($result['finding_ids']) + $result['pack']['omitted_findings']);
    }

    public function testDefaultLimitHoldsForHugeDiagnostic(): void
    {
        $findings = [];
        for ($id = 1; $id <= 1000; $id++) {
            $findings[] = $this->finding($id, 'medium', ['details' => str_repeat('x', 2000),
                'evidence' => ['stack' => str_repeat('y', 5000)]]);
        }

        $result = buildFactPack($findings, [], [], null);

        $this->assertLessThanOrEqual(FACT_PACK_MAX_CHARS, getFactJsonLength($result['pack']));
    }

    public function testLongTextsAreTruncated(): void
    {
        $result = buildFactPack([$this->finding(1, 'high', [
            'title'    => str_repeat('з', 1000),
            'details'  => str_repeat('п', 1000),
            'evidence' => ['stack' => str_repeat('s', 2000)],
        ])], [], [], ['pagespeed' => ['raw' => str_repeat('r', 5000)]]);

        $fact = $result['pack']['findings'][0];
        $this->assertSame(FACT_TEXT_MAX_CHARS, mb_strlen($fact['title']));
        $this->assertStringEndsWith(FACT_TRUNCATED_MARK, $fact['details']);
        $this->assertSame(FACT_EVIDENCE_MAX_CHARS, mb_strlen($fact['evidence']));
        $this->assertIsString($result['pack']['summary']['pagespeed']);
        $this->assertSame(FACT_SECTION_MAX_CHARS, mb_strlen($result['pack']['summary']['pagespeed']));
    }

    public function testCheckoutSummaryIsPackedWithoutScenarioPages(): void
    {
        $checkout = [
            'outcome' => 'reached',
            'reached' => 'checkout',
            'steps'   => [
                ['code' => 'product', 'status' => 'done', 'page_id' => 11],
                ['code' => 'checkout', 'status' => 'done', 'page_id' => 12],
            ],
            'posts'    => ['checkout_sent' => 0, 'blocked' => 2],
            'page_ids' => [11, 12],
        ];
        $pages = [
            ['id' => 1, 'url' => 'https://shop.ru/', 'page_type' => 'home', 'http_code' => 200, 'response_ms' => 100,
                'blocked' => 0],
            ['id' => 11, 'url' => 'https://shop.ru/product/', 'page_type' => 'product', 'http_code' => null,
                'response_ms' => null, 'blocked' => 0],
            ['id' => 12, 'url' => 'https://shop.ru/checkout/', 'page_type' => 'checkout', 'http_code' => null,
                'response_ms' => null, 'blocked' => 0],
        ];

        $result = buildFactPack([], [], excludeCheckoutPages($pages, $checkout), ['checkout' => $checkout]);

        $this->assertSame($checkout, $result['pack']['summary']['checkout']);
        $this->assertLessThanOrEqual(FACT_SECTION_MAX_CHARS, getFactJsonLength($result['pack']['summary']['checkout']));
        $this->assertSame(['https://shop.ru/'], array_column($result['pack']['pages'], 'url'));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function finding(int $id, string $severity, array $overrides = []): array
    {
        return $overrides + [
            'id'       => $id,
            'page_id'  => null,
            'source'   => 'check',
            'category' => 'php_error',
            'severity' => $severity,
            'title'    => 'Находка ' . $id,
            'details'  => null,
            'location' => null,
            'evidence' => null,
        ];
    }
}
