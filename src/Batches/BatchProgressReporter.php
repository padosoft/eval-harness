<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Batches;

/**
 * Optional sink for batch progress checkpoints.
 *
 * Implementations should be queue/Horizon-friendly: log lines, push events,
 * persist a heartbeat row, etc. The package never assumes a specific
 * transport, and an unbound reporter is treated as a no-op.
 */
interface BatchProgressReporter
{
    public function reportCheckpoint(string $batchId, int $samplesCompleted, int $totalSamples): void;
}
