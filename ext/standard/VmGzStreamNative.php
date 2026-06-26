<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * VM gz* stream shim — delegates to {@see VmGzStreamPure} (#8936, #6168).
 *
 * libz gzFile FFI removed; compression uses {@see VmZlib} on buffered I/O.
 */
final class VmGzStreamNative
{
    public static function available(): bool
    {
        return VmGzStreamPure::available();
    }

    public static function isNativeHandle(int $handle): bool
    {
        return VmGzStreamPure::isHandle($handle);
    }

    public static function gzopen(string $filename, string $mode): int|false
    {
        return VmGzStreamPure::gzopen($filename, $mode);
    }

    public static function gzwrite(int $handle, string $data, ?int $length = null): int|false
    {
        return VmGzStreamPure::gzwrite($handle, $data, $length);
    }

    public static function gzread(int $handle, int $length = 8192): string|false
    {
        return VmGzStreamPure::gzread($handle, $length);
    }

    public static function gzclose(int $handle): bool
    {
        return VmGzStreamPure::gzclose($handle);
    }
}
