# Claude Project Instructions

Read `AGENTS.md` first. It is the canonical agent guide for this repository.

Mandatory context before implementation:

1. `docs/ROADMAP_IMPLEMENTATION_PLAN.md`
2. `docs/RULES.md`
3. `docs/PROGRESS.md`
4. `docs/LESSON.md`
5. `.claude/skills/eval-harness-plan/SKILL.md`

Core constraints:

- Laravel package only; no bundled Web UI.
- Support Laravel `^12.0|^13.0` and PHP `^8.3`.
- Queue implementation must be Horizon-ready in production and `sync`/fake-friendly in tests.
- Use the branch, PR, CI, and Copilot review loop from `AGENTS.md`.
- Update `docs/PROGRESS.md` and `docs/LESSON.md` as work proceeds.
