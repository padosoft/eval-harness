<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Online;

use Illuminate\Support\Facades\Bus;
use Padosoft\EvalHarness\Online\JudgeLiveSampleJob;
use Padosoft\EvalHarness\Online\OnlineMonitor;
use Padosoft\EvalHarness\Tests\TestCase;

final class OnlineMonitorTest extends TestCase
{
    public function test_disabled_does_not_dispatch(): void
    {
        Bus::fake();
        config()->set('eval-harness.online.enabled', false);
        config()->set('eval-harness.online.sampling_rate', 1.0);

        $captured = $this->app->make(OnlineMonitor::class)
            ->capture('rag.faq', 's1', ['question' => 'q'], 'Paris', 'Paris');

        $this->assertFalse($captured);
        Bus::assertNothingDispatched();
    }

    public function test_sampled_capture_dispatches_job(): void
    {
        Bus::fake();
        config()->set('eval-harness.online.enabled', true);
        config()->set('eval-harness.online.sampling_rate', 1.0);

        $captured = $this->app->make(OnlineMonitor::class)
            ->capture('rag.faq', 's1', ['question' => 'q'], 'Paris', 'Paris');

        $this->assertTrue($captured);
        Bus::assertDispatched(
            JudgeLiveSampleJob::class,
            static fn (JudgeLiveSampleJob $job): bool => $job->dataset === 'rag.faq' && $job->sampleId === 's1',
        );
    }
}
