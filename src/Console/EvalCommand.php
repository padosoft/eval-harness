<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use JsonException;
use Padosoft\EvalHarness\Contracts\SampleRunner;
use Padosoft\EvalHarness\EvalEngine;
use Padosoft\EvalHarness\Exceptions\EvalHarnessException;
use Padosoft\EvalHarness\Exceptions\EvalRunException;

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
    /** @var string */
    protected $signature = 'eval-harness:run
        {dataset : Dataset name (e.g. rag.factuality.fy2026)}
        {--registrar= : FQCN of an invokable class that registers the dataset + drives the SUT}
        {--json : Emit JSON report instead of Markdown}
        {--out= : Write the report to this file path instead of stdout (relative paths use the configured reports disk + prefix unless --raw-path is set)}
        {--raw-path : Treat --out as a literal cwd-relative path; bypass the reports disk + prefix configuration}';

    /** @var string */
    protected $description = 'Run an eval-harness golden-dataset evaluation against a system-under-test.';

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

        $sut = $engine->container()->bound('eval-harness.sut')
            ? $engine->container()->make('eval-harness.sut')
            : null;

        if (! $sut instanceof SampleRunner && ! is_callable($sut)) {
            $this->error(
                "No system-under-test bound under 'eval-harness.sut'. Bind a callable or SampleRunner in your registrar with \$container->bind('eval-harness.sut', fn () => fn (array \$in) => ...).",
            );

            return self::FAILURE;
        }

        $report = $engine->run($datasetName, $sut);

        if ($this->option('json')) {
            try {
                $payload = json_encode(
                    $report->toJson(),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                );
            } catch (JsonException $e) {
                $this->error(sprintf(
                    'Failed to encode report as JSON: %s. The report contained values that cannot be serialised (most often invalid UTF-8 in actual_output or metric details).',
                    $e->getMessage(),
                ));

                return self::FAILURE;
            }

            // json_encode with JSON_THROW_ON_ERROR returns string or
            // throws — but PHPStan still types it as string|false.
            if (! is_string($payload)) {
                $this->error('Failed to encode report as JSON: encoder returned a non-string.');

                return self::FAILURE;
            }
        } else {
            $payload = $report->toMarkdown();
        }

        $out = $this->option('out');
        if (is_string($out) && $out !== '') {
            if (! $this->writeReport($out, $payload)) {
                return self::FAILURE;
            }
        } else {
            $this->line($payload);
        }

        return $report->totalFailures() === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function writeReport(string $out, string $payload): bool
    {
        $rawPath = (bool) $this->option('raw-path');
        $isAbsolute = $this->isAbsolutePath($out);

        if ($rawPath || $isAbsolute) {
            $bytes = file_put_contents($out, $payload);
            if ($bytes === false) {
                $this->error(sprintf('Failed to write report to %s', $out));

                return false;
            }
            $this->info(sprintf('Wrote %d bytes to %s', $bytes, $out));

            return true;
        }

        /** @var ConfigRepository $config */
        $config = $this->laravel->make(ConfigRepository::class);
        $diskName = (string) $config->get('eval-harness.reports.disk', 'local');
        $prefix = trim((string) $config->get('eval-harness.reports.path_prefix', ''), '/');

        /** @var FilesystemFactory $filesystems */
        $filesystems = $this->laravel->make(FilesystemFactory::class);
        $disk = $filesystems->disk($diskName);

        $relativePath = $prefix === '' ? $out : $prefix.'/'.ltrim($out, '/');

        if (! $disk->put($relativePath, $payload)) {
            $this->error(sprintf(
                'Failed to write report to disk [%s] at path [%s].',
                $diskName,
                $relativePath,
            ));

            return false;
        }

        $this->info(sprintf(
            'Wrote %d bytes to disk [%s] at path [%s].',
            strlen($payload),
            $diskName,
            $relativePath,
        ));

        return true;
    }

    private function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        // POSIX absolute (/foo) or Windows drive (C:\foo, C:/foo).
        if (str_starts_with($path, '/') || str_starts_with($path, '\\')) {
            return true;
        }

        return (bool) preg_match('#^[A-Za-z]:[\\\\/]#', $path);
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
