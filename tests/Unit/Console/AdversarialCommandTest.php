<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Console;

use Illuminate\Support\Facades\Artisan;
use Padosoft\EvalHarness\Adversarial\AdversarialDatasetFactory;
use Padosoft\EvalHarness\Adversarial\AdversarialRunManifestStore;
use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Datasets\YamlDatasetLoader;
use Padosoft\EvalHarness\EvalEngine;
use Padosoft\EvalHarness\Metrics\MetricScore;
use Padosoft\EvalHarness\Reports\EvalReport;
use Padosoft\EvalHarness\Reports\SampleResult;
use Padosoft\EvalHarness\Tests\TestCase;

final class AdversarialCommandTest extends TestCase
{
    public function test_adversarial_command_help_mentions_compatible_regression_baseline(): void
    {
        $this->artisan('help', ['command_name' => 'eval-harness:adversarial'])
            ->expectsOutputToContain('Compare this run with the latest compatible failure-free --manifest baseline and fail on score drops')
            ->assertExitCode(0);
    }

    public function test_outputs_warns_when_batch_flags_are_passed(): void
    {
        $sample = $this->adversarialSample('prompt-injection');
        $outputs = tempnam(sys_get_temp_dir(), 'eval-adv-outputs-');
        $report = tempnam(sys_get_temp_dir(), 'eval-adv-report-');
        $this->assertNotFalse($outputs);
        $this->assertNotFalse($report);
        $this->assertIsString($sample->expectedOutput);

        try {
            file_put_contents($outputs, json_encode([
                'outputs' => [
                    $sample->id => $sample->expectedOutput,
                ],
            ], JSON_THROW_ON_ERROR));

            // --outputs bypasses the batch dispatch path on the
            // adversarial command too. The runtime warning is the
            // safety net catching --batch-profile / --rate-limit
            // typos in saved-output flows.
            $exit = Artisan::call('eval-harness:adversarial', [
                '--category' => ['prompt-injection'],
                '--metric' => ['exact-match'],
                '--outputs' => $outputs,
                '--batch-profile' => 'ci',
                '--rate-limit' => '5',
                '--json' => true,
                '--out' => $report,
            ]);
            $output = Artisan::output();

            $this->assertSame(0, $exit, 'Saved-output run with extra batch flags must still exit 0; got output: '.$output);
            $this->assertStringContainsString('Ignoring batch flags', $output);
            $this->assertStringContainsString('--batch-profile', $output);
            $this->assertStringContainsString('--rate-limit', $output);
            $this->assertStringContainsString('--outputs is set', $output);
        } finally {
            @unlink($outputs);
            @unlink($report);
        }
    }

    public function test_outputs_does_not_warn_when_no_batch_flags_passed(): void
    {
        $sample = $this->adversarialSample('prompt-injection');
        $outputs = tempnam(sys_get_temp_dir(), 'eval-adv-outputs-');
        $report = tempnam(sys_get_temp_dir(), 'eval-adv-report-');
        $this->assertNotFalse($outputs);
        $this->assertNotFalse($report);
        $this->assertIsString($sample->expectedOutput);

        try {
            file_put_contents($outputs, json_encode([
                'outputs' => [
                    $sample->id => $sample->expectedOutput,
                ],
            ], JSON_THROW_ON_ERROR));

            $exit = Artisan::call('eval-harness:adversarial', [
                '--category' => ['prompt-injection'],
                '--metric' => ['exact-match'],
                '--outputs' => $outputs,
                '--json' => true,
                '--out' => $report,
            ]);
            $output = Artisan::output();

            $this->assertSame(0, $exit);
            $this->assertStringNotContainsString('Ignoring batch flags', $output);
        } finally {
            @unlink($outputs);
            @unlink($report);
        }
    }

    public function test_scores_selected_adversarial_category_saved_outputs_without_sut(): void
    {
        $sample = $this->adversarialSample('prompt-injection');
        $outputs = tempnam(sys_get_temp_dir(), 'eval-adv-outputs-');
        $report = tempnam(sys_get_temp_dir(), 'eval-adv-report-');
        $this->assertNotFalse($outputs);
        $this->assertNotFalse($report);
        $this->assertIsString($sample->expectedOutput);

        try {
            file_put_contents($outputs, json_encode([
                'outputs' => [
                    $sample->id => $sample->expectedOutput,
                ],
            ], JSON_THROW_ON_ERROR));

            $this->artisan('eval-harness:adversarial', [
                '--category' => ['prompt-injection'],
                '--metric' => ['exact-match'],
                '--outputs' => $outputs,
                '--json' => true,
                '--out' => $report,
            ])->assertExitCode(0);

            $decoded = json_decode((string) file_get_contents($report), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(AdversarialDatasetFactory::DEFAULT_DATASET_NAME, $decoded['dataset']);
            $this->assertSame('adv.prompt-injection', $decoded['samples'][0]['id']);
            $this->assertSame(['adversarial', 'prompt-injection'], $decoded['samples'][0]['tags']);
            $this->assertSame($sample->expectedOutput, $decoded['samples'][0]['actual_output']);
            $this->assertEqualsWithDelta(1.0, $decoded['metrics']['exact-match']['mean'], 1e-9);
        } finally {
            @unlink($outputs);
            @unlink($report);
        }
    }

    public function test_eval_adversarial_alias_scores_saved_outputs(): void
    {
        $sample = $this->adversarialSample('pii-leak');
        $outputs = tempnam(sys_get_temp_dir(), 'eval-adv-outputs-');
        $report = tempnam(sys_get_temp_dir(), 'eval-adv-report-');
        $this->assertNotFalse($outputs);
        $this->assertNotFalse($report);
        $this->assertIsString($sample->expectedOutput);

        try {
            file_put_contents($outputs, json_encode([
                'outputs' => [
                    $sample->id => $sample->expectedOutput,
                ],
            ], JSON_THROW_ON_ERROR));

            $this->artisan('eval:adversarial', [
                '--category' => ['pii-leak'],
                '--metric' => ['exact-match'],
                '--outputs' => $outputs,
                '--json' => true,
                '--out' => $report,
            ])->assertExitCode(0);

            $decoded = json_decode((string) file_get_contents($report), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('adv.pii-leak', $decoded['samples'][0]['id']);
        } finally {
            @unlink($outputs);
            @unlink($report);
        }
    }

    public function test_scores_saved_outputs_and_records_manifest_when_requested(): void
    {
        $sample = $this->adversarialSample('ssrf');
        $outputs = tempnam(sys_get_temp_dir(), 'eval-adv-outputs-');
        $manifest = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eval-adv-manifest-'.uniqid('', true).'.json';
        $report = tempnam(sys_get_temp_dir(), 'eval-adv-report-');
        $this->assertNotFalse($outputs);
        $this->assertNotFalse($report);
        $this->assertIsString($sample->expectedOutput);

        try {
            file_put_contents($outputs, json_encode([
                'outputs' => [
                    $sample->id => $sample->expectedOutput,
                ],
            ], JSON_THROW_ON_ERROR));

            $this->artisan('eval-harness:adversarial', [
                '--category' => ['ssrf'],
                '--metric' => ['exact-match'],
                '--outputs' => $outputs,
                '--manifest' => $manifest,
                '--manifest-retain' => '1',
                '--json' => true,
                '--out' => $report,
            ])->assertExitCode(0);

            $decoded = json_decode((string) file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('eval-harness.adversarial-runs.v1', $decoded['schema_version']);
            $this->assertSame(AdversarialDatasetFactory::DEFAULT_DATASET_NAME, $decoded['manifest']);
            $this->assertCount(1, $decoded['runs']);
            $this->assertSame(AdversarialDatasetFactory::DEFAULT_DATASET_NAME, $decoded['runs'][0]['dataset']);
            $this->assertSame(1, $decoded['runs'][0]['adversarial']['total_samples']);
            $this->assertSame('ssrf', $decoded['runs'][0]['adversarial']['categories'][0]['category']);
        } finally {
            @unlink($outputs);
            @unlink($manifest);
            @unlink($manifest.'.lock');
            @unlink($report);
        }
    }

    public function test_manifest_retention_option_must_be_positive(): void
    {
        $sample = $this->adversarialSample('ssrf');
        $outputs = tempnam(sys_get_temp_dir(), 'eval-adv-outputs-');
        $manifest = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eval-adv-manifest-'.uniqid('', true).'.json';
        $this->assertNotFalse($outputs);
        $this->assertIsString($sample->expectedOutput);

        try {
            file_put_contents($outputs, json_encode([
                'outputs' => [
                    $sample->id => $sample->expectedOutput,
                ],
            ], JSON_THROW_ON_ERROR));

            $this->artisan('eval-harness:adversarial', [
                '--category' => ['ssrf'],
                '--metric' => ['exact-match'],
                '--outputs' => $outputs,
                '--manifest' => $manifest,
                '--manifest-retain' => '0',
            ])
                ->expectsOutputToContain('The --manifest-retain option must be a positive integer.')
                ->assertExitCode(1);
        } finally {
            @unlink($outputs);
            @unlink($manifest);
            @unlink($manifest.'.lock');
        }
    }

    public function test_manifest_retention_option_rejects_empty_value_before_running(): void
    {
        $factory = $this->app->make(AdversarialDatasetFactory::class);
        $engine = $this->app->make(EvalEngine::class);
        $engine->registerDataset($factory->build(
            name: AdversarialDatasetFactory::DEFAULT_DATASET_NAME,
            categories: ['pii-leak'],
            metricSpecs: ['exact-match'],
        ));

        $sample = $this->adversarialSample('ssrf');
        $outputs = tempnam(sys_get_temp_dir(), 'eval-adv-outputs-');
        $manifest = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eval-adv-manifest-'.uniqid('', true).'.json';
        $this->assertNotFalse($outputs);
        $this->assertIsString($sample->expectedOutput);

        try {
            file_put_contents($outputs, json_encode([
                'outputs' => [
                    $sample->id => $sample->expectedOutput,
                ],
            ], JSON_THROW_ON_ERROR));

            $this->artisan('eval-harness:adversarial', [
                '--category' => ['ssrf'],
                '--metric' => ['exact-match'],
                '--outputs' => $outputs,
                '--manifest' => $manifest,
                '--manifest-retain' => '',
            ])
                ->expectsOutputToContain('The --manifest-retain option must be a positive integer.')
                ->assertExitCode(1);

            $registered = $engine->getDataset(AdversarialDatasetFactory::DEFAULT_DATASET_NAME);
            $this->assertSame('adv.pii-leak', $registered->samples[0]->id);
            $this->assertFileDoesNotExist($manifest);
        } finally {
            @unlink($outputs);
            @unlink($manifest);
            @unlink($manifest.'.lock');
        }
    }

    public function test_manifest_retention_option_rejects_empty_value_without_manifest(): void
    {
        $factory = $this->app->make(AdversarialDatasetFactory::class);
        $engine = $this->app->make(EvalEngine::class);
        $engine->registerDataset($factory->build(
            name: AdversarialDatasetFactory::DEFAULT_DATASET_NAME,
            categories: ['pii-leak'],
            metricSpecs: ['exact-match'],
        ));

        $sample = $this->adversarialSample('ssrf');
        $outputs = tempnam(sys_get_temp_dir(), 'eval-adv-outputs-');
        $this->assertNotFalse($outputs);
        $this->assertIsString($sample->expectedOutput);

        try {
            file_put_contents($outputs, json_encode([
                'outputs' => [
                    $sample->id => $sample->expectedOutput,
                ],
            ], JSON_THROW_ON_ERROR));

            $this->artisan('eval-harness:adversarial', [
                '--category' => ['ssrf'],
                '--metric' => ['exact-match'],
                '--outputs' => $outputs,
                '--manifest-retain' => '',
            ])
                ->expectsOutputToContain('The --manifest-retain option must be a positive integer.')
                ->assertExitCode(1);

            $registered = $engine->getDataset(AdversarialDatasetFactory::DEFAULT_DATASET_NAME);
            $this->assertSame('adv.pii-leak', $registered->samples[0]->id);
        } finally {
            @unlink($outputs);
        }
    }

    public function test_manifest_retention_option_requires_manifest_or_regression_gate(): void
    {
        $sample = $this->adversarialSample('ssrf');
        $outputs = tempnam(sys_get_temp_dir(), 'eval-adv-outputs-');
        $this->assertNotFalse($outputs);
        $this->assertIsString($sample->expectedOutput);

        try {
            file_put_contents($outputs, json_encode([
                'outputs' => [
                    $sample->id => $sample->expectedOutput,
                ],
            ], JSON_THROW_ON_ERROR));

            $this->artisan('eval-harness:adversarial', [
                '--category' => ['ssrf'],
                '--metric' => ['exact-match'],
                '--outputs' => $outputs,
                '--manifest-retain' => '2',
            ])
                ->expectsOutputToContain('The --manifest-retain option requires --manifest or --regression-gate.')
                ->assertExitCode(1);
        } finally {
            @unlink($outputs);
        }
    }

    public function test_regression_gate_requires_manifest_path(): void
    {
        $sample = $this->adversarialSample('ssrf');
        $outputs = tempnam(sys_get_temp_dir(), 'eval-adv-outputs-');
        $this->assertNotFalse($outputs);
        $this->assertIsString($sample->expectedOutput);

        try {
            file_put_contents($outputs, json_encode([
                'outputs' => [
                    $sample->id => $sample->expectedOutput,
                ],
            ], JSON_THROW_ON_ERROR));

            $this->artisan('eval-harness:adversarial', [
                '--category' => ['ssrf'],
                '--metric' => ['exact-match'],
                '--outputs' => $outputs,
                '--regression-gate' => true,
            ])
                ->expectsOutputToContain('The --regression-gate option requires --manifest=<path> so the current run can compare with or seed a compatible baseline.')
                ->assertExitCode(1);
        } finally {
            @unlink($outputs);
        }
    }

    public function test_regression_gate_rejects_empty_manifest_retention_before_running(): void
    {
        $sample = $this->adversarialSample('ssrf');
        $outputs = tempnam(sys_get_temp_dir(), 'eval-adv-outputs-');
        $manifest = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eval-adv-manifest-'.uniqid('', true).'.json';
        $this->assertNotFalse($outputs);
        $this->assertIsString($sample->expectedOutput);

        try {
            file_put_contents($outputs, json_encode([
                'outputs' => [
                    $sample->id => $sample->expectedOutput,
                ],
            ], JSON_THROW_ON_ERROR));

            $this->artisan('eval-harness:adversarial', [
                '--category' => ['ssrf'],
                '--metric' => ['exact-match'],
                '--outputs' => $outputs,
                '--manifest' => $manifest,
                '--manifest-retain' => '',
                '--regression-gate' => true,
            ])
                ->expectsOutputToContain('The --manifest-retain option must be a positive integer.')
                ->assertExitCode(1);

            $this->assertFileDoesNotExist($manifest);
        } finally {
            @unlink($outputs);
            @unlink($manifest);
            @unlink($manifest.'.lock');
        }
    }

    public function test_regression_gate_rejects_empty_manifest_path_before_running(): void
    {
        $sample = $this->adversarialSample('ssrf');
        $outputs = tempnam(sys_get_temp_dir(), 'eval-adv-outputs-');
        $this->assertNotFalse($outputs);
        $this->assertIsString($sample->expectedOutput);

        try {
            file_put_contents($outputs, json_encode([
                'outputs' => [
                    $sample->id => $sample->expectedOutput,
                ],
            ], JSON_THROW_ON_ERROR));

            $this->artisan('eval-harness:adversarial', [
                '--category' => ['ssrf'],
                '--metric' => ['exact-match'],
                '--outputs' => $outputs,
                '--manifest' => '',
                '--regression-gate' => true,
            ])
                ->expectsOutputToContain('The --manifest option requires a non-empty file path without leading or trailing whitespace.')
                ->assertExitCode(1);
        } finally {
            @unlink($outputs);
        }
    }

    public function test_regression_gate_rejects_directory_manifest_path_before_running(): void
    {
        $factory = $this->app->make(AdversarialDatasetFactory::class);
        $engine = $this->app->make(EvalEngine::class);
        $engine->registerDataset($factory->build(
            name: AdversarialDatasetFactory::DEFAULT_DATASET_NAME,
            categories: ['pii-leak'],
            metricSpecs: ['exact-match'],
        ));

        $sample = $this->adversarialSample('ssrf');
        $outputs = tempnam(sys_get_temp_dir(), 'eval-adv-outputs-');
        $manifest = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eval-adv-manifest-dir-'.uniqid('', true);
        $this->assertNotFalse($outputs);
        $this->assertIsString($sample->expectedOutput);
        mkdir($manifest);

        try {
            file_put_contents($outputs, json_encode([
                'outputs' => [
                    $sample->id => $sample->expectedOutput,
                ],
            ], JSON_THROW_ON_ERROR));

            $this->artisan('eval-harness:adversarial', [
                '--category' => ['ssrf'],
                '--metric' => ['exact-match'],
                '--outputs' => $outputs,
                '--manifest' => $manifest,
                '--regression-gate' => true,
            ])
                ->expectsOutputToContain('The --manifest option must point to a JSON file path, not a directory path.')
                ->assertExitCode(1);

            $registered = $engine->getDataset(AdversarialDatasetFactory::DEFAULT_DATASET_NAME);
            $this->assertSame('adv.pii-leak', $registered->samples[0]->id);
            $this->assertFileDoesNotExist($manifest.'.lock');
        } finally {
            @unlink($outputs);
            @unlink($manifest.'.lock');
            if (is_dir($manifest)) {
                @rmdir($manifest);
            }
        }
    }

    public function test_regression_gate_rejects_directory_shaped_manifest_path_before_running(): void
    {
        $factory = $this->app->make(AdversarialDatasetFactory::class);
        $engine = $this->app->make(EvalEngine::class);
        $engine->registerDataset($factory->build(
            name: AdversarialDatasetFactory::DEFAULT_DATASET_NAME,
            categories: ['pii-leak'],
            metricSpecs: ['exact-match'],
        ));

        $sample = $this->adversarialSample('ssrf');
        $outputs = tempnam(sys_get_temp_dir(), 'eval-adv-outputs-');
        $manifestDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eval-adv-manifest-dir-shaped-'.uniqid('', true);
        $manifest = $manifestDirectory.DIRECTORY_SEPARATOR;
        $this->assertNotFalse($outputs);
        $this->assertIsString($sample->expectedOutput);

        try {
            file_put_contents($outputs, json_encode([
                'outputs' => [
                    $sample->id => $sample->expectedOutput,
                ],
            ], JSON_THROW_ON_ERROR));

            $this->artisan('eval-harness:adversarial', [
                '--category' => ['ssrf'],
                '--metric' => ['exact-match'],
                '--outputs' => $outputs,
                '--manifest' => $manifest,
                '--regression-gate' => true,
            ])
                ->expectsOutputToContain('The --manifest option must point to a JSON file path, not a directory path.')
                ->assertExitCode(1);

            $registered = $engine->getDataset(AdversarialDatasetFactory::DEFAULT_DATASET_NAME);
            $this->assertSame('adv.pii-leak', $registered->samples[0]->id);
            $this->assertDirectoryDoesNotExist($manifestDirectory);
            $this->assertFileDoesNotExist($manifest.'.lock');
        } finally {
            @unlink($outputs);
            @unlink($manifest.'.lock');
            if (is_dir($manifestDirectory)) {
                @rmdir($manifestDirectory);
            }
        }
    }

    public function test_regression_gate_rejects_padded_manifest_path_before_running(): void
    {
        $sample = $this->adversarialSample('ssrf');
        $outputs = tempnam(sys_get_temp_dir(), 'eval-adv-outputs-');
        $manifest = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eval-adv-manifest-'.uniqid('', true).'.json';
        $this->assertNotFalse($outputs);
        $this->assertIsString($sample->expectedOutput);

        try {
            file_put_contents($outputs, json_encode([
                'outputs' => [
                    $sample->id => $sample->expectedOutput,
                ],
            ], JSON_THROW_ON_ERROR));

            $this->artisan('eval-harness:adversarial', [
                '--category' => ['ssrf'],
                '--metric' => ['exact-match'],
                '--outputs' => $outputs,
                '--manifest' => ' '.$manifest.' ',
                '--regression-gate' => true,
            ])
                ->expectsOutputToContain('The --manifest option requires a non-empty file path without leading or trailing whitespace.')
                ->assertExitCode(1);

            $this->assertFileDoesNotExist($manifest);
        } finally {
            @unlink($outputs);
            @unlink($manifest);
            @unlink($manifest.'.lock');
        }
    }

    public function test_manifest_rejects_padded_path_before_running_without_regression_gate(): void
    {
        $sample = $this->adversarialSample('ssrf');
        $outputs = tempnam(sys_get_temp_dir(), 'eval-adv-outputs-');
        $manifest = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eval-adv-manifest-'.uniqid('', true).'.json';
        $this->assertNotFalse($outputs);
        $this->assertIsString($sample->expectedOutput);

        try {
            file_put_contents($outputs, json_encode([
                'outputs' => [
                    $sample->id => $sample->expectedOutput,
                ],
            ], JSON_THROW_ON_ERROR));

            $this->artisan('eval-harness:adversarial', [
                '--category' => ['ssrf'],
                '--metric' => ['exact-match'],
                '--outputs' => $outputs,
                '--manifest' => ' '.$manifest.' ',
            ])
                ->expectsOutputToContain('The --manifest option requires a non-empty file path without leading or trailing whitespace.')
                ->assertExitCode(1);

            $this->assertFileDoesNotExist($manifest);
        } finally {
            @unlink($outputs);
            @unlink($manifest);
            @unlink($manifest.'.lock');
        }
    }

    public function test_manifest_rejects_directory_path_before_running_without_regression_gate(): void
    {
        $factory = $this->app->make(AdversarialDatasetFactory::class);
        $engine = $this->app->make(EvalEngine::class);
        $engine->registerDataset($factory->build(
            name: AdversarialDatasetFactory::DEFAULT_DATASET_NAME,
            categories: ['pii-leak'],
            metricSpecs: ['exact-match'],
        ));

        $sample = $this->adversarialSample('ssrf');
        $outputs = tempnam(sys_get_temp_dir(), 'eval-adv-outputs-');
        $manifest = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eval-adv-manifest-dir-'.uniqid('', true);
        $this->assertNotFalse($outputs);
        $this->assertIsString($sample->expectedOutput);
        mkdir($manifest);

        try {
            file_put_contents($outputs, json_encode([
                'outputs' => [
                    $sample->id => $sample->expectedOutput,
                ],
            ], JSON_THROW_ON_ERROR));

            $this->artisan('eval-harness:adversarial', [
                '--category' => ['ssrf'],
                '--metric' => ['exact-match'],
                '--outputs' => $outputs,
                '--manifest' => $manifest,
            ])
                ->expectsOutputToContain('The --manifest option must point to a JSON file path, not a directory path.')
                ->assertExitCode(1);

            $registered = $engine->getDataset(AdversarialDatasetFactory::DEFAULT_DATASET_NAME);
            $this->assertSame('adv.pii-leak', $registered->samples[0]->id);
            $this->assertFileDoesNotExist($manifest.'.lock');
        } finally {
            @unlink($outputs);
            @unlink($manifest.'.lock');
            if (is_dir($manifest)) {
                @rmdir($manifest);
            }
        }
    }

    public function test_manifest_rejects_directory_shaped_path_before_running_without_regression_gate(): void
    {
        $factory = $this->app->make(AdversarialDatasetFactory::class);
        $engine = $this->app->make(EvalEngine::class);
        $engine->registerDataset($factory->build(
            name: AdversarialDatasetFactory::DEFAULT_DATASET_NAME,
            categories: ['pii-leak'],
            metricSpecs: ['exact-match'],
        ));

        $sample = $this->adversarialSample('ssrf');
        $outputs = tempnam(sys_get_temp_dir(), 'eval-adv-outputs-');
        $manifestDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eval-adv-manifest-dir-shaped-'.uniqid('', true);
        $manifest = $manifestDirectory.DIRECTORY_SEPARATOR;
        $this->assertNotFalse($outputs);
        $this->assertIsString($sample->expectedOutput);

        try {
            file_put_contents($outputs, json_encode([
                'outputs' => [
                    $sample->id => $sample->expectedOutput,
                ],
            ], JSON_THROW_ON_ERROR));

            $this->artisan('eval-harness:adversarial', [
                '--category' => ['ssrf'],
                '--metric' => ['exact-match'],
                '--outputs' => $outputs,
                '--manifest' => $manifest,
            ])
                ->expectsOutputToContain('The --manifest option must point to a JSON file path, not a directory path.')
                ->assertExitCode(1);

            $registered = $engine->getDataset(AdversarialDatasetFactory::DEFAULT_DATASET_NAME);
            $this->assertSame('adv.pii-leak', $registered->samples[0]->id);
            $this->assertDirectoryDoesNotExist($manifestDirectory);
            $this->assertFileDoesNotExist($manifest.'.lock');
        } finally {
            @unlink($outputs);
            @unlink($manifest.'.lock');
            if (is_dir($manifestDirectory)) {
                @rmdir($manifestDirectory);
            }
        }
    }

    public function test_manifest_preflight_does_not_replace_registered_dataset(): void
    {
        $factory = $this->app->make(AdversarialDatasetFactory::class);
        $engine = $this->app->make(EvalEngine::class);
        $engine->registerDataset($factory->build(
            name: AdversarialDatasetFactory::DEFAULT_DATASET_NAME,
            categories: ['pii-leak'],
            metricSpecs: ['exact-match'],
        ));

        $sample = $this->adversarialSample('ssrf');
        $outputs = tempnam(sys_get_temp_dir(), 'eval-adv-outputs-');
        $manifest = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eval-adv-manifest-'.uniqid('', true).'.json';
        $this->assertNotFalse($outputs);
        $this->assertIsString($sample->expectedOutput);

        try {
            file_put_contents($outputs, json_encode([
                'outputs' => [
                    $sample->id => $sample->expectedOutput,
                ],
            ], JSON_THROW_ON_ERROR));

            $this->artisan('eval-harness:adversarial', [
                '--category' => ['ssrf'],
                '--metric' => ['exact-match'],
                '--outputs' => $outputs,
                '--manifest' => ' '.$manifest.' ',
            ])
                ->expectsOutputToContain('The --manifest option requires a non-empty file path without leading or trailing whitespace.')
                ->assertExitCode(1);

            $registered = $engine->getDataset(AdversarialDatasetFactory::DEFAULT_DATASET_NAME);
            $this->assertSame('adv.pii-leak', $registered->samples[0]->id);
            $this->assertSame(['exact-match'], $registered->metricNames());
            $this->assertFileDoesNotExist($manifest);
        } finally {
            @unlink($outputs);
            @unlink($manifest);
            @unlink($manifest.'.lock');
        }
    }

    public function test_regression_gate_missing_baseline_is_explicit_and_non_failing(): void
    {
        $sample = $this->adversarialSample('ssrf');
        $outputs = tempnam(sys_get_temp_dir(), 'eval-adv-outputs-');
        $manifest = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eval-adv-manifest-'.uniqid('', true).'.json';
        $report = tempnam(sys_get_temp_dir(), 'eval-adv-report-');
        $this->assertNotFalse($outputs);
        $this->assertNotFalse($report);
        $this->assertIsString($sample->expectedOutput);

        try {
            file_put_contents($outputs, json_encode([
                'outputs' => [
                    $sample->id => $sample->expectedOutput,
                ],
            ], JSON_THROW_ON_ERROR));

            $this->artisan('eval-harness:adversarial', [
                '--category' => ['ssrf'],
                '--metric' => ['exact-match'],
                '--outputs' => $outputs,
                '--manifest' => $manifest,
                '--regression-gate' => true,
                '--json' => true,
                '--out' => $report,
            ])
                ->expectsOutputToContain('Adversarial regression gate: missing-baseline - no compatible failure-free manifest baseline; current run will be recorded for future comparisons.')
                ->assertExitCode(0);

            $decoded = json_decode((string) file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
            $this->assertCount(1, $decoded['runs']);
            $this->assertEqualsWithDelta(1.0, $decoded['runs'][0]['macro_f1'], 1e-9);
        } finally {
            @unlink($outputs);
            @unlink($manifest);
            @unlink($manifest.'.lock');
            @unlink($report);
        }
    }

    public function test_regression_gate_missing_baseline_with_metric_failures_is_not_recorded(): void
    {
        $sample = $this->adversarialSample('ssrf');
        $outputs = tempnam(sys_get_temp_dir(), 'eval-adv-outputs-');
        $manifest = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eval-adv-manifest-'.uniqid('', true).'.json';
        $report = tempnam(sys_get_temp_dir(), 'eval-adv-report-');
        $this->assertNotFalse($outputs);
        $this->assertNotFalse($report);
        $this->assertIsString($sample->expectedOutput);

        try {
            file_put_contents($outputs, json_encode([
                'outputs' => [
                    $sample->id => $sample->expectedOutput,
                ],
            ], JSON_THROW_ON_ERROR));

            $this->artisan('eval-harness:adversarial', [
                '--category' => ['ssrf'],
                '--metric' => ['citation-groundedness'],
                '--outputs' => $outputs,
                '--manifest' => $manifest,
                '--regression-gate' => true,
                '--json' => true,
                '--out' => $report,
            ])
                ->expectsOutputToContain('Adversarial regression gate: missing-baseline - no compatible failure-free manifest baseline; current run has metric failures and was not recorded for future comparisons.')
                ->assertExitCode(1);

            $this->assertFileDoesNotExist($manifest);
        } finally {
            @unlink($outputs);
            @unlink($manifest);
            @unlink($manifest.'.lock');
            @unlink($report);
        }
    }

    public function test_regression_gate_pass_reports_recorded_run(): void
    {
        $sample = $this->adversarialSample('ssrf');
        $outputs = tempnam(sys_get_temp_dir(), 'eval-adv-outputs-');
        $manifest = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eval-adv-manifest-'.uniqid('', true).'.json';
        $report = tempnam(sys_get_temp_dir(), 'eval-adv-report-');
        $this->assertNotFalse($outputs);
        $this->assertNotFalse($report);
        $this->assertIsString($sample->expectedOutput);

        try {
            (new AdversarialRunManifestStore)->record(
                path: $manifest,
                report: new EvalReport(
                    datasetName: AdversarialDatasetFactory::DEFAULT_DATASET_NAME,
                    sampleResults: [
                        new SampleResult(
                            sample: $sample,
                            actualOutput: $sample->expectedOutput,
                            metricScores: ['exact-match' => new MetricScore(1.0)],
                        ),
                    ],
                    failures: [],
                    startedAt: 1.0,
                    finishedAt: 2.0,
                ),
                maxRuns: 2,
                runId: 'run-baseline',
            );

            file_put_contents($outputs, json_encode([
                'outputs' => [
                    $sample->id => $sample->expectedOutput,
                ],
            ], JSON_THROW_ON_ERROR));

            $this->artisan('eval-harness:adversarial', [
                '--category' => ['ssrf'],
                '--metric' => ['exact-match'],
                '--outputs' => $outputs,
                '--manifest' => $manifest,
                '--manifest-retain' => '2',
                '--regression-gate' => true,
                '--json' => true,
                '--out' => $report,
            ])
                ->expectsOutputToContain('Adversarial regression gate: pass - 1 check(s), max drop 5.00 percentage points.')
                ->assertExitCode(0);

            $decoded = json_decode((string) file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
            $this->assertCount(2, $decoded['runs']);
            $this->assertContains('run-baseline', array_column($decoded['runs'], 'run_id'));
        } finally {
            @unlink($outputs);
            @unlink($manifest);
            @unlink($manifest.'.lock');
            @unlink($report);
        }
    }

    public function test_regression_gate_pass_reports_when_retention_does_not_keep_current_run(): void
    {
        $sample = $this->adversarialSample('ssrf');
        $existingSample = $this->adversarialSample('ssrf');
        $outputs = tempnam(sys_get_temp_dir(), 'eval-adv-outputs-');
        $manifest = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eval-adv-manifest-'.uniqid('', true).'.json';
        $report = tempnam(sys_get_temp_dir(), 'eval-adv-report-');
        $this->assertNotFalse($outputs);
        $this->assertNotFalse($report);
        $this->assertIsString($sample->expectedOutput);
        $this->assertIsString($existingSample->expectedOutput);

        try {
            (new AdversarialRunManifestStore)->record(
                path: $manifest,
                report: new EvalReport(
                    datasetName: AdversarialDatasetFactory::DEFAULT_DATASET_NAME,
                    sampleResults: [
                        new SampleResult(
                            sample: $existingSample,
                            actualOutput: $existingSample->expectedOutput,
                            metricScores: ['exact-match' => new MetricScore(1.0)],
                        ),
                    ],
                    failures: [],
                    startedAt: microtime(true) + 3600.0,
                    finishedAt: microtime(true) + 3601.0,
                ),
                maxRuns: 1,
                runId: 'run-future-clean',
            );

            file_put_contents($outputs, json_encode([
                'outputs' => [
                    $sample->id => $sample->expectedOutput,
                ],
            ], JSON_THROW_ON_ERROR));

            $this->artisan('eval-harness:adversarial', [
                '--category' => ['ssrf'],
                '--metric' => ['exact-match'],
                '--outputs' => $outputs,
                '--manifest' => $manifest,
                '--manifest-retain' => '1',
                '--regression-gate' => true,
                '--json' => true,
                '--out' => $report,
            ])
                ->expectsOutputToContain('Adversarial regression gate: pass - score checks passed, but current run did not fit within manifest retention and was not recorded for future comparisons.')
                ->assertExitCode(0);

            $decoded = json_decode((string) file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(['run-future-clean'], array_column($decoded['runs'], 'run_id'));
        } finally {
            @unlink($outputs);
            @unlink($manifest);
            @unlink($manifest.'.lock');
            @unlink($report);
        }
    }

    public function test_regression_gate_pass_with_metric_failures_reports_non_recorded_run(): void
    {
        $sample = $this->adversarialSample('ssrf');
        $outputs = tempnam(sys_get_temp_dir(), 'eval-adv-outputs-');
        $manifest = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eval-adv-manifest-'.uniqid('', true).'.json';
        $report = tempnam(sys_get_temp_dir(), 'eval-adv-report-');
        $this->assertNotFalse($outputs);
        $this->assertNotFalse($report);
        $this->assertIsString($sample->expectedOutput);

        try {
            (new AdversarialRunManifestStore)->record(
                path: $manifest,
                report: new EvalReport(
                    datasetName: AdversarialDatasetFactory::DEFAULT_DATASET_NAME,
                    sampleResults: [
                        new SampleResult(
                            sample: $sample,
                            actualOutput: $sample->expectedOutput,
                            metricScores: [
                                'exact-match' => new MetricScore(1.0),
                                'citation-groundedness' => new MetricScore(1.0),
                            ],
                        ),
                    ],
                    failures: [],
                    startedAt: 1.0,
                    finishedAt: 2.0,
                ),
                maxRuns: 2,
                runId: 'run-baseline',
            );

            file_put_contents($outputs, json_encode([
                'outputs' => [
                    $sample->id => $sample->expectedOutput,
                ],
            ], JSON_THROW_ON_ERROR));

            $this->artisan('eval-harness:adversarial', [
                '--category' => ['ssrf'],
                '--metric' => ['exact-match', 'citation-groundedness'],
                '--outputs' => $outputs,
                '--manifest' => $manifest,
                '--regression-gate' => true,
                '--regression-max-drop' => '100',
                '--json' => true,
                '--out' => $report,
            ])
                ->expectsOutputToContain('Adversarial regression gate: pass - score checks passed, but current run has metric failures and was not recorded for future comparisons.')
                ->assertExitCode(1);

            $decoded = json_decode((string) file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(['run-baseline'], array_column($decoded['runs'], 'run_id'));
        } finally {
            @unlink($outputs);
            @unlink($manifest);
            @unlink($manifest.'.lock');
            @unlink($report);
        }
    }

    public function test_regression_gate_status_does_not_pollute_json_stdout(): void
    {
        $sample = $this->adversarialSample('ssrf');
        $outputs = tempnam(sys_get_temp_dir(), 'eval-adv-outputs-');
        $manifest = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eval-adv-manifest-'.uniqid('', true).'.json';
        $this->assertNotFalse($outputs);
        $this->assertIsString($sample->expectedOutput);

        try {
            file_put_contents($outputs, json_encode([
                'outputs' => [
                    $sample->id => $sample->expectedOutput,
                ],
            ], JSON_THROW_ON_ERROR));

            $this->artisan('eval-harness:adversarial', [
                '--category' => ['ssrf'],
                '--metric' => ['exact-match'],
                '--outputs' => $outputs,
                '--manifest' => $manifest,
                '--regression-gate' => true,
                '--json' => true,
            ])
                ->expectsOutputToContain('"dataset": "adversarial.security.v1"')
                ->doesntExpectOutputToContain('Adversarial regression gate:')
                ->assertExitCode(0);
        } finally {
            @unlink($outputs);
            @unlink($manifest);
            @unlink($manifest.'.lock');
        }
    }

    public function test_regression_gate_rejects_malformed_metric_target_before_running(): void
    {
        $sample = $this->adversarialSample('ssrf');
        $outputs = tempnam(sys_get_temp_dir(), 'eval-adv-outputs-');
        $manifest = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eval-adv-manifest-'.uniqid('', true).'.json';
        $this->assertNotFalse($outputs);
        $this->assertIsString($sample->expectedOutput);

        try {
            file_put_contents($outputs, json_encode([
                'outputs' => [
                    $sample->id => $sample->expectedOutput,
                ],
            ], JSON_THROW_ON_ERROR));

            $this->artisan('eval-harness:adversarial', [
                '--category' => ['ssrf'],
                '--metric' => ['exact-match'],
                '--outputs' => $outputs,
                '--manifest' => $manifest,
                '--regression-gate' => true,
                '--regression-metric' => ['exact-match :mean'],
            ])
                ->expectsOutputToContain("Adversarial regression gate metric target 'exact-match :mean' must use metric or metric:aggregate syntax.")
                ->assertExitCode(1);

            $this->assertFileDoesNotExist($manifest);
        } finally {
            @unlink($outputs);
            @unlink($manifest);
            @unlink($manifest.'.lock');
        }
    }

    public function test_regression_gate_rejects_non_numeric_max_drop_before_running(): void
    {
        $sample = $this->adversarialSample('ssrf');
        $outputs = tempnam(sys_get_temp_dir(), 'eval-adv-outputs-');
        $manifest = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eval-adv-manifest-'.uniqid('', true).'.json';
        $this->assertNotFalse($outputs);
        $this->assertIsString($sample->expectedOutput);

        try {
            file_put_contents($outputs, json_encode([
                'outputs' => [
                    $sample->id => $sample->expectedOutput,
                ],
            ], JSON_THROW_ON_ERROR));

            $this->artisan('eval-harness:adversarial', [
                '--category' => ['ssrf'],
                '--metric' => ['exact-match'],
                '--outputs' => $outputs,
                '--manifest' => $manifest,
                '--regression-gate' => true,
                '--regression-max-drop' => 'five',
            ])
                ->expectsOutputToContain('The --regression-max-drop option must be a finite percentage in [0, 100].')
                ->assertExitCode(1);

            $this->assertFileDoesNotExist($manifest);
        } finally {
            @unlink($outputs);
            @unlink($manifest);
            @unlink($manifest.'.lock');
        }
    }

    public function test_regression_max_drop_requires_regression_gate(): void
    {
        $sample = $this->adversarialSample('ssrf');
        $outputs = tempnam(sys_get_temp_dir(), 'eval-adv-outputs-');
        $manifest = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eval-adv-manifest-'.uniqid('', true).'.json';
        $this->assertNotFalse($outputs);
        $this->assertIsString($sample->expectedOutput);

        try {
            file_put_contents($outputs, json_encode([
                'outputs' => [
                    $sample->id => $sample->expectedOutput,
                ],
            ], JSON_THROW_ON_ERROR));

            $this->artisan('eval-harness:adversarial', [
                '--category' => ['ssrf'],
                '--metric' => ['exact-match'],
                '--outputs' => $outputs,
                '--manifest' => $manifest,
                '--regression-max-drop' => '10',
            ])
                ->expectsOutputToContain('The --regression-max-drop option requires --regression-gate.')
                ->assertExitCode(1);

            $this->assertFileDoesNotExist($manifest);
        } finally {
            @unlink($outputs);
            @unlink($manifest);
            @unlink($manifest.'.lock');
        }
    }

    public function test_regression_metric_requires_regression_gate(): void
    {
        $sample = $this->adversarialSample('ssrf');
        $outputs = tempnam(sys_get_temp_dir(), 'eval-adv-outputs-');
        $manifest = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eval-adv-manifest-'.uniqid('', true).'.json';
        $this->assertNotFalse($outputs);
        $this->assertIsString($sample->expectedOutput);

        try {
            file_put_contents($outputs, json_encode([
                'outputs' => [
                    $sample->id => $sample->expectedOutput,
                ],
            ], JSON_THROW_ON_ERROR));

            $this->artisan('eval-harness:adversarial', [
                '--category' => ['ssrf'],
                '--metric' => ['exact-match'],
                '--outputs' => $outputs,
                '--manifest' => $manifest,
                '--regression-metric' => ['exact-match:mean'],
            ])
                ->expectsOutputToContain('The --regression-metric option requires --regression-gate.')
                ->assertExitCode(1);

            $this->assertFileDoesNotExist($manifest);
        } finally {
            @unlink($outputs);
            @unlink($manifest);
            @unlink($manifest.'.lock');
        }
    }

    public function test_regression_gate_rejects_empty_max_drop_before_running(): void
    {
        $sample = $this->adversarialSample('ssrf');
        $outputs = tempnam(sys_get_temp_dir(), 'eval-adv-outputs-');
        $manifest = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eval-adv-manifest-'.uniqid('', true).'.json';
        $this->assertNotFalse($outputs);
        $this->assertIsString($sample->expectedOutput);

        try {
            file_put_contents($outputs, json_encode([
                'outputs' => [
                    $sample->id => $sample->expectedOutput,
                ],
            ], JSON_THROW_ON_ERROR));

            $this->artisan('eval-harness:adversarial', [
                '--category' => ['ssrf'],
                '--metric' => ['exact-match'],
                '--outputs' => $outputs,
                '--manifest' => $manifest,
                '--regression-gate' => true,
                '--regression-max-drop' => '',
            ])
                ->expectsOutputToContain('The --regression-max-drop option must be a finite percentage in [0, 100].')
                ->assertExitCode(1);

            $this->assertFileDoesNotExist($manifest);
        } finally {
            @unlink($outputs);
            @unlink($manifest);
            @unlink($manifest.'.lock');
        }
    }

    public function test_regression_gate_rejects_out_of_range_max_drop_before_running(): void
    {
        $sample = $this->adversarialSample('ssrf');
        $outputs = tempnam(sys_get_temp_dir(), 'eval-adv-outputs-');
        $manifest = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eval-adv-manifest-'.uniqid('', true).'.json';
        $this->assertNotFalse($outputs);
        $this->assertIsString($sample->expectedOutput);

        try {
            file_put_contents($outputs, json_encode([
                'outputs' => [
                    $sample->id => $sample->expectedOutput,
                ],
            ], JSON_THROW_ON_ERROR));

            $this->artisan('eval-harness:adversarial', [
                '--category' => ['ssrf'],
                '--metric' => ['exact-match'],
                '--outputs' => $outputs,
                '--manifest' => $manifest,
                '--regression-gate' => true,
                '--regression-max-drop' => '101',
            ])
                ->expectsOutputToContain('The --regression-max-drop option must be a finite percentage in [0, 100].')
                ->assertExitCode(1);

            $this->assertFileDoesNotExist($manifest);
        } finally {
            @unlink($outputs);
            @unlink($manifest);
            @unlink($manifest.'.lock');
        }
    }

    public function test_regression_gate_fails_and_does_not_record_when_configured_metric_is_missing(): void
    {
        $sample = $this->adversarialSample('ssrf');
        $outputs = tempnam(sys_get_temp_dir(), 'eval-adv-outputs-');
        $manifest = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eval-adv-manifest-'.uniqid('', true).'.json';
        $report = tempnam(sys_get_temp_dir(), 'eval-adv-report-');
        $this->assertNotFalse($outputs);
        $this->assertNotFalse($report);
        $this->assertIsString($sample->expectedOutput);

        try {
            file_put_contents($outputs, json_encode([
                'outputs' => [
                    $sample->id => $sample->expectedOutput,
                ],
            ], JSON_THROW_ON_ERROR));

            $this->artisan('eval-harness:adversarial', [
                '--category' => ['ssrf'],
                '--metric' => ['exact-match'],
                '--outputs' => $outputs,
                '--manifest' => $manifest,
                '--regression-gate' => true,
                '--regression-metric' => ['refusal-quality:mean'],
                '--json' => true,
                '--out' => $report,
            ])
                ->expectsOutputToContain('Adversarial regression gate: fail - metrics.refusal-quality.mean missing from current run')
                ->assertExitCode(1);

            $this->assertFileDoesNotExist($manifest);
        } finally {
            @unlink($outputs);
            @unlink($manifest);
            @unlink($manifest.'.lock');
            @unlink($report);
        }
    }

    public function test_regression_gate_fails_when_macro_f1_drops_beyond_threshold(): void
    {
        $sample = $this->adversarialSample('ssrf');
        $outputs = tempnam(sys_get_temp_dir(), 'eval-adv-outputs-');
        $manifest = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eval-adv-manifest-'.uniqid('', true).'.json';
        $report = tempnam(sys_get_temp_dir(), 'eval-adv-report-');
        $secondReport = tempnam(sys_get_temp_dir(), 'eval-adv-report-');
        $this->assertNotFalse($outputs);
        $this->assertNotFalse($report);
        $this->assertNotFalse($secondReport);
        $this->assertIsString($sample->expectedOutput);

        try {
            file_put_contents($outputs, json_encode([
                'outputs' => [
                    $sample->id => $sample->expectedOutput,
                ],
            ], JSON_THROW_ON_ERROR));

            $this->artisan('eval-harness:adversarial', [
                '--category' => ['ssrf'],
                '--metric' => ['exact-match'],
                '--outputs' => $outputs,
                '--manifest' => $manifest,
                '--manifest-retain' => '2',
                '--json' => true,
                '--out' => $report,
            ])->assertExitCode(0);

            file_put_contents($outputs, json_encode([
                'outputs' => [
                    $sample->id => 'unsafe',
                ],
            ], JSON_THROW_ON_ERROR));

            $this->artisan('eval-harness:adversarial', [
                '--category' => ['ssrf'],
                '--metric' => ['exact-match'],
                '--outputs' => $outputs,
                '--manifest' => $manifest,
                '--manifest-retain' => '2',
                '--regression-gate' => true,
                '--regression-max-drop' => '5',
                '--regression-metric' => ['exact-match:mean'],
                '--json' => true,
                '--out' => $secondReport,
            ])
                ->expectsOutputToContain('Adversarial regression gate: fail - macro_f1 dropped by 100.00 percentage points')
                ->assertExitCode(1);

            $decoded = json_decode((string) file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
            $this->assertCount(1, $decoded['runs']);
            $this->assertEqualsWithDelta(1.0, $decoded['runs'][0]['macro_f1'], 1e-9);
        } finally {
            @unlink($outputs);
            @unlink($manifest);
            @unlink($manifest.'.lock');
            @unlink($report);
            @unlink($secondReport);
        }
    }

    public function test_promote_failures_writes_reloadable_dataset_seed(): void
    {
        $sample = $this->adversarialSample('ssrf');
        $outputs = tempnam(sys_get_temp_dir(), 'eval-adv-outputs-');
        $report = tempnam(sys_get_temp_dir(), 'eval-adv-report-');
        $promotion = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eval-adv-promoted-'.uniqid('', true).'.yml';
        $this->assertNotFalse($outputs);
        $this->assertNotFalse($report);

        try {
            file_put_contents($outputs, json_encode([
                'outputs' => [
                    $sample->id => 'unsafe actual output',
                ],
            ], JSON_THROW_ON_ERROR));

            $this->artisan('eval-harness:adversarial', [
                '--category' => ['ssrf'],
                '--metric' => ['exact-match'],
                '--outputs' => $outputs,
                '--promote-failures' => $promotion,
                '--promoted-dataset' => 'adversarial.security.failures',
                '--json' => true,
                '--out' => $report,
            ])
                ->expectsOutputToContain('Failure promotion: wrote 1 failed sample(s)')
                ->assertExitCode(0);

            $parsed = $this->app->make(YamlDatasetLoader::class)->loadFile($promotion);
            $this->assertSame('adversarial.security.failures', $parsed->name);
            $this->assertSame($sample->id, $parsed->samples[0]->id);
            $this->assertSame($sample->input, $parsed->samples[0]->input);
            $this->assertSame($sample->expectedOutput, $parsed->samples[0]->expectedOutput);
            $this->assertSame(['exact-match'], $parsed->samples[0]->metadata['eval_harness']['promoted_failure']['failed_metrics']);
            $this->assertStringNotContainsString('unsafe actual output', (string) file_get_contents($promotion));
        } finally {
            @unlink($outputs);
            @unlink($report);
            @unlink($promotion);
        }
    }

    public function test_promote_failures_clears_stale_file_when_no_samples_failed(): void
    {
        $sample = $this->adversarialSample('ssrf');
        $outputs = tempnam(sys_get_temp_dir(), 'eval-adv-outputs-');
        $report = tempnam(sys_get_temp_dir(), 'eval-adv-report-');
        $promotion = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eval-adv-promoted-'.uniqid('', true).'.yml';
        $this->assertNotFalse($outputs);
        $this->assertNotFalse($report);
        $this->assertIsString($sample->expectedOutput);

        try {
            file_put_contents($promotion, 'stale failure seed');
            file_put_contents($outputs, json_encode([
                'outputs' => [
                    $sample->id => $sample->expectedOutput,
                ],
            ], JSON_THROW_ON_ERROR));

            $this->artisan('eval-harness:adversarial', [
                '--category' => ['ssrf'],
                '--metric' => ['exact-match'],
                '--outputs' => $outputs,
                '--promote-failures' => $promotion,
                '--json' => true,
                '--out' => $report,
            ])
                ->expectsOutputToContain('Failure promotion: no failed samples to export.')
                ->assertExitCode(0);

            $this->assertFileDoesNotExist($promotion);
        } finally {
            @unlink($outputs);
            @unlink($report);
            @unlink($promotion);
        }
    }

    public function test_promoted_dataset_option_requires_failure_promotion_path(): void
    {
        $this->artisan('eval-harness:adversarial', [
            '--promoted-dataset' => 'adversarial.security.failures',
        ])
            ->expectsOutputToContain('The --promoted-dataset option requires --promote-failures=<path>.')
            ->assertExitCode(1);
    }

    public function test_runs_selected_adversarial_category_with_bound_sut(): void
    {
        $sample = $this->adversarialSample('tool-abuse');
        $this->assertIsString($sample->expectedOutput);

        $this->app->bind('eval-harness.sut', fn () => fn (array $_input): string => $sample->expectedOutput);

        $this->artisan('eval-harness:adversarial', [
            '--category' => ['tool-abuse'],
            '--metric' => ['exact-match'],
        ])->assertExitCode(0);
    }

    public function test_rejects_unknown_category(): void
    {
        $this->artisan('eval-harness:adversarial', [
            '--category' => ['unknown'],
            '--metric' => ['exact-match'],
        ])
            ->expectsOutputToContain("Unsupported adversarial category 'unknown'")
            ->assertExitCode(1);
    }

    public function test_requires_sut_without_saved_outputs(): void
    {
        $this->artisan('eval-harness:adversarial', [
            '--category' => ['prompt-injection'],
            '--metric' => ['exact-match'],
        ])
            ->expectsOutputToContain("No system-under-test bound under 'eval-harness.sut'.")
            ->assertExitCode(1);
    }

    public function test_rejects_empty_repeatable_metric_option(): void
    {
        $this->artisan('eval-harness:adversarial', [
            '--category' => ['prompt-injection'],
            '--metric' => [''],
        ])
            ->expectsOutputToContain('The --metric option value at index 0 must be a non-empty string.')
            ->assertExitCode(1);
    }

    public function test_ci_profile_resolves_to_lazy_parallel_against_bound_sut(): void
    {
        // Saved-output runs bypass batchOptions(), so the only way to
        // observe profile resolution from the adversarial command is the
        // SUT-bound path. ci profile mode is lazy-parallel; a closure SUT
        // must surface the SampleRunner requirement, otherwise the
        // command would silently fall back to the default serial mode and
        // accept the closure.
        $sample = $this->adversarialSample('prompt-injection');
        $this->app->bind('eval-harness.sut', fn () => fn (array $_input): string => (string) $sample->expectedOutput);

        $this->artisan('eval-harness:adversarial', [
            '--category' => ['prompt-injection'],
            '--metric' => ['exact-match'],
            '--batch-profile' => 'ci',
        ])
            ->expectsOutputToContain('Lazy parallel batch mode requires a SampleRunner system-under-test')
            ->assertExitCode(1);
    }

    public function test_unknown_profile_returns_failure(): void
    {
        $sample = $this->adversarialSample('prompt-injection');
        $this->app->bind('eval-harness.sut', fn () => fn (array $_input): string => (string) $sample->expectedOutput);

        $this->artisan('eval-harness:adversarial', [
            '--category' => ['prompt-injection'],
            '--metric' => ['exact-match'],
            '--batch-profile' => 'release',
        ])
            ->expectsOutputToContain("Unknown batch profile 'release'")
            ->assertExitCode(1);
    }

    public function test_invalid_chunk_size_returns_failure(): void
    {
        $sample = $this->adversarialSample('prompt-injection');
        $this->app->bind('eval-harness.sut', fn () => fn (array $_input): string => (string) $sample->expectedOutput);

        $this->artisan('eval-harness:adversarial', [
            '--category' => ['prompt-injection'],
            '--metric' => ['exact-match'],
            '--batch' => 'lazy-parallel',
            '--chunk-size' => 'abc',
        ])
            ->expectsOutputToContain('The --chunk-size option must be a positive integer.')
            ->assertExitCode(1);
    }

    public function test_invalid_rate_limit_returns_failure(): void
    {
        $sample = $this->adversarialSample('prompt-injection');
        $this->app->bind('eval-harness.sut', fn () => fn (array $_input): string => (string) $sample->expectedOutput);

        $this->artisan('eval-harness:adversarial', [
            '--category' => ['prompt-injection'],
            '--metric' => ['exact-match'],
            '--batch' => 'lazy-parallel',
            '--rate-limit' => '-3',
        ])
            ->expectsOutputToContain('The --rate-limit option must be a positive integer.')
            ->assertExitCode(1);
    }

    public function test_invalid_checkpoint_every_returns_failure(): void
    {
        $sample = $this->adversarialSample('prompt-injection');
        $this->app->bind('eval-harness.sut', fn () => fn (array $_input): string => (string) $sample->expectedOutput);

        $this->artisan('eval-harness:adversarial', [
            '--category' => ['prompt-injection'],
            '--metric' => ['exact-match'],
            '--batch' => 'lazy-parallel',
            '--checkpoint-every' => 'abc',
        ])
            ->expectsOutputToContain('The --checkpoint-every option must be a positive integer.')
            ->assertExitCode(1);
    }

    public function test_invalid_result_ttl_seconds_returns_failure(): void
    {
        $sample = $this->adversarialSample('prompt-injection');
        $this->app->bind('eval-harness.sut', fn () => fn (array $_input): string => (string) $sample->expectedOutput);

        $this->artisan('eval-harness:adversarial', [
            '--category' => ['prompt-injection'],
            '--metric' => ['exact-match'],
            '--batch' => 'lazy-parallel',
            '--result-ttl-seconds' => 'abc',
        ])
            ->expectsOutputToContain('The --result-ttl-seconds option must be a positive integer.')
            ->assertExitCode(1);
    }

    public function test_invalid_rate_window_seconds_returns_failure(): void
    {
        $sample = $this->adversarialSample('prompt-injection');
        $this->app->bind('eval-harness.sut', fn () => fn (array $_input): string => (string) $sample->expectedOutput);

        $this->artisan('eval-harness:adversarial', [
            '--category' => ['prompt-injection'],
            '--metric' => ['exact-match'],
            '--batch' => 'lazy-parallel',
            '--rate-window-seconds' => 'abc',
        ])
            ->expectsOutputToContain('The --rate-window-seconds option must be a positive integer.')
            ->assertExitCode(1);
    }

    public function test_rate_window_seconds_without_rate_limit_returns_failure(): void
    {
        $sample = $this->adversarialSample('prompt-injection');
        $this->app->bind('eval-harness.sut', fn () => fn (array $_input): string => (string) $sample->expectedOutput);

        $this->artisan('eval-harness:adversarial', [
            '--category' => ['prompt-injection'],
            '--metric' => ['exact-match'],
            '--batch' => 'lazy-parallel',
            '--rate-window-seconds' => '30',
        ])
            ->expectsOutputToContain('Batch rate window seconds is only meaningful with a rate limit')
            ->assertExitCode(1);
    }

    public function test_serial_mode_rejects_explicit_chunk_size(): void
    {
        $sample = $this->adversarialSample('prompt-injection');
        $this->app->bind('eval-harness.sut', fn () => fn (array $_input): string => (string) $sample->expectedOutput);

        $this->artisan('eval-harness:adversarial', [
            '--category' => ['prompt-injection'],
            '--metric' => ['exact-match'],
            '--chunk-size' => '4',
        ])
            ->expectsOutputToContain('Serial batch mode does not use a chunk size.')
            ->assertExitCode(1);
    }

    private function adversarialSample(string $category): DatasetSample
    {
        /** @var AdversarialDatasetFactory $factory */
        $factory = $this->app->make(AdversarialDatasetFactory::class);

        return $factory->samples([$category])[0];
    }
}
