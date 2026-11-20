<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/src/Models/FindingModel.php';
require_once ROOT_PATH . '/src/Models/AiResultModel.php';
require_once ROOT_PATH . '/src/Models/PageModel.php';

final class ModelEncodingTest extends TestCase
{
    public function testEncodeFindingEvidenceSubstitutesInvalidUtf8(): void
    {
        $encoded = encodeFindingEvidence(['raw' => "bad\xC0byte"]);

        $this->assertIsString($encoded);
        $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['raw' => "bad\u{FFFD}byte"], $decoded);
        $this->assertNull(encodeFindingEvidence(null));
    }

    public function testEncodeAiResultJsonSubstitutesInvalidUtf8(): void
    {
        $encoded = encodeAiResultJson(['text' => "win1251\xC0\xD2"]);

        $this->assertIsString($encoded);
        $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['text' => "win1251\u{FFFD}\u{FFFD}"], $decoded);
        $this->assertNull(encodeAiResultJson(null));
    }

    public function testNormalizeCheckedPageUrlTruncatesAndScrubsInvalidUtf8(): void
    {
        $longUrl = 'https://shop.test/' . str_repeat('a', 3000);
        $normalizedLong = normalizeCheckedPageUrl($longUrl);
        $this->assertSame(CHECKED_PAGE_URL_MAX_LENGTH, mb_strlen($normalizedLong, 'UTF-8'));

        $dirtyUrl = "https://shop.test/\xC0\xD2/item";
        $normalizedDirty = normalizeCheckedPageUrl($dirtyUrl);
        $this->assertTrue(mb_check_encoding($normalizedDirty, 'UTF-8'));
        $this->assertStringStartsWith('https://shop.test/', $normalizedDirty);
        $this->assertStringEndsWith('/item', $normalizedDirty);
        $this->assertStringNotContainsString("\xC0", $normalizedDirty);
    }
}
