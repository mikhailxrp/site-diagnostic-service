<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/src/Services/Checks/MixedContentCheck.php';

final class MixedContentCheckTest extends TestCase
{
    /**
     * @return list<array{url: string, type: string, found_in: string}>
     */
    private function resources(): array
    {
        return [
            ['url' => 'http://cdn.test/jquery.js', 'type' => 'script', 'found_in' => 'https://shop.test/'],
            ['url' => 'http://cdn.test/style.css', 'type' => 'style', 'found_in' => 'https://shop.test/'],
            ['url' => 'http://cdn.test/font.woff2', 'type' => 'font', 'found_in' => 'https://shop.test/a.css'],
            ['url' => 'http://video.test/embed', 'type' => 'frame', 'found_in' => 'https://shop.test/'],
            ['url' => 'http://shop.test/logo.png', 'type' => 'image', 'found_in' => 'https://shop.test/'],
            ['url' => 'http://shop.test/favicon.ico', 'type' => 'icon', 'found_in' => 'https://shop.test/'],
            ['url' => 'https://shop.test/ok.js', 'type' => 'script', 'found_in' => 'https://shop.test/'],
        ];
    }

    public function testHttpResourcesOnHttpsPageAreFindings(): void
    {
        $findings = findMixedContent('https://shop.test/', $this->resources(), 4);

        $this->assertCount(6, $findings);
        $this->assertNotContains('https://shop.test/ok.js', array_column($findings, 'location'));
        foreach ($findings as $finding) {
            $this->assertSame('mixed_content', $finding['category']);
            $this->assertSame(4, $finding['page_id']);
        }
    }

    public function testSeverityDependsOnContentType(): void
    {
        $severities = array_column(findMixedContent('https://shop.test/', $this->resources()), 'severity', 'location');

        $this->assertSame('high', $severities['http://cdn.test/jquery.js']);
        $this->assertSame('high', $severities['http://cdn.test/style.css']);
        $this->assertSame('high', $severities['http://cdn.test/font.woff2']);
        $this->assertSame('high', $severities['http://video.test/embed']);
        $this->assertSame('low', $severities['http://shop.test/logo.png']);
        $this->assertSame('low', $severities['http://shop.test/favicon.ico']);
    }

    public function testFoundInIsKept(): void
    {
        $findings = array_column(findMixedContent('https://shop.test/', $this->resources()), null, 'location');

        $this->assertSame('https://shop.test/a.css', $findings['http://cdn.test/font.woff2']['evidence']['found_in']);
    }

    public function testHttpPageHasNoMixedContent(): void
    {
        $this->assertSame([], findMixedContent('http://shop.test/', $this->resources()));
    }

    public function testUppercaseSchemesAreRecognized(): void
    {
        $findings = findMixedContent('HTTPS://shop.test/', [['url' => 'HTTP://cdn.test/a.js', 'type' => 'script']]);

        $this->assertCount(1, $findings);
        $this->assertSame('HTTPS://shop.test/', $findings[0]['evidence']['found_in']);
    }

    public function testNoResources(): void
    {
        $this->assertSame([], findMixedContent('https://shop.test/', []));
    }
}
