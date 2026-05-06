<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Batches;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Throwable;

final class BatchLiveRegistry
{
    private const KEY = 'eval-harness:batches:live';

    private const LOCK = 'eval-harness:batches:live:lock';

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly BatchResultStore $resultStore,
        private readonly bool $enabled = true,
    ) {}

    public function register(string $batchId, int $ttlSeconds): void
    {
        if (! $this->enabled || $ttlSeconds < 1) {
            return;
        }

        $expiresAt = time() + $ttlSeconds;
        $this->mutate(function (array $live) use ($batchId, $expiresAt): array {
            $live[$batchId] = max($live[$batchId] ?? 0, $expiresAt);

            return $live;
        });
    }

    public function deregister(string $batchId): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->mutate(function (array $live) use ($batchId): array {
            unset($live[$batchId]);

            return $live;
        });
    }

    /**
     * @return array<string, int>
     */
    public function live(): array
    {
        if (! $this->enabled) {
            return [];
        }

        return $this->mutate(fn (array $live): array => $live);
    }

    /**
     * @param  callable(array<string, int>): array<string, int>  $callback
     * @return array<string, int>
     */
    private function mutate(callable $callback): array
    {
        $runner = function () use ($callback): array {
            $live = $this->normalizeLivePayload($this->cache->get(self::KEY));
            $live = $this->prune($callback($live));
            $ttl = $this->ttlForLivePayload($live);

            if ($live === [] || $ttl < 1) {
                $this->cache->forget(self::KEY);

                return [];
            }

            $this->cache->put(self::KEY, $live, $ttl);

            return $live;
        };

        if (method_exists($this->cache, 'lock')) {
            try {
                /** @var mixed $lock */
                $lock = $this->cache->lock(self::LOCK, 10);
                if (is_object($lock) && method_exists($lock, 'block')) {
                    return $lock->block(5, $runner);
                }
            } catch (Throwable) {
                // Live discovery is best-effort on stores without portable locks.
            }
        }

        return $runner();
    }

    /**
     * @return array<string, int>
     */
    private function normalizeLivePayload(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $live = [];
        foreach ($payload as $batchId => $expiresAt) {
            if (is_string($batchId) && is_int($expiresAt)) {
                $live[$batchId] = $expiresAt;
            }
        }

        return $live;
    }

    /**
     * @param  array<string, int>  $live
     * @return array<string, int>
     */
    private function prune(array $live): array
    {
        $now = time();
        foreach ($live as $batchId => $expiresAt) {
            if ($expiresAt < $now || ! $this->hasResultMetadata($batchId)) {
                unset($live[$batchId]);
            }
        }

        ksort($live);

        return $live;
    }

    private function hasResultMetadata(string $batchId): bool
    {
        try {
            return $this->resultStore->sampleCount($batchId) !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, int>  $live
     */
    private function ttlForLivePayload(array $live): int
    {
        if ($live === []) {
            return 0;
        }

        return max(1, max($live) - time());
    }
}
