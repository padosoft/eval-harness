<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Datasets;

use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Datasets\RowHash;
use PHPUnit\Framework\TestCase;

final class RowHashTest extends TestCase
{
    public function test_the_same_content_hashes_the_same(): void
    {
        $this->assertSame(
            RowHash::for($this->sample('a')),
            RowHash::for($this->sample('a')),
        );
    }

    /**
     * The whole reason the join key is not the id: a row renamed from
     * `sample-14` to `refund-policy` must keep its history, otherwise the first
     * tidy-up of a dataset resets every baseline in it.
     */
    public function test_renaming_a_row_does_not_change_its_hash(): void
    {
        $this->assertSame(
            RowHash::for($this->sample('sample-14')),
            RowHash::for($this->sample('refund-policy')),
        );
    }

    public function test_adding_metadata_does_not_change_the_hash(): void
    {
        $bare = new DatasetSample('a', ['question' => 'q'], 'expected');
        $tagged = new DatasetSample('a', ['question' => 'q'], 'expected', ['tags' => ['policy']]);

        $this->assertSame(RowHash::for($bare), RowHash::for($tagged));
    }

    public function test_key_order_in_the_input_does_not_change_the_hash(): void
    {
        $first = new DatasetSample('a', ['question' => 'q', 'context' => 'c'], 'expected');
        $second = new DatasetSample('a', ['context' => 'c', 'question' => 'q'], 'expected');

        $this->assertSame(RowHash::for($first), RowHash::for($second));
    }

    public function test_nested_key_order_does_not_change_the_hash(): void
    {
        $first = new DatasetSample('a', ['payload' => ['b' => 2, 'a' => 1]], 'expected');
        $second = new DatasetSample('a', ['payload' => ['a' => 1, 'b' => 2]], 'expected');

        $this->assertSame(RowHash::for($first), RowHash::for($second));
    }

    /**
     * List order is content, not formatting: a ranked retrieval and a
     * conversation both mean something different when reordered.
     */
    public function test_list_order_does_change_the_hash(): void
    {
        $first = new DatasetSample('a', ['ranked' => ['x', 'y']], 'expected');
        $second = new DatasetSample('a', ['ranked' => ['y', 'x']], 'expected');

        $this->assertNotSame(RowHash::for($first), RowHash::for($second));
    }

    public function test_editing_the_question_changes_the_hash(): void
    {
        $before = new DatasetSample('a', ['question' => 'q'], 'expected');
        $after = new DatasetSample('a', ['question' => 'a different question'], 'expected');

        $this->assertNotSame(RowHash::for($before), RowHash::for($after));
    }

    public function test_editing_the_expected_output_changes_the_hash(): void
    {
        $before = new DatasetSample('a', ['question' => 'q'], '30 days');
        $after = new DatasetSample('a', ['question' => 'q'], '60 days');

        $this->assertNotSame(RowHash::for($before), RowHash::for($after));
    }

    public function test_hash_is_a_sha256_hex_digest(): void
    {
        $hash = RowHash::for($this->sample('a'));

        $this->assertSame(64, strlen($hash));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    public function test_short_form_is_a_prefix(): void
    {
        $hash = RowHash::for($this->sample('a'));

        $this->assertSame(RowHash::SHORT_LENGTH, strlen(RowHash::short($hash)));
        $this->assertStringStartsWith(RowHash::short($hash), $hash);
    }

    public function test_non_string_expected_outputs_are_supported(): void
    {
        $structured = RowHash::fromParts(['q' => 'x'], ['label' => 'urgent', 'score' => 3]);
        $reordered = RowHash::fromParts(['q' => 'x'], ['score' => 3, 'label' => 'urgent']);

        $this->assertSame($structured, $reordered);
        $this->assertNotSame($structured, RowHash::fromParts(['q' => 'x'], ['label' => 'low', 'score' => 3]));
    }

    private function sample(string $id): DatasetSample
    {
        return new DatasetSample($id, ['question' => 'How many days?'], '30 days');
    }
}
