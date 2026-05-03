<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Metrics;

use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Exceptions\MetricException;
use Padosoft\EvalHarness\Metrics\ContainsMetric;
use PHPUnit\Framework\TestCase;

final class ContainsMetricTest extends TestCase
{
    public function test_scores_one_when_actual_contains_expected_string(): void
    {
        $score = (new ContainsMetric)->score(
            new DatasetSample(id: 's1', input: [], expectedOutput: 'Paris'),
            'The answer is Paris, France.',
        );

        $this->assertSame(1.0, $score->score);
        $this->assertTrue($score->details['match']);
    }

    public function test_scores_zero_when_actual_does_not_contain_expected_string(): void
    {
        $score = (new ContainsMetric)->score(
            new DatasetSample(id: 's1', input: [], expectedOutput: 'Paris'),
            'The answer is Lyon.',
        );

        $this->assertSame(0.0, $score->score);
        $this->assertFalse($score->details['match']);
    }

    public function test_expected_output_must_be_string(): void
    {
        $this->expectException(MetricException::class);
        $this->expectExceptionMessage('expected_output must be a string');

        (new ContainsMetric)->score(
            new DatasetSample(id: 's1', input: [], expectedOutput: ['Paris']),
            'Paris',
        );
    }

    public function test_expected_output_must_not_be_empty(): void
    {
        $this->expectException(MetricException::class);
        $this->expectExceptionMessage('expected_output must not be empty');

        (new ContainsMetric)->score(
            new DatasetSample(id: 's1', input: [], expectedOutput: ''),
            'Any output would otherwise match.',
        );
    }
}
