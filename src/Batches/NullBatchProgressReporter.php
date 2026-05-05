<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Batches;

final class NullBatchProgressReporter implements BatchTerminalProgressReporter
{
    public function reportCheckpoint(string $batchId, int $samplesCompleted, int $totalSamples): void
    {
        // Intentional no-op. Host apps bind their own reporter when they want progress events.
    }

    public function reportTerminal(string $batchId, int $samplesCompleted, int $totalSamples, string $status): void
    {
        // Intentional no-op. Same rationale as reportCheckpoint().
    }
}
