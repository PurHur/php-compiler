<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Per-request stat/realpath cache for VM builtins (issue #6265, #7844).
 *
 * php-src: Zend/zend_stat.c — php_stat(), php_clear_stat_cache()
 */
final class VmStatCache
{
    /** @var array<string, array<int|string, int>|false> */
    private static array $stat = [];

    /** @var array<string, array<int|string, int>|false> */
    private static array $lstat = [];

    /**
     * @return array<int|string, int>|false
     */
    public static function stat(string $path)
    {
        if (\array_key_exists($path, self::$stat)) {
            return self::$stat[$path];
        }
        $raw = VmStatNative::stat($path);
        self::$stat[$path] = $raw;

        return $raw;
    }

    /**
     * @return array<int|string, int>|false
     */
    public static function lstat(string $path)
    {
        if (\array_key_exists($path, self::$lstat)) {
            return self::$lstat[$path];
        }
        $raw = VmStatNative::lstat($path);
        self::$lstat[$path] = $raw;

        return $raw;
    }

    public static function clear(bool $clearRealpath = true, ?string $filename = null): void
    {
        if (null === $filename) {
            self::$stat = [];
            self::$lstat = [];
            if ($clearRealpath) {
                VmRealpathCache::clear();
            }
            if (\function_exists('clearstatcache')) {
                @\clearstatcache($clearRealpath);
            }

            return;
        }

        unset(self::$stat[$filename], self::$lstat[$filename]);
        if ($clearRealpath) {
            $resolved = VmStatNative::realpath($filename);
            if (false !== $resolved) {
                unset(self::$stat[$resolved], self::$lstat[$resolved]);
            }
            VmRealpathCache::remove($filename);
            if (false !== $resolved) {
                VmRealpathCache::remove($resolved);
            }
        }
        if (\function_exists('clearstatcache')) {
            @\clearstatcache($clearRealpath, $filename);
            if ($clearRealpath) {
                $resolved = VmStatNative::realpath($filename);
                if (false !== $resolved && $resolved !== $filename) {
                    @\clearstatcache(true, $resolved);
                }
            }
        }
    }

    public static function invalidatePath(string $path): void
    {
        self::clear(true, $path);
    }

    public static function reset(): void
    {
        self::$stat = [];
        self::$lstat = [];
        VmRealpathCache::reset();
    }
}
