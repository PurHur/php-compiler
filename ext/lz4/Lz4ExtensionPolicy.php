<?php

declare(strict_types=1);

namespace PHPCompiler\ext\lz4;

/**
 * ext/lz4 advertisement — PECL kjdev/php-ext-lz4 (#22529, #25087).
 *
 * Pure-PHP {@see VmLz4Native} stays compiled in-tree but must not flip
 * {@code extension_loaded('lz4')} / {@code function_exists('lz4_compress')} when host
 * Zend has no pecl-lz4 — same host-module gate as zstd/lzf (#25287).
 *
 * Enable via host {@code extension_loaded('lz4')}, or explicit
 * {@code PHP_COMPILER_ENABLE_LZ4=1} (functional PHPT / local runs).
 */
final class Lz4ExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        if (\extension_loaded('lz4')) {
            return true;
        }

        return self::explicitEnableRequested();
    }

    public static function advertisesBuiltins(): bool
    {
        return self::advertisesExtension();
    }

    /** Compliance filenames that exercise lz4_* / extension_loaded('lz4'). */
    public static function isLz4ComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'lz4')
            || str_contains($testFileName, 'extension_loaded_lz4');
    }

    /** Phantom-registration guards that assert lz4 is withheld (#25087). */
    public static function isLz4PhantomComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'lz4_phantom')
            || str_contains($testFileName, 'extension_loaded_lz4_phantom')
            || str_contains($testFileName, 'maintainer_gap_lz4_extension_phantom');
    }

    /**
     * Functional lz4 cases set {@code PHP_COMPILER_ENABLE_LZ4} via {@code --ENV--}; module
     * phantom guards run only when lz4 is withheld (#25087).
     */
    public static function runsLz4Compliance(string $testFileName): bool
    {
        if (self::isLz4PhantomComplianceCase($testFileName)) {
            return !self::advertisesExtension();
        }

        return true;
    }

    /** Explicit side-load / functional-test opt-in when host Zend lacks pecl-lz4 (#25087). */
    private static function explicitEnableRequested(): bool
    {
        $raw = getenv('PHP_COMPILER_ENABLE_LZ4');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        $v = strtolower(trim($raw));

        return !\in_array($v, ['0', 'false', 'off', 'no'], true);
    }
}
