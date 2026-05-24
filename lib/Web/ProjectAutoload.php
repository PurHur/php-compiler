<?php

declare(strict_types=1);

namespace PHPCompiler\Web;

use PHPCompiler\Runtime;

/**
 * PSR-4 autoload for phpc.json projects (issue #155, VM serve / lint phase A).
 */
final class ProjectAutoload
{
    /**
     * Namespace prefix => absolute base directory.
     *
     * @return array<string, string>
     */
    public static function parsePsr4Map(string $projectDir, ?array $manifest): array
    {
        if (null === $manifest || !isset($manifest['autoload']) || !is_array($manifest['autoload'])) {
            return [];
        }
        $psr4 = $manifest['autoload']['psr-4'] ?? null;
        if (!is_array($psr4)) {
            return [];
        }

        $map = [];
        foreach ($psr4 as $prefix => $relativeBase) {
            if (!is_string($prefix) || '' === $prefix || !is_string($relativeBase) || '' === $relativeBase) {
                continue;
            }
            if (!str_ends_with($prefix, '\\')) {
                $prefix .= '\\';
            }
            $map[$prefix] = ProjectManifest::resolveRelativePath($projectDir, $relativeBase);
        }

        return $map;
    }

    /**
     * Resolve a fully-qualified class name to an absolute PHP file path.
     *
     * @param array<string, string> $psr4Map prefix => absolute base directory
     */
    public static function resolveClassPath(string $className, array $psr4Map): ?string
    {
        foreach ($psr4Map as $prefix => $baseDir) {
            if (!str_starts_with($className, $prefix)) {
                continue;
            }
            $relative = substr($className, strlen($prefix));
            if ('' === $relative) {
                continue;
            }
            $path = $baseDir.'/'.str_replace('\\', '/', $relative).'.php';
            $resolved = realpath($path);

            return false !== $resolved && is_file($resolved) ? $resolved : null;
        }

        return null;
    }

    /**
     * @param array<string, string> $psr4Map
     *
     * Absolute paths to PHP files under mapped directories.
     *
     * @return list<string>
     */
    public static function collectPhpFiles(string $projectDir, array $psr4Map): array
    {
        $files = [];
        $seen = [];
        foreach ($psr4Map as $baseDir) {
            $root = realpath($baseDir);
            if (false === $root || !is_dir($root)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $fileInfo) {
                if (!$fileInfo->isFile() || 'php' !== strtolower($fileInfo->getExtension())) {
                    continue;
                }
                $path = $fileInfo->getPathname();
                $resolved = realpath($path);
                $key = false !== $resolved ? $resolved : $path;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $files[] = $key;
            }
        }
        sort($files);

        return $files;
    }

    /**
     * Register a VM class autoloader that compiles PSR-4 mapped files on demand.
     *
     * @param array<string, string> $psr4Map
     */
    public static function registerVmAutoload(Runtime $runtime, array $psr4Map): void
    {
        if ([] === $psr4Map) {
            return;
        }

        $runtime->vmContext->classAutoloaders[] = new ProjectVmAutoloadHandler($runtime, $psr4Map);
    }

    /**
     * @return list<string>
     */
    public static function validatePsr4PathsOnDisk(string $projectDir, mixed $autoload): array
    {
        if (!is_array($autoload)) {
            return [];
        }

        $errors = [];
        $map = self::parsePsr4Map($projectDir, ['autoload' => $autoload]);
        foreach ($map as $prefix => $baseDir) {
            $resolved = realpath($baseDir);
            if (false === $resolved || !is_dir($resolved)) {
                $errors[] = 'autoload.psr-4 base directory not found for prefix '.$prefix.': '.$baseDir;
            }
        }

        return $errors;
    }
}

/** VM class autoload callback without Expr_Closure (self-host AOT spine #1056). */
final class ProjectVmAutoloadHandler
{
    /**
     * @param array<string, string> $psr4Map
     */
    public function __construct(
        private Runtime $runtime,
        private array $psr4Map
    ) {
    }

    public function __invoke(string $className): bool
    {
        $path = ProjectAutoload::resolveClassPath($className, $this->psr4Map);
        if (null === $path) {
            return false;
        }
        $this->runtime->vm->executeCompileUnit($path);

        return isset($this->runtime->vmContext->classes[strtolower($className)]);
    }
}
