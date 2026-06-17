---
title: "Scoring saved outputs"
description: "Score precomputed model responses without invoking your agent in CI — the --outputs flag, the map and list formats, EvalFacade::scoreOutputs(), and when to decouple generation from scoring."
---

Sometimes generation and scoring should be **decoupled**. A nightly job, an
offline batch, or an expensive GPU pipeline produces model responses once; you
want to score those saved outputs in CI without re-running the agent. The
`--outputs` mode does exactly that.

## Why decouple

::: grids
::: grid
::: card "Expensive or slow generation" icon:hourglass
If producing answers takes minutes per sample or needs hardware CI doesn't
have, generate once and score the artifact cheaply on every push.
:::
:::
::: grid
::: card "Deterministic CI" icon:lock
Scoring a fixed outputs file removes provider variance from the gate
entirely — the same outputs always produce the same report.
:::
:::
::: grid
::: card "Reproduce a production incident" icon:bug
Capture the exact answers a model gave in prod, save them, and score them
against your golden dataset to confirm the regression offline.
:::
:::
::: grid
::: card "Compare two systems" icon:git-compare
Score outputs from model A and model B against the same dataset and metrics,
then diff the two reports.
:::
:::
:::

## The outputs file

`--outputs` accepts **JSON or YAML** in two shapes.

::: tabs

== tab "Map form"
Keyed by sample id:

```json
{
  "outputs": {
    "capital-france": "Paris",
    "refund-policy": "30 days from delivery."
  }
}
```

== tab "List form"
A list of `{id, actual_output}` objects:

```json
{
  "outputs": [
    { "id": "capital-france", "actual_output": "Paris" },
    { "id": "refund-policy", "actual_output": "30 days from delivery." }
  ]
}
```

:::

Each entry is aligned to its dataset sample by `id`, then scored with the
dataset's declared metrics.

## Running it

```bash
php artisan eval-harness:run rag.factuality.fy2026 \
  --registrar="App\Console\EvalRegistrar" \
  --outputs=eval/outputs/factuality.json \
  --json --out=factuality.json
```

The registrar still registers the dataset and its metrics, but **no
`eval-harness.sut` binding is required** in this mode — there is no SUT to
invoke.

::: callout warning
When `--outputs` is set, the run goes straight to `scoreOutputs()` and
**bypasses the batch contract entirely**. `--batch`, `--batch-profile`,
`--concurrency`, `--rate-limit`, and the other dispatch flags are ignored —
there is nothing to dispatch, only saved outputs to score.
:::

## Programmatic scoring

The same path is available through the facade — useful inside a test or a custom
command:

```php
use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Facades\EvalFacade;

// The facade class is EvalFacade because `Eval` is a reserved word in PHP and
// cannot be used as a class alias (`use ... as Eval` is a parse error).
EvalFacade::dataset('rag.smoke')
    ->withSamples([
        new DatasetSample(id: 's1', input: ['q' => 'hi'], expectedOutput: 'hello'),
        new DatasetSample(id: 's2', input: ['q' => 'bye'], expectedOutput: 'goodbye'),
    ])
    ->withMetrics(['exact-match'])
    ->register();

$report = EvalFacade::scoreOutputs('rag.smoke', [
    's1' => 'hello',
    's2' => 'wrong answer',
]);
```

`$report` is the same `EvalReport` a full run produces — identical aggregates,
cohorts, histograms, and JSON shape.

## Path handling

Relative `--out` paths resolve against the configured reports disk and prefix
(`eval-harness/reports` by default). Add `--raw-path` only when you want a literal
filesystem path and its parent directory already exists.

::: grids
::: grid
::: card "Running evaluations" icon:play
The full SUT-driven run for comparison.

[Open →](/guides/running-evaluations)
:::
:::
::: grid
::: card "Report contract" icon:file-code
The JSON shape both modes produce.

[Open →](/architecture/report-contract)
:::
:::
:::

