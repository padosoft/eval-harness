# Eval Harness Implementation Plan

## Summary

Build `padosoft/eval-harness` from scaffold into a headless Laravel package for AI evals, regression gates, adversarial datasets, queue-backed batch execution, and read-only report APIs for a separate Web UI package.

Current repo state:

- No-op `EvalHarnessServiceProvider`.
- Smoke PHPUnit coverage only.
- README lists planned features that are not implemented yet.
- CI exists but PHPStan/Pint must become hard gates.
- No docs/progress/lessons/rules existed before Macro Task 0.

Non-negotiable constraints:

- PHP `^8.3`.
- Laravel `^12.0|^13.0`; Laravel 11 is out of scope.
- Queue execution should be Horizon-ready for real batch runs.
- Tests must use `sync` queues, queue fakes, or deterministic in-process runners.
- This package stays headless. Web UI belongs in a separate package; this package provides report JSON, read-only APIs, resources, and route contracts for that UI.

## Macro Task 0 - Governance, Rules, And Hard Gates

Branch: `task/governance-agent-rules`

Implement:

- Add `AGENTS.md`, `CLAUDE.md`, `docs/RULES.md`, `docs/LESSON.md`, `docs/PROGRESS.md`, `.github/copilot-instructions.md`, and `.claude/skills/eval-harness-plan/SKILL.md`.
- Save this roadmap in `docs/ROADMAP_IMPLEMENTATION_PLAN.md`.
- Normalize stale `v4.0` references to roadmap naming.
- Update Composer constraints to Laravel `^12.0|^13.0`.
- Add PHPStan config and make PHPStan/Pint blocking in CI.
- Keep the existing test-count README sync skill active for PRs that change tests.

Done when:

- `composer validate --strict`
- `vendor/bin/phpunit`
- `vendor/bin/phpstan analyse`
- `vendor/bin/pint --test`
- PR loop to macro branch/main follows the documented Copilot process.

## Macro Task 1 - Core Eval Contracts v0.2

Branch: `task/core-eval-contracts`

Implement:

- Contracts for `Metric`, `EvalRunner`, `DatasetLoader`, `AgentInvoker`, and report serialization.
- DTO/value objects for samples, expected answers, metadata/tags, metric scores, run results, and report summaries.
- JSON dataset loader with schema version, validation errors, and offline fixtures.
- `eval:run` Artisan command with fake-friendly agent and metric resolution.
- Versioned JSON report writer and Markdown report writer shell.

Guardrails:

- No live LLM/network calls in tests.
- Invalid datasets fail with clear messages and non-zero exit codes.
- Report JSON shape is additive and versioned from the start.

Tests:

- DTO/contract unit tests.
- Dataset parser success/failure fixtures.
- Artisan command feature tests with fake agent and fake metrics.

## Macro Task 2 - Metrics, Cohorts, And Reports v0.2

Branch: `task/metrics-reporting`

Implement:

- Offline built-in metrics: exact match, contains, regex, ROUGE-L, citation groundedness baseline.
- Cohort metrics grouped by `metadata.tags`, including explicit bucket behavior for missing tags.
- Markdown report with summary table, cohort table, failure samples, and histogram.
- JSON report fields needed by a future UI package: metric distributions, cohorts, failures, run metadata, sample-level score rows.
- Standalone assertion mode over precomputed outputs, inspired by Promptfoo, so CI can score saved outputs without invoking an agent.

Guardrails:

- Cohort/global aggregates derive from the same raw scores.
- Histogram handles empty datasets and score bounds.
- Markdown and JSON report tests use deterministic fixtures.

Tests:

- Metric unit tests.
- Cohort multi-tag and missing-tag tests.
- JSON/Markdown fixture tests.
- Standalone output assertion tests.

## Macro Task 3 - Parallel Batch Queues v0.2

Branch: `task/parallel-batch-queues`

Implement:

- `SerialBatch` and `LazyParallelBatch`.
- Laravel queue jobs for sample evaluation and report assembly.
- CLI options: `--batch=serial|lazy-parallel`, `--concurrency=N`, `--queue=...`, `--timeout=...`.
- Horizon deployment guidance without requiring Horizon in package tests.
- Stable ordering even when queued samples finish out of order.

Guardrails:

- `sync` queue and queue fakes must cover unit/feature tests.
- Jobs must be serializable and avoid closures.
- Per-sample failures are isolated in the report unless infrastructure fails.

Tests:

- Batch scheduler tests.
- Queue fake feature tests.
- Out-of-order completion assembly tests.
- Failure isolation tests.

## Macro Task 4 - Advanced Metrics v0.2/v0.3

Branch: `task/advanced-metrics`

Implement:

- Embedding-based BERTScore-like metric through an embedding client interface.
- LLM-as-judge refusal-quality metric with strict response schema.
- Advanced citation groundedness using evidence spans and quote matching.
- Token/cost parser hook inspired by Ragas.
- Runtime config for retry/timeout/raise-exceptions behavior.

Guardrails:

- External clients are optional and fakeable.
- Judge responses fail closed when malformed.
- Reports redact sensitive prompt/provider payloads by default.

Tests:

- Fake embedding and fake judge tests.
- Response parser tests.
- Timeout/retry config tests.
- Redaction tests.

## Macro Task 5 - Adversarial Harness And Regression Detection v0.3

Branch: `task/adversarial-regression`

Implement:

- Opt-in adversarial datasets: prompt injection, jailbreak, tool abuse, PII leak, SSRF, SQL/shell injection, ASCII smuggling, competitor endorsement, excessive agency, hallucination/overreliance.
- `eval:adversarial` command.
- Manifest storing the last N runs.
- Regression gate: fail when macro-F1 or configured metric drops more than X%.
- Failure promotion workflow: export failed samples into a dataset seed for future regression coverage.

Guardrails:

- Adversarial datasets never run automatically without opt-in.
- Manifest writes are atomic.
- Missing baseline produces an explicit status, not a misleading pass.

Tests:

- Adversarial command tests.
- Manifest retention tests.
- Regression pass/fail/missing-baseline tests.
- Failure export tests.

## Macro Task 6 - Report API Contract For Separate UI Package v0.3

Branch: `task/report-api-ui-contract`

Implement:

- Read-only API routes for listing report manifests, showing a report, showing cohorts, showing histograms, and downloading JSON/Markdown artifacts.
- API resources that expose the exact data needed by a future Web UI package.
- Path/id validation to prevent traversal and accidental arbitrary file reads.
- Optional route prefix/config publishing, but no bundled UI assets.
- OpenAPI-style contract documentation or JSON examples for UI consumers.

Guardrails:

- API is read-only.
- No auth is bundled; document that host apps deploy routes behind their existing admin gate.
- No Vite, Vitest, or Playwright in this package unless UI assets are intentionally added later.

Tests:

- Feature tests for report API routes.
- Resource serialization tests.
- Path traversal and missing report tests.

## Macro Task 7 - v1.0 Stabilization, Docs, And Release

Branch: `task/v1-stabilization-release`

Implement:

- Freeze stable contracts for `Metric`, `EvalReport`, dataset schema, JSON report schema, commands, queue job payloads, and report API resources.
- Backward-compatibility policy within minor versions.
- Migration guide from pre-1.0.
- README "wow" pass inspired by `AskMyDocs`: real quick start, badges, examples, report screenshots/artifacts, CI examples, API contract, competitor comparison, and Laravel/Horizon positioning.
- Re-read `docs/LESSON.md` and upgrade `AGENTS.md`, `CLAUDE.md`, rules, skill, and Copilot instructions with all useful know-how.
- Tag and publish GitHub release after final macro PR is green and merged.

Guardrails:

- README must not claim unimplemented behavior.
- Examples must be executable or clearly marked as pseudo/example.
- Tag only after `main` is green.

Tests:

- Full package gate.
- README command smoke where feasible.
- Composer path install smoke in a Laravel test fixture if added.

## Competitor-Informed Additions

The README does not currently name competitors, but the final README should compare against them. Roadmap additions from current competitor capabilities:

- Promptfoo: standalone output assertions, tag/facet reporting, broader red-team categories, declarative assertion ergonomics.
- DeepEval: component/span-level evals and trace-aware reports in addition to black-box end-to-end evals.
- Ragas: runtime retry/timeout config, LLM/embedding abstraction, token/cost parsing, exception isolation.
- LangSmith: dataset versioning, dataset splits/filtering, export formats, and promoting failed traces/samples back into datasets.

Laravel-native differentiators:

- Artisan-first workflow.
- Horizon-ready batch eval queues.
- CI regression gates for PHP/Laravel teams.
- Headless report APIs for a separate Laravel admin/UI package.
- Deterministic offline test mode by design.
