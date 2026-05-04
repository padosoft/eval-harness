<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Support;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Normalizes runtime config that may come from env values or host-app
 * config overrides after the package service provider has booted.
 */
final class RuntimeOptions
{
    public static function raiseMetricExceptions(ConfigRepository $config): bool
    {
        return self::normalizeBoolean(
            $config->get('eval-harness.runtime.raise_exceptions'),
            false,
        );
    }

    public static function providerRetryAttempts(ConfigRepository $config): int
    {
        return self::normalizeNonNegativeInt(
            $config->get('eval-harness.runtime.provider_retry_attempts'),
            0,
        );
    }

    public static function providerRetrySleepMilliseconds(ConfigRepository $config): int
    {
        return self::normalizeNonNegativeInt(
            $config->get('eval-harness.runtime.provider_retry_sleep_milliseconds'),
            100,
        );
    }

    public static function providerMaxAttempts(ConfigRepository $config): int
    {
        return self::providerRetryAttempts($config) + 1;
    }

    public static function normalizeBoolean(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return match ($value) {
                0 => false,
                1 => true,
                default => $default,
            };
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return $default;
            }

            $filtered = filter_var(
                $trimmed,
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE,
            );

            return is_bool($filtered) ? $filtered : $default;
        }

        return $default;
    }

    public static function normalizeNonNegativeInt(mixed $value, int $default): int
    {
        $safeDefault = $default >= 0 ? $default : 0;

        if ($value === null) {
            return $safeDefault;
        }

        if (is_int($value)) {
            return $value >= 0 ? $value : $safeDefault;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return $safeDefault;
            }

            if (ctype_digit($trimmed)) {
                $normalized = ltrim($trimmed, '0');
                if ($normalized === '') {
                    $normalized = '0';
                }

                $filtered = filter_var(
                    $normalized,
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 0]],
                );

                return is_int($filtered) ? $filtered : $safeDefault;
            }

            $filtered = filter_var(
                $trimmed,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 0]],
            );

            if (is_int($filtered)) {
                return $filtered;
            }

            $float = filter_var($trimmed, FILTER_VALIDATE_FLOAT);
            if (is_float($float) && ! is_nan($float) && ! is_infinite($float) && $float >= 0.0) {
                return self::floorToNonNegativeInt($float, $safeDefault);
            }

            return $safeDefault;
        }

        if (is_float($value)) {
            return self::floorToNonNegativeInt($value, $safeDefault);
        }

        return $safeDefault;
    }

    private static function floorToNonNegativeInt(float $value, int $safeDefault): int
    {
        if (is_nan($value) || is_infinite($value) || $value < 0.0 || $value > (float) PHP_INT_MAX) {
            return $safeDefault;
        }

        return (int) floor($value);
    }
}
