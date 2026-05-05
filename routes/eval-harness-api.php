<?php

declare(strict_types=1);

use Illuminate\Contracts\Routing\Registrar;
use Padosoft\EvalHarness\ReportApi\Diff\ReportDiffController;
use Padosoft\EvalHarness\ReportApi\ReportArtifactController;

return static function (Registrar $router, string $prefix, array $middleware): void {
    $router->group([
        'prefix' => $prefix,
        'middleware' => $middleware,
        'as' => 'eval-harness.api.',
    ], static function () use ($router): void {
        $router->get('/reports', [ReportArtifactController::class, 'index'])->name('reports.index');
        $router->get('/reports/{id}', [ReportArtifactController::class, 'show'])
            ->where('id', '[A-Za-z0-9_-]+')
            ->name('reports.show');
        $router->get('/reports/{id}/cohorts', [ReportArtifactController::class, 'cohorts'])
            ->where('id', '[A-Za-z0-9_-]+')
            ->name('reports.cohorts');
        $router->get('/reports/{id}/histograms', [ReportArtifactController::class, 'histograms'])
            ->where('id', '[A-Za-z0-9_-]+')
            ->name('reports.histograms');
        $router->get('/reports/{id}/rows.csv', [ReportArtifactController::class, 'rowsCsv'])
            ->where('id', '[A-Za-z0-9_-]+')
            ->name('reports.rows');
        $router->get('/reports/{id}/download', [ReportArtifactController::class, 'download'])
            ->where('id', '[A-Za-z0-9_-]+')
            ->name('reports.download');
        $router->get('/reports/{id}/diff/{otherId}', [ReportDiffController::class, 'show'])
            ->where('id', '[A-Za-z0-9_-]+')
            ->where('otherId', '[A-Za-z0-9_-]+')
            ->name('reports.diff');
    });
};
