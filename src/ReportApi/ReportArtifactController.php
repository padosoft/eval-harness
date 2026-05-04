<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\ReportApi;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use JsonException;
use Padosoft\EvalHarness\Exceptions\EvalRunException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class ReportArtifactController
{
    public function index(Request $request, ReportArtifactRepository $reports): JsonResponse
    {
        try {
            $artifacts = $reports->all();
        } catch (ReportArtifactUnavailableException $e) {
            throw new ServiceUnavailableHttpException(null, 'Report artifact listing could not be read.', $e);
        }

        return new JsonResponse([
            'schema_version' => ReportApiSchema::VERSION,
            'data' => array_map(
                static fn (ReportArtifact $artifact): array => (new ReportArtifactResource($artifact))->toArray($request),
                $artifacts,
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

    public function cohorts(Request $request, ReportArtifactRepository $reports, string $id): JsonResponse
    {
        try {
            $artifact = $reports->find($id);
        } catch (ReportArtifactUnavailableException $e) {
            throw new ServiceUnavailableHttpException(null, 'Report artifact metadata could not be read.', $e);
        } catch (EvalRunException $e) {
            throw new NotFoundHttpException($e->getMessage(), $e);
        }

        if ($artifact->format !== 'json') {
            throw new UnprocessableEntityHttpException('Artifact cohorts require a JSON report.');
        }

        $jsonReport = $this->decodeJsonArtifactReport($reports, $artifact);

        $cohorts = $jsonReport['cohorts'] ?? null;
        if (! is_array($cohorts) || ! array_is_list($cohorts)) {
            throw new UnprocessableEntityHttpException('Report artifact payload missing a valid cohorts array.');
        }

        return new JsonResponse([
            'schema_version' => ReportApiSchema::VERSION,
            'data' => [
                'artifact' => (new ReportArtifactResource($artifact))->toArray($request),
                'cohorts' => $cohorts,
            ],
        ]);
    }

    public function histograms(Request $request, ReportArtifactRepository $reports, string $id): JsonResponse
    {
        try {
            $artifact = $reports->find($id);
        } catch (ReportArtifactUnavailableException $e) {
            throw new ServiceUnavailableHttpException(null, 'Report artifact metadata could not be read.', $e);
        } catch (EvalRunException $e) {
            throw new NotFoundHttpException($e->getMessage(), $e);
        }

        if ($artifact->format !== 'json') {
            throw new UnprocessableEntityHttpException('Artifact histograms require a JSON report.');
        }

        $jsonReport = $this->decodeJsonArtifactReport($reports, $artifact);
        $distributions = $jsonReport['metric_distributions'] ?? null;

        if (! is_array($distributions)) {
            throw new UnprocessableEntityHttpException('Report artifact payload missing metric_distributions.');
        }

        $histograms = [];
        foreach ($distributions as $metricName => $buckets) {
            if (! is_string($metricName) || ! is_array($buckets) || ! array_is_list($buckets)) {
                throw new UnprocessableEntityHttpException('Report artifact payload has invalid histogram data.');
            }

            $histograms[$metricName] = $buckets;
        }

        return new JsonResponse([
            'schema_version' => ReportApiSchema::VERSION,
            'data' => [
                'artifact' => (new ReportArtifactResource($artifact))->toArray($request),
                'histograms' => $histograms,
            ],
        ]);
    }

    public function rowsCsv(Request $request, ReportArtifactRepository $reports, string $id): Response
    {
        try {
            $artifact = $reports->find($id);
        } catch (ReportArtifactUnavailableException $e) {
            throw new ServiceUnavailableHttpException(null, 'Report artifact metadata could not be read.', $e);
        } catch (EvalRunException $e) {
            throw new NotFoundHttpException($e->getMessage(), $e);
        }

        if ($artifact->format !== 'json') {
            throw new UnprocessableEntityHttpException('Artifact row export requires a JSON report.');
        }

        $jsonReport = $this->decodeJsonArtifactReport($reports, $artifact);

        $samples = $jsonReport['samples'] ?? null;
        $metricAggregates = $jsonReport['metrics'] ?? null;
        if (! is_array($samples) || ! is_array($metricAggregates) || ! array_is_list($samples)) {
            throw new UnprocessableEntityHttpException('Report artifact payload is missing expected report rows.');
        }

        $payloadMetricNames = array_values(array_filter(
            array_map('strval', array_keys($metricAggregates)),
            static fn (string $name): bool => $name !== '',
        ));
        $csv = $this->buildRowsCsv($payloadMetricNames, $samples, $jsonReport['failures'] ?? []);

        $downloadName = $artifact->path !== ''
            ? basename($artifact->path).'.csv'
            : 'report-rows.csv';

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$downloadName.'"',
            'Cache-Control' => 'no-store, max-age=0',
        ]);
    }

    public function download(Request $request, ReportArtifactRepository $reports, string $id): Response
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
        }

        $contentType = $artifact->format === 'json'
            ? 'application/json; charset=utf-8'
            : 'text/markdown; charset=utf-8';

        $filename = basename($artifact->path);

        return new Response($contents, 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store, max-age=0',
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

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonArtifactReport(ReportArtifactRepository $reports, ReportArtifact $artifact): array
    {
        try {
            return $this->decodeJsonReport($reports->contents($artifact));
        } catch (ReportArtifactUnavailableException $e) {
            throw new ServiceUnavailableHttpException(null, 'Report artifact contents could not be read.', $e);
        }
    }

    /**
     * @param  list<string>  $metricNames
     * @param  list<mixed>  $samples
     * @param  list<mixed>  $failures
     */
    private function buildRowsCsv(array $metricNames, array $samples, array $failures): string
    {
        $metricFailuresBySample = [];
        foreach ($failures as $failure) {
            if (! is_array($failure)) {
                continue;
            }

            $sampleId = $failure['sample_id'] ?? null;
            $metric = $failure['metric'] ?? null;
            $error = $failure['error'] ?? null;

            if (! is_string($sampleId) || ! is_string($metric) || $metric === '' || ! is_string($error)) {
                continue;
            }

            $metricFailuresBySample[$sampleId][$metric] = $error;
        }

        $resource = fopen('php://temp', 'w+');
        if (! is_resource($resource)) {
            throw new UnprocessableEntityHttpException('Artifact CSV export could not initialize an output stream.');
        }

        try {
            $header = ['sample_id', 'tags', 'metric', 'score', 'error', 'details'];
            fputcsv($resource, $header);

            foreach ($samples as $sample) {
                if (! is_array($sample)) {
                    continue;
                }

                $sampleId = is_string($sample['id'] ?? null) ? $sample['id'] : '';
                $tags = is_array($sample['tags'] ?? null) ? $sample['tags'] : [];
                $rawScores = is_array($sample['scores'] ?? null) ? $sample['scores'] : [];

                $safeTags = '';
                if ($tags !== [] && array_is_list($tags)) {
                    $safeTags = json_encode($tags, JSON_THROW_ON_ERROR);
                }

                if ($metricNames === []) {
                    continue;
                }

                foreach ($metricNames as $metricName) {
                    $score = null;
                    $details = null;
                    $error = null;
                    $metricData = $rawScores[$metricName] ?? null;

                    if (is_array($metricData)) {
                        $score = $metricData['score'] ?? null;
                        $details = json_encode($metricData['details'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    } else {
                        $error = $metricFailuresBySample[$sampleId][$metricName] ?? null;
                    }

                    fputcsv($resource, [
                        $sampleId,
                        $safeTags,
                        $metricName,
                        $score === null ? '' : (string) $score,
                        $error ?? '',
                        $details ?? '',
                    ]);
                }
            }

            rewind($resource);
            $csv = stream_get_contents($resource);
            if ($csv === false) {
                throw new UnprocessableEntityHttpException('Artifact CSV export could not be read.');
            }

            return $csv;
        } finally {
            fclose($resource);
        }
    }
}
