<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Metrics;

use Illuminate\Config\Repository;
use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Metrics\RetrievalNdcgAtKMetric;
use PHPUnit\Framework\TestCase;

final class RetrievalNdcgAtKMetricTest extends TestCase
{
    private function metric(): RetrievalNdcgAtKMetric
    {
        $config = new Repository(['eval-harness' => ['metrics' => ['retrieval' => ['default_k' => 3]]]]);

        return new RetrievalNdcgAtKMetric($config);
    }

    public function test_name_is_stable(): void
    {
        $this->assertSame('retrieval-ndcg-at-k', $this->metric()->name());
    }

    public function test_binary_relevance(): void
    {
        // DCG = 1/log2(2) + 1/log2(4) = 1.5; IDCG = 1/log2(2) + 1/log2(3) = 1.6309
        $sample = new DatasetSample(id: 's', input: [], expectedOutput: ['a', 'c']);
        $score = $this->metric()->score($sample, '["a","b","c"]')->score;
        $this->assertEqualsWithDelta(1.5 / (1 + 1 / log(3, 2)), $score, 1e-9);
    }

    public function test_graded_relevance(): void
    {
        // DCG = 3/log2(2) + 1/log2(4) = 3.5; IDCG = 3/log2(2) + 1/log2(3)
        $sample = new DatasetSample(id: 's', input: [], expectedOutput: ['a' => 3, 'c' => 1]);
        $score = $this->metric()->score($sample, '["a","b","c"]')->score;
        $this->assertEqualsWithDelta(3.5 / (3 + 1 / log(3, 2)), $score, 1e-9);
    }

    public function test_perfect_ranking_scores_one(): void
    {
        $sample = new DatasetSample(id: 's', input: [], expectedOutput: ['a', 'b']);
        $this->assertEqualsWithDelta(1.0, $this->metric()->score($sample, '["a","b","c"]')->score, 1e-9);
    }

    public function test_no_relevant_retrieved_scores_zero(): void
    {
        $sample = new DatasetSample(id: 's', input: [], expectedOutput: ['z']);
        $this->assertSame(0.0, $this->metric()->score($sample, '["a","b","c"]')->score);
    }
}
