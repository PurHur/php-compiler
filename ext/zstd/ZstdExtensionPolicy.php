<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zstd;

use PHPCompiler\ExtensionRegistry;

/**
 * ext/zstd advertisement — PECL php-ext-zstd (#6387, #25287).
 *
 * {@see advertisesExtension()} is folded to ext.json advertise → ExtensionRegistry (#36204).
 *
 * Pure-PHP {@see VmZstdCore} stays compiled in-tree but must not flip
 * {@code extension_loaded('zstd')} / {@code function_exists('zstd_compress')} when host
 * Zend has no pecl-zstd — same host-module gate as zmq/enchant (#23964 / #23963).
 *
 * Enable via host {@code extension_loaded('zstd')}, or explicit
 * {@code PHP_COMPILER_ENABLE_ZSTD=1} (functional PHPT / local runs).
 */
final class ZstdExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return ExtensionRegistry::advertisesExtensionFor('zstd');
    }

    public static function advertisesBuiltins(): bool
    {
        return self::advertisesExtension();
    }

    /** Compliance filenames that exercise zstd_* / extension_loaded('zstd'). */
    public static function isZstdComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'zstd')
            || str_contains($testFileName, 'extension_loaded_zstd');
    }

    /** Phantom-registration guards that assert zstd is withheld (#25287). */
    public static function isZstdPhantomComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'zstd_phantom')
            || str_contains($testFileName, 'extension_loaded_zstd_phantom')
            || str_contains($testFileName, 'zstd_lzf_extension_phantom')
            || str_contains($testFileName, 'maintainer_gap_zstd_lzf_extension_phantom');
    }

    /**
     * Functional zstd cases set {@code PHP_COMPILER_ENABLE_ZSTD} via {@code --ENV--}; module
     * phantom guards run only when zstd is withheld (#25287).
     */
    public static function runsZstdCompliance(string $testFileName): bool
    {
        if (self::isZstdPhantomComplianceCase($testFileName)) {
            return !self::advertisesExtension();
        }

        return true;
    }

}
