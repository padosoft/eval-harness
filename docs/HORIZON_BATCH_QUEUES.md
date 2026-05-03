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
  --registrar="App\\Console\\EvalRegistrar" \
  --batch=lazy-parallel \
  --concurrency=20 \
  --queue=evals \
  --timeout=60 \
  --batch-timeout=300 \
  --json \
  --out=evals/rag-factuality.json
```

`--concurrency` is the producer window size: it controls how many sample jobs
the command dispatches before waiting for that window. Actual worker
concurrency is controlled by Horizon supervisor process counts.

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

Tune `maxProcesses` for how many samples may run at the same time. Tune
`--concurrency` for how many jobs this package feeds into the queue before
collecting a window. They do not need to be equal: a larger producer window can
keep a busy worker pool fed, while a smaller window reduces cache/result-store
pressure.

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
  --timeout=60 \
  --batch-timeout=300
```

## Operational Checks

- Verify the SUT binding resolves to a concrete `SampleRunner` class in the
  worker container. Lazy parallel mode rejects closures, anonymous runners, and
  caller-specific instance state because workers resolve the runner by class
  name.
- Verify the queue name passed with `--queue` matches the Horizon supervisor
  queue.
- Verify the configured batch cache store is reachable from CLI and worker
  processes.
- Keep offline metrics and fake LLM/embedding clients in test suites. Live LLM
  calls belong in opt-in live tests only.

## References

- Laravel Horizon job timeout and balancing guidance:
  <https://laravel.com/docs/13.x/horizon>
- Laravel queue timeout / `retry_after` guidance:
  <https://laravel.com/docs/13.x/queues>
