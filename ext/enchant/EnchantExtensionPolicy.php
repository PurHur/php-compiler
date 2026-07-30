<?php

declare(strict_types=1);

namespace PHPCompiler\ext\enchant;

/**
 * ext/enchant advertisement — php-src ext/enchant/enchant.c (#6230, #23963).
 *
 * Pure PHP {@see VmEnchantNative} stays compiled in-tree but must not flip
 * {@code extension_loaded('enchant')} / {@code function_exists('enchant_broker_init')} when host
 * Zend has no ext/enchant — same host-module gate as pspell/bz2 (#23968 / #25011).
 *
 * Enable via host {@code extension_loaded('enchant')}, or explicit {@code PHP_COMPILER_ENABLE_ENCHANT=1}
 * when {@see VmEnchantNative::available()} (functional PHPT / local runs).
 */
final class EnchantExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        if (\extension_loaded('enchant')) {
            return true;
        }

        if (!self::explicitEnableRequested()) {
            return false;
        }

        return VmEnchantNative::available();
    }

    public static function advertisesBuiltins(): bool
    {
        return self::advertisesExtension();
    }

    public static function advertisesClasses(): bool
    {
        return self::advertisesExtension();
    }

    /** Compliance filenames that exercise enchant_* / extension_loaded('enchant'). */
    public static function isEnchantComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'enchant')
            || str_contains($testFileName, 'extension_loaded_enchant');
    }

    /** Phantom-registration guards that assert enchant is withheld (#23963). */
    public static function isEnchantPhantomComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'enchant_phantom')
            || str_contains($testFileName, 'extension_loaded_enchant_phantom')
            || str_contains($testFileName, 'maintainer_gap_enchant_extension_phantom');
    }

    /**
     * Functional enchant cases set {@code PHP_COMPILER_ENABLE_ENCHANT} via {@code --ENV--}; module
     * phantom guards run only when enchant is withheld (#23963).
     */
    public static function runsEnchantCompliance(string $testFileName): bool
    {
        if (self::isEnchantPhantomComplianceCase($testFileName)) {
            return !self::advertisesExtension();
        }

        return true;
    }

    /** Explicit side-load / functional-test opt-in when host Zend lacks ext/enchant (#23963). */
    private static function explicitEnableRequested(): bool
    {
        $raw = getenv('PHP_COMPILER_ENABLE_ENCHANT');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        $v = strtolower(trim($raw));

        return !\in_array($v, ['0', 'false', 'off', 'no'], true);
    }
}
