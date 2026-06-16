<?php

declare(strict_types=1);

namespace PHPCompiler\ext\lzf;

/**
 * lzf_compress/lzf_decompress facade (php-src ext/lzf/lzf.c; #6384, #8805).
 */
final class VmLzf
{
    public static function compress(string $data): string|false
    {
        return VmLzfCore::compress($data);
    }

    public static function decompress(string $data): string|false
    {
        return VmLzfCore::decompress($data);
    }

    public static function available(): bool
    {
        return true;
    }
}
