<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Metrics\Retrieval;

use Padosoft\EvalHarness\Exceptions\MetricException;
use Padosoft\EvalHarness\Metrics\Retrieval\RankedRetrieval;
use PHPUnit\Framework\TestCase;

final class RankedRetrievalTest extends TestCase
{
    public function test_parses_object_form_with_ids_and_texts(): void
    {
        $r = RankedRetrieval::fromActualOutput(
            '{"retrieved":[{"id":"a","text":"alpha"},{"id":"b","text":"beta"}]}',
            's1',
        );
        $this->assertSame(['a', 'b'], $r->ids());
        $this->assertSame(['alpha', 'beta'], $r->texts());
        $this->assertSame(2, $r->count());
    }

    public function test_parses_bare_array_of_strings(): void
    {
        $r = RankedRetrieval::fromActualOutput('["a","b","c"]', 's1');
        $this->assertSame(['a', 'b', 'c'], $r->ids());
        $this->assertSame(['', '', ''], $r->texts());
    }

    public function test_dedups_ids_keeping_best_rank(): void
    {
        $r = RankedRetrieval::fromActualOutput('["a","b","a","c"]', 's1');
        $this->assertSame(['a', 'b', 'c'], $r->ids());
    }

    public function test_top_k_truncates(): void
    {
        $r = RankedRetrieval::fromActualOutput('["a","b","c","d"]', 's1');
        $this->assertSame(['a', 'b'], $r->topKIds(2));
        $this->assertSame(['a', 'b', 'c', 'd'], $r->topKIds(99));
    }

    public function test_invalid_json_throws(): void
    {
        $this->expectException(MetricException::class);
        $this->expectExceptionMessage('not valid JSON');
        RankedRetrieval::fromActualOutput('not json', 's1');
    }

    public function test_wrong_shape_throws(): void
    {
        $this->expectException(MetricException::class);
        RankedRetrieval::fromActualOutput('{"retrieved": 42}', 's1');
    }

    public function test_relevant_ids_from_php_array(): void
    {
        $this->assertSame(['x', 'y'], RankedRetrieval::relevantIdsFromExpected(['x', 'y'], 's1'));
    }

    public function test_relevant_ids_from_json_string(): void
    {
        $this->assertSame(['x', 'y'], RankedRetrieval::relevantIdsFromExpected('["x","y"]', 's1'));
    }

    public function test_relevant_ids_from_gain_map_keys(): void
    {
        $this->assertSame(['x', 'y'], RankedRetrieval::relevantIdsFromExpected(['x' => 3, 'y' => 1], 's1'));
    }

    public function test_empty_relevant_throws(): void
    {
        $this->expectException(MetricException::class);
        $this->expectExceptionMessage('at least one relevant id');
        RankedRetrieval::relevantIdsFromExpected([], 's1');
    }

    public function test_relevance_gains_binary_default(): void
    {
        $this->assertSame(['x' => 1.0, 'y' => 1.0], RankedRetrieval::relevanceGainsFromExpected(['x', 'y'], 's1'));
    }

    public function test_relevance_gains_graded(): void
    {
        $this->assertSame(['x' => 3.0, 'y' => 1.0], RankedRetrieval::relevanceGainsFromExpected(['x' => 3, 'y' => 1], 's1'));
    }
}
