---
title: PHP API
description: "Reference for the public PHP contracts used to register datasets, run evals, build metrics, score saved outputs, and capture online samples."
---

# PHP API

The package is primarily driven through Artisan, but the same engine is exposed
as normal Laravel services for test suites, custom dashboards, and host
application workflows.

::: callout info
All examples assume the package service provider has been loaded by Laravel's
package discovery and the host app has published `config/eval-harness.php`.
:::

## Eval engine

Resolve `Padosoft\EvalHarness\EvalEngine` from the container when you need to
register datasets and run a system under test directly.

```php
use Padosoft\EvalHarness\EvalEngine;

$report = app(EvalEngine::class)
    ->registerDataset($dataset)
    ->run('rag.factuality', fn (string $input) => app(RagAgent::class)->answer($input));
```

The SUT can be a callable, a `SampleRunner`, or a `SampleInvocation`-aware
callable. Use the command path when you want exit-code behavior; use the service
path when another workflow owns orchestration.

## Dataset builder

Programmatic datasets are useful for generated adversarial seeds, test fixtures,
or host applications that already store canonical cases elsewhere.

```php
use Padosoft\EvalHarness\Datasets\DatasetBuilder;

$dataset = DatasetBuilder::make('rag.factuality')
    ->sample(
        id: 'refund-policy',
        input: 'How long do I have to request a refund?',
        expectedOutput: 'Refunds are available within 30 days.',
        metadata: ['tags' => ['policy', 'refunds']],
    )
    ->metric('contains')
    ->metric('llm-as-judge')
    ->build();
```

## Metrics

Custom metrics implement `Padosoft\EvalHarness\Metrics\Metric` and return a
`MetricScore` between `0.0` and `1.0`.

```php
use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Metrics\Metric;
use Padosoft\EvalHarness\Metrics\MetricScore;

final class StartsWithExpectedMetric implements Metric
{
    public function name(): string
    {
        return 'starts-with-expected';
    }

    public function score(DatasetSample $sample, mixed $actual): MetricScore
    {
        return new MetricScore(str_starts_with((string) $actual, (string) $sample->expectedOutput) ? 1.0 : 0.0);
    }
}
```

Register an instance in the dataset, pass an FQCN string, or bind it behind a
container alias consumed by `MetricResolver`.

## Saved outputs

Use `SavedOutputsLoader` when generation and scoring are deliberately separated.
The loaded outputs can be passed through the same metrics and report renderers as
a live run.

```php
use Padosoft\EvalHarness\Outputs\SavedOutputsLoader;

$outputs = app(SavedOutputsLoader::class)->load(base_path('storage/eval/outputs.json'));
$report = app(EvalEngine::class)->scoreOutputs('rag.factuality', $outputs);
```

## Online monitor

`OnlineMonitor::capture()` samples production traffic according to config and
queues `JudgeLiveSampleJob` when the sample is selected.

```php
use Padosoft\EvalHarness\Online\OnlineMonitor;

app(OnlineMonitor::class)->capture(
    dataset: 'rag.factuality',
    input: $question,
    expectedOutput: $expectedAnswer,
    actualOutput: $agentAnswer,
    metadata: ['tenant' => $tenantId],
);
```

::: callout warning
Do not put secrets, raw provider payloads, or customer PII in metadata. Reports
and trend APIs are intentionally optimized for quality signals, not audit-log
retention.
:::

## Worked example

::: steps
1. **Build a tiny dataset**

   Define one or two cases with `DatasetBuilder` inside a PHPUnit test.

2. **Register a deterministic SUT**

   Pass a closure that returns fixed outputs so the test is network-free.

3. **Assert the aggregate**

   Read the returned `EvalReport` and assert the metric aggregate or macro-F1
   threshold that matters for the code path under test.
:::

## Gotchas and limits

::: collapsible "The service path does not fail CI by itself"
Artisan commands translate report failures into process exit codes. Direct PHP
calls return a report object, so the host test or job must make the assertion.
:::

::: collapsible "Provider-backed metrics still need configured clients"
`llm-as-judge`, `refusal-quality`, `cosine-embedding`, and `bertscore-like`
resolve judge or embedding clients from the container. Fake those bindings in
unit tests.
:::

::: collapsible "Saved outputs bypass batch options"
When scoring precomputed outputs, the package does not dispatch the SUT, so
batch profile, queue, concurrency, timeout, and rate-limit flags are irrelevant.
:::

::: grids
::: grid
::: card "CLI reference" icon:terminal
Use Artisan when you want shell-friendly exit codes and report files.

[Open](/reference/cli)
:::
:::

::: grid
::: card "Report API" icon:plug
Expose stored reports to companion dashboards through read-only routes.

[Open](/reference/report-api)
:::
:::
:::
