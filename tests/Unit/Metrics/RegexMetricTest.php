<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Metrics;

use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Exceptions\MetricException;
use Padosoft\EvalHarness\Metrics\RegexMetric;
use PHPUnit\Framework\TestCase;

final class RegexMetricTest extends TestCase
{
    public function test_scores_one_when_actual_matches_pattern(): void
    {
        $score = (new RegexMetric)->score(
            new DatasetSample(id: 's1', input: [], expectedOutput: '/order #[0-9]+/'),
            'Created order #1234.',
        );

        $this->assertSame(1.0, $score->score);
        $this->assertTrue($score->details['match']);
    }

    public function test_scores_zero_when_actual_does_not_match_pattern(): void
    {
        $score = (new RegexMetric)->score(
            new DatasetSample(id: 's1', input: [], expectedOutput: '/order #[0-9]+/'),
            'Created invoice ABC.',
        );

        $this->assertSame(0.0, $score->score);
        $this->assertFalse($score->details['match']);
    }

    public function test_invalid_regex_throws_metric_exception(): void
    {
        $this->expectException(MetricException::class);
        $this->expectExceptionMessage('regex metric could not evaluate expected_output against actual output');

        (new RegexMetric)->score(
            new DatasetSample(id: 's1', input: [], expectedOutput: '/unterminated'),
            'anything',
        );
    }

    public function test_regex_execution_failure_throws_metric_exception(): void
    {
        $this->expectException(MetricException::class);
        $this->expectExceptionMessage('regex metric could not evaluate expected_output against actual output');

        (new RegexMetric)->score(
            new DatasetSample(id: 's1', input: [], expectedOutput: '/./u'),
            "\xB1\x31",
        );
    }
}
