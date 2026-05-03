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

        foreach ($samples as $index => $sample) {
            $actualOutput = $actualOutputForSample($sample, $index);
            if (! is_string($actualOutput)) {
                throw new EvalRunException(sprintf(
                    "System-under-test for sample '%s' must return a string; got %s.",
                    $sample->id,
                    get_debug_type($actualOutput),
                ));
            }

            $actualOutputs[] = $actualOutput;
        }

        return $actualOutputs;
    }
}
