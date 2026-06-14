<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * libc fcntl(2) O_NONBLOCK toggle for VM streams without host stream_set_blocking() (#6007, #7908).
 *
 * Mirrors {@see \PHPCompiler\JIT\Builtin\StreamMetaJit} emitSetBlocking; JIT/AOT uses
 * __compiler_stream_set_blocking on phpc_stream_handles.
 *
 * php-src: ext/standard/streams.c — php_stream_set_blocking
 */
final class VmStreamBlockingNative
{
    private const O_NONBLOCK = 2048;

    private const F_GETFL = 3;

    private const F_SETFL = 4;

    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function setBlocking(int $fd, bool $mode): bool
    {
        if ($fd < 0) {
            return true;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        try {
            $flags = (int) $ffi->fcntl($fd, self::F_GETFL, 0);
            if (-1 === $flags) {
                return false;
            }
            $nonBlockMask = ~self::O_NONBLOCK;
            $newFlags = $mode
                ? ($flags & $nonBlockMask)
                : ($flags | self::O_NONBLOCK);
            if (-1 === (int) $ffi->fcntl($fd, self::F_SETFL, $newFlags)) {
                return false;
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * proc_open pipe handles still use host stream resources (#6211) until full fd adoption.
     *
     * @param resource $fp
     */
    public static function setBlockingForHostResource($fp, bool $mode): bool
    {
        return @\stream_set_blocking($fp, $mode);
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
int fcntl(int fd, int cmd, ...);
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
