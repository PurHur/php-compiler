<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * rename/copy/link/readlink/symlink via thin libc FFI — no VM builtin re-entry (#14110, #7971).
 *
 * VM path uses libc directly; {@see VmFsPathPure} remains bootstrap/Zend SSOT when FFI is off.
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(rename), copy, link
 * php-src: ext/standard/filestat.c — readlink, symlink
 * JIT/AOT: JitRename / JitCopy / JitSymlink / JitReadlink (unchanged).
 */
final class VmFsPathNative
{
    private const AT_FDCWD = -100;

    private const READLINK_BUF = 4096;

    private static ?\FFI $ffi = null;

    public static function available(): bool
    {
        return null !== self::ffi() || VmFsPathPure::available();
    }

    public static function rename(string $from, string $to): bool
    {
        if (str_contains($from, "\0") || str_contains($to, "\0")) {
            return false;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return VmFsPathPure::rename($from, $to);
        }

        return 0 === (int) $ffi->rename($from, $to);
    }

    public static function copy(string $from, string $to): bool
    {
        if (str_contains($from, "\0") || str_contains($to, "\0")) {
            return false;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return VmFsPathPure::copy($from, $to);
        }
        $in = @$ffi->fopen($from, 'rb');
        if (null === $in) {
            return false;
        }
        $out = @$ffi->fopen($to, 'wb');
        if (null === $out) {
            $ffi->fclose($in);

            return false;
        }
        $bufSize = 8192;
        $buf = $ffi->new("char[{$bufSize}]");
        $ok = true;
        while (true) {
            $n = (int) $ffi->fread($buf, 1, $bufSize, $in);
            if ($n < 0) {
                $ok = false;
                break;
            }
            if (0 === $n) {
                break;
            }
            $written = (int) $ffi->fwrite($buf, 1, $n, $out);
            if ($written !== $n) {
                $ok = false;
                break;
            }
        }
        $ffi->fclose($in);
        $ffi->fclose($out);

        return $ok;
    }

    public static function link(string $target, string $link): bool
    {
        if (str_contains($target, "\0") || str_contains($link, "\0")) {
            return false;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return VmFsPathPure::link($target, $link);
        }

        return 0 === (int) $ffi->link($target, $link);
    }

    /** @return string|false */
    public static function readlink(string $path)
    {
        if (str_contains($path, "\0")) {
            return false;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return VmFsPathPure::readlink($path);
        }
        $buf = $ffi->new('char['.self::READLINK_BUF.']');
        $n = (int) $ffi->readlink($path, $buf, self::READLINK_BUF);
        if ($n < 0) {
            return false;
        }

        return \FFI::string($buf, $n);
    }

    public static function symlink(string $target, string $link): bool
    {
        if (str_contains($target, "\0") || str_contains($link, "\0")) {
            return false;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return VmFsPathPure::symlink($target, $link);
        }

        return 0 === (int) $ffi->symlinkat($target, self::AT_FDCWD, $link);
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
typedef unsigned long size_t;

int rename(const char *oldpath, const char *newpath);
int link(const char *oldpath, const char *newpath);
ssize_t readlink(const char *pathname, char *buf, size_t bufsiz);
int symlinkat(const char *target, int newdirfd, const char *linkpath);

typedef struct _IO_FILE FILE;
FILE *fopen(const char *pathname, const char *mode);
size_t fread(void *ptr, size_t size, size_t nmemb, FILE *stream);
size_t fwrite(const void *ptr, size_t size, size_t nmemb, FILE *stream);
int fclose(FILE *stream);
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
