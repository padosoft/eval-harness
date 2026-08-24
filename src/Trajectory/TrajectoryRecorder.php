<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Trajectory;

/**
 * Where a system under test leaves the trajectory it just produced.
 *
 * The `Metric` contract takes `(DatasetSample, string $actualOutput)`, and
 * widening it for one family of metrics would change every implementation in
 * the package and every one a host has written. So the trajectory travels
 * beside the answer rather than inside it: the runner records it, the engine
 * hands it to the metrics that asked for one, and everything else is untouched.
 *
 * ```php
 * final class MyAgentRunner implements SampleRunner
 * {
 *     public function __construct(private readonly TrajectoryRecorder $trajectories) {}
 *
 *     public function run(SampleInvocation $sample): string
 *     {
 *         $result = MyAgent::handle($sample->input);
 *
 *         $this->trajectories->record($sample->id, new Trajectory(
 *             toolCalls: $result->toolCalls(),
 *             steps: $result->stepCount(),
 *             finishReason: $result->finishReason(),
 *         ));
 *
 *         return $result->text();
 *     }
 * }
 * ```
 *
 * Recording without a repetition index — the common case, since a runner has no
 * reason to know it is the third execution of a row — files under the row and
 * is read back by any repetition. Passing the index explicitly (which the
 * engine does when it has one) keeps per-execution trajectories apart, so a row
 * that called a different tool on its second try is visible as exactly that.
 */
final class TrajectoryRecorder
{
    /** @var array<string, Trajectory> */
    private array $trajectories = [];

    public function record(string $sampleId, Trajectory $trajectory, ?int $repetition = null): void
    {
        $this->trajectories[self::key($sampleId, $repetition)] = $trajectory;
    }

    /**
     * The trajectory for one execution, falling back to the row's.
     */
    public function for(string $sampleId, ?int $repetition = null): ?Trajectory
    {
        if ($repetition !== null) {
            $exact = $this->trajectories[self::key($sampleId, $repetition)] ?? null;

            if ($exact instanceof Trajectory) {
                return $exact;
            }
        }

        return $this->trajectories[self::key($sampleId, null)] ?? null;
    }

    public function has(string $sampleId, ?int $repetition = null): bool
    {
        return $this->for($sampleId, $repetition) instanceof Trajectory;
    }

    /**
     * Drop everything recorded so far.
     *
     * Called by the engine at the start of a run: the recorder is a singleton,
     * and a second run in the same process must not score against the first
     * run's tool calls.
     */
    public function flush(): void
    {
        $this->trajectories = [];
    }

    public function count(): int
    {
        return count($this->trajectories);
    }

    private static function key(string $sampleId, ?int $repetition): string
    {
        return $repetition === null ? $sampleId : $sampleId."\0".$repetition;
    }
}
