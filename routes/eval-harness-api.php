<?php

declare(strict_types=1);

use Illuminate\Contracts\Routing\Registrar;
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
    });
};
