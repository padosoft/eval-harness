<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Online;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Jobs\EvaluateSampleJob;
use Padosoft\EvalHarness\Metrics\MetricResolver;
use Padosoft\EvalHarness\Support\RuntimeOptions;

/**
 * Queue job that judges one captured live interaction with the
 * configured online metric and persists an {@see OnlineScore}, then
 * re-evaluates drift for the dataset.
 *
 * The interaction is carried as queue-safe scalars (mirroring
 * {@see EvaluateSampleJob}'s serialization
 * discipline); no closures or models cross the queue boundary.
 */
final class JudgeLiveSampleJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    /**
     * @param  array<string, mixed>  $input
     */
    public function __construct(
        public readonly string $dataset,
        public readonly string $sampleId,
        public readonly array $input,
        public readonly string $expected,
        public readonly string $actual,
        ?string $queue = null,
        ?string $connection = null,
    ) {
        if (is_string($queue) && trim($queue) !== '') {
            $this->onQueue(trim($queue));
        }

        if (is_string($connection) && trim($connection) !== '') {
            $this->onConnection(trim($connection));
        }
    }

    public function handle(
        MetricResolver $resolver,
        ConfigRepository $config,
        OnlineDriftAlert $drift,
        InteractionRetention $retention,
    ): void {
        $alias = $config->get('eval-harness.online.metric', 'llm-as-judge');
        $alias = is_string($alias) && trim($alias) !== '' ? trim($alias) : 'llm-as-judge';

        $metric = $resolver->resolve($alias);

        $sample = new DatasetSample(
            id: $this->sampleId,
            input: $this->input,
            expectedOutput: $this->expected,
        );

        $score = $metric->score($sample, $this->actual);

        $passThreshold = RuntimeOptions::normalizeUnitInterval(
            $config->get('eval-harness.online.pass_threshold'),
            0.7,
        );

        $now = Carbon::now();

        $columns = [
            'dataset' => $this->dataset,
            'sample_id' => $this->sampleId,
            'metric' => $alias,
            'score' => $score->score,
            'passed' => $score->score >= $passThreshold,
            'judge_model' => $this->judgeModel($config),
            'details' => $score->details,
            'judged_at' => $now,
        ];

        // Retention is opt-in and redacted at the boundary: the interaction is
        // never written raw and then cleaned up later, because "later" is where
        // backups, replicas and log shippers already took a copy.
        $retained = $retention->prepare($this->input, $this->expected, $this->actual);

        if ($retained !== null) {
            $columns = array_merge($columns, $retained->toColumns((string) $now->toDateTimeString()));
        }

        $row = new OnlineScore;
        $row->fill($columns);
        $row->save();

        $drift->evaluate($this->dataset);
    }

    private function judgeModel(ConfigRepository $config): ?string
    {
        $model = $config->get('eval-harness.metrics.llm_as_judge.model');

        return is_string($model) && trim($model) !== '' ? trim($model) : null;
    }
}
