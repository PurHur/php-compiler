<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

/**
 * posix_ctermid() for compiled JIT/AOT modules (#12684, php-in-PHP).
 *
 * SSOT: {@see VmPosixCtermidPure}
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_ctermid)
 */
final class PosixCtermidJitHelper
{
    public static function path(): string
    {
        return VmPosixCtermidPure::path();
    }
}
