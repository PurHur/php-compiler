<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zstd;

/**
 * VM zstd frame codec — delegates to {@see ZstdJitHelper} (#8869).
 *
 * php-src: ext/zstd/zstd.c — php_zstd_compress(), php_zstd_decompress() (behavior reference).
 */
final class VmZstdCore
{
    public static function available(): bool
    {
        return true;
    }

    public static function compress(string $data, int $level = 3): string|false
    {
        $result = ZstdJitHelper::compress($data, $level);

        return null === $result ? false : $result;
    }

    public static function decompress(string $data): string|false
    {
        $result = ZstdJitHelper::decompress($data);

        return null === $result ? false : $result;
    }
}
