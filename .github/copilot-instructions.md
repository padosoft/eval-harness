# Copilot Instructions

This repository is a headless Laravel package for AI evals.

- Support PHP `^8.3` and Laravel `^12.0|^13.0`.
- Do not add Laravel 11 support in new work.
- Keep Web UI out of this package; expose JSON/report APIs for a separate UI package.
- Queue-backed evals should be compatible with Laravel Horizon in production and use `sync` queues/fakes in tests.
- Avoid live LLM, embedding, network, or GitHub calls in unit tests.
- Keep reports versioned, deterministic, and redacted by default.
- Require tests for commands, metrics, dataset parsing, report serialization, queue jobs, and regression gates.
- Follow `docs/RULES.md` and update `docs/PROGRESS.md` / `docs/LESSON.md` when review feedback changes implementation rules.
