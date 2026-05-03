<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Outputs;

use Padosoft\EvalHarness\Exceptions\EvalRunException;

/**
 * Preserves saved-output sample IDs before PHP array-key coercion.
 */
final class SavedOutputs
{
    /**
     * @var list<array{id: string, actual_output: string}>
     */
    private array $entries;

    /**
     * @param  list<mixed>  $entries
     */
    public function __construct(array $entries)
    {
        if ($entries !== [] && ! array_is_list($entries)) {
            throw new EvalRunException('Saved output entries must be a list of entry arrays; use SavedOutputs::fromMap() for keyed maps.');
        }

        $normalizedEntries = [];
        $seen = [];
        foreach ($entries as $index => $entry) {
            if (! is_array($entry)) {
                throw new EvalRunException(sprintf('Saved output entry at index %d must be an array.', $index));
            }

            $id = $entry['id'] ?? null;
            if (! is_string($id)) {
                throw new EvalRunException(sprintf('Saved output entry at index %d must contain a string id.', $index));
            }

            if ($id === '') {
                throw new EvalRunException(sprintf('Saved output entry at index %d contains an empty sample id.', $index));
            }

            $actualOutput = $entry['actual_output'] ?? null;
            if (! is_string($actualOutput)) {
                throw new EvalRunException(sprintf(
                    "Saved output entry for sample '%s' at index %d must contain a string actual_output.",
                    $id,
                    $index,
                ));
            }

            if (array_key_exists($id, $seen)) {
                throw new EvalRunException(sprintf("Duplicate saved output for sample '%s'.", $id));
            }

            $seen[$id] = true;
            $normalizedEntries[] = ['id' => $id, 'actual_output' => $actualOutput];
        }

        $this->entries = $normalizedEntries;
    }

    /**
     * @param  array<array-key, mixed>  $outputs
     *
     * Use the entry-list constructor when numeric-string sample IDs must be
     * preserved; PHP coerces list-shaped numeric keys before this method runs.
     */
    public static function fromMap(array $outputs, string $context): self
    {
        if ($outputs !== [] && array_is_list($outputs)) {
            throw new EvalRunException(sprintf('Saved outputs for %s must be a keyed map of sample id to output string.', $context));
        }

        $entries = [];
        foreach ($outputs as $sampleId => $actualOutput) {
            $id = (string) $sampleId;
            if ($id === '') {
                throw new EvalRunException(sprintf('Saved outputs for %s contain an empty sample id.', $context));
            }

            if (! is_string($actualOutput)) {
                throw new EvalRunException(sprintf(
                    "Saved output for sample '%s' in %s must be a string; got %s.",
                    $id,
                    $context,
                    get_debug_type($actualOutput),
                ));
            }

            $entries[] = ['id' => $id, 'actual_output' => $actualOutput];
        }

        return new self($entries);
    }

    /**
     * @return list<array{id: string, actual_output: string}>
     */
    public function entries(): array
    {
        return $this->entries;
    }
}
