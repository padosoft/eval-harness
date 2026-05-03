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

    public function test_tokenization_uses_unicode_aware_lowercasing(): void
    {
        $score = (new RougeLMetric)->score(
            new DatasetSample(id: 's1', input: [], expectedOutput: 'École supérieure'),
            'école supérieure',
        );

        $this->assertSame(1.0, $score->score);
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

    public function test_invalid_utf8_throws_metric_exception(): void
    {
        $this->expectException(MetricException::class);
        $this->expectExceptionMessage('must be valid UTF-8');

        (new RougeLMetric)->score(
            new DatasetSample(id: 's1', input: [], expectedOutput: 'expected'),
            "\xB1\x31",
        );
    }

    public function test_rejects_inputs_that_exceed_token_cap(): void
    {
        $this->expectException(MetricException::class);
        $this->expectExceptionMessage('supports at most 2 tokens per field');

        (new RougeLMetric(maxTokens: 2))->score(
            new DatasetSample(id: 's1', input: [], expectedOutput: 'one two three'),
            'one two',
        );
    }

    public function test_token_cap_must_be_positive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('max token count must be at least 1');

        new RougeLMetric(maxTokens: 0);
    }
}
