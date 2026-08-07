<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ds;

/**
 * ext/ds advertisement — PECL php-ds/ext-ds (#22549, #25086).
 *
 * Pure-PHP {@see BuiltinClasses} / {@see VmDsStorage} stay compiled in-tree but must not flip
 * {@code extension_loaded('ds')} / {@code class_exists('Ds\\Vector')} when host Zend has no
 * pecl-ds — same host-module gate as zmq (#23964).
 *
 * Enable via host {@code extension_loaded('ds')}, or explicit
 * {@code PHP_COMPILER_ENABLE_DS=1} (functional PHPT / local runs).
 */
final class DsExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        if (\extension_loaded('ds')) {
            return true;
        }

        return self::explicitEnableRequested();
    }

    public static function advertisesClasses(): bool
    {
        return self::advertisesExtension();
    }

    /** Compliance filenames that exercise Ds\* / extension_loaded('ds'). */
    public static function isDsComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'ds_vector')
            || str_contains($testFileName, 'ds_map')
            || str_contains($testFileName, 'ds_set')
            || str_contains($testFileName, 'ds_depth')
            || str_contains($testFileName, 'ds_pair')
            || str_contains($testFileName, 'extension_loaded_ds')
            || str_contains($testFileName, 'maintainer_gap_ds')
            || str_contains($testFileName, 'ds_phantom')
            || str_contains($testFileName, 'ds_extension');
    }

    /** Phantom-registration guards that assert ds is withheld (#25086). */
    public static function isDsPhantomComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'ds_phantom')
            || str_contains($testFileName, 'extension_loaded_ds_phantom')
            || str_contains($testFileName, 'maintainer_gap_ds_extension_phantom');
    }

    /**
     * Functional ds cases set {@code PHP_COMPILER_ENABLE_DS} via {@code --ENV--}; module
     * phantom guards run only when ds is withheld (#25086).
     */
    public static function runsDsCompliance(string $testFileName): bool
    {
        if (self::isDsPhantomComplianceCase($testFileName)) {
            return !self::advertisesExtension();
        }

        return true;
    }

    /** Explicit side-load / functional-test opt-in when host Zend lacks pecl-ds (#25086). */
    private static function explicitEnableRequested(): bool
    {
        $raw = getenv('PHP_COMPILER_ENABLE_DS');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        $v = strtolower(trim($raw));

        return !\in_array($v, ['0', 'false', 'off', 'no'], true);
    }
}
