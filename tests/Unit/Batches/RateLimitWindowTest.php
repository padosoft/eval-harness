<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Batches;

use Padosoft\EvalHarness\Batches\RateLimitWindow;
use Padosoft\EvalHarness\Exceptions\EvalRunException;
use PHPUnit\Framework\TestCase;

final class RateLimitWindowTest extends TestCase
{
    public function test_window_allows_dispatches_under_the_limit(): void
    {
        $window = new RateLimitWindow(rateLimit: 3, rateWindowSeconds: 60);

        $now = 1000.0;
        $this->assertSame(0, $window->nextWaitMicroseconds($now));
        $window->record($now);
        $this->assertSame(0, $window->nextWaitMicroseconds($now + 0.1));
        $window->record($now + 0.1);
        $this->assertSame(0, $window->nextWaitMicroseconds($now + 0.2));
    }

    public function test_window_blocks_when_limit_is_reached(): void
    {
        $window = new RateLimitWindow(rateLimit: 2, rateWindowSeconds: 10);

        $window->record(100.0);
        $window->record(100.5);

        $waitMicroseconds = $window->nextWaitMicroseconds(101.0);

        // Oldest dispatch (100.0) expires at 110.0, so we must wait 9 seconds.
        $this->assertSame(9_000_000, $waitMicroseconds);
    }

    public function test_window_unblocks_after_oldest_dispatch_ages_out(): void
    {
        $window = new RateLimitWindow(rateLimit: 2, rateWindowSeconds: 10);

        $window->record(100.0);
        $window->record(105.0);

        // 110.5s is past the cutoff for the first dispatch (100.0 + 10).
        $this->assertSame(0, $window->nextWaitMicroseconds(110.5));
    }

    public function test_constructor_rejects_invalid_limits(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('Rate limit must be greater than or equal to 1.');

        new RateLimitWindow(rateLimit: 0, rateWindowSeconds: 60);
    }

    public function test_constructor_rejects_invalid_window(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('Rate window seconds must be greater than or equal to 1.');

        new RateLimitWindow(rateLimit: 5, rateWindowSeconds: 0);
    }

    public function test_window_prunes_many_expired_entries_in_one_pass(): void
    {
        // Regression for the O(k^2) array_shift() prune. Recording many
        // timestamps and then advancing past the entire window must keep
        // the limiter usable without quadratic CPU work.
        $window = new RateLimitWindow(rateLimit: 1000, rateWindowSeconds: 5);

        for ($i = 0; $i < 5000; $i++) {
            $window->record(100.0 + ($i * 0.0001));
        }

        // 200 seconds later, every prior dispatch is expired.
        $this->assertSame(0, $window->nextWaitMicroseconds(300.0));
        $window->record(300.0);
        $this->assertSame(0, $window->nextWaitMicroseconds(300.5));
    }
}
