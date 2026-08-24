<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Online;

/**
 * One production interaction, ready to be persisted.
 *
 * `$redactor` names what processed it, and `$redactedAt` says when. Both are
 * stored, because "was this row redacted, and by what?" is a question that gets
 * asked months later — during an audit, or when a redactor turns out to have
 * had a bug — and a boolean column cannot answer it.
 */
final class RetainedInteraction
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function __construct(
        public readonly array $input,
        public readonly string $expected,
        public readonly string $actual,
        public readonly ?string $redactor,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toColumns(string $now): array
    {
        return [
            'input' => $this->input,
            'expected_output' => $this->expected,
            'actual_output' => $this->actual,
            'redactor' => $this->redactor,
            'redacted_at' => $this->redactor === null ? null : $now,
        ];
    }
}
