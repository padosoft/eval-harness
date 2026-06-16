<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Metrics;

use Illuminate\Config\Repository;
use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Exceptions\MetricException;
use Padosoft\EvalHarness\Metrics\AnswerContainmentAtKMetric;
use PHPUnit\Framework\TestCase;

final class AnswerContainmentAtKMetricTest extends TestCase
{
    private function metric(?int $k = null, bool $caseSensitive = false): AnswerContainmentAtKMetric
    {
        $config = new Repository(['eval-harness' => ['metrics' => ['retrieval' => ['default_k' => 2]]]]);

        return new AnswerContainmentAtKMetric($config, $k, $caseSensitive);
    }

    public function test_name_is_stable(): void
    {
        $this->assertSame('answer-containment-at-k', $this->metric()->name());
    }

    public function test_contains_span_in_top_k(): void
    {
        $actual = '{"retrieved":[{"id":"a","text":"The capital is Paris."},{"id":"b","text":"Berlin is in Germany."}]}';
        $sample = new DatasetSample(id: 's', input: [], expectedOutput: 'paris');
        $this->assertSame(1.0, $this->metric()->score($sample, $actual)->score);
    }

    public function test_span_outside_top_k_scores_zero(): void
    {
        $actual = '{"retrieved":[{"id":"a","text":"alpha"},{"id":"b","text":"beta"},{"id":"c","text":"contains paris here"}]}';
        $sample = new DatasetSample(id: 's', input: [], expectedOutput: 'paris');
        $this->assertSame(0.0, $this->metric(k: 2)->score($sample, $actual)->score);
    }

    public function test_whitespace_is_normalized(): void
    {
        $actual = '{"retrieved":[{"id":"a","text":"... New   York is big ..."}]}';
        $sample = new DatasetSample(id: 's', input: [], expectedOutput: 'new york');
        $this->assertSame(1.0, $this->metric()->score($sample, $actual)->score);
    }

    public function test_case_sensitive_mismatch_scores_zero(): void
    {
        $actual = '{"retrieved":[{"id":"a","text":"the capital is paris"}]}';
        $sample = new DatasetSample(id: 's', input: [], expectedOutput: 'Paris');
        $this->assertSame(0.0, $this->metric(caseSensitive: true)->score($sample, $actual)->score);
    }

    public function test_non_string_expected_throws(): void
    {
        $sample = new DatasetSample(id: 's', input: [], expectedOutput: ['paris']);
        $this->expectException(MetricException::class);
        $this->metric()->score($sample, '{"retrieved":[]}');
    }

    public function test_empty_expected_throws(): void
    {
        $sample = new DatasetSample(id: 's', input: [], expectedOutput: '   ');
        $this->expectException(MetricException::class);
        $this->metric()->score($sample, '{"retrieved":[]}');
    }

    public function test_empty_retrieval_scores_zero(): void
    {
        $sample = new DatasetSample(id: 's', input: [], expectedOutput: 'paris');
        $this->assertSame(0.0, $this->metric()->score($sample, '{"retrieved":[]}')->score);
    }
}
