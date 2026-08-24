<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Costs;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * What a call cost, when the provider did not say.
 *
 * Some APIs bill you in the response body (`usage.cost_usd`) and most do not:
 * they hand back token counts and leave the arithmetic to the invoice, six
 * weeks later, aggregated across everything. That is exactly the wrong shape
 * for an evaluation suite, where the question is "what did *this dataset* cost
 * me last night, and is it worth what it caught?".
 *
 * The price book turns tokens into money using rates the host declares in
 * config, per million tokens, split input/output because every provider prices
 * them differently.
 *
 * ## An unknown model is never priced by guessing
 *
 * `costFor()` returns null for a model with no declared rate, and the run
 * reports it as **unpriced** rather than as zero. A cost report that quietly
 * says `$0.00` for a self-hosted or newly-released model is worse than one
 * that admits it does not know: the first gets believed, budgeted against, and
 * quoted in a meeting.
 *
 * ## Model names are matched by longest prefix
 *
 * Providers version their models in the name — `gpt-4o-mini-2024-07-18` — and
 * echo the dated form in the response while everybody writes the family name
 * in config. So a lookup falls back to the longest declared name that prefixes
 * the reported one, and an optional `vendor/` prefix is stripped first
 * (OpenRouter-style ids). Declaring the dated name still wins over the family,
 * because it is longer.
 */
final class PriceBook
{
    private const TOKENS_PER_UNIT = 1_000_000;

    public function __construct(private readonly ConfigRepository $config) {}

    /**
     * The cost in USD of one call, or null when the model has no declared rate.
     */
    public function costFor(string $model, int $promptTokens, int $completionTokens): ?float
    {
        $rate = $this->rateFor($model);

        if ($rate === null) {
            return null;
        }

        $cost = ($promptTokens * $rate['input_per_million'] + $completionTokens * $rate['output_per_million'])
            / self::TOKENS_PER_UNIT;

        return round($cost, 8);
    }

    public function knows(string $model): bool
    {
        return $this->rateFor($model) !== null;
    }

    /**
     * @return array{input_per_million: float, output_per_million: float}|null
     */
    public function rateFor(string $model): ?array
    {
        $needle = $this->normalise($model);

        if ($needle === '') {
            return null;
        }

        $best = null;
        $bestLength = -1;

        foreach ($this->rates() as $declared => $rate) {
            $candidate = $this->normalise((string) $declared);

            if ($candidate === '' || ! str_starts_with($needle, $candidate)) {
                continue;
            }

            if (strlen($candidate) > $bestLength) {
                $best = $rate;
                $bestLength = strlen($candidate);
            }
        }

        return $best;
    }

    /**
     * @return array<string, array{input_per_million: float, output_per_million: float}>
     */
    private function rates(): array
    {
        $configured = $this->config->get('eval-harness.costs.models', []);

        if (! is_array($configured)) {
            return [];
        }

        $rates = [];

        foreach ($configured as $model => $rate) {
            if (! is_array($rate)) {
                continue;
            }

            $input = $this->nonNegativeFloat($rate['input_per_million'] ?? null);
            $output = $this->nonNegativeFloat($rate['output_per_million'] ?? null);

            // A half-declared rate would silently bill one side at zero, which
            // is the same failure as guessing: cheap, plausible, and wrong.
            if ($input === null || $output === null) {
                continue;
            }

            $rates[(string) $model] = ['input_per_million' => $input, 'output_per_million' => $output];
        }

        return $rates;
    }

    private function normalise(string $model): string
    {
        $model = strtolower(trim($model));

        // OpenRouter and friends prefix the vendor: `openai/gpt-4o-mini`.
        $slash = strrpos($model, '/');

        return $slash === false ? $model : substr($model, $slash + 1);
    }

    private function nonNegativeFloat(mixed $value): ?float
    {
        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $float = (float) $value;

        return $float >= 0.0 && ! is_nan($float) && ! is_infinite($float) ? $float : null;
    }
}
