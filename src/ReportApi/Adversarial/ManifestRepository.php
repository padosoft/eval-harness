<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\ReportApi\Adversarial;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use JsonException;
use Padosoft\EvalHarness\Adversarial\AdversarialRunManifest;
use Padosoft\EvalHarness\Exceptions\EvalRunException;
use Padosoft\EvalHarness\ReportApi\ReportArtifactUnavailableException;
use Throwable;

/**
 * Reads adversarial run manifests from a configured disk + path
 * prefix. The endpoints stay opt-in: when the host app has not set
 * `eval-harness.adversarial.manifests.disk`, `discoveryEnabled()`
 * returns false and the controller surfaces a 404.
 */
final class ManifestRepository
{
    private const NAME_PATTERN = '/^[A-Za-z0-9._-]+$/';

    public function __construct(
        private readonly FilesystemFactory $filesystems,
        private readonly ConfigRepository $config,
    ) {}

    public function discoveryEnabled(): bool
    {
        $disk = $this->config->get('eval-harness.adversarial.manifests.disk');

        return is_string($disk) && trim($disk) !== '';
    }

    /**
     * @return list<array{name: string, runs_count: int, latest_macro_f1: float|null, updated_at: float|null}>
     */
    public function summaries(): array
    {
        if (! $this->discoveryEnabled()) {
            return [];
        }

        $disk = $this->disk();
        $prefix = $this->prefix();

        try {
            $paths = $disk->allFiles($prefix === '' ? null : $prefix);
        } catch (Throwable $e) {
            throw new ReportArtifactUnavailableException('Adversarial manifest listing could not be read.', previous: $e);
        }

        $summaries = [];
        foreach ($paths as $path) {
            if (! is_string($path) || ! str_ends_with($path, '.json')) {
                continue;
            }

            $relative = $this->relativePath($path, $prefix);
            if ($relative === null) {
                continue;
            }

            $name = $this->nameFromRelativePath($relative);
            if ($name === null) {
                continue;
            }

            try {
                $manifest = $this->loadManifestFromPath($disk, $this->storagePath($name));
            } catch (InvalidManifestPayloadException) {
                continue;
            } catch (EvalRunException) {
                continue;
            } catch (ReportArtifactUnavailableException) {
                continue;
            }

            $latest = $manifest->latest();
            $summaries[] = [
                'name' => $name,
                'runs_count' => count($manifest->runs),
                'latest_macro_f1' => $latest?->macroF1,
                'updated_at' => $manifest->updatedAt,
            ];
        }

        usort($summaries, static fn (array $left, array $right): int => strcmp($left['name'], $right['name']));

        return $summaries;
    }

    public function find(string $name): AdversarialRunManifest
    {
        if (! $this->discoveryEnabled()) {
            throw new ReportArtifactUnavailableException('Adversarial manifest discovery is not configured.');
        }

        $this->assertValidName($name);

        $disk = $this->disk();
        $path = $this->storagePath($name);

        return $this->loadManifestFromPath($disk, $path);
    }

    private function disk(): Filesystem
    {
        $name = $this->config->get('eval-harness.adversarial.manifests.disk');
        if (! is_string($name) || trim($name) === '') {
            throw new ReportArtifactUnavailableException('Adversarial manifest disk is not configured.');
        }

        try {
            return $this->filesystems->disk(trim($name));
        } catch (Throwable $e) {
            throw new ReportArtifactUnavailableException('Adversarial manifest disk could not be resolved.', previous: $e);
        }
    }

    private function prefix(): string
    {
        $prefix = $this->config->get(
            'eval-harness.adversarial.manifests.path_prefix',
            'eval-harness/adversarial/manifests',
        );

        if (! is_string($prefix)) {
            return 'eval-harness/adversarial/manifests';
        }

        return trim(trim(str_replace('\\', '/', $prefix)), '/');
    }

    private function storagePath(string $name): string
    {
        $this->assertValidName($name);

        $prefix = $this->prefix();
        $filename = $name.'.json';

        return $prefix === '' ? $filename : $prefix.'/'.$filename;
    }

    private function loadManifestFromPath(Filesystem $disk, string $path): AdversarialRunManifest
    {
        try {
            // Mirror ReportArtifactRepository::metadataFor(): branch on
            // FilesystemAdapter so we use the Flysystem v3 fileExists()
            // path when available and fall back to the contract's
            // generic exists() for non-adapter filesystems / fakes.
            if ($disk instanceof FilesystemAdapter) {
                if (! $disk->fileExists($path)) {
                    throw new EvalRunException('Adversarial manifest not found.');
                }
            } elseif (! $disk->exists($path)) {
                throw new EvalRunException('Adversarial manifest not found.');
            }

            $contents = $disk->get($path);
        } catch (EvalRunException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ReportArtifactUnavailableException('Adversarial manifest contents could not be read.', previous: $e);
        }

        if (! is_string($contents)) {
            throw new ReportArtifactUnavailableException('Adversarial manifest contents could not be read.');
        }

        try {
            $payload = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidManifestPayloadException('Adversarial manifest JSON is malformed.', previous: $e);
        }

        if (! is_array($payload)) {
            throw new InvalidManifestPayloadException('Adversarial manifest must decode to an object.');
        }

        /** @var array<string, mixed> $payload */
        try {
            return AdversarialRunManifest::fromJson($payload);
        } catch (EvalRunException $e) {
            throw new InvalidManifestPayloadException($e->getMessage(), previous: $e);
        }
    }

    private function relativePath(string $path, string $prefix): ?string
    {
        $normalized = trim(str_replace('\\', '/', $path), '/');
        if ($prefix === '') {
            return $normalized;
        }

        if ($normalized === $prefix || ! str_starts_with($normalized, $prefix.'/')) {
            return null;
        }

        return substr($normalized, strlen($prefix) + 1);
    }

    private function nameFromRelativePath(string $relative): ?string
    {
        if (! str_ends_with($relative, '.json')) {
            return null;
        }

        $name = substr($relative, 0, -strlen('.json'));
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            return null;
        }

        return $name;
    }

    private function assertValidName(string $name): void
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new EvalRunException('Adversarial manifest name must match [A-Za-z0-9._-]+.');
        }
    }
}
