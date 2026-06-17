---
title: "Adversarial / red-team testing"
description: "The opt-in safety lane — the 10 red-team categories, the eval-harness:adversarial command, compliance-framework summaries (OWASP / NIST / EU AI Act), manifest run history, the regression gate, and failure promotion back to datasets."
---

Functional correctness is one axis; **safety** is another. The adversarial lane
builds and runs red-team seeds — prompt injection, jailbreaks, data leaks — and
scores whether your system refuses appropriately. It is **opt-in**: nothing runs
until you invoke it.

## The 10 categories

The `AdversarialDatasetFactory` ships seeds across ten red-team categories:

::: grids
::: grid
::: card "Prompt injection" icon:syringe
Instructions smuggled into otherwise-benign input that try to override the
system prompt.
:::
:::
::: grid
::: card "Jailbreak" icon:lock-open
Roleplay and framing attacks that try to bypass safety policy.
:::
:::
::: grid
::: card "Tool abuse" icon:wrench
Attempts to misuse exposed tools / functions beyond their intent.
:::
:::
::: grid
::: card "PII leak" icon:id-card
Probes that try to extract personal or sensitive data.
:::
:::
::: grid
::: card "SSRF" icon:network
Server-side request forgery via crafted URLs / hosts.
:::
:::
::: grid
::: card "SQL / shell injection" icon:terminal
Injection payloads aimed at downstream query/command execution.
:::
:::
::: grid
::: card "ASCII smuggling" icon:ghost
Hidden-character and homoglyph payloads that evade naive filters.
:::
:::
::: grid
::: card "Competitor endorsement" icon:handshake
Attempts to make the assistant promote a competitor off-policy.
:::
:::
::: grid
::: card "Excessive agency" icon:bot
Pushing the model to take actions beyond its granted authority.
:::
:::
::: grid
::: card "Hallucination overreliance" icon:cloud
Baited prompts that reward confident fabrication.
:::
:::
:::

Seeds carry `metadata.tags`, `metadata.adversarial`, `metadata.refusal_expected`,
and `metadata.refusal_policy`, so they score with `refusal-quality` and group
cleanly in reports.

## Running the lane

```bash
php artisan eval-harness:adversarial \
  --registrar="App\Console\EvalRegistrar" \
  --category=prompt-injection \
  --category=pii-leak \
  --metric=refusal-quality \
  --manifest=storage/eval/adversarial-runs.json \
  --manifest-retain=10 \
  --regression-gate \
  --regression-max-drop=5 \
  --regression-metric=refusal-quality:pass_rate \
  --json --out=adversarial.json
```

`eval:adversarial` is a short alias. The command registers only the selected
adversarial seed dataset for that invocation and accepts `--metric=*` (default
`refusal-quality`). It shares the full batch contract with `eval-harness:run`
(`--batch`, `--batch-profile`, `--concurrency`, `--queue`, backpressure flags),
unless you pass `--outputs=` to score precomputed responses.

## Compliance summaries

Reports add a safe, normalized adversarial block — **category, label, severity,
and compliance frameworks only**. Raw prompts, refusal-policy text, and arbitrary
sample metadata stay out of the JSON sample rows. The top-level `adversarial`
block aggregates category metrics and framework counts for:

- **OWASP LLM Top 10**
- **NIST AI RMF**
- **EU AI Act**-style security reporting

Markdown reports render the same data under an **Adversarial coverage** section —
a ready-made artifact for a security or compliance review.

## Manifest run history

`--manifest=<path>` maintains a local JSON run-history manifest
(`eval-harness.adversarial-runs.v1`). `--manifest-retain=N` keeps a bounded set:
the newest N summaries **plus** any additional failure-free baselines needed per
compatible report-schema / dataset / metric / category / sample-count slice.

```mermaid
flowchart TB
    RUN[adversarial --regression-gate run] --> Q1{metric failures?}
    Q1 -->|yes| NO[NOT recorded]
    Q1 -->|no| Q2{gate FAILED, or a configured<br/>regression-metric aggregate missing?}
    Q2 -->|yes| NO
    Q2 -->|no| REC["recorded — including the first clean<br/>'missing-baseline' run, which seeds the baseline"]
    REC --> MAN[(manifest<br/>locked + atomic write)]
```

::: callout warning
A `--regression-gate` run is recorded only when it has **no metric failures**,
the gate **did not fail**, and no configured regression-metric aggregate is
missing. A failed or gate-failed run is **not written at all** — so the
manifest is not a complete audit log of every gated run (rely on the
JSON/Markdown report for failed-run history). Note that a **first clean run with
no compatible baseline** reports a `missing-baseline` status but **is** recorded
— that is exactly how the baseline gets seeded for future comparisons. The net
guarantee: a *broken* run can never seed a baseline, while a *clean* one always
can.
:::

::: callout info
Size `--manifest-retain` for the number of **distinct slices** you run (report
schema × dataset × metric × category × sample-count), because each slice may
need its own clean baseline. Manifest writes are serialized with a lock file
and written through a temp file before replacing the target.
:::

## The regression gate

`--regression-gate` compares the current run against the latest **compatible,
failure-free** manifest entry before recording the current run:

- `--regression-max-drop=5` — fail if an aggregate drops more than 5 normalized
  percentage points.
- `--regression-metric=metric` or `metric:mean|p50|p95|pass_rate` (repeatable) —
  additional metric-aggregate checks. Configured metrics must exist in the
  current run; missing current aggregates **fail closed**.
- If no compatible failure-free baseline exists, the command emits an explicit
  `missing-baseline` status.

Crucially, **broken runs cannot seed baselines**: metric failures and gate
failures are excluded from baseline advancement, so a regression can never become
the new "normal".

## Failure promotion

`--promote-failures=eval/adversarial-failures.yml` exports low-scoring samples
and samples with metric exceptions into a reloadable dataset YAML seed:

- `--promoted-dataset=<name>` controls the dataset name inside the YAML
  (default `<dataset>.failures`).
- Promotion preserves the original input, expected output, and metadata, and adds
  `metadata.eval_harness.promoted_failure` with the source dataset and failed
  metric names.
- It **intentionally omits** actual model output and raw provider error messages
  from the seed.
- If no samples fail, an existing promotion file at that path is **removed**, so a
  fixed CI artifact path never keeps a stale failure seed.

This closes the loop: today's adversarial failures become tomorrow's regression
dataset.

## Running it continuously

The package ships **no daemon**. Run the lane from Laravel Scheduler or a CI cron
with a persistent manifest, Horizon-backed queues, the regression gate, and
failure promotion. See [`docs/ADVERSARIAL_CONTINUOUS_MONITORING.md`](https://github.com/padosoft/eval-harness/blob/main/docs/ADVERSARIAL_CONTINUOUS_MONITORING.md)
for the full recipe and [Safety & red-teaming](/best-practices/safety-and-red-teaming)
for the operational practice.

::: grids
::: grid
::: card "Safety & red-teaming" icon:venetian-mask
How to operate the lane as a continuous safety baseline.

[Open →](/best-practices/safety-and-red-teaming)
:::
:::
::: grid
::: card "refusal-quality metric" icon:scale
The metric the adversarial lane scores with.

[Open →](/metrics/llm-as-judge)
:::
:::
:::

