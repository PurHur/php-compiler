<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bz2;

/**
 * VM bzcompress()/bzdecompress() — pure PHP via {@see VmBz2Core} (#8868, #12193).
 *
 * No libbz2 FFI on the default path — shrinks native link surface for self-host/M5.
 */
final class VmBz2Native
{
    public static function available(): bool
    {
        return VmBz2Core::available();
    }

    public static function compress(string $source, int $blockSize100k = 4, int $workFactor = 0): string|false
    {
        return VmBz2Core::compress($source, $blockSize100k, $workFactor);
    }

    public static function decompress(string $source, int $small = 0): string|false
    {
        return VmBz2Core::decompress($source, $small);
    }
}
