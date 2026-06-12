<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * VM path predicates via VmStatCache + libc access — mirrors {@see JitStat} (#8186).
 *
 * php-src: ext/standard/filestat.c
 */
final class VmStatPath
{
    private const S_IFMT = 0xF000;

    private const S_IFDIR = 0x4000;

    private const S_IFREG = 0x8000;

    private const S_IFLNK = 0xA000;

    public static function exists(string $path): bool
    {
        return false !== VmStatCache::stat($path);
    }

    public static function isDir(string $path): bool
    {
        return self::modeMatches($path, self::S_IFDIR, false);
    }

    public static function isFile(string $path): bool
    {
        return self::modeMatches($path, self::S_IFREG, false);
    }

    public static function isLink(string $path): bool
    {
        return self::modeMatches($path, self::S_IFLNK, true);
    }

    public static function isReadable(string $path): bool
    {
        return VmFsAccessNative::access($path, VmFsAccessNative::R_OK);
    }

    public static function isWritable(string $path): bool
    {
        return VmFsAccessNative::access($path, VmFsAccessNative::W_OK);
    }

    public static function isExecutable(string $path): bool
    {
        return VmFsAccessNative::access($path, VmFsAccessNative::X_OK);
    }

    private static function modeMatches(string $path, int $expectedType, bool $lstat): bool
    {
        $stat = $lstat ? VmStatCache::lstat($path) : VmStatCache::stat($path);
        if (false === $stat) {
            return false;
        }

        return ($stat['mode'] & self::S_IFMT) === $expectedType;
    }
}
