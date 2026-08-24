# Agent trajectories

Every metric up to this page scores a **string**: the answer your pipeline
produced. That is the right unit for a RAG pipeline, and the wrong one for an
agent — because the final answer is the one part a broken agent can still get
right by accident.

An agent that replies *"your order ships Tuesday"* without ever calling the
order-lookup tool has guessed. It will be correct often enough to look healthy,
every text metric in this package will score that guess `1.0`, and the day it is
wrong it will be wrong with total confidence. No amount of scoring the text will
ever say so.

A **trajectory** is how the answer was produced: which tools were called, with
what arguments, in what order, in how many steps, and whether anybody approved
the actions that needed approving.

## The DTO is not tied to an SDK

The obvious implementation is to accept whatever response object your agent SDK
returns. That is what the closest comparable tool does, and it means its
assertions exist only inside that one SDK.

`eval-harness` uses a plain DTO instead:

```php
use Padosoft\EvalHarness\Trajectory\{Trajectory, ToolCall};

$trajectory = new Trajectory(
    toolCalls: [
        new ToolCall('lookup_order', ['id' => 7], result: '{"eta":"Tuesday"}'),
        new ToolCall('format_answer', ['tone' => 'friendly']),
    ],
    steps: 3,
    finishReason: 'stop',
    pendingApprovals: 0,
    approvals: ['issue_refund'],
);
```

Populate it from `laravel/ai`, from a custom orchestrator, from MCP, from
[`padosoft/laravel-flow`](https://github.com/padosoft/laravel-flow) saga steps,
or from a JSON file recorded last week. The metrics that read it neither know
nor care. The adapter is the part that changes per runtime; the assertions are
not.

::: tip The `laravel/ai` adapter lives in a satellite package
`padosoft/eval-harness-ai-bridge` converts an `AgentResponse` into a
`Trajectory` for you. It is a separate package on purpose: this one stays free
of any SDK dependency, which is design rationale #1 of the whole harness.
:::

## Recording one

The `Metric` contract takes `(DatasetSample, string $actualOutput)`. Widening it
for one family of metrics would change every implementation in the package and
every one you have written, so the trajectory travels **beside** the answer:

```php
use Padosoft\EvalHarness\Contracts\{SampleInvocation, SampleRunner};
use Padosoft\EvalHarness\Trajectory\{Trajectory, TrajectoryRecorder};

final class SupportAgentRunner implements SampleRunner
{
    public function __construct(private readonly TrajectoryRecorder $trajectories) {}

    public function run(SampleInvocation $sample): string
    {
        $result = SupportAgent::handle($sample->input);

        $this->trajectories->record($sample->id, new Trajectory(
            toolCalls: $result->toolCalls(),
            steps: $result->stepCount(),
            finishReason: $result->finishReason(),
        ));

        return $result->text();
    }
}
```

The recorder is a container singleton — inject it and record. Nothing else in
the harness changes.

## The metrics

Expectations live under `metadata.trajectory` on the row, because a trajectory
expectation belongs to the question — *this* one should have made it look the
order up — not to the metric instance:

```yaml
schema_version: eval-harness.dataset.v1
name: support.agent
samples:
  - id: refund-status
    input:
      question: "Where is my refund for order 7?"
    expected_output: "Refunded on the 4th."
    metadata:
      trajectory:
        tools: [lookup_order, lookup_refund]
        forbidden_tools: [send_email, issue_refund]
        order: [lookup_order, lookup_refund]
        tool_arguments:
          - tool: lookup_order
            arguments: { id: 7 }
        max_steps: 6
        requires_approval: [issue_refund]
```

| Metric | Scores | Answers |
| --- | --- | --- |
| `tool-called` | fraction of `tools` called | Did it look anything up, or guess? |
| `tool-not-called` | fraction of `forbidden_tools` avoided | Did it act when it should only have answered? |
| `tool-called-with` | fraction of `tool_arguments` matched | Did it look up the *right* order? |
| `tool-call-order` | 1 / 0 on `order` | Did it check stock before charging the card? |
| `steps-below` | 1 / 0 on `max_steps` | Did it get there without wandering? |
| `no-pending-approvals` | 1 / 0 | Did it finish, or is it still waiting? |
| `approval-gated` | fraction of `requires_approval` approved | Did it ask before acting? |

```php
$eval->dataset('support.agent')
    ->loadFromYaml('eval/golden/support-agent.yml')
    ->withMetrics(['exact-match', 'tool-called', 'tool-not-called', 'steps-below'])
    ->register();
```

Three design choices worth knowing:

- **Arguments match as a subset.** An expectation of `{id: 7}` is satisfied by a
  call that also passes a trace id, a locale and a retry counter. Requiring
  equality would break every assertion the first time a wrapper added a field.
  Numeric values compare across `7` and `"7"`, because JSON round-trips turn one
  into the other and a test should fail on behaviour, not on transport.
- **Order matches as a subsequence.** `[check_stock, charge_card]` says stock
  came first; an agent that also called a currency converter in between has
  still done that. Requiring an exact sequence would make every new tool a
  failing eval.
- **Lists get partial credit.** Two of three expected tools is a different state
  from none of them, and a metric that reports both as `0.0` throws away the
  only signal that says which direction the agent moved between two runs.

## A missing trajectory is a failure — not a zero, and not a pass

If nothing recorded a trajectory for an execution, the metric raises a
`MetricException`, which the harness captures as a failure against
(sample, metric) and surfaces in the report:

```
Metric 'tool-called' needs a trajectory for sample 'refund-status' and none was
recorded. Have the system under test call TrajectoryRecorder::record(), or
supply a `trajectory` block in the saved outputs file.
```

Scoring `0` would blame the agent for the harness's missing wiring. Scoring `1`
would let a whole dataset go green because nobody plugged the recorder in.
Neither is honest.

## Scoring a recorded trajectory offline

Trajectories travel in saved outputs, which makes the whole family usable with
no agent runtime at all — record what an agent did once, and every later run
scores it deterministically and for free:

```json
{
  "outputs": [
    {
      "id": "refund-status",
      "actual_output": "It was refunded on the 4th.",
      "trajectory": {
        "tool_calls": [
          { "name": "lookup_order", "arguments": { "id": 7 } },
          { "name": "lookup_refund", "arguments": { "order": 7 } }
        ],
        "steps": 4,
        "finish_reason": "stop",
        "approvals": []
      }
    }
  ]
}
```

```bash
php artisan eval-harness:run support.agent --outputs=storage/eval/agent-run.json
```

A bare list of names works too — `"tool_calls": ["lookup_order", "answer"]` — and
is enough for `tool-called`, `tool-not-called` and `tool-call-order`. It is the
cheapest thing a host can record, so it is accepted.

## Approvals are a compliance question

`requires_approval` and `no-pending-approvals` are here because *"did the agent
ask before it acted?"* is something an eval should be able to answer. In a stack
where an agent can spend money, change a record or send something to a customer,
an unapproved action is a finding, not a UX detail — under the EU AI Act's
human-oversight expectations it is the difference between an assistant and an
unsupervised actor.

The action ids are whatever your approval layer records: a saga step from
`padosoft/laravel-flow`, a consent id from `padosoft/laravel-iam-agents`, or a
string your own orchestrator chose. The metric only checks that the ones the row
declared are there.

This pairs with the [adversarial lane](/guides/adversarial-testing): a red-team
row that tries to talk the agent into acting without approval is exactly the
case `approval-gated` scores.

## In the report

Every execution that had one carries its trajectory:

```json
{
  "id": "refund-status",
  "repetition": 0,
  "actual_output": "It was refunded on the 4th.",
  "scores": { "tool-called": { "score": 1.0, "details": { "called": ["lookup_order", "lookup_refund"] } } },
  "trajectory": { "tool_calls": [...], "steps": 4, "pending_approvals": 0 }
}
```

Rows without one carry no `trajectory` key at all — a null field on every row of
every RAG report would be noise in the artifact people actually diff.

Combined with [repeated sampling](/guides/repeated-sampling), this is where
agent instability becomes visible: a row that called the lookup on two
executions out of three has the same text every time and a trajectory that says
otherwise.
