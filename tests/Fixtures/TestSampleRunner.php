<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Fixtures;

use Padosoft\EvalHarness\Contracts\SampleInvocation;
use Padosoft\EvalHarness\Contracts\SampleRunner;

final class TestSampleRunner implements SampleRunner
{
    public function run(SampleInvocation $sample): string
    {
        return 'hi';
    }
}
