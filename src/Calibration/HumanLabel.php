<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Calibration;

/**
 * One human-labelled calibration case. `humanVerdict` is the ground
 * truth the judge is validated against: either 'pass' or 'fail'.
 */
final class HumanLabel
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function __construct(
        public readonly string $id,
        public readonly array $input,
        public readonly string $expected,
        public readonly string $actual,
        public readonly string $humanVerdict,
    ) {}
}
