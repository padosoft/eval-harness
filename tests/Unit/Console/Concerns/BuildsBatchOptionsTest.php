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

    public function test_empty_new_batch_flags_fall_back_to_profile_defaults(): void
    {
        // Pin the documented "--flag= falls back to default" pattern
        // for the new options so unset CI variables can keep using the
        // profile defaults. Without this, a parser regression on the
        // new flags would silently break the CI-variable workflow.
        config(['eval-harness.batches.profiles.observable-defaults' => [
            'mode' => BatchOptions::MODE_LAZY_PARALLEL,
            'concurrency' => 8,
            'queue' => 'evals',
            'timeout_seconds' => 30,
            'wait_timeout_seconds' => 120,
            'chunk_size' => 8,
            'rate_limit' => 30,
            'rate_window_seconds' => 60,
            'checkpoint_every' => 25,
            'result_ttl_seconds' => 4000,
        ]]);
        $this->app->forgetInstance(BatchProfileResolver::class);

        $this->artisan('eval-harness-test:capture-batch', [
            '--batch-profile' => 'observable-defaults',
            '--chunk-size' => '',
            '--rate-limit' => '',
            '--rate-window-seconds' => '',
            '--checkpoint-every' => '',
        ])->assertExitCode(0);

        $captured = $this->command->captured;
        $this->assertNotNull($captured);
        $this->assertSame(8, $captured->chunkSize);
        $this->assertSame(30, $captured->rateLimit);
        $this->assertSame(60, $captured->rateWindowSeconds);
        $this->assertSame(25, $captured->checkpointEvery);
    }

    public function test_empty_new_batch_flags_fall_back_to_baseline_defaults_without_profile(): void
    {
        // Without a profile and without explicit values, the new flags
        // must remain null so BatchOptions stays at the conservative
        // baseline.
        $this->artisan('eval-harness-test:capture-batch', [
            '--batch' => 'lazy-parallel',
            '--concurrency' => '4',
            '--queue' => 'evals',
            '--chunk-size' => '',
            '--rate-limit' => '',
            '--rate-window-seconds' => '',
            '--checkpoint-every' => '',
        ])->assertExitCode(0);

        $captured = $this->command->captured;
        $this->assertNotNull($captured);
        $this->assertSame(BatchOptions::MODE_LAZY_PARALLEL, $captured->mode);
        $this->assertNull($captured->chunkSize);
        $this->assertNull($captured->rateLimit);
        $this->assertNull($captured->rateWindowSeconds);
        $this->assertNull($captured->checkpointEvery);
    }

    public function test_explicit_none_sentinel_clears_inherited_profile_int_fields(): void
    {
        // Documented contract: pass `--flag=none` (or `null`) to clear
        // a numeric value inherited from a profile. Without the
        // sentinel the profile would be sticky because empty strings
        // fall back to the profile default.
        config(['eval-harness.batches.profiles.nightly-strict' => [
            'mode' => BatchOptions::MODE_LAZY_PARALLEL,
            'concurrency' => 8,
            'queue' => 'evals-nightly',
            'timeout_seconds' => 60,
            'wait_timeout_seconds' => 600,
            'chunk_size' => 8,
            'rate_limit' => 60,
            'rate_window_seconds' => 60,
            'checkpoint_every' => 100,
        ]]);
        $this->app->forgetInstance(BatchProfileResolver::class);

        $this->artisan('eval-harness-test:capture-batch', [
            '--batch-profile' => 'nightly-strict',
            '--rate-limit' => 'none',
            '--rate-window-seconds' => 'none',
            '--checkpoint-every' => 'NONE',
        ])->assertExitCode(0);

        $captured = $this->command->captured;
        $this->assertNotNull($captured);
        $this->assertNull($captured->rateLimit);
        $this->assertNull($captured->rateWindowSeconds);
        $this->assertNull($captured->checkpointEvery);
        // Other profile fields stay applied because they were not cleared.
        $this->assertSame(8, $captured->concurrency);
        $this->assertSame('evals-nightly', $captured->queue);
        $this->assertSame(60, $captured->timeoutSeconds);
        $this->assertSame(8, $captured->chunkSize);
    }

    public function test_queue_none_is_treated_as_a_real_queue_name(): void
    {
        // Regression: queue names are arbitrary strings, so the `none`
        // sentinel that clears integer fields must NOT swallow real
        // queue names. Some host apps legitimately dispatch eval jobs
        // to a queue literally called `none` or `null`; the CLI must
        // pass those through as queue names.
        config(['eval-harness.batches.profiles.queue-default' => [
            'mode' => BatchOptions::MODE_LAZY_PARALLEL,
            'concurrency' => 4,
            'queue' => 'evals-default',
        ]]);
        $this->app->forgetInstance(BatchProfileResolver::class);

        $this->artisan('eval-harness-test:capture-batch', [
            '--batch-profile' => 'queue-default',
            '--queue' => 'none',
        ])->assertExitCode(0);

        $captured = $this->command->captured;
        $this->assertNotNull($captured);
        $this->assertSame('none', $captured->queue);
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
