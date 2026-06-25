<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * libc open(2)/write(2)/flock(2) for VM file writes; falls back to {@see VmFsWritePure} when FFI unavailable (#8950).
 *
 * php-src: ext/standard/file.c — php_file_put_contents
 * JIT/AOT: __compiler_file_put_contents LLVM lowering (unchanged).
 */
final class VmFsWriteNative
{
    private const O_WRONLY = 1;

    private const O_CREAT = 64;

    private const O_TRUNC = 512;

    private const O_APPEND = 1024;

    private const LOCK_EX = 2;

    private const FILE_APPEND = 8;

    private const LOCK_EX_FLAG = 2;

    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi() || VmFsWritePure::available();
    }

    public static function write(string $path, string $data, int $flags = 0): int|false
    {
        if (str_contains($path, "\0")) {
            return false;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return VmFsWritePure::write($path, $data, $flags);
        }

        $openFlags = 0 !== ($flags & self::FILE_APPEND)
            ? self::O_WRONLY | self::O_CREAT | self::O_APPEND
            : self::O_WRONLY | self::O_CREAT | self::O_TRUNC;

        try {
            $fd = (int) $ffi->open($path, $openFlags, 0666);
            if ($fd < 0) {
                return false;
            }

            if (0 !== ($flags & self::LOCK_EX_FLAG)) {
                if (0 !== (int) $ffi->flock($fd, self::LOCK_EX)) {
                    $ffi->close($fd);

                    return false;
                }
            }

            $len = \strlen($data);
            if (0 === $len) {
                $ffi->close($fd);

                return 0;
            }

            $buf = $ffi->new('char['.$len.']');
            \FFI::memcpy($buf, $data, $len);
            $written = 0;
            while ($written < $len) {
                $w = (int) $ffi->write($fd, \FFI::addr($buf[$written]), $len - $written);
                if ($w <= 0) {
                    $ffi->close($fd);

                    return false;
                }
                $written += $w;
            }

            $ffi->close($fd);

            return $written;
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
        if (self::$ffiUnavailable || !self::ffiEnabled() || !\extension_loaded('ffi')) {
            self::$ffiUnavailable = true;

            return null;
        }

        $cdef = <<<'CDEF'
typedef long ssize_t;
typedef unsigned long size_t;
int open(const char *pathname, int flags, ...);
ssize_t write(int fd, const void *buf, size_t count);
int flock(int fd, int operation);
int close(int fd);
CDEF;

        foreach (['libc.so.6', 'libc.so'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);

                return self::$ffi;
            } catch (\Throwable) {
            }
        }

        self::$ffiUnavailable = true;

        return null;
    }
}
