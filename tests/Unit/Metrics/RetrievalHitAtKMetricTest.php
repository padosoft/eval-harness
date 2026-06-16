<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Metrics;

use Illuminate\Config\Repository;
use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Exceptions\MetricException;
use Padosoft\EvalHarness\Metrics\RetrievalHitAtKMetric;
use PHPUnit\Framework\TestCase;

final class RetrievalHitAtKMetricTest extends TestCase
{
    private function metric(?int $k = null): RetrievalHitAtKMetric
    {
        $config = new Repository(['eval-harness' => ['metrics' => ['retrieval' => ['default_k' => 3]]]]);

        return new RetrievalHitAtKMetric($config, $k);
    }

    public function test_name_is_stable(): void
    {
        $this->assertSame('retrieval-hit-at-k', $this->metric()->name());
    }

    public function test_hit_when_relevant_within_top_k(): void
    {
        $sample = new DatasetSample(id: 's', input: [], expectedOutput: ['b']);
        $this->assertSame(1.0, $this->metric()->score($sample, '["a","b","c"]')->score);
    }

    public function test_miss_when_relevant_absent(): void
    {
        $sample = new DatasetSample(id: 's', input: [], expectedOutput: ['z']);
        $this->assertSame(0.0, $this->metric()->score($sample, '["a","b","c"]')->score);
    }

    public function test_metadata_k_override_excludes_lower_ranks(): void
    {
        $sample = new DatasetSample(id: 's', input: [], expectedOutput: ['c'], metadata: ['k' => 2]);
        $this->assertSame(0.0, $this->metric()->score($sample, '["a","b","c"]')->score);
    }

    public function test_empty_relevant_throws(): void
    {
        $sample = new DatasetSample(id: 's', input: [], expectedOutput: []);
        $this->expectException(MetricException::class);
        $this->metric()->score($sample, '["a"]');
    }

    public function test_empty_retrieval_scores_zero(): void
    {
        $sample = new DatasetSample(id: 's', input: [], expectedOutput: ['a']);
        $this->assertSame(0.0, $this->metric()->score($sample, '[]')->score);
    }
}
