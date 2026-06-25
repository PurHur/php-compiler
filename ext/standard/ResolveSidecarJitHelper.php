<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * __compiler_resolve_sidecar_source_path for compiled JIT/AOT modules (#11412, php-in-PHP).
 *
 * Mirrors {@see \PHPCompiler\JIT\SidecarPathRemap} + build-path remap for M3 sidecar copy (#3046, #6982).
 */
final class ResolveSidecarJitHelper
{
    private const DOCKER_BUILD_PREFIX = '/compiler/build/';

    /** Remap prelinked sidecar source paths to the live repo when the original path is missing. */
    public static function resolveArgv(string $path): string
    {
        if ('' === $path || file_exists($path)) {
            return $path;
        }

        $dockerRemap = self::resolveDockerBuildPrefix($path);
        if ($dockerRemap !== $path && file_exists($dockerRemap)) {
            return $dockerRemap;
        }

        $repo = getenv('PHP_COMPILER_REPO_ROOT');
        if (!is_string($repo) || '' === $repo) {
            return $path;
        }

        $buildPos = strpos($path, '/build/');
        if (false === $buildPos) {
            return $path;
        }

        $repoRoot = rtrim(str_replace('\\', '/', $repo), '/');
        $candidate = $repoRoot.substr($path, $buildPos);
        if (file_exists($candidate)) {
            return $candidate;
        }

        return $path;
    }

    private static function resolveDockerBuildPrefix(string $path): string
    {
        if (!str_starts_with($path, self::DOCKER_BUILD_PREFIX)) {
            return $path;
        }
        $repo = getenv('PHP_COMPILER_REPO_ROOT');
        if (!is_string($repo) || '' === $repo) {
            return $path;
        }
        $repoRoot = rtrim(str_replace('\\', '/', $repo), '/');
        $candidate = $repoRoot.substr($path, strlen('/compiler'));
        if (file_exists($candidate)) {
            return $candidate;
        }

        return $path;
    }
}
