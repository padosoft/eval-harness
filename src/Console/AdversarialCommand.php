<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Console;

use Illuminate\Console\Command;
use Padosoft\EvalHarness\Adversarial\AdversarialDatasetFactory;
use Padosoft\EvalHarness\Adversarial\AdversarialRegressionGate;
use Padosoft\EvalHarness\Adversarial\AdversarialRegressionGateCheck;
use Padosoft\EvalHarness\Adversarial\AdversarialRegressionGateResult;
use Padosoft\EvalHarness\Adversarial\AdversarialRunManifestStore;
use Padosoft\EvalHarness\Console\Concerns\BuildsBatchOptions;
use Padosoft\EvalHarness\Console\Concerns\DispatchesEvalRegistrars;
use Padosoft\EvalHarness\Console\Concerns\ResolvesSystemUnderTest;
use Padosoft\EvalHarness\Console\Concerns\WritesEvalReports;
use Padosoft\EvalHarness\EvalEngine;
use Padosoft\EvalHarness\Exceptions\EvalHarnessException;
use Padosoft\EvalHarness\Exceptions\EvalRunException;
use Padosoft\EvalHarness\Outputs\SavedOutputsLoader;
use Padosoft\EvalHarness\Reports\EvalReport;

/**
 * Opt-in red-team evaluation command for built-in adversarial seed datasets.
 *
 * The command registers a fresh adversarial dataset for the current process,
 * then either invokes the bound system-under-test or scores saved outputs.
 * Nothing runs automatically: host applications must call the command
 * explicitly and provide a SUT binding, saved outputs, or a registrar that
 * prepares those dependencies.
 */
final class AdversarialCommand extends Command
{
    use BuildsBatchOptions;
    use DispatchesEvalRegistrars;
    use ResolvesSystemUnderTest;
    use WritesEvalReports;

    /** @var string */
    protected $signature = 'eval-harness:adversarial
        {--registrar= : FQCN of an invokable class that can bind the SUT or custom metrics before the adversarial dataset is registered}
        {--dataset=adversarial.security.v1 : Dataset name to register for this adversarial run}
        {--category=* : Adversarial category to include; repeat for multiple categories; defaults to all built-in categories}
        {--metric=* : Metric alias/FQCN to score with; repeat for multiple metrics; defaults to refusal-quality}
        {--outputs= : JSON/YAML file containing precomputed sample outputs to score without invoking the SUT}
        {--manifest= : JSON manifest path to update with this adversarial run summary}
        {--manifest-retain=10 : Maximum number of adversarial runs to retain when --manifest is used}
        {--regression-gate : Compare this run with the latest compatible failure-free --manifest baseline and fail on score drops}
        {--regression-max-drop=5 : Maximum allowed regression drop in percentage points (0-100)}
        {--regression-metric=* : Additional metric aggregate to gate; use metric or metric:mean|p50|p95|pass_rate}
        {--batch=serial : Batch mode for invoking the SUT; supports serial or lazy-parallel}
        {--concurrency=1 : Maximum queued samples dispatched before waiting in lazy-parallel mode}
        {--queue= : Queue name for queue-backed batch modes}
        {--timeout= : Per-sample timeout seconds for queue-backed batch modes}
        {--batch-timeout= : Maximum seconds to wait for each lazy-parallel dispatch window to finish}
        {--json : Emit JSON report instead of Markdown}
        {--out= : Write the report to this file path instead of stdout (relative paths use the configured reports disk + prefix unless --raw-path is set)}
        {--raw-path : Treat --out as a literal cwd-relative path; bypass the reports disk + prefix configuration}';

    /** @var list<string> */
    protected $aliases = ['eval:adversarial'];

    /** @var string */
    protected $description = 'Run opt-in adversarial eval-harness red-team seeds against a SUT or saved outputs.';

    public function handle(EvalEngine $engine, AdversarialDatasetFactory $factory): int
    {
        $registrar = $this->option('registrar');
        if (is_string($registrar) && $registrar !== '') {
            try {
                $this->dispatchRegistrar($engine, $registrar);
            } catch (EvalHarnessException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
        }

        try {
            $datasetName = $this->datasetNameOption();
            $engine->registerDataset($factory->build(
                name: $datasetName,
                categories: $this->categoryOptions(),
                metricSpecs: $this->metricOptions(),
            ));
        } catch (EvalHarnessException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        try {
            $this->validateManifestAndRegressionGateOptions();
        } catch (EvalHarnessException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $outputsPath = $this->option('outputs');
        if ($outputsPath !== null) {
            if (! is_string($outputsPath) || $outputsPath === '') {
                $this->error('The --outputs option requires a non-empty file path.');

                return self::FAILURE;
            }

            try {
                /** @var SavedOutputsLoader $loader */
                $loader = $this->laravel->make(SavedOutputsLoader::class);
                $report = $engine->scoreOutputs($datasetName, $loader->loadFile($outputsPath));
            } catch (EvalHarnessException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
        } else {
            $sut = $this->resolveSystemUnderTest($engine);
            if ($sut === null) {
                return self::FAILURE;
            }

            try {
                $report = $engine->runBatch($datasetName, $sut, $this->batchOptions());
            } catch (EvalHarnessException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
        }

        $payload = $this->reportPayload($report);
        if ($payload === null || ! $this->writeOrPrintReport($payload)) {
            return self::FAILURE;
        }

        $regressionGate = null;
        if ($this->regressionGateEnabled()) {
            $regressionGate = $this->recordManifestWithRegressionGate($report);
            if ($regressionGate === null) {
                return self::FAILURE;
            }
        } elseif (! $this->recordManifest($report)) {
            return self::FAILURE;
        }

        if ($regressionGate?->failed()) {
            return self::FAILURE;
        }

        return $report->totalFailures() === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function validateManifestAndRegressionGateOptions(): void
    {
        if ($this->regressionGateEnabled()) {
            $this->validateRegressionGateOptions();

            return;
        }

        $this->validateManifestOptions();
    }

    private function validateManifestOptions(): void
    {
        $manifestPath = $this->option('manifest');
        if ($manifestPath === null) {
            return;
        }

        if (! is_string($manifestPath) || $manifestPath === '' || $manifestPath !== trim($manifestPath)) {
            throw new EvalRunException('The --manifest option requires a non-empty file path without leading or trailing whitespace.');
        }

        $this->positiveIntegerOption('manifest-retain', 10);
    }

    private function validateRegressionGateOptions(): void
    {
        if (! $this->regressionGateEnabled()) {
            return;
        }

        $this->manifestPathForRegressionGate();
        $this->positiveIntegerOption('manifest-retain', 10);

        /** @var AdversarialRegressionGate $gate */
        $gate = $this->laravel->make(AdversarialRegressionGate::class);
        $gate->assertConfiguration(
            maxDrop: $this->regressionMaxDropRatio(),
            metricTargets: $this->stringListOption('regression-metric'),
        );
    }

    private function manifestPathForRegressionGate(): string
    {
        $manifestPath = $this->option('manifest');
        if (! is_string($manifestPath) || $manifestPath === '') {
            throw new EvalRunException('The --regression-gate option requires --manifest=<path> so a previous adversarial run can be used as baseline.');
        }
        if ($manifestPath !== trim($manifestPath)) {
            throw new EvalRunException('The --manifest option requires a non-empty file path without leading or trailing whitespace.');
        }

        return $manifestPath;
    }

    private function recordManifestWithRegressionGate(EvalReport $report): ?AdversarialRegressionGateResult
    {
        try {
            /** @var AdversarialRunManifestStore $store */
            $store = $this->laravel->make(AdversarialRunManifestStore::class);
            /** @var AdversarialRegressionGate $gate */
            $gate = $this->laravel->make(AdversarialRegressionGate::class);
            $result = $store->recordWithRegressionGate(
                path: $this->manifestPathForRegressionGate(),
                report: $report,
                gate: $gate,
                maxDrop: $this->regressionMaxDropRatio(),
                metricTargets: $this->stringListOption('regression-metric'),
                maxRuns: $this->positiveIntegerOption('manifest-retain', 10),
                manifestName: $report->datasetName,
            );
        } catch (EvalHarnessException $e) {
            $this->error($e->getMessage());

            return null;
        }

        $this->writeRegressionGateResult($result, $report);

        return $result;
    }

    private function writeRegressionGateResult(AdversarialRegressionGateResult $result, EvalReport $report): void
    {
        if ($result->missingBaseline()) {
            if (! $result->recorded) {
                $this->writeRegressionDiagnostic('Adversarial regression gate: missing-baseline - no compatible failure-free manifest baseline; current run has metric failures and was not recorded for future comparisons.');

                return;
            }

            $this->writeRegressionDiagnostic('Adversarial regression gate: missing-baseline - no compatible manifest baseline; current run will be recorded for future comparisons.');

            return;
        }

        if (! $result->failed()) {
            if (! $result->recorded) {
                $this->writeRegressionDiagnostic('Adversarial regression gate: pass - score checks passed, but current run has metric failures and was not recorded for future comparisons.');

                return;
            }

            $maxDrop = $result->checks[0]->maxDrop ?? 0.0;
            $this->writeRegressionDiagnostic(sprintf(
                'Adversarial regression gate: pass - %d check(s), max drop %s.',
                count($result->checks),
                $this->formatPercentagePoints($maxDrop),
            ));

            return;
        }

        $this->writeRegressionDiagnostic('Adversarial regression gate: fail - '.$this->regressionGateFailureSummary($result).'; current run was not recorded for future comparisons.');
    }

    private function writeRegressionDiagnostic(string $message): void
    {
        $out = $this->option('out');
        if (! is_string($out) || $out === '') {
            fwrite(STDERR, $message.PHP_EOL);

            return;
        }

        $this->output->getErrorStyle()->writeln($message);
    }

    private function regressionGateFailureSummary(AdversarialRegressionGateResult $result): string
    {
        $messages = [];
        foreach ($result->checks as $check) {
            if (! $check->failed()) {
                continue;
            }

            if ($check->status === AdversarialRegressionGateCheck::STATUS_MISSING_VALUE) {
                $missing = [];
                if ($check->baselineScore === null && $result->baselineRunId !== null) {
                    $missing[] = 'baseline';
                }
                if ($check->currentScore === null) {
                    $missing[] = 'current';
                }
                if ($missing === []) {
                    $missing[] = 'current';
                }

                $messages[] = sprintf('%s missing from %s run', $check->target, implode(' and ', $missing));

                continue;
            }

            $messages[] = sprintf(
                '%s dropped by %s (baseline %s -> current %s, max %s)',
                $check->target,
                $this->formatPercentagePoints($check->drop ?? 0.0),
                $this->formatScore($check->baselineScore ?? 0.0),
                $this->formatScore($check->currentScore ?? 0.0),
                $this->formatPercentagePoints($check->maxDrop),
            );
        }

        return implode('; ', $messages);
    }

    private function regressionGateEnabled(): bool
    {
        return (bool) $this->option('regression-gate');
    }

    private function regressionMaxDropRatio(): float
    {
        $value = $this->option('regression-max-drop');
        if ($value === null || $value === '') {
            return 0.05;
        }

        if ((! is_string($value) && ! is_int($value) && ! is_float($value)) || (is_string($value) && $value !== trim($value)) || ! is_numeric($value)) {
            throw new EvalRunException('The --regression-max-drop option must be a finite percentage in [0, 100].');
        }

        $percent = (float) $value;
        if ($percent < 0.0 || $percent > 100.0 || is_nan($percent) || is_infinite($percent)) {
            throw new EvalRunException('The --regression-max-drop option must be a finite percentage in [0, 100].');
        }

        return $percent / 100.0;
    }

    private function formatPercentagePoints(float $ratio): string
    {
        return number_format($ratio * 100.0, 2, '.', '').' percentage points';
    }

    private function formatScore(float $score): string
    {
        return number_format($score, 4, '.', '');
    }

    private function recordManifest(EvalReport $report): bool
    {
        $manifestPath = $this->option('manifest');
        if ($manifestPath === null) {
            return true;
        }

        if (! is_string($manifestPath) || $manifestPath === '') {
            $this->error('The --manifest option requires a non-empty file path.');

            return false;
        }

        try {
            /** @var AdversarialRunManifestStore $store */
            $store = $this->laravel->make(AdversarialRunManifestStore::class);
            $store->record(
                path: $manifestPath,
                report: $report,
                maxRuns: $this->positiveIntegerOption('manifest-retain', 10),
                manifestName: $report->datasetName,
            );
        } catch (EvalHarnessException $e) {
            $this->error($e->getMessage());

            return false;
        }

        return true;
    }

    private function datasetNameOption(): string
    {
        $dataset = $this->option('dataset');
        if (! is_string($dataset) || $dataset === '' || trim($dataset) !== $dataset) {
            throw new EvalRunException('The --dataset option must be a non-empty dataset name without leading or trailing whitespace.');
        }

        return $dataset;
    }

    /**
     * @return list<string>|null
     */
    private function categoryOptions(): ?array
    {
        $categories = $this->stringListOption('category');

        return $categories === [] ? null : $categories;
    }

    /**
     * @return list<string>
     */
    private function metricOptions(): array
    {
        $metrics = $this->stringListOption('metric');

        return $metrics === [] ? AdversarialDatasetFactory::DEFAULT_METRICS : $metrics;
    }

    /**
     * @return list<string>
     */
    private function stringListOption(string $name): array
    {
        $values = $this->option($name);
        if ($values === null || $values === []) {
            return [];
        }

        if (! is_array($values) || ! array_is_list($values)) {
            throw new EvalRunException(sprintf('The --%s option must be provided as repeatable string values.', $name));
        }

        $normalized = [];
        foreach ($values as $index => $value) {
            if (! is_string($value) || $value === '') {
                throw new EvalRunException(sprintf(
                    'The --%s option value at index %d must be a non-empty string.',
                    $name,
                    $index,
                ));
            }

            $normalized[] = $value;
        }

        return $normalized;
    }
}
