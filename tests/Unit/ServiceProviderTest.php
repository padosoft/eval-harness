<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit;

use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase;
use Padosoft\EvalHarness\EvalHarnessServiceProvider;

/**
 * Smoke coverage for the v0.0.1 scaffold.
 *
 * The package currently ships an empty no-op `EvalHarnessServiceProvider`;
 * real bindings land during v0.2 development. This test pins the
 * scaffold's two non-negotiable contracts so a future regression in
 * the auto-discovery wiring fails CI immediately:
 *
 *   1. The provider is a true `Illuminate\Support\ServiceProvider`
 *      subclass (auto-discovery requires this).
 *   2. `register()` and `boot()` execute cleanly when invoked
 *      directly on a Testbench app instance — both are no-ops in
 *      v0.0.1, so the test simply exercises the code path and
 *      asserts that no exception escaped.
 *
 * When v0.2 brings real bindings, replace these assertions with
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

    public function test_register_and_boot_complete_without_throwing(): void
    {
        $app = $this->app;

        $this->assertInstanceOf(
            Application::class,
            $app,
            'Testbench should provide a concrete Laravel application instance.',
        );

        // Construct a fresh provider and invoke both methods directly
        // — Testbench's setUp() also calls them, but invoking
        // explicitly here means a future regression that throws from
        // either method fails THIS test with a clear stack trace
        // instead of failing the whole TestCase setUp(). Both
        // methods are no-ops in the v0.0.1 scaffold, so reaching the
        // assertion is itself the green signal.
        $provider = new EvalHarnessServiceProvider($app);

        $provider->register();
        $provider->boot();

        $this->assertTrue(
            $app->providerIsLoaded(EvalHarnessServiceProvider::class),
            'Testbench should have registered the provider during setUp().',
        );
    }
}
