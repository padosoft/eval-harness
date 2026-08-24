<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Trajectory;

/**
 * How an agent got to its answer.
 *
 * ## Why this exists
 *
 * Every metric in this package until now scored a *string*: the final answer.
 * That is the right unit for a RAG pipeline, and the wrong one for an agent —
 * the final answer is the one part a broken agent can still get right by
 * accident. An agent that answers "your order ships Tuesday" without ever
 * calling the order-lookup tool has guessed, and no amount of text scoring will
 * ever say so.
 *
 * ## Why it is not tied to an SDK
 *
 * The obvious implementation is to accept whatever response object the agent
 * SDK returns. That is what the closest comparable tool does, and it means its
 * assertions exist only inside that one SDK.
 *
 * This is a plain DTO instead. It is populated from `laravel/ai`, from a custom
 * orchestrator, from MCP, from a saga engine, or from a JSON file recorded last
 * week — and the metrics that read it neither know nor care which. The adapter
 * is the part that changes per runtime; the assertions are not.
 *
 * ## Approvals
 *
 * `$pendingApprovals` and `$approvals` are here because "did the agent ask
 * before acting?" is a question an eval should be able to answer. In a stack
 * where an agent can spend money or change records, an unapproved action is a
 * compliance finding, not a UX detail — and it is a property of the trajectory,
 * invisible in the text.
 */
final class Trajectory
{
    /**
     * @param  list<ToolCall>  $toolCalls  in the order they happened
     * @param  list<string>  $approvals  identifiers of actions that passed an approval gate
     * @param  array<string, mixed>  $metadata  runtime-specific detail; never interpreted here
     */
    public function __construct(
        public readonly array $toolCalls = [],
        public readonly ?int $steps = null,
        public readonly ?string $finishReason = null,
        public readonly int $pendingApprovals = 0,
        public readonly array $approvals = [],
        public readonly array $metadata = [],
    ) {}

    /**
     * Steps taken, falling back to the number of tool calls.
     *
     * A runtime that does not report a step count has still taken at least as
     * many steps as it made calls, and a `steps-below` assertion on that floor
     * is more useful than one that silently passes for lack of data.
     */
    public function stepCount(): int
    {
        return $this->steps ?? count($this->toolCalls);
    }

    /**
     * @return list<string>
     */
    public function toolNames(): array
    {
        return array_map(static fn (ToolCall $call): string => $call->name, $this->toolCalls);
    }

    public function called(string $tool): bool
    {
        return in_array($tool, $this->toolNames(), true);
    }

    public function callCount(string $tool): int
    {
        return count(array_filter($this->toolNames(), static fn (string $name): bool => $name === $tool));
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function calledWith(string $tool, array $arguments): bool
    {
        foreach ($this->toolCalls as $call) {
            if ($call->name === $tool && $call->matchesArguments($arguments)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Did these tools happen in this relative order?
     *
     * Subsequence, not equality: an expectation of `[search, answer]` is about
     * search happening before answer, and an agent that also called a cache
     * lookup and a translator in between has still done that. Requiring an exact
     * sequence would turn every added tool into a failing eval.
     *
     * @param  list<string>  $expected
     */
    public function followedOrder(array $expected): bool
    {
        if ($expected === []) {
            return true;
        }

        $names = $this->toolNames();
        $cursor = 0;

        foreach ($names as $name) {
            if ($name === $expected[$cursor]) {
                $cursor++;

                if ($cursor === count($expected)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<ToolCall>
     */
    public function failedCalls(): array
    {
        return array_values(array_filter($this->toolCalls, static fn (ToolCall $call): bool => $call->failed()));
    }

    public function hasApproval(string $action): bool
    {
        return in_array($action, $this->approvals, true);
    }

    public function isEmpty(): bool
    {
        return $this->toolCalls === []
            && $this->steps === null
            && $this->finishReason === null
            && $this->pendingApprovals === 0
            && $this->approvals === []
            && $this->metadata === [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $rawCalls = $payload['tool_calls'] ?? $payload['tools'] ?? [];
        $calls = [];

        if (is_array($rawCalls)) {
            foreach ($rawCalls as $call) {
                if (is_string($call)) {
                    // A bare list of names is the cheapest thing a host can
                    // record, and enough for called/not-called/order.
                    $calls[] = new ToolCall(name: $call);

                    continue;
                }

                if (is_array($call)) {
                    $calls[] = ToolCall::fromArray($call);
                }
            }
        }

        $approvals = [];
        if (is_array($payload['approvals'] ?? null)) {
            foreach ($payload['approvals'] as $approval) {
                if (is_string($approval)) {
                    $approvals[] = $approval;
                }
            }
        }

        return new self(
            toolCalls: $calls,
            steps: is_int($payload['steps'] ?? null) ? $payload['steps'] : null,
            finishReason: is_string($payload['finish_reason'] ?? null) ? $payload['finish_reason'] : null,
            pendingApprovals: is_int($payload['pending_approvals'] ?? null) ? max(0, $payload['pending_approvals']) : 0,
            approvals: $approvals,
            metadata: is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'tool_calls' => array_map(static fn (ToolCall $call): array => $call->toArray(), $this->toolCalls),
            'steps' => $this->stepCount(),
            'pending_approvals' => $this->pendingApprovals,
        ];

        if ($this->finishReason !== null) {
            $payload['finish_reason'] = $this->finishReason;
        }

        if ($this->approvals !== []) {
            $payload['approvals'] = $this->approvals;
        }

        if ($this->metadata !== []) {
            $payload['metadata'] = $this->metadata;
        }

        return $payload;
    }
}
