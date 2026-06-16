<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Metrics;

use Padosoft\EvalHarness\Metrics\AnswerContainmentAtKMetric;
use Padosoft\EvalHarness\Metrics\MetricResolver;
use Padosoft\EvalHarness\Metrics\RetrievalHitAtKMetric;
use Padosoft\EvalHarness\Metrics\RetrievalMrrMetric;
use Padosoft\EvalHarness\Metrics\RetrievalNdcgAtKMetric;
use Padosoft\EvalHarness\Metrics\RetrievalRecallAtKMetric;
use Padosoft\EvalHarness\Tests\TestCase;

final class RetrievalAliasResolutionTest extends TestCase
{
    public function test_retrieval_aliases_resolve_via_container(): void
    {
        $resolver = new MetricResolver($this->app);

        $cases = [
            'retrieval-hit-at-k' => [RetrievalHitAtKMetric::class, 'retrieval-hit-at-k'],
            'retrieval-recall-at-k' => [RetrievalRecallAtKMetric::class, 'retrieval-recall-at-k'],
            'retrieval-mrr' => [RetrievalMrrMetric::class, 'retrieval-mrr'],
            'retrieval-ndcg-at-k' => [RetrievalNdcgAtKMetric::class, 'retrieval-ndcg-at-k'],
            'answer-containment-at-k' => [AnswerContainmentAtKMetric::class, 'answer-containment-at-k'],
        ];

        foreach ($cases as $alias => [$class, $name]) {
            $metric = $resolver->resolve($alias);
            $this->assertInstanceOf($class, $metric);
            $this->assertSame($name, $metric->name());
        }
    }
}
