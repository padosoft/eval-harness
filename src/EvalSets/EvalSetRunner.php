<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\EvalSets;

use Padosoft\EvalHarness\Batches\BatchOptions;
use Padosoft\EvalHarness\Contracts\SampleRunner;
use Padosoft\EvalHarness\EvalEngine;
use Throwable;

/**
 * Runs named groups of registered datasets and records resumable progress.
 */
final class EvalSetRunner
{
    public function __construct(private readonly EvalEngine $engine) {}

    public function run(
        EvalSetDefinition $definition,
        callable|SampleRunner $systemUnderTest,
        ?BatchOptions $batchOptions = null,
        ?EvalSetManifest $manifest = null,
    ): EvalSetRunResult {
        $manifest ??= EvalSetManifest::start($definition);
        $manifest->assertMatches($definition);
        $batchOptions ??= BatchOptions::serial();

        $reports = [];
        foreach ($definition->datasetNames as $datasetName) {
            if ($manifest->statusFor($datasetName) === EvalSetManifestEntry::STATUS_COMPLETED) {
                continue;
            }

            $manifest = $manifest->markRunning($datasetName);

            try {
                $report = $this->engine->runBatch($datasetName, $systemUnderTest, $batchOptions);
            } catch (Throwable $e) {
                $manifest = $manifest->markFailed($datasetName, $this->failureMessage($e));

                return new EvalSetRunResult(
                    definition: $definition,
                    manifest: $manifest,
                    reports: $reports,
                );
            }

            $manifest = $manifest->markCompleted($datasetName, $report);
            $reports[] = $report;
        }

        return new EvalSetRunResult(
            definition: $definition,
            manifest: $manifest,
            reports: $reports,
        );
    }

    private function failureMessage(Throwable $e): string
    {
        $message = trim($e->getMessage());

        return $message !== '' ? $message : $e::class;
    }
}
