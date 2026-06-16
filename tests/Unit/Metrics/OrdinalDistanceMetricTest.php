<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Metrics;

use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Exceptions\MetricException;
use Padosoft\EvalHarness\Metrics\OrdinalDistanceMetric;
use PHPUnit\Framework\TestCase;

final class OrdinalDistanceMetricTest extends TestCase
{
    private const SCALE = ['low', 'medium', 'high', 'urgent'];

    public function test_name_is_stable(): void
    {
        $this->assertSame('ordinal-distance', (new OrdinalDistanceMetric(self::SCALE))->name());
    }

    public function test_exact_match_scores_one(): void
    {
        $sample = new DatasetSample(id: 'a', input: [], expectedOutput: 'high');
        $score = (new OrdinalDistanceMetric(self::SCALE))->score($sample, 'high');
        $this->assertSame(1.0, $score->score);
        $this->assertSame(0, $score->details['distance']);
    }

    public function test_off_by_one_scores_half(): void
    {
        $sample = new DatasetSample(id: 'a', input: [], expectedOutput: 'high');
        $score = (new OrdinalDistanceMetric(self::SCALE))->score($sample, 'medium');
        $this->assertSame(0.5, $score->score);
        $this->assertSame(1, $score->details['distance']);
    }

    public function test_two_apart_scores_zero(): void
    {
        $sample = new DatasetSample(id: 'a', input: [], expectedOutput: 'urgent');
        $score = (new OrdinalDistanceMetric(self::SCALE))->score($sample, 'medium');
        $this->assertSame(0.0, $score->score);
        $this->assertSame(2, $score->details['distance']);
    }

    public function test_unknown_actual_scores_zero(): void
    {
        $sample = new DatasetSample(id: 'a', input: [], expectedOutput: 'low');
        $score = (new OrdinalDistanceMetric(self::SCALE))->score($sample, 'banana');
        $this->assertSame(0.0, $score->score);
        $this->assertNull($score->details['actual_index']);
    }

    public function test_per_sample_metadata_scale_overrides_constructor(): void
    {
        $sample = new DatasetSample(
            id: 'a',
            input: [],
            expectedOutput: 'b',
            metadata: ['ordinal_scale' => ['a', 'b', 'c']],
        );
        $score = (new OrdinalDistanceMetric(self::SCALE))->score($sample, 'a');
        $this->assertSame(0.5, $score->score);
    }

    public function test_non_string_expected_throws(): void
    {
        $sample = new DatasetSample(id: 'a', input: [], expectedOutput: 3);
        $this->expectException(MetricException::class);
        $this->expectExceptionMessage('must be a string for ordinal-distance');
        (new OrdinalDistanceMetric(self::SCALE))->score($sample, 'low');
    }

    public function test_expected_not_in_scale_throws(): void
    {
        $sample = new DatasetSample(id: 'a', input: [], expectedOutput: 'nope');
        $this->expectException(MetricException::class);
        $this->expectExceptionMessage('not in the ordinal scale');
        (new OrdinalDistanceMetric(self::SCALE))->score($sample, 'low');
    }

    public function test_empty_scale_throws_on_construction(): void
    {
        $this->expectException(MetricException::class);
        new OrdinalDistanceMetric([]);
    }
}
