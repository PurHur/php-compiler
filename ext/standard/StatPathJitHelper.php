<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * File path predicates for compiled JIT/AOT modules (#9112, #19215, #20742, php-in-PHP).
 *
 * Embed + thin standalone AOT: {@see phpc_stat_mode_kernel} / {@see phpc_access_kernel}
 * (NestedJIT leaf → {@see JitStatKernel} libc). VM SSOT for non-compiled paths remains
 * {@see VmStatPath}.
 * php-src: ext/standard/filestat.c
 */
final class StatPathJitHelper
{
    public static function exists(string $path): bool
    {
        if (!VmOpenBasedir::check($path, true, 'file_exists')) {
            return false;
        }

        return \phpc_stat_mode_kernel($path, 0) >= 0;
    }

    public static function isFile(string $path): bool
    {
        if (!VmOpenBasedir::check($path, true, 'is_file')) {
            return false;
        }

        $mode = \phpc_stat_mode_kernel($path, 0);

        // S_IFMT=0xF000, S_IFREG=0x8000 — literals so nested JIT folds cleanly (#19215).
        return $mode >= 0 && ($mode & 0xF000) === 0x8000;
    }

    public static function isDir(string $path): bool
    {
        if (!VmOpenBasedir::check($path, true, 'is_dir')) {
            return false;
        }

        $mode = \phpc_stat_mode_kernel($path, 0);

        return $mode >= 0 && ($mode & 0xF000) === 0x4000;
    }

    public static function isLink(string $path): bool
    {
        if (!VmOpenBasedir::check($path, true, 'is_link')) {
            return false;
        }

        $mode = \phpc_stat_mode_kernel($path, 1);

        return $mode >= 0 && ($mode & 0xF000) === 0xA000;
    }

    public static function isReadable(string $path): bool
    {
        return \phpc_access_kernel($path, 4);
    }

    public static function isWritable(string $path): bool
    {
        return \phpc_access_kernel($path, 2);
    }

    public static function isExecutable(string $path): bool
    {
        return \phpc_access_kernel($path, 1);
    }
}
