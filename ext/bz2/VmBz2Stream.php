<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bz2;

/**
 * bzopen/bzread/bzwrite/bzclose — bzip2 stream resource API (ext/bz2/bz2.c, #17301).
 */
final class VmBz2Stream
{
    public static function bzopen(string $filename, string $mode): int|false
    {
        if (!VmBz2StreamPure::available()) {
            return false;
        }

        return VmBz2StreamPure::bzopen($filename, $mode);
    }

    public static function isBzHandle(int $handle): bool
    {
        return VmBz2StreamPure::isHandle($handle);
    }

    public static function bzwrite(int $handle, string $data, ?int $length = null): int|false
    {
        if (!VmBz2StreamPure::isHandle($handle)) {
            return false;
        }

        return VmBz2StreamPure::bzwrite($handle, $data, $length);
    }

    public static function bzread(int $handle, int $length = 1024): string|false
    {
        if (!VmBz2StreamPure::isHandle($handle)) {
            return false;
        }

        return VmBz2StreamPure::bzread($handle, $length);
    }

    public static function bzclose(int $handle): bool
    {
        if (!VmBz2StreamPure::isHandle($handle)) {
            return false;
        }

        return VmBz2StreamPure::bzclose($handle);
    }
}
