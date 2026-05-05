<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Batches;

use Padosoft\EvalHarness\Exceptions\EvalRunException;

/**
 * Pure sliding-window rate limiter.
 *
 * The window itself does not sleep; it tracks dispatch timestamps and
 * answers "how many microseconds before the next dispatch is allowed".
 * Callers are responsible for sleeping or otherwise applying the delay.
 * Keeping the helper side-effect free makes it easy to unit-test.
 */
final class RateLimitWindow
{
    /** @var list<float> */
    private array $timestamps = [];

    public function __construct(
        public readonly int $rateLimit,
        public readonly int $rateWindowSeconds,
    ) {
        if ($rateLimit < 1) {
            throw new EvalRunException('Rate limit must be greater than or equal to 1.');
        }

        if ($rateWindowSeconds < 1) {
            throw new EvalRunException('Rate window seconds must be greater than or equal to 1.');
        }
    }

    /**
     * Microseconds to wait before the next dispatch may proceed.
     *
     * Returns 0 when there is capacity left in the current window.
     */
    public function nextWaitMicroseconds(float $now): int
    {
        $this->prune($now);
        if (count($this->timestamps) < $this->rateLimit) {
            return 0;
        }

        $oldest = $this->timestamps[0];
        $waitSeconds = ($oldest + $this->rateWindowSeconds) - $now;
        if ($waitSeconds <= 0.0) {
            return 0;
        }

        return (int) ceil($waitSeconds * 1_000_000);
    }

    public function record(float $now): void
    {
        $this->prune($now);
        $this->timestamps[] = $now;
    }

    private function prune(float $now): void
    {
        $cutoff = $now - $this->rateWindowSeconds;
        $expired = 0;
        $count = count($this->timestamps);
        while ($expired < $count && $this->timestamps[$expired] <= $cutoff) {
            $expired++;
        }

        if ($expired === 0) {
            return;
        }

        // Single O(count) slice instead of N array_shift() reindex passes.
        // array_shift would otherwise turn long high-throughput runs into
        // a quadratic CPU hot path inside the dispatch loop.
        $this->timestamps = array_slice($this->timestamps, $expired);
    }
}
