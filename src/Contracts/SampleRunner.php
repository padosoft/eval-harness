<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Contracts;

use Padosoft\EvalHarness\Datasets\DatasetSample;

/**
 * Queue-friendly contract for invoking the system under test.
 *
 * The legacy `EvalEngine::run($dataset, callable $sut)` API stays
 * supported, but queue jobs cannot safely serialize arbitrary closures.
 * Host applications that plan to use parallel batches should bind or
 * pass a concrete SampleRunner class instead.
 */
interface SampleRunner
{
    public function run(DatasetSample $sample): string;
}
