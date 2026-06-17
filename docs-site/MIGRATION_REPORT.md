# Mintlify → docmd migration report

Converted 31 files.

## Icons

All FontAwesome icons mapped to Lucide. ✅

## Unconverted tags

None. ✅

## Per-file conversion log

### `architecture/decisions.mdx`
- OK: <Accordion "Datasets are YAML, never database rows"> -> ::: collapsible
- OK: <Accordion "The report is human-readable and machine-versioned"> -> ::: collapsible
- OK: <Accordion "Minimal schema footprint — one auto-loaded migration"> -> ::: collapsible
- OK: <Accordion "Raw Http:: — no vendor AI SDKs"> -> ::: collapsible
- OK: <Accordion "Deterministic judges, narrow retries"> -> ::: collapsible
- OK: <Accordion "Metric exceptions are captured by default"> -> ::: collapsible
- OK: <Accordion "Lazy-parallel preserves positional ordering"> -> ::: collapsible
- OK: <Accordion "A SampleInvocation DTO carries queue work"> -> ::: collapsible
- OK: <Accordion "Adversarial coverage is opt-in"> -> ::: collapsible
- OK: <Accordion "Broken runs can never seed a baseline"> -> ::: collapsible
- OK: <Accordion "The report API is read-only and bundles no auth"> -> ::: collapsible
- OK: <Accordion "The package depends on none of its consumers"> -> ::: collapsible
- OK: <CardGroup> (2) -> ::: grids

### `architecture/evaluation-pipeline.mdx`
- OK: <Accordion "Order preservation"> -> ::: collapsible
- OK: <Accordion "Failures are data"> -> ::: collapsible
- OK: <Accordion "Determinism offline"> -> ::: collapsible
- OK: <Accordion "Narrow provider retries"> -> ::: collapsible
- OK: <CardGroup> (2) -> ::: grids
- OK: <Warning> -> ::: callout warning

### `architecture/overview.mdx`
- OK: <CardGroup> (2) -> ::: grids

### `architecture/report-contract.mdx`
- OK: <Accordion "Top-level aggregates"> -> ::: collapsible
- OK: <Accordion "metrics"> -> ::: collapsible
- OK: <Accordion "metric_distributions"> -> ::: collapsible
- OK: <Accordion "usage"> -> ::: collapsible
- OK: <Accordion "cohorts"> -> ::: collapsible
- OK: <Accordion "samples"> -> ::: collapsible
- OK: <Accordion "failures"> -> ::: collapsible
- OK: <Accordion "adversarial (always present)"> -> ::: collapsible
- OK: <CardGroup> (2) -> ::: grids

### `best-practices/regression-gating.mdx`
- OK: <Accordion "Never let a broken run become a baseline"> -> ::: collapsible
- OK: <Accordion "Match baselines to slices"> -> ::: collapsible
- OK: <Accordion "Fail closed on missing aggregates"> -> ::: collapsible
- OK: <CardGroup> (2) -> ::: grids
- OK: <CardGroup> (2) -> ::: grids
- OK: <Tip> -> ::: callout tip

### `best-practices/safety-and-red-teaming.mdx`
- OK: <Steps> (3) -> ::: steps
- OK: <CardGroup> (2) -> ::: grids
- OK: <Tip> -> ::: callout tip
- OK: <Warning> -> ::: callout warning

### `best-practices/trustworthy-judges.mdx`
- OK: <Steps> (3) -> ::: steps
- OK: <Accordion "Self-preference — never let a model grade itself"> -> ::: collapsible
- OK: <Accordion "Length bias — watch the verbosity correlation"> -> ::: collapsible
- OK: <Accordion "Leniency drift — read the confusion matrix, not just the rate"> -> ::: collapsible
- OK: <CardGroup> (2) -> ::: grids

### `configuration.mdx`
- OK: <CardGroup> (2) -> ::: grids
- OK: <Warning> -> ::: callout warning

### `core-concepts.mdx`
- OK: <Accordion "A standard run"> -> ::: collapsible
- OK: <Accordion "Scoring saved outputs (no SUT)"> -> ::: collapsible
- OK: <Accordion "An eval set"> -> ::: collapsible
- OK: <Accordion "The adversarial lane"> -> ::: collapsible
- OK: <CardGroup> (3) -> ::: grids
- OK: <Note> -> ::: callout info

### `guides/adversarial-testing.mdx`
- OK: <CardGroup> (10) -> ::: grids
- OK: <CardGroup> (2) -> ::: grids
- OK: <Note> -> ::: callout info
- OK: <Warning> -> ::: callout warning

### `guides/ci-gate.mdx`
- OK: <Accordion "Prefer offline metrics on the PR lane"> -> ::: collapsible
- OK: <Accordion "Push expensive judges to a nightly lane"> -> ::: collapsible
- OK: <Accordion "Use a batch profile"> -> ::: collapsible
- OK: <CardGroup> (2) -> ::: grids
- OK: <Note> -> ::: callout info
- OK: <Warning> -> ::: callout warning

### `guides/golden-datasets.mdx`
- OK: <Steps> (4) -> ::: steps
- OK: <Accordion "tags — cohort slicing"> -> ::: collapsible
- OK: <Accordion "k — retrieval cutoff override"> -> ::: collapsible
- OK: <Accordion "refusal_expected — safety contract"> -> ::: collapsible
- OK: <Accordion "citation_evidence — grounded-citation spans"> -> ::: collapsible
- OK: <CardGroup> (2) -> ::: grids
- OK: <Tip> -> ::: callout tip

### `guides/judge-calibration.mdx`
- OK: <Accordion "Exit non-zero (build fails)"> -> ::: collapsible
- OK: <Accordion "Warn (non-fatal)"> -> ::: collapsible
- OK: <Accordion "Pass"> -> ::: collapsible
- OK: <CardGroup> (2) -> ::: grids
- OK: <Tip> -> ::: callout tip

### `guides/online-monitoring.mdx`
- OK: <CardGroup> (2) -> ::: grids
- OK: <Note> -> ::: callout info
- OK: <Warning> -> ::: callout warning
- OK: <Warning> -> ::: callout warning

### `guides/running-evaluations.mdx`
- OK: <Tabs> (2) -> ::: tabs
- OK: <CardGroup> (2) -> ::: grids
- OK: <Note> -> ::: callout info

### `guides/scoring-saved-outputs.mdx`
- OK: <Tabs> (2) -> ::: tabs
- OK: <CardGroup> (4) -> ::: grids
- OK: <CardGroup> (2) -> ::: grids
- OK: <Warning> -> ::: callout warning

### `installation.mdx`
- OK: <Tabs> (3) -> ::: tabs
- OK: <CardGroup> (2) -> ::: grids
- OK: <Note> -> ::: callout info
- OK: <Tip> -> ::: callout tip
- OK: <Warning> -> ::: callout warning

### `introduction.mdx`
- OK: <CardGroup> (6) -> ::: grids
- OK: <CardGroup> (3) -> ::: grids
- OK: <Note> -> ::: callout info

### `metrics/lexical-and-structural.mdx`
- OK: <CardGroup> (2) -> ::: grids
- OK: <Note> -> ::: callout info
- OK: <Warning> -> ::: callout warning

### `metrics/llm-as-judge.mdx`
- OK: <Accordion "Self-preference bias"> -> ::: collapsible
- OK: <Accordion "Length / verbosity bias"> -> ::: collapsible
- OK: <Accordion "Position / leniency drift"> -> ::: collapsible
- OK: <CardGroup> (2) -> ::: grids
- OK: <Note> -> ::: callout info
- OK: <Warning> -> ::: callout warning
- OK: <Warning> -> ::: callout warning

### `metrics/ordinal-and-aggregation.mdx`
- OK: <CardGroup> (2) -> ::: grids
- OK: <Note> -> ::: callout info

### `metrics/overview.mdx`
- OK: <Accordion "The output is a single deterministic fact (an id, a date, a yes/no)"> -> ::: collapsible
- OK: <Accordion "The output is free-form prose that can be paraphrased many ways"> -> ::: collapsible
- OK: <Accordion "Correctness is subjective and needs a rubric"> -> ::: collapsible
- OK: <Accordion "You are measuring the retriever, not the generator"> -> ::: collapsible
- OK: <Accordion "Safety / refusal behavior matters"> -> ::: collapsible
- OK: <Accordion "Labels are ordered (low < medium < high < urgent)"> -> ::: collapsible
- OK: <Accordion "Answers must cite their evidence"> -> ::: collapsible
- OK: <CardGroup> (2) -> ::: grids
- OK: <Tip> -> ::: callout tip

### `metrics/retrieval-ranking.mdx`
- OK: <CardGroup> (2) -> ::: grids
- OK: <Tip> -> ::: callout tip

### `metrics/semantic-similarity.mdx`
- OK: <CardGroup> (2) -> ::: grids
- OK: <Note> -> ::: callout info
- OK: <Tip> -> ::: callout tip
- OK: <Warning> -> ::: callout warning

### `operations/batch-execution.mdx`
- OK: <Accordion "Works in lazy-parallel"> -> ::: collapsible
- OK: <Accordion "Serial-only"> -> ::: collapsible
- OK: <CardGroup> (2) -> ::: grids
- OK: <Note> -> ::: callout info

### `operations/horizon-and-queues.mdx`
- OK: <Accordion "Cache store"> -> ::: collapsible
- OK: <Accordion "Queue driver"> -> ::: collapsible
- OK: <Accordion "Horizon supervisors"> -> ::: collapsible
- OK: <Accordion "Online monitoring queue"> -> ::: collapsible
- OK: <CardGroup> (2) -> ::: grids
- OK: <Warning> -> ::: callout warning

### `operations/troubleshooting.mdx`
- OK: <CardGroup> (2) -> ::: grids

### `quickstart.mdx`
- OK: <Steps> (5) -> ::: steps
- OK: <Tip> -> ::: callout tip
- OK: <Tip> -> ::: callout tip
- OK: <Warning> -> ::: callout warning
- OK: <CardGroup> (3) -> ::: grids
- OK: <Note> -> ::: callout info

### `reference/cli.mdx`
- OK: <Accordion "Output (--json / --out / --raw-path)"> -> ::: collapsible
- OK: <Accordion "The none/null sentinel"> -> ::: collapsible
- OK: <Accordion "Strict mode"> -> ::: collapsible
- OK: <CardGroup> (2) -> ::: grids

### `reference/metrics-catalog.mdx`
- OK: <CardGroup> (2) -> ::: grids

### `reference/report-api.mdx`
- OK: <CardGroup> (2) -> ::: grids
- OK: <Note> -> ::: callout info
- OK: <Warning> -> ::: callout warning

