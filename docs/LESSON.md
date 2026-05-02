# Lessons

## 2026-05-02

- The repo started as a very small Laravel package scaffold: no domain eval code, no `docs/`, no runtime commands, and only a no-op service provider smoke test.
- Current branch before roadmap work was `chore/test-count-readme-sync`; it includes useful scaffold/test/skill commits not present on `main`, so Macro Task 0 branched from that HEAD.
- `vendor/bin/phpstan analyse` fails without explicit paths or config: "At least one path must be specified to analyse." Add `phpstan.neon.dist` and run `vendor/bin/phpstan analyse`.
- Existing PR template and test comments referenced `v4.0`; roadmap work should normalize naming to `v0.2`, `v0.3`, and `v1.0`.
- The README currently does not mention competitors by name. Competitor-driven additions should be tracked in the implementation plan and later reflected in the final README comparison section.
- User clarified package constraints: support Laravel 12+ and PHP 8.3+; use Laravel queues with Horizon operational guidance and `sync` queues/fakes for unit tests; keep Web UI in a separate package while this package prepares APIs/contracts for it.
- Promptfoo competitor notes: useful parity targets include standalone assertions over precomputed outputs, tags on outputs, and broad red-team categories such as prompt injection, jailbreaks, SSRF, SQL/shell injection, PII leaks, competitor endorsements, and excessive agency.
- DeepEval competitor notes: useful parity targets include explicit test cases, datasets, metrics, traces, and both end-to-end and component/span-level evals.
- Ragas competitor notes: useful parity targets include runtime config, timeout/retry controls, LLM and embedding abstraction, token/cost parsing, and exception isolation at row/metric level.
- LangSmith competitor notes: useful parity targets include dataset versioning, filtering/splits, export formats, and promotion of filtered traces/failures back into datasets.
- `composer.lock` is ignored in this library repo but may exist locally; after changing Composer constraints, run `composer update --lock` locally so `composer validate --strict` does not fail on a stale ignored lock.
- PHPStan level max sees Testbench `$this->app` as nullable. Narrow it with `assertInstanceOf(\Illuminate\Foundation\Application::class, $app)` before constructing service providers or calling Laravel application methods.
