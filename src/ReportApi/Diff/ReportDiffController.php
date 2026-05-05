<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\ReportApi\Diff;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;
use Padosoft\EvalHarness\Exceptions\EvalRunException;
use Padosoft\EvalHarness\ReportApi\ReportApiSchema;
use Padosoft\EvalHarness\ReportApi\ReportArtifact;
use Padosoft\EvalHarness\ReportApi\ReportArtifactRepository;
use Padosoft\EvalHarness\ReportApi\ReportArtifactUnavailableException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class ReportDiffController
{
    public function __construct(
        private readonly ReportDiffComputer $computer,
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

        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new UnprocessableEntityHttpException('Report JSON artifact is malformed.', $e);
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new UnprocessableEntityHttpException('Report JSON artifact must decode to an object.');
        }

        /** @var array<string, mixed> $decoded */

        return [
            'artifact' => $artifact,
            'decoded' => $decoded,
        ];
    }
}
