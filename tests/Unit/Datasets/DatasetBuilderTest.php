<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Datasets;

use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Datasets\DatasetSchema;
use Padosoft\EvalHarness\EvalEngine;
use Padosoft\EvalHarness\Exceptions\DatasetSchemaException;
use Padosoft\EvalHarness\Tests\TestCase;

final class DatasetBuilderTest extends TestCase
{
    public function test_register_persists_dataset_on_engine(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);

        $dataset = $engine->dataset('rag.builder.test')
            ->withSamples([
                new DatasetSample(id: 'a', input: ['question' => 'hi'], expectedOutput: 'hello'),
                new DatasetSample(id: 'b', input: ['question' => 'bye'], expectedOutput: 'goodbye'),
            ])
            ->withMetrics(['exact-match'])
            ->register();

        $this->assertTrue($engine->hasDataset('rag.builder.test'));
        $this->assertSame('rag.builder.test', $dataset->name);
        $this->assertSame(2, $dataset->sampleCount());
        $this->assertSame(['exact-match'], $dataset->metricNames());
        $this->assertSame(DatasetSchema::VERSION, $dataset->schemaVersion);
    }

    public function test_register_without_samples_throws(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);

        $this->expectException(DatasetSchemaException::class);
        $this->expectExceptionMessage('no samples');

        $engine->dataset('no-samples')
            ->withMetrics(['exact-match'])
            ->register();
    }

    public function test_register_without_metrics_throws(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);

        $this->expectException(DatasetSchemaException::class);
        $this->expectExceptionMessage('no metrics');

        $engine->dataset('no-metrics')
            ->withSamples([new DatasetSample(id: 's1', input: [], expectedOutput: 'x')])
            ->register();
    }

    public function test_with_samples_rejects_empty(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);

        $this->expectException(DatasetSchemaException::class);
        $this->expectExceptionMessage('at least one DatasetSample');

        $engine->dataset('empty')->withSamples([]);
    }

    public function test_with_samples_rejects_duplicate_ids(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);

        $this->expectException(DatasetSchemaException::class);
        $this->expectExceptionMessage("Duplicate sample id 'dup'");

        $engine->dataset('dup')->withSamples([
            new DatasetSample(id: 'dup', input: [], expectedOutput: 'a'),
            new DatasetSample(id: 'dup', input: [], expectedOutput: 'b'),
        ]);
    }

    public function test_with_metrics_rejects_empty(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);

        $this->expectException(DatasetSchemaException::class);
        $this->expectExceptionMessage('at least one metric');

        $engine->dataset('empty-metrics')->withMetrics([]);
    }

    public function test_load_from_yaml_string_path(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);

        $yaml = <<<'YAML'
        name: yaml.builder
        samples:
          - id: s1
            input: {q: hello}
            expected_output: hi
        YAML;

        $dataset = $engine->dataset('yaml.builder')
            ->loadFromYamlString($yaml)
            ->withMetrics(['exact-match'])
            ->register();

        $this->assertSame('yaml.builder', $dataset->name);
        $this->assertSame(1, $dataset->sampleCount());
        $this->assertSame(DatasetSchema::VERSION, $dataset->schemaVersion);
        $this->assertTrue($engine->hasDataset('yaml.builder'));
    }

    public function test_cannot_mix_yaml_and_programmatic_sample_sources(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);

        $yaml = <<<'YAML'
        name: mixed.source
        samples:
          - id: s1
            input: {q: hello}
            expected_output: hi
        YAML;

        $this->expectException(DatasetSchemaException::class);
        $this->expectExceptionMessage('Do not combine loadFromYaml() or loadFromYamlString() with withSamples()');

        $engine->dataset('mixed.source')
            ->loadFromYamlString($yaml)
            ->withSamples([new DatasetSample(id: 's2', input: [], expectedOutput: 'x')]);
    }

    public function test_cannot_mix_programmatic_and_yaml_sample_sources(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);

        $this->expectException(DatasetSchemaException::class);
        $this->expectExceptionMessage('Do not combine withSamples() with loadFromYaml() or loadFromYamlString()');

        $engine->dataset('mixed.source.reverse')
            ->withSamples([new DatasetSample(id: 's1', input: [], expectedOutput: 'x')])
            ->loadFromYamlString('name: mixed.source.reverse');
    }

    public function test_cannot_mix_programmatic_and_file_yaml_sample_sources(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);

        $tmp = tempnam(sys_get_temp_dir(), 'eval-yaml-');
        $this->assertNotFalse($tmp);
        file_put_contents(
            $tmp,
            "name: mixed.file.source\nsamples:\n  - id: s1\n    input: {q: 1}\n    expected_output: y\n",
        );

        try {
            $this->expectException(DatasetSchemaException::class);
            $this->expectExceptionMessage('Do not combine withSamples() with loadFromYaml() or loadFromYamlString()');

            $engine->dataset('mixed.file.source')
                ->withSamples([new DatasetSample(id: 's1', input: [], expectedOutput: 'x')])
                ->loadFromYaml($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_yaml_sample_source_can_be_replaced_before_register(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);

        $firstYaml = <<<'YAML'
        name: replace.yaml
        samples:
          - id: old
            input: {q: old}
            expected_output: old
        YAML;

        $secondYaml = <<<'YAML'
        name: replace.yaml
        samples:
          - id: new
            input: {q: new}
            expected_output: new
        YAML;

        $dataset = $engine->dataset('replace.yaml')
            ->loadFromYamlString($firstYaml)
            ->loadFromYamlString($secondYaml)
            ->withMetrics(['exact-match'])
            ->register();

        $this->assertSame('new', $dataset->samples[0]->id);
    }

    public function test_programmatic_sample_source_can_be_replaced_before_register(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);

        $dataset = $engine->dataset('replace.samples')
            ->withSamples([new DatasetSample(id: 'old', input: [], expectedOutput: 'old')])
            ->withSamples([new DatasetSample(id: 'new', input: [], expectedOutput: 'new')])
            ->withMetrics(['exact-match'])
            ->register();

        $this->assertSame('new', $dataset->samples[0]->id);
    }

    /**
     * Regression: passing an array with non-DatasetSample entries to
     * withSamples() previously crashed with a generic Error on
     * `$sample->id`. The public API now surfaces a typed
     * DatasetSchemaException with the offending index named.
     */
    public function test_with_samples_rejects_non_dataset_sample_entries(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);

        $this->expectException(DatasetSchemaException::class);
        $this->expectExceptionMessage('must be a Padosoft\\EvalHarness\\Datasets\\DatasetSample instance');

        /** @phpstan-ignore-next-line — the test asserts behaviour on bad input shape. */
        $engine->dataset('bad-samples')->withSamples([
            new DatasetSample(id: 'good', input: [], expectedOutput: 'a'),
            ['id' => 'array-not-instance'],
        ]);
    }

    /**
     * Regression: when YAML's `name:` field doesn't match the builder
     * name, the previous code silently registered the dataset under
     * the YAML name, breaking `engine->run($builderName, ...)`. The
     * builder now surfaces the mismatch as a schema error.
     */
    public function test_register_rejects_yaml_name_mismatch(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);

        $this->expectException(DatasetSchemaException::class);
        $this->expectExceptionMessage('Dataset name mismatch');

        $yaml = <<<'YAML'
        name: yaml.has.different.name
        samples:
          - id: s1
            input: {q: hello}
            expected_output: hi
        YAML;

        $engine->dataset('caller.passed.this.name')
            ->loadFromYamlString($yaml)
            ->withMetrics(['exact-match'])
            ->register();
    }
}
