---
title: "Configuration"
description: "The full annotated config/eval-harness.php — metrics (embeddings, judge, retrieval), calibration, online monitoring, runtime guardrails, reports disk, batch profiles, the report API, and adversarial manifests — with every env var."
---

Publish the config to override any default:

```bash
php artisan vendor:publish --tag=eval-harness-config
```

Every key reads from an environment variable, so most apps configure the package
entirely through `.env`. The sections below are the complete contract.

## metrics

Embedding and judge providers, plus the retrieval cutoff.

```php
'metrics' => [
    'cosine_embedding' => [
        'endpoint' => env('EVAL_HARNESS_EMBEDDINGS_ENDPOINT', 'https://api.openai.com/v1/embeddings'),
        'api_key'  => env('EVAL_HARNESS_EMBEDDINGS_API_KEY', env('OPENAI_API_KEY', '')),
        'model'    => env('EVAL_HARNESS_EMBEDDINGS_MODEL', 'text-embedding-3-small'),
        'timeout_seconds' => TimeoutNormalizer::normalize(env('EVAL_HARNESS_EMBEDDINGS_TIMEOUT'), 30),
    ],
    'llm_as_judge' => [
        'endpoint' => env('EVAL_HARNESS_JUDGE_ENDPOINT', 'https://api.openai.com/v1/chat/completions'),
        'api_key'  => env('EVAL_HARNESS_JUDGE_API_KEY', env('OPENAI_API_KEY', '')),
        'model'    => env('EVAL_HARNESS_JUDGE_MODEL', 'gpt-4o-mini'),
        'timeout_seconds' => TimeoutNormalizer::normalize(env('EVAL_HARNESS_JUDGE_TIMEOUT'), 60),
        'prompt_template' => env('EVAL_HARNESS_JUDGE_PROMPT_TEMPLATE'),
    ],
    'retrieval' => [
        'default_k' => RuntimeOptions::normalizePositiveInt(env('EVAL_HARNESS_RETRIEVAL_DEFAULT_K'), 5),
    ],
],
```

| key | env | default | meaning |
| --- | --- | --- | --- |
| `cosine_embedding.endpoint` | `EVAL_HARNESS_EMBEDDINGS_ENDPOINT` | OpenAI embeddings | OpenAI-compatible embeddings URL. |
| `cosine_embedding.model` | `EVAL_HARNESS_EMBEDDINGS_MODEL` | `text-embedding-3-small` | Embedding model for `cosine-embedding` / `bertscore-like`. |
| `llm_as_judge.endpoint` | `EVAL_HARNESS_JUDGE_ENDPOINT` | OpenAI chat | OpenAI-compatible chat-completions URL. |
| `llm_as_judge.model` | `EVAL_HARNESS_JUDGE_MODEL` | `gpt-4o-mini` | Judge model for `llm-as-judge` / `refusal-quality`. |
| `llm_as_judge.prompt_template` | `EVAL_HARNESS_JUDGE_PROMPT_TEMPLATE` | — | Optional custom judge rubric. |
| `retrieval.default_k` | `EVAL_HARNESS_RETRIEVAL_DEFAULT_K` | `5` | Cutoff for hit@k / recall@k / nDCG@k (per-sample `metadata.k` wins). |

## calibration

Thresholds for `eval-harness:calibrate-judge`. Agreement is on **verdicts**, not
raw scores; `require_distinct_models` is the self-preference guard.

```php
'calibration' => [
    'verdict_pass_threshold'  => RuntimeOptions::normalizeUnitInterval(env('EVAL_HARNESS_CALIBRATION_PASS_THRESHOLD'), 0.5),
    'min_agreement'           => RuntimeOptions::normalizeUnitInterval(env('EVAL_HARNESS_CALIBRATION_MIN_AGREEMENT'), 0.8),
    'length_bias_warn'        => RuntimeOptions::normalizeUnitInterval(env('EVAL_HARNESS_CALIBRATION_LENGTH_BIAS_WARN'), 0.4),
    'require_distinct_models' => RuntimeOptions::normalizeBoolean(env('EVAL_HARNESS_CALIBRATION_REQUIRE_DISTINCT_MODELS'), true),
    'model_under_test'        => env('EVAL_HARNESS_CALIBRATION_MODEL_UNDER_TEST'),
],
```

## online

Production monitoring. **Off by default**; the host app calls
`OnlineMonitor::capture()` and a sampled fraction is judged on a queue. See
[Online monitoring](/guides/online-monitoring).

```php
'online' => [
    'enabled'        => RuntimeOptions::normalizeBoolean(env('EVAL_HARNESS_ONLINE_ENABLED'), false),
    'sampling_rate'  => RuntimeOptions::normalizeUnitInterval(env('EVAL_HARNESS_ONLINE_SAMPLING_RATE'), 0.0),
    'metric'         => env('EVAL_HARNESS_ONLINE_METRIC', 'llm-as-judge'),
    'pass_threshold' => RuntimeOptions::normalizeUnitInterval(env('EVAL_HARNESS_ONLINE_PASS_THRESHOLD'), 0.7),
    'queue'          => env('EVAL_HARNESS_ONLINE_QUEUE'),
    'connection'     => env('EVAL_HARNESS_ONLINE_CONNECTION'),
    'alert' => [
        'threshold'   => RuntimeOptions::normalizeUnitInterval(env('EVAL_HARNESS_ONLINE_ALERT_THRESHOLD'), 0.8),
        'window'      => RuntimeOptions::normalizePositiveInt(env('EVAL_HARNESS_ONLINE_ALERT_WINDOW'), 50),
        'min_samples' => RuntimeOptions::normalizePositiveInt(env('EVAL_HARNESS_ONLINE_ALERT_MIN_SAMPLES'), 20),
    ],
],
```

## runtime

Strictness and provider retry behavior.

```php
'runtime' => [
    'raise_exceptions' => RuntimeOptions::normalizeBoolean(env('EVAL_HARNESS_RAISE_EXCEPTIONS'), false),
    'provider_retry_attempts' => RuntimeOptions::normalizeNonNegativeInt(env('EVAL_HARNESS_PROVIDER_RETRY_ATTEMPTS'), 0),
    'provider_retry_sleep_milliseconds' => RuntimeOptions::normalizeNonNegativeInt(env('EVAL_HARNESS_PROVIDER_RETRY_SLEEP_MS'), 100),
],
```

- **`raise_exceptions`** — when `true`, abort on the first `MetricException`
  instead of capturing it as a `SampleFailure`. For strict CI lanes.
- **`provider_retry_attempts`** — extra attempts after the first. Retries cover
  only Laravel HTTP connection failures, HTTP 429, and 5xx. Malformed successful
  responses still fail closed.

## reports

Where JSON / Markdown artifacts are written.

```php
'reports' => [
    'disk' => env('EVAL_HARNESS_REPORTS_DISK', 'local'),
    'path_prefix' => env('EVAL_HARNESS_REPORTS_PATH', 'eval-harness/reports'),
],
```

## batches

Lazy-parallel result store plus named operational profiles. Host apps can
override or add profiles under `batches.profiles.*`.

```php
'batches' => [
    'lazy_parallel' => [
        'cache_store' => env('EVAL_HARNESS_BATCH_CACHE_STORE'),
        'result_ttl_seconds' => TimeoutNormalizer::normalize(env('EVAL_HARNESS_BATCH_RESULT_TTL'), 3600),
        'wait_timeout_seconds' => TimeoutNormalizer::normalize(env('EVAL_HARNESS_BATCH_WAIT_TIMEOUT'), 60),
    ],
    'profiles' => [
        'ci' => [ /* lazy-parallel defaults for CI */ ],
        'smoke' => [ /* serial, fast */ ],
        'nightly' => [ /* throttled, checkpointed */ ],
    ],
    'live_registry' => [
        'enabled' => true,
    ],
],
```

See [Batch execution](/operations/batch-execution) and
[Horizon & queues](/operations/horizon-and-queues).

## api

The read-only report API. **Disabled by default** because the package bundles no
authentication — enable it only behind your host app's admin middleware.

```php
'api' => [
    'enabled' => RuntimeOptions::normalizeBoolean(env('EVAL_HARNESS_API_ENABLED'), false),
    'prefix' => env('EVAL_HARNESS_API_PREFIX', 'eval-harness/api'),
    // Default is an EMPTY middleware stack. Set EVAL_HARNESS_API_MIDDLEWARE to a
    // comma-separated list (e.g. "web,auth") — it is parsed into an array.
    'middleware' => env('EVAL_HARNESS_API_MIDDLEWARE') === null
        ? []
        : array_values(array_filter(array_map(
            static fn (string $middleware): string => trim($middleware),
            explode(',', (string) env('EVAL_HARNESS_API_MIDDLEWARE')),
        ))),
    'trend' => [
        'max_files_scanned' => RuntimeOptions::normalizePositiveInt(
            env('EVAL_HARNESS_API_TREND_MAX_FILES_SCANNED'),
            5000,
        ),
    ],
],
```

| key | env | default |
| --- | --- | --- |
| `enabled` | `EVAL_HARNESS_API_ENABLED` | `false` |
| `prefix` | `EVAL_HARNESS_API_PREFIX` | `eval-harness/api` |
| `middleware` | `EVAL_HARNESS_API_MIDDLEWARE` (comma-separated) | `[]` (empty) |
| `trend.max_files_scanned` | `EVAL_HARNESS_API_TREND_MAX_FILES_SCANNED` | `5000` |

::: callout warning
The middleware stack defaults to **empty** — there is no auth out of the box.
Enabling the API with only `EVAL_HARNESS_API_ENABLED=true` mounts the routes
**unauthenticated**. You **must** set `EVAL_HARNESS_API_MIDDLEWARE` (e.g.
`web,auth`) to a stack that authenticates, or exposing the report API leaks your
evaluation artifacts. See [Report API](/reference/report-api).
:::

## adversarial

Optional manifest-discovery disk for the adversarial API endpoints. The CLI
`--manifest=<path>` flag works independently of this.

```php
'adversarial' => [
    'manifests' => [
        'disk' => env('EVAL_HARNESS_ADVERSARIAL_MANIFEST_DISK'),
        'path_prefix' => env('EVAL_HARNESS_ADVERSARIAL_MANIFEST_PATH', 'eval-harness/adversarial/manifests'),
    ],
],
```

| key | env | default |
| --- | --- | --- |
| `manifests.disk` | `EVAL_HARNESS_ADVERSARIAL_MANIFEST_DISK` | `null` (discovery disabled) |
| `manifests.path_prefix` | `EVAL_HARNESS_ADVERSARIAL_MANIFEST_PATH` | `eval-harness/adversarial/manifests` |

When `manifests.disk` is `null`, the `/adversarial/manifests` endpoints respond
gracefully with a `discovery_not_configured` status. Set the disk to the storage
your scheduled adversarial runs write to in order to enable HTTP discovery.

::: grids
::: grid
::: card "Installation" icon:download
Provider setup and the compatibility matrix.

[Open →](/installation)
:::
:::
::: grid
::: card "Batch execution" icon:layers
The batch profiles and backpressure flags in depth.

[Open →](/operations/batch-execution)
:::
:::
:::

