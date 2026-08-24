<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Reports;

use Padosoft\EvalHarness\Statistics\WilsonInterval;

/**
 * One dataset row, across every repetition it was executed for.
 *
 * A {@see SampleResult} is a single execution. When a run repeats each row
 * (`--repetitions=N`) the row stops being a value and becomes a distribution,
 * and this is that distribution: how often it passed, how far the score moved
 * between executions, and how confident the pass rate actually is.
 *
 * The stddev is the one to read first when a suite feels unstable. A row whose
 * score is 0.9 ± 0.01 and a row whose score is 0.9 ± 0.4 have the same mean and
 * nothing else in common: the second one is a coin toss that happened to land
 * well, and it is the row that will fail the build next week for no reason
 * anybody changed.
 */
final class SampleAggregate
{
    /**
     * @param  array<string, array{mean: float, stddev: float, min: float, max: float, observations: int}>  $metrics
     * @param  array{low: float, high: float, point: float, half_width: float}  $passRateInterval
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $sampleId,
        public readonly string $rowHash,
        public readonly int $repetitions,
        public readonly int $passed,
        public readonly int $errored,
        public readonly float $passRate,
        public readonly ?float $scoreMean,
        public readonly ?float $scoreStddev,
        public readonly array $metrics,
        public readonly array $passRateInterval,
        public readonly array $metadata = [],
    ) {}

    /**
     * Is this row's outcome stable enough to be worth acting on?
     *
     * "Unstable" is not a judgement about the row's score — a row can be
     * consistently terrible and perfectly stable. It means the executions
     * disagreed with each other, which is a property of the pipeline, not of
     * the expected answer.
     */
    public function isUnstable(): bool
    {
        return $this->repetitions > 1 && $this->passed > 0 && $this->passed < $this->repetitions;
    }

    public function confidenceWidth(): float
    {
        return round($this->passRateInterval['high'] - $this->passRateInterval['low'], 6);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->sampleId,
            'row_hash' => $this->rowHash,
            'repetitions' => $this->repetitions,
            'passed' => $this->passed,
            'errored' => $this->errored,
            'pass_rate' => round($this->passRate, 6),
            'pass_rate_ci' => [
                'low' => $this->passRateInterval['low'],
                'high' => $this->passRateInterval['high'],
                'confidence' => 0.95,
            ],
            'unstable' => $this->isUnstable(),
            'score_mean' => $this->scoreMean === null ? null : round($this->scoreMean, 6),
            'score_stddev' => $this->scoreStddev === null ? null : round($this->scoreStddev, 6),
            'metrics' => $this->metrics,
        ];
    }

    /**
     * @param  list<float>  $values
     */
    public static function mean(array $values): ?float
    {
        return $values === [] ? null : array_sum($values) / count($values);
    }

    /**
     * Population standard deviation.
     *
     * Population rather than sample: these are all the executions there were,
     * not a sample drawn from a larger set of executions that also happened.
     *
     * @param  list<float>  $values
     */
    public static function stddev(array $values): ?float
    {
        $mean = self::mean($values);
        if ($mean === null) {
            return null;
        }

        $variance = 0.0;
        foreach ($values as $value) {
            $variance += ($value - $mean) ** 2;
        }

        return sqrt($variance / count($values));
    }

    /**
     * @param  array<string, array{mean: float, stddev: float, min: float, max: float, observations: int}>  $metrics
     * @param  array<string, mixed>  $metadata
     */
    public static function make(
        string $sampleId,
        string $rowHash,
        int $repetitions,
        int $passed,
        int $errored,
        array $metrics,
        ?float $scoreMean,
        ?float $scoreStddev,
        array $metadata = [],
    ): self {
        return new self(
            sampleId: $sampleId,
            rowHash: $rowHash,
            repetitions: $repetitions,
            passed: $passed,
            errored: $errored,
            passRate: $repetitions > 0 ? $passed / $repetitions : 0.0,
            scoreMean: $scoreMean,
            scoreStddev: $scoreStddev,
            metrics: $metrics,
            passRateInterval: WilsonInterval::forProportion($passed, $repetitions),
            metadata: $metadata,
        );
    }
}
