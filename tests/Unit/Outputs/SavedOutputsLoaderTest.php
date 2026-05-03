<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Outputs;

use Padosoft\EvalHarness\Exceptions\EvalRunException;
use Padosoft\EvalHarness\Outputs\SavedOutputsLoader;
use PHPUnit\Framework\TestCase;

final class SavedOutputsLoaderTest extends TestCase
{
    public function test_load_file_parses_json_by_extension(): void
    {
        $path = $this->writeTempFile('.json', '{"outputs":{"s1":"answer one"}}');

        try {
            $outputs = (new SavedOutputsLoader)->loadFile($path);
        } finally {
            @unlink($path);
        }

        $this->assertSame([['id' => 's1', 'actual_output' => 'answer one']], $outputs->entries());
    }

    public function test_load_file_parses_yaml_by_extension(): void
    {
        $path = $this->writeTempFile('.yaml', "outputs:\n  s1: answer one\n");

        try {
            $outputs = (new SavedOutputsLoader)->loadFile($path);
        } finally {
            @unlink($path);
        }

        $this->assertSame([['id' => 's1', 'actual_output' => 'answer one']], $outputs->entries());
    }

    public function test_load_file_parses_extensionless_yaml_flow_style(): void
    {
        $path = $this->writeTempFile('', '{outputs: {s1: answer one}}');

        try {
            $outputs = (new SavedOutputsLoader)->loadFile($path);
        } finally {
            @unlink($path);
        }

        $this->assertSame([['id' => 's1', 'actual_output' => 'answer one']], $outputs->entries());
    }

    public function test_load_file_rejects_missing_paths(): void
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eval-harness-missing-'.bin2hex(random_bytes(8)).'.json';

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('does not exist or is not a regular file');

        (new SavedOutputsLoader)->loadFile($path);
    }

    public function test_loads_json_outputs_map(): void
    {
        $outputs = (new SavedOutputsLoader)->loadString(
            '{"outputs":{"s1":"answer one","s2":"answer two"}}',
            'outputs.json',
        );

        $this->assertSame([
            ['id' => 's1', 'actual_output' => 'answer one'],
            ['id' => 's2', 'actual_output' => 'answer two'],
        ], $outputs->entries());
    }

    public function test_loads_json_outputs_list(): void
    {
        $outputs = (new SavedOutputsLoader)->loadString(
            '{"outputs":[{"id":"s1","actual_output":"answer one"},{"id":"s2","actual_output":"answer two"}]}',
            'outputs.json',
        );

        $this->assertSame([
            ['id' => 's1', 'actual_output' => 'answer one'],
            ['id' => 's2', 'actual_output' => 'answer two'],
        ], $outputs->entries());
    }

    public function test_loads_json_numeric_key_outputs_map(): void
    {
        $outputs = (new SavedOutputsLoader)->loadString(
            '{"outputs":{"0":"zero","1":"one"}}',
            'outputs.json',
        );

        $this->assertSame([
            ['id' => '0', 'actual_output' => 'zero'],
            ['id' => '1', 'actual_output' => 'one'],
        ], $outputs->entries());
    }

    public function test_loads_yaml_outputs_list(): void
    {
        $outputs = (new SavedOutputsLoader)->loadString(
            "outputs:\n  - id: s1\n    actual_output: answer one\n  - id: s2\n    actual_output: answer two\n",
            'outputs.yaml',
        );

        $this->assertSame([
            ['id' => 's1', 'actual_output' => 'answer one'],
            ['id' => 's2', 'actual_output' => 'answer two'],
        ], $outputs->entries());
    }

    public function test_loads_yaml_outputs_map(): void
    {
        $outputs = (new SavedOutputsLoader)->loadString(
            "outputs:\n  s1: answer one\n  s2: answer two\n",
            'outputs.yaml',
        );

        $this->assertSame([
            ['id' => 's1', 'actual_output' => 'answer one'],
            ['id' => 's2', 'actual_output' => 'answer two'],
        ], $outputs->entries());
    }

    public function test_loads_yaml_numeric_key_outputs_map(): void
    {
        $outputs = (new SavedOutputsLoader)->loadString(
            "outputs:\n  0: zero\n  1: one\n",
            'outputs.yaml',
        );

        $this->assertSame([
            ['id' => '0', 'actual_output' => 'zero'],
            ['id' => '1', 'actual_output' => 'one'],
        ], $outputs->entries());
    }

    public function test_loads_extensionless_yaml_flow_style_map(): void
    {
        $outputs = (new SavedOutputsLoader)->loadString(
            '{outputs: {s1: answer one, s2: answer two}}',
            'artifact',
        );

        $this->assertSame([
            ['id' => 's1', 'actual_output' => 'answer one'],
            ['id' => 's2', 'actual_output' => 'answer two'],
        ], $outputs->entries());
    }

    public function test_preserves_sample_ids_verbatim(): void
    {
        $outputs = (new SavedOutputsLoader)->loadString(
            '{"outputs":[{"id":" s1 ","actual_output":"answer"}]}',
            'outputs.json',
        );

        $this->assertSame([['id' => ' s1 ', 'actual_output' => 'answer']], $outputs->entries());
    }

    public function test_rejects_documents_without_outputs_field(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('must contain an outputs field');

        (new SavedOutputsLoader)->loadString('{"output":{"s1":"answer"}}', 'outputs.json');
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

    public function test_rejects_empty_map_sample_id(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('contain an empty sample id');

        (new SavedOutputsLoader)->loadString(
            '{"outputs":{"":"answer"}}',
            'outputs.json',
        );
    }

    public function test_rejects_empty_list_sample_id(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('must contain a non-empty string id');

        (new SavedOutputsLoader)->loadString(
            '{"outputs":[{"id":"","actual_output":"answer"}]}',
            'outputs.json',
        );
    }

    public function test_rejects_invalid_json_files_without_yaml_fallback(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('contains invalid JSON');

        (new SavedOutputsLoader)->loadString('{not-json', 'outputs.json');
    }

    public function test_rejects_json_like_extensionless_contents_with_combined_parse_error(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('could not be parsed as JSON or YAML');

        (new SavedOutputsLoader)->loadString('{"outputs":', 'artifact');
    }

    private function writeTempFile(string $suffix, string $contents): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eval-harness-'.bin2hex(random_bytes(8)).$suffix;
        file_put_contents($path, $contents);

        return $path;
    }
}
