<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Online;

use Illuminate\Contracts\Config\Repository;
use Padosoft\EvalHarness\Online\OnlineSamplingDecision;
use Padosoft\EvalHarness\Tests\TestCase;

final class OnlineSamplingDecisionTest extends TestCase
{
    private function decision(float $randomValue): OnlineSamplingDecision
    {
        /** @var Repository $config */
        $config = $this->app->make(Repository::class);

        return new OnlineSamplingDecision($config, fn (): float => $randomValue);
    }

    public function test_disabled_never_samples(): void
    {
        config()->set('eval-harness.online.enabled', false);
        config()->set('eval-harness.online.sampling_rate', 1.0);

        $this->assertFalse($this->decision(0.0)->shouldSample());
    }

    public function test_zero_rate_never_samples(): void
    {
        config()->set('eval-harness.online.enabled', true);
        config()->set('eval-harness.online.sampling_rate', 0.0);

        $this->assertFalse($this->decision(0.0)->shouldSample());
    }

    public function test_full_rate_always_samples(): void
    {
        config()->set('eval-harness.online.enabled', true);
        config()->set('eval-harness.online.sampling_rate', 1.0);

        $this->assertTrue($this->decision(0.99)->shouldSample());
    }

    public function test_fractional_rate_respects_randomizer(): void
    {
        config()->set('eval-harness.online.enabled', true);
        config()->set('eval-harness.online.sampling_rate', 0.5);

        $this->assertTrue($this->decision(0.4)->shouldSample());
        $this->assertFalse($this->decision(0.6)->shouldSample());
    }
}
