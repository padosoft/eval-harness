<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Fixtures;

use Illuminate\Contracts\Container\Container;
use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\EvalEngine;

/**
 * Test fixture: registrar that drives a SUT returning invalid UTF-8
 * bytes (a stray 0xFF). Used to exercise the JSON-encoding failure
 * path in EvalCommand — without JSON_THROW_ON_ERROR + a guard, the
 * command would silently emit an empty payload and still exit 0
 * when no metric failed.
 */
final class InvalidUtf8Registrar
{
    public function __construct(private readonly Container $container) {}

    public function __invoke(EvalEngine $engine): void
    {
        $engine->dataset('cli.invalid-utf8')
            ->withSamples([
                new DatasetSample(id: 's1', input: ['q' => 'a'], expectedOutput: 'ok'),
            ])
            ->withMetrics(['exact-match'])
            ->register();

        $this->container->bind('eval-harness.sut', static fn (): callable => static function (array $in): string {
            // 0xFF is invalid as a UTF-8 continuation byte — json_encode
            // returns false unless JSON_INVALID_UTF8_SUBSTITUTE /
            // JSON_INVALID_UTF8_IGNORE are set, which we deliberately
            // do NOT set so encoding failures surface as command
            // failures.
            return "ok\xFF";
        });
    }
}
