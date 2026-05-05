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
 * `BatchProgressReporter`. {@see LazyParallelBatch} emits
 * `reportTerminal()` whenever the bound reporter implements this
 * sub-contract, regardless of whether `--checkpoint-every` is set.
 *
 * Reporters bound only to the legacy `BatchProgressReporter` contract
 * receive the existing `reportCheckpoint()` emission AT END OF BATCH
 * ONLY when checkpoint reporting is enabled (`checkpointEvery !== null`).
 * Without `--checkpoint-every`, a legacy reporter receives no
 * terminal-equivalent event — implement this sub-contract to get a
 * guaranteed end-of-batch signal.
 */
interface BatchTerminalProgressReporter extends BatchProgressReporter
{
    /** Batch finished successfully and produced every expected output. */
    public const STATUS_SUCCESS = 'success';

    /**
     * Batch terminated through the failure path (dispatch failure,
     * worker failure, deadline, or other thrown exception). The
     * `samplesCompleted` count reflects the sum of fully-completed
     * windows AND the count of stored successes inside the failed
     * window (bounded by chunk size for cost), so partial wins are
     * included. Worst-case under-report is bounded by the number of
     * samples that succeeded but had not yet flushed their result to
     * the shared result store at the moment the failing exception
     * propagated.
     */
    public const STATUS_FAILURE = 'failure';

    /** Empty / filtered batch finished without dispatching any sample. */
    public const STATUS_EMPTY = 'empty';

    public function reportTerminal(string $batchId, int $samplesCompleted, int $totalSamples, string $status): void;
}
