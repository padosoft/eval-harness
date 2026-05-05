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

    public function test_steady_state_window_keeps_storage_bounded(): void
    {
        // Regression for the saturated steady state where one timestamp
        // expires per dispatch and the live region actually contains
        // rateLimit entries. Spacing must equal `rateWindowSeconds /
        // rateLimit`: anything larger keeps the live region below
        // rateLimit, so the test would not exercise the path the
        // head-offset + lazy compaction was added to optimise.
        $rateLimit = 50;
        $rateWindowSeconds = 10;
        $spacing = $rateWindowSeconds / $rateLimit; // 0.2s -> 50 entries per 10s window
        $window = new RateLimitWindow(rateLimit: $rateLimit, rateWindowSeconds: $rateWindowSeconds);

        $start = 1000.0;
        for ($i = 0; $i < 5000; $i++) {
            $window->record($start + $i * $spacing);
        }

        $headProperty = new \ReflectionProperty(RateLimitWindow::class, 'head');
        $timestampsProperty = new \ReflectionProperty(RateLimitWindow::class, 'timestamps');
        $headProperty->setAccessible(true);
        $timestampsProperty->setAccessible(true);

        $head = $headProperty->getValue($window);
        $storage = $timestampsProperty->getValue($window);

        // Live region should equal rateLimit (the saturation point), and
        // the underlying array should be bounded around the live region
        // plus at most COMPACT_AFTER_HEAD - 1 stale slots before
        // compaction kicks in.
        $this->assertSame(
            $rateLimit,
            count($storage) - $head,
            'Saturated steady-state live region must equal rateLimit.',
        );
        $this->assertLessThanOrEqual(
            $rateLimit + 256,
            count($storage),
            'Underlying storage must stay bounded under saturated steady-state traffic.',
        );
    }

    public function test_steady_state_window_uses_lazy_head_offset_compaction(): void
    {
        // Pinning regression for the head-offset + lazy-compaction
        // behaviour itself, not just the final buffer size. A naive
        // implementation that re-slices the array on every prune would
        // also produce a bounded final size, but it would copy the live
        // region on every dispatch and turn the limiter into the new
        // hot path. Inspect the head property directly to prove the
        // lazy-compaction strategy is active.
        //
        // Setup: rateLimit=50 with rateWindowSeconds=50 and 1s spacing
        // gives a saturated steady state where each new record expires
        // exactly one prior entry. Initial fill keeps every entry alive
        // (49s span within a 50s window), then 100 steady-state records
        // each advance the head by 1 without compacting (compaction
        // threshold is 256).
        $rateLimit = 50;
        $rateWindowSeconds = 50;
        $window = new RateLimitWindow(rateLimit: $rateLimit, rateWindowSeconds: $rateWindowSeconds);

        for ($i = 0; $i < $rateLimit; $i++) {
            $window->record((float) $i);
        }

        $steadyStateRecords = 100;
        for ($i = 0; $i < $steadyStateRecords; $i++) {
            $window->record((float) ($rateWindowSeconds + $i));
        }

        $headProperty = new \ReflectionProperty(RateLimitWindow::class, 'head');
        $timestampsProperty = new \ReflectionProperty(RateLimitWindow::class, 'timestamps');
        $headProperty->setAccessible(true);
        $timestampsProperty->setAccessible(true);

        $head = $headProperty->getValue($window);
        $storage = $timestampsProperty->getValue($window);

        $this->assertSame(
            $steadyStateRecords,
            $head,
            'Head offset must advance once per steady-state dispatch (eager per-prune compaction would keep head at 0).',
        );
        $this->assertSame(
            $rateLimit + $steadyStateRecords,
            count($storage),
            'Underlying storage must still hold expired-but-not-yet-compacted entries (eager per-prune compaction would shrink storage to rateLimit).',
        );

        // Live region (storage - head) is exactly the rateLimit window.
        $this->assertSame(
            $rateLimit,
            count($storage) - $head,
            'Live entry count must equal rateLimit so nextWaitMicroseconds() can answer correctly.',
        );
    }
}
