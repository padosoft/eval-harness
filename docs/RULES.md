# Project Rules

## Source Of Truth

- Durable roadmap: `docs/ROADMAP_IMPLEMENTATION_PLAN.md`.
- Agent handoff: `AGENTS.md`.
- Session progress: `docs/PROGRESS.md`.
- Reusable discoveries: `docs/LESSON.md`.
- Repo-local workflow skill: `.claude/skills/eval-harness-plan/SKILL.md`.

## Package Defaults

- This is a headless Laravel package.
- Supported PHP: `^8.3`.
- Supported Laravel components: `^12.0|^13.0`.
- Do not support Laravel 11 in new roadmap work.
- Do not bundle a Web UI in this repo.
- Prepare JSON report contracts, read-only API routes, and API resources for a separate UI package.
- Queue execution must work with Laravel queues and be operationally compatible with Laravel Horizon.
- Unit and feature tests must use `sync` queues, queue fakes, or deterministic in-process runners.
- Keep external LLM/embedding/provider calls behind interfaces and fakes.

## Security Rules

- Do not write secrets, raw API keys, authorization headers, tokens, prompts containing sensitive data, or raw provider payloads to reports unless explicitly redacted.
- API responses for reports must be read-only and must validate paths/identifiers to prevent traversal.
- Adversarial datasets must be opt-in and clearly labeled.
- LLM-as-judge outputs must be schema-validated before being trusted.

## Testing Rules

Every completed backend/package slice should run:

```text
composer validate --strict
vendor/bin/phpunit
vendor/bin/phpstan analyse --memory-limit=512M
vendor/bin/pint --test
```

- Add command/feature tests for every Artisan command.
- Add unit tests for every metric, DTO/value object, serializer, dataset parser, and regression gate.
- Use queue fakes or `sync` queue in tests; do not require Horizon in tests.
- Do not make live model, embedding, network, or GitHub calls in unit tests.
- If a task adds a Web UI in a separate package, that package must add Vite/Vitest/Playwright gates. This package should stay API-tested unless UI assets are intentionally introduced.

If a tool is unavailable, blocked, or remote-only, record the exact blocker in `docs/PROGRESS.md`.

## Documentation Rules

- Update `docs/PROGRESS.md` after meaningful work, with date `YYYY-MM-DD`.
- Update `docs/LESSON.md` after non-obvious discoveries, Copilot feedback, setup fixes, or reusable implementation constraints.
- Keep README claims aligned with implemented behavior. Until features exist, label them as planned.
- Before any PR that changes tests or assertion counts, run the existing `.claude/skills/test-count-readme-sync` workflow.

## Review Rules

- Open subtask PRs into the active macro branch.
- Open macro PRs into `main`.
- Request GitHub Copilot Code Review through the PR Reviewers menu or:

```text
gh pr edit <PR> --add-reviewer copilot
```

- If `gh pr edit` fails before requesting Copilot because of missing `read:project`, resolve the PR node ID:

```text
gh pr view <PR> --json id
```

Then request the Copilot bot:

```powershell
$query = @'
mutation RequestReviewsByLogin($pullRequestId: ID!, $botLogins: [String!], $union: Boolean!) {
  requestReviewsByLogin(input: {pullRequestId: $pullRequestId, botLogins: $botLogins, union: $union}) {
    clientMutationId
  }
}
'@
gh api graphql -f query="$query" -F pullRequestId='<PR_NODE_ID>' -F botLogins[]='copilot-pull-request-reviewer[bot]' -F union=true
```

Verify:

```text
gh api repos/$(gh repo view --json nameWithOwner --jq .nameWithOwner)/pulls/<PR>/requested_reviewers
```

- Do not treat `@codex review` or REST `reviewers[]=copilot` as equivalent to a visible Copilot Code Review request.

## Competitor Awareness

Use competitor research to shape the roadmap, not to copy APIs blindly. Track parity and useful differentiators against:

- Promptfoo: declarative assertions, tags, standalone output assertions, red-team breadth.
- DeepEval: test cases, datasets, metrics, traces, end-to-end and component-level evals.
- Ragas: metric runtime settings, LLM/embedding abstraction, token/cost parsing, exception isolation.
- LangSmith: dataset versioning, filtering/splits, export formats, trace-to-dataset workflows.

Preferred differentiator: Laravel-native evals with Artisan, queues, Horizon-ready batch execution, JSON/API contracts for a separate UI package, and CI-friendly regression gates.
