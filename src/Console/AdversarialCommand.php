<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Console;

use Illuminate\Console\Command;
use Padosoft\EvalHarness\Adversarial\AdversarialDatasetFactory;
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

        if (! $this->recordManifest($report)) {
            return self::FAILURE;
        }

        return $report->totalFailures() === 0 ? self::SUCCESS : self::FAILURE;
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
