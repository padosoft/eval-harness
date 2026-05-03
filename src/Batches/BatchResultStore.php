<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Batches;

/**
 * Shared result store used by queued sample jobs and report assembly.
 */
interface BatchResultStore
{
    public function start(string $batchId, int $sampleCount, int $ttlSeconds): void;

    public function sampleCount(string $batchId): ?int;

    public function ttlSeconds(string $batchId): ?int;

    public function finish(string $batchId, int $sampleCount, int $ttlSeconds): void;

    public function abort(string $batchId, int $sampleCount, int $ttlSeconds): void;

    public function recordSuccess(string $batchId, int $index, string $sampleId, string $actualOutput, int $ttlSeconds): void;

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
