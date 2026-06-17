---
title: "CLI reference"
description: "Every Artisan command — eval-harness:run, eval-harness:adversarial (alias eval:adversarial), and eval-harness:calibrate-judge — with exact signatures, shared flags, and exit-code semantics."
---

The package exposes three Artisan commands. Run `php artisan <command> --help`
for the authoritative, version-current option list.

## eval-harness:run

Execute a golden-dataset evaluation against a system under test, or score
precomputed outputs.

```
eval-harness:run {dataset}
  {--registrar=}
  {--outputs=}
  {--batch=serial|lazy-parallel}
  {--batch-profile=ci|smoke|nightly}
  {--concurrency=}
  {--queue=}
  {--timeout=}
  {--batch-timeout=}
  {--chunk-size=}
  {--rate-limit=}
  {--rate-window-seconds=}
  {--result-ttl-seconds=}
  {--checkpoint-every=}
  {--json}
  {--out=}
  {--raw-path}
```

| flag | purpose |
| --- | --- |
| `{dataset}` | The registered dataset name to run. |
| `--registrar=` | Registrar class to invoke (registers datasets + binds the SUT). |
| `--outputs=` | Score precomputed JSON/YAML outputs; bypasses the batch contract. |
| `--batch=` | `serial` (default) or `lazy-parallel`. |
| `--batch-profile=` | Apply a named preset (`ci` / `smoke` / `nightly`); explicit flags win. |
| `--concurrency=` | Lazy-parallel fan-out cap and default dispatch-window size. |
| `--queue=` | Queue name for lazy-parallel jobs. |
| `--timeout=` | Per-sample job timeout (seconds). |
| `--batch-timeout=` | Producer wait cap per dispatch window (dispatch + collection). |
| `--chunk-size=` | Dispatch-window size (`<= --concurrency`); the effective in-flight limit. |
| `--rate-limit=` / `--rate-window-seconds=` | Throttle N samples per W-second sliding window. |
| `--result-ttl-seconds=` | Lifetime of result metadata/outputs for delayed collection. |
| `--checkpoint-every=` | Emit a progress event every N completed samples. |
| `--json` | Render JSON instead of Markdown. |
| `--out=` | Write the report to the reports disk/prefix (or literal with `--raw-path`). |
| `--raw-path` | Treat `--out` as a literal filesystem path (parent must exist). |

**Exit code.** `0` if every metric scored cleanly; non-zero on any captured
failure (or the first `MetricException` under `raise_exceptions`). Pass `none` on
any nullable numeric flag to clear an inherited profile value.

## eval-harness:adversarial

Run opt-in adversarial red-team seeds with compliance reporting, manifest
history, regression gating, and failure promotion. Alias: **`eval:adversarial`**.

```
eval-harness:adversarial
  {--registrar=}
  {--dataset=}
  {--category=*}
  {--metric=*}
  {--outputs=}
  {--manifest=}
  {--manifest-retain=10}
  {--promote-failures=}
  {--promoted-dataset=}
  {--regression-gate}
  {--regression-max-drop=5}
  {--regression-metric=*}
  {--batch=serial|lazy-parallel}
  {--batch-profile=ci|smoke|nightly}
  {--concurrency=} {--queue=} {--timeout=} {--batch-timeout=}
  {--chunk-size=} {--rate-limit=} {--rate-window-seconds=}
  {--result-ttl-seconds=} {--checkpoint-every=}
  {--json} {--out=} {--raw-path}
```

| flag | purpose |
| --- | --- |
| `--category=*` | Red-team categories to seed (repeatable). One of the 10 built-ins. |
| `--metric=*` | Metric(s) to score with (default `refusal-quality`). |
| `--manifest=` | Local JSON run-history manifest path. |
| `--manifest-retain=` | Bounded retention: newest N summaries + needed clean baselines (default 10). |
| `--regression-gate` | Compare against the latest compatible failure-free baseline. |
| `--regression-max-drop=` | Allowed normalized-percentage-point drop (default 5). |
| `--regression-metric=*` | Extra `metric` or `metric:mean\|p50\|p95\|pass_rate` checks (repeatable). |
| `--promote-failures=` | Export failing samples to a reloadable YAML seed. |
| `--promoted-dataset=` | Dataset name inside the promotion YAML (default `<dataset>.failures`). |

Shares all batch/backpressure/output flags with `eval-harness:run`. When
`--outputs=` is set, the batch flags are ignored. **Exit code** is non-zero on
metric failures or a tripped regression gate. See
[Adversarial testing](/guides/adversarial-testing).

## eval-harness:calibrate-judge

Validate the LLM judge against human-labelled cases before gating on it.

```
eval-harness:calibrate-judge {cases}
  {--min-agreement=}
  {--model-under-test=}
  {--json}
  {--out=}
  {--raw-path}
```

| flag | purpose |
| --- | --- |
| `{cases}` | Path to the calibration YAML (`eval-harness.calibration.v1`). |
| `--min-agreement=` | Verdict-agreement floor (config default `0.8`). |
| `--model-under-test=` | The SUT model; triggers the self-preference guard. |

**Exit code.** Non-zero when verdict agreement is below the floor **or** the judge
model equals `--model-under-test`; warns (non-fatal) on suspected length bias. See
[Judge calibration](/guides/judge-calibration).

## Shared conventions

  ::: collapsible "Output (--json / --out / --raw-path)"
All three commands render Markdown to stdout by default. `--json` switches to
the versioned JSON contract. `--out=` writes to the configured reports
disk/prefix; `--raw-path` makes `--out` a literal path (parent must exist).
:::
  ::: collapsible "The none/null sentinel"
On `eval-harness:run` and `eval-harness:adversarial`, pass `none` (or `null`)
on any nullable numeric flag to clear an inherited profile value for a single
run. `--queue` is excluded — override it in config.
:::
  ::: collapsible "Strict mode"
Set `EVAL_HARNESS_RAISE_EXCEPTIONS=true` to abort on the first
`MetricException` instead of capturing it.
:::

::: grids
::: grid
::: card "Report API" icon:plug
The read-only HTTP surface for stored artifacts.

[Open →](/reference/report-api)
:::
:::
::: grid
::: card "Configuration" icon:settings
The env vars these commands read.

[Open →](/configuration)
:::
:::
:::

