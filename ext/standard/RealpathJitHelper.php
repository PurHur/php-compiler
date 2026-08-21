<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Pure-PHP realpath normalize for Zend/unit parity (#15323).
 *
 * User-script JIT/AOT `realpath()` uses libc via {@see \PHPCompiler\JIT\Builtin\StringRealpath}
 * (#33432) — this helper returned empty under thin NestedJIT (#33287).
 *
 * php-src: ext/standard/basic_functions.c — php_realpath
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
