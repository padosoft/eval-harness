<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Metrics;

use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Exceptions\MetricException;
use Padosoft\EvalHarness\Metrics\RougeLMetric;
use PHPUnit\Framework\TestCase;

final class RougeLMetricTest extends TestCase
{
    public function test_scores_one_for_identical_token_sequences(): void
    {
        $score = (new RougeLMetric)->score(
            new DatasetSample(id: 's1', input: [], expectedOutput: 'Paris is the capital'),
            'Paris is the capital',
        );

        $this->assertSame(1.0, $score->score);
        $this->assertSame(4, $score->details['lcs_tokens']);
    }

    public function test_scores_rouge_l_f1_for_partial_overlap(): void
    {
        $score = (new RougeLMetric)->score(
            new DatasetSample(id: 's1', input: [], expectedOutput: 'the cat sat'),
            'the cat slept',
        );

        $this->assertEqualsWithDelta(2.0 / 3.0, $score->score, 1e-9);
        $this->assertSame(2, $score->details['lcs_tokens']);
    }

    public function test_scores_empty_expected_and_empty_actual_as_one(): void
    {
        $score = (new RougeLMetric)->score(
            new DatasetSample(id: 's1', input: [], expectedOutput: ''),
            '',
        );

        $this->assertSame(1.0, $score->score);
    }

    public function test_expected_output_must_be_string(): void
    {
        $this->expectException(MetricException::class);
        $this->expectExceptionMessage('expected_output must be a string');

        (new RougeLMetric)->score(
            new DatasetSample(id: 's1', input: [], expectedOutput: ['expected']),
            'actual',
        );
    }
}
