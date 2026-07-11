<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

/**
 * posix_strerror() for compiled JIT/AOT modules (#12477, php-in-PHP).
 *
 * SSOT: {@see VmPosixStrerrorPure}
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_strerror)
 */
final class PosixStrerrorJitHelper
{
    public static function message(int $errno): string
    {
        return VmPosixStrerrorPure::message($errno);
    }
}
