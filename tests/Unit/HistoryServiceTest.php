<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/src/Services/HistoryService.php';

final class HistoryServiceTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function queryProvider(): array
    {
        return [
            'часть домена'          => ['sudar', 'sudar'],
            'полный адрес'          => ['https://www.Sudar.su/catalog/?a=1', 'sudar.su'],
            'без схемы с путём'     => ['www.sudar.su/catalog', 'sudar.su'],
            'протокол-относительный' => ['//cleansan.ru/', 'cleansan.ru'],
            'пробелы'               => ['  citilink.ru  ', 'citilink.ru'],
            'кириллический домен'   => ['Магазин.рф', 'xn--80aairftm.xn--p1ai'],
            'спецсимволы как есть'  => ['50%_off', '50%_off'],
            'пусто'                 => ['', ''],
        ];
    }

    #[DataProvider('queryProvider')]
    public function testQueryIsNormalized(string $raw, string $expected): void
    {
        $this->assertSame($expected, normalizeHistoryQuery($raw));
    }

    public function testQueryIsLimitedInLength(): void
    {
        $this->assertSame(HISTORY_QUERY_MAX_LENGTH, mb_strlen(normalizeHistoryQuery(str_repeat('а', 400))));
        $this->assertSame(HISTORY_QUERY_MAX_LENGTH, mb_strlen(readHistoryQueryInput(str_repeat('a', 400))));
    }

    public function testQueryInputAcceptsOnlyStrings(): void
    {
        $this->assertSame('', readHistoryQueryInput(['a']));
        $this->assertSame('shop.ru', readHistoryQueryInput(' shop.ru '));
    }

    /**
     * @return array<string, array{mixed, int}>
     */
    public static function pageInputProvider(): array
    {
        return [
            'число'        => ['3', 3],
            'ноль'         => ['0', 1],
            'минус'        => ['-2', 1],
            'буквы'        => ['abc', 1],
            'дробь'        => ['1.5', 1],
            'массив'       => [['2'], 1],
            'огромное'     => ['99999999999', 1],
        ];
    }

    #[DataProvider('pageInputProvider')]
    public function testPageInputIsValidated(mixed $raw, int $expected): void
    {
        $this->assertSame($expected, readHistoryPageInput($raw));
    }

    public function testPaginationClampsPageAndCalculatesOffset(): void
    {
        $pagination = buildHistoryPagination(45, 7, 20);

        $this->assertSame(3, $pagination['pages']);
        $this->assertSame(3, $pagination['current']);
        $this->assertSame(40, $pagination['offset']);
        $this->assertSame(2, $pagination['prev']);
        $this->assertNull($pagination['next']);
        $this->assertSame([1, 2, 3], $pagination['items']);
    }

    public function testEmptyHistoryHasOnePage(): void
    {
        $pagination = buildHistoryPagination(0, 5, 20);

        $this->assertSame(1, $pagination['pages']);
        $this->assertSame(1, $pagination['current']);
        $this->assertSame(0, $pagination['offset']);
        $this->assertNull($pagination['prev']);
        $this->assertNull($pagination['next']);
    }

    public function testPaginationShowsGapsAroundCurrentPage(): void
    {
        $this->assertSame([1, null, 4, 5, 6, 7, 8, null, 20], buildHistoryPagination(400, 6, 20)['items']);
        $this->assertSame([1, 2, 3, null, 20], buildHistoryPagination(400, 1, 20)['items']);
        $this->assertSame([1, 2, 3, 4, 5, null, 20], buildHistoryPagination(400, 3, 20)['items']);
    }

    public function testHistoryUrlKeepsOnlyMeaningfulParams(): void
    {
        $this->assertSame('/history', buildHistoryUrl('', 1));
        $this->assertSame('/history?page=2', buildHistoryUrl('', 2));
        $this->assertSame('/history?q=sudar.su&page=3', buildHistoryUrl('sudar.su', 3));
        $this->assertSame('/history?q=%D0%BC%D0%B0%D0%B3', buildHistoryUrl('маг', 1));
    }

    public function testPaginationLinksMarkCurrentPage(): void
    {
        $links = buildHistoryPaginationLinks(buildHistoryPagination(60, 2, 20), 'shop');

        $this->assertSame('/history?q=shop', $links['prev']);
        $this->assertSame('/history?q=shop&page=3', $links['next']);
        $this->assertSame([false, true, false], array_column($links['links'], 'active'));
    }

    public function testRowsHaveLabelsCountersAndDeleteFlag(): void
    {
        $rows = buildHistoryRows([
            $this->diagnostic(5, 'done', ['critical_count' => '2', 'high_count' => '1', 'finding_count' => '9']),
            $this->diagnostic(6, 'running'),
            $this->diagnostic(7, 'queued'),
            $this->diagnostic(8, 'failed'),
        ]);

        $this->assertSame('/diagnostics/5', $rows[0]['showUrl']);
        $this->assertSame('/diagnostics/5/delete', $rows[0]['deleteUrl']);
        $this->assertSame('15.11.2026 18:29', $rows[0]['createdAt']);
        $this->assertSame('Готово', $rows[0]['statusLabel']);
        $this->assertSame([2, 1, 9], [$rows[0]['criticalCount'], $rows[0]['highCount'], $rows[0]['findingCount']]);
        $this->assertSame([true, false, false, true], array_column($rows, 'canDelete'));
        $this->assertSame([true, false, false, true], array_column($rows, 'isFinished'));
    }

    public function testPunycodeHostIsShownInCyrillic(): void
    {
        $this->assertSame('магазин.рф', formatHistoryHost('xn--80aairftm.xn--p1ai'));
        $this->assertSame('shop.ru', formatHistoryHost('shop.ru'));
        $this->assertSame('магазин.рф', buildHistoryRows([$this->diagnostic(1, 'done', ['host' => 'xn--80aairftm.xn--p1ai'])])[0]['displayHost']);
    }

    public function testUnknownDateIsShownAsIs(): void
    {
        $this->assertSame('вчера', formatHistoryDate('вчера'));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function diagnostic(int $id, string $status, array $overrides = []): array
    {
        return $overrides + [
            'id'             => $id,
            'url'            => 'https://shop.ru/',
            'host'           => 'shop.ru',
            'status'         => $status,
            'created_at'     => '2026-11-15 18:29:43',
            'critical_count' => 0,
            'high_count'     => 0,
            'finding_count'  => 0,
        ];
    }
}
