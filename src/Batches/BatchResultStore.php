<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Batches;

/**
 * Shared result store used by queued sample jobs and report assembly.
 */
interface BatchResultStore
{
    public function start(string $batchId, int $sampleCount, int $ttlSeconds): void;

    public function recordSuccess(string $batchId, int $index, string $sampleId, string $actualOutput, int $ttlSeconds): void;

    public function recordFailure(string $batchId, int $index, string $sampleId, string $error, int $ttlSeconds): void;

    /**
     * @return array<int, string>
     */
    public function successfulOutputs(string $batchId, int $sampleCount): array;

    /**
     * @return array<int, array{sample_id: string, error: string}>
     */
    public function failures(string $batchId, int $sampleCount): array;

    public function forget(string $batchId, int $sampleCount): void;
}
