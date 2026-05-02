<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Console;

use Illuminate\Console\Command;
use Padosoft\EvalHarness\EvalEngine;
use Padosoft\EvalHarness\Exceptions\EvalRunException;

/**
 * Artisan entry point: `php artisan eval-harness:run <dataset>`.
 *
 * Modes:
 *   - With `--registrar=<class>`: the host application binds a
 *     callable class implementing `__invoke(EvalEngine $engine): void`
 *     in the container. The command resolves it, lets it register
 *     datasets + the system-under-test, then executes the run.
 *   - Without `--registrar`: prints the list of currently-registered
 *     datasets and exits (useful as a smoke test of the SP wiring).
 *
 * Output:
 *   - Markdown report on stdout by default.
 *   - `--json` writes JSON to stdout instead.
 *   - `--out=<path>` writes the chosen format to a file (relative
 *     to cwd).
 *
 * Exit code:
 *   - 0 on green run with no failures captured.
 *   - 1 if any sample failed any metric, OR if the dataset/registrar
 *     resolution raised. CI gates can `exit 1`-on-regression by
 *     wrapping a custom registrar that adds threshold assertions.
 */
final class EvalCommand extends Command
{
    /** @var string */
    protected $signature = 'eval-harness:run
        {dataset : Dataset name (e.g. rag.factuality.fy2026)}
        {--registrar= : FQCN of an invokable class that registers the dataset + drives the SUT}
        {--json : Emit JSON report instead of Markdown}
        {--out= : Write the report to this file path instead of stdout}';

    /** @var string */
    protected $description = 'Run an eval-harness golden-dataset evaluation against a system-under-test.';

    public function handle(EvalEngine $engine): int
    {
        $datasetName = (string) $this->argument('dataset');
        $registrar = $this->option('registrar');

        if (is_string($registrar) && $registrar !== '') {
            $this->dispatchRegistrar($engine, $registrar);
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

        $sut = $engine->container()->bound('eval-harness.sut')
            ? $engine->container()->make('eval-harness.sut')
            : null;

        if (! is_callable($sut)) {
            $this->error(
                "No system-under-test bound under 'eval-harness.sut'. Bind a callable in your registrar with \$container->bind('eval-harness.sut', fn () => fn (array \$in) => ...).",
            );

            return self::FAILURE;
        }

        $report = $engine->run($datasetName, $sut);

        $payload = $this->option('json')
            ? (string) json_encode(
                $report->toJson(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            )
            : $report->toMarkdown();

        $out = $this->option('out');
        if (is_string($out) && $out !== '') {
            $bytes = file_put_contents($out, $payload);
            if ($bytes === false) {
                $this->error(sprintf('Failed to write report to %s', $out));

                return self::FAILURE;
            }
            $this->info(sprintf('Wrote %d bytes to %s', $bytes, $out));
        } else {
            $this->line($payload);
        }

        return $report->totalFailures() === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function dispatchRegistrar(EvalEngine $engine, string $registrarClass): void
    {
        if (! class_exists($registrarClass)) {
            throw new EvalRunException(
                sprintf("Registrar class '%s' does not exist.", $registrarClass),
            );
        }

        $instance = $engine->container()->make($registrarClass);

        if (! is_callable($instance)) {
            throw new EvalRunException(
                sprintf(
                    "Registrar '%s' must be an invokable class (define __invoke(EvalEngine \$engine): void).",
                    $registrarClass,
                ),
            );
        }

        $instance($engine);
    }
}
