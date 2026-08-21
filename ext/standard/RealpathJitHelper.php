<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * realpath() for compiled JIT/AOT modules (#15323, php-in-PHP).
 *
 * php-src: ext/standard/basic_functions.c — php_realpath
 *
 * NestedJIT must not call the PHP `realpath()` builtin — that re-enters
 * {@see \PHPCompiler\JIT\Builtin\StringRealpath} and returns empty (#33287).
 *
 * Keep the leaf minimal: no `str_replace` (missing NestedJIT link) and no
 * per-byte `$path[$i]` loops (thin-AOT segfault). Use `explode('/')` only.
 */
final class RealpathJitHelper
{
    public static function resolveArgv(string $path): ?string
    {
        if ('' === $path) {
            $path = '.';
        }
        if (str_contains($path, "\0")) {
            return null;
        }

        $absolute = str_starts_with($path, '/');
        if (!$absolute) {
            $cwd = \getcwd();
            if (false === $cwd || '' === $cwd) {
                return null;
            }
            $path = $cwd.'/'.$path;
            $absolute = true;
        }

        $normalized = self::normalizeSlashPath($path);
        if ('' === $normalized) {
            return null;
        }
        if (!\file_exists($normalized)) {
            return null;
        }

        return $normalized;
    }

    /** Collapse `.` / `..` for `/`-separated paths (Linux NestedJIT leaf). */
    private static function normalizeSlashPath(string $path): string
    {
        $absolute = str_starts_with($path, '/');
        $segments = \explode('/', $path);
        $parts = [];
        foreach ($segments as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }
            if ('..' === $segment) {
                if ([] !== $parts) {
                    \array_pop($parts);
                }

                continue;
            }
            $parts[] = $segment;
        }
        $joined = \implode('/', $parts);

        return $absolute ? '/'.$joined : $joined;
    }
}
