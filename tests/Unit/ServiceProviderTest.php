<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit;

use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase;
use Padosoft\EvalHarness\EvalHarnessServiceProvider;

/**
 * Smoke coverage for the v0.0.1 scaffold.
 *
 * The package currently ships an empty no-op `EvalHarnessServiceProvider`;
 * real bindings land during v4.0 development. This test pins the
 * scaffold's two non-negotiable contracts so a future regression in
 * the auto-discovery wiring fails CI immediately:
 *
 *   1. The provider is a true `Illuminate\Support\ServiceProvider`
 *      subclass (auto-discovery requires this).
 *   2. Loading the provider into a fresh Testbench app does not throw
 *      — both `register()` and `boot()` complete cleanly even though
 *      they are no-ops.
 *
 * When v4.0 brings real bindings, replace these assertions with
 * coverage of the actual public surface.
 */
final class ServiceProviderTest extends TestCase
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [EvalHarnessServiceProvider::class];
    }

    public function test_service_provider_is_a_laravel_service_provider_subclass(): void
    {
        $reflection = new \ReflectionClass(EvalHarnessServiceProvider::class);

        $this->assertTrue(
            $reflection->isSubclassOf(ServiceProvider::class),
            'EvalHarnessServiceProvider must extend Illuminate\Support\ServiceProvider for Laravel package auto-discovery to wire it.',
        );
    }

    public function test_service_provider_boots_without_throwing(): void
    {
        // Reaching this assertion proves the Testbench setUp() chain
        // (which calls register() then boot() on every package
        // provider) completed without exceptions for the scaffold.
        $this->assertTrue($this->app->providerIsLoaded(EvalHarnessServiceProvider::class));
    }
}
