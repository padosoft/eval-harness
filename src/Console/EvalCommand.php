<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Console;

use Illuminate\Console\Command;
use Padosoft\EvalHarness\Console\Concerns\BuildsBatchOptions;
use Padosoft\EvalHarness\Console\Concerns\ComparesRuns;
use Padosoft\EvalHarness\Console\Concerns\DispatchesEvalRegistrars;
use Padosoft\EvalHarness\Console\Concerns\ResolvesSystemUnderTest;
use Padosoft\EvalHarness\Console\Concerns\WritesEvalReports;
use Padosoft\EvalHarness\EvalEngine;
use Padosoft\EvalHarness\Exceptions\EvalHarnessException;
use Padosoft\EvalHarness\Exceptions\EvalRunException;
use Padosoft\EvalHarness\Outputs\SavedOutputsLoader;
use Padosoft\EvalHarness\Regression\BaselineStore;
use Padosoft\EvalHarness\Regression\RunComparator;
use Padosoft\EvalHarness\Reports\EvalReport;
use Symfony\Component\Console\Output\ConsoleOutputInterface;

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
    use ComparesRuns;
    use DispatchesEvalRegistrars;
    use ResolvesSystemUnderTest;
    use WritesEvalReports;

    /** @var string */
    protected $signature = 'eval-harness:run
        {dataset : Dataset name (e.g. rag.factuality.fy2026)}
        {--registrar= : FQCN of an invokable class that registers the dataset + drives the SUT}
        {--outputs= : JSON/YAML file containing precomputed sample outputs to score without invoking the SUT}
        {--batch=serial : Batch mode for invoking the SUT; supports serial or lazy-parallel}
        {--batch-profile= : Operational profile preset (ci, smoke, nightly, or custom); explicit options override profile defaults; pass `none` (or `null`) to one of the nullable numeric flags (--timeout, --batch-timeout, --chunk-size, --rate-limit, --rate-window-seconds, --result-ttl-seconds, --checkpoint-every) to clear an inherited profile value}
        {--concurrency=1 : Producer fan-out cap for lazy-parallel mode (also the default --chunk-size); --chunk-size narrows the dispatch window further but cannot exceed --concurrency}
        {--queue= : Queue name for queue-backed batch modes}
        {--timeout= : Per-sample timeout seconds for queue-backed batch modes}
        {--batch-timeout= : Maximum seconds to wait for each lazy-parallel dispatch window to finish (covers both rate-limit pauses and result collection)}
        {--result-ttl-seconds= : Raise the lazy-parallel result-store TTL floor for this run (positive integer, or "none"/"null" to clear an inherited profile value). On the run() path the runner takes max(this value, the package default 3600s, --batch-timeout, --timeout, ceil(samples/chunkSize) * max(--batch-timeout, --timeout)); on the dispatch() path it takes max(this value, the package default, ceil(samples/concurrency) * --timeout). Explicit values BELOW the package default cannot lower it — set the global floor in eval-harness.batches.lazy_parallel.result_ttl_seconds for that}
        {--chunk-size= : Producer window size for lazy-parallel dispatch; defaults to --concurrency when unset and must be <= --concurrency}
        {--rate-limit= : Maximum samples dispatched per --rate-window-seconds in lazy-parallel mode}
        {--rate-window-seconds= : Rolling window in seconds used by --rate-limit (defaults to 60)}
        {--checkpoint-every= : Emit a progress checkpoint every N completed samples in lazy-parallel mode}
        {--budget-usd= : Stop the run once observable provider spend (judge + embedding calls) passes this many US dollars; a halted run always exits non-zero}
        {--repetitions= : Execute every sample this many times and report pass rate, spread, and the smallest difference the run could actually detect; overrides the dataset `repetitions:` field (default 1)}
        {--compare= : Compare this run against a reference: `baseline`, `latest`, or a report path on the reports disk}
        {--max-regressions=0 : Fail the run when more than N rows regressed against the reference (requires --compare)}
        {--confident-only : Count only regressions larger than the difference this run could actually detect}
        {--compare-epsilon= : Fixed score tolerance in [0,1]; overrides the statistical resolution derived from --repetitions}
        {--comparison-out= : Write the row-by-row comparison payload as JSON to this path}
        {--promote-baseline : Promote this run as the dataset baseline when it finishes clean and passes the gate}
        {--json : Emit JSON report instead of Markdown}
        {--out= : Write the report to this file path instead of stdout (relative paths use the configured reports disk + prefix unless --raw-path is set)}
        {--raw-path : Treat --out as a literal cwd-relative path; bypass the reports disk + prefix configuration}';

    /** @var string */
    protected $description = 'Run an eval-harness golden-dataset evaluation against a system-under-test or saved outputs. Note: when --outputs is set the command scores precomputed outputs and the batch flags (--batch, --batch-profile, --concurrency, --queue, --timeout, --batch-timeout, --result-ttl-seconds, --chunk-size, --rate-limit, --rate-window-seconds, --checkpoint-every) do NOT apply.';

    public function handle(EvalEngine $engine): int
    {
        $datasetName = (string) $this->argument('dataset');
        $registrar = $this->option('registrar');

        try {
            $repetitions = $this->repetitionsOption();

            // Validated here rather than where they are used. The comparison
            // flags are only read once the run has finished, and rejecting an
            // unusable value at that point has already cost somebody a full
            // suite of tokens.
            $this->maxRegressionsOption();
            $this->compareEpsilonOption();
            $budgetUsd = $this->budgetOption();
        } catch (EvalHarnessException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

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

            $this->warnIfBatchFlagsIgnored();

            try {
                /** @var SavedOutputsLoader $loader */
                $loader = $this->laravel->make(SavedOutputsLoader::class);
                $report = $engine->scoreOutputs($datasetName, $loader->loadFile($outputsPath), $repetitions, $budgetUsd);
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
                $report = $engine->runBatch($datasetName, $sut, $this->batchOptions(), $repetitions, $budgetUsd);
            } catch (EvalHarnessException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
        }

        $this->reportSamplingPrecision($report);
        $this->reportCost($report);

        $payload = $this->reportPayload($report);
        if ($payload === null || ! $this->writeOrPrintReport($payload)) {
            return self::FAILURE;
        }

        // A halted run is incomplete data, and incomplete data that exits zero
        // is the worst outcome a gate can produce: the rows that would have
        // failed are exactly the ones that never ran.
        $exitCode = $report->totalFailures() === 0 && ! $report->wasHalted() ? self::SUCCESS : self::FAILURE;

        try {
            $gateFailed = $this->compareAgainstReference($datasetName, $report);
        } catch (EvalHarnessException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return $gateFailed ? self::FAILURE : $exitCode;
    }

    private function budgetOption(): ?float
    {
        $raw = $this->option('budget-usd');

        if ($raw === null || $raw === '') {
            return null;
        }

        if (! is_string($raw) || ! is_numeric($raw) || (float) $raw <= 0.0) {
            throw new EvalRunException(sprintf(
                'The --budget-usd option requires a positive amount in US dollars; got %s.',
                is_scalar($raw) ? var_export($raw, true) : get_debug_type($raw),
            ));
        }

        return (float) $raw;
    }

    /**
     * Print what the run cost, and say loudly when it stopped for lack of money.
     *
     * Routed through the same diagnostic channel as the comparison, so a
     * `--json` run streaming to stdout stays parseable.
     */
    private function reportCost(EvalReport $report): void
    {
        $cost = $report->cost;

        if ($cost !== null && $cost->calls > 0) {
            $this->diagnostic('');
            $this->diagnostic(sprintf(
                '<options=bold>Cost</> $%s across %d provider call%s (%s tokens).',
                number_format($cost->totalUsd(), 4),
                $cost->calls,
                $cost->calls === 1 ? '' : 's',
                number_format($cost->totalTokens()),
            ));

            if (! $cost->isComplete()) {
                $this->diagnostic(sprintf(
                    '  <comment>Floor, not a figure: %d call(s) on unpriced model(s) %s. Declare rates under eval-harness.costs.models.</comment>',
                    $cost->unpricedCalls,
                    implode(', ', $cost->unpricedModels),
                ));
            }
        }

        if ($report->wasHalted()) {
            $this->error('Halted on budget. '.(string) $report->budget?->reason);
            $this->diagnostic('  This report covers a partial run: the rows that never executed are not failures, they are unknowns.');
        }
    }

    /**
     * Compare against the reference run, print what moved, apply the gate, and
     * promote the baseline when asked.
     *
     * Returns true when the gate failed. A missing or unreadable reference is
     * not a failure: the run happened, and losing a baseline must never turn a
     * green run red.
     */
    private function compareAgainstReference(string $datasetName, EvalReport $report): bool
    {
        /** @var BaselineStore $baselines */
        $baselines = $this->laravel->make(BaselineStore::class);

        $reference = $this->resolveReferenceReport($baselines, $datasetName, $this->lastWrittenArtifactPath);
        $currentPayload = $report->toJson();
        $gateFailed = false;

        if ($reference !== null) {
            /** @var RunComparator $comparator */
            $comparator = $this->laravel->make(RunComparator::class);

            $comparison = $this->compareRuns($comparator, $currentPayload, $reference['payload'], $reference['label']);

            // An unusable reference is treated exactly like a missing one: warn
            // and leave the run's own exit code alone. The alternative — gating
            // on a comparison that could not join a single row — reports zero
            // regressions and passes, which is the most expensive kind of green.
            if (! $comparison->isComparable()) {
                $this->warn(sprintf(
                    'Skipping the comparison against %s: %s.',
                    $reference['label'],
                    (string) $comparison->incomparableReason,
                ));

                $this->promoteBaselineIfRequested($baselines, $datasetName, $report, false);

                return false;
            }

            $this->renderComparison($comparison);

            if (! $this->writeComparison($comparison)) {
                return true;
            }

            $verdict = $this->regressionGate()->evaluate($comparison, $currentPayload);

            if (! $verdict['passed']) {
                foreach ($verdict['failures'] as $failure) {
                    $this->error('Gate failed: '.$failure);
                }

                $gateFailed = true;
            }
        }

        $this->promoteBaselineIfRequested($baselines, $datasetName, $report, $gateFailed);

        return $gateFailed;
    }

    /**
     * A run only becomes the baseline if it is one worth measuring against:
     * clean, and past the gate. Promoting a run that failed would silently
     * lower the bar to the level of the regression that just shipped.
     */
    private function promoteBaselineIfRequested(
        BaselineStore $baselines,
        string $datasetName,
        EvalReport $report,
        bool $gateFailed,
    ): void {
        if (! (bool) $this->option('promote-baseline')) {
            return;
        }

        if ($this->lastWrittenArtifactPath === null) {
            $this->warn('--promote-baseline needs a stored report: re-run with --out=<path> (and without --raw-path).');

            return;
        }

        if ($gateFailed || $report->totalFailures() > 0) {
            $this->warn('Not promoting a baseline: this run did not finish clean.');

            return;
        }

        $baselines->promote($datasetName, $this->lastWrittenArtifactPath, $report->toJson());
        $this->info(sprintf("Baseline for '%s' is now [%s].", $datasetName, $this->lastWrittenArtifactPath));
    }

    /**
     * @throws EvalHarnessException when the flag is present but not a positive integer
     */
    private function repetitionsOption(): ?int
    {
        $raw = $this->option('repetitions');

        if ($raw === null || $raw === '') {
            return null;
        }

        if (! is_string($raw) || ! ctype_digit($raw) || (int) $raw < 1) {
            throw new EvalRunException(sprintf(
                'The --repetitions option requires a positive integer; got %s.',
                is_scalar($raw) ? var_export($raw, true) : get_debug_type($raw),
            ));
        }

        return (int) $raw;
    }

    /**
     * Tell the operator what the run could actually have detected.
     *
     * Written to stderr so it never lands inside a JSON report being piped
     * from stdout, and only for runs that repeated: a single-execution run is
     * the package's original deterministic-pipeline default, and adding a
     * statistics warning to every one of those would be noise. The `precision`
     * block is in the report either way, so nothing is lost by staying quiet.
     */
    private function reportSamplingPrecision(EvalReport $report): void
    {
        if ($report->repetitions() < 2) {
            return;
        }

        $precision = $report->precision();
        $tag = $precision['target_resolvable'] ? 'info' : 'comment';
        $message = sprintf('<%s>[eval-harness] %s</%s>', $tag, $precision['summary'], $tag);

        $output = $this->output->getOutput();

        if ($output instanceof ConsoleOutputInterface) {
            $output->getErrorOutput()->writeln($message);

            return;
        }

        // No separate error stream on this output. Stay silent when a JSON
        // report is headed for stdout: one advisory line there would make the
        // payload unparseable, and an unparseable report is a worse outcome
        // than an unprinted note.
        if ($this->option('json') === true && $this->option('out') === null) {
            return;
        }

        $this->line($message);
    }
}
