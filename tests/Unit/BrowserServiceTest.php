<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/src/Core/Logger.php';
require_once ROOT_PATH . '/src/Services/FindingFactory.php';
require_once ROOT_PATH . '/src/Services/Checks/ResourceCheck.php';
require_once ROOT_PATH . '/src/Services/BrowserService.php';

final class BrowserServiceTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/sd-browser-test-' . bin2hex(random_bytes(4));
        mkdir($this->baseDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->baseDir)) {
            removeDirectoryTree($this->baseDir);
        }
    }

    public function testLibraryIsInstalled(): void
    {
        $this->assertTrue(isBrowserLibraryAvailable());
    }

    public function testBrowserOptions(): void
    {
        $options = buildBrowserOptions('C:/temp/chrome-1-ab', 30);

        $this->assertTrue($options['headless']);
        $this->assertSame([BROWSER_WINDOW_WIDTH, BROWSER_WINDOW_HEIGHT], $options['windowSize']);
        $this->assertSame([1366, 768], $options['windowSize']);
        $this->assertSame('C:/temp/chrome-1-ab', $options['userDataDir']);
        $this->assertSame(30, $options['startupTimeout']);
        $this->assertTrue($options['ignoreCertificateErrors']);
        $this->assertFalse($options['keepAlive']);
        $this->assertArrayNotHasKey('userAgent', $options);
    }

    public function testParseBrowserVersion(): void
    {
        $this->assertSame('157.0.8103.52', parseBrowserVersion('Chrome/157.0.8103.52'));
        $this->assertSame('157.0.8103.52', parseBrowserVersion('HeadlessChrome/157.0.8103.52'));
        $this->assertNull(parseBrowserVersion('Chrome'));
    }

    public function testSelectPagesSkipsBotProtection(): void
    {
        $selection = selectBrowserPages([
            ['url' => 'https://shop.test/', 'types' => ['home'], 'page_id' => 1, 'bot_protection' => null, 'body' => '<html>'],
            ['url' => 'https://shop.test/cart/', 'types' => ['cart', 'checkout'], 'page_id' => 2,
                'bot_protection' => ['provider' => 'ddos-guard', 'title' => 'DDoS-Guard', 'reason' => 'заглушка']],
            ['url' => 'https://shop.test/catalog/', 'types' => ['category'], 'page_id' => 3, 'bot_protection' => null],
        ]);

        $this->assertSame(['https://shop.test/', 'https://shop.test/catalog/'], array_column($selection['open'], 'url'));
        $this->assertSame([['url' => 'https://shop.test/cart/', 'types' => ['cart', 'checkout'], 'page_id' => 2]], $selection['blocked']);
        $this->assertArrayNotHasKey('body', $selection['open'][0]);
    }

    public function testPagesFromContextWithoutCrawlAndWithoutResponse(): void
    {
        $onlyHome = selectBrowserPages(collectResourcePages(['facts' => ['availability' => ['home' => [
            'url' => 'https://shop.test', 'final_url' => 'https://shop.test/', 'status' => 200, 'page_id' => 5,
            'bot_protection' => null,
        ]]]]));
        $this->assertSame(['https://shop.test/'], array_column($onlyHome['open'], 'url'));

        $noResponse = selectBrowserPages(collectResourcePages(['facts' => ['availability' => ['home' => [
            'url' => 'https://down.test/', 'final_url' => 'https://down.test/', 'status' => null, 'page_id' => 6,
        ]]]]));
        $this->assertSame(['open' => [], 'blocked' => []], $noResponse);
    }

    public function testStepMessage(): void
    {
        $opened = ['status' => BROWSER_PAGE_OPENED];

        $this->assertSame('Открыто в браузере: 2 из 2 страниц', buildBrowserStepMessage([$opened, $opened], 0));
        $this->assertSame(
            'Открыто в браузере: 1 из 3 страниц; не открылось: 1; пропущено (защита от ботов): 1',
            buildBrowserStepMessage([$opened, ['status' => BROWSER_PAGE_TIMEOUT]], 1)
        );
    }

    public function testCreateAndRemoveProfileDir(): void
    {
        $dir = createBrowserProfileDir(42, $this->baseDir);
        mkdir($dir . '/Default/Cache', 0775, true);
        file_put_contents($dir . '/Default/Cache/data', 'x');

        $this->assertMatchesRegularExpression('~/chrome-42-[0-9a-f]{8}$~', $dir);
        $this->assertTrue(removeBrowserProfileDir($dir, $this->baseDir));
        $this->assertDirectoryDoesNotExist($dir);
        $this->assertTrue(removeBrowserProfileDir($dir, $this->baseDir), 'уже удалённая папка — не ошибка');
    }

    public function testRemoveProfileRefusesForeignDir(): void
    {
        $foreign = $this->baseDir . '/not-a-profile';
        mkdir($foreign);

        $this->expectException(InvalidArgumentException::class);
        removeBrowserProfileDir($foreign, $this->baseDir);
    }

    public function testRemoveStaleProfilesKeepsFreshOnes(): void
    {
        $stale = createBrowserProfileDir(1, $this->baseDir);
        $fresh = createBrowserProfileDir(2, $this->baseDir);
        $other = $this->baseDir . '/other';
        mkdir($other);
        $old = time() - BROWSER_PROFILE_MAX_AGE_SECONDS - 60;
        touch($stale, $old);
        touch($other, $old);

        $this->assertSame(1, removeStaleBrowserProfiles(time(), $this->baseDir));
        $this->assertDirectoryDoesNotExist($stale);
        $this->assertDirectoryExists($fresh);
        $this->assertDirectoryExists($other);
    }
}
