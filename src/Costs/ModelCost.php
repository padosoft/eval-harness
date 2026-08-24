<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Costs;

/**
 * What one model cost across a run.
 *
 * `$priced` is the distinction that matters: a model with no declared rate and
 * no provider-reported cost contributes tokens and *no money*, and the run has
 * to say so rather than fold it into a total that then reads as complete.
 */
final class ModelCost
{
    public function __construct(
        public readonly string $model,
        public readonly int $calls,
        public readonly int $promptTokens,
        public readonly int $completionTokens,
        public readonly float $costUsd,
        public readonly bool $priced,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'model' => $this->model,
            'calls' => $this->calls,
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'total_tokens' => $this->promptTokens + $this->completionTokens,
            'cost_usd' => round($this->costUsd, 6),
            'priced' => $this->priced,
        ];
    }
}
