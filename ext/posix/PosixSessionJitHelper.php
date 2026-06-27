<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

/**
 * posix_getsid()/posix_getpgid() for compiled JIT/AOT modules (#12685, php-in-PHP).
 *
 * SSOT: {@see VmPosixSessionPure}
 * php-src: ext/posix/posix.c — PHP_FUNCTION(posix_getsid), posix_getpgid
 */
final class PosixSessionJitHelper
{
    /** Sentinel for JIT boxedPidOrFalse when procfs lookup fails. */
    private const FAIL = -1;

    public static function getsid(int $pid): int
    {
        $sid = VmPosixSessionPure::getsid($pid);

        return null === $sid ? self::FAIL : $sid;
    }

    public static function getpgid(int $pid): int
    {
        $pgid = VmPosixSessionPure::getpgid($pid);

        return null === $pgid ? self::FAIL : $pgid;
    }
}
