<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\ReportApi\Adversarial;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Padosoft\EvalHarness\Exceptions\EvalRunException;
use Padosoft\EvalHarness\ReportApi\ReportApiSchema;
use Padosoft\EvalHarness\ReportApi\ReportArtifactUnavailableException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class ManifestController
{
    public function index(Request $request, ManifestRepository $repository): JsonResponse
    {
        if (! $repository->discoveryEnabled()) {
            return $this->discoveryNotConfigured();
        }

        try {
            $summaries = $repository->summaries();
        } catch (ReportArtifactUnavailableException $e) {
            throw new ServiceUnavailableHttpException(null, 'Adversarial manifest listing could not be read.', $e);
        }

        return new JsonResponse([
            'schema_version' => ReportApiSchema::VERSION,
            'schema' => ReportApiSchema::SCHEMA_ADVERSARIAL_MANIFESTS,
            'data' => $summaries,
        ]);
    }

    public function show(Request $request, ManifestRepository $repository, string $name): JsonResponse
    {
        if (! $repository->discoveryEnabled()) {
            return $this->discoveryNotConfigured();
        }

        try {
            $manifest = $repository->find($name);
        } catch (ReportArtifactUnavailableException $e) {
            throw new ServiceUnavailableHttpException(null, 'Adversarial manifest contents could not be read.', $e);
        } catch (InvalidManifestPayloadException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        } catch (EvalRunException $e) {
            throw new NotFoundHttpException($e->getMessage(), $e);
        }

        return new JsonResponse([
            'schema_version' => ReportApiSchema::VERSION,
            'schema' => ReportApiSchema::SCHEMA_ADVERSARIAL_MANIFEST,
            'data' => (new ManifestResource($manifest))->toArray($request),
        ]);
    }

    private function discoveryNotConfigured(): JsonResponse
    {
        return new JsonResponse([
            'schema_version' => ReportApiSchema::VERSION,
            'error' => 'discovery_not_configured',
            'message' => 'Adversarial manifest discovery is not configured. Set eval-harness.adversarial.manifests.disk to enable.',
        ], 404);
    }
}
