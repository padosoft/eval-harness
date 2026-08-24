<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Online;

use Illuminate\Support\Carbon;
use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Datasets\RowHash;
use Padosoft\EvalHarness\Datasets\YamlDatasetLoader;
use Padosoft\EvalHarness\Exceptions\DatasetSchemaException;
use Symfony\Component\Yaml\Yaml;

/**
 * Turns production failures into golden dataset rows.
 *
 * ## The row you cannot write at a desk
 *
 * Golden datasets get written by the people who built the pipeline, which means
 * they encode what those people already thought to worry about. The questions
 * that actually break a system are the ones nobody predicted: the phrasing no
 * designer would choose, the edge case that only exists in real inventory, the
 * follow-up that assumes context the pipeline never had.
 *
 * Online monitoring already scores those interactions. This turns the ones that
 * failed into permanent regression tests — so tonight's incident is tomorrow's
 * red build, instead of an anecdote in a retro.
 *
 * ## Duplicates solve themselves
 *
 * A nightly promotion promotes the same recurring failure every night. Rows are
 * therefore deduplicated by {@see RowHash} — content identity over input and
 * expected output — against both the existing dataset file and the batch being
 * built. That is the same hash the regression gate joins on, which means a
 * promoted row keeps its history from the moment it lands.
 *
 * ## Unredacted rows are refused by default
 *
 * A row retained while no redactor was bound is raw production text. Promoting
 * it copies that text into a YAML file that gets committed to a repository, and
 * a repository is forever. So it is skipped unless the caller says otherwise in
 * as many words.
 */
final class OnlineInteractionPromoter
{
    public function __construct(private readonly YamlDatasetLoader $loader) {}

    /**
     * @param  list<string>  $metrics  metric aliases the emitted dataset scores with
     * @return array{yaml: string, promoted: int, skipped_duplicate: int, skipped_unredacted: int, skipped_unretained: int, existing: int}
     */
    public function promote(
        string $dataset,
        PromotionCriteria $criteria,
        array $metrics,
        ?string $mergeIntoYaml = null,
        ?string $datasetName = null,
        bool $allowUnredacted = false,
    ): array {
        $existing = $this->existingSamples($mergeIntoYaml);
        $seen = [];

        foreach ($existing as $sample) {
            $seen[RowHash::for($sample)] = true;
        }

        $existingCount = count($existing);
        $promoted = [];
        $skippedDuplicate = 0;
        $skippedUnredacted = 0;
        $skippedUnretained = 0;

        foreach ($this->candidates($dataset, $criteria) as $score) {
            if (! $score->isRetained()) {
                $skippedUnretained++;

                continue;
            }

            if ($score->redactor === null && ! $allowUnredacted) {
                $skippedUnredacted++;

                continue;
            }

            /** @var array<string, mixed> $input */
            $input = $score->input ?? [];
            $expected = (string) $score->expected_output;
            $hash = RowHash::fromParts($input, $expected);

            if (isset($seen[$hash])) {
                $skippedDuplicate++;

                continue;
            }

            $seen[$hash] = true;
            $promoted[] = $this->rowFor($score, $input, $expected, $hash);
        }

        return [
            'yaml' => $this->render(
                name: $datasetName ?? $dataset,
                metrics: $metrics,
                existing: $existing,
                promoted: $promoted,
            ),
            'promoted' => count($promoted),
            'skipped_duplicate' => $skippedDuplicate,
            'skipped_unredacted' => $skippedUnredacted,
            'skipped_unretained' => $skippedUnretained,
            'existing' => $existingCount,
        ];
    }

    /**
     * @return list<OnlineScore>
     */
    private function candidates(string $dataset, PromotionCriteria $criteria): array
    {
        $query = OnlineScore::forDataset($dataset);

        if ($criteria->failingOnly) {
            $query->where('passed', false);
        }

        if ($criteria->maxScore !== null) {
            $query->where('score', '<=', $criteria->maxScore);
        }

        if ($criteria->sinceDays !== null) {
            $query->where('judged_at', '>=', Carbon::now()->subDays($criteria->sinceDays));
        }

        // Worst first, then newest: when a limit truncates the batch, the rows
        // that survive should be the ones the pipeline handled worst.
        /** @var list<OnlineScore> $rows */
        $rows = $query->orderBy('score')->orderByDesc('judged_at')->limit($criteria->limit)->get()->all();

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function rowFor(OnlineScore $score, array $input, string $expected, string $hash): array
    {
        return [
            // Derived from the content hash, not from the database id: the same
            // production failure promoted from two environments gets the same
            // row id, and re-promoting after a table truncation does not
            // renumber a dataset somebody has already committed.
            'id' => 'online-'.RowHash::short($hash),
            'input' => $input,
            'expected_output' => $expected,
            'metadata' => array_filter([
                'tags' => ['promoted-from-production'],
                'source' => 'online',
                'online_sample_id' => $score->sample_id,
                'online_score' => round($score->score, 4),
                'online_metric' => $score->metric,
                'judge_model' => $score->judge_model,
                'judged_at' => $score->judged_at?->toIso8601String(),
                'redactor' => $score->redactor,
            ], static fn (mixed $value): bool => $value !== null),
        ];
    }

    /**
     * @return list<DatasetSample>
     */
    private function existingSamples(?string $path): array
    {
        if ($path === null || $path === '' || ! is_file($path)) {
            return [];
        }

        try {
            return $this->loader->loadFile($path)->samples;
        } catch (DatasetSchemaException) {
            // A merge target that is not a valid dataset is not a reason to
            // lose the promotion, but it IS a reason not to overwrite: the
            // command checks for this before writing.
            return [];
        }
    }

    /**
     * @param  list<string>  $metrics
     * @param  list<DatasetSample>  $existing
     * @param  list<array<string, mixed>>  $promoted
     */
    private function render(string $name, array $metrics, array $existing, array $promoted): string
    {
        $samples = [];

        // Existing rows first and byte-identical in content, so a merge shows
        // up in review as pure additions.
        foreach ($existing as $sample) {
            $row = [
                'id' => $sample->id,
                'input' => $sample->input,
                'expected_output' => $sample->expectedOutput,
            ];

            if ($sample->metadata !== []) {
                $row['metadata'] = $sample->metadata;
            }

            $samples[] = $row;
        }

        foreach ($promoted as $row) {
            $samples[] = $row;
        }

        $document = ['name' => $name];

        if ($metrics !== []) {
            $document['metrics'] = $metrics;
        }

        $document['samples'] = $samples;

        return Yaml::dump($document, 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }
}
