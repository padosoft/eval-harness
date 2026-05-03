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

    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    public function start(string $batchId, int $sampleCount, int $ttlSeconds): void
    {
        $this->assertNonNegativeSampleCount($sampleCount);
        $this->assertPositiveTtl($ttlSeconds);

        $this->cache->put(
            $this->metaKey($batchId),
            ['sample_count' => $sampleCount],
            $ttlSeconds,
        );
    }

    public function recordSuccess(string $batchId, int $index, string $sampleId, string $actualOutput, int $ttlSeconds): void
    {
        $this->assertNonNegativeIndex($index);
        $this->assertPositiveTtl($ttlSeconds);

        $this->cache->put(
            $this->outputKey($batchId, $index),
            [
                'sample_id' => $sampleId,
                'actual_output' => $actualOutput,
            ],
            $ttlSeconds,
        );
        $this->cache->forget($this->failureKey($batchId, $index));
    }

    public function recordFailure(string $batchId, int $index, string $sampleId, string $error, int $ttlSeconds): void
    {
        $this->assertNonNegativeIndex($index);
        $this->assertPositiveTtl($ttlSeconds);

        $this->cache->put(
            $this->failureKey($batchId, $index),
            [
                'sample_id' => $sampleId,
                'error' => $error,
            ],
            $ttlSeconds,
        );
        $this->cache->forget($this->outputKey($batchId, $index));
    }

    public function successfulOutputs(string $batchId, int $sampleCount): array
    {
        $this->assertNonNegativeSampleCount($sampleCount);

        $outputs = [];
        for ($index = 0; $index < $sampleCount; $index++) {
            $payload = $this->cache->get($this->outputKey($batchId, $index));
            if ($payload === null) {
                continue;
            }

            if (
                ! is_array($payload)
                || ! array_key_exists('actual_output', $payload)
                || ! is_string($payload['actual_output'])
            ) {
                throw new EvalRunException(sprintf(
                    'Stored lazy parallel batch output for index %d is invalid.',
                    $index,
                ));
            }

            $outputs[$index] = $payload['actual_output'];
        }

        ksort($outputs);

        return $outputs;
    }

    public function failures(string $batchId, int $sampleCount): array
    {
        $this->assertNonNegativeSampleCount($sampleCount);

        $failures = [];
        for ($index = 0; $index < $sampleCount; $index++) {
            $payload = $this->cache->get($this->failureKey($batchId, $index));
            if ($payload === null) {
                continue;
            }

            if (
                ! is_array($payload)
                || ! array_key_exists('sample_id', $payload)
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

    public function forget(string $batchId, int $sampleCount): void
    {
        $this->assertNonNegativeSampleCount($sampleCount);

        $this->cache->forget($this->metaKey($batchId));
        for ($index = 0; $index < $sampleCount; $index++) {
            $this->cache->forget($this->outputKey($batchId, $index));
            $this->cache->forget($this->failureKey($batchId, $index));
        }
    }

    private function metaKey(string $batchId): string
    {
        return sprintf('%s:%s:meta', self::KEY_PREFIX, $batchId);
    }

    private function outputKey(string $batchId, int $index): string
    {
        return sprintf('%s:%s:output:%d', self::KEY_PREFIX, $batchId, $index);
    }

    private function failureKey(string $batchId, int $index): string
    {
        return sprintf('%s:%s:failure:%d', self::KEY_PREFIX, $batchId, $index);
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
