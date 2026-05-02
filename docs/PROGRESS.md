# Progress

## 2026-05-02

- Started Macro Task 0 on `task/governance-agent-rules` from `chore/test-count-readme-sync`.
- Confirmed no open PRs existed before starting the macro task.
- Verified current local baseline:
  - `composer validate --strict` passed.
  - `vendor/bin/phpunit` passed before the PHPStan nullability fix.
  - `vendor/bin/pint --test` passed.
  - `vendor/bin/phpstan analyse --memory-limit=512M` failed because no analysis path/config existed.
- Read the reference repo `product_image_discovery_admin` and adapted its durable workflow pattern: `AGENTS.md`, rules, lessons, progress, repo-local skill, PR/Copilot loop, and GraphQL Copilot fallback.
- Recorded user constraints in the plan: Laravel `^12|^13`, PHP `^8.3`, Horizon-ready queue execution with `sync` queues/fakes in tests, and no bundled Web UI in this package.
- Added governance files, the roadmap plan, repo-local Claude skill, Copilot instructions, PHPStan config, hard PHPStan/Pint CI gates, Laravel 12/13 Composer constraints, and normalized stale `v4.0` operational references.
- Updated CI triggers to run on `task/**` pushes and PRs so subtask PRs into macro branches receive checks before the macro PR targets `main`.
- First post-change gate pass found:
  - `composer validate --strict` blocked because the local ignored `composer.lock` was stale after Composer constraint changes.
  - `vendor/bin/phpstan analyse` caught Testbench app nullability in `ServiceProviderTest`.
- Fixed the Testbench app nullability issue and synchronized the local ignored Composer lock metadata.
- Full local gate passed after fixes:
  - `composer validate --strict`
  - `vendor/bin/phpstan analyse --memory-limit=512M`
  - `vendor/bin/pint --test`
  - `vendor/bin/phpunit` => `OK (2 tests, 3 assertions)`
