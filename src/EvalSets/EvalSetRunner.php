<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\EvalSets;

use Padosoft\EvalHarness\Batches\BatchOptions;
use Padosoft\EvalHarness\Contracts\SampleRunner;
use Padosoft\EvalHarness\EvalEngine;
use Padosoft\EvalHarness\Exceptions\EvalRunException;
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

        $this->assertCanStart($definition, $systemUnderTest, $batchOptions);

        $reports = [];
        foreach ($definition->datasetNames as $datasetName) {
            $status = $manifest->statusFor($datasetName);
            if ($status === EvalSetManifestEntry::STATUS_COMPLETED) {
                continue;
            }

            if ($status === EvalSetManifestEntry::STATUS_FAILED) {
                return new EvalSetRunResult(
                    definition: $definition,
                    manifest: $manifest,
                    reports: $reports,
                );
            }

            $manifest = $manifest->markRunning($datasetName);

            try {
                $report = $this->engine->runBatch($datasetName, $systemUnderTest, $batchOptions);
            } catch (Throwable $e) {
                if ($this->shouldSurface($e)) {
                    throw $e;
                }

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

    private function assertCanStart(EvalSetDefinition $definition, callable|SampleRunner $systemUnderTest, BatchOptions $batchOptions): void
    {
        foreach ($definition->datasetNames as $datasetName) {
            $this->engine->getDataset($datasetName);
        }

        if ($batchOptions->mode === BatchOptions::MODE_LAZY_PARALLEL && ! $this->isSampleRunnerSystemUnderTest($systemUnderTest)) {
            throw new EvalRunException(
                'Lazy parallel batch mode requires a SampleRunner system-under-test; arbitrary callables and closures are not queue-serializable.',
            );
        }
    }

    private function isSampleRunnerSystemUnderTest(callable|SampleRunner $systemUnderTest): bool
    {
        if ($systemUnderTest instanceof SampleRunner) {
            return true;
        }

        return is_array($systemUnderTest)
            && $systemUnderTest[0] instanceof SampleRunner
            && $systemUnderTest[1] === 'run';
    }

    private function shouldSurface(Throwable $e): bool
    {
        if (! $e instanceof EvalRunException) {
            return false;
        }

        $message = $e->getMessage();

        return str_starts_with($message, 'Failed to resolve lazy parallel batch services:')
            || str_starts_with($message, 'Container binding for ')
            || str_starts_with($message, 'Lazy parallel batch mode requires ')
            || str_starts_with($message, 'Lazy parallel batch mode could not resolve ');
    }
}
