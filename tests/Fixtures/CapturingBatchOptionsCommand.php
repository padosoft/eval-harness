<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Fixtures;

use Illuminate\Console\Command;
use Padosoft\EvalHarness\Batches\BatchOptions;
use Padosoft\EvalHarness\Console\Concerns\BuildsBatchOptions;
use Padosoft\EvalHarness\Exceptions\EvalHarnessException;

/**
 * Test-only Artisan command that exercises {@see BuildsBatchOptions}
 * end-to-end and exposes the resulting {@see BatchOptions} so unit
 * tests can assert what the trait actually produced for a given mix
 * of CLI flags + profile config.
 */
final class CapturingBatchOptionsCommand extends Command
{
    use BuildsBatchOptions;

    /** @var string */
    protected $signature = 'eval-harness-test:capture-batch
        {--batch=serial : Batch mode}
        {--batch-profile= : Operational profile}
        {--concurrency=1 : Producer fan-out cap}
        {--queue= : Queue name}
        {--timeout= : Per-sample timeout seconds}
        {--batch-timeout= : Per-window wait timeout seconds}
        {--result-ttl-seconds= : Result-store TTL override}
        {--chunk-size= : Producer dispatch window}
        {--rate-limit= : Maximum dispatches per rate window}
        {--rate-window-seconds= : Rolling rate window}
        {--checkpoint-every= : Checkpoint interval}';

    /** @var string */
    protected $description = 'Test-only command capturing the materialised BatchOptions for trait assertions.';

    public ?BatchOptions $captured = null;

    public function handle(): int
    {
        try {
            $this->captured = $this->batchOptions();

            return self::SUCCESS;
        } catch (EvalHarnessException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
