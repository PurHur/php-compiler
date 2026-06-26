<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * rename/copy/link/readlink/symlink for VM — {@see VmFsPathPure} SSOT, no libc FFI (#5213, #12316).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(rename), copy, link
 * JIT/AOT: JitRename / JitCopy / __compiler_copy (unchanged).
 */
final class VmFsPathNative
{
    public static function available(): bool
    {
        return VmFsPathPure::available();
    }

    public static function rename(string $from, string $to): bool
    {
        return VmFsPathPure::rename($from, $to);
    }

    public static function copy(string $from, string $to): bool
    {
        return VmFsPathPure::copy($from, $to);
    }

    public static function link(string $target, string $link): bool
    {
        return VmFsPathPure::link($target, $link);
    }

    /** @return string|false */
    public static function readlink(string $path)
    {
        return VmFsPathPure::readlink($path);
    }

    public static function symlink(string $target, string $link): bool
    {
        return VmFsPathPure::symlink($target, $link);
    }
}
