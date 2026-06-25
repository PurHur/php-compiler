<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * touch() via libc stat/open/utime — no host PHP @touch() delegation (#8430, pairs StringFsDirJit::emitTouch).
 *
 * php-src: ext/standard/filestat.c — php_touch
 */
final class VmFsTouchNative
{
    private const O_WRONLY_CREAT_TRUNC = 577;

    private const STAT_BUF_SIZE = 144;

    private static ?\FFI $ffi = null;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    public static function touch(string $path, ?int $mtime = null, ?int $atime = null): bool
    {
        if (str_contains($path, "\0")) {
            return false;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        try {
            $st = $ffi->new('char['.self::STAT_BUF_SIZE.']');
            if (0 !== (int) $ffi->stat($path, \FFI::addr($st[0]))) {
                $fd = (int) $ffi->open($path, self::O_WRONLY_CREAT_TRUNC, 0666);
                if ($fd < 0) {
                    return false;
                }
                if (0 !== (int) $ffi->close($fd)) {
                    return false;
                }
            }

            // php-src filestat.c — omitted mtime/atime → current time; explicit negatives pass through.
            if (null === $mtime && null === $atime) {
                return 0 === (int) $ffi->utime($path, null);
            }

            $now = (int) $ffi->time(null);
            $mtimeEff = $mtime ?? $now;
            $atimeEff = $atime ?? $mtimeEff;
            $times = $ffi->new('long[2]');
            $times[0] = $atimeEff;
            $times[1] = $mtimeEff;

            return 0 === (int) $ffi->utime($path, \FFI::addr($times[0]));
        } catch (\Throwable) {
            return false;
        }
    }

    private static function ffiEnabled(): bool
    {
        $v = getenv('PHP_COMPILER_DISABLE_FFI');
        if (false !== $v && '' !== $v && '0' !== $v && 'false' !== strtolower($v)) {
            return false;
        }

        return true;
    }

    private static function ffi(): ?\FFI
    {
        if (!self::ffiEnabled()) {
            return null;
        }
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!\extension_loaded('ffi')) {
            return null;
        }

        $cdef = <<<'CDEF'
typedef long time_t;
time_t time(time_t *t);
int stat(const char *pathname, void *statbuf);
int open(const char *pathname, int flags, ...);
int close(int fd);
int utime(const char *filename, const long *times);
CDEF;

        foreach (['libc.so.6', 'libc.so'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);

                return self::$ffi;
            } catch (\Throwable) {
            }
        }

        return null;
    }
}
