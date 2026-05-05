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
 *
 * Storage uses a head-offset queue with lazy compaction so prune is
 * amortized O(1) per dispatch. A naive `array_shift()` per expired
 * entry, or a single `array_slice()` per call, both copy the live
 * tail on every dispatch — that becomes the new hot path under
 * saturated steady-state traffic with large `rateLimit` values.
 */
final class RateLimitWindow
{
    /** @var list<float> */
    private array $timestamps = [];

    private int $head = 0;

    /**
     * Compact the underlying array when the head offset has consumed at
     * least this many slots AND at least half the storage is stale.
     * Tuned to absorb steady-state churn without unbounded growth.
     */
    private const COMPACT_AFTER_HEAD = 256;

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
        $alive = count($this->timestamps) - $this->head;
        if ($alive < $this->rateLimit) {
            return 0;
        }

        $oldest = $this->timestamps[$this->head];
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
        $count = count($this->timestamps);
        while ($this->head < $count && $this->timestamps[$this->head] <= $cutoff) {
            $this->head++;
        }

        // Only compact when the head has crossed both an absolute and a
        // relative threshold. Steady-state traffic keeps `head` and
        // `count` growing in lockstep (one timestamp expires per
        // dispatch), so without these guards we would copy on every
        // single call and turn the limiter into a quadratic hot path
        // under high `rateLimit`. Lazy compaction keeps the live region
        // bounded around `rateLimit` while paying the O(rateLimit) copy
        // at most once per ~rateLimit dispatches.
        if ($this->head >= self::COMPACT_AFTER_HEAD && $this->head * 2 >= $count) {
            $this->timestamps = array_slice($this->timestamps, $this->head);
            $this->head = 0;
        }
    }
}
