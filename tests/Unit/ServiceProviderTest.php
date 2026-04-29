<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit;

use Orchestra\Testbench\TestCase;
use Padosoft\EvalHarness\EvalHarnessServiceProvider;

/**
 * Smoke test — verifies the service provider boots inside a Testbench
 * Laravel application.
 *
 * This is the v0.0.1 scaffold gate: as concrete bindings and tests
 * land during v4.0 development, this file stays as the "package
 * health" check. Real coverage (golden datasets, LLM-as-judge,
 * adversarial harness, regression detection) lands when the
 * implementation does.
 */
final class ServiceProviderTest extends TestCase
{
    public function test_service_provider_boots_without_errors(): void
    {
        $providers = $this->app->getLoadedProviders();

        $this->assertArrayHasKey(EvalHarnessServiceProvider::class, $providers);
        $this->assertTrue($providers[EvalHarnessServiceProvider::class]);
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [EvalHarnessServiceProvider::class];
    }
}
