# Cost and budgets

An evaluation suite is the one workload where a config change costs real money
in a way nobody notices until the invoice. A thousand rows × three repetitions ×
an LLM judge is **three thousand paid calls**, all of which exist purely to
grade — and to a provider dashboard they look exactly like production traffic.
Same key, same model, same endpoint.

So the honest answer to *"how much are we spending on quality?"* is usually
*"we cannot tell"*, and the honest answer to *"what stops a runaway nightly
run?"* is usually *"the invoice, six weeks later"*.

This page is about both.

## What the harness can cost, said plainly

The harness costs what it can **observe**:

- the **LLM judge** calls it makes to grade answers;
- the **embedding** calls behind `cosine-embedding` and `bertscore-like`;
- whatever a system-under-test chooses to report back through metric details.

It does **not** see the tokens your own pipeline burned answering the question,
unless you tell it. That is a narrower number than "what the eval cost", and
deliberately the more useful one: teams already know what their pipeline costs
per call. Almost nobody knows what their *judge* costs, because until now it was
invisible.

## Reported, derived, unpriced

Every observed call lands in one of three buckets, and the report says which:

| Bucket | Means | Trust |
|---|---|---|
| **reported** | the provider billed us in the response body (`usage.cost_usd`) | authoritative |
| **derived** | computed from token counts and a rate you declared | an estimate, labelled as one |
| **unpriced** | tokens with neither | **counted in tokens, absent from the money** |

The third row is the one that matters. A cost report that quietly prints
`$0.00` for a self-hosted or newly released model gets believed, budgeted
against, and quoted in a meeting. So an unpriced call is named:

> **This total is a floor, not a figure.** 3 calls used a model with no declared
> rate and no provider-reported cost (`llama-3.1-70b`). Declare rates under
> `eval-harness.costs.models` to price them.

`cost.complete` in the JSON report is the machine-readable form of that
sentence. Anything that gates on a cost number should read it first: a total
that excludes half the calls is not a small bill, it is an unknown one.

## Declaring rates

```php
// config/eval-harness.php
'costs' => [
    'cost_center' => env('EVAL_HARNESS_COST_CENTER', 'eval:{dataset}'),

    'models' => [
        'gpt-4o-mini' => ['input_per_million' => 0.15, 'output_per_million' => 0.60],
        'text-embedding-3-small' => ['input_per_million' => 0.02, 'output_per_million' => 0.0],
    ],
],
```

**No rates ship by default.** Provider prices change, and a stale rate baked
into a package is a wrong number delivered with a straight face. Declare the
models you actually use.

Names match by **longest prefix**, after stripping a `vendor/` prefix:

- `gpt-4o-mini` covers `gpt-4o-mini-2024-07-18` (providers echo the dated form)
- `gpt-4o-mini` covers `openai/gpt-4o-mini` (OpenRouter-style ids)
- declaring `gpt-4o-mini-2024-07-18` explicitly still wins, because it is longer

A **half-declared** rate — input but no output — is ignored rather than half
applied. Billing one side at zero is the same failure as guessing: cheap,
plausible, and wrong.

## A budget that actually stops the run

```bash
php artisan eval-harness:run rag.factuality --repetitions=3 --budget-usd=2.50
```

Once observable spend passes the limit, the run **halts**: it stops at the row
that crossed the line, keeps every row it had already scored, and does not
invoke the pipeline again — that next call is precisely the money you said you
did not have.

```
⛔ Halted on budget. Spent $2.5400 of a $2.5000 budget after 148 rows.
```

### A halted run always exits non-zero

This is the part that matters more than the halting.

A halted run is **incomplete data**, and incomplete data that exits zero is the
worst outcome a CI gate can produce: the rows that would have failed are exactly
the ones that never ran. So the command exits `1` even when nothing failed, and
the report carries the halt in every format:

```json
"budget": {
  "limit_usd": 2.5,
  "spent_usd": 2.54,
  "halted": true,
  "completed_rows": 148,
  "reason": "Spent $2.5400 of a $2.5000 budget after 148 rows."
}
```

Green must mean *"everything ran and everything passed"*, never *"we ran out of
money before we got to the bad rows"*.

### The budget spans repetitions

One budget covers the whole run, not one per pass. A halt in pass two ends the
run instead of starting pass three with a fresh wallet.

### It also caps `--outputs`

Scoring saved outputs invokes no pipeline — but grading is not free, and for an
LLM-as-judge suite over saved outputs the grading bill *is* the bill:

```bash
php artisan eval-harness:run rag.factuality --outputs=outputs.json --budget-usd=1.00
```

### The one thing a budget cannot bound

An **unpriced** model contributes no money, so a budget over an unpriced model
does not bind. That is the honest behaviour — the alternative is inventing a
number and then stopping a run because of it — and `cost.complete` is how a
caller finds out. If you rely on budgets, declare your rates.

## The FinOps seam

Every run dispatches `EvalRunCosted`, whether or not anything is listening:

```php
namespace App\Providers;

use Padosoft\EvalHarness\Costs\Events\EvalRunCosted;
use Illuminate\Support\Facades\Event;

Event::listen(function (EvalRunCosted $event): void {
    // $event->costCenter  → "eval:rag.factuality"
    // $event->cost        → RunCost: totals, per-model breakdown, unpriced list
    // $event->rows        → dataset rows
    // $event->executions  → rows × repetitions
    // $event->halted      → true when a budget stopped the run

    Ledger::charge($event->costCenter, $event->cost->totalUsd(), [
        'complete' => $event->cost->isComplete(),
    ]);
});
```

`padosoft/laravel-ai-finops` subscribes to exactly this shape — but so can a
home-grown ledger, a Grafana exporter, or a Slack notifier. **Neither package
depends on the other**, which is the whole reason this is an event and not an
integration.

The cost centre defaults to `eval:{dataset}` and is configurable. Per-dataset is
the right granularity, because per-dataset is where the decision lives: a
nightly thousand-row judge run over `rag.factuality` either is or is not worth
what it catches, and you cannot have that conversation without the number.

A **halted** run still fires the event, and says so. It is precisely the run a
FinOps listener most wants to hear about.

## In the report

Markdown:

```markdown
## Cost

**$0.4212** across 300 provider calls (1,204,880 tokens). Derived from configured token rates.

| model | calls | prompt tokens | completion tokens | cost USD |
| --- | --- | --- | --- | --- |
| gpt-4o-mini | 300 | 1,198,000 | 6,880 | 0.421200 |

_Budget: $0.4212 of $2.5000 spent._
```

JSON (`cost` and `budget`, both additive to `eval-harness.report.v1`):

```json
"cost": {
  "total_usd": 0.4212,
  "reported_usd": 0.0,
  "derived_usd": 0.4212,
  "complete": true,
  "calls": 300,
  "unpriced_calls": 0,
  "unpriced_models": [],
  "prompt_tokens": 1198000,
  "completion_tokens": 6880,
  "total_tokens": 1204880,
  "models": [
    { "model": "gpt-4o-mini", "calls": 300, "prompt_tokens": 1198000,
      "completion_tokens": 6880, "total_tokens": 1204880,
      "cost_usd": 0.4212, "priced": true }
  ]
}
```

## A note on what "cheap" costs

The reason this feature exists at all: the same repeated sampling that makes
[precision](/guides/repeated-sampling) honest also multiplies the bill by the
repetition count. Those two facts belong on the same page as each other, and a
package that gives you the first without the second has handed you a way to
spend three times as much without telling you.
