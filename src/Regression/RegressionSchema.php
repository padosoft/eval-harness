<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Regression;

/**
 * Contract identifiers for the regression surface.
 *
 * The comparison payload and the baseline pointer are both consumed outside
 * this package — by CI scripts, by the admin panel — so both carry an explicit
 * version and evolve additively, exactly like the report contract.
 */
final class RegressionSchema
{
    public const VERSION = 'eval-harness.comparison.v1';

    public const BASELINE_VERSION = 'eval-harness.baseline.v1';
}
