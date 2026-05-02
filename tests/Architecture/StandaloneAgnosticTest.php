<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Architecture;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Per the Padosoft "standalone agnostic" rule (memory:
 * feedback_packages_standalone_agnostic): the package source MUST NOT
 * reference any AskMyDocs-specific symbol, table name, or sibling
 * Padosoft package not declared in composer.json. The package is
 * consumed by AskMyDocs (and others) but never depends on it;
 * community adoption depends on this invariant.
 *
 * Note: this is a plain PHPUnit\Framework\TestCase (no Testbench
 * bootstrap) because the assertion is purely textual / file-system-based
 * and does not need the Laravel container.
 */
final class StandaloneAgnosticTest extends TestCase
{
    /**
     * Forbidden substrings that, if present in any PHP file under src/,
     * indicate a leak from AskMyDocs (or a sibling Padosoft package not
     * declared in composer.json) into this standalone package.
     *
     * @return list<string>
     */
    private static function forbiddenNeedles(): array
    {
        return [
            // AskMyDocs internal symbols
            'KnowledgeDocument',
            'KbSearchService',
            'knowledge_documents',
            'knowledge_chunks',
            'kb_nodes',
            'kb_edges',
            'kb_canonical_audit',
            'lopadova/askmydocs',
            'App\\Models\\KnowledgeDocument',
            // Padosoft sibling packages — eval-harness must not import
            // them at the source level. Suggest entries in composer.json
            // are fine; that's the consumer's call.
            'padosoft/askmydocs-pro',
            'padosoft/laravel-patent-box-tracker',
            'PatentBoxTracker',
            'padosoft/laravel-flow',
            'Padosoft\\LaravelFlow',
            'Padosoft\\Flow',
        ];
    }

    public function test_src_directory_does_not_reference_other_padosoft_or_askmydocs_symbols(): void
    {
        $srcPath = realpath(__DIR__.'/../../src');
        $this->assertNotFalse($srcPath, 'src/ directory must exist.');

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcPath, FilesystemIterator::SKIP_DOTS),
        );

        $offenders = [];
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $contents = (string) file_get_contents($file->getPathname());
            foreach (self::forbiddenNeedles() as $needle) {
                if (str_contains($contents, $needle)) {
                    $offenders[] = sprintf('%s contains "%s"', $file->getPathname(), $needle);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Standalone-agnostic violation. The package must not reference AskMyDocs or sibling Padosoft package symbols.\n"
            .implode("\n", $offenders),
        );
    }

    public function test_milestone_directories_are_walked_and_clean(): void
    {
        // Self-documenting assertion: the recursive walk above already
        // covers everything under src/. Listing the W6.A directories
        // explicitly here means a future contributor cannot accidentally
        // exclude one and hide a leak.
        $directories = [
            'src/Datasets' => 'W6.A',
            'src/Metrics' => 'W6.A',
            'src/Reports' => 'W6.A',
            'src/Console' => 'W6.A',
            'src/Exceptions' => 'W6.A',
            'src/Facades' => 'W6.A',
        ];

        foreach ($directories as $relative => $milestone) {
            $dir = realpath(__DIR__.'/../../'.$relative);
            $this->assertNotFalse(
                $dir,
                sprintf('%s/ must exist after %s.', $relative, $milestone),
            );

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }
                $contents = (string) file_get_contents($file->getPathname());
                foreach (self::forbiddenNeedles() as $needle) {
                    $this->assertStringNotContainsString(
                        $needle,
                        $contents,
                        sprintf('%s leaks symbol "%s".', $file->getPathname(), $needle),
                    );
                }
            }
        }
    }

    public function test_composer_json_does_not_require_askmydocs_or_siblings(): void
    {
        $composerPath = realpath(__DIR__.'/../../composer.json');
        $this->assertNotFalse($composerPath, 'composer.json must exist.');

        $raw = (string) file_get_contents($composerPath);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $require */
        $require = (array) ($decoded['require'] ?? []);
        /** @var array<string, mixed> $requireDev */
        $requireDev = (array) ($decoded['require-dev'] ?? []);

        $forbidden = [
            'lopadova/askmydocs',
            'padosoft/askmydocs-pro',
            'padosoft/laravel-patent-box-tracker',
            'padosoft/laravel-flow',
        ];

        foreach ($forbidden as $package) {
            $this->assertArrayNotHasKey(
                $package,
                $require,
                sprintf('composer.json must not require %s.', $package),
            );
            $this->assertArrayNotHasKey(
                $package,
                $requireDev,
                sprintf('composer.json must not require %s in require-dev.', $package),
            );
        }
    }
}
