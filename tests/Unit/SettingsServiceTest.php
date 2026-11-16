<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/src/Services/SettingsService.php';

final class SettingsServiceTest extends TestCase
{
    public static function validModelNames(): array
    {
        return [
            'с провайдером'   => ['openai/gpt-4o-mini'],
            'с двоеточием'    => ['meta-llama/llama-3.1-8b-instruct:free'],
            'с точкой и _'    => ['claude_3.5'],
            'ровно 150'       => [str_repeat('a', MODEL_NAME_MAX_LENGTH)],
        ];
    }

    #[DataProvider('validModelNames')]
    public function testAcceptsValidModelName(string $name): void
    {
        $this->assertNull(validateModelName($name));
    }

    public static function invalidModelNames(): array
    {
        return [
            'пусто'          => [''],
            '151 символ'     => [str_repeat('a', MODEL_NAME_MAX_LENGTH + 1)],
            'пробел внутри'  => ['gpt 4o'],
            'кириллица'      => ['модель'],
            'html'           => ['<script>'],
            'перевод строки' => ["gpt-4o\n"],
        ];
    }

    #[DataProvider('invalidModelNames')]
    public function testRejectsInvalidModelName(string $name): void
    {
        $this->assertIsString(validateModelName($name));
    }

    public function testValidateInputTrimsAndReportsEachField(): void
    {
        $result = validateSettingsInput([
            'model_problem' => '  openai/gpt-4o  ',
            'model_visual'  => '   ',
            'model_summary' => ['not-a-string'],
            'unknown_key'   => 'ignored',
        ]);

        $this->assertSame(
            ['model_problem' => 'openai/gpt-4o', 'model_visual' => '', 'model_summary' => ''],
            $result['values']
        );
        $this->assertSame(['model_visual', 'model_summary'], array_keys($result['errors']));
    }

    public function testValidateInputWithoutErrors(): void
    {
        $result = validateSettingsInput([
            'model_problem' => 'a/b',
            'model_visual'  => 'c/d',
            'model_summary' => 'e/f',
        ]);

        $this->assertSame([], $result['errors']);
    }

    public static function validChromePaths(): array
    {
        return [
            'пусто — по умолчанию' => [''],
            'стандартный'          => [CHROME_DEFAULT_PATH],
            'прямые слэши'         => ['C:/Program Files/Google/Chrome/Application/chrome.exe'],
            'другой диск, EXE'     => ['D:\\Portable\\Chromium\\CHROME.EXE'],
        ];
    }

    #[DataProvider('validChromePaths')]
    public function testAcceptsValidChromePath(string $path): void
    {
        $this->assertNull(validateChromePath($path));
    }

    public static function invalidChromePaths(): array
    {
        return [
            'относительный'  => ['chrome.exe'],
            'без диска'      => ['\\Program Files\\chrome.exe'],
            'не exe'         => ['C:\\Program Files\\Google\\Chrome\\Application\\chrome.bat'],
            'папка'          => ['C:\\Program Files\\Google\\Chrome\\Application\\'],
            'кавычки'        => ['"C:\\Program Files\\chrome.exe"'],
            'перевод строки' => ["C:\\chrome.exe\n"],
            'амперсанд-пайп' => ['C:\\a|b\\chrome.exe'],
            '256 символов'   => ['C:\\' . str_repeat('a', CHROME_PATH_MAX_LENGTH - 6) . '.exe'],
        ];
    }

    #[DataProvider('invalidChromePaths')]
    public function testRejectsInvalidChromePath(string $path): void
    {
        $this->assertIsString(validateChromePath($path));
    }

    public function testBrowserInputTrimsAndRejectsNonString(): void
    {
        $this->assertSame(
            ['value' => CHROME_DEFAULT_PATH, 'error' => null],
            validateBrowserSettingsInput(['chrome_path' => '  ' . CHROME_DEFAULT_PATH . ' '])
        );
        $this->assertIsString(validateBrowserSettingsInput(['chrome_path' => ['x']])['error']);
        $this->assertNull(validateBrowserSettingsInput([])['error']);
    }

    public function testResolveChromePathFallsBackToDefault(): void
    {
        $this->assertSame(CHROME_DEFAULT_PATH, resolveChromePath(null));
        $this->assertSame(CHROME_DEFAULT_PATH, resolveChromePath('  '));
        $this->assertSame('D:\\Chrome\\chrome.exe', resolveChromePath('D:\\Chrome\\chrome.exe'));
    }

    public function testMaskSecretEmptyIsNotSet(): void
    {
        $this->assertNull(maskSecret(''));
    }

    public function testMaskSecretShortIsFullyHidden(): void
    {
        $this->assertSame(SECRET_FULL_MASK, maskSecret('12345678'));
    }

    public function testMaskSecretKeepsPrefixAndSuffix(): void
    {
        $masked = maskSecret('sk-verysecretvalue-a1b2');

        $this->assertSame('sk-…a1b2', $masked);
        $this->assertStringNotContainsString('verysecret', $masked);
    }
}
