<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Console;

use Illuminate\Console\Command;
use Padosoft\EvalHarness\Console\Concerns\BuildsBatchOptions;
use Padosoft\EvalHarness\Console\Concerns\DispatchesEvalRegistrars;
use Padosoft\EvalHarness\Console\Concerns\ResolvesSystemUnderTest;
use Padosoft\EvalHarness\Console\Concerns\WritesEvalReports;
use Padosoft\EvalHarness\EvalEngine;
use Padosoft\EvalHarness\Exceptions\EvalHarnessException;
use Padosoft\EvalHarness\Outputs\SavedOutputsLoader;

/**
 * Artisan entry point: `php artisan eval-harness:run <dataset>`.
 *
 * Modes:
 *   - With `--registrar=<class>`: the host application binds a
 *     callable class implementing `__invoke(EvalEngine $engine): void`
 *     in the container. The command resolves it, lets it register
 *     datasets + the system-under-test, then executes the run.
 *   - Without `--registrar`: the command requires the named dataset
 *     to have been registered earlier (e.g. in a service provider's
 *     `boot()` method) AND a system-under-test to be bound under the
 *     container key `eval-harness.sut`. If either is missing, the
 *     command errors out with a non-zero exit code. The bound value
 *     may be either a callable or a SampleRunner implementation.
 *   - With `--outputs=<path>`: the command scores precomputed
 *     sample outputs from a JSON/YAML file and does not require a
 *     system-under-test binding.
 *   - With `--batch=serial|lazy-parallel`: the command routes SUT
 *     invocation through the batch execution contract. Lazy parallel
 *     requires a SampleRunner binding because queue jobs cannot
 *     serialize arbitrary callables.
 *
 * Output:
 *   - Markdown report on stdout by default.
 *   - `--json` writes JSON to stdout instead.
 *   - `--out=<path>` writes the chosen format to a file. The path is
 *     resolved as follows:
 *       * If absolute, written verbatim via the local filesystem.
 *       * If relative, the configured reports disk
 *         (`eval-harness.reports.disk`) and path prefix
 *         (`eval-harness.reports.path_prefix`) are honoured. Pass
 *         `--raw-path` to opt out and write to the cwd-relative path.
 *
 * Exit code:
 *   - 0 on green run with no failures captured.
 *   - 1 if any sample failed any metric, OR if the dataset/registrar
 *     resolution raised, OR if JSON encoding of the report failed
 *     (rather than silently writing an empty payload). CI gates can
 *     `exit 1`-on-regression by wrapping a custom registrar that
 *     adds threshold assertions.
 */
final class EvalCommand extends Command
{
    use BuildsBatchOptions;
    use DispatchesEvalRegistrars;
    use ResolvesSystemUnderTest;
    use WritesEvalReports;

    /** @var string */
    protected $signature = 'eval-harness:run
        {dataset : Dataset name (e.g. rag.factuality.fy2026)}
        {--registrar= : FQCN of an invokable class that registers the dataset + drives the SUT}
        {--outputs= : JSON/YAML file containing precomputed sample outputs to score without invoking the SUT}
        {--batch=serial : Batch mode for invoking the SUT; supports serial or lazy-parallel}
        {--concurrency=1 : Maximum queued samples dispatched before waiting in lazy-parallel mode}
        {--queue= : Queue name for queue-backed batch modes}
        {--timeout= : Per-sample timeout seconds for queue-backed batch modes}
        {--batch-timeout= : Maximum seconds to wait for each lazy-parallel dispatch window to finish}
        {--json : Emit JSON report instead of Markdown}
        {--out= : Write the report to this file path instead of stdout (relative paths use the configured reports disk + prefix unless --raw-path is set)}
        {--raw-path : Treat --out as a literal cwd-relative path; bypass the reports disk + prefix configuration}';

    /** @var string */
    protected $description = 'Run an eval-harness golden-dataset evaluation against a system-under-test or saved outputs.';

    public function handle(EvalEngine $engine): int
    {
        $datasetName = (string) $this->argument('dataset');
        $registrar = $this->option('registrar');

        if (is_string($registrar) && $registrar !== '') {
            try {
                $this->dispatchRegistrar($engine, $registrar);
            } catch (EvalHarnessException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
        }

        if (! $engine->hasDataset($datasetName)) {
            $this->error(sprintf(
                "Dataset '%s' is not registered. Pass --registrar=<class> to register one, or call \$eval->dataset(...)->register() during boot.",
                $datasetName,
            ));
            $available = $engine->registeredDatasetNames();
            if ($available !== []) {
                $this->line('Available datasets: '.implode(', ', $available));
            }

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

        return $report->totalFailures() === 0 ? self::SUCCESS : self::FAILURE;
    }
}
