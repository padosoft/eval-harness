# Horizon Batch Queue Guidance

This package stays headless and does not require Horizon in tests. Use
Laravel's `sync` queue driver or queue fakes for package and host-app test
suites. Use Horizon only in the host application that runs production or
staging batch evals.

## Queue And Cache Setup

Lazy parallel evals dispatch one `EvaluateSampleJob` per sample and assemble
the report in deterministic dataset order after workers write results to the
shared batch result store.

For Horizon-backed runs:

- Use a Redis queue connection for the Horizon worker pool.
- Put eval jobs on a dedicated queue such as `evals`.
- Set `EVAL_HARNESS_BATCH_CACHE_STORE` to a cache store shared by the Artisan
  command process and every worker. Do not use the `array` cache outside tests.
- Size `EVAL_HARNESS_BATCH_RESULT_TTL` long enough for the expected queue drain
  plus any delayed external `dispatch()` / `collectOutputs()` flow.
- Keep `EVAL_HARNESS_BATCH_WAIT_TIMEOUT` or `--batch-timeout` large enough for
  one producer window to finish.

```env
QUEUE_CONNECTION=redis
EVAL_HARNESS_BATCH_CACHE_STORE=redis
EVAL_HARNESS_BATCH_RESULT_TTL=3600
EVAL_HARNESS_BATCH_WAIT_TIMEOUT=300
```

```bash
php artisan eval-harness:run rag.factuality.fy2026 \
  --registrar="App\\Console\\EvalQueueRegistrar" \
  --batch=lazy-parallel \
  --concurrency=20 \
  --queue=evals \
  --timeout=60 \
  --batch-timeout=300 \
  --json \
  --out=evals/rag-factuality.json
```

`--concurrency` is the lazy-parallel producer fan-out cap and the
default producer window size. Pass `--chunk-size=N` to narrow the
window further (must be `<= --concurrency`; see Backpressure Knobs
below). The producer waits after each chunk completes before
dispatching the next chunk, so **when `--chunk-size < --concurrency`,
chunk-size is the effective in-flight job count per producer process**
— not concurrency. Actual worker concurrency across the whole pool is
controlled by Horizon supervisor process counts.

Use a queue-specific registrar, or update the host app's existing registrar, so
it binds the SUT to a concrete `SampleRunner` class:

```php
use App\Eval\MyRagRunner;

$this->app->bind('eval-harness.sut', MyRagRunner::class);
```

The closure-based quick-start registrar remains valid for serial mode only.

## Horizon Supervisor Example

In the host application's `config/horizon.php`, use a dedicated supervisor for
eval jobs when they should not compete with latency-sensitive queues.

```php
'environments' => [
    'production' => [
        'supervisor-evals' => [
            'connection' => 'redis',
            'queue' => ['evals'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses' => 1,
            'maxProcesses' => 10,
            'timeout' => 70,
        ],
    ],
],
```

Tune `maxProcesses` for how many samples may run at the same time across the
whole Horizon pool. Tune `--concurrency` for the maximum producer window
allowed (and the default dispatch window). When you need tighter backpressure
than the fan-out cap, set `--chunk-size` lower and remember the runner waits
after each chunk: in that case chunk-size — not concurrency — is the actual
in-flight count per producer process. Size `maxProcesses` for the chunk-size
you actually use under load, not the upper bound. Larger windows keep a busy
worker pool fed; smaller windows reduce cache/result-store pressure but limit
producer throughput per command.

## Timeout Sizing

Use distinct timeout layers:

- `--timeout` is the per-sample queued job timeout assigned to
  `EvaluateSampleJob`.
- Horizon supervisor `timeout` should be greater than the per-job timeout so
  Horizon does not kill a healthy job before Laravel's job timeout can fail it
  cleanly.
- The queue connection `retry_after` value should be greater than the Horizon
  supervisor timeout to avoid duplicate processing.
- `--batch-timeout` is the command-side wait for each producer window. Increase
  it when workers are healthy but the command reports missing queued outputs.
- `EVAL_HARNESS_BATCH_RESULT_TTL` should be greater than the full expected
  queue drain and collection window so workers and collectors can still see the
  active batch metadata.

Example sizing for `--timeout=60`:

```php
// config/horizon.php
'timeout' => 70,

// config/queue.php
'retry_after' => 90,
```

```bash
php artisan eval-harness:run rag.factuality.fy2026 \
  --batch=lazy-parallel \
  --queue=evals \
  --timeout=60 \
  --batch-timeout=300
```

## Operational Checks

- Verify the SUT binding resolves to a fresh concrete `SampleRunner` class in
  the worker container. Prefer class bindings or factories that return a new
  container-resolved runner. Lazy parallel mode rejects closures, anonymous
  runners, `instance()` / singleton bindings that return the same initialized
  runner object, and caller-specific instance state because workers resolve the
  runner by class name.
- Verify the queue name passed with `--queue` matches the Horizon supervisor
  queue.
- Verify the configured batch cache store is reachable from CLI and worker
  processes.
- Keep offline metrics and fake LLM/embedding clients in test suites. Live LLM
  calls belong in opt-in live tests only.

## Operational Profiles

`--batch-profile=<name>` applies a named operational preset of batch
defaults so CI lanes, smoke checks, and nightly runs do not have to
duplicate `--concurrency / --timeout / --queue / --rate-limit / ...` on
every invocation. Explicit CLI options always override profile defaults;
profiles never lock operators in.

Built-in profiles:

| Profile  | Mode          | Concurrency | Chunk size | Rate limit       | Checkpoint every |
| -------- | ------------- | ----------- | ---------- | ---------------- | ---------------- |
| `smoke`  | serial        | 1           | n/a        | n/a              | n/a              |
| `ci`     | lazy-parallel | 4           | 4          | none             | every 25 samples |
| `nightly`| lazy-parallel | 16          | 16         | 60 / 60s         | every 100        |

Override or register profiles per host app under
`eval-harness.batches.profiles` in `config/eval-harness.php`:

```php
// config/eval-harness.php
return [
    // ... other config keys ...

    'batches' => [
        // ... other batch keys (lazy_parallel, etc.) ...

        'profiles' => [
            'ci' => ['concurrency' => 8, 'rate_limit' => 30],
            'release' => [
                'mode' => 'lazy-parallel',
                'concurrency' => 24,
                'queue' => 'evals-release',
                'timeout_seconds' => 90,
                'wait_timeout_seconds' => 600,
                'chunk_size' => 24,
                'rate_limit' => 90,
                'rate_window_seconds' => 60,
                'checkpoint_every' => 50,
            ],
        ],
    ],
];
```

```bash
# CI gate: lazy-parallel with sane defaults, no extra knobs.
php artisan eval-harness:run rag.factuality.fy2026 \
  --batch-profile=ci \
  --queue=evals \
  --json --out=evals/ci-rag.json

# Nightly long run: throttled dispatch with checkpoints.
php artisan eval-harness:run rag.factuality.fy2026 \
  --batch-profile=nightly \
  --queue=evals-nightly \
  --json --out=evals/nightly-rag.json

# Smoke check before opening a PR: deterministic in-process serial run.
php artisan eval-harness:run rag.factuality.fy2026 \
  --batch-profile=smoke
```

## Backpressure Knobs

Use these flags to keep producer dispatch and SUT/provider QPS within
operational limits. They apply to lazy-parallel mode only:

- `--chunk-size=N` narrows the producer window size for dispatching
  jobs before waiting for results. Defaults to `--concurrency` when
  unset and must be `<= --concurrency`. Note that the runner waits
  after each chunk completes, so when `--chunk-size < --concurrency`
  chunk-size is the actual in-flight count per producer process — not
  a "small chunk against a larger fan-out". Use this knob when you
  want tighter backpressure on the SUT/provider per producer command.
- `--rate-limit=N` caps how many sample jobs the producer dispatches per
  rolling `--rate-window-seconds=W` window (default 60s). The limiter is
  process-side, so multiple parallel commands compound.
- `--checkpoint-every=N` emits a structured progress checkpoint every N
  completed samples plus a final checkpoint at end-of-batch. Bind a
  `Padosoft\EvalHarness\Batches\BatchProgressReporter` implementation in
  the container to forward checkpoints to logs, Horizon dashboards, or
  custom metrics. The default reporter is a no-op so the package stays
  Horizon-optional.

```php
use Padosoft\EvalHarness\Batches\BatchProgressReporter;

$this->app->singleton(BatchProgressReporter::class, function () {
    return new class implements BatchProgressReporter {
        public function reportCheckpoint(string $batchId, int $samplesCompleted, int $totalSamples): void
        {
            \Log::info('eval-harness checkpoint', [
                'batch_id' => $batchId,
                'samples_completed' => $samplesCompleted,
                'total' => $totalSamples,
            ]);
        }
    };
});
```

In tests, the default `NullBatchProgressReporter` is used and Horizon
is never required. Rate limiting works under the `sync` queue too: the
producer pauses between samples even when each job runs immediately.

## References

- Laravel Horizon job timeout and balancing guidance:
  <https://laravel.com/docs/13.x/horizon>
- Laravel queue timeout / `retry_after` guidance:
  <https://laravel.com/docs/13.x/queues>
