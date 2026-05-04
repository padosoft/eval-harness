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
