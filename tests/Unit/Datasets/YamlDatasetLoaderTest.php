<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Datasets;

use Padosoft\EvalHarness\Datasets\YamlDatasetLoader;
use Padosoft\EvalHarness\Exceptions\DatasetSchemaException;
use PHPUnit\Framework\TestCase;

final class YamlDatasetLoaderTest extends TestCase
{
    private function loader(): YamlDatasetLoader
    {
        return new YamlDatasetLoader;
    }

    public function test_happy_path_yaml_string(): void
    {
        $yaml = <<<'YAML'
        name: rag.smoke
        samples:
          - id: s1
            input:
              question: "Who?"
            expected_output: "Alice"
            metadata:
              difficulty: easy
          - id: s2
            input:
              question: "Where?"
            expected_output: "Paris"
        YAML;

        $parsed = $this->loader()->loadString($yaml);

        $this->assertSame('rag.smoke', $parsed->name);
        $this->assertCount(2, $parsed->samples);
        $this->assertSame('s1', $parsed->samples[0]->id);
        $this->assertSame(['question' => 'Who?'], $parsed->samples[0]->input);
        $this->assertSame('Alice', $parsed->samples[0]->expectedOutput);
        $this->assertSame(['difficulty' => 'easy'], $parsed->samples[0]->metadata);
        $this->assertSame([], $parsed->samples[1]->metadata);
    }

    public function test_missing_name_throws(): void
    {
        $this->expectException(DatasetSchemaException::class);
        $this->expectExceptionMessage("missing required string field 'name'");

        $this->loader()->loadString("samples:\n  - id: s1\n    input: {q: 1}\n    expected_output: x\n");
    }

    public function test_missing_samples_throws(): void
    {
        $this->expectException(DatasetSchemaException::class);
        $this->expectExceptionMessage("missing required list field 'samples'");

        $this->loader()->loadString("name: x\n");
    }

    public function test_empty_samples_throws(): void
    {
        $this->expectException(DatasetSchemaException::class);
        $this->expectExceptionMessage('at least one sample');

        $this->loader()->loadString("name: x\nsamples: []\n");
    }

    public function test_sample_missing_id_throws(): void
    {
        $this->expectException(DatasetSchemaException::class);
        $this->expectExceptionMessage("missing required string field 'id'");

        $yaml = "name: x\nsamples:\n  - input: {q: 1}\n    expected_output: y\n";
        $this->loader()->loadString($yaml);
    }

    public function test_sample_missing_input_throws(): void
    {
        $this->expectException(DatasetSchemaException::class);
        $this->expectExceptionMessage("missing required associative-array field 'input'");

        $yaml = "name: x\nsamples:\n  - id: s1\n    expected_output: y\n";
        $this->loader()->loadString($yaml);
    }

    public function test_sample_missing_expected_output_throws(): void
    {
        $this->expectException(DatasetSchemaException::class);
        $this->expectExceptionMessage("missing required field 'expected_output'");

        $yaml = "name: x\nsamples:\n  - id: s1\n    input: {q: 1}\n";
        $this->loader()->loadString($yaml);
    }

    public function test_sample_metadata_must_be_array(): void
    {
        $this->expectException(DatasetSchemaException::class);
        $this->expectExceptionMessage("'metadata' must be an associative array");

        $yaml = "name: x\nsamples:\n  - id: s1\n    input: {q: 1}\n    expected_output: y\n    metadata: 'not-an-array'\n";
        $this->loader()->loadString($yaml);
    }

    public function test_duplicate_sample_id_throws(): void
    {
        $this->expectException(DatasetSchemaException::class);
        $this->expectExceptionMessage("Duplicate sample id 's1'");

        $yaml = <<<'YAML'
        name: x
        samples:
          - id: s1
            input: {q: 1}
            expected_output: y1
          - id: s1
            input: {q: 2}
            expected_output: y2
        YAML;
        $this->loader()->loadString($yaml);
    }

    public function test_invalid_yaml_throws_schema_exception_with_parse_context(): void
    {
        $this->expectException(DatasetSchemaException::class);
        $this->expectExceptionMessage('not valid YAML');

        $this->loader()->loadString(": : : :\n  - bogus");
    }

    public function test_load_file_missing_throws(): void
    {
        $this->expectException(DatasetSchemaException::class);
        $this->expectExceptionMessage('missing or unreadable');

        $this->loader()->loadFile(__DIR__.'/does-not-exist.yml');
    }

    public function test_load_file_happy_path(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'eval-yaml-');
        $this->assertNotFalse($tmp);
        file_put_contents(
            $tmp,
            "name: ds.file\nsamples:\n  - id: s1\n    input: {q: 1}\n    expected_output: y\n",
        );

        try {
            $parsed = $this->loader()->loadFile($tmp);
            $this->assertSame('ds.file', $parsed->name);
            $this->assertCount(1, $parsed->samples);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Regression: 'input' is documented as an associative array but
     * a YAML list (e.g. `input: [1, 2]`) was previously accepted and
     * would surface as a confusing downstream error when the SUT
     * looked up a named key. The schema check now rejects lists at
     * load time with a message that names the offending field.
     */
    public function test_sample_input_must_be_associative_array(): void
    {
        $this->expectException(DatasetSchemaException::class);
        $this->expectExceptionMessage("'input' must be an associative array");

        $yaml = <<<'YAML'
        name: x
        samples:
          - id: s1
            input: [1, 2, 3]
            expected_output: y
        YAML;
        $this->loader()->loadString($yaml);
    }

    /**
     * Regression: 'metadata' is documented as an associative array
     * but a YAML list slipped through the previous shape check. The
     * loader now rejects lists at this boundary too.
     */
    public function test_sample_metadata_must_be_associative_array_not_list(): void
    {
        $this->expectException(DatasetSchemaException::class);
        $this->expectExceptionMessage("'metadata' must be an associative array");

        $yaml = <<<'YAML'
        name: x
        samples:
          - id: s1
            input: {q: 1}
            expected_output: y
            metadata: [tag1, tag2]
        YAML;
        $this->loader()->loadString($yaml);
    }

    public function test_sample_input_empty_associative_is_accepted(): void
    {
        // `input: {}` is legitimate — a sample that takes no keyed
        // input. PHP's array_is_list returns true for [], so the
        // schema check special-cases empty arrays as associative.
        $yaml = <<<'YAML'
        name: x
        samples:
          - id: s1
            input: {}
            expected_output: y
        YAML;
        $parsed = $this->loader()->loadString($yaml);
        $this->assertSame([], $parsed->samples[0]->input);
    }
}
