<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * POSIX R_OK/W_OK/X_OK aliases — delegates to {@see VmFsAccessPure} (#8990, pairs JitStat).
 *
 * php-src: ext/standard/filestat.c — php_is_writable / php_is_readable / php_is_executable
 */
final class VmFsAccessNative
{
    public const R_OK = 4;

    public const W_OK = 2;

    public const X_OK = 1;

    public static function available(): bool
    {
        return VmStatNative::available() && VmProcessIdentityNative::available();
    }

    public static function access(string $path, int $mode): bool
    {
        return VmFsAccessPure::access($path, $mode);
    }
}
