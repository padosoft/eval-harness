<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Metrics;

use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Exceptions\MetricException;
use Padosoft\EvalHarness\Metrics\ExactMatchMetric;
use PHPUnit\Framework\TestCase;

final class ExactMatchMetricTest extends TestCase
{
    public function test_name_is_stable(): void
    {
        $this->assertSame('exact-match', (new ExactMatchMetric)->name());
    }

    public function test_exact_string_match_scores_one(): void
    {
        $sample = new DatasetSample(id: 'a', input: [], expectedOutput: 'Paris');
        $score = (new ExactMatchMetric)->score($sample, 'Paris');

        $this->assertSame(1.0, $score->score);
        $this->assertTrue($score->details['match']);
    }

    public function test_case_difference_scores_zero(): void
    {
        $sample = new DatasetSample(id: 'a', input: [], expectedOutput: 'Paris');
        $score = (new ExactMatchMetric)->score($sample, 'paris');

        $this->assertSame(0.0, $score->score);
        $this->assertFalse($score->details['match']);
    }

    public function test_trailing_whitespace_difference_scores_zero(): void
    {
        $sample = new DatasetSample(id: 'a', input: [], expectedOutput: 'Paris');
        $score = (new ExactMatchMetric)->score($sample, 'Paris ');

        $this->assertSame(0.0, $score->score);
    }

    public function test_unicode_equality_scores_one(): void
    {
        $sample = new DatasetSample(id: 'a', input: [], expectedOutput: 'café');
        $score = (new ExactMatchMetric)->score($sample, 'café');

        $this->assertSame(1.0, $score->score);
    }

    public function test_non_string_expected_throws(): void
    {
        $sample = new DatasetSample(id: 'a', input: [], expectedOutput: ['array' => 'output']);

        $this->expectException(MetricException::class);
        $this->expectExceptionMessage('must be a string for exact-match');

        (new ExactMatchMetric)->score($sample, 'whatever');
    }
}
