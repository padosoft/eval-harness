<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\ReportApi\Online;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Padosoft\EvalHarness\Online\OnlineTrendRepository;
use Padosoft\EvalHarness\ReportApi\ReportApiSchema;
use Padosoft\EvalHarness\Support\RuntimeOptions;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class OnlineTrendController
{
    public function show(Request $request, OnlineTrendRepository $trends, ConfigRepository $config, string $dataset): JsonResponse
    {
        if ($dataset === '.' || $dataset === '..' || str_contains($dataset, '/') || str_contains($dataset, '\\')) {
            throw new NotFoundHttpException("Online trend for dataset '{$dataset}' was not found.");
        }

        $limit = $this->limit($request->query('limit'));
        $threshold = RuntimeOptions::normalizeUnitInterval(
            $config->get('eval-harness.online.alert.threshold'),
            0.8,
        );

        $points = $trends->trend($dataset, $limit);

        return new JsonResponse([
            'schema_version' => ReportApiSchema::VERSION,
            'schema' => ReportApiSchema::SCHEMA_ONLINE_TREND,
            'data' => (new OnlineTrendResource([
                'dataset' => $dataset,
                'limit' => $limit,
                'threshold' => $threshold,
                'points' => $points,
            ]))->toArray($request),
        ]);
    }

    private function limit(mixed $value): int
    {
        if (is_string($value) && ctype_digit($value)) {
            return max(1, min(365, (int) $value));
        }

        if (is_int($value)) {
            return max(1, min(365, $value));
        }

        return 30;
    }
}
