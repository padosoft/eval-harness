<?php

declare(strict_types=1);

use Padosoft\EvalHarness\Support\RuntimeOptions;
use Padosoft\EvalHarness\Support\TimeoutNormalizer;

return [
    /*
    |--------------------------------------------------------------------------
    | Default provider transport
    |--------------------------------------------------------------------------
    |
    | The eval-harness ships LLM-as-judge and embedding-backed metrics that
    | call out to an external provider via raw `Http::`. The defaults below
    | match OpenAI's wire format; OpenRouter / Regolo / any OpenAI-compatible
    | endpoint works with only an env-var change.
    |
    */

    'metrics' => [

        'cosine_embedding' => [
            'endpoint' => env(
                'EVAL_HARNESS_EMBEDDINGS_ENDPOINT',
                'https://api.openai.com/v1/embeddings',
            ),
            'api_key' => env('EVAL_HARNESS_EMBEDDINGS_API_KEY', env('OPENAI_API_KEY', '')),
            'model' => env('EVAL_HARNESS_EMBEDDINGS_MODEL', 'text-embedding-3-small'),
            // Validated via TimeoutNormalizer so a typo'd env value
            // (e.g. `abc`) falls back to the documented default
            // instead of collapsing to 0, which Http::timeout(0)
            // would interpret as "no timeout" and hang the eval run.
            'timeout_seconds' => TimeoutNormalizer::normalize(
                env('EVAL_HARNESS_EMBEDDINGS_TIMEOUT'),
                30,
            ),
        ],

        // Shared by `llm-as-judge` and `refusal-quality`.
        'llm_as_judge' => [
            'endpoint' => env(
                'EVAL_HARNESS_JUDGE_ENDPOINT',
                'https://api.openai.com/v1/chat/completions',
            ),
            'api_key' => env('EVAL_HARNESS_JUDGE_API_KEY', env('OPENAI_API_KEY', '')),
            'model' => env('EVAL_HARNESS_JUDGE_MODEL', 'gpt-4o-mini'),
            // Validated via TimeoutNormalizer; same rationale as the
            // embeddings timeout above.
            'timeout_seconds' => TimeoutNormalizer::normalize(
                env('EVAL_HARNESS_JUDGE_TIMEOUT'),
                60,
            ),

            // Override to inject a custom prompt template. Placeholders
            // {expected} {actual} {question} are interpolated from the
            // sample. Leave null to use the package default.
            'prompt_template' => env('EVAL_HARNESS_JUDGE_PROMPT_TEMPLATE'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Runtime guardrails
    |--------------------------------------------------------------------------
    |
    | Metric failures are captured into reports by default. Enable
    | `raise_exceptions` for strict CI lanes that should abort on the first
    | MetricException/provider contract errors instead. Provider retries are
    | extra attempts after the initial request and only apply to Laravel HTTP
    | connection failures, HTTP 429, and 5xx responses.
    |
    */

    'runtime' => [
        'raise_exceptions' => RuntimeOptions::normalizeBoolean(
            env('EVAL_HARNESS_RAISE_EXCEPTIONS'),
            false,
        ),
        'provider_retry_attempts' => RuntimeOptions::normalizeNonNegativeInt(
            env('EVAL_HARNESS_PROVIDER_RETRY_ATTEMPTS'),
            0,
        ),
        'provider_retry_sleep_milliseconds' => RuntimeOptions::normalizeNonNegativeInt(
            env('EVAL_HARNESS_PROVIDER_RETRY_SLEEP_MS'),
            100,
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reports storage
    |--------------------------------------------------------------------------
    |
    | When the `eval-harness:run` Artisan command writes a report to disk,
    | this disk + path prefix are used. The disk must exist in
    | `config/filesystems.php` of the host application; the package does
    | NOT create it — that's a host concern.
    |
    */

    'reports' => [
        'disk' => env('EVAL_HARNESS_REPORTS_DISK', 'local'),
        'path_prefix' => env('EVAL_HARNESS_REPORTS_PATH', 'eval-harness/reports'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Adversarial run manifests (HTTP discovery)
    |--------------------------------------------------------------------------
    |
    | The CLI `eval-harness:adversarial --adversarial-manifest=<path>` keeps
    | accepting arbitrary filesystem paths. This config block is purely
    | additive: it lets the read-only Report API enumerate adversarial run
    | manifests via /<configured-prefix>/adversarial/manifests (Macro 9+).
    |
    | When `disk` is null, the manifest discovery endpoints respond
    | 404 + "discovery_not_configured" so a companion UI degrades
    | gracefully. Operators who want HTTP discovery should set the disk
    | (and optionally the path prefix) to point at the directory their
    | scheduled adversarial runs write to.
    |
    */

    'adversarial' => [
        'manifests' => [
            'disk' => env('EVAL_HARNESS_ADVERSARIAL_MANIFEST_DISK'),
            'path_prefix' => env(
                'EVAL_HARNESS_ADVERSARIAL_MANIFEST_PATH',
                'eval-harness/adversarial/manifests',
            ),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Read-only report API
    |--------------------------------------------------------------------------
    |
    | The package can register read-only routes for a separate UI/admin package
    | to consume report artifacts. Routes are disabled by default because this
    | package does not bundle auth. Enable them only behind the host app's
    | existing admin middleware or network controls.
    |
    */

    'api' => [
        'enabled' => RuntimeOptions::normalizeBoolean(env('EVAL_HARNESS_API_ENABLED'), false),
        'prefix' => env('EVAL_HARNESS_API_PREFIX', 'eval-harness/api'),
        'middleware' => env('EVAL_HARNESS_API_MIDDLEWARE') === null
            ? []
            : array_values(array_filter(array_map(
                static fn (string $middleware): string => trim($middleware),
                explode(',', (string) env('EVAL_HARNESS_API_MIDDLEWARE')),
            ))),
        'trend' => [
            'max_files_scanned' => TimeoutNormalizer::normalize(
                env('EVAL_HARNESS_API_TREND_MAX_FILES_SCANNED'),
                5000,
            ),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue-backed batches
    |--------------------------------------------------------------------------
    |
    | Lazy parallel batches write sample results to cache so the command process
    | and queue workers can rendezvous without a database table. Horizon
    | deployments should point `cache_store` at a shared store such as Redis or
    | database. Leave null to use the host application's default cache store.
    |
    */

    'batches' => [
        'lazy_parallel' => [
            'cache_store' => env('EVAL_HARNESS_BATCH_CACHE_STORE'),
            'result_ttl_seconds' => TimeoutNormalizer::normalize(
                env('EVAL_HARNESS_BATCH_RESULT_TTL'),
                3600,
            ),
            'wait_timeout_seconds' => TimeoutNormalizer::normalize(
                env('EVAL_HARNESS_BATCH_WAIT_TIMEOUT'),
                60,
            ),
        ],

        'live_registry' => [
            'enabled' => RuntimeOptions::normalizeBoolean(
                env('EVAL_HARNESS_BATCH_LIVE_REGISTRY_ENABLED'),
                true,
            ),
        ],

        /*
        |----------------------------------------------------------------------
        | Operational profiles
        |----------------------------------------------------------------------
        |
        | Profiles set defaults for batch options when --batch-profile=<name>
        | is passed to eval-harness:run / eval-harness:adversarial. The
        | package ships built-in `ci`, `smoke`, and `nightly` defaults
        | (see Padosoft\EvalHarness\Batches\BatchProfileResolver). Host apps
        | can override individual fields per profile or register additional
        | profiles below. Explicit CLI options always override profile
        | defaults; this layer is for sane operational presets, never lock-in.
        |
        | Example:
        |
        |     'profiles' => [
        |         'ci' => ['concurrency' => 8, 'rate_limit' => 30],
        |         'release' => [
        |             'mode' => 'lazy-parallel',
        |             'concurrency' => 24,
        |             'queue' => 'evals-release',
        |             'timeout_seconds' => 90,
        |             'wait_timeout_seconds' => 600,
        |             'chunk_size' => 24,
        |             'rate_limit' => 90,
        |             'rate_window_seconds' => 60,
        |             'checkpoint_every' => 50,
        |         ],
        |     ],
        |
        */

        'profiles' => [
            // Host-app overrides applied on top of the built-in
            // `ci`, `smoke`, and `nightly` profiles. Add new named
            // profiles here, or override fields for existing ones.
        ],
    ],
];
