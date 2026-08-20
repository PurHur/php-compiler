<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * copy() for compiled JIT/AOT modules (#9585, php-in-PHP).
 *
 * NestedJIT must not call unbound `@\copy` (#32466): that Internal is not on the
 * pre-register NestedJIT whitelist, so the compiled helper always returned 0.
 * `file_get_contents` / `file_put_contents` already have NestedJIT libc leaves
 * (#29833 / #30127) — same observable for ordinary filesystem paths as
 * {@see VmFsPathPure::copy()} → `@\copy`.
 *
 * SSOT (VM): {@see VmFs::copy()}
 * php-src: ext/standard/file.c — PHP_FUNCTION(copy)
 */
final class CopyJitHelper
{
    /** @return 0|1 ABI for __compiler_copy */
    public static function copyArgv(string $from, string $to): int
    {
        if (\is_dir($from)) {
            TriggerErrorJitHelper::warning(
                'The first argument to copy() function cannot be a directory'
            );

            return 0;
        }

        // NestedJIT-safe leaf path (#32466) — do not call VmFs::copy → @\copy.
        $data = @\file_get_contents($from);
        if (false === $data) {
            return 0;
        }
        if (false === @\file_put_contents($to, $data)) {
            return 0;
        }

        return 1;
    }
}
