<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\ReportApi\Batches;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Padosoft\EvalHarness\Batches\BatchLiveRegistry;
use Padosoft\EvalHarness\Batches\CacheBatchResultStore;
use Padosoft\EvalHarness\Exceptions\EvalRunException;
use Padosoft\EvalHarness\ReportApi\ReportApiSchema;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class BatchLiveController
{
    public function live(Request $request, BatchLiveRegistry $registry): JsonResponse
    {
        return new JsonResponse([
            'schema_version' => ReportApiSchema::VERSION,
            'schema' => ReportApiSchema::SCHEMA_BATCHES_LIVE,
            'data' => (new BatchLiveResource($registry->live()))->toArray($request),
        ]);
    }

    public function progress(Request $request, CacheBatchResultStore $resultStore, string $id): JsonResponse
    {
        try {
            $metadata = $resultStore->metadata($id);
        } catch (EvalRunException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), previous: $e);
        }

        if ($metadata === null) {
            throw new NotFoundHttpException(sprintf("Batch '%s' progress metadata was not found.", $id));
        }

        try {
            $progress = $resultStore->progress($id);
        } catch (EvalRunException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), previous: $e);
        }

        return new JsonResponse([
            'schema_version' => ReportApiSchema::VERSION,
            'schema' => ReportApiSchema::SCHEMA_BATCH_PROGRESS,
            'data' => (new BatchProgressResource([
                'id' => $id,
                'status' => $metadata['status'],
                'sample_count' => $metadata['sample_count'],
                'ttl_seconds' => $metadata['ttl_seconds'],
                'successes' => $progress['successes'],
                'failures' => $progress['failures'],
            ]))->toArray($request),
        ]);
    }
}
