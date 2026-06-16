<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Metrics;

use Padosoft\EvalHarness\Metrics\Retrieval\AbstractRetrievalRankingMetric;
use Padosoft\EvalHarness\Metrics\Retrieval\RankedRetrieval;

/**
 * MRR: reciprocal rank (1-based) of the first relevant id over the
 * FULL ranked list; 0.0 when no relevant id is retrieved.
 *
 * MRR is rank-cutoff-independent, so `k` is intentionally ignored.
 *
 * Alias `retrieval-mrr` resolves via the container with zero extra
 * binding.
 */
final class RetrievalMrrMetric extends AbstractRetrievalRankingMetric
{
    public function name(): string
    {
        return 'retrieval-mrr';
    }

    protected function scoreRanked(RankedRetrieval $ranked, array $relevantGains, int $k): MetricScore
    {
        $rank = 0;
        foreach ($ranked->ids() as $index => $id) {
            if (isset($relevantGains[$id])) {
                $rank = $index + 1;
                break;
            }
        }

        $score = $rank > 0 ? 1.0 / $rank : 0.0;

        return new MetricScore($score, [
            'first_relevant_rank' => $rank > 0 ? $rank : null,
        ]);
    }
}
