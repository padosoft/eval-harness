<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Batches;

use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Exceptions\EvalRunException;

/**
 * Deterministic in-process sample batch runner.
 *
 * This is the baseline used by unit tests and by the default Artisan
 * path. Queue-backed runners must preserve the same output ordering
 * contract even when samples complete out of order.
 */
final class SerialBatch
{
    /**
     * @param  list<DatasetSample>  $samples
     * @param  callable(DatasetSample, int): string  $actualOutputForSample
     * @return list<string>
     */
    public function run(array $samples, callable $actualOutputForSample): array
    {
        $actualOutputs = [];

        $this->runEach(
            samples: $samples,
            actualOutputForSample: $actualOutputForSample,
            handleOutput: static function (DatasetSample $_sample, int $_index, string $actualOutput) use (&$actualOutputs): void {
                $actualOutputs[] = $actualOutput;
            },
        );

        return $actualOutputs;
    }

    /**
     * @param  list<DatasetSample>  $samples
     * @param  callable(DatasetSample, int): string  $actualOutputForSample
     * @param  callable(DatasetSample, int, string): void  $handleOutput
     */
    public function runEach(array $samples, callable $actualOutputForSample, callable $handleOutput): void
    {
        $this->assertSampleList($samples);

        foreach ($samples as $index => $sample) {
            $actualOutput = $actualOutputForSample($sample, $index);
            if (! is_string($actualOutput)) {
                throw new EvalRunException(sprintf(
                    "System-under-test for sample '%s' must return a string; got %s.",
                    $sample->id,
                    get_debug_type($actualOutput),
                ));
            }

            $handleOutput($sample, $index, $actualOutput);
        }
    }

    /**
     * @param  array<array-key, mixed>  $samples
     */
    private function assertSampleList(array $samples): void
    {
        if (! array_is_list($samples)) {
            throw new EvalRunException('Serial batch samples must be a zero-based list.');
        }

        foreach ($samples as $index => $sample) {
            if (! $sample instanceof DatasetSample) {
                throw new EvalRunException(sprintf(
                    'Serial batch sample at index %d must be an instance of %s; got %s.',
                    $index,
                    DatasetSample::class,
                    get_debug_type($sample),
                ));
            }
        }
    }
}
