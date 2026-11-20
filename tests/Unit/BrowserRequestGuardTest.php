<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once ROOT_PATH . '/src/Services/BrowserRequestGuard.php';

final class BrowserRequestGuardTest extends TestCase
{
    public static function fakeResolver(string $host): array
    {
        return match ($host) {
            'service-diagnostic-site.local' => ['127.127.126.54'],
            'mixed-dns.test'                => ['93.184.216.34', '127.0.0.1'],
            'missing.test'                  => [],
            default                         => ['93.184.216.34'],
        };
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function blockedBrowserUrls(): array
    {
        return [
            'loopback ipv4'         => ['http://127.0.0.1/'],
            'loopback port'         => ['http://127.0.0.1:8080/a'],
            'localhost'             => ['http://localhost/'],
            'sub localhost'         => ['http://sub.localhost/'],
            'local vhost'           => ['https://service-diagnostic-site.local/settings'],
            'ipv6 loopback'         => ['http://[::1]/'],
            'ipv4-mapped loopback'  => ['http://[::ffff:127.0.0.1]/'],
            'ipv4-mapped hex'       => ['http://[::ffff:7f00:1]/'],
            'private 192.168'       => ['http://192.168.0.1/'],
            'private 10'            => ['http://10.0.0.1/'],
            'private 172.16'        => ['http://172.16.0.1/'],
            'link-local metadata'   => ['http://169.254.169.254/'],
            'unspecified 0.0.0.0'   => ['http://0.0.0.0/'],
            'file scheme'           => ['file:///C:/Windows/win.ini'],
            'ftp scheme'            => ['ftp://shop.test/a'],
            'chrome scheme'         => ['chrome://settings'],
            'about version'         => ['about:version'],
            'javascript scheme'     => ['javascript:alert(1)'],
            'credentials in url'    => ['http://user:pass@shop.test/'],
            'backslash at bypass'   => ['http://127.0.0.1\@shop.test/'],
            'empty resolver result' => ['https://missing.test/'],
            'mixed public and local' => ['https://mixed-dns.test/'],
            'empty string'          => [''],
            'whitespace in url'     => ["https://shop.test/\n"],
            'port zero'             => ['https://shop.test:0/'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function allowedBrowserUrls(): array
    {
        return [
            'https public'       => ['https://shop.test/catalog'],
            'trailing dot host'  => ['http://example.com./a'],
            'wss public'         => ['wss://ws.shop.test/live'],
            'public ipv6'        => ['https://[2001:4860:4860::8888]/'],
            'data svg multiline' => ["data:image/svg+xml,<svg>\n</svg>"],
            'blob url'           => ['blob:https://shop.test/uuid'],
            'about blank'        => ['about:blank'],
            'about srcdoc'       => ['about:srcdoc'],
        ];
    }

    #[DataProvider('blockedBrowserUrls')]
    public function testBlockedBrowserUrlsAreRejected(string $url): void
    {
        $this->assertFalse(isBrowserRequestAllowed($url, [self::class, 'fakeResolver']), $url);
    }

    #[DataProvider('allowedBrowserUrls')]
    public function testAllowedBrowserUrlsAreAccepted(string $url): void
    {
        $this->assertTrue(isBrowserRequestAllowed($url, [self::class, 'fakeResolver']), $url);
    }

    public function testThrowingResolverFailsClosed(): void
    {
        $throwing = static function (string $host): array {
            throw new RuntimeException('DNS failed for ' . $host);
        };

        $this->assertFalse(isBrowserRequestAllowed('https://shop.test/', $throwing));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function allowedPdfUrls(): array
    {
        return [
            'windows encoded path' => ['file:///C:/OS%20Panel/site/public/assets/css/print.css'],
            'posix path'           => ['file:///tmp/report.html'],
            'empty data uri'       => ['data:,'],
            'svg data uri'         => ['data:image/svg+xml;utf8,<svg/>'],
            'about blank'          => ['about:blank'],
            'about srcdoc'         => ['about:srcdoc'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function blockedPdfUrls(): array
    {
        return [
            'https external'      => ['https://shop.test/img.png'],
            'http loopback'       => ['http://127.0.0.1/settings'],
            'wss external'        => ['wss://shop.test/'],
            'unc ip share'        => ['file://127.0.0.1/c$/win.ini'],
            'unc host share'      => ['file://evil.test/share/a.css'],
            'four slash unc'      => ['file:////127.0.0.1/c$/win.ini'],
            'path traversal'      => ['file:///C:/dir/../../.env'],
            'encoded traversal'   => ['file:///C:/dir/%2e%2e/.env'],
            'blob url'            => ['blob:null/1'],
            'chrome version'      => ['chrome://version'],
            'about version'       => ['about:version'],
            'control char'        => ["file:///C:/dir/a\n.css"],
        ];
    }

    #[DataProvider('allowedPdfUrls')]
    public function testAllowedPdfUrlsAreAccepted(string $url): void
    {
        $this->assertTrue(isBrowserPdfRequestAllowed($url), $url);
    }

    #[DataProvider('blockedPdfUrls')]
    public function testBlockedPdfUrlsAreRejected(string $url): void
    {
        $this->assertFalse(isBrowserPdfRequestAllowed($url), $url);
    }

    public function testCachedResolverReusesWithinTtlRefreshesAfterTtlAndDoesNotCacheExceptions(): void
    {
        $now = 1000;
        $calls = 0;
        $shouldThrow = false;

        $inner = static function (string $host) use (&$calls, &$shouldThrow): array {
            $calls++;
            if ($shouldThrow) {
                throw new RuntimeException('DNS timeout');
            }

            return $host === 'shop.test' ? ['93.184.216.34'] : ['93.184.216.35'];
        };

        $cached = createCachedBrowserResolver($inner, 15, static function () use (&$now): int {
            return $now;
        });

        $this->assertSame(['93.184.216.34'], $cached('shop.test'));
        $now = 1014;
        $this->assertSame(['93.184.216.34'], $cached('shop.test'));
        $this->assertSame(1, $calls, 'в пределах TTL резолвер вызывается один раз');

        $now = 1015;
        $this->assertSame(['93.184.216.34'], $cached('shop.test'));
        $this->assertSame(2, $calls, 'после истечения TTL кэш обновляется');

        $shouldThrow = true;
        try {
            $cached('failing.test');
            $this->fail('Ожидалось исключение резолвера.');
        } catch (RuntimeException) {
            // ожидаемо
        }
        $this->assertSame(3, $calls);

        $shouldThrow = false;
        $this->assertSame(['93.184.216.35'], $cached('failing.test'));
        $this->assertSame(4, $calls, 'исключение не кэшируется');
    }

    public function testSanitizeBrowserLogUrlStripsQueryFragmentAndDataPayload(): void
    {
        $this->assertSame(
            'https://shop.test/checkout',
            sanitizeBrowserLogUrl('https://shop.test/checkout?token=secret#frag')
        );
        $this->assertSame('data:...', sanitizeBrowserLogUrl('data:text/html,secret_payload'));
    }
}
