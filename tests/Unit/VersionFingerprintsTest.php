<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/src/Services/VersionFingerprints.php';

/**
 * Отпечатки версий без сети: хэш, пересечение версий, сборка из файлов релизов, настоящие отпечатки OpenCart.
 */
final class VersionFingerprintsTest extends TestCase
{
    private const OPENCART_3038 = ROOT_PATH . '/tests/fixtures/stack/opencart-3.0.3.8/';

    private function fingerprints(): array
    {
        return [
            'a.js'  => ['h1' => ['1.0', '1.1', '1.2'], 'h2' => ['2.0']],
            'b.css' => ['h3' => ['1.1'], 'h4' => ['1.0', '1.2', '2.0']],
        ];
    }

    public function testHashIgnoresWindowsLineEndingsAndBom(): void
    {
        $this->assertSame(hashFingerprintBody("a\nb\n"), hashFingerprintBody("a\r\nb\r\n"));
        $this->assertSame(hashFingerprintBody("a\nb\n"), hashFingerprintBody("\xEF\xBB\xBFa\nb\n"));
        $this->assertNotSame(hashFingerprintBody("a\nb\n"), hashFingerprintBody("a\nb \n"));
    }

    public function testIntersectionGivesSingleVersion(): void
    {
        $match = matchVersionFingerprints($this->fingerprints(), ['a.js' => 'h1', 'b.css' => 'h3']);

        $this->assertSame(['1.1'], $match['versions']);
        $this->assertSame(['a.js', 'b.css'], $match['matched']);
    }

    public function testUnknownHashIsIgnored(): void
    {
        $match = matchVersionFingerprints($this->fingerprints(), ['a.js' => 'h1', 'b.css' => 'changed-on-site']);

        $this->assertSame(['1.0', '1.1', '1.2'], $match['versions'], 'изменённый файл не сужает и не обнуляет');
        $this->assertSame(['a.js'], $match['matched']);
    }

    public function testFilesFromDifferentReleasesGiveNoVersion(): void
    {
        $match = matchVersionFingerprints($this->fingerprints(), ['a.js' => 'h2', 'b.css' => 'h3']);

        $this->assertSame([], $match['versions']);
        $this->assertSame(['a.js', 'b.css'], $match['matched']);
    }

    public function testNothingKnown(): void
    {
        $this->assertSame(['versions' => [], 'matched' => []], matchVersionFingerprints($this->fingerprints(), ['x.js' => 'h1']));
    }

    public function testFormatCandidates(): void
    {
        $this->assertSame('3.0.3.8', formatVersionCandidates(['3.0.3.8']));
        $this->assertSame('3.0.3.3–3.0.3.7', formatVersionCandidates(['3.0.3.3', '3.0.3.5', '3.0.3.7']));
    }

    public function testBuildGroupsVersionsBySameContent(): void
    {
        $files = buildVersionFingerprints([
            '1.10' => ['a.js' => "same\r\n", 'b.css' => null],
            '1.9'  => ['a.js' => "same\n", 'b.css' => 'old'],
        ]);

        $this->assertSame([hashFingerprintBody("same\n") => ['1.9', '1.10']], $files['a.js'], 'одно содержимое — один хэш, версии по возрастанию');
        $this->assertSame([hashFingerprintBody('old') => ['1.9']], $files['b.css'], 'файла нет в релизе — не отпечаток');
    }

    public function testRealOpencartFingerprints(): void
    {
        $fingerprints = loadVersionFingerprints('opencart');

        $this->assertNotNull($fingerprints);
        $this->assertArrayHasKey('admin/view/javascript/common.js', $fingerprints);
        $this->assertArrayHasKey('catalog/view/stylesheet/stylesheet.css', $fingerprints, 'файл OpenCart 4');

        $hashes = [];
        foreach (array_keys($fingerprints) as $path) {
            if (is_file(self::OPENCART_3038 . $path)) {
                $hashes[$path] = hashFingerprintBody((string) file_get_contents(self::OPENCART_3038 . $path));
            }
        }
        $this->assertSame(['3.0.3.8'], matchVersionFingerprints($fingerprints, $hashes)['versions']);
    }

    public function testBadNameIsNotLoaded(): void
    {
        $this->assertNull(loadVersionFingerprints('../signatures'));
        $this->assertNull(loadVersionFingerprints('no-such-cms'));
    }
}
