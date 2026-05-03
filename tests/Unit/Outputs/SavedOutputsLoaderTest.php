<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Outputs;

use Padosoft\EvalHarness\Exceptions\EvalRunException;
use Padosoft\EvalHarness\Outputs\SavedOutputsLoader;
use PHPUnit\Framework\TestCase;

final class SavedOutputsLoaderTest extends TestCase
{
    public function test_loads_json_outputs_map(): void
    {
        $outputs = (new SavedOutputsLoader)->loadString(
            '{"outputs":{"s1":"answer one","s2":"answer two"}}',
            'outputs.json',
        );

        $this->assertSame(['s1' => 'answer one', 's2' => 'answer two'], $outputs);
    }

    public function test_loads_yaml_outputs_list(): void
    {
        $outputs = (new SavedOutputsLoader)->loadString(
            "outputs:\n  - id: s1\n    actual_output: answer one\n  - id: s2\n    actual_output: answer two\n",
            'outputs.yaml',
        );

        $this->assertSame(['s1' => 'answer one', 's2' => 'answer two'], $outputs);
    }

    public function test_rejects_duplicate_list_ids(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage("Duplicate saved output for sample 's1'");

        (new SavedOutputsLoader)->loadString(
            '{"outputs":[{"id":"s1","actual_output":"a"},{"id":"s1","actual_output":"b"}]}',
            'outputs.json',
        );
    }

    public function test_rejects_non_string_map_outputs(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage("Saved output for sample 's1'");

        (new SavedOutputsLoader)->loadString(
            '{"outputs":{"s1":{"text":"answer"}}}',
            'outputs.json',
        );
    }

    public function test_rejects_invalid_json_files_without_yaml_fallback(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('contains invalid JSON');

        (new SavedOutputsLoader)->loadString('{not-json', 'outputs.json');
    }
}
