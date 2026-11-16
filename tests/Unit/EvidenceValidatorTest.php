<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/src/Services/Ai/EvidenceValidator.php';

final class EvidenceValidatorTest extends TestCase
{
    private const ALLOWED = ['finding_ids' => [10, 11, 12], 'stack_ids' => [5]];

    public function testItemWithValidReferencesIsKept(): void
    {
        $result = validateAiEvidence([['title' => 'А', 'finding_ids' => [10, 12], 'stack_ids' => [5]]], self::ALLOWED);

        $this->assertSame(0, $result['rejected']);
        $this->assertSame([10, 12], $result['items'][0]['finding_ids']);
        $this->assertSame([5], $result['items'][0]['stack_ids']);
    }

    public function testUnknownOrForeignIdsAreRemovedAndItemWithoutReferencesIsRejected(): void
    {
        // 999 — несуществующий факт, 77 — находка другой диагностики (нет в пакете этой)
        $result = validateAiEvidence([
            ['title' => 'Смешанные', 'finding_ids' => [999, 11, 77], 'stack_ids' => [6]],
            ['title' => 'Только чужие', 'finding_ids' => [77], 'stack_ids' => [999]],
            ['title' => 'Без ссылок', 'finding_ids' => [], 'stack_ids' => []],
        ], self::ALLOWED);

        $this->assertSame(2, $result['rejected']);
        $this->assertSame(['Смешанные'], array_column($result['items'], 'title'));
        $this->assertSame([11], $result['items'][0]['finding_ids']);
        $this->assertSame([], $result['items'][0]['stack_ids']);
    }

    public function testMissingKeysAndBrokenItemsAreRejected(): void
    {
        $result = validateAiEvidence([['title' => 'Нет ключей'], 'строка', null], self::ALLOWED);

        $this->assertSame(3, $result['rejected']);
        $this->assertSame([], $result['items']);
    }

    public function testIdsAreDeduplicatedAndDigitStringsAccepted(): void
    {
        $this->assertSame([10, 11], filterAiEvidenceIds([10, '10', '11', 'x', 1.5, -10, null], [10, 11]));
        $this->assertSame([], filterAiEvidenceIds('10', [10]));
    }
}
