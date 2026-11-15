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
