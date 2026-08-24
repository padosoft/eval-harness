<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Metrics\Trajectory;

use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Metrics\MetricScore;
use Padosoft\EvalHarness\Trajectory\Trajectory;

/**
 * Did the agent call the right tool with the right arguments?
 *
 * Calling `lookup_order` is not the same as calling it with the order number
 * the customer actually asked about, and an agent that looks up the wrong order
 * confidently is worse than one that looks up nothing. Arguments match as a
 * subset, so a runtime that adds a trace id or a locale does not break the
 * expectation.
 *
 * ```yaml
 * metadata:
 *   trajectory:
 *     tool_arguments:
 *       - tool: lookup_order
 *         arguments: { id: 7 }
 * ```
 */
final class ToolCalledWithMetric extends AbstractTrajectoryMetric
{
    public function name(): string
    {
        return 'tool-called-with';
    }

    protected function judge(DatasetSample $sample, Trajectory $trajectory): MetricScore
    {
        $expectations = $this->expectations($sample)['tool_arguments'] ?? null;

        if (! is_array($expectations) || $expectations === []) {
            return new MetricScore(1.0, [
                'expected' => [],
                'note' => 'No tool-argument expectations declared for this sample.',
            ]);
        }

        $satisfied = [];
        $unmet = [];
        $declared = [];

        foreach ($expectations as $expectation) {
            if (! is_array($expectation)) {
                continue;
            }

            $tool = $expectation['tool'] ?? $expectation['name'] ?? null;
            if (! is_string($tool) || $tool === '') {
                continue;
            }

            $arguments = $expectation['arguments'] ?? $expectation['args'] ?? [];
            $arguments = is_array($arguments) ? $arguments : [];
            $declared[] = ['tool' => $tool, 'arguments' => $arguments];

            $matched = $trajectory->calledWith($tool, $arguments);
            $satisfied[] = $matched;

            if (! $matched) {
                $unmet[] = ['tool' => $tool, 'arguments' => $arguments];
            }
        }

        if ($satisfied === []) {
            return new MetricScore(1.0, [
                'expected' => [],
                'note' => 'No usable tool-argument expectations declared for this sample.',
            ]);
        }

        return new MetricScore($this->fraction($satisfied), [
            'expected' => $declared,
            'unmet' => $unmet,
            'calls' => array_map(
                static fn ($call): array => ['tool' => $call->name, 'arguments' => $call->arguments],
                $trajectory->toolCalls,
            ),
        ]);
    }
}
