<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\ReportApi;

use BadMethodCallException;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Padosoft\EvalHarness\ReportApi\ReportArtifactController;
use Padosoft\EvalHarness\ReportApi\ReportArtifactId;
use Padosoft\EvalHarness\ReportApi\ReportArtifactRepository;
use Padosoft\EvalHarness\Tests\TestCase;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class ReportArtifactFailureTest extends TestCase
{
    public function test_lists_skip_invalid_report_artifacts_in_storage_listing(): void
    {
        $disk = new FakeReportFilesystem(
            files: [
                'eval-harness/reports/valid/report.json' => '{"schema_version":"eval-harness.report.v1"}',
            ],
            allFiles: [
                'eval-harness/reports/../broken/report.json',
                'eval-harness/reports/valid/report.json',
            ],
        );

        $this->app->instance(FilesystemFactory::class, new FakeFilesystemFactory($disk));
        $this->app->forgetInstance(ReportArtifactRepository::class);

        $artifacts = $this->app->make(ReportArtifactRepository::class)->all();

        $this->assertCount(1, $artifacts);
        $this->assertSame('valid/report.json', $artifacts[0]->path);
    }

    public function test_index_returns_service_unavailable_when_listing_cannot_be_read(): void
    {
        $disk = new FakeReportFilesystem(
            failListing: true,
        );

        $repository = new ReportArtifactRepository(
            new FakeFilesystemFactory($disk),
            $this->app['config'],
        );
        $controller = new ReportArtifactController;

        try {
            $controller->index($this->app->make(Request::class), $repository);
            $this->fail('Expected a ServiceUnavailableHttpException.');
        } catch (ServiceUnavailableHttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
    }

    public function test_show_returns_service_unavailable_when_report_contents_cannot_be_read(): void
    {
        $disk = new FakeReportFilesystem(
            files: [
                'eval-harness/reports/rag/report.json' => '{"schema_version":"eval-harness.report.v1"}',
            ],
            failGet: true,
        );

        $repository = new ReportArtifactRepository(
            new FakeFilesystemFactory($disk),
            $this->app['config'],
        );
        $controller = new ReportArtifactController;
        $id = ReportArtifactId::encode('rag/report.json');

        try {
            $controller->show($this->app->make(Request::class), $repository, $id);
            $this->fail('Expected a ServiceUnavailableHttpException.');
        } catch (ServiceUnavailableHttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
    }

    public function test_show_returns_service_unavailable_when_report_metadata_cannot_be_read(): void
    {
        $disk = new FakeReportFilesystem(
            files: [
                'eval-harness/reports/rag/report.json' => '{"schema_version":"eval-harness.report.v1"}',
            ],
            failMetadata: true,
        );

        $repository = new ReportArtifactRepository(
            new FakeFilesystemFactory($disk),
            $this->app['config'],
        );
        $controller = new ReportArtifactController;
        $id = ReportArtifactId::encode('rag/report.json');

        try {
            $controller->show($this->app->make(Request::class), $repository, $id);
            $this->fail('Expected a ServiceUnavailableHttpException.');
        } catch (ServiceUnavailableHttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
    }
}

/**
 * @internal
 */
final class FakeFilesystemFactory implements FilesystemFactory
{
    public function __construct(private readonly Filesystem $disk) {}

    public function disk($name = null): Filesystem
    {
        return $this->disk;
    }
}

/**
 * @internal
 */
final class FakeReportFilesystem implements Filesystem
{
    /**
     * @param  array<string, string>  $files
     * @param  list<string>  $allFiles
     */
    public function __construct(
        private readonly array $files = [],
        private readonly array $allFiles = [],
        private readonly bool $failGet = false,
        private readonly bool $failListing = false,
        private readonly bool $failMetadata = false,
    ) {}

    public function path($path)
    {
        return $path;
    }

    public function exists($path)
    {
        return array_key_exists($path, $this->files);
    }

    public function get($path)
    {
        if ($this->failGet) {
            throw new RuntimeException('Read failure');
        }

        return $this->files[$path] ?? null;
    }

    public function readStream($path)
    {
        throw new BadMethodCallException('Not implemented.');
    }

    public function put($path, $contents, $options = [])
    {
        throw new BadMethodCallException('Not implemented.');
    }

    public function putFile($path, $file = null, $options = [])
    {
        throw new BadMethodCallException('Not implemented.');
    }

    public function putFileAs($path, $file, $name = null, $options = [])
    {
        throw new BadMethodCallException('Not implemented.');
    }

    public function writeStream($path, $resource, array $options = [])
    {
        throw new BadMethodCallException('Not implemented.');
    }

    public function getVisibility($path)
    {
        throw new BadMethodCallException('Not implemented.');
    }

    public function setVisibility($path, $visibility)
    {
        throw new BadMethodCallException('Not implemented.');
    }

    public function prepend($path, $data)
    {
        throw new BadMethodCallException('Not implemented.');
    }

    public function append($path, $data)
    {
        throw new BadMethodCallException('Not implemented.');
    }

    public function delete($paths)
    {
        throw new BadMethodCallException('Not implemented.');
    }

    public function copy($from, $to)
    {
        throw new BadMethodCallException('Not implemented.');
    }

    public function move($from, $to)
    {
        throw new BadMethodCallException('Not implemented.');
    }

    public function size($path)
    {
        if ($this->failMetadata) {
            throw new RuntimeException('Metadata read failure');
        }

        return strlen($this->files[$path] ?? '');
    }

    public function lastModified($path)
    {
        if ($this->failMetadata) {
            throw new RuntimeException('Metadata read failure');
        }

        return 1_717_000_000;
    }

    public function files($directory = null, $recursive = false)
    {
        return $this->allFiles($directory);
    }

    public function allFiles($directory = null)
    {
        if ($this->failListing) {
            throw new RuntimeException('Listing failure');
        }

        return $this->allFiles;
    }

    public function directories($directory = null, $recursive = false)
    {
        return [];
    }

    public function allDirectories($directory = null)
    {
        return [];
    }

    public function makeDirectory($path)
    {
        throw new BadMethodCallException('Not implemented.');
    }

    public function deleteDirectory($directory)
    {
        throw new BadMethodCallException('Not implemented.');
    }
}
