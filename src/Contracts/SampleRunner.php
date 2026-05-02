<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Contracts;

/**
 * Queue-friendly contract for invoking the system under test.
 *
 * The legacy `EvalEngine::run($dataset, callable $sut)` API stays
 * supported, but queue jobs cannot safely serialize arbitrary closures.
 * Host applications that plan to use parallel batches should pass a
 * SampleRunner instance or bind `eval-harness.sut` to a concrete
 * SampleRunner class so the container resolves it before execution.
 */
interface SampleRunner
{
    public function run(SampleInvocation $sample): string;
}
