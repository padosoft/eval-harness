---
title: "Installation"
description: "Install the package, understand the compatibility matrix, publish configuration and the optional online-monitoring migration, and point the embedding/judge metrics at any OpenAI-compatible provider."
---

## Install

```bash
composer require padosoft/eval-harness
```

The package is **auto-discovered** — no `config/app.php` edits required. Datasets
are YAML and reports are JSON/Markdown on a configured disk. The only schema
change is a **single migration** for the optional online-monitoring feature,
which the service provider auto-loads — see
[below](#the-online-monitoring-migration).

## Compatibility matrix

| eval-harness   | PHP             | Laravel       | laravel/ai SDK | symfony/yaml |
| -------------- | --------------- | ------------- | -------------- | ------------ |
| 0.x (current)  | 8.3 / 8.4 / 8.5 | 12.x / 13.x   | ^0.6           | ^7 / ^8      |

::: callout info
The package never imports a vendor AI SDK. Every embedding and judge call goes
through Laravel's `Http::` facade against an OpenAI-compatible
chat-completions / embeddings endpoint. That keeps swapping providers a
config change, and makes the whole surface fakeable with `Http::fake()`.
:::

## Publish configuration

```bash
php artisan vendor:publish --tag=eval-harness-config
```

This drops `config/eval-harness.php` into your app, where you can override the
embeddings and judge endpoints, models, API keys, retrieval `default_k`,
calibration thresholds, online-monitoring settings, runtime guardrails, the
reports disk, and batch defaults. See [Configuration](/configuration) for the
full annotated file.

## The online-monitoring migration

The package ships **one** migration — `eval_harness_online_scores` — for the
optional online-monitoring feature. The service provider **auto-loads** it
(outside the console guard, so it is also available to `RefreshDatabase` in
tests), which means your next `php artisan migrate` creates this table:

```bash
php artisan migrate
```

The feature itself is **off by default** (`online.enabled = false`), so the table
simply stays empty until you opt in — it does not change any existing table. See
[Online monitoring](/guides/online-monitoring).

::: callout tip
You only need `vendor:publish --tag=eval-harness-migrations` if you want to
**copy** the migration into your app's `database/migrations` to customize it.
Publishing is not required for the table to be created — the auto-load handles
that.
:::

## Pointing at a provider

Offline metrics need no provider. The embedding-backed metrics
(`cosine-embedding`, `bertscore-like`) and judge-backed metrics
(`llm-as-judge`, `refusal-quality`) call an OpenAI-compatible endpoint.

::: tabs

== tab "OpenAI (default)"
```env
# OPENAI_API_KEY is the fallback for BOTH the judge and embeddings keys,
# so setting it alone authenticates every provider-backed metric.
OPENAI_API_KEY=sk-...

EVAL_HARNESS_EMBEDDINGS_ENDPOINT=https://api.openai.com/v1/embeddings
EVAL_HARNESS_EMBEDDINGS_MODEL=text-embedding-3-small
EVAL_HARNESS_EMBEDDINGS_API_KEY=sk-...   # optional; defaults to OPENAI_API_KEY
EVAL_HARNESS_JUDGE_ENDPOINT=https://api.openai.com/v1/chat/completions
EVAL_HARNESS_JUDGE_MODEL=gpt-4o-mini
EVAL_HARNESS_JUDGE_API_KEY=sk-...        # optional; defaults to OPENAI_API_KEY
```

== tab "OpenRouter"
```env
EVAL_HARNESS_JUDGE_ENDPOINT=https://openrouter.ai/api/v1/chat/completions
EVAL_HARNESS_JUDGE_API_KEY=or-your-key
EVAL_HARNESS_JUDGE_MODEL=anthropic/claude-3.5-sonnet
```

== tab "Regolo (EU sovereign)"
```env
EVAL_HARNESS_JUDGE_ENDPOINT=https://api.regolo.ai/v1/chat/completions
EVAL_HARNESS_JUDGE_API_KEY=rgl-your-key
EVAL_HARNESS_JUDGE_MODEL=mistral-large
```

:::

The embedding metrics expect the standard `data[].embedding` response shape;
most providers already implement it. Host apps that prefer to route embeddings
through Laravel AI or a deterministic fake can bind
`Padosoft\EvalHarness\Contracts\EmbeddingClient` in the container.

::: callout warning
Never commit provider keys. Inject `EVAL_HARNESS_JUDGE_API_KEY` from CI
secrets, and prefer offline metrics for the PR gate so most runs cost nothing
and need no network.
:::

## Verify the install

```bash
php artisan list | grep eval-harness
```

You should see `eval-harness:run`, `eval-harness:adversarial`, and
`eval-harness:calibrate-judge`. Continue to the [Quickstart](/quickstart) to
register your first dataset.

::: grids
::: grid
::: card "Configuration" icon:settings
The full annotated `config/eval-harness.php`.

[Open →](/configuration)
:::
:::
::: grid
::: card "Core concepts" icon:workflow
Datasets, samples, the SUT, metrics, reports, and gates.

[Open →](/core-concepts)
:::
:::
:::

