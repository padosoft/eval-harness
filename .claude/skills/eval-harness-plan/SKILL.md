---
name: eval-harness-plan
description: Continue or resume padosoft/eval-harness implementation. Use when working in this Laravel package, following the roadmap, enforcing Laravel 12+/PHP 8.3+ support, Horizon-ready queue design with sync tests, headless report APIs for a separate UI package, branch/PR/Copilot review loops, and progress/lesson documentation.
---

# Eval Harness Plan

## Start Here

Read these files before editing application code:

1. `AGENTS.md`
2. `docs/ROADMAP_IMPLEMENTATION_PLAN.md`
3. `docs/RULES.md`
4. `docs/PROGRESS.md`
5. `docs/LESSON.md`

## Core Rules

- Treat this as a headless Laravel package.
- Support PHP `^8.3` and Laravel `^12.0|^13.0`.
- Do not add bundled Web UI assets in this repo.
- Prepare report JSON, read-only API routes, and API resources for a separate UI package.
- Use Laravel queues for batch work; target Horizon operationally and `sync` queues/fakes in tests.
- Keep live LLM/embedding/provider calls behind fakeable interfaces.

## Procedure

1. Re-read current files and `git status` before changing anything.
2. Keep changes scoped to the current macro/subtask from `docs/ROADMAP_IMPLEMENTATION_PLAN.md`.
3. Run the relevant local gates:

```text
composer validate --strict
vendor/bin/phpunit
vendor/bin/phpstan analyse
vendor/bin/pint --test
```

4. Update `docs/PROGRESS.md`.
5. Update `docs/LESSON.md` for non-obvious setup facts, bugs, review feedback, or reusable constraints.
6. Open PRs according to `AGENTS.md`: subtask PR into macro branch, macro PR into `main`.
7. Request GitHub Copilot Code Review and wait for CI/review completion.
8. If remote review or CI cannot be completed, record the exact blocker and next required step in `docs/PROGRESS.md`.

## Copilot Review Fallback

Prefer:

```text
gh pr edit <PR> --add-reviewer @copilot
```

If that fails before requesting the review because `read:project` is missing, use the GraphQL `requestReviewsByLogin` fallback documented in `docs/RULES.md`.
