<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Online;

/**
 * Removes personal data from a production interaction before it is stored.
 *
 * ## Why this is a contract and not an implementation
 *
 * Promoting a production failure into a regression test is the single most
 * valuable dataset row anybody can have: it is a real thing a real user asked,
 * on which the pipeline really failed. It is also, for exactly the same reason,
 * the row most likely to contain a name, an order number, an address, or a
 * medical detail.
 *
 * A redactor good enough to be trusted with that is a package of its own —
 * `padosoft/laravel-pii-redactor` is one, a regex the host already owns is
 * another, a call to a classifier is a third — and which one is right depends
 * on the jurisdiction, the data, and the retention policy. So this package
 * declares the seam and refuses to guess: an eval harness shipping its own
 * half-good PII regex would be a package quietly promising a compliance
 * property it cannot keep.
 *
 * ## Text in, text out
 *
 * One method, deliberately. A host implementing a text redactor plugs straight
 * in; {@see InteractionRetention} walks the input array and applies it to every
 * string inside, so a nested structure needs no extra work from the
 * implementer.
 *
 * Bind it by class name, or by any container key, under
 * `eval-harness.online.retention.redactor`.
 */
interface Redactor
{
    /**
     * Return the text with personal data removed or masked.
     *
     * Implementations must be total: no exception for unexpected input, no
     * null return. A redactor that throws on one odd string would drop the
     * interaction it was meant to protect.
     */
    public function redact(string $text): string;
}
