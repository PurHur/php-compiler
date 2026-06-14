<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * libc dup(2)/fdopen(3) for php://stdin|stdout|stderr without host PHP stream wrappers (#4648).
 *
 * php-src: ext/standard/streams.c — php_stream_stdio_ops
 * JIT/AOT: {@see \PHPCompiler\JIT\Builtin\StreamStdioJit} / __compiler_fopen stdio branch
 */
final class VmFsStdioNative
{
    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    /**
     * @return int|false VM fd stream handle
     */
    public static function openDupFd(int $fd, string $mode)
    {
        if ($fd < 0 || $fd > 2) {
            return false;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        try {
            $dupFd = (int) $ffi->dup($fd);
            if ($dupFd < 0) {
                return false;
            }

            $phpMode = self::phpStreamMode($mode);
            $uri = match ($fd) {
                0 => 'php://stdin',
                1 => 'php://stdout',
                2 => 'php://stderr',
                default => 'php://fd/'.$dupFd,
            };
            $handle = VmPhpFdStream::adopt($dupFd, $uri, $phpMode);
            if (false === $handle) {
                $ffi->close($dupFd);

                return false;
            }

            return $handle;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function phpStreamMode(string $mode): string
    {
        if ('' === $mode) {
            return 'rb';
        }
        if (!str_contains($mode, 'b')) {
            return $mode.'b';
        }

        return $mode;
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
        if (self::$ffiUnavailable) {
            return null;
        }
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!\extension_loaded('ffi')) {
            self::$ffiUnavailable = true;

            return null;
        }

        $cdef = <<<'CDEF'
typedef struct _IO_FILE FILE;
int dup(int oldfd);
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
