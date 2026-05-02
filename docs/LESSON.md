# Lessons

## 2026-05-02

- The repo started as a very small Laravel package scaffold: no domain eval code, no `docs/`, no runtime commands, and only a no-op service provider smoke test.
- Current branch before roadmap work was `chore/test-count-readme-sync`; it includes useful scaffold/test/skill commits not present on `main`, so Macro Task 0 branched from that HEAD.
- `vendor/bin/phpstan analyse` fails without explicit paths or config: "At least one path must be specified to analyse." Add `phpstan.neon.dist` and run `vendor/bin/phpstan analyse`.
- Existing PR template and test comments referenced `v4.0`; roadmap work should normalize naming to `v0.2`, `v0.3`, and `v1.0`.
- After merging `origin/main`, README already mentions competitors by name: OpenAI Evals, LangSmith, Ragas, Promptfoo, and DeepEval. Competitor-driven additions should extend that comparison and the roadmap instead of creating a second competing section.
- User clarified package constraints: support Laravel 12+ and PHP 8.3+; use Laravel queues with Horizon operational guidance and `sync` queues/fakes for unit tests; keep Web UI in a separate package while this package prepares APIs/contracts for it.
- Promptfoo competitor notes: useful parity targets include standalone assertions over precomputed outputs, tags on outputs, and broad red-team categories such as prompt injection, jailbreaks, SSRF, SQL/shell injection, PII leaks, competitor endorsements, and excessive agency.
- DeepEval competitor notes: useful parity targets include explicit test cases, datasets, metrics, traces, and both end-to-end and component/span-level evals.
- Ragas competitor notes: useful parity targets include runtime config, timeout/retry controls, LLM and embedding abstraction, token/cost parsing, and exception isolation at row/metric level.
- LangSmith competitor notes: useful parity targets include dataset versioning, filtering/splits, export formats, and promotion of filtered traces/failures back into datasets.
- `composer.lock` is ignored in this library repo but may exist locally; after changing Composer constraints, run `composer update --lock` locally so `composer validate --strict` does not fail on a stale ignored lock.
- PHPStan level max sees Testbench `$this->app` as nullable. Narrow it with `assertInstanceOf(\Illuminate\Foundation\Application::class, $app)` before constructing service providers or calling Laravel application methods.
- Do not enable both `push` and `pull_request` CI triggers for `task/**` branches unless duplicate CI runs are desired. For this repo, `pull_request` on `task/**` is enough for subtask PRs, while `push` should stay limited to `main`.
- `origin/main` can move while a macro branch is under review. When it landed the v0.1 eval engine core during Macro Task 0, resolve conflicts by preserving the runtime implementation from `main` and layering governance/roadmap constraints on top.
- The roadmap must now treat Macro Task 1 as an audit/fill-gaps task over the v0.1 core, not a greenfield implementation.
- PHPStan on the v0.1 core can exhaust the default 128 MB memory limit on Windows/PHP 8.4. Use `vendor/bin/phpstan analyse --memory-limit=512M` locally and in CI.
- Copilot PR #5 caught a stale plan sentence saying README had no competitor names after `origin/main` had already added the comparison table. When main moves under an open PR, re-scan docs for statements that were true only before the merge.
