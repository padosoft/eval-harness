<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Console;

use Illuminate\Console\Command;
use Padosoft\EvalHarness\Brief\DatasetContext;
use Padosoft\EvalHarness\Brief\RunBriefing;
use Padosoft\EvalHarness\Console\Concerns\WritesEvalReports;
use Padosoft\EvalHarness\Datasets\YamlDatasetLoader;
use Padosoft\EvalHarness\Exceptions\DatasetSchemaException;
use Padosoft\EvalHarness\Regression\BaselineStore;
use Padosoft\EvalHarness\Reports\ReportSchema;

/**
 * Artisan entry point: `php artisan eval-harness:brief <report>`.
 *
 * Turns a failed run into something a person or a coding agent can act on
 * without opening a JSON file: the failing rows worst-first, what each failing
 * metric actually measures, whether the failures share a cohort, and whether
 * any of them are safety findings rather than quality ones.
 *
 * ```
 * php artisan eval-harness:brief eval-harness/reports/run.json \
 *     --dataset=database/evals/rag.factuality.yaml \
 *     --format=github --out=brief.md
 * ```
 *
 * `--format=md` is the document itself; `--format=github` wraps it in a
 * collapsed pull-request comment; `--format=json` emits the same content
 * structurally, for a UI or another tool.
 *
 * The briefing quotes model output verbatim, so it opens by declaring that
 * everything fenced inside it is untrusted data and must not be executed. That
 * is not decoration: this artifact is designed to be pasted into an agent with
 * repository access, and a dataset row is a place an attacker's text can reach.
 */
final class BriefCommand extends Command
{
    use WritesEvalReports;

    /** @var string */
    protected $signature = 'eval-harness:brief
        {report : Path to a JSON report (absolute, or relative to the configured reports disk + prefix)}
        {--dataset= : Dataset YAML file, so the briefing can quote the question and the golden answer}
        {--comparison= : A comparison payload (written by --comparison-out) to say what moved against the reference run}
        {--format=md : md for the briefing, github for a collapsed PR comment, json for the structured payload}
        {--budget= : Maximum characters of briefing to produce (default 24000, roughly 6k tokens)}
        {--out= : Write the briefing to this path instead of stdout (relative paths use the reports disk + prefix unless --raw-path is set)}
        {--raw-path : Treat --out as a literal cwd-relative path; bypass the reports disk + prefix configuration}';

    /** @var string */
    protected $description = 'Turn a JSON eval report into a briefing a coding agent can act on: failing rows worst-first, metric semantics, cohorts, and safety findings.';

    public function handle(BaselineStore $baselines): int
    {
        $format = strtolower((string) $this->option('format'));

        if (! in_array($format, ['md', 'markdown', 'github', 'json'], true)) {
            $this->error(sprintf("Unknown --format '%s'. Supported: md, github, json.", $format));

            return self::FAILURE;
        }

        $budget = $this->budgetOption();

        if ($budget === null) {
            return self::FAILURE;
        }

        $reportPath = (string) $this->argument('report');
        $report = $this->readPayload($baselines, $reportPath);

        if ($report === null) {
            $this->error(sprintf('Report [%s] could not be read as JSON.', $reportPath));

            return self::FAILURE;
        }

        // A comparison payload has no sample_aggregates, so briefing one would
        // quietly produce "every row passed" for a run that did not pass. Say
        // which artifact was handed over instead.
        if (($report['schema_version'] ?? null) !== ReportSchema::VERSION) {
            $this->error(sprintf(
                'Artifact [%s] is not an eval report (expected schema_version %s, found %s).',
                $reportPath,
                ReportSchema::VERSION,
                is_string($report['schema_version'] ?? null) ? $report['schema_version'] : 'none',
            ));

            return self::FAILURE;
        }

        $comparison = null;
        $comparisonPath = $this->option('comparison');

        if (is_string($comparisonPath) && $comparisonPath !== '') {
            $comparison = $this->readPayload($baselines, $comparisonPath);

            if ($comparison === null) {
                $this->warn(sprintf('Comparison [%s] could not be read; briefing the run on its own.', $comparisonPath));
            }
        }

        $dataset = $this->datasetContext();

        if ($dataset === false) {
            return self::FAILURE;
        }

        $briefing = new RunBriefing($report, $comparison, $dataset, $budget);

        $payload = match ($format) {
            'github' => $briefing->renderForGithub(),
            'json' => $this->encode($briefing->toArray()),
            default => $briefing->render(),
        };

        if ($payload === null) {
            $this->error('The briefing could not be encoded as JSON.');

            return self::FAILURE;
        }

        $out = $this->option('out');

        if (is_string($out) && $out !== '') {
            return $this->writeArtifact($out, $payload, 'briefing') ? self::SUCCESS : self::FAILURE;
        }

        $this->output->write($payload."\n");

        return self::SUCCESS;
    }

    /**
     * The dataset behind the report, when one was named.
     *
     * Returns null when no dataset was asked for — the briefing degrades to
     * naming rows rather than quoting them — and false when one was asked for
     * and could not be loaded, which is a mistake worth stopping on rather
     * than silently producing a thinner document than the caller expected.
     */
    private function datasetContext(): DatasetContext|null|false
    {
        $path = $this->option('dataset');

        if (! is_string($path) || $path === '') {
            return null;
        }

        try {
            return DatasetContext::fromDefinition((new YamlDatasetLoader)->loadFile($path));
        } catch (DatasetSchemaException $e) {
            $this->error(sprintf('Dataset [%s] could not be loaded: %s', $path, $e->getMessage()));

            return false;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readPayload(BaselineStore $baselines, string $path): ?array
    {
        // An absolute path is the CI case: the report was just written next to
        // the job's other artifacts and never went near a configured disk.
        if ($this->isAbsolutePath($path) || is_file($path)) {
            $contents = @file_get_contents($path);

            if (! is_string($contents) || $contents === '') {
                return null;
            }

            $decoded = json_decode($contents, true);

            return is_array($decoded) ? $decoded : null;
        }

        return $baselines->readReport($path);
    }

    private function budgetOption(): ?int
    {
        $raw = $this->option('budget');

        if ($raw === null || $raw === '') {
            return RunBriefing::DEFAULT_BUDGET;
        }

        if (! is_string($raw) || ! ctype_digit($raw) || (int) $raw < 1) {
            $this->error(sprintf(
                'The --budget option requires a positive integer number of characters; got %s.',
                is_scalar($raw) ? var_export($raw, true) : get_debug_type($raw),
            ));

            return null;
        }

        return (int) $raw;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encode(array $payload): ?string
    {
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? null : $encoded;
    }
}
