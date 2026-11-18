<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/src/Services/DiagnosticRunner.php';

/**
 * Результат шага `checkout` по готовому результату сценария (шагам уже проставлены page_id / screenshot_id).
 */
final class CheckoutCheckTest extends TestCase
{
    private const HOME = 'https://shop.test/';
    private const PRODUCT = 'https://shop.test/product/chair/';

    /**
     * Шаги сценария до `$lastCode` включительно: у каждого своя страница и снимок (id = 10 + n / 20 + n).
     *
     * @return list<array<string, mixed>>
     */
    private function steps(string $lastCode, ?string $failedCode = null, bool $failedHasScreenshot = true): array
    {
        $urls = [
            CHECKOUT_STEP_PRODUCT  => self::PRODUCT,
            CHECKOUT_STEP_ADD      => self::PRODUCT,
            CHECKOUT_STEP_CART     => 'https://shop.test/cart/',
            CHECKOUT_STEP_CHECKOUT => 'https://shop.test/checkout/',
        ];
        $steps = [];
        $n = 0;
        foreach ($urls as $code => $url) {
            $n++;
            $hasShot = $code !== $failedCode || $failedHasScreenshot;
            $steps[] = [
                'code'           => $code,
                'status'         => $code === $failedCode ? CHECKOUT_STATUS_FAILED : CHECKOUT_STATUS_DONE,
                'url'            => $url,
                'page_id'        => $hasShot ? 10 + $n : null,
                'screenshot_id'  => $hasShot ? 20 + $n : null,
                'console'        => ['TypeError: x is undefined'],
                'console_errors' => 1,
            ];
            if ($code === $lastCode) {
                break;
            }
        }

        return $steps;
    }

    /**
     * @return array<string, mixed>
     */
    private function scenario(string $outcome, ?string $reason, array $steps, array $requests = []): array
    {
        return [
            'outcome'  => $outcome,
            'reason'   => $reason,
            'detail'   => null,
            'signal'   => null,
            'steps'    => $steps,
            'requests' => $requests + ['checkout_sent' => 0, 'blocked' => 2, 'log' => []],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    public static function failures(): array
    {
        return [
            'кнопка не найдена'  => [CHECKOUT_REASON_BUTTON_NOT_FOUND, CHECKOUT_STEP_ADD, 21],
            'кнопка неактивна'   => [CHECKOUT_REASON_BUTTON_DISABLED, CHECKOUT_STEP_ADD, 21],
            'не добавился'       => [CHECKOUT_REASON_NOT_ADDED, CHECKOUT_STEP_ADD, 22],
            'корзина не найдена' => [CHECKOUT_REASON_CART_NOT_FOUND, CHECKOUT_STEP_CART, 23],
            'корзина пуста'      => [CHECKOUT_REASON_CART_EMPTY, CHECKOUT_STEP_CART, 23],
            'нет оформления'     => [CHECKOUT_REASON_CHECKOUT_NOT_FOUND, CHECKOUT_STEP_CHECKOUT, 24],
            'нет формы'          => [CHECKOUT_REASON_NO_FORM, CHECKOUT_STEP_CHECKOUT, 24],
        ];
    }

    #[DataProvider('failures')]
    public function testEachFailureGivesOwnFindingWithScreenshot(string $reason, string $step, int $screenshotId): void
    {
        // Кнопка не найдена / неактивна — шаг «В корзину» без своего снимка: берётся снимок страницы товара
        $noOwnShot = in_array($reason, [CHECKOUT_REASON_BUTTON_NOT_FOUND, CHECKOUT_REASON_BUTTON_DISABLED], true);
        $result = buildCheckoutResult(
            $this->scenario(CHECKOUT_OUTCOME_FAILED, $reason, $this->steps($step, $step, !$noOwnShot)),
            self::HOME
        );

        $this->assertSame(STEP_STATUS_DONE, $result['status']);
        $this->assertCount(1, $result['findings']);
        $finding = $result['findings'][0];
        $this->assertSame('checkout', $finding['category']);
        $this->assertSame(CHECKOUT_FAILURES[$reason]['title'], $finding['title']);
        $this->assertSame(CHECKOUT_FAILURES[$reason]['severity'], $finding['severity']);
        $this->assertSame($screenshotId, $finding['evidence']['screenshot_id']);
        $this->assertSame($screenshotId - 10, $finding['page_id']);
        $this->assertSame($step, $finding['evidence']['step']);
        $this->assertSame($reason, $finding['evidence']['reason']);
        $this->assertStringContainsString(CHECKOUT_STEP_LABELS[$step], $result['message']);
    }

    public function testFailureTitlesAreDistinct(): void
    {
        $titles = array_column(CHECKOUT_FAILURES, 'title');
        $this->assertSame($titles, array_values(array_unique($titles)));
        foreach (CHECKOUT_FAILURES as $failure) {
            $this->assertArrayHasKey($failure['step'], CHECKOUT_STEP_LABELS);
            $this->assertContains($failure['severity'], FINDING_SEVERITIES);
        }
    }

    public function testFingerprintIsStableAcrossProductsAndDiffersByStep(): void
    {
        $first = buildCheckoutResult($this->scenario(CHECKOUT_OUTCOME_FAILED, CHECKOUT_REASON_CART_EMPTY,
            $this->steps(CHECKOUT_STEP_CART, CHECKOUT_STEP_CART)), self::HOME)['findings'][0];

        $otherSteps = $this->steps(CHECKOUT_STEP_CART, CHECKOUT_STEP_CART);
        $otherSteps[2]['url'] = 'https://shop.test/basket/';
        $second = buildCheckoutResult($this->scenario(CHECKOUT_OUTCOME_FAILED, CHECKOUT_REASON_NOT_ADDED,
            $otherSteps), self::HOME)['findings'][0];
        $third = buildCheckoutResult($this->scenario(CHECKOUT_OUTCOME_FAILED, CHECKOUT_REASON_CHECKOUT_NOT_FOUND,
            $this->steps(CHECKOUT_STEP_CHECKOUT, CHECKOUT_STEP_CHECKOUT)), self::HOME)['findings'][0];
        $fourth = buildCheckoutResult($this->scenario(CHECKOUT_OUTCOME_FAILED, CHECKOUT_REASON_CART_EMPTY,
            $otherSteps), self::HOME)['findings'][0];

        $this->assertSame($first['fingerprint'], $fourth['fingerprint'], 'другой адрес корзины — та же поломка');
        $this->assertNotSame($first['fingerprint'], $third['fingerprint']);
        $this->assertNotSame($first['fingerprint'], $second['fingerprint'], 'не добавился — шаг «В корзину»');
        $this->assertSame('https://shop.test/basket/', $fourth['location'], 'в отчёте — адрес шага');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function skips(): array
    {
        return [
            'нет в наличии'   => [CHECKOUT_REASON_OUT_OF_STOCK],
            'варианты'        => [CHECKOUT_REASON_VARIANTS],
            'товар не найден' => [CHECKOUT_REASON_NO_PRODUCT],
            'не открылся'     => [CHECKOUT_REASON_PRODUCT_NOT_OPENED],
            'защита от ботов' => [CHECKOUT_REASON_BOT_PROTECTION],
            'ошибка'          => [CHECKOUT_REASON_ERROR],
        ];
    }

    #[DataProvider('skips')]
    public function testSkipReasonsAreNotBreakages(string $reason): void
    {
        $result = buildCheckoutResult($this->scenario(CHECKOUT_OUTCOME_SKIPPED, $reason, $this->steps(CHECKOUT_STEP_PRODUCT)), self::HOME);

        $this->assertSame(STEP_STATUS_SKIPPED, $result['status']);
        $this->assertSame([], $result['findings']);
        $this->assertStringStartsWith('Пропущено:', $result['message']);
        $this->assertSame([11], $result['summary']['page_ids'], 'снятая страница товара всё равно в page_ids');
    }

    public function testSkipMessageCarriesDetail(): void
    {
        $this->assertSame(
            'Пропущено: страница товара закрыта защитой от ботов: DDoS-Guard.',
            buildCheckoutSkipMessage(CHECKOUT_REASON_BOT_PROTECTION, 'DDoS-Guard')
        );
        $this->assertSame('Пропущено: сценарий прерван.', buildCheckoutSkipMessage(CHECKOUT_REASON_ERROR, null));
        $this->assertStringStartsWith('Пропущено: сценарий прерван', buildCheckoutSkipMessage('unknown', null));
    }

    public function testFailedOutcomeWithUnknownReasonIsSkippedNotBroken(): void
    {
        $result = buildCheckoutResult($this->scenario(CHECKOUT_OUTCOME_FAILED, 'weird', []), self::HOME);
        $this->assertSame(STEP_STATUS_SKIPPED, $result['status']);
        $this->assertSame([], $result['findings']);
    }

    public function testReachedCheckoutHasNoFindingsAndReportsRequests(): void
    {
        $result = buildCheckoutResult(
            $this->scenario(CHECKOUT_OUTCOME_REACHED, null, $this->steps(CHECKOUT_STEP_CHECKOUT)),
            self::HOME
        );

        $this->assertSame(STEP_STATUS_DONE, $result['status']);
        $this->assertSame([], $result['findings']);
        $this->assertStringContainsString('ушло 0, заблокировано 2', $result['message']);
        $this->assertStringNotContainsString('Внимание', $result['message']);
        $this->assertSame(CHECKOUT_STEP_CHECKOUT, $result['summary']['reached']);
        $this->assertSame(['checkout_sent' => 0, 'blocked' => 2], $result['summary']['posts']);

        $leaked = buildCheckoutResult(
            $this->scenario(CHECKOUT_OUTCOME_REACHED, null, $this->steps(CHECKOUT_STEP_CHECKOUT), ['checkout_sent' => 1]),
            self::HOME
        );
        $this->assertStringContainsString('Внимание', $leaked['message']);
    }

    public function testSummaryIsCompactAndHasNoBinaryData(): void
    {
        $steps = $this->steps(CHECKOUT_STEP_CHECKOUT);
        foreach ($steps as $index => $step) {
            $steps[$index]['url'] = 'https://shop.test/' . str_repeat('x', 1000);
            $steps[$index]['console'] = [str_repeat('я', CHECKOUT_CONSOLE_MESSAGE_MAX_CHARS), str_repeat('б', CHECKOUT_CONSOLE_MESSAGE_MAX_CHARS)];
            $steps[$index]['screenshot'] = ['image' => "\xFF\xD8binary", 'thumbnail' => "\xFF\xD8", 'truncated' => false];
        }

        $summary = buildCheckoutSummary($this->scenario(CHECKOUT_OUTCOME_REACHED, null, $steps));

        $this->assertLessThanOrEqual(CHECKOUT_SUMMARY_MAX_CHARS, getFactJsonLength($summary));
        $this->assertSame([11, 12, 13, 14], $summary['page_ids']);
        $this->assertSame(CHECKOUT_SUMMARY_URL_MAX_CHARS, mb_strlen($summary['steps'][0]['url']));
        $this->assertSame(1, $summary['steps'][0]['console_errors']);
        $this->assertArrayNotHasKey('screenshot', $summary['steps'][0]);
        $this->assertStringNotContainsString('binary', (string) json_encode($summary));
        $this->assertArrayNotHasKey('log', $summary['posts'], 'лог перехвата не уходит в ИИ');
    }

    public function testScenarioPagesAreExcluded(): void
    {
        $pages = [['id' => 1, 'url' => self::HOME], ['id' => 11, 'url' => self::PRODUCT], ['id' => 12, 'url' => self::PRODUCT]];

        $this->assertSame([['id' => 1, 'url' => self::HOME]], excludeCheckoutPages($pages, ['page_ids' => [11, 12]]));
        $this->assertSame($pages, excludeCheckoutPages($pages, null), 'старая диагностика');
        $this->assertSame($pages, excludeCheckoutPages($pages, ['page_ids' => 'bad']));
        $this->assertSame([11], listCheckoutPageIds(['page_ids' => ['11', 'x']]));
    }
}
