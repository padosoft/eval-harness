<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Batches;

/**
 * Optional extension of {@see BatchProgressReporter} that also
 * receives an explicit terminal-status signal at end of batch.
 *
 * In-progress checkpoints flow through `reportCheckpoint()` as before.
 * A dashboard or log forwarder that needs to distinguish "this batch
 * has finished and ended successfully", "this batch has finished and
 * failed", or "this batch was empty" — independently of progress
 * counters that may incidentally match an earlier in-progress
 * emission — should implement this contract instead of the bare
 * `BatchProgressReporter`. {@see LazyParallelBatch} prefers
 * `reportTerminal()` when the bound reporter implements this
 * sub-contract; otherwise it falls back to the legacy
 * `reportCheckpoint()` based emission for backward compatibility.
 */
interface BatchTerminalProgressReporter extends BatchProgressReporter
{
    /** Batch finished successfully and produced every expected output. */
    public const STATUS_SUCCESS = 'success';

    /**
     * Batch terminated through the failure path (dispatch failure,
     * worker failure, deadline, or other thrown exception). The
     * `samplesCompleted` count reflects the per-window counter at
     * the time of failure and therefore may under-report by up to
     * one chunk for partial wins recorded by workers in the failed
     * window.
     */
    public const STATUS_FAILURE = 'failure';

    /** Empty / filtered batch finished without dispatching any sample. */
    public const STATUS_EMPTY = 'empty';

    public function reportTerminal(string $batchId, int $samplesCompleted, int $totalSamples, string $status): void;
}
