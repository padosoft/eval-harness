<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Outputs;

use Padosoft\EvalHarness\Exceptions\EvalRunException;
use Padosoft\EvalHarness\Outputs\SavedOutputs;
use PHPUnit\Framework\TestCase;

final class SavedOutputsTest extends TestCase
{
    public function test_constructor_rejects_missing_actual_output(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('actual_output');

        new SavedOutputs([
            ['id' => 's1'],
        ]);
    }

    public function test_constructor_rejects_non_array_entries(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('must be an array');

        new SavedOutputs([
            'not-an-entry',
        ]);
    }

    public function test_constructor_rejects_associative_entry_arrays(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('must be a list of entry arrays');

        new SavedOutputs([
            's1' => ['id' => 's1', 'actual_output' => 'answer'],
        ]);
    }

    public function test_constructor_rejects_non_string_id(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('string id');

        new SavedOutputs([
            ['id' => 123, 'actual_output' => 'answer'],
        ]);
    }

    public function test_constructor_preserves_numeric_like_ids_as_distinct_entries(): void
    {
        $outputs = new SavedOutputs([
            ['id' => '0', 'actual_output' => 'zero'],
            ['id' => '00', 'actual_output' => 'double zero'],
        ]);

        $this->assertSame([
            ['id' => '0', 'actual_output' => 'zero'],
            ['id' => '00', 'actual_output' => 'double zero'],
        ], $outputs->entries());
    }
}
