<?php

declare(strict_types=1);

namespace PHPCompiler\Web;

/**
 * PHPC_DEPLOY_ROOT resolution for AOT deploy bundles (issue #585).
 */
final class DeployRoot
{
    public const ENV = 'PHPC_DEPLOY_ROOT';

    /**
     * Walk upward from a file path to find a directory containing phpc.json.
     */
    public static function findProjectRootForPath(string $filePath): ?string
    {
        $dir = is_file($filePath) ? dirname($filePath) : $filePath;
        $resolved = realpath($dir);
        if (false !== $resolved) {
            $dir = $resolved;
        }
        while ('' !== $dir && '/' !== $dir) {
            if (is_file($dir.'/phpc.json')) {
                return $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        return null;
    }

    /**
     * Directory path relative to project root (empty string when $absoluteDir is the root).
     */
    public static function relativeDirFromProject(string $absoluteDir, string $projectRoot): string
    {
        $root = realpath($projectRoot) ?: $projectRoot;
        $dir = realpath($absoluteDir) ?: $absoluteDir;
        $rootTrim = rtrim($root, '/');
        if ($dir === $rootTrim || $dir === $root) {
            return '';
        }
        $prefix = $rootTrim.'/';
        if (str_starts_with($dir.'/', $prefix)) {
            return substr($dir, strlen($prefix));
        }

        return $dir;
    }

    /**
     * Resolve deploy path at runtime (VM); mirrors AOT {@see phpc_deploy_path()}.
     */
    public static function resolvePath(string $relFromProjectRoot, string $fallbackAbsoluteDir): string
    {
        $root = getenv(self::ENV);
        if (false === $root || '' === $root) {
            return $fallbackAbsoluteDir;
        }
        $root = rtrim($root, '/\\');
        if ('' === $relFromProjectRoot) {
            return $root;
        }

        return $root.'/'.$relFromProjectRoot;
    }
}
