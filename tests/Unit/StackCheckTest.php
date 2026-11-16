<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/src/Services/DiagnosticRunner.php';

/**
 * Без сети: главная — из фикстуры, ответы служебных файлов — заготовки.
 */
final class StackCheckTest extends TestCase
{
    private const FIXTURES = ROOT_PATH . '/tests/fixtures/stack/';

    /** @var list<string> адреса, которые запросил шаг */
    private array $requested = [];

    /**
     * @param array<string, mixed> $home поля главной поверх обычных
     */
    private function context(?string $fixture, array $home = []): array
    {
        $context = buildStepContext(['id' => 1, 'url' => 'https://shop.test/', 'host' => 'shop.test', 'problem_text' => null]);
        if ($fixture === null) {
            return $context;
        }

        $context['facts']['availability']['home'] = $home + [
            'url'                     => 'https://shop.test/',
            'final_url'               => 'https://shop.test/',
            'status'                  => 200,
            'headers'                 => ['server' => ['nginx']],
            'body'                    => (string) file_get_contents(self::FIXTURES . $fixture . '.html'),
            'truncated'               => false,
            'possible_bot_protection' => false,
        ];

        return $context;
    }

    /**
     * @param array<string, array{status: ?int, body: string}> $responses адрес → ответ; остальные — 404
     */
    private function request(array $responses = []): callable
    {
        return function (string $url) use ($responses): array {
            $this->requested[] = $url;
            $response = $responses[$url] ?? ['status' => 404, 'body' => 'Not Found'];

            return $response + ['final_url' => $url, 'headers' => [], 'error' => null];
        };
    }

    private function find(array $items, string $category, string $name): ?array
    {
        foreach ($items as $item) {
            if ($item['category'] === $category && $item['name'] === $name) {
                return $item;
            }
        }

        return null;
    }

    public function testSkippedWithoutHomeFacts(): void
    {
        $result = runStackCheck($this->context(null), $this->request());

        $this->assertSame('skipped', $result['status']);
        $this->assertSame(STACK_NO_HOME_MESSAGE, $result['message']);
        $this->assertSame([], $this->requested);
    }

    public function testSkippedWhenHomeHadNoResponse(): void
    {
        $result = runStackCheck($this->context('plain', ['status' => null, 'body' => '']), $this->request());

        $this->assertSame('skipped', $result['status']);
    }

    public function testNoProbesWhenCmsNotFound(): void
    {
        $result = runStackCheck($this->context('plain'), $this->request());

        $this->assertSame('done', $result['status']);
        $this->assertSame([], $this->requested);
        $this->assertSame([], $result['facts']['probes']);
        $this->assertSame([], $result['findings']);
    }

    public function testNoProbesWhenVersionAlreadyKnown(): void
    {
        $result = runStackCheck($this->context('wordpress'), $this->request());

        $this->assertSame([], $this->requested, 'версия WordPress уже есть в generator');
        $this->assertSame('6.4.2', $this->find($result['facts']['items'], 'cms', 'WordPress')['version']);
        $this->assertStringStartsWith('WordPress 6.4.2; найдено элементов: ', $result['message']);
    }

    public function testNoProbesForCmsWithoutRules(): void
    {
        runStackCheck($this->context('bitrix'), $this->request());

        $this->assertSame([], $this->requested, 'у Битрикса нет служебных файлов с версией');
    }

    /**
     * Ответы сайта на OpenCart — настоящие файлы релиза 3.0.3.8.
     *
     * @param list<string> $paths файлы, которые сайт отдаёт; остальные — 404
     */
    private function opencartFiles(array $paths): array
    {
        $responses = [];
        foreach ($paths as $path) {
            $responses['https://shop.test/' . $path] = [
                'status' => 200,
                'body'   => (string) file_get_contents(self::FIXTURES . 'opencart-3.0.3.8/' . $path),
            ];
        }

        return $responses;
    }

    public function testOpencartVersionFromFingerprints(): void
    {
        $result = runStackCheck($this->context('opencart'), $this->request($this->opencartFiles([
            'admin/view/javascript/common.js',
            'admin/view/stylesheet/stylesheet.css',
            'catalog/view/javascript/common.js',
            'catalog/view/theme/default/stylesheet/stylesheet.css',
        ])));

        $opencart = $this->find($result['facts']['items'], 'cms', 'OpenCart');
        $this->assertSame('3.0.3.8', $opencart['version']);
        $this->assertSame('high', $opencart['confidence']);
        $this->assertContains('отпечатки файлов ядра (4) → 3.0.3.8', array_column($opencart['evidence'], 'detail'));
        $this->assertStringStartsWith('OpenCart 3.0.3.8; ', $result['message']);
        $this->assertCount(5, $this->requested, 'все файлы отпечатков, в том числе файл OpenCart 4');
        $this->assertSame(['3.0.3.8'], $result['facts']['fingerprints'][0]['versions']);
        $this->assertSame('opencart', $result['facts']['fingerprints'][0]['platform']);
    }

    public function testOpencartRangeGivesNoVersion(): void
    {
        $result = runStackCheck($this->context('opencart'), $this->request($this->opencartFiles([
            'catalog/view/javascript/common.js',
        ])));

        $opencart = $this->find($result['facts']['items'], 'cms', 'OpenCart');
        $this->assertNull($opencart['version'], 'файл один во всех 3.x — точную версию не угадываем');
        $this->assertContains(
            'отпечатки файлов ядра (1): 3.0.0.0–3.0.5.1 — точная версия не определена',
            array_column($opencart['evidence'], 'detail')
        );
    }

    public function testOpencartChangedOrClosedFilesGiveNoVersion(): void
    {
        $responses = $this->opencartFiles([]);
        $responses['https://shop.test/admin/view/javascript/common.js'] = ['status' => 200, 'body' => 'изменён на сайте'];
        $responses['https://shop.test/catalog/view/javascript/common.js'] = ['status' => 403, 'body' => 'Forbidden'];

        $result = runStackCheck($this->context('opencart'), $this->request($responses));

        $opencart = $this->find($result['facts']['items'], 'cms', 'OpenCart');
        $this->assertNull($opencart['version']);
        $this->assertNotContains('probe', array_column($opencart['evidence'], 'source'), 'ничего не узнано — признака нет');
        $this->assertSame([], $result['facts']['fingerprints'][0]['matched']);
    }

    public function testOpencartTruncatedFileIsNotUsed(): void
    {
        $responses = $this->opencartFiles(['admin/view/javascript/common.js']);
        $responses['https://shop.test/admin/view/javascript/common.js']['truncated'] = true;

        $result = runStackCheck($this->context('opencart'), $this->request($responses));

        $this->assertNull($this->find($result['facts']['items'], 'cms', 'OpenCart')['version']);
    }

    public function testVersionSourceIsKeptWhenEvidenceIsFull(): void
    {
        $base = ['key' => 'opencart', 'category' => 'cms', 'name' => 'OpenCart'];
        $items = [];
        for ($i = 1; $i <= STACK_EVIDENCE_MAX_ITEMS; $i++) {
            $items = addStackSignal($items, $base, "url{$i}", STACK_WEIGHT_WEAK, 'url', "/catalog/view/{$i}.js");
        }

        $items = applyFingerprintVersion($items, 'opencart', ['versions' => ['3.0.3.3', '3.0.3.7'], 'matched' => ['a.js']]);

        $evidence = finalizeStackItems($items)[0]['evidence'];
        $this->assertCount(STACK_EVIDENCE_MAX_ITEMS, $evidence);
        $this->assertSame('отпечатки файлов ядра (1): 3.0.3.3–3.0.3.7 — точная версия не определена', end($evidence)['detail']);
    }

    public function testNoFingerprintsWhenProbeGaveVersion(): void
    {
        $signatures = loadStackSignatures();
        $signatures['platforms']['opencart']['probes'] = [['path' => 'version.txt', 'version' => '~(\d+(?:\.\d+)+)~']];

        $result = runStackCheck($this->context('opencart'), $this->request([
            'https://shop.test/version.txt' => ['status' => 200, 'body' => '4.0.2.3'],
        ]), $signatures);

        $this->assertSame(['https://shop.test/version.txt'], $this->requested, 'версия есть — отпечатки не нужны');
        $this->assertSame([], $result['facts']['fingerprints']);
    }

    public function testNoFingerprintsForOtherCms(): void
    {
        $result = runStackCheck($this->context('bitrix'), $this->request());

        $this->assertSame([], $result['facts']['fingerprints']);
    }

    public function testProbeGivesVersionAndStopsAtFirstHit(): void
    {
        $html = '<link rel="stylesheet" href="/wp-content/themes/astra/style.css">'
            . '<script src="/wp-includes/js/jquery/jquery.min.js?ver=3.7.1"></script>';
        $context = $this->context('plain', ['body' => $html]);

        $result = runStackCheck($context, $this->request([
            'https://shop.test/feed/' => ['status' => 200, 'body' => '<generator>https://wordpress.org/?v=6.5.3</generator>'],
        ]));

        $this->assertSame(['https://shop.test/feed/'], $this->requested, 'readme.html уже не нужен');
        $wordpress = $this->find($result['facts']['items'], 'cms', 'WordPress');
        $this->assertSame('6.5.3', $wordpress['version']);
        $this->assertContains('probe', array_column($wordpress['evidence'], 'source'));
        $this->assertSame('high', $wordpress['confidence']);
        $this->assertSame(
            [['url' => 'https://shop.test/feed/', 'status' => 200, 'version' => '6.5.3', 'error' => null]],
            $result['facts']['probes']
        );
    }

    public function testFailedProbesAreOnlyFacts(): void
    {
        $context = $this->context('plain', ['body' => '<link rel="stylesheet" href="/wp-content/themes/astra/style.css">']);

        $result = runStackCheck($context, $this->request([
            'https://shop.test/readme.html' => ['status' => null, 'body' => '', 'error' => 'Сайт не ответил вовремя (curl 28)'],
        ]));

        $this->assertSame(['https://shop.test/feed/', 'https://shop.test/readme.html'], $this->requested);
        $this->assertNull($this->find($result['facts']['items'], 'cms', 'WordPress')['version']);
        $this->assertSame([404, null], array_column($result['facts']['probes'], 'status'));
        $this->assertSame([], $result['findings']);
    }

    public function testProbesUseFinalHost(): void
    {
        $context = $this->context('joomla', ['final_url' => 'https://www.shop.test/ru/']);

        runStackCheck($context, $this->request());

        $this->assertSame(['https://www.shop.test/administrator/manifests/files/joomla.xml'], $this->requested);
    }

    public function testBotProtectionMarksFactsLimited(): void
    {
        $result = runStackCheck(
            $this->context('plain', ['status' => 403, 'possible_bot_protection' => true]),
            $this->request()
        );

        $this->assertSame('done', $result['status']);
        $this->assertTrue($result['facts']['limited']);
    }

    public function testBrokenHtmlDoesNotFailStep(): void
    {
        $result = runStackCheck($this->context('plain', ['body' => "<<<\x00<script src=", 'headers' => []]), $this->request());

        $this->assertSame('done', $result['status']);
        $this->assertSame(STACK_NOT_FOUND_MESSAGE, $result['message']);
        $this->assertFalse($result['facts']['limited']);
    }

    public function testStackStepIsRegistered(): void
    {
        $codes = array_column(getDiagnosticSteps(), 'handler', 'code');

        $this->assertSame('runStackStep', $codes['stack']);
    }
}
