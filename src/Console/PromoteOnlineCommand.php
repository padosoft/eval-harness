<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Console;

use Illuminate\Console\Command;
use Padosoft\EvalHarness\Console\Concerns\WritesEvalReports;
use Padosoft\EvalHarness\Datasets\YamlDatasetLoader;
use Padosoft\EvalHarness\Exceptions\DatasetSchemaException;
use Padosoft\EvalHarness\Exceptions\EvalHarnessException;
use Padosoft\EvalHarness\Exceptions\EvalRunException;
use Padosoft\EvalHarness\Online\OnlineInteractionPromoter;
use Padosoft\EvalHarness\Online\PromotionCriteria;

/**
 * Artisan entry point: `php artisan eval-harness:promote-online <dataset>`.
 *
 * Turns interactions your online monitor scored in production into golden
 * dataset rows — by default the ones that failed, because a dataset of rows the
 * pipeline already got right can only ever stay green.
 *
 * ```
 * php artisan eval-harness:promote-online rag.factuality \
 *     --merge=database/evals/rag.factuality.yaml \
 *     --since=7 --limit=25
 * ```
 *
 * Requires `eval-harness.online.retention.enabled`: without it the online table
 * stores a score and not the interaction, and there is nothing to promote.
 *
 * Rows retained while no redactor was bound are **skipped** unless
 * `--allow-unredacted` is passed: promoting raw production text copies it into
 * a YAML file that gets committed, and a repository is forever.
 */
final class PromoteOnlineCommand extends Command
{
    use WritesEvalReports;

    /** @var string */
    protected $signature = 'eval-harness:promote-online
        {dataset : Dataset the online monitor scored under}
        {--out= : Write the dataset YAML here (relative paths use the reports disk + prefix unless --raw-path)}
        {--merge= : Existing dataset YAML to append to; rows already present by content hash are skipped}
        {--metrics=exact-match : Comma-separated metric aliases for the emitted dataset}
        {--name= : Dataset name to write into the YAML (defaults to the dataset argument)}
        {--all : Promote every recorded interaction, not just the failing ones}
        {--max-score= : Only promote interactions scored at or below this (0..1)}
        {--since= : Only promote interactions judged within this many days}
        {--limit=50 : Maximum interactions to promote in one run}
        {--allow-unredacted : Promote interactions retained with no redactor bound (raw production text)}
        {--raw-path : Treat --out as a literal cwd-relative path; bypass the reports disk + prefix configuration}';

    /** @var string */
    protected $description = 'Promote production interactions recorded by the online monitor into a golden dataset, so tonight\'s incident becomes tomorrow\'s regression test.';

    public function handle(OnlineInteractionPromoter $promoter, YamlDatasetLoader $loader): int
    {
        $dataset = (string) $this->argument('dataset');
        $merge = $this->option('merge');

        if (is_string($merge) && $merge !== '' && ! $this->mergeTargetIsUsable($loader, $merge)) {
            return self::FAILURE;
        }

        try {
            $criteria = new PromotionCriteria(
                failingOnly: ! (bool) $this->option('all'),
                maxScore: $this->floatOption('max-score'),
                sinceDays: $this->intOption('since'),
                limit: $this->intOption('limit') ?? PromotionCriteria::DEFAULT_LIMIT,
            );

            $result = $promoter->promote(
                dataset: $dataset,
                criteria: $criteria,
                metrics: $this->metricsOption(),
                mergeIntoYaml: is_string($merge) && $merge !== '' ? $merge : null,
                datasetName: is_string($this->option('name')) && $this->option('name') !== ''
                    ? (string) $this->option('name')
                    : null,
                allowUnredacted: (bool) $this->option('allow-unredacted'),
            );
        } catch (EvalHarnessException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->summarise($result);

        if ($result['promoted'] === 0) {
            $this->warn('Nothing new to promote; leaving the dataset untouched.');

            return self::SUCCESS;
        }

        $out = $this->option('out');

        // Writing back over the merge target is the common case and the whole
        // point: the dataset grows in place and the diff is pure additions.
        $destination = is_string($out) && $out !== ''
            ? $out
            : (is_string($merge) && $merge !== '' ? $merge : null);

        if ($destination === null) {
            $this->line($result['yaml']);

            return self::SUCCESS;
        }

        $rawPath = is_string($merge) && $merge !== '' && $destination === $merge
            ? true
            : (bool) $this->option('raw-path');

        return $this->writeArtifact($destination, $result['yaml'], 'dataset', $rawPath)
            ? self::SUCCESS
            : self::FAILURE;
    }

    /**
     * A merge target that exists but does not parse must stop the command.
     *
     * The promoter treats an unreadable target as "no existing rows", which is
     * the right behaviour for a *missing* file and catastrophic for a corrupt
     * one: writing the result back would replace a dataset with the handful of
     * rows promoted this run.
     */
    private function mergeTargetIsUsable(YamlDatasetLoader $loader, string $path): bool
    {
        if (! is_file($path)) {
            return true;
        }

        try {
            $loader->loadFile($path);

            return true;
        } catch (DatasetSchemaException $e) {
            $this->error(sprintf(
                'Merge target [%s] is not a readable dataset, so promoting into it would replace it: %s',
                $path,
                $e->getMessage(),
            ));

            return false;
        }
    }

    /**
     * @param  array{yaml: string, promoted: int, skipped_duplicate: int, skipped_unredacted: int, skipped_unretained: int, existing: int}  $result
     */
    private function summarise(array $result): void
    {
        $this->info(sprintf(
            '%d promoted, %d already present, %d duplicate%s.',
            $result['promoted'],
            $result['existing'],
            $result['skipped_duplicate'],
            $result['skipped_duplicate'] === 1 ? '' : 's',
        ));

        if ($result['skipped_unretained'] > 0) {
            $this->warn(sprintf(
                '%d scored interaction(s) had no retained text and were skipped. Enable eval-harness.online.retention to keep it.',
                $result['skipped_unretained'],
            ));
        }

        // Said as a warning, never silently: a skipped unredacted row is a row
        // somebody expected to see in the dataset and will otherwise assume
        // never existed.
        if ($result['skipped_unredacted'] > 0) {
            $this->warn(sprintf(
                '%d interaction(s) were retained with no redactor bound and were skipped. Pass --allow-unredacted to promote raw production text into a committed file.',
                $result['skipped_unredacted'],
            ));
        }
    }

    /**
     * @return list<string>
     */
    private function metricsOption(): array
    {
        $raw = $this->option('metrics');

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $metrics = [];

        foreach (explode(',', $raw) as $metric) {
            $metric = trim($metric);

            if ($metric !== '') {
                $metrics[] = $metric;
            }
        }

        return $metrics;
    }

    private function intOption(string $name): ?int
    {
        $raw = $this->option($name);

        if ($raw === null || $raw === '') {
            return null;
        }

        if (! is_string($raw) || ! ctype_digit($raw)) {
            throw new EvalRunException(sprintf(
                'The --%s option requires a positive integer; got %s.',
                $name,
                is_scalar($raw) ? var_export($raw, true) : get_debug_type($raw),
            ));
        }

        return (int) $raw;
    }

    private function floatOption(string $name): ?float
    {
        $raw = $this->option($name);

        if ($raw === null || $raw === '') {
            return null;
        }

        if (! is_string($raw) || ! is_numeric($raw)) {
            throw new EvalRunException(sprintf(
                'The --%s option requires a number between 0 and 1; got %s.',
                $name,
                is_scalar($raw) ? var_export($raw, true) : get_debug_type($raw),
            ));
        }

        return (float) $raw;
    }
}
