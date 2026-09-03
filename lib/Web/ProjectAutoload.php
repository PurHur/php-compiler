<?php

declare(strict_types=1);

namespace PHPCompiler\Web;

use PHPCompiler\Runtime;

/**
 * PSR-4 autoload for phpc.json projects (issue #155, VM serve / lint phase A).
 *
 * Values may be one directory or a list — Composer’s autoload_psr4.php often lists
 * several bases per prefix (e.g. Psr\Http\Message\ → http-factory + http-message) (#36382).
 */
final class ProjectAutoload
{
    /**
     * Namespace prefix => absolute base directory (or list of directories).
     *
     * @return array<string, string|list<string>>
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
            if (!is_string($prefix) || '' === $prefix) {
                continue;
            }
            if (!str_ends_with($prefix, '\\')) {
                $prefix .= '\\';
            }
            $rels = is_array($relativeBase) ? $relativeBase : [$relativeBase];
            $dirs = [];
            foreach ($rels as $rel) {
                if (!is_string($rel) || '' === $rel) {
                    continue;
                }
                $dirs[] = ProjectManifest::resolveRelativePath($projectDir, $rel);
            }
            if ([] === $dirs) {
                continue;
            }
            $map[$prefix] = 1 === count($dirs) ? $dirs[0] : $dirs;
        }

        return $map;
    }

    /**
     * Normalize a PSR-4 map value to a list of absolute base directories.
     *
     * @param string|list<string>|mixed $baseDirs
     *
     * @return list<string>
     */
    public static function normalizeBaseDirs(mixed $baseDirs): array
    {
        if (is_string($baseDirs) && '' !== $baseDirs) {
            return [$baseDirs];
        }
        if (!is_array($baseDirs)) {
            return [];
        }
        $out = [];
        foreach ($baseDirs as $dir) {
            if (is_string($dir) && '' !== $dir) {
                $out[] = $dir;
            }
        }

        return $out;
    }

    /**
     * Resolve a fully-qualified class name to an absolute PHP file path.
     *
     * Tries every base directory for matching prefixes, longest prefix first
     * (Composer\Autoload\ClassLoader::findFileWithExtension).
     *
     * @param array<string, string|list<string>> $psr4Map prefix => base dir(s)
     */
    public static function resolveClassPath(string $className, array $psr4Map): ?string
    {
        $className = ltrim($className, '\\');
        $candidates = [];
        foreach ($psr4Map as $prefix => $baseDirs) {
            if (!is_string($prefix) || !str_starts_with($className, $prefix)) {
                continue;
            }
            $candidates[] = [$prefix, self::normalizeBaseDirs($baseDirs)];
        }
        usort(
            $candidates,
            static fn (array $a, array $b): int => strlen($b[0]) <=> strlen($a[0])
        );

        foreach ($candidates as [$prefix, $dirs]) {
            $relative = substr($className, strlen($prefix));
            if ('' === $relative) {
                continue;
            }
            $relPath = str_replace('\\', '/', $relative).'.php';
            foreach ($dirs as $baseDir) {
                $path = rtrim($baseDir, '/\\').'/'.$relPath;
                $resolved = realpath($path);
                if (false !== $resolved && is_file($resolved)) {
                    return $resolved;
                }
            }
        }

        return null;
    }

    /**
     * Absolute paths to PHP files under mapped directories.
     *
     * @param array<string, string|list<string>> $psr4Map
     *
     * @return list<string>
     */
    public static function collectPhpFiles(string $projectDir, array $psr4Map): array
    {
        $files = [];
        $seen = [];
        foreach ($psr4Map as $baseDirs) {
            foreach (self::normalizeBaseDirs($baseDirs) as $baseDir) {
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
        }
        sort($files);

        return $files;
    }

    /**
     * Register a VM class autoloader that compiles PSR-4 mapped files on demand.
     *
     * @param array<string, string|list<string>> $psr4Map
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
        foreach ($map as $prefix => $baseDirs) {
            foreach (self::normalizeBaseDirs($baseDirs) as $baseDir) {
                $resolved = realpath($baseDir);
                if (false === $resolved || !is_dir($resolved)) {
                    $errors[] = 'autoload.psr-4 base directory not found for prefix '.$prefix.': '.$baseDir;
                }
            }
        }

        return $errors;
    }
}

/** VM class autoload callback without Expr_Closure (self-host AOT spine #1056). */
final class ProjectVmAutoloadHandler
{
    /**
     * @param array<string, string|list<string>> $psr4Map
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
        $this->runtime->vm()->executeCompileUnit($path);

        return isset($this->runtime->vmContext->classes[strtolower($className)]);
    }
}
