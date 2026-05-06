<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\ReportApi\Trend;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Padosoft\EvalHarness\ReportApi\ReportApiSchema;
use Padosoft\EvalHarness\ReportApi\ReportArtifactUnavailableException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class DatasetTrendController
{
    public function show(Request $request, DatasetTrendRepository $trends, string $name): JsonResponse
    {
        if ($name === '.' || $name === '..' || str_contains($name, '/') || str_contains($name, '\\')) {
            throw new NotFoundHttpException("Dataset '{$name}' trend was not found.");
        }

        $limit = $this->limit($request->query('limit'));

        try {
            $points = $trends->trend($name, $limit);
        } catch (ReportArtifactUnavailableException $e) {
            throw new ServiceUnavailableHttpException(null, 'Dataset trend listing could not be read.', $e);
        }

        return new JsonResponse([
            'schema_version' => ReportApiSchema::VERSION,
            'schema' => ReportApiSchema::SCHEMA_TREND,
            'data' => (new DatasetTrendResource([
                'dataset' => $name,
                'limit' => $limit,
                'points' => $points,
            ]))->toArray($request),
        ]);
    }

    private function limit(mixed $value): int
    {
        if (is_string($value) && ctype_digit($value)) {
            return max(1, min(100, (int) $value));
        }

        if (is_int($value)) {
            return max(1, min(100, $value));
        }

        return 30;
    }
}
