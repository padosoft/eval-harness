<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Fixtures;

use Illuminate\Contracts\Container\Container;
use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\EvalEngine;

/**
 * Test fixture: an invokable registrar passed to
 * `eval-harness:run --registrar=...` so the command can register a
 * dataset + bind the system-under-test in a single call.
 *
 * The class is autoloaded under the `tests/` PSR-4 prefix and is
 * therefore unavailable in production runs — exactly the right shape
 * for a fixture.
 */
final class TestRegistrar
{
    public function __construct(private readonly Container $container) {}

    public function __invoke(EvalEngine $engine): void
    {
        $engine->dataset('cli.smoke')
            ->withSamples([
                new DatasetSample(id: 's1', input: ['q' => 'a'], expectedOutput: 'hello'),
                new DatasetSample(id: 's2', input: ['q' => 'b'], expectedOutput: 'world'),
            ])
            ->withMetrics(['exact-match'])
            ->register();

        $this->container->bind('eval-harness.sut', static fn (): callable => static function (array $in): string {
            return match ($in['q'] ?? null) {
                'a' => 'hello',
                'b' => 'world',
                default => '',
            };
        });
    }
}
