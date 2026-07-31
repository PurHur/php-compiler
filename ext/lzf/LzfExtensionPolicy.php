<?php

declare(strict_types=1);

namespace PHPCompiler\ext\lzf;

/**
 * ext/lzf advertisement — PECL lzf (#6384, #25287).
 *
 * Pure-PHP {@see VmLzfCore} stays compiled in-tree but must not flip
 * {@code extension_loaded('lzf')} / {@code function_exists('lzf_compress')} when host
 * Zend has no pecl-lzf — same host-module gate as zstd/zmq (#25287 / #23964).
 *
 * Enable via host {@code extension_loaded('lzf')}, or explicit
 * {@code PHP_COMPILER_ENABLE_LZF=1} (functional PHPT / local runs).
 */
final class LzfExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        if (\extension_loaded('lzf')) {
            return true;
        }

        return self::explicitEnableRequested();
    }

    public static function advertisesBuiltins(): bool
    {
        return self::advertisesExtension();
    }

    /** Compliance filenames that exercise lzf_* / extension_loaded('lzf'). */
    public static function isLzfComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'lzf')
            || str_contains($testFileName, 'extension_loaded_lzf');
    }

    /** Phantom-registration guards that assert lzf is withheld (#25287). */
    public static function isLzfPhantomComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'lzf_phantom')
            || str_contains($testFileName, 'extension_loaded_lzf_phantom')
            || str_contains($testFileName, 'zstd_lzf_extension_phantom')
            || str_contains($testFileName, 'maintainer_gap_zstd_lzf_extension_phantom');
    }

    /**
     * Functional lzf cases set {@code PHP_COMPILER_ENABLE_LZF} via {@code --ENV--}; module
     * phantom guards run only when lzf is withheld (#25287).
     */
    public static function runsLzfCompliance(string $testFileName): bool
    {
        if (self::isLzfPhantomComplianceCase($testFileName)) {
            return !self::advertisesExtension();
        }

        return true;
    }

    /** Explicit side-load / functional-test opt-in when host Zend lacks pecl-lzf (#25287). */
    private static function explicitEnableRequested(): bool
    {
        $raw = getenv('PHP_COMPILER_ENABLE_LZF');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        $v = strtolower(trim($raw));

        return !\in_array($v, ['0', 'false', 'off', 'no'], true);
    }
}
