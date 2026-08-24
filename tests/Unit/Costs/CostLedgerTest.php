<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Costs;

use Padosoft\EvalHarness\Costs\CostLedger;
use Padosoft\EvalHarness\Costs\PriceBook;
use Padosoft\EvalHarness\Tests\TestCase;

final class CostLedgerTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('eval-harness.costs.models', [
            'gpt-4o-mini' => ['input_per_million' => 0.15, 'output_per_million' => 0.60],
        ]);
    }

    public function test_a_provider_reported_cost_is_authoritative(): void
    {
        $ledger = $this->ledger();
        // Tokens that would derive to a different number: the provider's
        // figure wins, because it is the one on the invoice.
        $ledger->record(['model' => 'gpt-4o-mini', 'prompt_tokens' => 1_000_000, 'completion_tokens' => 0, 'cost_usd' => 0.99]);

        $cost = $ledger->toRunCost();

        $this->assertEqualsWithDelta(0.99, $cost->reportedUsd, 1e-9);
        $this->assertEqualsWithDelta(0.0, $cost->derivedUsd, 1e-9);
        $this->assertTrue($cost->isComplete());
    }

    public function test_tokens_are_priced_when_the_provider_did_not_bill_us(): void
    {
        $ledger = $this->ledger();
        $ledger->record(['model' => 'gpt-4o-mini', 'prompt_tokens' => 1_000_000, 'completion_tokens' => 1_000_000]);

        $cost = $ledger->toRunCost();

        $this->assertEqualsWithDelta(0.75, $cost->derivedUsd, 1e-9);
        $this->assertEqualsWithDelta(0.0, $cost->reportedUsd, 1e-9);
        $this->assertEqualsWithDelta(0.75, $cost->totalUsd(), 1e-9);
    }

    /**
     * The total must not read as "cheap" when it is really "unknown".
     */
    public function test_an_unpriced_model_makes_the_total_incomplete_and_names_itself(): void
    {
        $ledger = $this->ledger();
        $ledger->record(['model' => 'gpt-4o-mini', 'prompt_tokens' => 1_000_000, 'completion_tokens' => 0]);
        $ledger->record(['model' => 'llama-3.1-70b', 'prompt_tokens' => 5_000_000, 'completion_tokens' => 100_000]);

        $cost = $ledger->toRunCost();

        $this->assertFalse($cost->isComplete());
        $this->assertSame(1, $cost->unpricedCalls);
        $this->assertSame(['llama-3.1-70b'], $cost->unpricedModels);
        $this->assertEqualsWithDelta(0.15, $cost->totalUsd(), 1e-9, 'unpriced tokens contribute no money');
        $this->assertSame(6_100_000, $cost->totalTokens(), 'but they do contribute tokens');
    }

    public function test_one_unpriced_call_makes_the_whole_model_a_floor(): void
    {
        config()->set('eval-harness.costs.models', []);
        $ledger = $this->ledger();
        $ledger->record(['model' => 'mystery', 'prompt_tokens' => 100, 'completion_tokens' => 10, 'cost_usd' => 0.5]);
        $ledger->record(['model' => 'mystery', 'prompt_tokens' => 100, 'completion_tokens' => 10]);

        $models = $ledger->toRunCost()->models;

        $this->assertCount(1, $models);
        $this->assertFalse($models[0]->priced);
        $this->assertSame(2, $models[0]->calls);
    }

    public function test_a_latency_only_detail_block_is_not_a_call(): void
    {
        $ledger = $this->ledger();
        $ledger->record(['latency_ms' => 412.0]);

        $this->assertSame(0, $ledger->toRunCost()->calls);
    }

    public function test_usage_without_a_model_still_counts_its_tokens(): void
    {
        $ledger = $this->ledger();
        $ledger->record(['prompt_tokens' => 500, 'completion_tokens' => 100]);

        $cost = $ledger->toRunCost();

        $this->assertSame(1, $cost->calls);
        $this->assertSame(600, $cost->totalTokens());
        $this->assertFalse($cost->isComplete());
    }

    public function test_it_reads_usage_out_of_metric_details(): void
    {
        $ledger = $this->ledger();
        $ledger->recordMetricDetails([
            'judge_reason' => 'wrong window',
            'usage' => ['model' => 'gpt-4o-mini', 'prompt_tokens' => 1_000_000, 'completion_tokens' => 0],
        ]);

        $this->assertEqualsWithDelta(0.15, $ledger->spentUsd(), 1e-9);
    }

    public function test_details_without_usage_are_a_no_op(): void
    {
        $ledger = $this->ledger();
        $ledger->recordMetricDetails(['judge_reason' => 'fine']);

        $this->assertSame(0, $ledger->calls());
        $this->assertEqualsWithDelta(0.0, $ledger->spentUsd(), 1e-9);
    }

    public function test_models_are_reported_most_expensive_first(): void
    {
        config()->set('eval-harness.costs.models', [
            'cheap' => ['input_per_million' => 0.01, 'output_per_million' => 0.01],
            'dear' => ['input_per_million' => 10.0, 'output_per_million' => 10.0],
        ]);

        $ledger = $this->ledger();
        $ledger->record(['model' => 'cheap', 'prompt_tokens' => 1_000_000, 'completion_tokens' => 0]);
        $ledger->record(['model' => 'dear', 'prompt_tokens' => 1_000_000, 'completion_tokens' => 0]);

        $models = $ledger->toRunCost()->models;

        $this->assertSame('dear', $models[0]->model);
        $this->assertSame('cheap', $models[1]->model);
    }

    private function ledger(): CostLedger
    {
        /** @var PriceBook $prices */
        $prices = $this->app->make(PriceBook::class);

        return new CostLedger($prices);
    }
}
