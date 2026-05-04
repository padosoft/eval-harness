<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\ReportApi;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;
use Padosoft\EvalHarness\Exceptions\EvalRunException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class ReportArtifactController
{
    public function index(Request $request, ReportArtifactRepository $reports): JsonResponse
    {
        return new JsonResponse([
            'schema_version' => ReportApiSchema::VERSION,
            'data' => array_map(
                static fn (ReportArtifact $artifact): array => (new ReportArtifactResource($artifact))->toArray($request),
                $reports->all(),
            ),
        ]);
    }

    public function show(Request $request, ReportArtifactRepository $reports, string $id): JsonResponse
    {
        try {
            $artifact = $reports->find($id);
        } catch (ReportArtifactUnavailableException $e) {
            throw new ServiceUnavailableHttpException(null, 'Report artifact metadata could not be read.', $e);
        } catch (EvalRunException $e) {
            throw new NotFoundHttpException($e->getMessage(), $e);
        }

        try {
            $contents = $reports->contents($artifact);
        } catch (ReportArtifactUnavailableException $e) {
            throw new ServiceUnavailableHttpException(null, 'Report artifact contents could not be read.', $e);
        } catch (EvalRunException $e) {
            throw new NotFoundHttpException($e->getMessage(), $e);
        }

        return new JsonResponse([
            'schema_version' => ReportApiSchema::VERSION,
            'data' => [
                'artifact' => (new ReportArtifactResource($artifact))->toArray($request),
                'report' => $artifact->format === 'json' ? $this->decodeJsonReport($contents) : null,
                'content' => $artifact->format === 'markdown' ? $contents : null,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonReport(string $contents): array
    {
        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new UnprocessableEntityHttpException('Report JSON artifact is malformed.', $e);
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new UnprocessableEntityHttpException('Report JSON artifact must decode to an object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
