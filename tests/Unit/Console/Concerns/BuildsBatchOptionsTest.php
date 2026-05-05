<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Console\Concerns;

use Illuminate\Contracts\Console\Kernel;
use Padosoft\EvalHarness\Batches\BatchOptions;
use Padosoft\EvalHarness\Batches\BatchProfileResolver;
use Padosoft\EvalHarness\Tests\Fixtures\CapturingBatchOptionsCommand;
use Padosoft\EvalHarness\Tests\TestCase;

final class BuildsBatchOptionsTest extends TestCase
{
    private CapturingBatchOptionsCommand $command;

    protected function setUp(): void
    {
        parent::setUp();

        $this->command = new CapturingBatchOptionsCommand;
        $this->app->make(Kernel::class)->registerCommand($this->command);
    }

    public function test_profile_result_ttl_seconds_reaches_batch_options(): void
    {
        // Pinning regression: BuildsBatchOptions maps the profile's
        // result_ttl_seconds into BatchOptions::resultTtlSeconds. A
        // regression here would only surface in delayed dispatch /
        // collectOutputs flows when batch metadata expires in
        // production, so keep an explicit unit-level assertion.
        config(['eval-harness.batches.profiles.custom-ttl' => [
            'mode' => BatchOptions::MODE_LAZY_PARALLEL,
            'concurrency' => 4,
            'queue' => 'evals',
            'timeout_seconds' => 30,
            'wait_timeout_seconds' => 120,
            'result_ttl_seconds' => 4000,
        ]]);
        $this->app->forgetInstance(BatchProfileResolver::class);

        $this->artisan('eval-harness-test:capture-batch', [
            '--batch-profile' => 'custom-ttl',
        ])->assertExitCode(0);

        $captured = $this->command->captured;
        $this->assertNotNull($captured);
        $this->assertSame(BatchOptions::MODE_LAZY_PARALLEL, $captured->mode);
        $this->assertSame(4, $captured->concurrency);
        $this->assertSame('evals', $captured->queue);
        $this->assertSame(30, $captured->timeoutSeconds);
        $this->assertSame(120, $captured->waitTimeoutSeconds);
        $this->assertSame(4000, $captured->resultTtlSeconds);
        $this->assertSame('custom-ttl', $captured->profile);
    }

    public function test_explicit_cli_options_override_profile_defaults(): void
    {
        // Pair to the profile-mapping test above: the same custom
        // profile must be overridable by explicit CLI options. Without
        // this assertion a regression that ignored CLI flags entirely
        // would still pass the profile-mapping test.
        config(['eval-harness.batches.profiles.custom-ttl' => [
            'mode' => BatchOptions::MODE_LAZY_PARALLEL,
            'concurrency' => 4,
            'queue' => 'evals',
            'timeout_seconds' => 30,
            'wait_timeout_seconds' => 120,
            'result_ttl_seconds' => 4000,
        ]]);
        $this->app->forgetInstance(BatchProfileResolver::class);

        $this->artisan('eval-harness-test:capture-batch', [
            '--batch-profile' => 'custom-ttl',
            '--queue' => 'evals-override',
            '--timeout' => '90',
        ])->assertExitCode(0);

        $captured = $this->command->captured;
        $this->assertNotNull($captured);
        $this->assertSame('evals-override', $captured->queue);
        $this->assertSame(90, $captured->timeoutSeconds);
        // Fields not overridden on the CLI keep the profile defaults.
        $this->assertSame(4, $captured->concurrency);
        $this->assertSame(120, $captured->waitTimeoutSeconds);
        $this->assertSame(4000, $captured->resultTtlSeconds);
    }

    public function test_profile_lazy_only_fields_drop_when_explicit_batch_serial(): void
    {
        // Pair to the profile/serial flip behaviour: explicit
        // --batch=serial must drop the profile's lazy-only fields so
        // BatchOptions stays valid for serial mode.
        config(['eval-harness.batches.profiles.custom-ttl' => [
            'mode' => BatchOptions::MODE_LAZY_PARALLEL,
            'concurrency' => 4,
            'queue' => 'evals',
            'timeout_seconds' => 30,
            'wait_timeout_seconds' => 120,
            'result_ttl_seconds' => 4000,
        ]]);
        $this->app->forgetInstance(BatchProfileResolver::class);

        $this->artisan('eval-harness-test:capture-batch', [
            '--batch-profile' => 'custom-ttl',
            '--batch' => 'serial',
        ])->assertExitCode(0);

        $captured = $this->command->captured;
        $this->assertNotNull($captured);
        $this->assertSame(BatchOptions::MODE_SERIAL, $captured->mode);
        $this->assertSame(1, $captured->concurrency);
        $this->assertNull($captured->queue);
        $this->assertNull($captured->timeoutSeconds);
        $this->assertNull($captured->waitTimeoutSeconds);
        $this->assertNull($captured->resultTtlSeconds);
        $this->assertSame('custom-ttl', $captured->profile);
    }
}
