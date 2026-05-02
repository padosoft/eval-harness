# Contributing to padosoft/eval-harness

Thanks for your interest. The package follows the same workflow as
the other Padosoft Laravel packages.

## Quick start

```bash
git clone https://github.com/padosoft/eval-harness.git
cd eval-harness
composer install
vendor/bin/phpunit --testsuite Unit
vendor/bin/phpunit --testsuite Architecture
vendor/bin/pint --test
vendor/bin/phpstan analyse
```

The `Live` testsuite is opt-in. To run it:

```bash
EVAL_HARNESS_LIVE_API_KEY=sk-your-key vendor/bin/phpunit --testsuite Live
```

## Branching

- `main` is protected. Open a pull request from a feature branch.
- Branch naming: `feature/<short-description>`,
  `fix/<short-description>`, `chore/<short-description>`.
- Use `feature/Wn.x-<slug>` for v4.0 cycle work (W6.x is this
  package's scaffold-and-core milestone).

## Commit conventions

Conventional commits with optional W-prefix scope:

- `feat(W6.A): introduce metric resolver`
- `fix(W6): handle malformed judge JSON`
- `docs: README polish`
- `chore: bump phpstan to level 7`
- `test: cover percentile edge cases`
- `refactor: extract sample iteration`

## Pull requests

1. Fork the repo and create a feature branch from `main`.
2. Make your changes with tests. Architecture tests in
   `tests/Architecture/` enforce the standalone-agnostic invariant:
   if you add a string referring to AskMyDocs / sibling Padosoft
   packages in `src/`, the suite fails. Don't suppress — that rule
   is load-bearing for community adoption.
3. Run the local validation gate:
   ```bash
   vendor/bin/phpunit --testsuite Unit
   vendor/bin/phpunit --testsuite Architecture
   vendor/bin/pint --test
   vendor/bin/phpstan analyse
   ```
4. Open a PR using the provided template.
5. CI runs the same matrix on PHP 8.3 / 8.4 / 8.5 x Laravel 12 / 13.
   The PR is mergeable when CI is green AND Copilot review has zero
   outstanding must-fix comments (R36).

## Code style

- PSR-12 + Laravel preset (Pint).
- `declare(strict_types=1);` on every PHP file.
- Type hints on every parameter / property / return type. `mixed`
  only when the contract genuinely allows it.
- Constructor-promoted readonly properties for DTOs.
- Explicit cast over implicit coercion.

## Tests

- Unit tests: pure components + container-resolved Laravel services
  via `Orchestra\Testbench\TestCase`. External calls use
  `Http::fake()`.
- Architecture tests: file-system / textual invariants.
- Live tests: opt-in only; skip when API key is missing.
- Aim for 90%+ line coverage on new `src/` code.

## Reporting issues

Use GitHub Issues. Include:
- Steps to reproduce
- Expected vs actual behaviour
- PHP version + Laravel version
- Relevant logs / stack traces

## Security

Security issues go to lorenzo.padovani@padosoft.com — see
[SECURITY.md](SECURITY.md) — never to public issues.
