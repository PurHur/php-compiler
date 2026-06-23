<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * disk_free_space() / disk_total_space() without libc statvfs FFI — host bootstrap path (#8989, #1492).
 *
 * VM under Zend PHP uses native \\disk_free_space()/\\disk_total_space() when FFI is disabled;
 * JIT/AOT unchanged via {@see JitStat}.
 *
 * php-src: ext/standard/filestat.c — php_disk_free_space / php_disk_total_space
 */
final class VmFsDiskPure
{
    public static function available(): bool
    {
        return \function_exists('disk_free_space') && \function_exists('disk_total_space');
    }

    /**
     * @return float|false
     */
    public static function diskFreeSpace(string $path)
    {
        return self::invoke($path, true);
    }

    /**
     * @return float|false
     */
    public static function diskTotalSpace(string $path)
    {
        return self::invoke($path, false);
    }

    /**
     * @return float|false
     */
    private static function invoke(string $path, bool $free)
    {
        if (str_contains($path, "\0")) {
            return false;
        }
        if (!self::available()) {
            return false;
        }

        $result = $free ? @\disk_free_space($path) : @\disk_total_space($path);
        if (false === $result) {
            return false;
        }

        return (float) $result;
    }
}
