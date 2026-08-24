<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Trajectory;

use Padosoft\EvalHarness\Exceptions\EvalRunException;

/**
 * One tool invocation an agent made while producing an answer.
 *
 * Deliberately small: a name, the arguments it was called with, where it sat in
 * the sequence, and whether it came back. Anything an individual runtime knows
 * beyond that goes in `$extra` rather than growing this shape — the DTO exists
 * to be the same across every agent runtime, and a field only one of them can
 * populate is a field nobody can assert against.
 */
final class ToolCall
{
    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $extra  runtime-specific detail; never interpreted here
     */
    public function __construct(
        public readonly string $name,
        public readonly array $arguments = [],
        public readonly ?string $result = null,
        public readonly ?string $error = null,
        public readonly ?int $durationMs = null,
        public readonly array $extra = [],
    ) {
        if ($name === '') {
            throw new EvalRunException('A tool call must have a non-empty name.');
        }
    }

    public function failed(): bool
    {
        return $this->error !== null;
    }

    /**
     * Were these arguments used, at least in part?
     *
     * Subset semantics, not equality: an assertion says "it looked up order 7",
     * and a runtime that also passes a trace id, a locale or a retry counter has
     * still done that. Requiring equality would make every assertion break the
     * first time a wrapper added a field.
     *
     * @param  array<string, mixed>  $expected
     */
    public function matchesArguments(array $expected): bool
    {
        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $this->arguments)) {
                return false;
            }

            if (! self::valuesMatch($value, $this->arguments[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $name = $payload['name'] ?? $payload['tool'] ?? null;

        if (! is_string($name) || $name === '') {
            throw new EvalRunException('A tool call entry must carry a non-empty "name".');
        }

        $arguments = $payload['arguments'] ?? $payload['args'] ?? [];

        return new self(
            name: $name,
            arguments: is_array($arguments) ? $arguments : [],
            result: self::stringOrNull($payload['result'] ?? null),
            error: self::stringOrNull($payload['error'] ?? null),
            durationMs: is_int($payload['duration_ms'] ?? null) ? $payload['duration_ms'] : null,
            extra: is_array($payload['extra'] ?? null) ? $payload['extra'] : [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = ['name' => $this->name, 'arguments' => $this->arguments];

        if ($this->result !== null) {
            $payload['result'] = $this->result;
        }

        if ($this->error !== null) {
            $payload['error'] = $this->error;
        }

        if ($this->durationMs !== null) {
            $payload['duration_ms'] = $this->durationMs;
        }

        if ($this->extra !== []) {
            $payload['extra'] = $this->extra;
        }

        return $payload;
    }

    /**
     * Scalars compare loosely on numeric strings — a tool called with `"7"` and
     * an expectation of `7` is the same call, and JSON round-trips turn one into
     * the other often enough that strict comparison would fail on transport
     * rather than on behaviour. Arrays recurse with the same subset rule.
     */
    private static function valuesMatch(mixed $expected, mixed $actual): bool
    {
        if (is_array($expected) && is_array($actual)) {
            foreach ($expected as $key => $value) {
                if (! array_key_exists($key, $actual) || ! self::valuesMatch($value, $actual[$key])) {
                    return false;
                }
            }

            return true;
        }

        if (is_numeric($expected) && is_numeric($actual)) {
            return (float) $expected === (float) $actual;
        }

        return $expected === $actual;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
