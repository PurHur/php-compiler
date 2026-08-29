<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zstd;

/**
 * VM zstd_* — pure PHP via {@see VmZstdCore} (#8869, #6382, #6387).
 *
 * php-src: ext/zstd/zstd.c
 */
final class VmZstdNative
{
    public static function available(): bool
    {
        return VmZstdCore::available();
    }

    /**
     * ZSTD_versionNumber() — pecl zstd.c ZSTD_VERSION_NUMBER (#28079).
     */
    public static function versionNumber(): int
    {
        return VmZstdCore::versionNumber();
    }

    /**
     * ZSTD_versionString() — pecl zstd.c ZSTD_VERSION_TEXT (#28079).
     */
    public static function versionText(): string
    {
        return VmZstdCore::versionText();
    }

    public static function compress(string $data, int $level = 3): string|false
    {
        return VmZstdCore::compress($data, $level);
    }

    public static function decompress(string $data): string|false
    {
        return VmZstdCore::decompress($data);
    }
}
