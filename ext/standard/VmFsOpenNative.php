<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * VM fopen on regular paths — {@see VmFsOpenPure} SSOT, no libc FFI (#8950, #12315).
 *
 * php-src: ext/standard/streams.c — _php_stream_fopen
 * JIT/AOT: {@see \PHPCompiler\JIT\Builtin\StreamIoJit} / __compiler_fopen (unchanged)
 */
final class VmFsOpenNative
{
    /**
     * @return int|false VM fd stream handle
     */
    public static function open(string $path, string $mode): int|false
    {
        return VmFsOpenPure::open($path, $mode);
    }

    public static function available(): bool
    {
        return VmFsOpenPure::available();
    }
}
