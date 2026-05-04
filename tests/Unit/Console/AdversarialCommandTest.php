<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Console;

use Padosoft\EvalHarness\Adversarial\AdversarialDatasetFactory;
use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Tests\TestCase;

final class AdversarialCommandTest extends TestCase
{
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
                ->expectsOutputToContain('The --regression-gate option requires --manifest=<path>')
                ->assertExitCode(1);
        } finally {
            @unlink($outputs);
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
            ])->assertExitCode(0);

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
            ])->assertExitCode(1);

            $decoded = json_decode((string) file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
            $this->assertCount(2, $decoded['runs']);
            $this->assertEqualsWithDelta(0.0, $decoded['runs'][0]['macro_f1'], 1e-9);
            $this->assertEqualsWithDelta(1.0, $decoded['runs'][1]['macro_f1'], 1e-9);
        } finally {
            @unlink($outputs);
            @unlink($manifest);
            @unlink($manifest.'.lock');
            @unlink($report);
            @unlink($secondReport);
        }
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

    private function adversarialSample(string $category): DatasetSample
    {
        /** @var AdversarialDatasetFactory $factory */
        $factory = $this->app->make(AdversarialDatasetFactory::class);

        return $factory->samples([$category])[0];
    }
}
