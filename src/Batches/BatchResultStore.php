<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Batches;

/**
 * Shared result store used by queued sample jobs and report assembly.
 *
 * Implementations must keep one terminal result per sample index with
 * first-writer-wins semantics, ignore writes after finish()/abort(), and
 * keep finished successes readable until their effective TTL so external
 * collect retries remain idempotent.
 */
interface BatchResultStore
{
    /**
     * Create an active batch marker and retain metadata for at least the
     * effective TTL used by queued jobs.
     */
    public function start(string $batchId, int $sampleCount, int $ttlSeconds): void;

    public function sampleCount(string $batchId): ?int;

    public function ttlSeconds(string $batchId): ?int;

    /**
     * Mark a batch finished without deleting successful sample results.
     *
     * Existing successes must stay readable until TTL expiry, and future
     * recordSuccess()/recordFailure() calls for the batch must be ignored.
     */
    public function finish(string $batchId, int $sampleCount, int $ttlSeconds): void;

    /**
     * Mark a batch aborted. Future recordSuccess()/recordFailure() calls for
     * the batch must be ignored.
     */
    public function abort(string $batchId, int $sampleCount, int $ttlSeconds): void;

    /**
     * Record the first successful terminal result for an active sample index.
     */
    public function recordSuccess(string $batchId, int $index, string $sampleId, string $actualOutput, int $ttlSeconds): void;

    /**
     * Record the first failed terminal result for an active sample index.
     */
    public function recordFailure(string $batchId, int $index, string $sampleId, string $error, int $ttlSeconds): void;

    /**
     * @param  list<int>|null  $indexes
     * @return array<int, array{sample_id: string, actual_output: string}>
     */
    public function successfulResults(string $batchId, int $sampleCount, ?array $indexes = null): array;

    /**
     * @param  list<int>|null  $indexes
     * @return array<int, array{sample_id: string, error: string}>
     */
    public function failures(string $batchId, int $sampleCount, ?array $indexes = null): array;
}
