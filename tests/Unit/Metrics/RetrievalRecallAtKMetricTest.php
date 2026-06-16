<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Metrics;

use Illuminate\Config\Repository;
use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Metrics\RetrievalRecallAtKMetric;
use PHPUnit\Framework\TestCase;

final class RetrievalRecallAtKMetricTest extends TestCase
{
    private function metric(?int $k = null): RetrievalRecallAtKMetric
    {
        $config = new Repository(['eval-harness' => ['metrics' => ['retrieval' => ['default_k' => 4]]]]);

        return new RetrievalRecallAtKMetric($config, $k);
    }

    public function test_name_is_stable(): void
    {
        $this->assertSame('retrieval-recall-at-k', $this->metric()->name());
    }

    public function test_partial_recall(): void
    {
        $sample = new DatasetSample(id: 's', input: [], expectedOutput: ['a', 'c', 'z']);
        $score = $this->metric()->score($sample, '["a","b","c","d"]')->score;
        $this->assertEqualsWithDelta(2 / 3, $score, 1e-9);
    }

    public function test_full_recall(): void
    {
        $sample = new DatasetSample(id: 's', input: [], expectedOutput: ['a', 'b']);
        $this->assertSame(1.0, $this->metric()->score($sample, '["a","b"]')->score);
    }

    public function test_empty_retrieval_scores_zero(): void
    {
        $sample = new DatasetSample(id: 's', input: [], expectedOutput: ['a']);
        $this->assertSame(0.0, $this->metric()->score($sample, '[]')->score);
    }
}
