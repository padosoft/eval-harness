<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Contracts;

/**
 * Optional provider-side usage channel for metrics that need vectors or
 * judge content as their primary return value.
 */
interface ProvidesUsageDetails
{
    /**
     * Returns structured usage details from the most recent provider call.
     *
     * Implementations must clear prior usage at the start of every
     * request/score attempt and return an empty array when the current
     * attempt fails before any reportable provider usage exists.
     *
     * @return array<string, int|float>
     */
    public function usageDetails(): array;
}
