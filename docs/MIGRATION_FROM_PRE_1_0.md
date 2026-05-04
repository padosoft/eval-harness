# Migration From Pre-1.0 To 1.x

`padosoft/eval-harness` is in a 0.x release history before the planned 1.0 line.
The migration steps below assume moving to a semver-stable 1.x contract.

## 1) Composer strategy

1. Pin to the first stable major explicitly:

```bash
composer require padosoft/eval-harness:"^1.0"
```

2. In CI, avoid floating ranges during upgrades:

```bash
composer update padosoft/eval-harness --with-all-dependencies
```

## 2) Validate dataset compatibility

- `schema_version` in dataset YAML is now explicit and must be
  `eval-harness.dataset.v1` when present.
- Existing legacy datasets without `schema_version` are still accepted and
  default to `eval-harness.dataset.v1`.
- If you have a custom dataset parser or preprocessor, ensure it can
  tolerate the canonical `dataset.name`, `samples`, and optional
  `metadata.tags` formats.

## 3) Validate report ingestion code

- Always read `schema_version` and `dataset_schema_version` from JSON reports.
- Only treat report artifacts as compatible when both versions are one of
  supported values.
- Ignore unknown top-level fields to remain forward compatible with future
  additive fields.

## 4) Queue and execution behavior

- Keep queue job handlers concrete and `SampleRunner`-based in production
  queue mode (`--batch=lazy-parallel`).
- Ensure queue workers and the invoking command share the configured cache
  store for batch result collection.
- If your deployment previously used long-running queue workers, verify
  cache persistence and `timeout`/`batch-timeout` sizing are aligned to
  your maximum per-sample latency.

## 5) API consumers

- Consumers of read-only report API must validate `schema_version`
  (`eval-harness.api.v1`) and support additive schema expansion.
- Routes are opt-in and require explicit middleware by host app policy.
- The API contract examples and schemas are documented in
  [`docs/REPORT_API_CONTRACT.md`](docs/REPORT_API_CONTRACT.md).

## 6) Adversarial/manifests and regression gates

- Manifests are now compatibility-aware and keyed by report schema,
  dataset, metric names, and adversarial signatures.
- If you had custom scripts around manifest JSON, ensure they accept:
  - `eval-harness.adversarial-runs.v1`
  - `status: pass | fail | missing-baseline | baseline-missing-metric`
  - `compatible`/`incompatible` metadata fields.
- `--promote-failures` may rewrite an existing file with zero samples and
  will now clear that artifact on no-failure runs.

## 7) CI gate and command usage

- Keep gate scripts aligned with explicit exit semantics:
  non-zero means captured metric failure or configured assertion failure.
- For non-LLM-only metrics (`exact-match`, `contains`, etc.), tests remain
  deterministic with `Http::fake`.

## 8) Command and metric compatibility checks

- `--outputs` and `--json/--raw-path` behaviors are stable and intentionally
  constrained to deterministic filesystem paths when requested.
- New metrics can be introduced safely in patch/minor releases if they do not
  alter existing metric aliases or defaults.

## 9) Fast verification checklist

Before merging a host app upgrade:

- Run a smoke gate on at least one dataset:
  `php artisan eval-harness:run ...`.
- Generate and load a JSON report and API payload in your downstream consumer.
- Run your adversarial workflow once with manifest output:
  `php artisan eval-harness:adversarial ... --manifest=...`.

