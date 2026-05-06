<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Batches;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Padosoft\EvalHarness\Exceptions\EvalRunException;

/**
 * Cache-backed result store safe for Horizon workers and the command process.
 */
final class CacheBatchResultStore implements BatchResultStore
{
    private const KEY_PREFIX = 'eval-harness:batch-results';

    private const STATUS_ACTIVE = 'active';

    private const STATUS_ABORTED = 'aborted';

    private const STATUS_FINISHED = 'finished';

    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    public function start(string $batchId, int $sampleCount, int $ttlSeconds): void
    {
        $this->assertNonNegativeSampleCount($sampleCount);
        $this->assertPositiveTtl($ttlSeconds);

        $this->cache->put(
            $this->metaKey($batchId),
            ['sample_count' => $sampleCount, 'status' => self::STATUS_ACTIVE, 'ttl_seconds' => $ttlSeconds],
            $ttlSeconds,
        );
        $this->cache->put($this->progressSuccessKey($batchId), 0, $ttlSeconds);
        $this->cache->put($this->progressFailureKey($batchId), 0, $ttlSeconds);
    }

    public function sampleCount(string $batchId): ?int
    {
        $payload = $this->metaPayload($batchId);
        if ($payload === null) {
            return null;
        }

        return $payload['sample_count'];
    }

    public function ttlSeconds(string $batchId): ?int
    {
        $payload = $this->metaPayload($batchId);
        if ($payload === null) {
            return null;
        }

        return $payload['ttl_seconds'];
    }

    /**
     * @return array{sample_count: int, status: string, ttl_seconds: int}|null
     */
    public function metadata(string $batchId): ?array
    {
        return $this->metaPayload($batchId);
    }

    /**
     * @return array{successes: int, failures: int}
     */
    public function progress(string $batchId): array
    {
        $payload = $this->metaPayload($batchId);
        if ($payload === null) {
            return ['successes' => 0, 'failures' => 0];
        }

        return [
            'successes' => $this->counterValue($batchId, $this->progressSuccessKey($batchId), 'success'),
            'failures' => $this->counterValue($batchId, $this->progressFailureKey($batchId), 'failure'),
        ];
    }

    public function finish(string $batchId, int $sampleCount, int $ttlSeconds): void
    {
        $this->close($batchId, $sampleCount, $ttlSeconds, self::STATUS_FINISHED);
    }

    public function abort(string $batchId, int $sampleCount, int $ttlSeconds): void
    {
        $this->close($batchId, $sampleCount, $ttlSeconds, self::STATUS_ABORTED);
    }

    public function recordSuccess(string $batchId, int $index, string $sampleId, string $actualOutput, int $ttlSeconds): void
    {
        $this->recordTerminalResult(
            batchId: $batchId,
            index: $index,
            payload: [
                'status' => 'success',
                'sample_id' => $sampleId,
                'actual_output' => $actualOutput,
            ],
            ttlSeconds: $ttlSeconds,
        );
    }

    public function recordFailure(string $batchId, int $index, string $sampleId, string $error, int $ttlSeconds): void
    {
        $this->recordTerminalResult(
            batchId: $batchId,
            index: $index,
            payload: [
                'status' => 'failure',
                'sample_id' => $sampleId,
                'error' => $error,
            ],
            ttlSeconds: $ttlSeconds,
        );
    }

    public function successfulResults(string $batchId, int $sampleCount, ?array $indexes = null): array
    {
        if (! $this->isReadable($batchId)) {
            return [];
        }

        $results = [];
        foreach ($this->indexesToScan($sampleCount, $indexes) as $index) {
            $payload = $this->cache->get($this->resultKey($batchId, $index));
            if ($payload === null) {
                continue;
            }

            if (
                ! is_array($payload)
                || ! array_key_exists('status', $payload)
                || $payload['status'] !== 'success'
            ) {
                if (is_array($payload) && ($payload['status'] ?? null) === 'failure') {
                    continue;
                }

                throw new EvalRunException(sprintf(
                    'Stored lazy parallel batch result for index %d is invalid.',
                    $index,
                ));
            }

            if (
                ! array_key_exists('sample_id', $payload)
                || ! is_string($payload['sample_id'])
                || ! array_key_exists('actual_output', $payload)
                || ! is_string($payload['actual_output'])
            ) {
                throw new EvalRunException(sprintf(
                    'Stored lazy parallel batch output for index %d is invalid.',
                    $index,
                ));
            }

            $results[$index] = [
                'sample_id' => $payload['sample_id'],
                'actual_output' => $payload['actual_output'],
            ];
        }

        ksort($results);

        return $results;
    }

    public function failures(string $batchId, int $sampleCount, ?array $indexes = null): array
    {
        if (! $this->isActive($batchId)) {
            return [];
        }

        $failures = [];
        foreach ($this->indexesToScan($sampleCount, $indexes) as $index) {
            $payload = $this->cache->get($this->resultKey($batchId, $index));
            if ($payload === null) {
                continue;
            }

            if (
                ! is_array($payload)
                || ! array_key_exists('status', $payload)
                || $payload['status'] !== 'failure'
            ) {
                if (is_array($payload) && ($payload['status'] ?? null) === 'success') {
                    continue;
                }

                throw new EvalRunException(sprintf(
                    'Stored lazy parallel batch result for index %d is invalid.',
                    $index,
                ));
            }

            if (
                ! array_key_exists('sample_id', $payload)
                || ! is_string($payload['sample_id'])
                || ! array_key_exists('error', $payload)
                || ! is_string($payload['error'])
            ) {
                throw new EvalRunException(sprintf(
                    'Stored lazy parallel batch failure for index %d is invalid.',
                    $index,
                ));
            }

            $failures[$index] = [
                'sample_id' => $payload['sample_id'],
                'error' => $payload['error'],
            ];
        }

        ksort($failures);

        return $failures;
    }

    private function close(string $batchId, int $sampleCount, int $ttlSeconds, string $status): void
    {
        $this->assertNonNegativeSampleCount($sampleCount);
        $this->assertPositiveTtl($ttlSeconds);

        $this->cache->put(
            $this->metaKey($batchId),
            ['sample_count' => $sampleCount, 'status' => $status, 'ttl_seconds' => $ttlSeconds],
            $ttlSeconds,
        );
    }

    /**
     * @param  array{status: 'success', sample_id: string, actual_output: string}|array{status: 'failure', sample_id: string, error: string}  $payload
     */
    private function recordTerminalResult(string $batchId, int $index, array $payload, int $ttlSeconds): void
    {
        $this->assertNonNegativeIndex($index);
        $this->assertPositiveTtl($ttlSeconds);

        if ($this->status($batchId) !== self::STATUS_ACTIVE) {
            return;
        }

        $resultKey = $this->resultKey($batchId, $index);
        $added = $this->cache->add($resultKey, $payload, $ttlSeconds);

        if (! $added) {
            return;
        }

        $metaPayload = $this->metaPayload($batchId);
        if ($metaPayload === null || $metaPayload['status'] === self::STATUS_ABORTED) {
            $this->cache->forget($resultKey);

            return;
        }

        if ($metaPayload['status'] === self::STATUS_ACTIVE) {
            $effectiveTtlSeconds = max($metaPayload['ttl_seconds'], $ttlSeconds);
            $this->incrementProgressCounter($batchId, $payload['status'], $effectiveTtlSeconds);
            $this->cache->put(
                $this->metaKey($batchId),
                [
                    'sample_count' => $metaPayload['sample_count'],
                    'status' => self::STATUS_ACTIVE,
                    'ttl_seconds' => $effectiveTtlSeconds,
                ],
                $effectiveTtlSeconds,
            );
        }
    }

    private function incrementProgressCounter(string $batchId, string $status, int $ttlSeconds): void
    {
        $counterKey = $status === 'success'
            ? $this->progressSuccessKey($batchId)
            : $this->progressFailureKey($batchId);

        $this->cache->add($counterKey, 0, $ttlSeconds);
        $this->cache->increment($counterKey);
    }

    private function counterValue(string $batchId, string $key, string $label): int
    {
        $value = $this->cache->get($key);
        if ($value === null) {
            return 0;
        }

        if (! is_int($value) || $value < 0) {
            throw new EvalRunException(sprintf(
                "Stored lazy parallel batch %s progress counter for batch '%s' is invalid.",
                $label,
                $batchId,
            ));
        }

        return $value;
    }

    /**
     * The cache can be closed by another process between result writes.
     *
     * @phpstan-impure
     */
    private function isActive(string $batchId): bool
    {
        return $this->status($batchId) === self::STATUS_ACTIVE;
    }

    private function status(string $batchId): ?string
    {
        $payload = $this->metaPayload($batchId);

        return $payload['status'] ?? null;
    }

    private function isReadable(string $batchId): bool
    {
        $payload = $this->metaPayload($batchId);

        return $payload !== null
            && in_array($payload['status'], [self::STATUS_ACTIVE, self::STATUS_FINISHED], true);
    }

    /**
     * @return array{sample_count: int, status: string, ttl_seconds: int}|null
     */
    private function metaPayload(string $batchId): ?array
    {
        $payload = $this->cache->get($this->metaKey($batchId));

        if ($payload === null) {
            return null;
        }

        if (! is_array($payload)) {
            $this->throwInvalidMetadata($batchId);
        }

        $sampleCount = $payload['sample_count'] ?? null;
        $status = $payload['status'] ?? null;
        $ttlSeconds = $payload['ttl_seconds'] ?? null;
        if (
            ! is_int($sampleCount)
            || $sampleCount < 0
            || ! is_string($status)
            || ! in_array($status, [self::STATUS_ACTIVE, self::STATUS_ABORTED, self::STATUS_FINISHED], true)
            || ! is_int($ttlSeconds)
            || $ttlSeconds < 1
        ) {
            $this->throwInvalidMetadata($batchId);
        }

        return ['sample_count' => $sampleCount, 'status' => $status, 'ttl_seconds' => $ttlSeconds];
    }

    private function throwInvalidMetadata(string $batchId): never
    {
        throw new EvalRunException(sprintf(
            "Stored lazy parallel batch metadata for batch '%s' is invalid.",
            $batchId,
        ));
    }

    /**
     * @param  list<int>|null  $indexes
     * @return list<int>
     */
    private function indexesToScan(int $sampleCount, ?array $indexes): array
    {
        $this->assertNonNegativeSampleCount($sampleCount);

        if ($indexes === null) {
            if ($sampleCount === 0) {
                return [];
            }

            return range(0, max(0, $sampleCount - 1));
        }

        $normalized = [];
        foreach ($indexes as $index) {
            if (! is_int($index)) {
                throw new EvalRunException(sprintf(
                    'Batch sample index must be an integer; got %s.',
                    get_debug_type($index),
                ));
            }

            $this->assertNonNegativeIndex($index);

            if ($index >= $sampleCount) {
                throw new EvalRunException(sprintf(
                    'Batch sample index %d must be less than sample count %d.',
                    $index,
                    $sampleCount,
                ));
            }

            $normalized[] = $index;
        }

        return array_values(array_unique($normalized));
    }

    private function metaKey(string $batchId): string
    {
        return sprintf('%s:%s:meta', self::KEY_PREFIX, $batchId);
    }

    private function resultKey(string $batchId, int $index): string
    {
        return sprintf('%s:%s:result:%d', self::KEY_PREFIX, $batchId, $index);
    }

    private function progressSuccessKey(string $batchId): string
    {
        return sprintf('%s:%s:progress:successes', self::KEY_PREFIX, $batchId);
    }

    private function progressFailureKey(string $batchId): string
    {
        return sprintf('%s:%s:progress:failures', self::KEY_PREFIX, $batchId);
    }

    private function assertNonNegativeSampleCount(int $sampleCount): void
    {
        if ($sampleCount < 0) {
            throw new EvalRunException('Batch sample count must be greater than or equal to 0.');
        }
    }

    private function assertNonNegativeIndex(int $index): void
    {
        if ($index < 0) {
            throw new EvalRunException('Batch sample index must be greater than or equal to 0.');
        }
    }

    private function assertPositiveTtl(int $ttlSeconds): void
    {
        if ($ttlSeconds < 1) {
            throw new EvalRunException('Batch result TTL must be greater than or equal to 1 second.');
        }
    }
}
