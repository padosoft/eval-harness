<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Costs;

/**
 * What a run cost, and what part of it nobody can price.
 *
 * ## What this covers, said plainly
 *
 * The harness can only cost what it can observe: the **grading** calls it makes
 * itself — the LLM judge, the embedding provider — plus whatever a
 * system-under-test chooses to report back. It does not see the tokens your own
 * pipeline burned answering the question unless you tell it.
 *
 * That is a narrower number than "what the eval cost", and it is deliberately
 * the more useful one. Teams already know what their pipeline costs per call;
 * almost nobody knows what their *judge* costs, because it is invisible until
 * it is a line on an invoice. An LLM-as-judge suite over a thousand rows with
 * three repetitions is three thousand model calls that exist purely to grade,
 * and it is routinely the larger half of the bill.
 *
 * ## Reported, derived, unpriced
 *
 * - **reported** — the provider billed us in the response body. Authoritative.
 * - **derived** — computed from token counts and a rate declared in config
 *   ({@see PriceBook}). An estimate, and labelled as one.
 * - **unpriced** — tokens with neither. Counted in the token totals, absent
 *   from the money, and named in {@see self::$unpricedModels} so a
 *   suspiciously small total explains itself instead of being believed.
 */
final class RunCost
{
    /**
     * @param  list<ModelCost>  $models
     * @param  list<string>  $unpricedModels
     */
    public function __construct(
        public readonly float $reportedUsd,
        public readonly float $derivedUsd,
        public readonly int $promptTokens,
        public readonly int $completionTokens,
        public readonly int $calls,
        public readonly array $models,
        public readonly array $unpricedModels,
        public readonly int $unpricedCalls,
    ) {}

    public function totalUsd(): float
    {
        return round($this->reportedUsd + $this->derivedUsd, 8);
    }

    public function totalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }

    /**
     * Whether every observed call could be turned into money.
     *
     * A gate that trusts a cost number should check this first: a total that
     * excludes half the calls is not a small bill, it is an unknown one.
     */
    public function isComplete(): bool
    {
        return $this->unpricedCalls === 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'total_usd' => round($this->totalUsd(), 6),
            'reported_usd' => round($this->reportedUsd, 6),
            'derived_usd' => round($this->derivedUsd, 6),
            'complete' => $this->isComplete(),
            'calls' => $this->calls,
            'unpriced_calls' => $this->unpricedCalls,
            'unpriced_models' => $this->unpricedModels,
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'total_tokens' => $this->totalTokens(),
            'models' => array_map(static fn (ModelCost $model): array => $model->toArray(), $this->models),
        ];
    }

    public static function empty(): self
    {
        return new self(0.0, 0.0, 0, 0, 0, [], [], 0);
    }
}
