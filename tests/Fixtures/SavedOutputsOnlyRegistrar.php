<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Fixtures;

use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\EvalEngine;

/**
 * Test fixture for `eval-harness:run --registrar=... --outputs=...`.
 *
 * It intentionally does not bind `eval-harness.sut`; saved-output
 * scoring only needs the registrar to make the dataset available.
 */
final class SavedOutputsOnlyRegistrar
{
    public function __invoke(EvalEngine $engine): void
    {
        $engine->dataset('cli.saved-output-registrar')
            ->withSamples([
                new DatasetSample(id: 's1', input: [], expectedOutput: 'hello'),
                new DatasetSample(id: 's2', input: [], expectedOutput: 'world'),
            ])
            ->withMetrics(['exact-match'])
            ->register();
    }
}
