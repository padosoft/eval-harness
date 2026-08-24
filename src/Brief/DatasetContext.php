<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Brief;

use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Datasets\ParsedDatasetDefinition;
use Padosoft\EvalHarness\Datasets\RowHash;

/**
 * The dataset behind a report, so the briefing can quote the question.
 *
 * A report records what the pipeline *said*, never what it was *asked*: the
 * question and the golden answer live in the dataset, and duplicating them
 * into every report would put whole corpora into an artifact that gets
 * committed, diffed and shipped to a browser. The dataset is already in the
 * repository, so the briefing reads it there.
 *
 * Rows are indexed by content hash first and by id second — the same
 * negotiation the regression comparator makes, for the same reason: an id can
 * be renamed while the row stays the same test, and a hash cannot. When the
 * report predates row hashes, or when a row was edited after the run, the id
 * lookup still finds it; when neither matches, the briefing says so instead of
 * quoting the wrong question, which is the one outcome worse than quoting
 * none.
 */
final class DatasetContext
{
    /**
     * @param  array<string, DatasetSample>  $byHash
     * @param  array<string, DatasetSample>  $byId
     */
    private function __construct(
        private readonly array $byHash,
        private readonly array $byId,
        public readonly string $name,
    ) {}

    public static function fromDefinition(ParsedDatasetDefinition $definition): self
    {
        $byHash = [];
        $byId = [];

        foreach ($definition->samples as $sample) {
            $byHash[RowHash::for($sample)] = $sample;
            $byId[$sample->id] = $sample;
        }

        return new self($byHash, $byId, $definition->name);
    }

    public function find(?string $rowHash, ?string $id): ?DatasetSample
    {
        if (is_string($rowHash) && isset($this->byHash[$rowHash])) {
            return $this->byHash[$rowHash];
        }

        if (is_string($id) && isset($this->byId[$id])) {
            return $this->byId[$id];
        }

        return null;
    }

    /**
     * The row's input as text a reader can act on.
     *
     * A single-key input (the common `question:` shape) prints as the bare
     * value: wrapping one string in JSON adds punctuation and no information.
     * Anything richer keeps its structure, because for a multi-field input the
     * field names are half the meaning.
     */
    public static function inputText(DatasetSample $sample): ?string
    {
        if ($sample->input === []) {
            return null;
        }

        $input = $sample->input;

        if (count($input) === 1) {
            $only = reset($input);

            if (is_string($only) && $only !== '') {
                return $only;
            }
        }

        return self::encode($input);
    }

    public static function expectedText(DatasetSample $sample): ?string
    {
        $expected = $sample->expectedOutput;

        if ($expected === null || $expected === '' || $expected === []) {
            return null;
        }

        return is_string($expected) ? $expected : self::encode($expected);
    }

    private static function encode(mixed $value): ?string
    {
        $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? null : $encoded;
    }
}
