<?php

declare(strict_types=1);

namespace PHPCompiler\ext\inotify;

/**
 * Thin libc ABI for inotify(7) syscalls (php-src ext/inotify/inotify.c; #6410).
 *
 * VM-only FFI — no logic in runtime/*.c.
 */
final class InotifyLibcThinAbi
{
    private static ?\FFI $ffi = null;

    private static bool $unavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    public static function init(): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return -1;
        }

        return (int) $ffi->inotify_init();
    }

    public static function addWatch(int $fd, string $pathname, int $mask): int
    {
        $ffi = self::ffi();
        if (null === $ffi || $fd < 0) {
            return -1;
        }

        return (int) $ffi->inotify_add_watch($fd, $pathname, $mask);
    }

    public static function rmWatch(int $fd, int $wd): int
    {
        $ffi = self::ffi();
        if (null === $ffi || $fd < 0) {
            return -1;
        }

        return (int) $ffi->inotify_rm_watch($fd, $wd);
    }

    /**
     * @return string|false raw inotify_event bytes
     */
    public static function readBytes(int $fd, int $length): string|false
    {
        $ffi = self::ffi();
        if (null === $ffi || $fd < 0 || $length <= 0) {
            return false;
        }

        try {
            $buf = $ffi->new('char['.$length.']');
            $n = (int) $ffi->read($fd, \FFI::addr($buf[0]), $length);
            if ($n < 0) {
                return false;
            }
            if (0 === $n) {
                return '';
            }

            return \FFI::string($buf, $n);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function readErrno(): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return 0;
        }

        $loc = $ffi->__errno_location();

        return (int) $loc[0];
    }

    private static function ffi(): ?\FFI
    {
        if (self::$unavailable) {
            return null;
        }
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!self::ffiEnabled() || !\extension_loaded('ffi')) {
            self::$unavailable = true;

            return null;
        }

        $cdef = <<<'CDEF'
int inotify_init(void);
int inotify_add_watch(int fd, const char *pathname, unsigned int mask);
int inotify_rm_watch(int fd, int wd);
ssize_t read(int fd, void *buf, size_t count);
int * __errno_location(void);
CDEF;

        foreach (['libc.so.6', 'libc.so'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);

                return self::$ffi;
            } catch (\Throwable) {
            }
        }

        self::$unavailable = true;

        return null;
    }

    private static function ffiEnabled(): bool
    {
        $v = \getenv('PHP_COMPILER_DISABLE_FFI');
        if (false !== $v && '' !== $v && '0' !== $v && 'false' !== \strtolower($v)) {
            return false;
        }

        return true;
    }
}
