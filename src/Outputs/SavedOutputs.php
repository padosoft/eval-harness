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
     * @param  list<array{id: string, actual_output: string}>  $entries
     */
    public function __construct(array $entries)
    {
        $seen = [];
        foreach ($entries as $index => $entry) {
            $id = $entry['id'];
            if ($id === '') {
                throw new EvalRunException(sprintf('Saved output entry at index %d contains an empty sample id.', $index));
            }

            if (array_key_exists($id, $seen)) {
                throw new EvalRunException(sprintf("Duplicate saved output for sample '%s'.", $id));
            }

            $seen[$id] = true;
        }

        $this->entries = $entries;
    }

    /**
     * @param  array<array-key, mixed>  $outputs
     */
    public static function fromMap(array $outputs, string $context): self
    {
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

    /**
     * @return array<array-key, string>
     */
    public function toMap(): array
    {
        $map = [];
        foreach ($this->entries as $entry) {
            $map[$entry['id']] = $entry['actual_output'];
        }

        return $map;
    }
}
