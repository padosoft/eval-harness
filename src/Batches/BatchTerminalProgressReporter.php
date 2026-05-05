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
 * `BatchProgressReporter`.
 *
 * Scope: `reportTerminal()` is only emitted from
 * {@see LazyParallelBatch::run()} (the synchronous engine flow used by
 * `EvalEngine::runBatch()` / `runEvalSet()`). Callers using the public
 * fire-and-return `LazyParallelBatch::dispatch()` /
 * `collectOutputs()` flow do NOT receive a terminal callback —
 * dispatch enqueues jobs and returns; collection is driven by an
 * out-of-band scheduler that has no producer-side signal point. Host
 * apps using the external dispatch/collect flow should observe
 * terminal state via their own job-completion bookkeeping.
 *
 * When the bound reporter implements this sub-contract, `run()` emits
 * `reportTerminal()` once at end of batch regardless of whether
 * `--checkpoint-every` is set.
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
     * windows AND a best-effort count of stored successes inside the
     * failed window (bounded by chunk size for cost), so partial wins
     * are included on the happy reporting path.
     *
     * Best-effort caveat: when the result-store query for the failed
     * window's success count itself throws (cache outage, transient
     * driver error), the partial count falls back to 0 to keep the
     * failure path moving — already-flushed successes inside the
     * failed window are dropped from `samplesCompleted` in that
     * branch. The original sample-level exception always propagates
     * cleanly; the reporter event is best-effort telemetry, not the
     * authoritative completion record.
     */
    public const STATUS_FAILURE = 'failure';

    /** Empty / filtered batch finished without dispatching any sample. */
    public const STATUS_EMPTY = 'empty';

    public function reportTerminal(string $batchId, int $samplesCompleted, int $totalSamples, string $status): void;
}
