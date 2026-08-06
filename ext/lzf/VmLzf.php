<?php

declare(strict_types=1);

namespace PHPCompiler\ext\lzf;

/**
 * lzf_compress/lzf_decompress/lzf_optimized_for facade (php-src ext/lzf/lzf.c; #6384, #8805, #28063).
 */
final class VmLzf
{
    /** PECL PHP_LZF_ULTRA_FAST — pure-PHP VmLzfCore is the bundled speed build. */
    public const OPTIMIZED_FOR_SPEED = 1;

    public static function compress(string $data): string|false
    {
        return VmLzfCore::compress($data);
    }

    public static function decompress(string $data): string|false
    {
        return VmLzfCore::decompress($data);
    }

    /**
     * @return int PECL returns int|false; system-liblzf would be false — we always use bundled PHP.
     */
    public static function optimizedFor(): int
    {
        return self::OPTIMIZED_FOR_SPEED;
    }

    public static function available(): bool
    {
        return true;
    }
}
