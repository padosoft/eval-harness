<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Online;

use Padosoft\EvalHarness\Exceptions\EvalRunException;
use Padosoft\EvalHarness\Online\InteractionRetention;
use Padosoft\EvalHarness\Online\Redactor;
use Padosoft\EvalHarness\Tests\TestCase;

final class InteractionRetentionTest extends TestCase
{
    /**
     * The online monitor stores a score by default. Keeping the *text* is a
     * different decision with a different legal basis, so it is a different
     * switch and it starts off.
     */
    public function test_retention_is_off_by_default(): void
    {
        $this->assertNull($this->retention()->prepare(['q' => 'hi'], 'expected', 'actual'));
    }

    /**
     * The failure this prevents is the one that actually happens: retention
     * gets switched on in a hurry, the redactor is "next sprint", and six
     * months of customer questions are sitting in a table.
     */
    public function test_retention_without_a_redactor_raises_rather_than_storing_raw_text(): void
    {
        config()->set('eval-harness.online.retention.enabled', true);

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('no PII redactor is bound');

        $this->retention()->prepare(['q' => 'hi'], 'expected', 'actual');
    }

    public function test_the_requirement_can_be_waived_explicitly(): void
    {
        config()->set('eval-harness.online.retention.enabled', true);
        config()->set('eval-harness.online.retention.require_redactor', false);

        $retained = $this->retention()->prepare(['q' => 'hi'], 'expected', 'actual');

        $this->assertNotNull($retained);
        $this->assertNull($retained->redactor, 'an unredacted row must say so');
        $this->assertSame('hi', $retained->input['q']);
    }

    public function test_a_bound_redactor_is_applied_to_input_expected_and_actual(): void
    {
        $this->enableWith(MaskingRedactor::class);

        $retained = $this->retention()->prepare(
            ['question' => 'refund for ada@example.com'],
            'we refunded ada@example.com',
            'contact ada@example.com',
        );

        $this->assertNotNull($retained);
        $this->assertSame('refund for [redacted]', $retained->input['question']);
        $this->assertSame('we refunded [redacted]', $retained->expected);
        $this->assertSame('contact [redacted]', $retained->actual);
        $this->assertSame(MaskingRedactor::class, $retained->redactor);
    }

    public function test_nested_input_is_redacted_at_any_depth(): void
    {
        $this->enableWith(MaskingRedactor::class);

        $retained = $this->retention()->prepare([
            'messages' => [
                ['role' => 'user', 'content' => 'write to ada@example.com'],
            ],
            'count' => 3,
        ], 'ok', 'ok');

        $this->assertNotNull($retained);
        $this->assertSame('write to [redacted]', $retained->input['messages'][0]['content']);
        $this->assertSame(3, $retained->input['count'], 'non-strings pass through untouched');
    }

    /**
     * A map keyed by email address is not a hypothetical.
     */
    public function test_string_keys_are_redacted_too(): void
    {
        $this->enableWith(MaskingRedactor::class);

        $retained = $this->retention()->prepare(['by' => ['ada@example.com' => 'yes']], 'ok', 'ok');

        $this->assertNotNull($retained);
        $this->assertArrayHasKey('[redacted]', $retained->input['by']);
    }

    /**
     * Losing a dataset row is an inconvenience; storing the one string the
     * redactor choked on is an incident.
     */
    public function test_a_throwing_redactor_drops_the_interaction(): void
    {
        $this->enableWith(ThrowingRedactor::class);

        $this->assertNull($this->retention()->prepare(['q' => 'hi'], 'expected', 'actual'));
    }

    public function test_a_binding_that_is_not_a_redactor_is_treated_as_absent(): void
    {
        config()->set('eval-harness.online.retention.enabled', true);
        config()->set('eval-harness.online.retention.redactor', \stdClass::class);

        $this->expectException(EvalRunException::class);

        $this->retention()->prepare(['q' => 'hi'], 'expected', 'actual');
    }

    public function test_an_unresolvable_binding_is_treated_as_absent(): void
    {
        config()->set('eval-harness.online.retention.enabled', true);
        config()->set('eval-harness.online.retention.redactor', 'no.such.binding');

        $this->expectException(EvalRunException::class);

        $this->retention()->prepare(['q' => 'hi'], 'expected', 'actual');
    }

    private function enableWith(string $redactor): void
    {
        config()->set('eval-harness.online.retention.enabled', true);
        config()->set('eval-harness.online.retention.redactor', $redactor);
    }

    private function retention(): InteractionRetention
    {
        /** @var InteractionRetention $retention */
        $retention = $this->app->make(InteractionRetention::class);

        return $retention;
    }
}

final class MaskingRedactor implements Redactor
{
    public function redact(string $text): string
    {
        return (string) preg_replace('/[\w.+-]+@[\w-]+\.[\w.]+/', '[redacted]', $text);
    }
}

final class ThrowingRedactor implements Redactor
{
    public function redact(string $text): string
    {
        throw new \RuntimeException('classifier unavailable');
    }
}
