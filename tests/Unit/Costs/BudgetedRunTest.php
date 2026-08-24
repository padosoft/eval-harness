<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Costs;

use Illuminate\Support\Facades\Event;
use Padosoft\EvalHarness\Contracts\ProvidesUsageDetails;
use Padosoft\EvalHarness\Costs\Events\EvalRunCosted;
use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\EvalEngine;
use Padosoft\EvalHarness\Exceptions\EvalRunException;
use Padosoft\EvalHarness\Exceptions\MetricException;
use Padosoft\EvalHarness\Metrics\Metric;
use Padosoft\EvalHarness\Metrics\MetricScore;
use Padosoft\EvalHarness\Tests\TestCase;

final class BudgetedRunTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('eval-harness.costs.models', [
            'priced-model' => ['input_per_million' => 1.0, 'output_per_million' => 0.0],
        ]);
    }

    public function test_a_run_reports_what_it_cost(): void
    {
        $engine = $this->engineWithCostingMetric('cost.basic', rows: 3, dollarsPerRow: 0.01);

        $report = $engine->run('cost.basic', static fn (): string => 'answer');

        $this->assertNotNull($report->cost);
        $this->assertSame(3, $report->cost->calls);
        $this->assertEqualsWithDelta(0.03, $report->cost->totalUsd(), 1e-9);
        $this->assertTrue($report->cost->isComplete());
        $this->assertFalse($report->wasHalted());
    }

    /**
     * The point of the feature: a runaway suite has to stop, not send a
     * receipt. A halted run keeps the rows it scored and says it is partial.
     */
    public function test_a_run_halts_when_the_budget_is_spent(): void
    {
        $engine = $this->engineWithCostingMetric('cost.halt', rows: 10, dollarsPerRow: 0.10);

        $report = $engine->run('cost.halt', static fn (): string => 'answer', budgetUsd: 0.25);

        $this->assertTrue($report->wasHalted());
        $this->assertSame(3, $report->totalSamples(), 'stops at the row that crossed the line, keeping it');
        $this->assertEqualsWithDelta(0.30, (float) $report->budget?->spentUsd, 1e-9);
        $this->assertSame(3, (int) $report->budget?->completedRows);
        $this->assertStringContainsString('of a $0.2500 budget', (string) $report->budget?->reason);
    }

    /**
     * A stop must also prevent the *next* pipeline call — that call is the
     * money the caller said they did not have.
     */
    public function test_the_system_under_test_is_not_invoked_after_a_halt(): void
    {
        $engine = $this->engineWithCostingMetric('cost.sut', rows: 10, dollarsPerRow: 0.10);
        $invocations = 0;

        $engine->run('cost.sut', function () use (&$invocations): string {
            $invocations++;

            return 'answer';
        }, budgetUsd: 0.25);

        $this->assertSame(3, $invocations);
    }

    public function test_a_run_inside_its_budget_is_not_halted(): void
    {
        $engine = $this->engineWithCostingMetric('cost.inside', rows: 3, dollarsPerRow: 0.01);

        $report = $engine->run('cost.inside', static fn (): string => 'answer', budgetUsd: 10.0);

        $this->assertFalse($report->wasHalted());
        $this->assertEqualsWithDelta(10.0, (float) $report->budget?->limitUsd, 1e-9);
    }

    /**
     * A budget of null and a budget that held are different facts, and a
     * reader three weeks later cannot tell them apart from an absent field.
     */
    public function test_a_run_without_a_budget_still_records_the_absence_of_one(): void
    {
        $engine = $this->engineWithCostingMetric('cost.nobudget', rows: 1, dollarsPerRow: 0.01);

        $report = $engine->run('cost.nobudget', static fn (): string => 'answer');

        $this->assertNotNull($report->budget);
        $this->assertNull($report->budget->limitUsd);
        $this->assertFalse($report->budget->halted);
    }

    /**
     * One budget spans every pass, or pass three starts with a fresh wallet.
     */
    public function test_the_budget_spans_repetitions(): void
    {
        $engine = $this->engineWithCostingMetric('cost.reps', rows: 2, dollarsPerRow: 0.10);

        $report = $engine->run('cost.reps', static fn (): string => 'answer', repetitions: 5, budgetUsd: 0.35);

        $this->assertTrue($report->wasHalted());
        $this->assertSame(4, $report->totalExecutions(), 'two passes of two rows, then stop');
    }

    public function test_a_metric_that_threw_after_calling_a_provider_is_still_charged(): void
    {
        $engine = $this->engine();
        $engine->dataset('cost.throwing')
            ->withSamples([new DatasetSample(id: 's1', input: ['q' => 'x'], expectedOutput: 'y')])
            ->withMetrics([new ThrowingCostingMetric(0.42)])
            ->register();

        $report = $engine->run('cost.throwing', static fn (): string => 'answer');

        $this->assertSame(1, $report->totalFailures());
        $this->assertEqualsWithDelta(0.42, (float) $report->cost?->totalUsd(), 1e-9);
    }

    public function test_a_negative_budget_is_refused(): void
    {
        $engine = $this->engineWithCostingMetric('cost.negative', rows: 1, dollarsPerRow: 0.01);

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('positive amount in USD');

        $engine->run('cost.negative', static fn (): string => 'answer', budgetUsd: -1.0);
    }

    public function test_the_run_announces_its_cost_under_a_cost_centre(): void
    {
        Event::fake([EvalRunCosted::class]);
        $engine = $this->engineWithCostingMetric('rag.factuality', rows: 2, dollarsPerRow: 0.05);

        $engine->run('rag.factuality', static fn (): string => 'answer');

        Event::assertDispatched(EvalRunCosted::class, static function (EvalRunCosted $event): bool {
            return $event->dataset === 'rag.factuality'
                && $event->costCenter === 'eval:rag.factuality'
                && $event->rows === 2
                && abs($event->cost->totalUsd() - 0.10) < 1e-9
                && $event->halted === false;
        });
    }

    public function test_the_cost_centre_template_is_configurable(): void
    {
        Event::fake([EvalRunCosted::class]);
        config()->set('eval-harness.costs.cost_center', 'quality/{dataset}');
        $engine = $this->engineWithCostingMetric('rag.x', rows: 1, dollarsPerRow: 0.01);

        $engine->run('rag.x', static fn (): string => 'answer');

        Event::assertDispatched(
            EvalRunCosted::class,
            static fn (EvalRunCosted $event): bool => $event->costCenter === 'quality/rag.x',
        );
    }

    /**
     * A halted run is exactly the one a FinOps listener most wants to hear
     * about, so the event fires anyway and says it was halted.
     */
    public function test_a_halted_run_still_announces_its_cost(): void
    {
        Event::fake([EvalRunCosted::class]);
        $engine = $this->engineWithCostingMetric('cost.halted-event', rows: 10, dollarsPerRow: 0.10);

        $engine->run('cost.halted-event', static fn (): string => 'answer', budgetUsd: 0.15);

        Event::assertDispatched(
            EvalRunCosted::class,
            static fn (EvalRunCosted $event): bool => $event->halted === true,
        );
    }

    public function test_scoring_saved_outputs_is_budgeted_too(): void
    {
        $engine = $this->engineWithCostingMetric('cost.saved', rows: 6, dollarsPerRow: 0.10);

        $outputs = [];
        foreach (range(1, 6) as $index) {
            $outputs['s'.$index] = 'answer';
        }

        $report = $engine->scoreOutputs('cost.saved', $outputs, budgetUsd: 0.25);

        $this->assertTrue($report->wasHalted());
        $this->assertSame(3, $report->totalSamples());
    }

    public function test_the_json_report_carries_cost_and_budget(): void
    {
        $engine = $this->engineWithCostingMetric('cost.json', rows: 2, dollarsPerRow: 0.05);

        $payload = $engine->run('cost.json', static fn (): string => 'answer', budgetUsd: 1.0)->toJson();

        $this->assertEqualsWithDelta(0.1, (float) $payload['cost']['total_usd'], 1e-9);
        $this->assertTrue($payload['cost']['complete']);
        $this->assertEqualsWithDelta(1.0, (float) $payload['budget']['limit_usd'], 1e-9);
        $this->assertFalse($payload['budget']['halted']);
    }

    public function test_the_markdown_report_names_an_unpriced_total_as_a_floor(): void
    {
        $engine = $this->engine();
        $engine->dataset('cost.unpriced')
            ->withSamples([new DatasetSample(id: 's1', input: ['q' => 'x'], expectedOutput: 'answer')])
            ->withMetrics([new TokenCostingMetric('mystery-model', 1_000_000)])
            ->register();

        $markdown = $engine->run('cost.unpriced', static fn (): string => 'answer')->toMarkdown();

        $this->assertStringContainsString('This total is a floor, not a figure', $markdown);
        $this->assertStringContainsString('mystery-model', $markdown);
        $this->assertStringContainsString('unpriced', $markdown);
    }

    public function test_the_markdown_report_shouts_when_a_run_was_halted(): void
    {
        $engine = $this->engineWithCostingMetric('cost.halt-md', rows: 5, dollarsPerRow: 0.10);

        $markdown = $engine->run('cost.halt-md', static fn (): string => 'answer', budgetUsd: 0.15)->toMarkdown();

        $this->assertStringContainsString('Halted on budget', $markdown);
        $this->assertStringContainsString('partial run', $markdown);
    }

    private function engine(): EvalEngine
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);

        return $engine;
    }

    private function engineWithCostingMetric(string $dataset, int $rows, float $dollarsPerRow): EvalEngine
    {
        $engine = $this->engine();

        $samples = [];
        foreach (range(1, $rows) as $index) {
            $samples[] = new DatasetSample(id: 's'.$index, input: ['q' => 'x'], expectedOutput: 'answer');
        }

        $engine->dataset($dataset)
            ->withSamples($samples)
            ->withMetrics([new ReportedCostMetric($dollarsPerRow)])
            ->register();

        return $engine;
    }
}

/**
 * A metric whose provider bills a fixed amount per call.
 */
final class ReportedCostMetric implements Metric
{
    public function __construct(private readonly float $costUsd) {}

    public function name(): string
    {
        return 'costing';
    }

    public function score(DatasetSample $sample, string $actualOutput): MetricScore
    {
        return new MetricScore(1.0, [
            'usage' => [
                'model' => 'priced-model',
                'prompt_tokens' => 10,
                'completion_tokens' => 2,
                'cost_usd' => $this->costUsd,
            ],
        ]);
    }
}

/**
 * A metric whose provider reports tokens only, on a model name the caller
 * chooses — so a test can exercise both the priced and unpriced paths.
 */
final class TokenCostingMetric implements Metric
{
    public function __construct(
        private readonly string $model,
        private readonly int $promptTokens,
    ) {}

    public function name(): string
    {
        return 'token-costing';
    }

    public function score(DatasetSample $sample, string $actualOutput): MetricScore
    {
        return new MetricScore(1.0, [
            'usage' => ['model' => $this->model, 'prompt_tokens' => $this->promptTokens, 'completion_tokens' => 0],
        ]);
    }
}

/**
 * A metric that calls a provider and then fails: the money is spent either way.
 */
final class ThrowingCostingMetric implements Metric, ProvidesUsageDetails
{
    public function __construct(private readonly float $costUsd) {}

    public function name(): string
    {
        return 'throwing-costing';
    }

    public function score(DatasetSample $sample, string $actualOutput): MetricScore
    {
        throw new MetricException('provider returned 500 after billing us');
    }

    /**
     * @return array<string, int|float|string>
     */
    public function usageDetails(): array
    {
        return ['model' => 'priced-model', 'prompt_tokens' => 10, 'completion_tokens' => 0, 'cost_usd' => $this->costUsd];
    }
}
