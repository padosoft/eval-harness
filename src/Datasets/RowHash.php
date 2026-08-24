<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Datasets;

/**
 * Content identity for a dataset row.
 *
 * A regression gate has to answer "is *this* row worse than it was last
 * week?", and the sample id cannot answer it. Ids get renamed, rows get
 * reordered when somebody sorts the YAML, and a row whose question was
 * rewritten keeps its id while becoming a different test. Joining two runs on
 * the id therefore compares things that are not the same row, and misses
 * things that are.
 *
 * The hash is taken over what makes a row a test — its input and its expected
 * output — and deliberately not over:
 *
 *   - the **id**, so renaming `sample-14` to `refund-policy` keeps its history;
 *   - the **metadata**, so adding a tag or a cohort does not orphan the row;
 *   - the **key order**, so a reformatted YAML file is still the same dataset.
 *
 * Editing the question or the expected answer *does* change the hash, and that
 * is correct: the row shows up as removed and a new one as added, because the
 * old measurements no longer describe the new test.
 */
final class RowHash
{
    /** Enough of the digest to be unambiguous in a table or a CLI line. */
    public const SHORT_LENGTH = 12;

    public static function for(DatasetSample $sample): string
    {
        return self::fromParts($sample->input, $sample->expectedOutput);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromParts(array $input, mixed $expectedOutput): string
    {
        $canonical = json_encode(
            [
                'input' => self::canonicalise($input),
                'expected' => self::canonicalise($expectedOutput),
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );

        // json_encode returns false only for unencodable input (a resource, or
        // malformed UTF-8). Such a row cannot be hashed structurally, so fall
        // back to a serialisation that always produces something stable rather
        // than throwing during a run.
        if ($canonical === false) {
            $canonical = serialize([$input, $expectedOutput]);
        }

        return hash('sha256', $canonical);
    }

    public static function short(string $hash): string
    {
        return substr($hash, 0, self::SHORT_LENGTH);
    }

    /**
     * Recursively sort associative arrays by key so that two YAML files
     * differing only in key order hash identically. Lists keep their order:
     * in a list the order is part of the content (a ranked retrieval, a
     * conversation), not an accident of formatting.
     */
    private static function canonicalise(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $canonical = array_map(static fn (mixed $item): mixed => self::canonicalise($item), $value);

        if (! array_is_list($canonical)) {
            ksort($canonical);
        }

        return $canonical;
    }
}
