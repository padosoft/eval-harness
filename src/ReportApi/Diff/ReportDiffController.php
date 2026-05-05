<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\ReportApi\Diff;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Padosoft\EvalHarness\Exceptions\EvalRunException;
use Padosoft\EvalHarness\ReportApi\ReportApiSchema;
use Padosoft\EvalHarness\ReportApi\ReportArtifact;
use Padosoft\EvalHarness\ReportApi\ReportArtifactRepository;
use Padosoft\EvalHarness\ReportApi\ReportArtifactUnavailableException;
use Padosoft\EvalHarness\ReportApi\ReportJsonDecoder;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class ReportDiffController
{
    public function __construct(
        private readonly ReportDiffComputer $computer,
        private readonly ReportJsonDecoder $jsonDecoder,
    ) {}

    public function show(
        Request $request,
        ReportArtifactRepository $reports,
        string $id,
        string $otherId,
    ): JsonResponse {
        $left = $this->loadJsonArtifact($reports, $id);
        $right = $this->loadJsonArtifact($reports, $otherId);

        try {
            $diff = $this->computer->compute($left['decoded'], $right['decoded']);
        } catch (ReportDiffSchemaMismatchException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }

        $resource = (new ReportDiffResource([
            'left' => $left['artifact'],
            'right' => $right['artifact'],
            'diff' => $diff,
        ]))->toArray($request);

        return new JsonResponse([
            'schema_version' => ReportApiSchema::VERSION,
            'schema' => ReportApiSchema::SCHEMA_DIFF,
            'data' => $resource,
        ]);
    }

    /**
     * @return array{artifact: ReportArtifact, decoded: array<string, mixed>}
     */
    private function loadJsonArtifact(ReportArtifactRepository $reports, string $id): array
    {
        try {
            $artifact = $reports->find($id);
        } catch (ReportArtifactUnavailableException $e) {
            throw new ServiceUnavailableHttpException(null, 'Report artifact metadata could not be read.', $e);
        } catch (EvalRunException $e) {
            throw new NotFoundHttpException($e->getMessage(), $e);
        }

        if ($artifact->format !== 'json') {
            throw new UnprocessableEntityHttpException('Report diff requires a JSON report on both sides.');
        }

        try {
            $contents = $reports->contents($artifact);
        } catch (ReportArtifactUnavailableException $e) {
            throw new ServiceUnavailableHttpException(null, 'Report artifact contents could not be read.', $e);
        }

        return [
            'artifact' => $artifact,
            'decoded' => $this->jsonDecoder->decodeObject($contents),
        ];
    }
}
