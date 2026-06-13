<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * libc open(2)/read(2) for VM file reads without host PHP \\file_get_contents() (#1492, pairs StringFileGetContents).
 *
 * php-src: ext/standard/file.c — php_stream_copy_to_mem
 * JIT/AOT: __compiler_file_get_contents LLVM lowering (unchanged).
 */
final class VmFsReadNative
{
    private const O_RDONLY = 0;

    private const CHUNK = 8192;

    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    public static function read(string $path): string|false
    {
        if (str_contains($path, "\0")) {
            return false;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        try {
            $fd = (int) $ffi->open($path, self::O_RDONLY);
            if ($fd < 0) {
                return false;
            }

            $parts = [];
            $buf = $ffi->new('char['.self::CHUNK.']');
            while (true) {
                $n = (int) $ffi->read($fd, \FFI::addr($buf[0]), self::CHUNK);
                if ($n < 0) {
                    $ffi->close($fd);

                    return false;
                }
                if (0 === $n) {
                    break;
                }
                $parts[] = \FFI::string($buf, $n);
            }
            $ffi->close($fd);

            return '' === $parts ? '' : implode('', $parts);
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
ssize_t read(int fd, void *buf, size_t count);
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
