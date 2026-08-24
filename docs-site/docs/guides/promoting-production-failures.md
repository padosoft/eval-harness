# Promoting production failures

Golden datasets are written by the people who built the pipeline, which means
they encode what those people already thought to worry about.

The questions that actually break a system are the other ones: the phrasing no
designer would choose, the edge case that only exists in real inventory, the
follow-up that assumes context the pipeline never had. Nobody writes those at a
desk. They arrive at 11pm, on a Tuesday, from a real user.

[Online monitoring](/guides/online-monitoring) already scores those interactions
in production. This turns the ones that failed into permanent regression tests —
so tonight's incident becomes tomorrow's red build instead of an anecdote in a
retro.

```bash
php artisan eval-harness:promote-online rag.factuality \
    --merge=database/evals/rag.factuality.yaml \
    --since=7 --limit=25
```

```
12 promoted, 84 already present, 3 duplicates.
Wrote 41208 bytes of dataset to database/evals/rag.factuality.yaml
```

The diff is pure additions, and the next CI run treats them like any other row.

## First: the interaction has to be kept

The online monitor stores a **score**. That is enough to plot drift, and not
enough to build a test — for that you need the question that was asked and the
answer that was expected.

Keeping production text is a different decision, with a different legal basis,
from keeping a number. So it is a different switch, and it is off:

```php
'online' => [
    'retention' => [
        'enabled' => env('EVAL_HARNESS_ONLINE_RETENTION', false),
        'require_redactor' => env('EVAL_HARNESS_ONLINE_REQUIRE_REDACTOR', true),
        'redactor' => env('EVAL_HARNESS_ONLINE_REDACTOR'),
    ],
],
```

## The redaction seam

The row you most want is, for exactly the reason you want it, the row most
likely to contain a name, an order number, an address, or a medical detail.

So retention runs through a redactor, at the boundary, **before** the row is
written:

```php
namespace App\Eval;

use Padosoft\EvalHarness\Online\Redactor;

final class PiiRedactor implements Redactor
{
    public function redact(string $text): string
    {
        return app(\Padosoft\PiiRedactor\Redactor::class)->scrub($text);
    }
}
```

```php
'redactor' => \App\Eval\PiiRedactor::class,
```

One method, text in and text out, so a host that already owns a text redactor
plugs straight in. The package walks the input array itself and applies it to
every string at every depth — **including string keys**, because a map keyed by
email address is not a hypothetical.

### This package ships no redactor, on purpose

A redactor good enough to be trusted with production text is a package of its
own — `padosoft/laravel-pii-redactor` is one, a regex you already own is
another, a call to a classifier is a third — and which one is right depends on
the jurisdiction, the data, and the retention policy.

An eval harness shipping its own half-good PII regex would be a package quietly
promising a compliance property it cannot keep.

### Three defaults, each chosen against convenience

**Retention is off.** Somebody has to turn it on deliberately.

**A redactor is required.** With retention enabled and nothing bound, the job
**raises** rather than storing raw text. The failure this prevents is the one
that actually happens: retention gets switched on in a hurry to debug something,
the redactor binding is "next sprint", and six months of real customer questions
are sitting in a table nobody remembers agreeing to.

You can waive it — `require_redactor => false` is legitimate for an internal
corpus that provably carries no personal data — and it is spelled out so the
person waiving it is the person who decided it was safe.

**A broken redactor drops the interaction.** If the bound redactor throws, the
row is not retained, and the exception message deliberately does not include the
text it failed on. Losing a dataset row is an inconvenience; storing (or
logging) the one string the redactor choked on is an incident.

`redactor` and `redacted_at` are stored per row, because *"which redactor
handled this?"* is a question asked months later — during an audit, or after a
redactor turns out to have had a bug — and a boolean column cannot answer it.

## What gets promoted

**Failures, by default.** A dataset of rows the pipeline already got right can
only ever stay green. `--all` promotes everything, for pinning behaviour you
want to keep.

| Option | Effect |
|---|---|
| `--all` | promote passing interactions too |
| `--max-score=0.3` | only interactions scored at or below this |
| `--since=7` | only interactions judged in the last N days |
| `--limit=25` | cap the batch (default 50) |
| `--merge=<file>` | append into an existing dataset, writing back in place |
| `--out=<file>` | write elsewhere; without either, the YAML goes to stdout |
| `--metrics=exact-match,llm-as-judge` | metric aliases for the emitted dataset |
| `--name=` | dataset name in the YAML (defaults to the argument) |
| `--allow-unredacted` | promote rows retained with no redactor bound |

When a limit truncates the batch, the survivors are the **worst-scoring** rows.
A limit should keep the interactions the pipeline handled worst, not the ones
that happened to be inserted first.

## Duplicates solve themselves

A nightly promotion promotes the same recurring failure every night, so dedup is
not a nicety.

Rows are deduplicated by **content hash** — the same
[`RowHash`](/guides/baselines-and-regressions) the regression gate joins on,
taken over input and expected output. That has two consequences worth naming:

- a row already in the dataset by *any* route, including one somebody wrote by
  hand, is not promoted again;
- a promoted row **keeps its history from the moment it lands**, because it
  joins to past and future runs on the same hash the comparator uses.

The row's `id` is derived from that hash too (`online-9f2c4a1b3e7d`), not from
the database id. Promoting from two environments, or re-promoting after the
table is truncated, produces the same id — so a dataset somebody has already
committed does not get renumbered underneath them.

## Unredacted rows are skipped, loudly

A row retained while no redactor was bound is raw production text. Promoting it
copies that text into a YAML file that gets committed, and a repository is
forever.

So those rows are skipped, and the skip is **announced** rather than silent —
otherwise somebody expects a row in the dataset and concludes it never existed:

```
4 interaction(s) were retained with no redactor bound and were skipped.
Pass --allow-unredacted to promote raw production text into a committed file.
```

Scores from before retention was enabled carry no text at all, and get their own
line, so an upgraded install does not silently produce a dataset of empty
questions.

## A corrupt merge target stops the command

The promoter treats an unreadable merge target as "no existing rows" — correct
for a *missing* file, catastrophic for a corrupt one, because writing the result
back would replace a curated dataset with this run's handful of promotions.

So the command parses the target first and refuses:

```
Merge target [database/evals/rag.factuality.yaml] is not a readable dataset,
so promoting into it would replace it: Dataset YAML is not valid YAML: ...
```

## What a promoted row looks like

```yaml
- id: online-9f2c4a1b3e7d
  input:
    question: 'what is the refund window for a faulty item'
  expected_output: '30 days from delivery'
  metadata:
    tags:
      - promoted-from-production
    source: online
    online_sample_id: live-7c1a9f22
    online_score: 0.2
    online_metric: llm-as-judge
    judge_model: gpt-4o-mini
    judged_at: '2026-08-23T23:14:07+00:00'
    redactor: App\Eval\PiiRedactor
```

The metadata is not decoration. `tags` puts every promoted row in one
[cohort](/guides/running-evaluations), so a
[briefing](/guides/briefing) can say *"7 of 9 failures are tagged
`promoted-from-production`"* — which is a very specific diagnosis: the pipeline
is failing on real traffic and passing the tests somebody imagined.

## The loop, end to end

1. **Capture** — the online monitor samples production and judges it.
2. **Retain** — the interaction is redacted and stored.
3. **Promote** — the failures become dataset rows.
4. **Gate** — the next CI run scores them, and
   [`--compare=baseline`](/guides/baselines-and-regressions) stops the pull
   request that breaks one again.
5. **Brief** — when one fails, [`eval-harness:brief`](/guides/briefing) says what
   the user asked, what was expected, and what the pipeline said instead.

The dataset stops being a snapshot of what the team imagined in week one and
starts being a record of what actually went wrong.
