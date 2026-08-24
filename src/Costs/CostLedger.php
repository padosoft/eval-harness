<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Costs;

use Padosoft\EvalHarness\Support\ProviderUsageDetails;

/**
 * Running total of what a run has spent so far.
 *
 * Kept separate from {@see RunCost} because they answer different questions at
 * different times: the ledger is mutable and consulted *during* the run (a
 * budget cannot be enforced from a report that only exists when the run is
 * over), and the RunCost is the immutable statement produced at the end.
 *
 * Usage arrives in metric details as a flat map — `prompt_tokens`,
 * `completion_tokens`, an optional `cost_usd` the provider billed, and an
 * optional `model` — which is exactly what {@see ProviderUsageDetails}
 * extracts from an OpenAI-compatible response.
 */
final class CostLedger
{
    /** Calls whose model has no rate and no reported cost, keyed by model name. */
    private const UNKNOWN_MODEL = '(unnamed model)';

    private float $reportedUsd = 0.0;

    private float $derivedUsd = 0.0;

    private int $promptTokens = 0;

    private int $completionTokens = 0;

    private int $calls = 0;

    private int $unpricedCalls = 0;

    /** @var array<string, array{calls: int, prompt: int, completion: int, cost: float, priced: bool}> */
    private array $models = [];

    public function __construct(private readonly PriceBook $prices) {}

    /**
     * Record one provider call from a metric's usage details.
     *
     * @param  array<string, mixed>  $usage
     */
    public function record(array $usage): void
    {
        $promptTokens = $this->intOf($usage, 'prompt_tokens');
        $completionTokens = $this->intOf($usage, 'completion_tokens');
        $reported = $this->floatOf($usage, 'cost_usd');
        $model = is_string($usage['model'] ?? null) && $usage['model'] !== ''
            ? $usage['model']
            : self::UNKNOWN_MODEL;

        // Neither tokens nor money: a latency-only detail block is not a call.
        if ($promptTokens === 0 && $completionTokens === 0 && $reported === null) {
            return;
        }

        $this->calls++;
        $this->promptTokens += $promptTokens;
        $this->completionTokens += $completionTokens;

        $cost = $reported;
        $priced = $reported !== null;

        if ($cost === null) {
            $cost = $this->prices->costFor($model, $promptTokens, $completionTokens);
            $priced = $cost !== null;

            if ($cost !== null) {
                $this->derivedUsd += $cost;
            }
        } else {
            $this->reportedUsd += $cost;
        }

        if (! $priced) {
            $this->unpricedCalls++;
        }

        $entry = $this->models[$model] ?? ['calls' => 0, 'prompt' => 0, 'completion' => 0, 'cost' => 0.0, 'priced' => true];
        $entry['calls']++;
        $entry['prompt'] += $promptTokens;
        $entry['completion'] += $completionTokens;
        $entry['cost'] += $cost ?? 0.0;
        // One unpriced call makes the whole model's total a floor, not a figure.
        $entry['priced'] = $entry['priced'] && $priced;
        $this->models[$model] = $entry;
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public function recordMetricDetails(array $details): void
    {
        $usage = $details['usage'] ?? null;

        if (is_array($usage)) {
            /** @var array<string, mixed> $usage */
            $this->record($usage);
        }
    }

    public function spentUsd(): float
    {
        return round($this->reportedUsd + $this->derivedUsd, 8);
    }

    public function calls(): int
    {
        return $this->calls;
    }

    public function toRunCost(): RunCost
    {
        $models = [];
        $unpriced = [];

        foreach ($this->models as $model => $entry) {
            $models[] = new ModelCost(
                model: $model,
                calls: $entry['calls'],
                promptTokens: $entry['prompt'],
                completionTokens: $entry['completion'],
                costUsd: $entry['cost'],
                priced: $entry['priced'],
            );

            if (! $entry['priced']) {
                $unpriced[] = $model;
            }
        }

        usort($models, static fn (ModelCost $a, ModelCost $b): int => $b->costUsd <=> $a->costUsd);
        sort($unpriced);

        return new RunCost(
            reportedUsd: round($this->reportedUsd, 8),
            derivedUsd: round($this->derivedUsd, 8),
            promptTokens: $this->promptTokens,
            completionTokens: $this->completionTokens,
            calls: $this->calls,
            models: $models,
            unpricedModels: $unpriced,
            unpricedCalls: $this->unpricedCalls,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function intOf(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;

        return is_int($value) && $value >= 0 ? $value : 0;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function floatOf(array $payload, string $key): ?float
    {
        $value = $payload[$key] ?? null;

        return (is_int($value) || is_float($value)) && $value >= 0 ? (float) $value : null;
    }
}
