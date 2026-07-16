<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

/**
 * posix_getlogin()/posix_ttyname()/posix_isatty() for compiled JIT/AOT modules (#6504).
 *
 * SSOT: {@see VmPosixTerminalPure}
 * php-src: ext/posix/posix.c
 */
final class PosixTerminalJitHelper
{
    /** Empty string → JIT boxes false (same convention as getcwd). */
    public static function getlogin(): string
    {
        return VmPosixTerminalPure::getlogin() ?? '';
    }

    public static function ttyname(int $fd): string
    {
        return VmPosixTerminalPure::ttyname($fd) ?? '';
    }

    public static function isatty(int $fd): int
    {
        return VmPosixTerminalPure::isatty($fd) ? 1 : 0;
    }
}
