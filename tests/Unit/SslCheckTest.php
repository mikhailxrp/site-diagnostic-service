<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/src/Services/Checks/SslCheck.php';

/**
 * Без сети: сертификаты — заготовленные массивы как у `openssl_x509_parse`.
 */
final class SslCheckTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private function certificate(int $daysLeft, string $san = 'DNS:sudar.su, DNS:www.sudar.su', string $cn = 'sudar.su'): array
    {
        return [
            'subject'          => ['CN' => $cn],
            'issuer'           => ['C' => 'US', 'O' => "Let's Encrypt", 'CN' => 'R11'],
            'validFrom_time_t' => self::NOW - 60 * SECONDS_IN_DAY,
            'validTo_time_t'   => self::NOW + $daysLeft * SECONDS_IN_DAY + 60,
            'extensions'       => ['subjectAltName' => $san],
        ];
    }

    private function keys(array $result): array
    {
        return array_map(
            static fn (array $finding): string => $finding['severity'] . ':' . $finding['title'],
            $result['findings']
        );
    }

    public function testValidCertificateHasNoFindings(): void
    {
        $result = evaluateCertificate($this->certificate(60), 'sudar.su', true, self::NOW);

        $this->assertSame([], $result['findings']);
        $this->assertSame(60, $result['facts']['days_left']);
        $this->assertSame("Let's Encrypt", $result['facts']['issuer']);
        $this->assertSame(['sudar.su', 'www.sudar.su'], $result['facts']['domains']);
        $this->assertTrue($result['facts']['domain_match']);
        $this->assertTrue($result['facts']['trusted']);
    }

    public function testExpiredIsCritical(): void
    {
        $result = evaluateCertificate($this->certificate(-3), 'sudar.su', false, self::NOW);

        $this->assertCount(1, $result['findings'], 'просроченный не даёт второй находки «не доверена»');
        $this->assertSame('critical', $result['findings'][0]['severity']);
        $this->assertSame('ssl', $result['findings'][0]['category']);
        $this->assertLessThan(0, $result['facts']['days_left']);
    }

    public function testExpiringSoonIsMedium(): void
    {
        $result = evaluateCertificate($this->certificate(SSL_EXPIRY_WARNING_DAYS - 1), 'sudar.su', true, self::NOW);

        $this->assertCount(1, $result['findings']);
        $this->assertSame('medium', $result['findings'][0]['severity']);
    }

    public function testFourteenDaysLeftIsFine(): void
    {
        $result = evaluateCertificate($this->certificate(SSL_EXPIRY_WARNING_DAYS), 'sudar.su', true, self::NOW);

        $this->assertSame([], $result['findings']);
    }

    public function testDomainMismatchIsHigh(): void
    {
        $result = evaluateCertificate($this->certificate(60, 'DNS:other.ru'), 'sudar.su', false, self::NOW);

        $this->assertFalse($result['facts']['domain_match']);
        $this->assertSame(['high:SSL-сертификат выдан на другой домен'], $this->keys($result));
    }

    public function testCommonNameUsedWithoutAltNames(): void
    {
        $result = evaluateCertificate($this->certificate(60, '', 'sudar.su'), 'sudar.su', true, self::NOW);

        $this->assertTrue($result['facts']['domain_match']);
    }

    public function testUntrustedChainIsHigh(): void
    {
        $result = evaluateCertificate($this->certificate(60), 'sudar.su', false, self::NOW);

        $this->assertSame(['high:Цепочка SSL-сертификата не доверена'], $this->keys($result));
    }

    public static function wildcardCases(): array
    {
        return [
            'поддомен'            => [['*.sudar.su'], 'shop.sudar.su', true],
            'сам домен'           => [['*.sudar.su'], 'sudar.su', false],
            'два уровня'          => [['*.sudar.su'], 'a.shop.sudar.su', false],
            'регистр'             => [['SUDAR.SU'], 'sudar.su', true],
            'другой домен'        => [['*.other.ru'], 'shop.sudar.su', false],
            'похожий суффикс'     => [['*.sudar.su'], 'xsudar.su', false],
        ];
    }

    #[DataProvider('wildcardCases')]
    public function testCertificateMatchesHost(array $patterns, string $host, bool $expected): void
    {
        $this->assertSame($expected, certificateMatchesHost($patterns, $host));
    }

    public function testFetchRefusesPrivateHost(): void
    {
        $connected = false;
        $result = fetchPeerCertificate(
            'shop.ru',
            SSL_DEFAULT_PORT,
            static fn (string $host): array => ['10.0.0.5'],
            static function () use (&$connected): array {
                $connected = true;

                return ['certificate' => null, 'error' => null];
            }
        );

        $this->assertFalse($connected, 'на частный адрес соединения нет');
        $this->assertNull($result['certificate']);
        $this->assertNotNull($result['error']);
    }

    public function testFetchRetriesOnceAfterDroppedConnection(): void
    {
        $calls = 0;
        $result = fetchPeerCertificate(
            'sudar.su',
            SSL_DEFAULT_PORT,
            static fn (string $host): array => ['93.184.216.34'],
            function (array $target) use (&$calls): array {
                $calls++;

                return $calls === 1
                    ? ['certificate' => null, 'error' => 'Unknown error']
                    : ['certificate' => $this->certificate(60), 'error' => null];
            }
        );

        $this->assertSame(2, $calls);
        $this->assertNotNull($result['certificate']);
        $this->assertNull($result['error']);
    }

    public function testFetchGivesUpAfterAttempts(): void
    {
        $calls = 0;
        $result = fetchPeerCertificate(
            'sudar.su',
            SSL_DEFAULT_PORT,
            static fn (string $host): array => ['93.184.216.34'],
            static function () use (&$calls): array {
                $calls++;

                return ['certificate' => null, 'error' => 'Connection refused'];
            }
        );

        $this->assertSame(SSL_CONNECT_ATTEMPTS, $calls);
        $this->assertSame('Connection refused', $result['error']);
    }
}
