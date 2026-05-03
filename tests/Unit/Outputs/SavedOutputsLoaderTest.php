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

    public function test_loads_json_outputs_list(): void
    {
        $outputs = (new SavedOutputsLoader)->loadString(
            '{"outputs":[{"id":"s1","actual_output":"answer one"},{"id":"s2","actual_output":"answer two"}]}',
            'outputs.json',
        );

        $this->assertSame(['s1' => 'answer one', 's2' => 'answer two'], $outputs);
    }

    public function test_loads_json_numeric_key_outputs_map(): void
    {
        $outputs = (new SavedOutputsLoader)->loadString(
            '{"outputs":{"0":"zero","1":"one"}}',
            'outputs.json',
        );

        $this->assertSame(['0' => 'zero', '1' => 'one'], $outputs);
    }

    public function test_loads_yaml_outputs_list(): void
    {
        $outputs = (new SavedOutputsLoader)->loadString(
            "outputs:\n  - id: s1\n    actual_output: answer one\n  - id: s2\n    actual_output: answer two\n",
            'outputs.yaml',
        );

        $this->assertSame(['s1' => 'answer one', 's2' => 'answer two'], $outputs);
    }

    public function test_loads_yaml_outputs_map(): void
    {
        $outputs = (new SavedOutputsLoader)->loadString(
            "outputs:\n  s1: answer one\n  s2: answer two\n",
            'outputs.yaml',
        );

        $this->assertSame(['s1' => 'answer one', 's2' => 'answer two'], $outputs);
    }

    public function test_loads_yaml_numeric_key_outputs_map(): void
    {
        $outputs = (new SavedOutputsLoader)->loadString(
            "outputs:\n  0: zero\n  1: one\n",
            'outputs.yaml',
        );

        $this->assertSame(['0' => 'zero', '1' => 'one'], $outputs);
    }

    public function test_loads_extensionless_yaml_flow_style_map(): void
    {
        $outputs = (new SavedOutputsLoader)->loadString(
            '{outputs: {s1: answer one, s2: answer two}}',
            'artifact',
        );

        $this->assertSame(['s1' => 'answer one', 's2' => 'answer two'], $outputs);
    }

    public function test_preserves_sample_ids_verbatim(): void
    {
        $outputs = (new SavedOutputsLoader)->loadString(
            '{"outputs":[{"id":" s1 ","actual_output":"answer"}]}',
            'outputs.json',
        );

        $this->assertSame([' s1 ' => 'answer'], $outputs);
    }

    public function test_rejects_scalar_list_outputs(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('must be an object with id and actual_output');

        (new SavedOutputsLoader)->loadString('{"outputs":["answer"]}', 'outputs.json');
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

    public function test_rejects_json_like_extensionless_contents_without_yaml_fallback(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('contains invalid JSON');

        (new SavedOutputsLoader)->loadString('{"outputs":', 'artifact');
    }
}
