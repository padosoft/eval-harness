<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Support;

use Padosoft\EvalHarness\Contracts\ProvidesUsageDetails;

/**
 * Adds provider usage to metric details without changing primary contracts.
 */
final class MetricUsageDetails
{
    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    public static function append(array $details, object $provider): array
    {
        $usage = self::from($provider);
        if ($usage === []) {
            return $details;
        }

        $details['usage'] = $usage;

        return $details;
    }

    /**
     * @return array<string, int|float>
     */
    public static function from(object $provider): array
    {
        if (! $provider instanceof ProvidesUsageDetails) {
            return [];
        }

        return $provider->usageDetails();
    }
}
