# Contract Stability And Version Policy

`padosoft/eval-harness` is a headless Laravel package intended for
production automation. This document defines the stability baseline for
the v1.0 contract surface and is the source of truth for breaking-change
decisions.

## Core contract surfaces

The following public contracts are versioned and reviewed for stability:

- Dataset contract (`dataset schema`): `eval-harness.dataset.v1`
  (`README` and `YamlDatasetLoader`).
- Report contract (`JSON report schema`): `eval-harness.report.v1`
  (`JsonReportRenderer` output).
- Report API contract: `eval-harness.api.v1`
  (`ReportArtifactController` + API resources + `docs/REPORT_API_CONTRACT.md`).
- Eval-set contract:
  - `eval-harness.eval-set.v1` (`EvalSetDefinition`)
  - `eval-harness.eval-set-manifest.v1` (`EvalSetManifest`)
- Adversarial manifest contract: `eval-harness.adversarial-runs.v1`
  (`AdversarialRunManifest`).
- Queue batch result contract (`CacheBatchResultStore`) currently
  treated as stable for queue payloads and cache keys used by queued jobs.
- Command-level contracts:
  - `eval-harness:run`
  - `eval-harness:adversarial` (alias `eval:adversarial`)

## SemVer and compatibility policy

This package follows semantic versioning:

- **Major**: changing these contracts in a breaking way.
  - Removing fields, changing required/typed fields, renaming commands,
    removing supported command options, or changing queue payload fields in
    incompatible ways requires a new major.
- **Minor**: additive, backward-compatible contract changes.
  - Adding optional fields to JSON contracts.
  - Adding optional CLI options.
  - Adding new metrics via `Metric` registration APIs.
  - Extending reports with additional fields under
    existing parent structures.
- **Patch**: bug fixes and internal quality/performance changes that keep
  existing external expectations unchanged.

## Non-breaking guarantees

The package guarantees these are preserved by default within a minor version:

- **Stable schema IDs** in all serialized outputs.
- **Additive expansion** for JSON report, manifest, and API contract fields.
- **CLI exit behavior** continues to use non-zero exits for captured errors
  unless explicitly opted into strict exceptions mode.
- **Queue job serialization safety** for sample evaluation (`EvaluateSampleJob`)
  through `SampleInvocation` and a concrete `SampleRunner` class contract.

## Consumer guidance

Before upgrading across major versions:

1. Read release notes for each major migration section.
2. Review sample migration playbook in
   [`docs/MIGRATION_FROM_PRE_1_0.md`](docs/MIGRATION_FROM_PRE_1_0.md).
3. Pin version ranges in `composer.json` to the desired major (`^1.0` for
   v1 semantics).
4. Validate generated report manifests after upgrade in CI.

## Reported contract examples

- Use schema IDs to assert compatibility in consumers before loading:
  - `schema_version: eval-harness.report.v1`
  - `dataset_schema_version: eval-harness.dataset.v1`
  - `schema_version: eval-harness.api.v1` for API payloads.
- Keep manifest records and report artifacts as immutable historical artifacts;
  only additive readers should handle older `v1` payloads.

