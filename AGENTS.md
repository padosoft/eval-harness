# Eval Harness Agent Guide

This repository is the headless Laravel package for `padosoft/eval-harness`.

If context is missing, read these files before editing code:

- `docs/ROADMAP_IMPLEMENTATION_PLAN.md`
- `docs/RULES.md`
- `docs/PROGRESS.md`
- `docs/LESSON.md`
- `.claude/skills/eval-harness-plan/SKILL.md`

## Operating Rules

- Treat this as a reusable Laravel package, not a host application.
- Support Laravel `^12.0|^13.0` and PHP `^8.3`.
- Keep the package headless. Do not build a bundled Web UI in this repo.
- Prepare stable JSON reports, read-only API endpoints, and route contracts so a separate UI package can consume them.
- Use Laravel queues for batch execution. Production guidance targets Laravel Horizon; unit tests must use the `sync` queue/fakes.
- Keep public contracts small, typed, and versioned: metrics, datasets, reports, commands, queue jobs, and API resources.
- Do not make network calls in unit tests. Use fake LLM, embedding, queue, and agent clients.
- Update `docs/PROGRESS.md` after meaningful implementation steps.
- Update `docs/LESSON.md` when discovering setup facts, package contracts, review feedback, or test workarounds that will save the next agent time.

## Branch And PR Loop

Use macro branches from the roadmap:

- `task/governance-agent-rules`
- `task/core-eval-contracts`
- `task/metrics-reporting`
- `task/parallel-batch-queues`
- `task/advanced-metrics`
- `task/adversarial-regression`
- `task/report-api-ui-contract`
- `task/v1-stabilization-release`

For each subtask:

1. Create a subtask branch from the active macro branch.
2. Implement the smallest coherent slice.
3. Run the relevant local gates.
4. Open a PR into the macro branch.
5. Request GitHub Copilot Code Review.
6. Wait for CI and Copilot comments.
7. Fix tests and review comments, then request a fresh Copilot review.
8. Merge only after CI is green and actionable review comments are resolved.

At the end of a macro task, open a PR from the macro branch into `main` and run the same loop.

Copilot review means GitHub Copilot Code Review through the PR Reviewers menu or:

```powershell
gh pr edit <PR> --add-reviewer copilot
```

If that fails before requesting the review because GitHub CLI needs `read:project`, use the GraphQL fallback documented in `docs/RULES.md`. Do not use `@codex review` as a substitute unless the user explicitly asks for it.

If GitHub, Copilot, or CI access is unavailable, do not fake the loop. Record the exact blocker and next remote step in `docs/PROGRESS.md`.

## Local Gates

Run these before every PR unless the task explicitly does not touch that surface:

```powershell
composer validate --strict
vendor/bin/phpunit
vendor/bin/phpstan analyse --memory-limit=512M
vendor/bin/pint --test
```

Frontend gates (`npm`, Vite, Vitest, Playwright) are not expected in this repo unless a future task intentionally adds UI assets. For API-only work, use PHP feature tests instead.

## Current Priority

Do not assume the active branch from this file. Check `docs/PROGRESS.md`,
`docs/ROADMAP_IMPLEMENTATION_PLAN.md`, and `git status --short --branch`
for the latest in-flight PR, macro branch, or next roadmap task.

If `docs/PROGRESS.md` records an open subtask or macro PR, finish that
PR/Copilot/CI loop before starting new roadmap work.

After a macro PR merges to `main`, start the next roadmap macro task from
updated `main` unless `docs/PROGRESS.md` records a newer priority.
