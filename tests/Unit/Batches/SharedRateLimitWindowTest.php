<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Batches;

use Padosoft\EvalHarness\Batches\BatchOptions;
use Padosoft\EvalHarness\Batches\LazyParallelBatch;
use Padosoft\EvalHarness\Batches\RateLimitWindow;
use Padosoft\EvalHarness\Contracts\SampleInvocation;
use Padosoft\EvalHarness\Contracts\SampleRunner;
use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Tests\TestCase;

/**
 * A rate limit is a promise about how hard a provider gets hit, and a run that
 * repeats its dataset calls `run()` once per repetition. A limiter rebuilt per
 * call would let `--repetitions=3 --rate-limit=10` dispatch thirty requests
 * inside a single configured window — the exact thing the flag exists to
 * prevent — so the caller builds one window and hands it to every pass.
 */
final class SharedRateLimitWindowTest extends TestCase
{
    public function test_a_supplied_window_keeps_counting_across_invocations(): void
    {
        $this->app['config']->set('queue.default', 'sync');
        $this->app['config']->set('cache.default', 'array');

        /** @var LazyParallelBatch $batch */
        $batch = $this->app->make(LazyParallelBatch::class);

        // Two dispatches per window: the first pass fills it exactly, so a
        // second pass through the same window must be told to wait.
        $window = new RateLimitWindow(rateLimit: 2, rateWindowSeconds: 3600);
        $samples = $this->samples();

        // The batch records dispatches against the monotonic clock, so the
        // probe has to read the same clock — microtime() would put every record
        // decades in the past and prune them all.
        $now = static fn (): float => hrtime(true) / 1_000_000_000.0;

        $this->assertSame(0, $window->nextWaitMicroseconds($now()));

        $batch->run(
            samples: $samples,
            sampleInvocations: $this->sampleInvocations($samples),
            runner: new SharedWindowRunner,
            options: BatchOptions::lazyParallel(concurrency: 2, timeoutSeconds: 5, rateLimit: 2, rateWindowSeconds: 3600),
            rateLimiter: $window,
        );

        $this->assertGreaterThan(
            0,
            $window->nextWaitMicroseconds($now()),
            'the window must still hold the first pass; a per-call limiter would have been discarded',
        );
    }

    public function test_windows_are_built_from_the_options(): void
    {
        $window = LazyParallelBatch::windowFor(
            BatchOptions::lazyParallel(rateLimit: 4, rateWindowSeconds: 15),
        );

        $this->assertNotNull($window);
        $this->assertSame(4, $window->rateLimit);
        $this->assertSame(15, $window->rateWindowSeconds);
        $this->assertNull(LazyParallelBatch::windowFor(BatchOptions::lazyParallel()));
    }

    /**
     * @return list<DatasetSample>
     */
    private function samples(): array
    {
        return [
            new DatasetSample(id: 's1', input: ['answer' => 'one'], expectedOutput: 'one'),
            new DatasetSample(id: 's2', input: ['answer' => 'two'], expectedOutput: 'two'),
        ];
    }

    /**
     * @param  list<DatasetSample>  $samples
     * @return list<SampleInvocation>
     */
    private function sampleInvocations(array $samples): array
    {
        return array_map(
            static fn (DatasetSample $sample): SampleInvocation => SampleInvocation::fromDatasetSample($sample),
            $samples,
        );
    }
}

final class SharedWindowRunner implements SampleRunner
{
    public function run(SampleInvocation $sample): string
    {
        return (string) $sample->input['answer'];
    }
}
