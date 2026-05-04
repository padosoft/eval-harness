<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Support;

/**
 * Extracts safe, reportable usage fields from provider response bodies.
 */
final class ProviderUsageDetails
{
    /**
     * @param  array<mixed>  $body
     * @return array<string, int|float>
     */
    public static function fromResponseBody(array $body, float $latencyMs): array
    {
        $details = [];
        $rawUsage = $body['usage'] ?? null;

        if (is_array($rawUsage)) {
            foreach (['prompt_tokens', 'completion_tokens', 'total_tokens'] as $key) {
                $value = self::nonNegativeInt($rawUsage[$key] ?? null);
                if ($value !== null) {
                    $details[$key] = $value;
                }
            }

            $costUsd = self::nonNegativeFloat($rawUsage['cost_usd'] ?? null)
                ?? self::nonNegativeFloat($rawUsage['total_cost_usd'] ?? null);
            if ($costUsd !== null) {
                $details['cost_usd'] = $costUsd;
            }
        }

        if ($latencyMs >= 0.0 && ! is_nan($latencyMs) && ! is_infinite($latencyMs)) {
            $details['latency_ms'] = $latencyMs;
        }

        return $details;
    }

    private static function nonNegativeInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (! is_string($value) || ! ctype_digit($value)) {
            return null;
        }

        $filtered = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0]],
        );

        return is_int($filtered) ? $filtered : null;
    }

    private static function nonNegativeFloat(mixed $value): ?float
    {
        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $floatValue = (float) $value;

        return $floatValue >= 0.0 && ! is_nan($floatValue) && ! is_infinite($floatValue)
            ? $floatValue
            : null;
    }
}
