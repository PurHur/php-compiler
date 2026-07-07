<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bz2;

/**
 * VM bz* stream shim — delegates to {@see VmBz2StreamPure} (#17301).
 */
final class VmBz2StreamNative
{
    public static function available(): bool
    {
        return VmBz2StreamPure::available();
    }

    public static function isNativeHandle(int $handle): bool
    {
        return VmBz2StreamPure::isHandle($handle);
    }

    public static function bzopen(string $filename, string $mode): int|false
    {
        return VmBz2StreamPure::bzopen($filename, $mode);
    }

    public static function bzwrite(int $handle, string $data, ?int $length = null): int|false
    {
        return VmBz2StreamPure::bzwrite($handle, $data, $length);
    }

    public static function bzread(int $handle, int $length = 4096): string|false
    {
        return VmBz2StreamPure::bzread($handle, $length);
    }

    public static function bzclose(int $handle): bool
    {
        return VmBz2StreamPure::bzclose($handle);
    }
}
