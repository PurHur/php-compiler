<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pspell;

/**
 * ext/pspell advertisement — php-src ext/pspell/pspell.c (#6294, #23968).
 *
 * Pure PHP {@see VmPspellNative} stays compiled in-tree but must not flip
 * {@code extension_loaded('pspell')} / {@code function_exists('pspell_new')} when host
 * Zend has no ext/pspell — same host-module gate as bz2/gmp (#25011 / #22860).
 *
 * Enable via host {@code extension_loaded('pspell')}, or explicit {@code PHP_COMPILER_ENABLE_PSPELL=1}
 * when {@see VmPspellNative::available()} (functional PHPT / local runs).
 */
final class PspellExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        if (\extension_loaded('pspell')) {
            return true;
        }

        if (!self::explicitEnableRequested()) {
            return false;
        }

        return VmPspellNative::available();
    }

    public static function advertisesBuiltins(): bool
    {
        return self::advertisesExtension();
    }

    public static function advertisesClasses(): bool
    {
        return self::advertisesExtension();
    }

    /** Compliance filenames that exercise pspell_* / extension_loaded('pspell'). */
    public static function isPspellComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'pspell')
            || str_contains($testFileName, 'extension_loaded_pspell');
    }

    /** Phantom-registration guards that assert pspell is withheld (#23968). */
    public static function isPspellPhantomComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'pspell_phantom')
            || str_contains($testFileName, 'extension_loaded_pspell_phantom');
    }

    /**
     * Functional pspell cases set {@code PHP_COMPILER_ENABLE_PSPELL} via {@code --ENV--}; module
     * phantom guards run only when pspell is withheld (#23968).
     */
    public static function runsPspellCompliance(string $testFileName): bool
    {
        if (self::isPspellPhantomComplianceCase($testFileName)) {
            return !self::advertisesExtension();
        }

        return true;
    }

    /** Explicit side-load / functional-test opt-in when host Zend lacks ext/pspell (#23968). */
    private static function explicitEnableRequested(): bool
    {
        $raw = getenv('PHP_COMPILER_ENABLE_PSPELL');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        $v = strtolower(trim($raw));

        return !\in_array($v, ['0', 'false', 'off', 'no'], true);
    }
}
