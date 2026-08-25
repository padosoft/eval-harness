# Changelog

All notable changes to `padosoft/eval-harness` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Entries for 0.1.0 through 1.5.0 were reconstructed on 2026-08-25 from the
published GitHub releases and the commit range between each tag; the file had
been left behind at the pre-0.1.0 scaffold. Where a release note and the diff
disagreed, the diff won.

## [Unreleased]

### Fixed

- README quickstart: the three copy-paste steps now run in the order printed on
  a fresh install. The registration snippet shows a registrar and the
  `eval-harness.sut` binding (a bare `register()` reaches "Dataset is not
  registered", and no binding reaches "No system-under-test bound"); the first
  run records the baseline the gated second run compares against (on an
  installation with no baseline `--compare=baseline` warns, skips the comparison
  and passes); and the run writes the JSON artifact `eval-harness:brief` then
  reads. Illustrated console output corrected against the format strings that
  produce it.

### Changed

- This file: 0.1.0–1.5.0 backfilled, and the long-standing `[Unreleased]`
  section — which described the pre-release scaffold — filed under `[0.1.0]`
  where it belongs.

## [1.6.0] - 2026-08-24

Six additive features. Every existing report, dataset, metric and command keeps
working unchanged; the report contract grew only new keys.

### Added

- **Repeated sampling and the difference a run could actually detect** (#49) —
  `--repetitions=N` (plus the YAML `repetitions:` field and
  `DatasetBuilder::withRepetitions()`) executes every row N times and reports
  per-row pass rate, score mean, population stddev and a **Wilson** confidence
  interval, plus a run-level `precision` block stating the smallest difference
  the run could detect and how many repetitions it would take. Rows that
  disagreed with themselves are listed separately. Default stays 1.
- **Baselines and per-row regression gating** (#50) — rows join across runs by a
  **content hash** of input + expected output, so renaming a sample keeps its
  history and rewriting the expected answer reads as a different test.
  `eval-harness:baseline`, `--compare=baseline|latest|<path>`,
  `--max-regressions=N`, `--confident-only`, `--promote-baseline`,
  `--comparison-out=<path>`. An unusable or missing reference warns and leaves
  the run's exit code alone.
- **SDK-agnostic agent trajectories and seven tool-call metrics** — a
  `Trajectory` DTO independent of any agent runtime, scored by `tool-called`,
  `approval-gated` and five siblings covering ordering, step budgets and
  approvals.
- **`eval-harness:brief`** — turns a JSON report into a briefing a person reads
  in a minute and a coding agent acts on: failing rows worst-first, metric
  semantics, cohorts and safety findings. It opens by declaring its own quoted
  blocks untrusted data.
- **Cost per run and a budget that halts** — billed and derived cost split,
  unpriced models named rather than counted as `$0.00`, and `--budget-usd`.
- **Production failures promoted into the dataset** (#54) —
  `eval-harness:promote-online` turns scored production failures into permanent
  regression tests, behind a PII redactor it refuses to run without. Promoted
  ids are derived from content, not from the database.

### Fixed

- A rate limit is a promise about a provider, and it was being made once per
  repetition: each pass built its own `RateLimitWindow`, so `--repetitions=3
  --rate-limit=10` could dispatch thirty requests inside one configured window.
  One window is now built for the whole run.
- `total_samples` had become rows × repetitions, which would have made every
  stored manifest see a dataset-size change that never happened. It means
  dataset rows again; the work count is `total_executions`.

### Documentation

- README opening rewritten around a claim a reader can evaluate, a five-row
  table of what this does that other eval tooling stops short of, and the
  companion packages (`eval-harness-ai-bridge`, `laravel-evidence-risk-review`).
  Additive only — nothing was removed (#56).

## [1.5.0] - 2026-07-24

No package changes. The tag contains a single Dependabot bump of `linkify-it`
5.0.1 → 5.0.2, a transitive dev dependency of `docs-site/` (#48). Nothing under
`src/`, `config/` or `resources/` differs from 1.4.0.

## [1.4.0] - 2026-06-20

### Documentation

- docmd documentation site rewritten as an enterprise landing page: hero banner
  and admin dashboard screenshot, one-line value proposition, problem → solution
  table, audience and moat sections, a head-to-head competitor matrix (vs Python
  eval libraries, manual spot-checks, no eval at all), architecture diagram,
  30-second quickstart and next steps. 32 pages, semantic search active.

No `src/` changes.

## [1.3.0] - 2026-06-16

Additive, backward-compatible feature set. All v1 contracts are preserved.

### Added

- Retrieval-ranking metric family (domain-agnostic, ids/texts in,
  `[0,1]` out):
  - `retrieval-hit-at-k`, `retrieval-recall-at-k`, `retrieval-mrr`,
    `retrieval-ndcg-at-k` (binary or graded gains), and
    `answer-containment-at-k`.
  - Shared `Metrics\Retrieval\RankedRetrieval` parser/value object and
    `AbstractRetrievalRankingMetric` base.
  - `metrics.retrieval.default_k` config (`EVAL_HARNESS_RETRIEVAL_DEFAULT_K`)
    with per-sample `metadata.k` override.
- `ordinal-distance` metric — partial credit for ordered labels
  (exact 1.0 / off-by-one 0.5 / further 0.0), per-sample
  `metadata.ordinal_scale` override.
- Judge calibration:
  - `eval-harness:calibrate-judge` Artisan command (verdict agreement
    rate, confusion matrix, length-bias signal, self-preference guard;
    Markdown/JSON output; CI gating).
  - `Calibration\HumanLabel`, `CalibrationCaseLoader`,
    `JudgeCalibrator`, `JudgeCalibrationReport`.
  - `calibration.*` config block.
- Online / production monitoring (off by default):
  - `Online\OnlineMonitor::capture()`, `OnlineSamplingDecision`,
    queueable `JudgeLiveSampleJob`, `OnlineScore` Eloquent model, and
    the package's first migration (`eval_harness_online_scores`).
  - `OnlineTrendRepository`, `OnlineDriftAlert`, and the
    `Online\Events\OnlinePassRateDropped` drift event.
  - Read-only API endpoint `GET /{prefix}/online/{dataset}/trend`
    (`eval-harness.report-api.v1.online-trend`) and the
    `eval-harness-migrations` publish tag.
  - `online.*` config block.
- `RuntimeOptions::normalizeUnitInterval()` helper (clamp to `[0,1]`).

The companion admin UI screen for this release lives in
padosoft/eval-harness-admin#6 and requires it.

## [1.2.0] - 2026-05-06

Additive for v1 consumers. `ReportApiSchema::VERSION` remains
`eval-harness.report-api.v1`; the new payloads carry per-payload `schema`
discriminators.

### Added

- Report diff endpoint `GET /{prefix}/reports/{id}/diff/{otherId}` — metric,
  cohort, sample-count, failure-count and adversarial deltas (#40).
- Adversarial manifest discovery endpoints for configured manifest storage (#41).
- Live batch registry and progress endpoints backed by cache, with best-effort
  observability registration and cleanup (#42).
- Dataset trend endpoint — chronological points, metrics, cohorts, usage
  summaries, arbitrary report-prefix discovery and a configurable scan cap (#43).
- `docs/UI_PACKAGE_SPEC.md`, the specification for the companion UI package.

### Documentation

- README: banner, v1.2 feature coverage, endpoint docs and companion UI link.

Release gate on `d848e3f`: 778 tests / 2165 assertions, PHPStan clean, Pint
passed, CI green across PHP 8.3/8.4/8.5 × Laravel 12/13.

## [1.1.0] - 2026-05-05

Enterprise operations and scalability add-on (#38, #37). All v1 contracts are
preserved and every new flag is additive; no hard Horizon dependency was added.

### Added

- **Operational batch profiles** — `--batch-profile=ci|smoke|nightly`, with
  explicit-CLI-wins precedence and host-app overrides under
  `eval-harness.batches.profiles.*`. Built-in defaults are conservative: a CI
  run does not exceed 4 in-flight samples.
- **Producer-side backpressure** — `--chunk-size`, `--rate-limit`,
  `--rate-window-seconds`. `RateLimitWindow` uses a monotonic clock (`hrtime`)
  and amortized O(1) sliding-window math via head-offset compaction.
- **Progress checkpoints** — `--checkpoint-every` with the optional
  `BatchProgressReporter` container binding (default
  `NullBatchProgressReporter`), plus an optional `BatchTerminalProgressReporter`
  sub-contract reporting `SUCCESS` / `FAILURE` / `EMPTY` with partial-wins
  tolerance on the failure path.
- **TTL controls** — `--result-ttl-seconds`, with separate run and dispatch TTL
  math: the run uses chunk-deadline-bounded windows, dispatch uses
  concurrency-keyed drain time with a fixed 60s per-drain-batch fallback
  decoupled from the wait-timeout config.
- `docs/HORIZON_BATCH_QUEUES.md` — Horizon supervisor sizing guidance, including
  the multi-producer caveat and `chunk-size` vs `concurrency`.

Release gate on `3e202ce`: 699 tests / 1956 assertions, PHPStan clean, Pint
passed, CI green across PHP 8.3/8.4/8.5 × Laravel 12/13.

## [1.0] - 2026-05-04

First stable release. Tagged `1.0` without the `v` prefix, unlike every tag
before and after it.

### Added

- Stable contracts for `Metric`, `EvalReport`, the JSON report schema, batch
  execution, the command surface, queue jobs and report API resources.
- Parallel batch execution — `SerialBatch` and `LazyParallelBatch`, selected
  with `--batch=serial|lazy-parallel`.
- Adversarial lane — regression manifests, `eval-harness:adversarial`,
  `--regression-gate` and `--promote-failures`.
- Read-only report API — list and show, cohorts, histograms, CSV export and
  artifact download.
- Offline and advanced metrics landed across this range: `rouge-l`,
  `bertscore-like`, `refusal-quality` and `citation-groundedness`.
- `docs/CONTRACT_STABILITY.md` and `docs/MIGRATION_FROM_PRE_1_0.md` for pre-1.0
  users; `docs/REPORT_API_CONTRACT.md` for the API.

## [0.1.0] - 2026-05-02

First public release: a standalone Laravel evaluation framework for RAG and LLM
applications, with no coupling to any sister Padosoft package.

### Added

- Public surface
  - `Padosoft\EvalHarness\Facades\EvalFacade` (registered as the
    `Eval` Laravel alias) — fluent entry point for dataset
    registration + run dispatch.
  - `Padosoft\EvalHarness\EvalEngine` — single source of truth for
    registered datasets and orchestration of the system-under-test
    pass.
  - `php artisan eval-harness:run <dataset>` — Artisan CI gate.
- Datasets
  - `GoldenDataset`, `DatasetSample`, `ParsedDatasetDefinition` DTOs.
  - `DatasetBuilder` fluent builder with `loadFromYaml()` /
    `loadFromYamlString()` / `withSamples()` / `withMetrics()` /
    `register()`.
  - `YamlDatasetLoader` strict-schema YAML loader with 11 validation
    failure modes (missing key, wrong type, duplicate id, etc.).
- Metrics
  - `Metric` interface + `MetricScore` DTO (range-checked [0, 1]).
  - `MetricResolver` — accepts alias strings, FQCN strings, or
    instantiated `Metric` objects.
  - `ExactMatchMetric` — case-sensitive byte-equality.
  - `CosineEmbeddingMetric` — embeds expected + actual via
    OpenAI-compatible embeddings endpoint, returns
    `1 - cosine_distance` clamped to `[0, 1]`.
  - `LlmAsJudgeMetric` — strict-JSON LLM grading with deterministic
    seed + temperature 0 + `response_format=json_object`.
- Reports
  - `EvalReport` — read-only outcome with mean / p50 / p95 / macroF1
    aggregates.
  - `MarkdownReportRenderer` — diff-friendly human report.
  - `JsonReportRenderer` — stable additive-only JSON shape (R27).
- Exceptions
  - `EvalHarnessException` (non-final base) +
    `DatasetSchemaException`, `MetricException`, `EvalRunException`.
- Tests
  - 109 unit tests + 3 architecture tests (~600 assertions) enforcing
    the standalone-agnostic invariant: no AskMyDocs or sibling-Padosoft
    package symbol leaks into `src/`.
  - `tests/Live/LiveLlmAsJudgeTest.php` opt-in suite gated on
    `EVAL_HARNESS_LIVE_API_KEY`.
- Tooling
  - PHP 8.3 / 8.4 / 8.5 × Laravel 12 / 13 CI matrix.
  - Pint (Laravel preset + strict types + ordered imports).
  - PHPStan level 6 against `src/`.
  - PHPUnit 12 testsuites: Unit (default) + Architecture + Live.
  - Padosoft `.claude` vibe-coding pack imported.

[Unreleased]: https://github.com/padosoft/eval-harness/compare/v1.6.0...HEAD
[1.6.0]: https://github.com/padosoft/eval-harness/compare/v1.5.0...v1.6.0
[1.5.0]: https://github.com/padosoft/eval-harness/compare/v1.4.0...v1.5.0
[1.4.0]: https://github.com/padosoft/eval-harness/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/padosoft/eval-harness/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/padosoft/eval-harness/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/padosoft/eval-harness/compare/1.0...v1.1.0
[1.0]: https://github.com/padosoft/eval-harness/compare/v0.1.0...1.0
[0.1.0]: https://github.com/padosoft/eval-harness/releases/tag/v0.1.0
