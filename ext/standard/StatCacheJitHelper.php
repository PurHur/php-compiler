<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * JIT/AOT stat mode cache + clearstatcache for compiled modules (#9244, php-in-PHP).
 *
 * VM SSOT: {@see VmStatCache} (same clear/stat semantics).
 * php-src: ext/standard/filestat.c — php_clear_stat_cache(), php_stat()
 */
final class StatCacheJitHelper
{
    /** @return int st_mode on success, -1 on failure (LLVM i32 ABI) */
    public static function modeCached(string $path, int $useLstat): int
    {
        if ('' === $path) {
            return -1;
        }
        $lstat = 0 !== $useLstat;
        $raw = $lstat ? VmStatCache::lstat($path) : VmStatCache::stat($path);
        if (false === $raw) {
            return -1;
        }

        if (isset($raw['mode'])) {
            return (int) $raw['mode'];
        }
        if (isset($raw[2])) {
            return (int) $raw[2];
        }

        return -1;
    }

    public static function clearAll(): void
    {
        VmStatCache::clear();
    }

    public static function clearWithFlag(int $clearRealpath): void
    {
        VmStatCache::clear(0 !== $clearRealpath);
    }

    public static function clearPath(int $clearRealpath, string $filename): void
    {
        VmStatCache::clear(0 !== $clearRealpath, $filename);
    }
}
