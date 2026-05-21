<?php

declare(strict_types=1);

namespace PHPCompiler\Web;

/**
 * Resolve relative include/require paths against the compiling file location.
 */
final class IncludePathResolver
{
    public static function resolve(string $path, string $fromFile): ?string
    {
        if ('' === $path) {
            return null;
        }
        if ($path[0] === '/' || (strlen($path) > 1 && $path[1] === ':')) {
            if (!is_file($path)) {
                return null;
            }
            $resolved = realpath($path);

            return false !== $resolved ? $resolved : $path;
        }
        $base = dirname($fromFile);
        $candidate = $base.'/'.$path;
        if (!is_file($candidate)) {
            return null;
        }
        $resolved = realpath($candidate);

        return false !== $resolved ? $resolved : $candidate;
    }
}
