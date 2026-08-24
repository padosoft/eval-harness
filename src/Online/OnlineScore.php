<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Online;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Historical store of online (production-sampled) judge scores.
 *
 * @property string $dataset
 * @property string $sample_id
 * @property array<string, mixed>|null $input
 * @property string|null $expected_output
 * @property string|null $actual_output
 * @property string|null $redactor
 * @property Carbon|null $redacted_at
 * @property string $metric
 * @property float $score
 * @property bool $passed
 * @property string|null $judge_model
 * @property array<string, mixed>|null $details
 * @property Carbon $judged_at
 */
final class OnlineScore extends Model
{
    protected $table = 'eval_harness_online_scores';

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'score' => 'float',
        'passed' => 'boolean',
        'input' => 'array',
        'details' => 'array',
        'judged_at' => 'datetime',
        'redacted_at' => 'datetime',
    ];

    /**
     * Whether this row carries the interaction and not just its score.
     *
     * Retention is opt-in, so most rows answer false — and a promoter has to
     * check rather than assume, or an upgraded install would promote a dataset
     * of empty questions.
     */
    public function isRetained(): bool
    {
        return is_array($this->input) && is_string($this->expected_output);
    }

    /**
     * Typed query starter scoped to a dataset. Exposed as an explicit
     * static (rather than an Eloquent scope) so static analysis can
     * verify call sites without Larastan.
     *
     * @return Builder<OnlineScore>
     */
    public static function forDataset(string $dataset): Builder
    {
        return self::query()->where('dataset', $dataset);
    }
}
