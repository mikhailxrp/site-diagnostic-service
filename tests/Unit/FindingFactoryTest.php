<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/src/Services/FindingFactory.php';

final class FindingFactoryTest extends TestCase
{
    public function testSeverityComesFromCategory(): void
    {
        $finding = makeFinding('broken_resource', 'Не загружается style.css', 'https://shop.ru/style.css', '404');

        $this->assertSame('medium', $finding['severity']);
        $this->assertSame('check', $finding['source']);
        $this->assertSame('broken_resource', $finding['category']);
        $this->assertNull($finding['page_id']);
    }

    public function testSeverityCanBeOverridden(): void
    {
        $finding = makeFinding('ssl', 'Сертификат истёк', 'https://shop.ru/', 'expired', severity: 'critical');

        $this->assertSame('critical', $finding['severity']);
    }

    public function testAllFieldsArePassedThrough(): void
    {
        $finding = makeFinding(
            'php_error',
            'Fatal error на главной',
            'https://shop.ru/',
            'fatal',
            'Путь: /var/www/index.php',
            ['fragment' => 'Fatal error: …'],
            12
        );

        $this->assertSame('Путь: /var/www/index.php', $finding['details']);
        $this->assertSame(['fragment' => 'Fatal error: …'], $finding['evidence']);
        $this->assertSame(12, $finding['page_id']);
        $this->assertSame(40, strlen($finding['fingerprint']));
    }

    public function testUnknownCategoryIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        makeFinding('weird', 'Что-то', null);
    }

    public function testUnknownSeverityIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        makeFinding('ssl', 'Что-то', null, severity: 'urgent');
    }

    public function testEveryDefaultSeverityIsValid(): void
    {
        foreach (FINDING_DEFAULT_SEVERITY as $severity) {
            $this->assertContains($severity, FINDING_SEVERITIES);
        }
    }

    public function testLongTitleIsTruncated(): void
    {
        $finding = makeFinding('seo', str_repeat('я', FINDING_TITLE_MAX_LENGTH + 10), null);

        $this->assertSame(FINDING_TITLE_MAX_LENGTH, mb_strlen($finding['title']));
    }

    public function testLongDetailsIsTruncatedByBytesAndRemainsValidUtf8(): void
    {
        $raw = str_repeat('я', 50000) . "\xC0" . str_repeat('я', 50000);
        $finding = makeFinding('vulnerability', 'Много уязвимостей', null, 'many', $raw);
        $nullFinding = makeFinding('seo', 'Без описания', null, 'none', null);

        $this->assertNotNull($finding['details']);
        $this->assertLessThanOrEqual(FINDING_DETAILS_MAX_BYTES, strlen($finding['details']));
        $this->assertGreaterThanOrEqual(FINDING_DETAILS_MAX_BYTES - 4, strlen($finding['details']));
        $this->assertTrue(mb_check_encoding($finding['details'], 'UTF-8'));
        $this->assertNull($nullFinding['details']);
    }

    public function testInvalidUtf8InTitleAndLocationIsScrubbedAndTruncated(): void
    {
        $dirtyTitle = "Заголовок \xC0 " . str_repeat('б', FINDING_TITLE_MAX_LENGTH + 20);
        $dirtyLocation = "https://shop.test/\xC0/" . str_repeat('x', FINDING_LOCATION_MAX_LENGTH + 20);

        $finding = makeFinding('broken_resource', $dirtyTitle, $dirtyLocation, '404');

        $this->assertTrue(mb_check_encoding($finding['title'], 'UTF-8'));
        $this->assertSame(FINDING_TITLE_MAX_LENGTH, mb_strlen($finding['title'], 'UTF-8'));
        $this->assertNotNull($finding['location']);
        $this->assertTrue(mb_check_encoding($finding['location'], 'UTF-8'));
        $this->assertSame(FINDING_LOCATION_MAX_LENGTH, mb_strlen($finding['location'], 'UTF-8'));
    }

    public function testFingerprintIsStableForSameFinding(): void
    {
        $this->assertSame(
            buildFingerprint('broken_resource', 'https://shop.ru/a.css', '404'),
            buildFingerprint('broken_resource', 'https://shop.ru/a.css', '404')
        );
    }

    public function testFingerprintIgnoresHostCaseFragmentAndSpaces(): void
    {
        $this->assertSame(
            buildFingerprint('broken_resource', 'https://shop.ru/a.css', '404'),
            buildFingerprint('broken_resource', '  HTTPS://Shop.RU/a.css#top ', '404')
        );
    }

    public function testFingerprintKeepsPathCase(): void
    {
        $this->assertNotSame(
            buildFingerprint('broken_resource', 'https://shop.ru/A.css', '404'),
            buildFingerprint('broken_resource', 'https://shop.ru/a.css', '404')
        );
    }

    public function testFingerprintDiffersForDifferentFindings(): void
    {
        $base = buildFingerprint('broken_resource', 'https://shop.ru/a.css', '404');

        $this->assertNotSame($base, buildFingerprint('php_error', 'https://shop.ru/a.css', '404'));
        $this->assertNotSame($base, buildFingerprint('broken_resource', 'https://shop.ru/b.css', '404'));
        $this->assertNotSame($base, buildFingerprint('broken_resource', 'https://shop.ru/a.css', '500'));
    }

    public function testAiFindingHasSourceResultIdAndEvidenceLinks(): void
    {
        $finding = makeAiFinding('hypothesis', 'Корзина  падает', 'Почему', 'critical', ['finding_ids' => [3]], 9);

        $this->assertSame('ai', $finding['source']);
        $this->assertSame(9, $finding['ai_result_id']);
        $this->assertSame('critical', $finding['severity']);
        $this->assertSame(['finding_ids' => [3]], $finding['evidence']);
        $this->assertNull($finding['location']);
        $this->assertSame(
            makeAiFinding('hypothesis', ' корзина падает ', null, 'low', [], 1)['fingerprint'],
            $finding['fingerprint']
        );
    }

    public function testLayoutAiFindingKeepsPageAndDiffersByPage(): void
    {
        $home = makeAiFinding('layout', 'Слайдер пустой', null, 'high', ['screenshot_ids' => [31]], 8, 'https://shop.ru/', 1);
        $cart = makeAiFinding('layout', 'Слайдер пустой', null, 'high', ['screenshot_ids' => [41]], 8, 'https://shop.ru/cart', 2);

        $this->assertSame('https://shop.ru/', $home['location']);
        $this->assertSame(1, $home['page_id']);
        $this->assertSame(['screenshot_ids' => [31]], $home['evidence']);
        $this->assertNotSame($home['fingerprint'], $cart['fingerprint']);
    }

    public function testAiFindingRejectsUnknownSeverity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        makeAiFinding('hypothesis', 'Гипотеза', null, 'катастрофа', [], 1);
    }

    public function testNonUrlLocationIsOnlyTrimmed(): void
    {
        $this->assertSame('/var/www/Index.php', normalizeFindingLocation(' /var/www/Index.php '));
        $this->assertSame('', normalizeFindingLocation(null));
    }
}
