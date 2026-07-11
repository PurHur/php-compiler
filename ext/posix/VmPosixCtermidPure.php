<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

/**
 * posix_ctermid() without libc ctermid(3) FFI (#12684).
 *
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_ctermid)
 * glibc ctermid(NULL) returns L_ctermid buffer containing "/dev/tty" on Linux.
 */
final class VmPosixCtermidPure
{
    public static function path(): string
    {
        if (\is_readable('/dev/tty')) {
            return '/dev/tty';
        }

        return '';
    }
}
