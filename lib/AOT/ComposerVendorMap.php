<?php

declare(strict_types=1);

namespace PHPCompiler\AOT;

use PHPCompiler\Web\ProjectAutoload;
use PHPCompiler\Web\ProjectManifest;

/**
 * Read Composer-generated vendor/composer/autoload_*.php maps for AOT project graphs (#36382).
 *
 * Prefer including the generated files (same contract Composer uses) when present; fall back to
 * an empty map when vendor/composer is missing. Does not execute vendor/autoload.php itself.
 */
final class ComposerVendorMap
{
    /**
     * @return array{
     *   enabled: bool,
     *   classmap: array<string, string>,
     *   psr4: array<string, string>,
     *   files: list<string>,
     *   all_files: list<string>,
     *   errors: list<string>
     * }
     */
    public static function load(string $projectDir, ?array $manifest = null): array
    {
        $empty = [
            'enabled' => false,
            'classmap' => [],
            'psr4' => [],
            'files' => [],
            'all_files' => [],
            'errors' => [],
        ];

        $root = ProjectManifest::resolveProjectDir($projectDir) ?? (realpath($projectDir) ?: $projectDir);
        $manifest ??= ProjectManifest::loadManifest($root);
        if (!self::shouldUseComposer($root, $manifest)) {
            return $empty;
        }

        $composerDir = $root.'/vendor/composer';
        if (!is_dir($composerDir)) {
            return $empty;
        }

        $errors = [];
        $classmap = self::loadClassmap($composerDir, $errors);
        $psr4 = self::loadPsr4($composerDir, $errors);
        $files = self::loadFiles($composerDir, $errors);
        $includeRoots = self::resolveIncludeRoots($root, $manifest);

        $all = [];
        $seen = [];
        foreach ($classmap as $path) {
            self::pushFile($path, $all, $seen);
        }
        foreach ($files as $path) {
            self::pushFile($path, $all, $seen);
        }
        // Whole PSR-4 trees (issue #36382 deliverable: every mapped file).
        if ([] !== $psr4) {
            foreach (ProjectAutoload::collectPhpFiles($root, $psr4) as $path) {
                self::pushFile($path, $all, $seen);
            }
        }
        foreach ($includeRoots as $baseDir) {
            foreach (ProjectAutoload::collectPhpFiles($root, ['App\\' => $baseDir]) as $path) {
                self::pushFile($path, $all, $seen);
            }
        }

        // Always compile vendor/autoload.php so SourceBundler / IncludeHelper can stub it.
        $autoloadPhp = $root.'/vendor/autoload.php';
        if (is_file($autoloadPhp)) {
            self::pushFile(realpath($autoloadPhp) ?: $autoloadPhp, $all, $seen);
        }

        sort($all);

        return [
            'enabled' => true,
            'classmap' => $classmap,
            'psr4' => $psr4,
            'files' => $files,
            'all_files' => $all,
            'errors' => $errors,
        ];
    }

    /**
     * Whether ProjectGraph should ingest Composer maps (default on when vendor/composer exists).
     *
     * @param array<string, mixed>|null $manifest
     */
    public static function shouldUseComposer(string $projectDir, ?array $manifest): bool
    {
        $root = ProjectManifest::resolveProjectDir($projectDir) ?? (realpath($projectDir) ?: $projectDir);
        if (!is_dir($root.'/vendor/composer')) {
            return false;
        }

        if (null === $manifest) {
            return true;
        }

        if (!array_key_exists('autoload', $manifest)) {
            return true;
        }

        $autoload = $manifest['autoload'];
        if (is_string($autoload)) {
            $mode = strtolower(trim($autoload));
            if ('none' === $mode) {
                return false;
            }
            if ('composer' === $mode) {
                return true;
            }

            return false;
        }

        if (is_array($autoload) && isset($autoload['composer'])) {
            return (bool) $autoload['composer'];
        }

        // Existing phpc.json psr-4 maps remain valid; Composer vendor maps still apply.
        return true;
    }

    /**
     * Resolve class name via Composer classmap then PSR-4 prefixes.
     *
     * @param array<string, string> $classmap
     * @param array<string, string> $psr4
     */
    public static function resolveClassPath(string $className, array $classmap, array $psr4): ?string
    {
        $className = ltrim($className, '\\');
        if (isset($classmap[$className]) && is_file($classmap[$className])) {
            return realpath($classmap[$className]) ?: $classmap[$className];
        }

        return ProjectAutoload::resolveClassPath($className, $psr4);
    }

    /**
     * When a compile unit requires vendor/autoload.php, add Composer-mapped files to $includes
     * so AOT can stub the dynamic loader without dropping classes (#36382).
     *
     * @param list<string> $includes
     *
     * @return list<string>
     */
    public static function expandIncludesForAutoload(string $entryPath, array $includes): array
    {
        $autoloadPaths = [];
        foreach ($includes as $path) {
            if (self::isComposerAutoloadPhp($path)) {
                $autoloadPaths[] = $path;
            }
        }
        if (self::isComposerAutoloadPhp($entryPath)) {
            $autoloadPaths[] = $entryPath;
        }
        if ([] === $autoloadPaths) {
            // Entry may literal-require vendor/autoload.php without it appearing in $includes yet.
            $entryDir = dirname(realpath($entryPath) ?: $entryPath);
            $guess = $entryDir.'/vendor/autoload.php';
            if (is_file($guess)) {
                $autoloadPaths[] = $guess;
            }
            $guess2 = dirname($entryDir).'/vendor/autoload.php';
            if (is_file($guess2)) {
                $autoloadPaths[] = $guess2;
            }
        }
        if ([] === $autoloadPaths) {
            return $includes;
        }

        $seen = [];
        $out = [];
        foreach ($includes as $path) {
            $key = realpath($path) ?: $path;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $path;
        }

        foreach ($autoloadPaths as $autoloadPhp) {
            $autoloadReal = realpath($autoloadPhp) ?: $autoloadPhp;
            $vendorDir = dirname($autoloadReal);
            $projectRoot = dirname($vendorDir);
            if (is_dir($projectRoot.'/vendor/composer')) {
                $map = self::load($projectRoot, ['autoload' => 'composer']);
                foreach ($map['all_files'] as $path) {
                    $key = realpath($path) ?: $path;
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $out[] = $path;
                }
            }
            // Always keep literal require(_once) __DIR__.'…' targets from the autoload body
            // (mini fixtures and dual Zend/AOT loaders — #36382).
            foreach (self::literalRequiresFromAutoloadPhp($autoloadReal) as $path) {
                $key = realpath($path) ?: $path;
                if (isset($seen[$key]) || !is_file($key)) {
                    continue;
                }
                $seen[$key] = true;
                $out[] = $key;
            }
            if (!isset($seen[$autoloadReal])) {
                $seen[$autoloadReal] = true;
                $out[] = $autoloadReal;
            }
        }

        return $out;
    }

    /**
     * @return list<string> absolute paths from require(_once) __DIR__ . '/…' in autoload.php
     */
    private static function literalRequiresFromAutoloadPhp(string $autoloadPhp): array
    {
        $code = (string) file_get_contents($autoloadPhp);
        $base = dirname($autoloadPhp);
        $paths = [];
        if (preg_match_all(
            '/require(?:_once)?\s+__DIR__\s*\.\s*([\'"])([^\'"]+)\1\s*;/',
            $code,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $rel = $m[2];
                $candidate = $base.'/'.$rel;
                $resolved = realpath($candidate);
                if (false !== $resolved && is_file($resolved)) {
                    $paths[] = $resolved;
                }
            }
        }

        return $paths;
    }

    /**
     * True when $path is vendor/autoload.php (Composer entry).
     */
    public static function isComposerAutoloadPhp(string $path): bool
    {
        $norm = str_replace('\\', '/', $path);

        return str_ends_with($norm, '/vendor/autoload.php');
    }

    /**
     * @param array<string, mixed>|null $manifest
     *
     * @return list<string> absolute directories
     */
    private static function resolveIncludeRoots(string $projectRoot, ?array $manifest): array
    {
        if (null === $manifest || !isset($manifest['include_roots']) || !is_array($manifest['include_roots'])) {
            return [];
        }

        $dirs = [];
        foreach ($manifest['include_roots'] as $item) {
            if (!is_string($item) || '' === $item) {
                continue;
            }
            $path = ProjectManifest::resolveRelativePath($projectRoot, $item);
            $resolved = realpath($path);
            if (false !== $resolved && is_dir($resolved)) {
                $dirs[] = $resolved;
            }
        }

        return $dirs;
    }

    /**
     * @param list<string> $errors
     *
     * @return array<string, string> class => absolute path
     */
    private static function loadClassmap(string $composerDir, array &$errors): array
    {
        $raw = self::includeReturningArray($composerDir.'/autoload_classmap.php', $errors);
        $map = [];
        foreach ($raw as $class => $path) {
            if (!is_string($class) || '' === $class || !is_string($path) || '' === $path) {
                continue;
            }
            if (!is_file($path)) {
                continue;
            }
            $map[ltrim($class, '\\')] = realpath($path) ?: $path;
        }

        return $map;
    }

    /**
     * @param list<string> $errors
     *
     * @return array<string, string> prefix => absolute base dir (first path wins)
     */
    private static function loadPsr4(string $composerDir, array &$errors): array
    {
        $raw = self::includeReturningArray($composerDir.'/autoload_psr4.php', $errors);
        $map = [];
        foreach ($raw as $prefix => $dirs) {
            if (!is_string($prefix) || '' === $prefix) {
                continue;
            }
            if (!str_ends_with($prefix, '\\')) {
                $prefix .= '\\';
            }
            $list = is_array($dirs) ? $dirs : [$dirs];
            foreach ($list as $dir) {
                if (!is_string($dir) || '' === $dir) {
                    continue;
                }
                $resolved = realpath($dir);
                if (false === $resolved || !is_dir($resolved)) {
                    continue;
                }
                $map[$prefix] = $resolved;
                break;
            }
        }

        return $map;
    }

    /**
     * @param list<string> $errors
     *
     * @return list<string> absolute paths
     */
    private static function loadFiles(string $composerDir, array &$errors): array
    {
        $raw = self::includeReturningArray($composerDir.'/autoload_files.php', $errors);
        $files = [];
        $seen = [];
        foreach ($raw as $path) {
            if (!is_string($path) || '' === $path || !is_file($path)) {
                continue;
            }
            self::pushFile(realpath($path) ?: $path, $files, $seen);
        }

        return $files;
    }

    /**
     * @param list<string> $errors
     *
     * @return array<mixed, mixed>
     */
    private static function includeReturningArray(string $path, array &$errors): array
    {
        if (!is_file($path)) {
            return [];
        }

        try {
            /** @var mixed $result */
            $result = (static function (string $__phpcComposerMapPath) {
                return require $__phpcComposerMapPath;
            })($path);
        } catch (\Throwable $e) {
            $errors[] = 'composer map failed to load '.$path.': '.$e->getMessage();

            return [];
        }

        if (!is_array($result)) {
            $errors[] = 'composer map did not return an array: '.$path;

            return [];
        }

        return $result;
    }

    /**
     * @param list<string>         $files
     * @param array<string, true>  $seen
     */
    private static function pushFile(string $path, array &$files, array &$seen): void
    {
        $key = realpath($path) ?: $path;
        if (isset($seen[$key]) || !is_file($key)) {
            return;
        }
        $seen[$key] = true;
        $files[] = $key;
    }
}
