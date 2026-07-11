<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bz2;

/**
 * bzcompress()/bzdecompress() for compiled JIT/AOT modules (#8868, php-in-PHP).
 *
 * SSOT: {@see VmBz2Core}
 * php-src: ext/bz2/bz2.c
 */
final class Bz2JitHelper
{
    public static function compressArgv(string $source, int $blockSize, int $workFactor): ?string
    {
        $result = VmBz2Native::compress($source, $blockSize, $workFactor);

        return false === $result ? null : $result;
    }

    public static function decompressArgv(string $source, int $small): ?string
    {
        $result = VmBz2Native::decompress($source, $small);

        return false === $result ? null : $result;
    }
}
