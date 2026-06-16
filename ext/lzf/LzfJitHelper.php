<?php

declare(strict_types=1);

namespace PHPCompiler\ext\lzf;

/**
 * Lowered into JIT/AOT modules for lzf_* at runtime (#8805, php-in-PHP).
 *
 * php-src: ext/lzf/lzf.c — PHP_FUNCTION(lzf_compress/lzf_decompress).
 */
final class LzfJitHelper
{
    public static function compress(string $data): string|false
    {
        return VmLzfCore::compress($data);
    }

    public static function decompress(string $data): string|false
    {
        return VmLzfCore::decompress($data);
    }
}
