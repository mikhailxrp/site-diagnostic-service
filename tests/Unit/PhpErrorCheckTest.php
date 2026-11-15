<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/src/Services/Checks/PhpErrorCheck.php';

final class PhpErrorCheckTest extends TestCase
{
    private const FIXTURES = ROOT_PATH . '/tests/fixtures/resources/';
    private const PAGE_URL = 'https://shop.test/';

    /**
     * @return array<string, array>
     */
    private function findingsByType(string $html): array
    {
        return array_column(findPhpErrors($html, self::PAGE_URL, 3), null, 'title');
    }

    public function testFindsErrorsWithServerPathInDetails(): void
    {
        $findings = findPhpErrors((string) file_get_contents(self::FIXTURES . 'php-errors.html'), self::PAGE_URL, 3);

        $this->assertCount(3, $findings);

        $warning = $findings[0];
        $this->assertSame('php_error', $warning['category']);
        $this->assertSame('medium', $warning['severity']);
        $this->assertSame('Warning', $warning['evidence']['type']);
        $this->assertSame('Undefined variable $price', $warning['evidence']['message']);
        $this->assertSame(42, $warning['evidence']['line']);
        $this->assertStringContainsString(
            '/var/www/shop/public_html/catalog/view/theme/default/template/product.php',
            (string) $warning['details']
        );
        $this->assertSame('PHP Warning в product.php:42', $warning['title']);
        $this->assertSame(self::PAGE_URL, $warning['location']);
        $this->assertSame(3, $warning['page_id']);
    }

    public function testSameErrorIsCountedOnce(): void
    {
        $findings = findPhpErrors((string) file_get_contents(self::FIXTURES . 'php-errors.html'), self::PAGE_URL);

        $this->assertSame(2, $findings[0]['evidence']['count']);
        $this->assertStringContainsString('Повторяется 2 раз', (string) $findings[0]['details']);
    }

    public function testSeverityDependsOnType(): void
    {
        $findings = findPhpErrors((string) file_get_contents(self::FIXTURES . 'php-errors.html'), self::PAGE_URL);
        $types = array_column(array_column($findings, 'evidence'), 'type');

        $this->assertSame(['Warning', 'Deprecated', 'Fatal error'], $types);
        $this->assertSame(['medium', 'low', 'critical'], array_column($findings, 'severity'));
    }

    public function testUncaughtExceptionWithWindowsPath(): void
    {
        $findings = findPhpErrors((string) file_get_contents(self::FIXTURES . 'php-errors.html'), self::PAGE_URL);
        $fatal = $findings[2]['evidence'];

        $this->assertSame('C:\OSPanel\home\shop\wp-content\themes\shop\functions.php', $fatal['file']);
        $this->assertSame(118, $fatal['line']);
        $this->assertStringStartsWith('Uncaught Error: Call to undefined function wc_price()', $fatal['message']);
        $this->assertStringContainsString('Stack trace', $fatal['fragment']);
    }

    public function testFragmentHasNoTagsAndIsLimited(): void
    {
        $html = str_repeat('<p>Текст каталога</p>', 100)
            . '<b>Notice</b>:  Undefined offset: 1 in <b>/home/u/site/a.php</b> on line <b>5</b>'
            . str_repeat('<p>Подвал сайта</p>', 100);

        $fragment = findPhpErrors($html, self::PAGE_URL)[0]['evidence']['fragment'];

        $this->assertStringNotContainsString('<', $fragment);
        $this->assertStringContainsString('Notice: Undefined offset: 1 in /home/u/site/a.php on line 5', $fragment);
        $this->assertLessThanOrEqual(
            2 * PHP_ERROR_CONTEXT_CHARS + mb_strlen('Notice: Undefined offset: 1 in /home/u/site/a.php on line 5') + 10,
            mb_strlen($fragment)
        );
    }

    public function testPlainTextWithWarningWordsIsNotAnError(): void
    {
        $this->assertSame([], findPhpErrors((string) file_get_contents(self::FIXTURES . 'no-php-errors.html'), self::PAGE_URL));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function falsePositiveProvider(): array
    {
        return [
            'слово warning'          => ['<p>Warning: hot surface</p>'],
            'путь без строки'        => ['<p>Warning: see /docs/install.php for details</p>'],
            'строка без файла php'   => ['<p>Notice: read in /news/today on line 5</p>'],
            'тип с маленькой буквы'  => ['<p>warning: Undefined variable in /var/www/a.php on line 3</p>'],
            'пусто'                  => [''],
            'мусор'                  => ["<<<>>>\x00\x01"],
        ];
    }

    #[DataProvider('falsePositiveProvider')]
    public function testNoFalsePositives(string $html): void
    {
        $this->assertSame([], findPhpErrors($html, self::PAGE_URL));
    }

    public function testWindows1251PageIsReadable(): void
    {
        $html = '<html><head><meta http-equiv="Content-Type" content="text/html; charset=windows-1251"></head><body>'
            . mb_convert_encoding('<p>Каталог</p>', 'Windows-1251', 'UTF-8')
            . '<b>Warning</b>:  Division by zero in <b>/var/www/cart.php</b> on line <b>9</b></body></html>';

        $findings = findPhpErrors($html, self::PAGE_URL);

        $this->assertCount(1, $findings);
        $this->assertTrue(mb_check_encoding((string) $findings[0]['details'], 'UTF-8'));
    }

    public function testDifferentLinesAreDifferentFindings(): void
    {
        $html = '<b>Warning</b>:  A in <b>/var/www/a.php</b> on line <b>1</b><br />'
            . '<b>Warning</b>:  A in <b>/var/www/a.php</b> on line <b>2</b>';

        $findings = $this->findingsByType($html);

        $this->assertCount(2, $findings);
        $this->assertNotSame(
            array_values($findings)[0]['fingerprint'],
            array_values($findings)[1]['fingerprint']
        );
    }
}
