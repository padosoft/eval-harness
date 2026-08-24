<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Costs;

use Padosoft\EvalHarness\Costs\PriceBook;
use Padosoft\EvalHarness\Tests\TestCase;

final class PriceBookTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('eval-harness.costs.models', [
            'gpt-4o-mini' => ['input_per_million' => 0.15, 'output_per_million' => 0.60],
            'gpt-4o-mini-2099-01-01' => ['input_per_million' => 99.0, 'output_per_million' => 99.0],
            'text-embedding-3-small' => ['input_per_million' => 0.02, 'output_per_million' => 0.0],
        ]);
    }

    public function test_it_prices_a_declared_model(): void
    {
        // 1M in at $0.15 + 500k out at $0.60 = 0.15 + 0.30
        $this->assertEqualsWithDelta(0.45, (float) $this->prices()->costFor('gpt-4o-mini', 1_000_000, 500_000), 1e-9);
    }

    /**
     * Providers version their models in the name and echo the dated form,
     * while everybody writes the family name in config.
     */
    public function test_a_dated_variant_falls_back_to_the_family_rate(): void
    {
        $this->assertEqualsWithDelta(
            0.15,
            (float) $this->prices()->costFor('gpt-4o-mini-2024-07-18', 1_000_000, 0),
            1e-9,
        );
    }

    public function test_an_explicitly_declared_variant_beats_the_family(): void
    {
        $this->assertEqualsWithDelta(
            99.0,
            (float) $this->prices()->costFor('gpt-4o-mini-2099-01-01', 1_000_000, 0),
            1e-9,
        );
    }

    public function test_a_vendor_prefix_is_stripped(): void
    {
        $this->assertEqualsWithDelta(
            0.15,
            (float) $this->prices()->costFor('openai/gpt-4o-mini', 1_000_000, 0),
            1e-9,
        );
    }

    /**
     * A cost report that quietly says $0.00 for a self-hosted model gets
     * believed, budgeted against, and quoted in a meeting.
     */
    public function test_an_unknown_model_is_not_priced_at_zero(): void
    {
        $this->assertNull($this->prices()->costFor('llama-3.1-70b', 1_000_000, 1_000_000));
        $this->assertFalse($this->prices()->knows('llama-3.1-70b'));
    }

    /**
     * Half a rate would silently bill one side at zero, which is the same
     * failure as guessing: cheap, plausible and wrong.
     */
    public function test_a_half_declared_rate_is_ignored_rather_than_half_applied(): void
    {
        config()->set('eval-harness.costs.models', [
            'half-priced' => ['input_per_million' => 1.0],
        ]);

        $this->assertNull($this->prices()->costFor('half-priced', 1_000_000, 1_000_000));
    }

    public function test_a_negative_rate_is_ignored(): void
    {
        config()->set('eval-harness.costs.models', [
            'negative' => ['input_per_million' => -1.0, 'output_per_million' => 1.0],
        ]);

        $this->assertNull($this->prices()->costFor('negative', 1_000_000, 0));
    }

    public function test_no_configured_rates_price_nothing(): void
    {
        config()->set('eval-harness.costs.models', []);

        $this->assertNull($this->prices()->costFor('gpt-4o-mini', 1_000, 1_000));
    }

    public function test_a_free_output_rate_still_prices_the_input(): void
    {
        $this->assertEqualsWithDelta(
            0.02,
            (float) $this->prices()->costFor('text-embedding-3-small', 1_000_000, 0),
            1e-9,
        );
    }

    private function prices(): PriceBook
    {
        /** @var PriceBook $prices */
        $prices = $this->app->make(PriceBook::class);

        return $prices;
    }
}
