<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * VM stream_set_blocking — {@see VmStreamBlockingPure} SSOT, no libc fcntl FFI (#6007, #12251).
 *
 * Mirrors {@see \PHPCompiler\JIT\Builtin\StreamMetaJit} emitSetBlocking; JIT/AOT uses
 * __compiler_stream_set_blocking on phpc_stream_handles.
 *
 * php-src: ext/standard/streams.c — php_stream_set_blocking
 */
final class VmStreamBlockingNative
{
    public static function available(): bool
    {
        return VmStreamBlockingPure::available();
    }

    public static function setBlocking(int $fd, bool $mode): bool
    {
        return VmStreamBlockingPure::setBlocking($fd, $mode);
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
}
