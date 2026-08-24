<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Online;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Padosoft\EvalHarness\Exceptions\EvalRunException;
use Padosoft\EvalHarness\Support\RuntimeOptions;
use Throwable;

/**
 * Decides whether a production interaction may be kept, and redacts it first.
 *
 * ## Three defaults, each chosen against convenience
 *
 * **Retention is off.** The online monitor scores production traffic and stores
 * a number; keeping the *text* is a separate decision with a separate legal
 * basis, so it is a separate switch that somebody has to turn on deliberately.
 *
 * **A redactor is required.** With `require_redactor` on — the default —
 * retention without a bound {@see Redactor} does not silently store raw
 * production text: it raises. The failure mode this prevents is the one that
 * actually happens: retention gets enabled in a hurry to debug something, the
 * redactor binding is added "next sprint", and six months of customer questions
 * are sitting in a table nobody remembers agreeing to.
 *
 * **A broken redactor drops the interaction.** If the bound redactor throws or
 * cannot be resolved, the interaction is not retained. Losing a dataset row is
 * an inconvenience; storing the one string the redactor choked on is an
 * incident.
 *
 * Turning `require_redactor` off is legitimate — an internal dataset with no
 * personal data in it, a synthetic corpus — and it is spelled explicitly so
 * that the person turning it off is the person who decided it was safe.
 */
final class InteractionRetention
{
    public function __construct(
        private readonly Container $container,
        private readonly ConfigRepository $config,
    ) {}

    public function isEnabled(): bool
    {
        return RuntimeOptions::normalizeBoolean(
            $this->config->get('eval-harness.online.retention.enabled'),
            false,
        );
    }

    public function requiresRedactor(): bool
    {
        return RuntimeOptions::normalizeBoolean(
            $this->config->get('eval-harness.online.retention.require_redactor'),
            true,
        );
    }

    /**
     * Prepare an interaction for storage, or return null when it must not be kept.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws EvalRunException when retention is on but no redactor is bound and one is required
     */
    public function prepare(array $input, string $expected, string $actual): ?RetainedInteraction
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $redactor = $this->redactor();

        if ($redactor === null) {
            if ($this->requiresRedactor()) {
                throw new EvalRunException(
                    'Online interaction retention is enabled but no PII redactor is bound. '
                    .'Set eval-harness.online.retention.redactor to a class implementing Padosoft\\EvalHarness\\Online\\Redactor, '
                    .'or set retention.require_redactor to false if this dataset provably contains no personal data.',
                );
            }

            return new RetainedInteraction($input, $expected, $actual, null);
        }

        try {
            return new RetainedInteraction(
                input: $this->redactInput($redactor, $input),
                expected: $redactor->redact($expected),
                actual: $redactor->redact($actual),
                redactor: $redactor::class,
            );
        } catch (Throwable) {
            // Deliberately silent about the payload: an exception message
            // built from the text it failed on would put the unredacted
            // string in the log, which is the thing this class exists to
            // prevent.
            return null;
        }
    }

    private function redactor(): ?Redactor
    {
        $binding = $this->config->get('eval-harness.online.retention.redactor');

        if (! is_string($binding) || trim($binding) === '') {
            return null;
        }

        try {
            $resolved = $this->container->make(trim($binding));
        } catch (Throwable) {
            return null;
        }

        return $resolved instanceof Redactor ? $resolved : null;
    }

    /**
     * Apply the redactor to every string inside the input, at any depth.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function redactInput(Redactor $redactor, array $input): array
    {
        $redacted = [];

        foreach ($input as $key => $value) {
            $redacted[$key] = $this->redactValue($redactor, $value);
        }

        return $redacted;
    }

    private function redactValue(Redactor $redactor, mixed $value): mixed
    {
        if (is_string($value)) {
            return $redactor->redact($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        $redacted = [];

        foreach ($value as $key => $item) {
            // Keys carry data too — a map keyed by email address is not a
            // hypothetical — so they are redacted alongside the values.
            $redacted[is_string($key) ? $redactor->redact($key) : $key] = $this->redactValue($redactor, $item);
        }

        return $redacted;
    }
}
