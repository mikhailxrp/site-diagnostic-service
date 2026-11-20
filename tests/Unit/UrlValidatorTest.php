<?php

declare(strict_types=1);

namespace Tests\Unit;

use ErrorException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/src/Services/UrlValidator.php';

final class UrlValidatorTest extends TestCase
{
    private const PUBLIC_IP = '93.184.216.34';

    public static function normalizedUrls(): array
    {
        return [
            'без схемы'                => ['shop.ru', 'https://shop.ru/', 'shop.ru'],
            'с пробелами по краям'     => ['  shop.ru  ', 'https://shop.ru/', 'shop.ru'],
            'регистр и фрагмент'       => ['http://Shop.RU/catalog#x', 'http://shop.ru/catalog', 'shop.ru'],
            'кириллический домен'      => ['магазин.рф', 'https://xn--80aairftm.xn--p1ai/', 'xn--80aairftm.xn--p1ai'],
            'с www'                    => ['https://www.sudar.su', 'https://www.sudar.su/', 'www.sudar.su'],
            'с портом без схемы'       => ['shop.ru:8080/cart', 'https://shop.ru:8080/cart', 'shop.ru'],
            'с query'                  => ['https://shop.ru/?route=common/home', 'https://shop.ru/?route=common/home', 'shop.ru'],
            'протокол-относительная'   => ['//shop.ru/a', 'https://shop.ru/a', 'shop.ru'],
            'точка в конце хоста'      => ['shop.ru.', 'https://shop.ru/', 'shop.ru'],
            'IPv6 в скобках'           => ['http://[2606:4700::1111]/', 'http://[2606:4700::1111]/', '2606:4700::1111'],
        ];
    }

    #[DataProvider('normalizedUrls')]
    public function testNormalizeSiteUrl(string $raw, string $expectedUrl, string $expectedHost): void
    {
        $this->assertSame(['url' => $expectedUrl, 'host' => $expectedHost], normalizeSiteUrl($raw));
    }

    public static function rejectedUrls(): array
    {
        return [
            'пусто'             => [''],
            'только пробелы'    => ['   '],
            'ftp'               => ['ftp://shop.ru'],
            'mailto'            => ['mailto:a@shop.ru'],
            'javascript'        => ['javascript:alert(1)'],
            'логин и пароль'    => ['http://user:pass@shop.ru'],
            'только логин'      => ['https://user@shop.ru'],
            'пробел внутри'     => ['shop .ru'],
            'перевод строки'    => ["shop.ru\n/evil"],
            'подчёркивание'     => ['bad_host.ru'],
            'слишком длинная'   => ['https://shop.ru/' . str_repeat('a', SITE_URL_MAX_LENGTH)],
            'без хоста'         => ['https:///path'],
        ];
    }

    #[DataProvider('rejectedUrls')]
    public function testNormalizeSiteUrlRejects(string $raw): void
    {
        $result = normalizeSiteUrl($raw);

        $this->assertArrayHasKey('error', $result);
        $this->assertNotSame('', $result['error']);
    }

    public function testExtractBaseHostRemovesOnlyLeadingWww(): void
    {
        $this->assertSame('sudar.su', extractBaseHost('www.sudar.su'));
        $this->assertSame('sudar.su', extractBaseHost('sudar.su'));
        $this->assertSame('shop.www.ru', extractBaseHost('shop.www.ru'));
        $this->assertSame('citilink.ru', extractBaseHost('WWW.citilink.ru'));
    }

    public static function nonPublicIps(): array
    {
        return [
            'loopback'        => ['127.0.0.1'],
            '10/8'            => ['10.0.0.5'],
            '192.168/16'      => ['192.168.1.1'],
            '172.16/12'       => ['172.16.0.1'],
            'link-local'      => ['169.254.169.254'],
            'CGNAT'           => ['100.64.0.1'],
            'нулевой'         => ['0.0.0.0'],
            'IPv6 loopback'   => ['::1'],
            'IPv6 ULA'        => ['fd00::1'],
            'IPv6 link-local' => ['fe80::1'],
            'IPv4-mapped'     => ['::ffff:127.0.0.1'],
            'не IP'           => ['shop.ru'],
        ];
    }

    #[DataProvider('nonPublicIps')]
    public function testIsPublicIpRejectsNonPublic(string $ip): void
    {
        $this->assertFalse(isPublicIp($ip));
    }

    public function testIsPublicIpAcceptsPublic(): void
    {
        $this->assertTrue(isPublicIp(self::PUBLIC_IP));
        $this->assertTrue(isPublicIp('2606:4700::1111'));
    }

    public function testValidateSiteHostAcceptsPublicDomain(): void
    {
        $this->assertNull(validateSiteHost('shop.ru', $this->resolver([self::PUBLIC_IP])));
    }

    public static function rejectedHosts(): array
    {
        return [
            'localhost'                 => ['localhost', [self::PUBLIC_IP]],
            'поддомен localhost'        => ['app.localhost', [self::PUBLIC_IP]],
            'IP локальной сети'         => ['192.168.1.1', []],
            'loopback'                  => ['127.0.0.1', []],
            'IPv6 loopback'             => ['::1', []],
            'одно слово'                => ['intranet', [self::PUBLIC_IP]],
            'не резолвится'             => ['no-such-shop.ru', []],
            'указывает на частный IP'   => ['evil.ru', ['10.0.0.1']],
            'хотя бы один частный IP'   => ['mixed.ru', [self::PUBLIC_IP, '127.0.0.1']],
        ];
    }

    #[DataProvider('rejectedHosts')]
    public function testValidateSiteHostRejects(string $host, array $ips): void
    {
        $this->assertNotNull(validateSiteHost($host, $this->resolver($ips)));
    }

    public function testValidateSiteHostAcceptsPublicIpLiteralWithoutDns(): void
    {
        $resolver = function (): array {
            $this->fail('Для IP-адреса DNS не нужен');
        };

        $this->assertNull(validateSiteHost(self::PUBLIC_IP, $resolver));
    }

    public function testValidateDiagnosticInputValid(): void
    {
        $result = validateDiagnosticInput(
            ['url' => ' www.sudar.su ', 'problem_text' => "  не оформляется заказ \n"],
            $this->resolver([self::PUBLIC_IP])
        );

        $this->assertSame([], $result['errors']);
        $this->assertSame('https://www.sudar.su/', $result['url']);
        $this->assertSame('sudar.su', $result['host']);
        $this->assertSame('не оформляется заказ', $result['problemText']);
    }

    public function testValidateDiagnosticInputEmptyProblemIsNull(): void
    {
        $result = validateDiagnosticInput(['url' => 'shop.ru', 'problem_text' => '   '], $this->resolver([self::PUBLIC_IP]));

        $this->assertSame([], $result['errors']);
        $this->assertNull($result['problemText']);
    }

    public function testValidateDiagnosticInputProblemLengthLimit(): void
    {
        $resolver = $this->resolver([self::PUBLIC_IP]);

        $atLimit = validateDiagnosticInput(
            ['url' => 'shop.ru', 'problem_text' => str_repeat('ж', PROBLEM_TEXT_MAX_LENGTH)],
            $resolver
        );
        $overLimit = validateDiagnosticInput(
            ['url' => 'shop.ru', 'problem_text' => str_repeat('ж', PROBLEM_TEXT_MAX_LENGTH + 1)],
            $resolver
        );

        $this->assertSame([], $atLimit['errors']);
        $this->assertArrayHasKey('problem_text', $overLimit['errors']);
        $this->assertNull($overLimit['url']);
    }

    public function testValidateDiagnosticInputKeepsValuesOnError(): void
    {
        $result = validateDiagnosticInput(
            ['url' => 'localhost', 'product_url' => 'localhost/item', 'problem_text' => 'текст'],
            $this->resolver([])
        );

        $this->assertArrayHasKey('url', $result['errors']);
        $this->assertSame(
            ['url' => 'localhost', 'product_url' => 'localhost/item', 'problem_text' => 'текст'],
            $result['values']
        );
        $this->assertNull($result['url']);
        $this->assertNull($result['host']);
    }

    public function testValidateDiagnosticInputRejectsInvalidUtf8AndNonStrings(): void
    {
        $result = validateDiagnosticInput(['url' => ['shop.ru'], 'problem_text' => "\xC3\x28"], $this->resolver([]));

        $this->assertArrayHasKey('url', $result['errors']);
        $this->assertArrayHasKey('problem_text', $result['errors']);
        $this->assertSame('', $result['values']['url']);
    }

    public function testEmptyProductUrlIsNull(): void
    {
        $result = validateDiagnosticInput(['url' => 'shop.ru', 'product_url' => '   '], $this->resolver([self::PUBLIC_IP]));

        $this->assertSame([], $result['errors']);
        $this->assertNull($result['productUrl']);
        $this->assertSame('', $result['values']['product_url']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function validProductUrlProvider(): array
    {
        return [
            'без схемы'       => ['shop.ru/product/123', 'https://shop.ru/product/123'],
            'с www'           => ['https://www.shop.ru/catalog/item/', 'https://www.shop.ru/catalog/item/'],
            'с фрагментом'    => ['shop.ru/p/1#reviews', 'https://shop.ru/p/1'],
            'товар по query'  => ['shop.ru/?product_id=42', 'https://shop.ru/?product_id=42'],
            'http'            => ['http://shop.ru/item', 'http://shop.ru/item'],
        ];
    }

    #[DataProvider('validProductUrlProvider')]
    public function testValidProductUrlIsNormalized(string $raw, string $expected): void
    {
        $result = validateDiagnosticInput(['url' => 'shop.ru', 'product_url' => $raw], $this->resolver([self::PUBLIC_IP]));

        $this->assertSame([], $result['errors']);
        $this->assertSame($expected, $result['productUrl']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidProductUrlProvider(): array
    {
        return [
            'чужой домен'  => ['other.ru/product/1'],
            'поддомен'     => ['catalog.shop.ru/product/1'],
            'похожий'      => ['shop.ru.evil.com/product/1'],
            'ftp'          => ['ftp://shop.ru/product/1'],
            'мусор'        => ['http://'],
            'главная'      => ['https://shop.ru/'],
            'главная www'  => ['www.shop.ru'],
            'с паролем'    => ['https://user:pass@shop.ru/p/1'],
        ];
    }

    #[DataProvider('invalidProductUrlProvider')]
    public function testInvalidProductUrlIsRejectedAtItsField(string $raw): void
    {
        $result = validateDiagnosticInput(
            ['url' => 'shop.ru', 'product_url' => $raw, 'problem_text' => 'текст'],
            $this->resolver([self::PUBLIC_IP])
        );

        $this->assertSame(['product_url'], array_keys($result['errors']));
        $this->assertNull($result['url']);
        $this->assertNull($result['productUrl']);
        $this->assertSame($raw, $result['values']['product_url']);
        $this->assertSame('текст', $result['values']['problem_text']);
    }

    public function testForeignDomainErrorNamesTheSite(): void
    {
        $this->assertSame(
            'Адрес товара должен быть с того же сайта: shop.ru.',
            validateProductUrl('other.ru/p/1', 'shop.ru')['error']
        );
    }

    public function testSiteUrlErrorDoesNotBlameValidProductUrl(): void
    {
        $result = validateDiagnosticInput(
            ['url' => 'localhost', 'product_url' => 'shop.ru/product/1'],
            $this->resolver([])
        );

        $this->assertSame(['url'], array_keys($result['errors']));
    }

    public function testResolveHostIpsKeepsIpv4WhenAaaaFailsWithWarningOrException(): void
    {
        $ipv4 = static fn (string $host): array => ['93.184.216.34'];

        $fromWarning = resolveHostIps(
            'shop.ru',
            $ipv4,
            static function (): bool {
                trigger_error('SERVFAIL', E_USER_WARNING);

                return false;
            }
        );
        $this->assertSame(['93.184.216.34'], $fromWarning);

        $fromException = resolveHostIps(
            'shop.ru',
            $ipv4,
            static fn (): array => throw new ErrorException('SERVFAIL')
        );
        $this->assertSame(['93.184.216.34'], $fromException);

        $withIpv6 = resolveHostIps(
            'shop.ru',
            $ipv4,
            static fn (): array => [['ipv6' => '2606:4700::1111'], ['ipv6' => '2606:4700::1111']]
        );
        $this->assertSame(['93.184.216.34', '2606:4700::1111'], $withIpv6);
    }

    /**
     * @param list<string> $ips
     */
    private function resolver(array $ips): callable
    {
        return static fn (string $host): array => $ips;
    }
}
