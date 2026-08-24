<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Console\Concerns;

use Padosoft\EvalHarness\Exceptions\EvalRunException;
use Padosoft\EvalHarness\Regression\BaselineStore;
use Padosoft\EvalHarness\Regression\RegressionGate;
use Padosoft\EvalHarness\Regression\RunComparator;
use Padosoft\EvalHarness\Regression\RunComparison;
use Symfony\Component\Console\Output\ConsoleOutputInterface;

/**
 * `--compare` handling for the eval command: resolve a reference run, diff the
 * current run against it row by row, print what moved, and apply the gate.
 *
 * Kept out of the command body because comparing is a second job the command
 * does after running: the run either happened or it did not, and a missing
 * reference must never turn a completed run into a failed one.
 */
trait ComparesRuns
{
    /** How many regressed rows to print before pointing at the full artifact. */
    private const MAX_RENDERED_REGRESSIONS = 10;

    /**
     * The reference the caller asked for, resolved to a decoded report.
     *
     * @return array{payload: array<string, mixed>, label: string}|null
     */
    private function resolveReferenceReport(BaselineStore $baselines, string $dataset, ?string $excludePath): ?array
    {
        $reference = $this->option('compare');

        if (! is_string($reference) || $reference === '') {
            return null;
        }

        if ($reference === 'baseline') {
            $payload = $baselines->report($dataset);

            if ($payload === null) {
                $this->warn(sprintf(
                    "No baseline to compare against for dataset '%s'. Promote one with: php artisan eval-harness:baseline %s",
                    $dataset,
                    $dataset,
                ));

                return null;
            }

            // Re-read rather than kept from report(): the pointer can be
            // rewritten or removed between the two calls, and a label is not
            // worth failing a completed run over.
            $pointer = $baselines->pointer($dataset) ?? [];
            $reportPath = $pointer['report_path'] ?? null;
            $path = is_string($reportPath) ? $reportPath : 'baseline';

            return ['payload' => $payload, 'label' => sprintf('the baseline [%s]', $path)];
        }

        if ($reference === 'latest') {
            $path = $baselines->latestReportPath($dataset, $excludePath);

            if ($path === null) {
                $this->warn(sprintf("No earlier stored report to compare against for dataset '%s'.", $dataset));

                return null;
            }

            $payload = $baselines->readReport($path);

            if ($payload === null) {
                $this->warn(sprintf('Report [%s] could not be read; skipping the comparison.', $path));

                return null;
            }

            return ['payload' => $payload, 'label' => sprintf('the previous run [%s]', $path)];
        }

        $payload = $baselines->readReport($reference);

        if ($payload === null) {
            $this->warn(sprintf('Report [%s] could not be read; skipping the comparison.', $reference));

            return null;
        }

        return ['payload' => $payload, 'label' => sprintf('[%s]', $reference)];
    }

    /**
     * @param  array<string, mixed>  $currentPayload
     * @param  array<string, mixed>  $referencePayload
     */
    private function compareRuns(
        RunComparator $comparator,
        array $currentPayload,
        array $referencePayload,
        string $label,
    ): RunComparison {
        return $comparator->compare(
            current: $currentPayload,
            reference: $referencePayload,
            referenceLabel: $label,
            epsilon: $this->compareEpsilonOption(),
        );
    }

    private function regressionGate(): RegressionGate
    {
        return new RegressionGate(
            maxRegressions: $this->maxRegressionsOption(),
            confidentOnly: (bool) $this->option('confident-only'),
        );
    }

    private function renderComparison(RunComparison $comparison): void
    {
        $counts = $comparison->toArray()['counts'];

        $this->diagnostic('');
        $this->diagnostic(sprintf('<options=bold>Compared against %s</>', $comparison->referenceLabel ?? 'the reference run'));

        if ($comparison->joinedByIdOnly()) {
            $this->diagnostic(
                '  <comment>Rows joined by sample id: the reference predates content hashes, so a renamed row reads as removed and added.</comment>',
            );
        }

        $this->diagnostic(sprintf(
            '  %d regressed (%d beyond this run\'s %.1f-point detectable difference), %d improved, %d added, %d removed, %d compared.',
            $counts['regressed'],
            $counts['regressed_confident'],
            $comparison->resolution * 100,
            $counts['improved'],
            $counts['added'],
            $counts['removed'],
            $counts['compared'],
        ));

        $regressions = $comparison->regressed();

        if ($regressions === []) {
            return;
        }

        foreach (array_slice($regressions, 0, self::MAX_RENDERED_REGRESSIONS) as $row) {
            $this->diagnostic(sprintf(
                '  %-28s %s → %s   score %s   %s%s',
                $this->truncate($row->sampleId, 28),
                $this->formatRate($row->before['pass_rate'] ?? null),
                $this->formatRate($row->after['pass_rate'] ?? null),
                $this->formatDelta($row->scoreDelta),
                $row->confident ? 'beyond noise' : 'within noise',
                $row->isNewlyFailing() ? ', newly failing' : '',
            ));
        }

        if (count($regressions) > self::MAX_RENDERED_REGRESSIONS) {
            $this->diagnostic(sprintf(
                '  … and %d more. Write the full comparison with --comparison-out=<path>.',
                count($regressions) - self::MAX_RENDERED_REGRESSIONS,
            ));
        }
    }

    /**
     * Operator-facing text that must never end up inside a machine-readable
     * report.
     *
     * `--json` without `--out` streams the report to stdout, and a single line
     * of commentary appended to that stream makes the whole payload
     * unparseable — which is worse than an unprinted note, because the CI job
     * consuming it fails somewhere else entirely. Diagnostics go to stderr
     * where one exists, and are dropped when it does not and stdout is
     * carrying JSON.
     */
    private function diagnostic(string $message): void
    {
        $output = $this->output->getOutput();

        if ($output instanceof ConsoleOutputInterface) {
            $output->getErrorOutput()->writeln($message);

            return;
        }

        if ($this->option('json') === true && $this->option('out') === null) {
            return;
        }

        $this->line($message);
    }

    private function truncate(string $value, int $length): string
    {
        return mb_strlen($value) > $length ? mb_substr($value, 0, $length - 1).'…' : $value;
    }

    /**
     * Persist the comparison payload when the caller asked for it.
     *
     * Written through the same disk + prefix rules as a report, so a CI job
     * collects one directory and gets both.
     */
    private function writeComparison(RunComparison $comparison): bool
    {
        $out = $this->option('comparison-out');

        if (! is_string($out) || $out === '') {
            return true;
        }

        $encoded = json_encode(
            $comparison->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        if ($encoded === false) {
            $this->error('The comparison payload could not be encoded as JSON.');

            return false;
        }

        return $this->writeArtifact($out, $encoded, 'comparison');
    }

    private function maxRegressionsOption(): int
    {
        $raw = $this->option('max-regressions');

        if ($raw === null || $raw === '') {
            return 0;
        }

        if (! is_string($raw) || ! ctype_digit($raw)) {
            throw new EvalRunException(sprintf(
                'The --max-regressions option requires a non-negative integer; got %s.',
                is_scalar($raw) ? var_export($raw, true) : get_debug_type($raw),
            ));
        }

        return (int) $raw;
    }

    private function compareEpsilonOption(): ?float
    {
        $raw = $this->option('compare-epsilon');

        if ($raw === null || $raw === '') {
            return null;
        }

        if (! is_string($raw) || ! is_numeric($raw)) {
            throw new EvalRunException(sprintf(
                'The --compare-epsilon option requires a number between 0 and 1; got %s.',
                is_scalar($raw) ? var_export($raw, true) : get_debug_type($raw),
            ));
        }

        $epsilon = (float) $raw;

        if ($epsilon < 0.0 || $epsilon > 1.0) {
            throw new EvalRunException(sprintf(
                'The --compare-epsilon option requires a number between 0 and 1; got %s.',
                $raw,
            ));
        }

        return $epsilon;
    }

    private function formatRate(?float $value): string
    {
        return $value === null ? 'n/a' : sprintf('%.0f%%', $value * 100);
    }

    private function formatDelta(?float $value): string
    {
        if ($value === null) {
            return 'n/a';
        }

        return sprintf('%+.4f', $value);
    }
}
