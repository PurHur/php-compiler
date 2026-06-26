<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * VM touch() without libc utime/stat/open FFI (#12145, pairs {@see VmFsTouchNative}).
 *
 * Bootstrap path: VmFsOpenNative exclusive create + host touch() for mtime/atime.
 *
 * php-src: ext/standard/filestat.c — php_touch
 */
final class VmFsTouchPure
{
    public static function available(): bool
    {
        return VmFsOpenNative::available();
    }

    public static function touch(string $path, ?int $mtime = null, ?int $atime = null): bool
    {
        if (str_contains($path, "\0")) {
            return false;
        }

        if (!VmStatPath::exists($path)) {
            $handle = VmFsOpenNative::open($path, 'c');
            if (false === $handle) {
                return false;
            }
            if (!VmFs::fclose($handle)) {
                return false;
            }
        }

        if (null === $mtime && null === $atime) {
            if (\function_exists('touch')) {
                return @\touch($path);
            }
            $handle = VmFsOpenNative::open($path, 'a');
            if (false === $handle) {
                return false;
            }

            return VmFs::fclose($handle);
        }

        if (!\function_exists('touch')) {
            return false;
        }

        return @\touch($path, $mtime, $atime);
    }
}
