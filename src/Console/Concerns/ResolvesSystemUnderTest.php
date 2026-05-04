<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Console\Concerns;

use Padosoft\EvalHarness\Contracts\SampleRunner;
use Padosoft\EvalHarness\EvalEngine;

trait ResolvesSystemUnderTest
{
    private function resolveSystemUnderTest(EvalEngine $engine): callable|SampleRunner|null
    {
        if (! $engine->container()->bound('eval-harness.sut')) {
            $this->error(
                "No system-under-test bound under 'eval-harness.sut'. Bind a callable with \$container->bind('eval-harness.sut', fn () => fn (array \$in) => ...), or bind a SampleRunner class with \$container->bind('eval-harness.sut', \\App\\Eval\\MyRunner::class).",
            );

            return null;
        }

        $sut = $engine->container()->make('eval-harness.sut');

        if (! $sut instanceof SampleRunner && ! is_callable($sut)) {
            $this->error(
                sprintf(
                    "System-under-test bound under 'eval-harness.sut' must resolve to a callable or SampleRunner; got %s. Update the binding to return a callable, or bind a SampleRunner class with \$container->bind('eval-harness.sut', \\App\\Eval\\MyRunner::class).",
                    get_debug_type($sut),
                ),
            );

            return null;
        }

        return $sut;
    }
}
