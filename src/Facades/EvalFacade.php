<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Facades;

use Illuminate\Support\Facades\Facade;
use Padosoft\EvalHarness\Datasets\DatasetBuilder;
use Padosoft\EvalHarness\Datasets\GoldenDataset;
use Padosoft\EvalHarness\EvalEngine;
use Padosoft\EvalHarness\Reports\EvalReport;

/**
 * Laravel-idiomatic facade for {@see EvalEngine}.
 *
 * The class is named `EvalFacade` because `Eval` is a reserved
 * keyword in PHP and cannot be used as a class identifier (parse
 * error). Laravel's auto-discovery declares the alias `Eval` →
 * this FQCN under `extra.laravel.aliases`, so calling code reads
 * as `Eval::dataset(...)` while the underlying class follows PHP
 * lexical rules.
 *
 * Usage:
 *
 *   use Eval; // alias declared via composer.json
 *
 *   Eval::dataset('rag.factuality.fy2026')
 *       ->loadFromYaml('eval/golden/factuality.yml')
 *       ->withMetrics(['exact-match'])
 *       ->register();
 *
 *   $report = Eval::run('rag.factuality.fy2026', $callable);
 *
 * Code that imports the FQCN directly uses the canonical name:
 *
 *   use Padosoft\EvalHarness\Facades\EvalFacade;
 *
 * @method static DatasetBuilder dataset(string $name)
 * @method static EvalReport run(string $datasetName, callable $systemUnderTest)
 * @method static bool hasDataset(string $name)
 * @method static GoldenDataset getDataset(string $name)
 * @method static list<string> registeredDatasetNames()
 * @method static void reset()
 *
 * @see EvalEngine
 */
final class EvalFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return EvalEngine::class;
    }
}
