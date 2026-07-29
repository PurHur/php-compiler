<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bz2;

/**
 * ext/bz2 surface advertisement — php-src ext/bz2/bz2.c (#11992, #14219, #25011).
 *
 * Pure PHP {@see VmBz2Core} stays compiled in-tree but must not flip
 * {@code extension_loaded('bz2')} / {@code function_exists('bzcompress')} when host Zend has no
 * ext/bz2 — same host-module gate as zip/pgsql (#25010 / #24994). Forward
 * {@code PHP_COMPILER_PROFILE=8.4} alone must not invent the optional module.
 *
 * Enable via host {@code extension_loaded('bz2')}, or explicit {@code PHP_COMPILER_ENABLE_BZ2=1}
 * when {@see VmBz2Native::available()} (functional PHPT / local runs).
 */
final class Bz2ExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        if (\extension_loaded('bz2')) {
            return true;
        }

        if (!self::explicitEnableRequested()) {
            return false;
        }

        return VmBz2Native::available();
    }

    /** Compliance filenames that exercise bz* / extension_loaded('bz2'). */
    public static function isBz2ComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'bz2')
            || str_contains($testFileName, 'bzcompress')
            || str_contains($testFileName, 'bzdecompress')
            || str_contains($testFileName, 'bzopen')
            || str_contains($testFileName, 'bzread')
            || str_contains($testFileName, 'bzwrite')
            || str_contains($testFileName, 'bzclose')
            || str_contains($testFileName, 'bzerrno')
            || str_contains($testFileName, 'bzerror')
            || str_contains($testFileName, 'bzerrstr')
            || str_contains($testFileName, 'bzflush');
    }

    /** Phantom-registration guards that assert bz2 is withheld (#14219 / #25011). */
    public static function isBz2ModulePhantomComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'bz2_phantom')
            || str_contains($testFileName, 'extension_loaded_bz2_phantom');
    }

    /**
     * Functional bz2 cases set {@code PHP_COMPILER_ENABLE_BZ2} via {@code --ENV--}; module
     * phantom guards run only when bz2 is withheld (#25011).
     */
    public static function runsBz2Compliance(string $testFileName): bool
    {
        if (self::isBz2ModulePhantomComplianceCase($testFileName)) {
            return !self::advertisesExtension();
        }

        return true;
    }

    /** Explicit side-load / functional-test opt-in when host Zend lacks ext/bz2 (#25011). */
    private static function explicitEnableRequested(): bool
    {
        $raw = getenv('PHP_COMPILER_ENABLE_BZ2');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        $v = strtolower(trim($raw));

        return !\in_array($v, ['0', 'false', 'off', 'no'], true);
    }
}
