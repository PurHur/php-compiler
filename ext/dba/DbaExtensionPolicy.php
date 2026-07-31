<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dba;

/**
 * ext/dba advertisement — php-src ext/dba/dba.c (#4422, #24134).
 *
 * Pure-PHP flatfile/inifile handlers stay compiled in-tree but must not flip
 * {@code extension_loaded('dba')} / {@code function_exists('dba_open')} /
 * {@code class_exists('Dba\\Connection')} when host Zend has no ext/dba —
 * same host-module gate as mailparse (#24908).
 *
 * Enable via host {@code extension_loaded('dba')}, or explicit
 * {@code PHP_COMPILER_ENABLE_DBA=1} (functional PHPT / local runs).
 */
final class DbaExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        if (\extension_loaded('dba')) {
            return true;
        }

        return self::explicitEnableRequested();
    }

    public static function advertisesBuiltins(): bool
    {
        return self::advertisesExtension();
    }

    public static function advertisesClasses(): bool
    {
        return self::advertisesExtension();
    }

    /** Compliance filenames that exercise dba_* / Dba\* / extension_loaded('dba'). */
    public static function isDbaComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'dba_')
            || str_contains($testFileName, 'extension_loaded_dba')
            || str_contains($testFileName, 'dba_connection')
            || str_contains($testFileName, 'maintainer_gap_dba');
    }

    /** Phantom-registration guards that assert dba is withheld (#24134). */
    public static function isDbaPhantomComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'dba_phantom')
            || str_contains($testFileName, 'extension_loaded_dba_phantom')
            || str_contains($testFileName, 'maintainer_gap_dba_extension_phantom');
    }

    /**
     * Functional dba cases set {@code PHP_COMPILER_ENABLE_DBA} via {@code --ENV--}; module
     * phantom guards run only when dba is withheld (#24134).
     */
    public static function runsDbaCompliance(string $testFileName): bool
    {
        if (self::isDbaPhantomComplianceCase($testFileName)) {
            return !self::advertisesExtension();
        }

        return true;
    }

    /** Explicit side-load / functional-test opt-in when host Zend lacks ext/dba (#24134). */
    private static function explicitEnableRequested(): bool
    {
        $raw = getenv('PHP_COMPILER_ENABLE_DBA');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        $v = strtolower(trim($raw));

        return !\in_array($v, ['0', 'false', 'off', 'no'], true);
    }
}
