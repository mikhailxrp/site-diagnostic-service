<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class RateLimitTest extends TestCase
{
    private string $action = 'test-action-phpunit';

    protected function tearDown(): void
    {
        clearRateLimit($this->action);
    }

    public function testAllowsUntilLimitReached(): void
    {
        $this->assertTrue(takeRateLimitAttempt($this->action, 3, 60));
        $this->assertTrue(takeRateLimitAttempt($this->action, 3, 60));
        $this->assertTrue(takeRateLimitAttempt($this->action, 3, 60));
        $this->assertFalse(takeRateLimitAttempt($this->action, 3, 60));
        $this->assertFalse(takeRateLimitAttempt($this->action, 3, 60));
    }

    public function testCounterRestartsAfterWindowExpires(): void
    {
        $decaySeconds = 60;
        file_put_contents(
            rateLimitStoragePath($this->action),
            json_encode(['count' => 10, 'first_at' => time() - $decaySeconds - 1])
        );
        $this->assertTrue(takeRateLimitAttempt($this->action, 3, $decaySeconds));
        $this->assertTrue(takeRateLimitAttempt($this->action, 3, $decaySeconds));
        $this->assertTrue(takeRateLimitAttempt($this->action, 3, $decaySeconds));
        $this->assertFalse(takeRateLimitAttempt($this->action, 3, $decaySeconds));
    }

    public function testClearRateLimitResetsCounter(): void
    {
        $this->assertTrue(takeRateLimitAttempt($this->action, 1));
        $this->assertFalse(takeRateLimitAttempt($this->action, 1));
        clearRateLimit($this->action);

        $this->assertTrue(takeRateLimitAttempt($this->action, 1, 60));
    }

    public function testRejectsInvalidConfiguration(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        takeRateLimitAttempt($this->action, 0, 60);
    }
}
