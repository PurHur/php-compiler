<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zstd;

/**
 * ext/zstd advertisement — PECL php-ext-zstd (#6387, #25287).
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
        if (\extension_loaded('zstd')) {
            return true;
        }

        return self::explicitEnableRequested();
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

    /** Explicit side-load / functional-test opt-in when host Zend lacks pecl-zstd (#25287). */
    private static function explicitEnableRequested(): bool
    {
        $raw = getenv('PHP_COMPILER_ENABLE_ZSTD');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        $v = strtolower(trim($raw));

        return !\in_array($v, ['0', 'false', 'off', 'no'], true);
    }
}
