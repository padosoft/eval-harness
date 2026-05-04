<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Console\Concerns;

use Padosoft\EvalHarness\EvalEngine;
use Padosoft\EvalHarness\Exceptions\EvalRunException;

trait DispatchesEvalRegistrars
{
    private function dispatchRegistrar(EvalEngine $engine, string $registrarClass): void
    {
        if (! class_exists($registrarClass)) {
            throw new EvalRunException(
                sprintf("Registrar class '%s' does not exist.", $registrarClass),
            );
        }

        $instance = $engine->container()->make($registrarClass);

        if (! is_callable($instance)) {
            throw new EvalRunException(
                sprintf(
                    "Registrar '%s' must be an invokable class (define __invoke(EvalEngine \$engine): void).",
                    $registrarClass,
                ),
            );
        }

        $instance($engine);
    }
}
