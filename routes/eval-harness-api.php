<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Padosoft\EvalHarness\ReportApi\ReportArtifactController;

Route::get('/reports', [ReportArtifactController::class, 'index'])->name('reports.index');
Route::get('/reports/{report}', [ReportArtifactController::class, 'show'])
    ->where('report', '[A-Za-z0-9_-]+')
    ->name('reports.show');
