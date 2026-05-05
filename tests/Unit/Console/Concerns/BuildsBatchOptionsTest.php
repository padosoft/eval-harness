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

    public function test_explicit_result_ttl_seconds_overrides_profile_default(): void
    {
        config(['eval-harness.batches.profiles.ttl-heavy' => [
            'mode' => BatchOptions::MODE_LAZY_PARALLEL,
            'concurrency' => 4,
            'queue' => 'evals',
            'timeout_seconds' => 30,
            'wait_timeout_seconds' => 120,
            'result_ttl_seconds' => 4000,
        ]]);
        $this->app->forgetInstance(BatchProfileResolver::class);

        $this->artisan('eval-harness-test:capture-batch', [
            '--batch-profile' => 'ttl-heavy',
            '--result-ttl-seconds' => '7200',
        ])->assertExitCode(0);

        $captured = $this->command->captured;
        $this->assertNotNull($captured);
        $this->assertSame(7200, $captured->resultTtlSeconds);
    }

    public function test_explicit_none_sentinel_clears_inherited_profile_result_ttl_seconds(): void
    {
        // result_ttl_seconds was previously sticky because no CLI flag
        // could override it. Now it accepts the same `none` sentinel
        // as the other nullable int fields.
        config(['eval-harness.batches.profiles.ttl-heavy' => [
            'mode' => BatchOptions::MODE_LAZY_PARALLEL,
            'concurrency' => 4,
            'queue' => 'evals',
            'timeout_seconds' => 30,
            'wait_timeout_seconds' => 120,
            'result_ttl_seconds' => 4000,
        ]]);
        $this->app->forgetInstance(BatchProfileResolver::class);

        $this->artisan('eval-harness-test:capture-batch', [
            '--batch-profile' => 'ttl-heavy',
            '--result-ttl-seconds' => 'none',
        ])->assertExitCode(0);

        $captured = $this->command->captured;
        $this->assertNotNull($captured);
        $this->assertNull($captured->resultTtlSeconds);
    }

    public function test_explicit_lower_concurrency_caps_inherited_profile_chunk_size(): void
    {
        // Cross-field reconciliation: operator overrides nightly's
        // concurrency=16 with --concurrency=8 and does not pass
        // --chunk-size. Without the cap, BatchOptions would reject the
        // run because inherited chunk_size=16 > new concurrency=8 — the
        // explicit --concurrency override would lose to the inherited
        // chunk size, which contradicts the documented precedence.
        config(['eval-harness.batches.profiles.chunked-nightly' => [
            'mode' => BatchOptions::MODE_LAZY_PARALLEL,
            'concurrency' => 16,
            'queue' => 'evals',
            'timeout_seconds' => 60,
            'wait_timeout_seconds' => 600,
            'chunk_size' => 16,
        ]]);
        $this->app->forgetInstance(BatchProfileResolver::class);

        $this->artisan('eval-harness-test:capture-batch', [
            '--batch-profile' => 'chunked-nightly',
            '--concurrency' => '8',
        ])->assertExitCode(0);

        $captured = $this->command->captured;
        $this->assertNotNull($captured);
        $this->assertSame(8, $captured->concurrency);
        $this->assertSame(8, $captured->chunkSize);
    }

    public function test_explicit_chunk_size_still_validates_against_explicit_concurrency(): void
    {
        // Pair to the cap test: an operator who explicitly passes both
        // --concurrency and a higher --chunk-size still gets the
        // documented BatchOptions validation error (chunk size cannot
        // exceed concurrency).
        config(['eval-harness.batches.profiles.chunked-nightly' => [
            'mode' => BatchOptions::MODE_LAZY_PARALLEL,
            'concurrency' => 16,
            'queue' => 'evals',
            'timeout_seconds' => 60,
            'wait_timeout_seconds' => 600,
            'chunk_size' => 16,
        ]]);
        $this->app->forgetInstance(BatchProfileResolver::class);

        $this->artisan('eval-harness-test:capture-batch', [
            '--batch-profile' => 'chunked-nightly',
            '--concurrency' => '8',
            '--chunk-size' => '12',
        ])
            ->expectsOutputToContain('Batch chunk size (12) cannot exceed concurrency (8)')
            ->assertExitCode(1);
    }

    public function test_explicit_rate_limit_none_drops_inherited_rate_window_seconds(): void
    {
        // Operator explicitly clears the rate limit on a nightly-style
        // profile that also set rate_window_seconds. Without dropping
        // the inherited window, BatchOptions would reject the run for
        // "rate window seconds is only meaningful with a rate limit",
        // which would defeat the documented `--rate-limit=none` clear
        // contract.
        config(['eval-harness.batches.profiles.throttled-nightly' => [
            'mode' => BatchOptions::MODE_LAZY_PARALLEL,
            'concurrency' => 8,
            'queue' => 'evals',
            'timeout_seconds' => 60,
            'wait_timeout_seconds' => 600,
            'rate_limit' => 60,
            'rate_window_seconds' => 60,
        ]]);
        $this->app->forgetInstance(BatchProfileResolver::class);

        $this->artisan('eval-harness-test:capture-batch', [
            '--batch-profile' => 'throttled-nightly',
            '--rate-limit' => 'none',
        ])->assertExitCode(0);

        $captured = $this->command->captured;
        $this->assertNotNull($captured);
        $this->assertNull($captured->rateLimit);
        $this->assertNull($captured->rateWindowSeconds);
        // Other profile fields stay applied.
        $this->assertSame(8, $captured->concurrency);
        $this->assertSame(60, $captured->timeoutSeconds);
    }

    public function test_explicit_rate_window_with_explicit_none_rate_limit_still_fails(): void
    {
        // Operator explicit-vs-explicit conflicts must still surface
        // through BatchOptions rather than being silently reconciled.
        // --rate-limit=none + explicit --rate-window-seconds is a real
        // misconfig and should not be papered over by the cascading
        // clear above.
        $this->artisan('eval-harness-test:capture-batch', [
            '--batch' => 'lazy-parallel',
            '--concurrency' => '4',
            '--rate-limit' => 'none',
            '--rate-window-seconds' => '30',
        ])
            ->expectsOutputToContain('Batch rate window seconds is only meaningful with a rate limit')
            ->assertExitCode(1);
    }

    public function test_explicit_none_sentinel_clears_inherited_profile_timeout_fields(): void
    {
        // The shared optional-int parser treats `none` / `null` as an
        // explicit unset sentinel for every nullable integer flag, not
        // just the new backpressure ones. Pin the behaviour for the
        // older `--timeout` and `--batch-timeout` flags so a future
        // refactor of the parser cannot quietly break the documented
        // numeric-flag clearing path for them while the rest of the
        // suite stays green.
        config(['eval-harness.batches.profiles.timeout-heavy' => [
            'mode' => BatchOptions::MODE_LAZY_PARALLEL,
            'concurrency' => 4,
            'queue' => 'evals',
            'timeout_seconds' => 60,
            'wait_timeout_seconds' => 600,
        ]]);
        $this->app->forgetInstance(BatchProfileResolver::class);

        $this->artisan('eval-harness-test:capture-batch', [
            '--batch-profile' => 'timeout-heavy',
            '--timeout' => 'none',
            '--batch-timeout' => 'NULL',
        ])->assertExitCode(0);

        $captured = $this->command->captured;
        $this->assertNotNull($captured);
        $this->assertNull($captured->timeoutSeconds);
        $this->assertNull($captured->waitTimeoutSeconds);
        // Other profile defaults still apply.
        $this->assertSame(4, $captured->concurrency);
        $this->assertSame('evals', $captured->queue);
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
    }
}
