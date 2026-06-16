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
        'details' => 'array',
        'judged_at' => 'datetime',
    ];

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
