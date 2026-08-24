<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Metrics\Trajectory;

use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Exceptions\MetricException;
use Padosoft\EvalHarness\Metrics\MetricScore;
use Padosoft\EvalHarness\Trajectory\Trajectory;

/**
 * Shared plumbing for the trajectory family: find the expectation, refuse to
 * guess when the trajectory is missing, and delegate the actual judgement.
 *
 * ## Where expectations live
 *
 * Under `metadata.trajectory.*` on the sample, because a trajectory
 * expectation belongs to the row — "*this* question should have made it call
 * the order lookup" — not to the metric instance. A constructor argument is
 * accepted too, for the case where every row in a dataset shares one rule.
 *
 * ```yaml
 * - id: refund-status
 *   input: { question: "Where is my refund for order 7?" }
 *   expected_output: "Refunded on the 4th."
 *   metadata:
 *     trajectory:
 *       tools: [lookup_order, lookup_refund]
 *       forbidden_tools: [send_email]
 *       order: [lookup_order, lookup_refund]
 *       max_steps: 6
 * ```
 *
 * ## A missing trajectory is a failure, not a zero and not a pass
 *
 * If nothing recorded a trajectory for this execution, the metric raises a
 * {@see MetricException}, which the engine captures as a failure against
 * (sample, metric) and surfaces in the report. Scoring 0 would blame the agent
 * for the harness's missing wiring; scoring 1 would let a whole dataset go
 * green because nobody plugged the recorder in. Neither is honest; "this metric
 * could not do its job, here is why" is.
 */
abstract class AbstractTrajectoryMetric implements TrajectoryMetric
{
    /** Where a row keeps its trajectory expectations. */
    protected const METADATA_KEY = 'trajectory';

    public function score(DatasetSample $sample, string $actualOutput): MetricScore
    {
        return $this->scoreTrajectory($sample, $actualOutput, null);
    }

    public function scoreTrajectory(DatasetSample $sample, string $actualOutput, ?Trajectory $trajectory): MetricScore
    {
        if (! $trajectory instanceof Trajectory) {
            throw new MetricException(sprintf(
                "Metric '%s' needs a trajectory for sample '%s' and none was recorded. Have the system under test call TrajectoryRecorder::record(), or supply a `trajectory` block in the saved outputs file.",
                $this->name(),
                $sample->id,
            ));
        }

        return $this->judge($sample, $trajectory);
    }

    abstract protected function judge(DatasetSample $sample, Trajectory $trajectory): MetricScore;

    /**
     * @return array<string, mixed>
     */
    protected function expectations(DatasetSample $sample): array
    {
        $expectations = $sample->metadata[static::METADATA_KEY] ?? null;

        return is_array($expectations) ? $expectations : [];
    }

    /**
     * A list of tool names from the row, or the metric's own default.
     *
     * @param  list<string>  $fallback
     * @return list<string>
     */
    protected function expectedList(DatasetSample $sample, string $key, array $fallback = []): array
    {
        $value = $this->expectations($sample)[$key] ?? null;

        if (is_string($value)) {
            return [$value];
        }

        if (! is_array($value)) {
            return $fallback;
        }

        $names = [];
        foreach ($value as $entry) {
            if (is_string($entry) && $entry !== '') {
                $names[] = $entry;
            }
        }

        return $names === [] ? $fallback : $names;
    }

    protected function expectedInt(DatasetSample $sample, string $key, ?int $fallback): ?int
    {
        $value = $this->expectations($sample)[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return $fallback;
    }

    /**
     * Score with partial credit over a list of independent conditions.
     *
     * Two of three expected tools called is not the same failure as none of
     * them, and a metric that reports both as 0.0 throws away the only signal
     * that says which direction an agent moved between two runs.
     *
     * @param  list<bool>  $satisfied
     */
    protected function fraction(array $satisfied): float
    {
        if ($satisfied === []) {
            return 1.0;
        }

        $met = count(array_filter($satisfied));

        return round($met / count($satisfied), 6);
    }
}
