<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Config;

/**
 * Remap Docker-prelinked M3 sidecar paths (/compiler/build/.m3_*) to the live repo (#3046).
 */
final class SidecarPathRemap
{
    private const DOCKER_BUILD_PREFIX = '/compiler/build/';

    public static function resolve(string $path): string
    {
        if ('' === $path || is_file($path)) {
            return $path;
        }
        if (!str_starts_with($path, self::DOCKER_BUILD_PREFIX)) {
            return $path;
        }
        $repo = Config::getenv('PHP_COMPILER_REPO_ROOT');
        if (!is_string($repo) || '' === $repo) {
            return $path;
        }
        $repo = rtrim(str_replace('\\', '/', $repo), '/');
        $candidate = $repo.substr($path, strlen('/compiler'));
        if (is_file($candidate)) {
            return $candidate;
        }

        return $path;
    }
}
