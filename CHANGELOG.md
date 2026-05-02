# Changelog

All notable changes to `padosoft/eval-harness` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added — W6 scaffold + initial core engine

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
  - 87 unit tests / 180 assertions. 3 architecture tests / 347
    assertions enforcing the standalone-agnostic invariant
    (no AskMyDocs / sibling-Padosoft-package symbols leak into
    `src/`).
  - `tests/Live/LiveLlmAsJudgeTest.php` opt-in suite gated on
    `EVAL_HARNESS_LIVE_API_KEY`.
- Tooling
  - PHP 8.3 / 8.4 / 8.5 × Laravel 12 / 13 CI matrix.
  - Pint (Laravel preset + strict types + ordered imports).
  - PHPStan level 6 against `src/`.
  - PHPUnit 12 testsuites: Unit (default) + Architecture + Live.
  - Padosoft `.claude` vibe-coding pack imported.

[Unreleased]: https://github.com/padosoft/eval-harness/compare/main...HEAD
