<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Support;

use Illuminate\Config\Repository;
use Padosoft\EvalHarness\Support\RuntimeOptions;
use PHPUnit\Framework\TestCase;

final class RuntimeOptionsTest extends TestCase
{
    public function test_boolean_values_are_normalized(): void
    {
        $this->assertTrue(RuntimeOptions::normalizeBoolean(true, false));
        $this->assertTrue(RuntimeOptions::normalizeBoolean('true', false));
        $this->assertTrue(RuntimeOptions::normalizeBoolean('1', false));
        $this->assertTrue(RuntimeOptions::normalizeBoolean('yes', false));

        $this->assertFalse(RuntimeOptions::normalizeBoolean(false, true));
        $this->assertFalse(RuntimeOptions::normalizeBoolean('false', true));
        $this->assertFalse(RuntimeOptions::normalizeBoolean('0', true));
        $this->assertFalse(RuntimeOptions::normalizeBoolean('no', true));
    }

    public function test_invalid_boolean_values_fall_back_to_default(): void
    {
        $this->assertTrue(RuntimeOptions::normalizeBoolean('', true));
        $this->assertTrue(RuntimeOptions::normalizeBoolean('definitely', true));
        $this->assertFalse(RuntimeOptions::normalizeBoolean(2, false));
        $this->assertFalse(RuntimeOptions::normalizeBoolean(['true'], false));
    }

    public function test_non_negative_int_values_are_normalized(): void
    {
        $this->assertSame(0, RuntimeOptions::normalizeNonNegativeInt(0, 5));
        $this->assertSame(12, RuntimeOptions::normalizeNonNegativeInt('12', 5));
        $this->assertSame(12, RuntimeOptions::normalizeNonNegativeInt('0012', 5));
        $this->assertSame(12, RuntimeOptions::normalizeNonNegativeInt(12.9, 5));
        $this->assertSame(12, RuntimeOptions::normalizeNonNegativeInt('12.9', 5));
    }

    public function test_invalid_non_negative_int_values_fall_back_to_default(): void
    {
        $this->assertSame(5, RuntimeOptions::normalizeNonNegativeInt(-1, 5));
        $this->assertSame(5, RuntimeOptions::normalizeNonNegativeInt('-1', 5));
        $this->assertSame(5, RuntimeOptions::normalizeNonNegativeInt('', 5));
        $this->assertSame(5, RuntimeOptions::normalizeNonNegativeInt('abc', 5));
        $this->assertSame(5, RuntimeOptions::normalizeNonNegativeInt(INF, 5));
        $this->assertSame(5, RuntimeOptions::normalizeNonNegativeInt('999999999999999999999', 5));
        $this->assertSame(5, RuntimeOptions::normalizeNonNegativeInt(1.0e100, 5));
        $this->assertSame(0, RuntimeOptions::normalizeNonNegativeInt('abc', -1));
    }

    public function test_runtime_values_are_read_from_config(): void
    {
        $config = new Repository([
            'eval-harness' => [
                'runtime' => [
                    'raise_exceptions' => 'true',
                    'provider_retry_attempts' => '2',
                    'provider_retry_sleep_milliseconds' => '25',
                ],
            ],
        ]);

        $this->assertTrue(RuntimeOptions::raiseMetricExceptions($config));
        $this->assertSame(2, RuntimeOptions::providerRetryAttempts($config));
        $this->assertSame(3, RuntimeOptions::providerMaxAttempts($config));
        $this->assertSame(25, RuntimeOptions::providerRetrySleepMilliseconds($config));
    }
}
