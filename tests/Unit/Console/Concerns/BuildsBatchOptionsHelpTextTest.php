<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Console\Concerns;

use Padosoft\EvalHarness\Console\AdversarialCommand;
use Padosoft\EvalHarness\Console\EvalCommand;
use Padosoft\EvalHarness\Tests\TestCase;
use ReflectionClass;

final class BuildsBatchOptionsHelpTextTest extends TestCase
{
    public function test_help_text_lists_every_batch_flag(): void
    {
        // The trait's `BATCH_FLAGS` constant is the single source
        // of truth for the runtime warning that fires when
        // `--outputs` is set alongside any batch flag. Both command
        // `$description` strings ALSO list the same flag set so the
        // contract is visible from `--help`. Laravel command
        // signatures are static and cannot be built from a runtime
        // constant, so the help text is a hand-written copy. This
        // test cross-checks both descriptions against `BATCH_FLAGS`
        // so a future flag addition cannot land with only the
        // constant updated and the help text stale.
        $batchFlags = $this->batchFlagsConstant();

        $evalCommand = $this->app->make(EvalCommand::class);
        $evalDescription = $evalCommand->getDescription();
        foreach ($batchFlags as $flag) {
            $this->assertStringContainsString(
                $flag,
                $evalDescription,
                "EvalCommand `\$description` is missing batch flag '{$flag}'. Update the description string AND BATCH_FLAGS together when adding new batch flags.",
            );
        }

        $adversarialCommand = $this->app->make(AdversarialCommand::class);
        $adversarialDescription = $adversarialCommand->getDescription();
        foreach ($batchFlags as $flag) {
            $this->assertStringContainsString(
                $flag,
                $adversarialDescription,
                "AdversarialCommand `\$description` is missing batch flag '{$flag}'. Update the description string AND BATCH_FLAGS together when adding new batch flags.",
            );
        }
    }

    /**
     * @return list<string>
     */
    private function batchFlagsConstant(): array
    {
        // Reach the private constant via reflection on a concrete
        // command that uses the trait. The constant name is
        // `BATCH_FLAGS` on the trait; PHP exposes trait constants
        // through the consuming class.
        $reflection = new ReflectionClass(EvalCommand::class);
        /** @var list<string> $flags */
        $flags = $reflection->getConstant('BATCH_FLAGS');

        return $flags;
    }
}
