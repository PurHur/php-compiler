<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Per-request stat/realpath cache for VM builtins (issue #6265).
 *
 * php-src: Zend/zend_stat.c — php_stat(), php_clear_stat_cache()
 */
final class VmStatCache
{
    /** @var array<string, array<int|string, int>> */
    private static array $stat = [];

    /** @var array<string, array<int|string, int>> */
    private static array $lstat = [];

    /** @var array<string, string> */
    private static array $realpath = [];

    /**
     * @return array<int|string, int>|false
     */
    public static function stat(string $path)
    {
        if (\array_key_exists($path, self::$stat)) {
            return self::$stat[$path];
        }
        $raw = @stat($path);
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
        $raw = @lstat($path);
        self::$lstat[$path] = $raw;

        return $raw;
    }

    public static function clear(bool $clearRealpath = true, ?string $filename = null): void
    {
        if (null === $filename) {
            self::$stat = [];
            self::$lstat = [];
            if ($clearRealpath) {
                self::$realpath = [];
            }
            self::syncHostClearstatcache($clearRealpath);

            return;
        }

        unset(self::$stat[$filename], self::$lstat[$filename]);
        if ($clearRealpath) {
            unset(self::$realpath[$filename]);
            $resolved = @realpath($filename);
            if (false !== $resolved) {
                unset(self::$stat[$resolved], self::$lstat[$resolved], self::$realpath[$resolved]);
            }
        }
        self::syncHostClearstatcache($clearRealpath, $filename);
    }

    public static function invalidatePath(string $path): void
    {
        self::clear(true, $path);
    }

    /**
     * VM builtins call host stat(); keep Zend negative-cache in sync (issue #7436).
     */
    private static function syncHostClearstatcache(bool $clearRealpath, ?string $filename = null): void
    {
        if (!\function_exists('clearstatcache')) {
            return;
        }
        if (null === $filename) {
            \clearstatcache($clearRealpath);
        } else {
            \clearstatcache($clearRealpath, $filename);
        }
    }

    public static function reset(): void
    {
        self::$stat = [];
        self::$lstat = [];
        self::$realpath = [];
    }
}
