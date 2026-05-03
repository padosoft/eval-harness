<?php

declare(strict_types=1);

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
    ],
];
