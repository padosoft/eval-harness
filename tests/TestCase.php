<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Padosoft\EvalHarness\EvalHarnessServiceProvider;
use Padosoft\EvalHarness\Facades\EvalFacade;

/**
 * Base TestCase for unit tests that need the package's container
 * bindings (EvalEngine, MetricResolver, YamlDatasetLoader, etc.).
 *
 * Architecture tests under tests/Architecture/ extend
 * PHPUnit\Framework\TestCase directly because they only need
 * file-system primitives — keeping Testbench out of that layer
 * means a slow Composer install does not slow architecture
 * assertions either.
 */
abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [EvalHarnessServiceProvider::class];
    }

    /**
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return [
            'Eval' => EvalFacade::class,
        ];
    }
}
