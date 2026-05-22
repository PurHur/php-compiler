<?php

declare(strict_types=1);

namespace PHPCompiler\AOT;

use PHPCompiler\Runtime;
use PHPCompiler\Web\LiteralIncludeDiscovery;
use PHPCompiler\Web\ManifestValidator;
use PHPCompiler\Web\ProjectManifest;

/**
 * Resolve phpc build --project include graph before LLVM compile (issue #504).
 */
final class ProjectGraph
{
    /**
     * @return array{files: list<string>, errors: list<string>}
     */
    public static function resolve(string $projectDir): array
    {
        $errors = ManifestValidator::validateForBuild($projectDir);
        if ([] !== $errors) {
            return ['files' => [], 'errors' => $errors];
        }

        $root = ProjectManifest::resolveProjectDir($projectDir);
        if (null === $root) {
            return ['files' => [], 'errors' => ['phpc.json not found in '.$projectDir]];
        }

        $entry = ProjectManifest::resolveEntryPath($root);
        if (null === $entry) {
            return ['files' => [], 'errors' => ['could not resolve entry from phpc.json']];
        }

        $manifestIncludes = ProjectManifest::resolveIncludePaths($root);
        $errors = array_merge($errors, self::duplicateIncludeErrors($manifestIncludes));
        $errors = array_merge($errors, self::entryLiteralIncludeCoverageErrors($entry, $manifestIncludes));

        $runtime = new Runtime(Runtime::MODE_AOT);
        $discovered = LiteralIncludeDiscovery::discoverAbsolutePaths($runtime, $entry);

        $files = [];
        $seen = [];
        foreach ($discovered as $path) {
            $key = realpath($path) ?: $path;
            if (isset($seen[$key])) {
                continue;
            }
            if (!is_file($path)) {
                $errors[] = 'discovered include not found: '.self::displayPath($root, $path);

                continue;
            }
            $seen[$key] = true;
            $files[] = $path;
        }

        foreach ($manifestIncludes as $path) {
            $key = realpath($path) ?: $path;
            if (isset($seen[$key])) {
                continue;
            }
            if (!is_file($path)) {
                $errors[] = 'includes[] path not found: '.self::displayPath($root, $path);

                continue;
            }
            $seen[$key] = true;
            $files[] = $path;
        }

        $entryKey = realpath($entry) ?: $entry;
        if (!isset($seen[$entryKey])) {
            $files[] = $entry;
        }

        return ['files' => $files, 'errors' => $errors];
    }

    /**
     * Print resolved graph paths to stdout; return exit code (issue #504).
     */
    public static function preflight(string $projectDir, bool $json = false): int
    {
        $result = self::resolve($projectDir);
        if ([] !== $result['errors']) {
            foreach ($result['errors'] as $message) {
                fwrite(STDERR, $message."\n");
            }

            return 1;
        }

        $display = self::formatFileList($projectDir, $result['files']);
        if ($json) {
            fwrite(STDOUT, json_encode(['files' => $display], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        } else {
            foreach ($display as $path) {
                fwrite(STDOUT, $path."\n");
            }
        }

        return 0;
    }

    /**
     * @param list<string> $files absolute paths
     *
     * @return list<string> paths relative to project root when possible
     */
    public static function formatFileList(string $projectDir, array $files): array
    {
        $root = ProjectManifest::resolveProjectDir($projectDir);
        if (null === $root) {
            return $files;
        }

        return array_map(
            static fn (string $path): string => self::displayPath($root, $path),
            $files
        );
    }

    /**
     * @param list<string> $manifestIncludes
     *
     * @return list<string>
     */
    /**
     * Entry-level literal includes must be covered by phpc.json includes[] basenames (issue #452 bundle).
     *
     * @param list<string> $manifestIncludes
     *
     * @return list<string>
     */
    private static function entryLiteralIncludeCoverageErrors(string $entry, array $manifestIncludes): array
    {
        $manifestBasenames = [];
        foreach ($manifestIncludes as $path) {
            $manifestBasenames[basename($path)] = $path;
        }

        $runtime = new Runtime(Runtime::MODE_AOT);
        $entryOnly = LiteralIncludeDiscovery::discoverDirectAbsolutePaths($runtime, $entry);
        $errors = [];
        foreach ($entryOnly as $resolved) {
            $base = basename($resolved);
            if (!isset($manifestBasenames[$base])) {
                $errors[] = 'includes[] must list '.$resolved.' (literal include from entry requires this file in the manifest bundle)';
            }
        }

        return $errors;
    }

    private static function duplicateIncludeErrors(array $manifestIncludes): array
    {
        $seen = [];
        $errors = [];
        foreach ($manifestIncludes as $path) {
            $key = realpath($path) ?: $path;
            if (isset($seen[$key])) {
                $errors[] = 'duplicate path in includes[]: '.$path;
                continue;
            }
            $seen[$key] = true;
        }

        return $errors;
    }

    private static function displayPath(string $projectRoot, string $absolutePath): string
    {
        $root = realpath($projectRoot) ?: $projectRoot;
        $resolved = realpath($absolutePath) ?: $absolutePath;
        $prefix = $root.'/';
        if (str_starts_with($resolved, $prefix)) {
            return substr($resolved, strlen($prefix));
        }

        return $resolved;
    }
}
