# Repeated sampling and statistical precision

A model is not a function. Run the same golden dataset twice against a pipeline
nobody touched and the macro-F1 moves anyway — different sampling, a different
cache hit, a provider that quietly rotated a dated model id behind an alias.

That leaves every eval gate with the same question, and almost every eval tool
answers it with a guess: *ignore differences under five points*. On three
executions a five-point difference is indistinguishable from a coin landing
differently, and on three hundred executions a five-point drop is a regression
you have just been told to ignore. The threshold was never the answer; it was
standing in for one.

`eval-harness` measures the answer instead.

## Executing a row more than once

```bash
php artisan eval-harness:run rag.factuality.fy2026 --repetitions=5
```

or, in the dataset itself:

```yaml
schema_version: eval-harness.dataset.v1
name: rag.factuality.fy2026
repetitions: 5
samples:
  - id: refund-policy
    input:
      question: "How many days do I have to return an order?"
    expected_output: "30 days from delivery."
```

or on the builder, which wins over the YAML field:

```php
$eval->dataset('rag.factuality.fy2026')
    ->loadFromYaml('eval/golden/factuality.yml')
    ->withRepetitions(5)
    ->withMetrics(['exact-match', 'llm-as-judge'])
    ->register();
```

The default stays **1**. A deterministic pipeline — a retriever scored with
`retrieval-mrr`, a classifier scored with `exact-match` — gains nothing from
repetition, and the package does not spend anybody's tokens by default.

::: tip Why `repetitions` and not `samples`
In this package a *sample* is a dataset row. The knob that repeats a row cannot
also be called `samples`, because that is already the list of rows. Other tools
call this "samples"; here it is `repetitions`.
:::

## What comes back

Each execution is recorded individually (`samples[].repetition`), and every row
gains an aggregate:

```json
{
  "id": "refund-policy",
  "repetitions": 5,
  "passed": 3,
  "errored": 0,
  "pass_rate": 0.6,
  "pass_rate_ci": { "low": 0.231, "high": 0.882, "confidence": 0.95 },
  "unstable": true,
  "score_mean": 0.64,
  "score_stddev": 0.37,
  "metrics": {
    "llm-as-judge": { "mean": 0.64, "stddev": 0.37, "min": 0.2, "max": 1.0, "observations": 5 }
  }
}
```

`score_stddev` is the field to read first. A row at 0.9 ± 0.01 and a row at
0.9 ± 0.4 have the same mean and nothing else in common: the second is a coin
toss that happened to land well, and it is the row that will fail a build next
week on a commit that had nothing to do with it. Those rows are listed on their
own, in the JSON report and under **Unstable rows** in the Markdown one.

The interval is a [Wilson score interval](https://en.wikipedia.org/wiki/Binomial_proportion_confidence_interval#Wilson_score_interval),
not the textbook normal one. The interesting pass rates in an eval sit at the
edges — a row that passed 3 of 3, or 0 of 3 — and the normal interval returns
zero width for both. "100% ± 0" is the single most misleading number a
regression gate can be handed.

## What the run could actually detect

Every report carries a `precision` block:

```json
{
  "repetitions": 3,
  "pass_rate": 0.667,
  "confidence": 0.95,
  "resolution": 0.533,
  "target_delta": 0.05,
  "target_resolvable": false,
  "required_repetitions": 683,
  "summary": "3 repetitions only resolve differences above 53.3 points, so a 5 points change is not distinguishable from noise here. Resolving 5 points needs at least 683 repetitions (--repetitions=683)."
}
```

Read that number before you read any other number in the report. It is the
honest version of the fixed epsilon every other tool ships: with three
executions at a two-thirds pass rate, **nothing under half the scale is
measurable**, and a gate that fails a build on a four-point drop is failing it
on noise.

It is also, deliberately, an uncomfortable number. Six hundred and eighty-three
repetitions of a judge-scored dataset is not something anybody is going to run.
The useful conclusions are the other two:

- **Gate on what you can see.** A pass-rate drop from 1.0 to 0.6 on five
  repetitions is real and worth failing a build for; a macro-F1 move from 0.81
  to 0.79 on one execution is not evidence of anything.
- **Make the rows deterministic instead.** Tighter assertions, a fixed seed,
  temperature zero on the judge, `exact-match` where a judge was doing a job
  a regex could do — every one of those shrinks the variance, and shrinking
  the variance is far cheaper than paying for the repetitions needed to see
  through it.

## The arithmetic

Comparing two runs of the same dataset is a two-proportion comparison. With
equal repetition counts and a shared working estimate of the pass rate `p`, the
standard error of the difference is `sqrt(2p(1-p)/n)`, and a difference is
distinguishable at confidence `z` when it exceeds `z` times that. Inverting for
`n` gives `required_repetitions`.

The edge case is the one that matters most in practice, because it is where
healthy suites live. A row that passed every repetition has `p(1-p) = 0`, and
the formula above would cheerfully report that zero repetitions suffice. It does
not: with no observed failures in `n` trials the 95% upper bound on the failure
rate is about `3/n` — the [rule of three](https://en.wikipedia.org/wiki/Rule_of_three_(statistics))
— so resolving a drop of `δ` from a perfect record needs `n ≥ 3/δ`. That branch
is why a 100%-passing row is reported as *"could not have seen a 5-point drop"*
rather than as certainty.

Both live in `Padosoft\EvalHarness\Statistics\SamplingPrecision` and are
callable directly:

```php
use Padosoft\EvalHarness\Statistics\SamplingPrecision;

SamplingPrecision::requiredRepetitions(passRate: 1.0, targetDelta: 0.05); // 60
SamplingPrecision::differenceResolution(passRate: 0.5, repetitions: 30);  // ~0.253
```

## Measuring the judge instead of the pipeline

`--repetitions` also applies when scoring saved outputs:

```bash
php artisan eval-harness:run rag.factuality.fy2026 \
    --outputs=storage/eval/outputs.json \
    --repetitions=5
```

The outputs are fixed, so the pipeline contributes no variance at all:
deterministic metrics return a standard deviation of zero by construction, and
anything still moving is **the judge disagreeing with itself**. That is the
cheapest judge-stability check in the package — no pipeline invocation, no new
dataset — and it pairs with
[judge calibration](/guides/judge-calibration), which answers the other half of
the question: whether the judge agrees with a human.

## Cost

Repetitions multiply executions, and for judge-scored datasets they multiply
tokens. Three sensible patterns:

| Lane | Repetitions | Why |
| --- | --- | --- |
| PR gate | 1–3 | Catch the obvious break fast; gate on pass rate, not on small score moves. |
| Nightly | 5–10 | Enough to surface unstable rows before they surface themselves. |
| Release / model swap | 10+ on a subset | The comparison that actually needs resolution, run on the rows that matter. |

`GoldenDataset::executionCount()` reports rows × repetitions, so a scheduler can
size a run before starting it.
