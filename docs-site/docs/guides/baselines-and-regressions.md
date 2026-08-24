# Baselines and per-row regressions

An aggregate says something changed. A baseline says *which rows*.

Before this, a report was an island: you knew today's macro-F1 and nothing about
which of your two hundred rows got worse to produce it. Comparing two runs by
eye is not a code review anyone does twice.

```bash
# after a run you are happy with
php artisan eval-harness:run rag.factuality --json --out=green.json --promote-baseline

# from then on, in CI
php artisan eval-harness:run rag.factuality --json --out=ci.json \
    --compare=baseline --max-regressions=0
```

```
Compared against the baseline [eval-harness/reports/green.json]
  2 regressed (2 beyond this run's 5.4-point detectable difference), 1 improved, 0 added, 0 removed, 40 compared.

 sample          pass rate     score delta   beyond noise   newly failing
 refund-policy   100% → 40%    -0.5800       yes            yes
 shipping-eta    80% → 40%     -0.3100       yes

Gate failed: 2 rows regressed against the baseline (allowed: 0)
```

Exit code 1, two row names, and the receipts.

## Rows join on content, not on id

The join key is a SHA-256 of the row's **input and expected output** — not its
id, and not its position in the file.

| Change | Same row? |
| --- | --- |
| Renaming `sample-14` to `refund-policy` | Yes — history is kept |
| Sorting the YAML file | Yes |
| Adding a tag or a cohort | Yes |
| Reordering keys inside `input` | Yes |
| Editing the question or the expected answer | **No** — old row removed, new row added |

That last line is the point: a row whose expected answer was rewritten is a
different test, and the measurements taken against the old one no longer
describe it. Reporting it as "regressed" would be a lie; reporting it as
removed-and-added is what actually happened.

::: tip Ids are still what you read
The hash is the join key, never the label. Every report, table and failure
message shows the sample id, because `refund-policy` means something to a human
and `9f2c8a…` does not.
:::

## Where the tolerance comes from

Every other tool in this space ships a constant — *"ignore drops under 5%"*.
That constant is wrong in both directions at once: too tight for a run of three
executions, where half the scale is sampling noise, and far too loose for a run
of three hundred, where it hides real regressions.

Here the tolerance is the run's own **detectable difference**, computed from its
repetitions and pass rate (see [repeated sampling](/guides/repeated-sampling)).
It tightens by itself as a suite gains repetitions. Pass `--compare-epsilon=0.05`
when a contract needs a fixed number instead.

## Status and confidence are separate

Two things are reported per row, and conflating them is how a gate loses its
audience:

- **status** — what happened. A drop is a drop.
- **confident** — whether this run had the repetitions to tell that drop apart
  from the pipeline sampling differently.

A single-execution run *can* see that a row went from green to red, and *cannot*
prove it. Both facts travel:

```json
{
  "sample_id": "refund-policy",
  "status": "regressed",
  "confident": false,
  "newly_failing": true,
  "resolution": 1.0,
  "pass_rate_delta": -1.0,
  "score_delta": -1.0
}
```

By default the gate counts **every** regression — a row that went red is worth
stopping a pull request for, provable or not. Add `--confident-only` for a
scheduled lane with enough repetitions to be sure, where a false alarm costs
more than a day's delay:

```bash
php artisan eval-harness:run rag.factuality --repetitions=10 \
    --compare=baseline --confident-only --max-regressions=0
```

When a gate fails on rows it could not prove, it says so in the same breath:

```
Gate failed: 3 rows regressed against the baseline (allowed: 0);
1 of 3 exceed this run's detectable difference of 53.3 points,
the rest are within sampling noise
```

## Managing the baseline

```bash
php artisan eval-harness:baseline rag.factuality                      # promote the most recent report
php artisan eval-harness:baseline rag.factuality --report=green.json  # promote a specific one
php artisan eval-harness:baseline rag.factuality --show               # what is it now?
php artisan eval-harness:baseline rag.factuality --clear              # forget it
```

A baseline is a **pointer**, not a copy: one small JSON file naming a report
artifact that already exists on the reports disk. Nothing is duplicated, so a
baseline cannot drift from the run it claims to describe, and getting it wrong
costs one more command rather than a lost artifact.

Two refusals are deliberate:

- Promoting a report from a **different dataset** is refused. No row would ever
  join, so every row would read as "added" and no regression could ever be
  detected — a baseline that silently disables the gate is worse than none.
- `--promote-baseline` **does not promote a run that failed**. Otherwise a
  regression that shipped becomes the new bar, and the next run compares
  against the broken state.

## Why the baseline is a file

This is the design decision that separates this package from the tools it
competes with.

When runs and baselines live only in a database, the baseline lives in *one*
database — whoever promoted it — and CI, which starts from an empty schema every
time, has no history at all. That gap is precisely the hole those tools then
sell a hosted service to fill.

Here the artifact and its pointer are files:

- they travel in a CI artifact,
- they can be committed next to the dataset that produced them,
- a comparison in CI reads the same bytes the developer read locally,
- and a `git diff` between two reports is a readable document.

An optional database index can be layered on top for querying, but the file
stays the source of truth and the index is rebuildable from it.

## Comparing without a baseline

`--compare=latest` compares against the most recent stored report for the
dataset, excluding the one this run just wrote. It is the question people ask
before they have promoted anything: *is this worse than the last run I did?*

`--compare=<path>` compares against any specific report on the reports disk.

## The comparison payload

`--comparison-out=diff.json` writes the full row-by-row comparison:

```json
{
  "schema_version": "eval-harness.comparison.v1",
  "dataset": "rag.factuality",
  "reference": "the baseline [eval-harness/reports/green.json]",
  "resolution": 0.054,
  "resolution_is_statistical": true,
  "macro_f1_delta": -0.031,
  "pass_rate_delta": -0.05,
  "counts": {
    "regressed": 2, "regressed_confident": 2, "newly_failing": 1,
    "improved": 1, "added": 0, "removed": 0, "compared": 40
  },
  "rows": [ … ]
}
```

Versioned and additive, like the report contract — wire a dashboard to it once.

## A missing baseline never fails a build

If the baseline was never promoted, or the artifact it pointed at has been
deleted, the run warns and finishes with the exit code it had already earned.
Losing a reference is not a reason to fail a run that was otherwise fine, and a
CI job that goes red because somebody cleaned a storage bucket teaches everyone
to ignore it.
