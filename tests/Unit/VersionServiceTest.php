<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/src/Services/VersionService.php';

final class VersionServiceTest extends TestCase
{
    /**
     * @return array<string, array{?string, ?string}>
     */
    public static function versions(): array
    {
        return [
            'обычная'           => ['3.6.0', '3.6.0'],
            'с v'               => ['v2.1', '2.1'],
            'суффикс сборки'    => ['1.12.4-aem', '1.12.4'],
            'патч Magento'      => ['2.4.7-p3', '2.4.7-p3'],
            'четыре части'      => ['4.1.0.4', '4.1.0.4'],
            'rc без суффикса'   => ['4.2.0-rc1', '4.2.0'],
            'одно число'        => ['4', null],
            'метка времени'     => ['20231105', null],
            'хэш'               => ['a8f3c2d1', null],
            'пять частей'       => ['1.2.3.4.5', null],
            'пусто'             => ['', null],
            'null'              => [null, null],
        ];
    }

    #[DataProvider('versions')]
    public function testNormalizeVersion(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, normalizeVersion($input));
    }

    public function testStableVersions(): void
    {
        $this->assertTrue(isStableVersion('2.4.7-p3'));
        $this->assertTrue(isStableVersion('6.2.0'));
        foreach (['2.4.10-alpha1', '6.0.0-beta2', '4.2.0-rc1', '4.2.0-RC1', 'dev-master', '1.0.0-dev', 'abc'] as $version) {
            $this->assertFalse(isStableVersion($version), $version);
        }
    }

    public function testCompareVersions(): void
    {
        $this->assertLessThan(0, compareVersions('1.12.4', '3.0.0'));
        $this->assertLessThan(0, compareVersions('2.4.7', '2.4.7-p3'));
        $this->assertLessThan(0, compareVersions('2.4.7-p3', '2.4.8'));
        $this->assertSame(0, compareVersions('v3.6.0', '3.6.0'));
        $this->assertGreaterThan(0, compareVersions('4.10.0', '4.9.9'));
    }

    public function testPickLatestStableIgnoresPreReleasesAndOrder(): void
    {
        $this->assertSame('2.4.9', pickLatestStableVersion(['2.4.10-alpha1', '2.4.7-p8', '2.4.9', '2.4.8-p3']));
        $this->assertNull(pickLatestStableVersion(['dev-master', '1.0.0-rc1']));
        $this->assertNull(pickLatestStableVersion([]));
    }

    public function testBranches(): void
    {
        $this->assertSame(3, getMajorVersion('3.6.0'));
        $this->assertSame('8.2', getMinorBranch('8.2.35'));
        $this->assertSame('2.4', getMinorBranch('2.4.7-p3'));
        $this->assertSame('7.4', getMinorBranch('7.4'));
    }
}
