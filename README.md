# padosoft/eval-harness

> Laravel-native evaluation framework for RAG / LLM applications. Golden datasets in YAML, seven built-in metrics, standalone output assertions, Markdown + JSON reports, and an Artisan CI gate. Stop shipping silent regressions in your AI pipeline.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/padosoft/eval-harness.svg?style=flat-square)](https://packagist.org/packages/padosoft/eval-harness)
[![Tests](https://github.com/padosoft/eval-harness/actions/workflows/ci.yml/badge.svg)](https://github.com/padosoft/eval-harness/actions/workflows/ci.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/padosoft/eval-harness.svg?style=flat-square)](https://packagist.org/packages/padosoft/eval-harness)
[![License](https://img.shields.io/packagist/l/padosoft/eval-harness.svg?style=flat-square)](LICENSE)
[![PHP Version Require](https://img.shields.io/packagist/dependency-v/padosoft/eval-harness/php.svg?style=flat-square)](https://packagist.org/packages/padosoft/eval-harness)

---

## Table of Contents

1. [Why eval-harness?](#why-eval-harness)
2. [Design rationale](#design-rationale)
3. [Features](#features)
4. [Comparison with alternatives](#comparison-with-alternatives)
5. [Installation](#installation)
6. [Quick start](#quick-start)
7. [Usage examples](#usage-examples)
8. [Configuration](#configuration)
9. [Architecture](#architecture)
10. [AI vibe-coding pack included](#ai-vibe-coding-pack-included)
11. [Testing](#testing)
12. [Roadmap](#roadmap)
13. [Contributing](#contributing)
14. [Security](#security)
15. [License](#license)

---

## Why eval-harness?

Imagine deploying a RAG-powered chatbot to production. Quality is great
on launch day. Three months later, somebody:

- bumps the embedding model from `text-embedding-3-small` to
  `text-embedding-3-large`,
- swaps the chat model from `gpt-4o` to `gpt-4o-mini` for cost,
- tweaks the prompt template,
- updates `laravel/ai` from `^0.5` to `^0.6`,
- changes the chunker from 800-token sliding window to 1200-token
  semantic.

Every one of those changes is a quality regression risk, and you have
**no programmatic signal** they shipped intact. Your test suite
green-lights the deployment because PHPUnit doesn't know what a
"correct answer" looks like.

`padosoft/eval-harness` fixes that loop:

1. You curate a small golden dataset — a YAML file with 30-200
   `(question, expected answer)` pairs that represent the queries you
   actually care about.
2. You declare metrics — exact-match for deterministic outputs,
   cosine-embedding for paraphrase tolerance, LLM-as-judge for
   subjective grading.
3. You wire up a callable that drives your real pipeline against the
   dataset.
4. CI runs `php artisan eval-harness:run rag.factuality` on every PR
   and gates the merge on the macro-F1 score.

Now your AI pipeline has the same regression protection your business
logic has had for the last fifteen years.

---

## Design rationale

The package is opinionated. Three decisions matter most:

**1. No SDK lock-in.** Every external call goes through Laravel's
   `Http::` facade — never an OpenAI / Anthropic / Vertex SDK. Tests
   substitute via `Http::fake()` for deterministic offline runs, and
   swapping providers is a config-file change, not a refactor.

**2. The dataset is YAML, not a Laravel model.** YAML is reviewable
   in pull requests, diffable across releases, and survives database
   wipes. The package never stores datasets in your DB — they live in
   `eval/golden/*.yml` next to your code.

**3. Failures are captured, not thrown.** A timeout on sample 47
   should not mask the macro-F1 score across 200 valid samples. Every
   metric exception is recorded against `(sample, metric)` and
   surfaced in the final report so the operator can investigate, not
   re-run the whole 30-minute suite.

These decisions cost some flexibility (you can't dispatch metrics
across multiple processes yet — see Roadmap) but they keep the public
surface small and the offline path fast.

---

![eval-harness report banner](https://raw.githubusercontent.com/padosoft/eval-harness/main/resources/banner.png)

## Features

- **Seven metrics out of the box** — `exact-match`, `contains`,
  `regex`, `rouge-l`, `citation-groundedness`,
  `cosine-embedding`, `llm-as-judge` — and a clean `Metric`
  interface for adding more.
- **Strict-schema YAML loader** — versioned dataset contracts and
  actionable validation errors for malformed samples.
- **Deterministic LLM-as-judge** — temperature 0, seed 42,
  `response_format=json_object`. Strict-JSON parser rejects malformed
  responses instead of silently scoring 0.
- **Stable JSON report shape** — every payload carries explicit
  `schema_version` and `dataset_schema_version` fields. Wire into
  your CI dashboard once, then evolve additively.
- **Cohort-ready report data** — JSON and Markdown reports aggregate
  scores by `metadata.tags`, expose an explicit untagged bucket, and
  include per-metric score histograms for dashboards.
- **Standalone output assertions** — score saved JSON/YAML outputs
  with the same metrics and report contract, without invoking your
  agent in CI.
- **Batch execution modes** — SUT runs flow through deterministic
  `SerialBatch` by default, or queue-backed `LazyParallelBatch` via
  `--batch=lazy-parallel` for Laravel queue/Horizon workers.
- **Provider-agnostic** — works with OpenAI, OpenRouter, Regolo,
  Mistral, any OpenAI-compatible chat-completions endpoint.
- **No DB migrations required** — datasets are YAML, results are
  JSON. The package adds zero rows to your schema.
- **Artisan-driven CI gate** — `php artisan eval-harness:run` exits
  non-zero on any captured failure. Wire it into the same workflow
  your unit tests run in.
- **Architecture tests included** — every release proves it doesn't
  leak symbols from sibling packages, ever.

---

## Comparison with alternatives

| Concern | OpenAI Evals | LangSmith | Ragas | Promptfoo | DeepEval | **eval-harness** |
| --- | --- | --- | --- | --- | --- | --- |
| Language | Python | Python / TS | Python | TS / YAML | Python | **PHP / Laravel** |
| Runs in your Laravel app | No | No | No | No | No | **Yes** |
| Storage | OpenAI cloud | LangSmith cloud | Local | Local | Local | **Local YAML + JSON** |
| Metrics | Custom Python | Built-in + custom | RAG-specific | Built-in + custom | Built-in + custom | **7 + interface** |
| LLM-as-judge | Yes | Yes | Yes | Yes | Yes | **Yes (deterministic)** |
| Provider | OpenAI-only | Multi | Multi | Multi | Multi | **Any OpenAI-compatible** |
| CI integration | Manual | API hook | Manual | CLI gate | CLI gate | **Artisan + CI matrix** |
| Vendor lock-in | High | High | Low | Low | Low | **None** |
| Cost to evaluate 200 samples | Cloud bill | Cloud bill | Free | Free | Free | **Free (offline tests via Http::fake)** |
| Auditable in PR diff | Cloud-only | Cloud-only | Yes | Yes | Yes | **Yes (YAML + report)** |

The Python-stack tools are excellent if your stack is Python. If your
RAG pipeline lives in a Laravel monolith, `eval-harness` is the
shortest path from "we have AI in prod" to "we have a regression
test for our AI in prod".

---

## Installation

```bash
composer require padosoft/eval-harness
```

The package is auto-discovered. No `config/app.php` edits required.

Optional config publishing:

```bash
php artisan vendor:publish --tag=eval-harness-config
```

This drops `config/eval-harness.php` into your app where you can
override the embeddings + judge endpoints / models / API keys.

### Compatibility matrix

| eval-harness | PHP | Laravel | laravel/ai SDK | symfony/yaml |
| --- | --- | --- | --- | --- |
| 0.x (current) | 8.3 / 8.4 / 8.5 | 12.x / 13.x | ^0.6 | ^7 / ^8 |

---

## Quick start

### 1. Curate a golden dataset

`eval/golden/factuality.yml`:

```yaml
schema_version: eval-harness.dataset.v1
name: rag.factuality.fy2026
samples:
  - id: capital-france
    input:
      question: "What is the capital of France?"
    expected_output: "Paris"
    metadata:
      tags: [geography, easy]

  - id: refund-policy
    input:
      question: "How many days do I have to return an order?"
    expected_output: "30 days from delivery."
    metadata:
      tags: [policy, support]
```

`schema_version` is optional for existing datasets. If omitted, the
loader defaults to `eval-harness.dataset.v1`.

### 2. Wire up a registrar in your app

`app/Console/EvalRegistrar.php`:

```php
<?php

namespace App\Console;

use Illuminate\Contracts\Container\Container;
use Padosoft\EvalHarness\EvalEngine;

class EvalRegistrar
{
    public function __construct(private readonly Container $container) {}

    public function __invoke(EvalEngine $engine): void
    {
        $engine->dataset('rag.factuality.fy2026')
            ->loadFromYaml(base_path('eval/golden/factuality.yml'))
            ->withMetrics(['exact-match', 'cosine-embedding'])
            ->register();

        $this->container->bind('eval-harness.sut', fn () =>
            fn (array $input): string => app(\App\Rag\KnowledgeAgent::class)
                ->answer($input['question']),
        );
    }
}
```

### 3. Run the eval

```bash
php artisan eval-harness:run rag.factuality.fy2026 \
  --registrar="App\\Console\\EvalRegistrar" \
  --json --out=factuality.json
```

Exit code is `0` if every metric scored cleanly, non-zero otherwise.
Wire that into the same `tests.yml` workflow that runs your PHPUnit
suite and you've got a regression gate.

### 4. Score saved outputs

When another job already generated model responses, keep the same
dataset and score those outputs directly:

```json
{
  "outputs": {
    "capital-france": "Paris",
    "refund-policy": "30 days from delivery."
  }
}
```

```bash
php artisan eval-harness:run rag.factuality.fy2026 \
  --registrar="App\\Console\\EvalRegistrar" \
  --outputs=eval/outputs/factuality.json \
  --json --out=factuality.json
```

`--outputs` accepts JSON or YAML, map form (`outputs.sample_id`) or
list form (`outputs[].id` + `outputs[].actual_output`). Relative
`--out` paths use the configured reports disk and path prefix
(`eval-harness/reports` by default). Add `--raw-path` only when you
want a literal filesystem path and its parent directory already
exists. The registrar still registers the dataset; no
`eval-harness.sut` binding is required for this mode.

### 5. Read the report

```bash
php artisan eval-harness:run rag.factuality.fy2026 \
  --registrar="App\\Console\\EvalRegistrar"
```

```
# Eval report — rag.factuality.fy2026

_Run completed in 2.41s over 30 samples (0 failures captured)._

## Summary

| total samples | total failures | duration seconds |
| --- | --- | --- |
| 30 | 0 | 2.41 |

## Per-metric aggregates

| metric | mean | p50 | p95 | pass-rate (>= 0.5) |
| --- | --- | --- | --- | --- |
| exact-match | 0.7333 | 1.0000 | 1.0000 | 0.7333 |
| cosine-embedding | 0.9012 | 0.9421 | 0.9893 | 0.9667 |

## Macro-F1 (avg pass-rate across all metrics): 0.8500

## Cohorts by metadata.tags

| cohort | samples | metric | mean | p50 | p95 | pass-rate (>= 0.5) |
| --- | --- | --- | --- | --- | --- | --- |
| geography | 12 | exact-match | 0.9500 | 1.0000 | 1.0000 | 0.9500 |
| refund-policy | 8 | exact-match | 0.6000 | 0.5000 | 1.0000 | 0.6000 |

## Score histograms

### exact-match

| score range | count |
| --- | --- |
| 0.0-0.1 | 8 |
| 0.9-1.0 inclusive | 22 |
```

---

## Usage examples

### Programmatic dataset (no YAML)

```php
use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Facades\EvalFacade as Eval;

Eval::dataset('rag.smoke')
    ->withSamples([
        new DatasetSample(id: 's1', input: ['q' => 'hi'], expectedOutput: 'hello'),
        new DatasetSample(id: 's2', input: ['q' => 'bye'], expectedOutput: 'goodbye'),
    ])
    ->withMetrics(['exact-match'])
    ->register();

$report = Eval::scoreOutputs('rag.smoke', [
    's1' => 'hello',
    's2' => 'wrong answer',
]);
```

### Custom metric

```php
use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Metrics\Metric;
use Padosoft\EvalHarness\Metrics\MetricScore;

class JaccardWordOverlapMetric implements Metric
{
    public function name(): string
    {
        return 'jaccard-words';
    }

    public function score(DatasetSample $sample, string $actualOutput): MetricScore
    {
        $expected = array_unique(preg_split('/\\s+/', strtolower((string) $sample->expectedOutput)));
        $actual   = array_unique(preg_split('/\\s+/', strtolower($actualOutput)));
        $union = array_unique(array_merge($expected, $actual));
        if ($union === []) {
            return new MetricScore(0.0);
        }
        $intersection = array_intersect($expected, $actual);

        return new MetricScore(count($intersection) / count($union));
    }
}

// Wire it into a dataset:
Eval::dataset('rag.recall')
    ->loadFromYaml(base_path('eval/golden/recall.yml'))
    ->withMetrics([new JaccardWordOverlapMetric(), 'exact-match'])
    ->register();
```

### CI workflow snippet

```yaml
# .github/workflows/eval-gate.yml
- name: Run RAG regression gate
  env:
    EVAL_HARNESS_JUDGE_API_KEY: ${{ secrets.OPENAI_API_KEY }}
  run: |
    php artisan eval-harness:run rag.factuality.fy2026 \
      --registrar="App\\Console\\EvalRegistrar" \
      --json --out=eval-report.json
- uses: actions/upload-artifact@v4
  if: always()
  with:
    name: eval-report
    path: eval-report.json
```

### Queue-backed batch execution

`--batch=lazy-parallel` dispatches one queue job per sample and then
assembles outputs in dataset order through the shared batch result
store. It requires the SUT to be a concrete `SampleRunner` class so
queue workers can resolve it; arbitrary callables and closures remain
serial-only.

```php
use App\Eval\MyRagRunner;

$this->app->bind('eval-harness.sut', MyRagRunner::class);
```

```bash
php artisan eval-harness:run rag.factuality.fy2026 \
  --registrar="App\\Console\\EvalRegistrar" \
  --batch=lazy-parallel \
  --concurrency=4 \
  --queue=evals \
  --timeout=60 \
  --batch-timeout=300
```

Use Laravel's `sync` queue driver for unit tests. In production, run
Horizon workers on the chosen queue and use a cache backend shared by
the command process and workers so queued sample outputs can be
collected for report assembly. `--timeout` is the per-sample job
timeout; `--batch-timeout` is the maximum wait for each concurrency
window to finish before the command reports missing queued outputs.

---

## Configuration

`config/eval-harness.php` (after `vendor:publish`):

```php
return [
    'metrics' => [

        'cosine_embedding' => [
            'endpoint' => env('EVAL_HARNESS_EMBEDDINGS_ENDPOINT', 'https://api.openai.com/v1/embeddings'),
            'api_key'  => env('EVAL_HARNESS_EMBEDDINGS_API_KEY', env('OPENAI_API_KEY', '')),
            'model'    => env('EVAL_HARNESS_EMBEDDINGS_MODEL', 'text-embedding-3-small'),
            'timeout_seconds' => (int) env('EVAL_HARNESS_EMBEDDINGS_TIMEOUT', 30),
        ],

        'llm_as_judge' => [
            'endpoint' => env('EVAL_HARNESS_JUDGE_ENDPOINT', 'https://api.openai.com/v1/chat/completions'),
            'api_key'  => env('EVAL_HARNESS_JUDGE_API_KEY', env('OPENAI_API_KEY', '')),
            'model'    => env('EVAL_HARNESS_JUDGE_MODEL', 'gpt-4o-mini'),
            'timeout_seconds' => (int) env('EVAL_HARNESS_JUDGE_TIMEOUT', 60),
            'prompt_template' => env('EVAL_HARNESS_JUDGE_PROMPT_TEMPLATE'),
        ],

    ],

    'reports' => [
        'disk' => env('EVAL_HARNESS_REPORTS_DISK', 'local'),
        'path_prefix' => env('EVAL_HARNESS_REPORTS_PATH', 'eval-harness/reports'),
    ],
];
```

### Pointing at a non-OpenAI provider

```env
# OpenRouter
EVAL_HARNESS_JUDGE_ENDPOINT=https://openrouter.ai/api/v1/chat/completions
EVAL_HARNESS_JUDGE_API_KEY=or-your-key
EVAL_HARNESS_JUDGE_MODEL=anthropic/claude-3.5-sonnet

# Regolo (Italian sovereign infra)
EVAL_HARNESS_JUDGE_ENDPOINT=https://api.regolo.ai/v1/chat/completions
EVAL_HARNESS_JUDGE_API_KEY=rgl-your-key
EVAL_HARNESS_JUDGE_MODEL=mistral-large
```

The cosine-embedding metric only works against an embeddings endpoint
returning the OpenAI shape (`data[0].embedding`), but most providers
already implement that contract.

---

## Architecture

```
┌──────────────────────────────────────────────────────────────────┐
│  EvalCommand (php artisan eval-harness:run)                      │
│  └─► resolves Registrar (--registrar=FQCN)                       │
│      └─► registrar binds dataset + callable/SampleRunner SUT     │
└──────────────────────────────────────────────────────────────────┘
                                 │
                                 ▼
┌──────────────────────────────────────────────────────────────────┐
│  EvalEngine                                                      │
│  - dataset registry (in-memory, single source of truth)          │
│  - run(dataset, sut) / runBatch(dataset, sut, BatchOptions)      │
│      ├─► dispatch samples through SerialBatch or LazyParallelBatch│
│      ├─► invoke input callable or SampleInvocation callable/runner│
│      ├─► lazy-parallel jobs write outputs to BatchResultStore     │
│      ├─► for each metric: score(sample, actual)                  │
│      │   - exception → SampleFailure                             │
│      │   - clean → MetricScore                                   │
│      └─► assemble EvalReport                                     │
└──────────────────────────────────────────────────────────────────┘
                  │                                 │
                  ▼                                 ▼
┌────────────────────────────┐       ┌────────────────────────────┐
│  Metrics                   │       │  Reports                   │
│  - ExactMatchMetric        │       │  - EvalReport              │
│  - CosineEmbeddingMetric   │       │  - MarkdownRenderer        │
│  - LlmAsJudgeMetric        │       │  - JsonRenderer            │
│  (Http::-backed)           │       │  - macroF1, p50, p95, mean │
└────────────────────────────┘       └────────────────────────────┘
```

### Pluggable metric registry (R23)

`MetricResolver` accepts:
1. A `Metric` instance (full control).
2. An FQCN string (resolved through the container).
3. A built-in alias: `exact-match`, `cosine-embedding`, `llm-as-judge`.

Every resolved class is asserted to implement `Metric` so a typo'd
FQCN fails with a clear error instead of producing a runtime
"method does not exist".

### Standalone-agnostic guarantee

`tests/Architecture/StandaloneAgnosticTest.php` walks `src/` and
fails the build if it finds a substring referring to:

- AskMyDocs internal symbols (`KnowledgeDocument`, `kb_nodes`, etc.).
- Sibling Padosoft packages (`padosoft/laravel-flow`,
  `padosoft/laravel-patent-box-tracker`, etc.).

The package is consumed by AskMyDocs / patent-box-tracker / others
but never depends on them. Your `composer require` works exactly the
same whether you use AskMyDocs or not.

---

## 🚀 AI vibe-coding pack included

Every Padosoft package ships with a `.claude/` pack that primes
[Claude Code](https://claude.com/claude-code) (or any compatible AI
coding agent) on the conventions used across the Padosoft repos:

- **Skills** — auto-loaded when context matches (e.g. opening a
  Pull Request triggers `copilot-pr-review-loop`, editing tests
  triggers `test-actually-tests-what-it-claims`).
- **Rules** — domain-specific guardrails (Laravel naming, query
  optimisation, exception handling, frontend testability).
- **Agents** — specialised sub-agents for reviewing PRs,
  investigating CI failures, anticipating Copilot review findings.
- **Pattern adoption** — the COMPANY-PACK + COMPANY-INVENTORY +
  PATTERN-ADOPTION docs explain how the conventions evolved across
  PRs so an AI agent can match the prevailing style on day one.

Install Claude Code, open the repo, and your agent immediately knows
the house style. No prompt engineering required.

---

## Testing

The package ships three PHPUnit testsuites:

```bash
# Default — offline, fast (1-2s)
vendor/bin/phpunit --testsuite Unit

# File-system invariants (standalone-agnostic)
vendor/bin/phpunit --testsuite Architecture

# Opt-in live test against a real LLM provider
EVAL_HARNESS_LIVE_API_KEY=sk-... vendor/bin/phpunit --testsuite Live
```

The `Unit` and `Architecture` suites are run by CI on every PR
across the PHP 8.3 / 8.4 / 8.5 x Laravel 12 / 13 matrix. The `Live`
suite is **never** run by CI — invoke it explicitly when you want
to verify wire compatibility with a new provider or model.

### Live suite contract

Every test in `tests/Live/` calls `markTestSkipped(...)` from
`setUp()` when `EVAL_HARNESS_LIVE_API_KEY` is empty. A contributor
running the default `phpunit` invocation never trips the live path
accidentally and never burns API credits.

---

## Roadmap

### v0.2 (in progress)

- **Cohort metrics** — aggregate scores by `metadata.tags` so the
  report surfaces "geography questions are 95%, refund-policy
  questions are 60%" instead of a single mean. Implemented in
  Markdown/JSON reports.
- **Histogram view** in Markdown and JSON reports.
- **Parallel batch evals** — `SerialBatch`, `LazyParallelBatch`,
  `--batch=serial`, and `--batch=lazy-parallel` are implemented for
  deterministic serial runs and queue-backed sample fan-out.
- **Eval sets with resumable progress** — run named groups of
  datasets and resume interrupted multi-dataset runs.
- **Standalone output assertions** — score saved JSON/YAML outputs
  without invoking an agent, closing the Promptfoo-style CI workflow
  gap. Implemented through `Eval::scoreOutputs()` and `--outputs`.
- **More built-in metrics**: ROUGE-L and citation-groundedness
  baseline are implemented; BERTScore (via embeddings) and
  refusal-quality (LLM-as-judge specialised prompt) remain planned.
- **Usage summaries** — token, cost, and latency fields when metric
  providers expose usage.

### v0.3 (planned)

- **Adversarial harness** — prompt injection / jailbreak / tool-abuse
  test datasets bundled (opt-in), including multi-input targets and
  compliance/framework mapping for security reports.
- **Regression detection** — store the last N runs in a JSON
  manifest and fail the gate when macro-F1 drops more than X%.
- **Report API contract for a separate UI package** — read-only
  Laravel routes/resources for JSON reports, cohorts, histograms,
  CSV export, and artifacts. No bundled UI in this package; deploy
  the UI behind your existing admin gate.
- **Dataset splits/filtering and failure promotion** — keep parity
  with LangSmith-style workflows while staying local-file-first.

### v1.0

- Stable contract on `Metric`, `EvalReport`, JSON report shape.
- Backward-compat guarantees within minor versions.

---

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). PRs follow the Padosoft
9-step canonical flow (R36): branch + local green + open PR with
Copilot reviewer + wait for CI + wait for review + fix + repeat
until zero must-fix comments + green CI + merge.

---

## Security

If you discover a security vulnerability, see
[SECURITY.md](SECURITY.md) — please do **not** open a public issue.

---

## License

Apache-2.0. See [LICENSE](LICENSE).

Copyright © 2026 [Padosoft](https://padosoft.com) — Lorenzo Padovani.
