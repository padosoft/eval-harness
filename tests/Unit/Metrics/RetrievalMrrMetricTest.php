<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Metrics;

use Illuminate\Config\Repository;
use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Metrics\RetrievalMrrMetric;
use PHPUnit\Framework\TestCase;

final class RetrievalMrrMetricTest extends TestCase
{
    private function metric(): RetrievalMrrMetric
    {
        $config = new Repository(['eval-harness' => ['metrics' => ['retrieval' => ['default_k' => 3]]]]);

        return new RetrievalMrrMetric($config);
    }

    public function test_name_is_stable(): void
    {
        $this->assertSame('retrieval-mrr', $this->metric()->name());
    }

    public function test_first_relevant_at_rank_three(): void
    {
        $sample = new DatasetSample(id: 's', input: [], expectedOutput: ['c']);
        $this->assertEqualsWithDelta(1 / 3, $this->metric()->score($sample, '["a","b","c"]')->score, 1e-9);
    }

    public function test_first_relevant_at_rank_one(): void
    {
        $sample = new DatasetSample(id: 's', input: [], expectedOutput: ['a']);
        $this->assertSame(1.0, $this->metric()->score($sample, '["a","b","c"]')->score);
    }

    public function test_none_relevant_scores_zero(): void
    {
        $sample = new DatasetSample(id: 's', input: [], expectedOutput: ['z']);
        $this->assertSame(0.0, $this->metric()->score($sample, '["a","b","c"]')->score);
    }

    public function test_uses_first_relevant_even_with_later_relevant(): void
    {
        $sample = new DatasetSample(id: 's', input: [], expectedOutput: ['b', 'd']);
        $this->assertEqualsWithDelta(1 / 2, $this->metric()->score($sample, '["a","b","c","d"]')->score, 1e-9);
    }
}
