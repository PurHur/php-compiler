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
        if (isset(self::$stat[$path])) {
            return self::$stat[$path];
        }
        $raw = @stat($path);
        if (false !== $raw) {
            self::$stat[$path] = $raw;
        }

        return $raw;
    }

    /**
     * @return array<int|string, int>|false
     */
    public static function lstat(string $path)
    {
        if (isset(self::$lstat[$path])) {
            return self::$lstat[$path];
        }
        $raw = @lstat($path);
        if (false !== $raw) {
            self::$lstat[$path] = $raw;
        }

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
    }

    public static function invalidatePath(string $path): void
    {
        self::clear(true, $path);
    }

    public static function reset(): void
    {
        self::$stat = [];
        self::$lstat = [];
        self::$realpath = [];
    }
}
