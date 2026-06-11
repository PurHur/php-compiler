<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * rename(2)/link(2)/copy via libc FFI only — no host PHP builtin delegation (#5213, #7971).
 *
 * php-src: ext/standard/filestat.c — php_rename, php_link; copy uses streams
 * JIT/AOT: JitRename / JitCopy / __compiler_copy (unchanged).
 */
final class VmFsPathNative
{
    private const O_RDONLY = 0;

    private const O_WRONLY_CREAT_TRUNC = 577;

    private const COPY_BUF_SIZE = 8192;

    private static ?\FFI $ffi = null;

    public static function rename(string $from, string $to): bool
    {
        if (str_contains($from, "\0") || str_contains($to, "\0")) {
            return false;
        }
        if (self::ffiEnabled()) {
            $ffi = self::ffi();
            if (null !== $ffi) {
                return 0 === (int) $ffi->rename($from, $to);
            }
        }

        return false;
    }

    public static function link(string $target, string $link): bool
    {
        if (str_contains($target, "\0") || str_contains($link, "\0")) {
            return false;
        }
        if (self::ffiEnabled()) {
            $ffi = self::ffi();
            if (null !== $ffi) {
                return 0 === (int) $ffi->link($target, $link);
            }
        }

        return false;
    }

    public static function copy(string $from, string $to): bool
    {
        if (str_contains($from, "\0") || str_contains($to, "\0")) {
            return false;
        }
        if (self::ffiEnabled()) {
            $ffi = self::ffi();
            if (null !== $ffi) {
                return self::copyViaFfi($ffi, $from, $to);
            }
        }

        return false;
    }

    private static function copyViaFfi(\FFI $ffi, string $from, string $to): bool
    {
        try {
            $inFd = (int) $ffi->open($from, self::O_RDONLY);
            if ($inFd < 0) {
                return false;
            }
            $outFd = (int) $ffi->open($to, self::O_WRONLY_CREAT_TRUNC, 0666);
            if ($outFd < 0) {
                $ffi->close($inFd);

                return false;
            }

            $ok = true;
            $buf = $ffi->new('char['.self::COPY_BUF_SIZE.']');
            while (true) {
                $n = (int) $ffi->read($inFd, \FFI::addr($buf[0]), self::COPY_BUF_SIZE);
                if ($n < 0) {
                    $ok = false;
                    break;
                }
                if (0 === $n) {
                    break;
                }
                $written = 0;
                while ($written < $n) {
                    $w = (int) $ffi->write($outFd, \FFI::addr($buf[$written]), $n - $written);
                    if ($w <= 0) {
                        $ok = false;
                        break 2;
                    }
                    $written += $w;
                }
            }

            $ffi->close($inFd);
            $ffi->close($outFd);

            return $ok;
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
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!\extension_loaded('ffi')) {
            return null;
        }
        $cdef = <<<'CDEF'
typedef long ssize_t;
typedef unsigned long size_t;
typedef int mode_t;
int rename(const char *oldpath, const char *newpath);
int link(const char *oldpath, const char *newpath);
int open(const char *pathname, int flags, ...);
ssize_t read(int fd, void *buf, size_t count);
ssize_t write(int fd, const void *buf, size_t count);
int close(int fd);
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
