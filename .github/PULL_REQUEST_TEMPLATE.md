## Sottotask
ID: `v0.x.y.z` (example: `v0.2.0.1`; format: roadmap version, macro, subtask)
Plan ref: `docs/ROADMAP_IMPLEMENTATION_PLAN.md` sezione `Macro Task N`

## Summary
1-2 sentences description.

## Changes
- File modificati
- Migration aggiunta
- Test aggiunti

## Test gate
- [ ] PHPUnit verde (`vendor/bin/phpunit`)
- [ ] PHPStan configured level (`vendor/bin/phpstan analyse --memory-limit=512M`)
- [ ] Pint clean (`vendor/bin/pint --test`)
- [ ] Queue path coperto con `sync`/fake se il task tocca batch/job
- [ ] API feature tests verdi se il task tocca report/API routes
- [ ] Vitest verde solo in package UI separato o se vengono aggiunti asset UI
- [ ] Playwright E2E verde solo in package UI separato o se vengono aggiunti asset UI
- [ ] Eval tests verdi (se applicabile per agent/prompt changes)

## Architecture impact
[Brief: contratto package/API/report preservato; nessun coupling a host app specifica]

## Security impact
[Brief: nessun nuovo path PII, ACL rispettate]

## Risk
[Low/Medium/High + mitigation]

## Rollback plan
[Come revertire se serve]
