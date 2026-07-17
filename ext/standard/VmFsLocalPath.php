<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Resolve relative local paths before host-style fopen/file_put_contents in compiled helpers.
 *
 * User-script AOT nested helpers call {@see VmFsReadPure} / {@see VmFsWritePure}; libc
 * defer kernels used absolute open(2) paths while PHP lowering kept argv-relative strings
 * (#19966, #16075).
 */
final class VmFsLocalPath
{
    public static function resolveAgainstCwd(string $path): string
    {
        if ('' === $path || str_contains($path, "\0")) {
            return $path;
        }
        if (str_starts_with($path, '/')) {
            return $path;
        }
        if (\strlen($path) >= 2 && ':' === $path[1]) {
            return $path;
        }
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:/', $path)) {
            return $path;
        }
        $cwd = VmFs::getcwd();
        if (false === $cwd || '' === $cwd) {
            return $path;
        }

        return str_ends_with($cwd, '/') ? $cwd.$path : $cwd.'/'.$path;
    }
}
