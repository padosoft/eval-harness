<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Metrics;

use Padosoft\EvalHarness\Metrics\Retrieval\AbstractRetrievalRankingMetric;
use Padosoft\EvalHarness\Metrics\Retrieval\RankedRetrieval;

/**
 * nDCG@k: DCG@k / IDCG@k.
 *
 * DCG uses gain g_i (binary 1.0 or graded from the expected gain map)
 * with the standard discount 1/log2(rank + 1) over the top-k ranked
 * ids. IDCG@k is computed from the ideal ordering of the relevant
 * gains truncated to k. Score is 0.0 when IDCG@k is 0 (no relevant
 * gain reachable within k).
 *
 * Alias `retrieval-ndcg-at-k` resolves via the container with zero
 * extra binding.
 */
final class RetrievalNdcgAtKMetric extends AbstractRetrievalRankingMetric
{
    public function name(): string
    {
        return 'retrieval-ndcg-at-k';
    }

    protected function scoreRanked(RankedRetrieval $ranked, array $relevantGains, int $k): MetricScore
    {
        $topK = $ranked->topKIds($k);

        $dcg = 0.0;
        foreach ($topK as $index => $id) {
            $gain = $relevantGains[$id] ?? 0.0;
            if ($gain !== 0.0) {
                $dcg += $gain / log($index + 2, 2);
            }
        }

        $idealGains = array_values($relevantGains);
        rsort($idealGains);
        $idealGains = array_slice($idealGains, 0, max(0, $k));

        $idcg = 0.0;
        foreach ($idealGains as $index => $gain) {
            $idcg += $gain / log($index + 2, 2);
        }

        $score = $idcg > 0.0 ? max(0.0, min(1.0, $dcg / $idcg)) : 0.0;

        return new MetricScore($score, [
            'k' => $k,
            'dcg' => $dcg,
            'idcg' => $idcg,
        ]);
    }
}
