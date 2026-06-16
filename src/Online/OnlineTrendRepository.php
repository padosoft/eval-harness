<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Online;

/**
 * Aggregates {@see OnlineScore} rows into per-day pass-rate points for
 * a dataset. Aggregation happens in SQL (count + sum) so large score
 * histories do not hydrate full models, per rule-query-optimization.
 */
final class OnlineTrendRepository
{
    /**
     * @return list<array{date: string, pass_rate: float, total: int, passed: int}>
     */
    public function trend(string $dataset, int $limit): array
    {
        $limit = max(1, min(365, $limit));

        // Aggregate aliases must not collide with the model's own
        // attribute names (e.g. `passed` is cast to boolean, which would
        // turn the SUM into 1/0). Use distinct alias names and read the
        // raw aggregate rows via the base query (plain stdClass), since
        // these rows are projections, not hydrated models.
        $rows = OnlineScore::forDataset($dataset)
            ->toBase()
            ->selectRaw('DATE(judged_at) as day_bucket, count(*) as total_count, sum(passed) as passed_count')
            ->groupBy('day_bucket')
            ->orderByDesc('day_bucket')
            ->limit($limit)
            ->get()
            ->map(static fn (\stdClass $row): array => [
                'day' => (string) $row->day_bucket,
                'total' => (int) $row->total_count,
                'passed' => (int) $row->passed_count,
            ])
            ->all();

        // Query returns newest-first for the limit; present ascending.
        $rows = array_reverse($rows);

        return array_map(static function (array $row): array {
            $total = $row['total'];
            $passed = $row['passed'];

            return [
                'date' => $row['day'],
                'pass_rate' => $total > 0 ? $passed / $total : 0.0,
                'total' => $total,
                'passed' => $passed,
            ];
        }, $rows);
    }
}
