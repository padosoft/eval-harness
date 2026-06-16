<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Metrics;

use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Exceptions\MetricException;

/**
 * Distance-aware metric for ordered categorical labels
 * (e.g. low < medium < high < urgent).
 *
 * Score by ordinal distance between expected and actual:
 *   - distance 0 (exact)      => 1.0
 *   - distance 1 (off-by-one) => 0.5
 *   - distance >= 2 / unknown => 0.0
 *
 * The ordered scale is a zero-based list passed to the constructor.
 * A per-sample `metadata.ordinal_scale` (non-empty list of unique
 * strings) overrides the constructor scale for that sample.
 *
 * Dataset-level averaging and the pass threshold are applied by the
 * report/regression layer; this metric only emits per-sample scores.
 *
 * Sample contract: `expected_output` MUST be a string present in the
 * active scale. An `actual` value absent from the scale scores 0.0.
 *
 * Alias note: because the scale is required and has no usable default,
 * the `ordinal-distance` alias resolves through {@see MetricResolver}
 * only when the consumer binds an instance (e.g.
 * `withMetrics([new OrdinalDistanceMetric([...])])` or a container
 * binding under the alias). A bare `make('ordinal-distance')` throws,
 * by design.
 */
final class OrdinalDistanceMetric implements Metric
{
    /**
     * @param  list<string>  $scale  ordered labels, low -> high
     */
    public function __construct(private readonly array $scale = [])
    {
        if (! $this->isValidScale($this->scale)) {
            throw new MetricException('Ordinal scale must be a non-empty list of unique strings.');
        }
    }

    public function name(): string
    {
        return 'ordinal-distance';
    }

    public function score(DatasetSample $sample, string $actualOutput): MetricScore
    {
        if (! is_string($sample->expectedOutput)) {
            throw new MetricException(sprintf(
                "Sample '%s' expected_output must be a string for ordinal-distance metric; got %s.",
                $sample->id,
                get_debug_type($sample->expectedOutput),
            ));
        }

        $scale = $this->scaleFor($sample);

        $expectedIndex = array_search($sample->expectedOutput, $scale, true);
        if ($expectedIndex === false) {
            throw new MetricException(sprintf(
                "Sample '%s' expected_output '%s' is not in the ordinal scale [%s].",
                $sample->id,
                $sample->expectedOutput,
                implode(', ', $scale),
            ));
        }

        $actualIndex = array_search($actualOutput, $scale, true);

        if ($actualIndex === false) {
            return new MetricScore(0.0, [
                'expected' => $sample->expectedOutput,
                'actual' => $actualOutput,
                'expected_index' => $expectedIndex,
                'actual_index' => null,
                'distance' => null,
            ]);
        }

        $distance = abs($expectedIndex - $actualIndex);
        $score = match (true) {
            $distance === 0 => 1.0,
            $distance === 1 => 0.5,
            default => 0.0,
        };

        return new MetricScore($score, [
            'expected' => $sample->expectedOutput,
            'actual' => $actualOutput,
            'expected_index' => $expectedIndex,
            'actual_index' => $actualIndex,
            'distance' => $distance,
        ]);
    }

    /**
     * @return list<string>
     */
    private function scaleFor(DatasetSample $sample): array
    {
        $override = $sample->metadata['ordinal_scale'] ?? null;
        if (is_array($override) && $this->isValidScale($override)) {
            return array_values($override);
        }

        return $this->scale;
    }

    private function isValidScale(mixed $scale): bool
    {
        if (! is_array($scale) || $scale === [] || ! array_is_list($scale)) {
            return false;
        }

        foreach ($scale as $label) {
            if (! is_string($label) || $label === '') {
                return false;
            }
        }

        return count(array_unique($scale)) === count($scale);
    }
}
