<?php

declare(strict_types=1);

namespace PHPCompiler\ext\odbc;

use PHPCompiler\ExtensionRegistry;

/**
 * ext/odbc advertisement (php-src ext/odbc/php_odbc.c; #6293, #23969).
 *
 * {@see advertisesExtension()} is folded to ext.json advertise → ExtensionRegistry (#36204).
 *
 * In-tree ODBC PHP + optional unixODBC FFI stay compiled but must not flip
 * {@code extension_loaded('odbc')} / {@code function_exists('odbc_connect')} when
 * host Zend has no ext/odbc — same host-module gate as dba (#24134).
 *
 * Enable via host {@code extension_loaded('odbc')}, or explicit
 * {@code PHP_COMPILER_ENABLE_ODBC=1} (functional PHPT / local runs).
 */
final class OdbcExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return ExtensionRegistry::advertisesExtensionFor('odbc');
    }

    public static function advertisesBuiltins(): bool
    {
        return self::advertisesExtension();
    }

    public static function advertisesClasses(): bool
    {
        return self::advertisesExtension();
    }

    /** Compliance filenames that exercise odbc_* / extension_loaded('odbc'). */
    public static function isOdbcComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'odbc_')
            || str_contains($testFileName, 'extension_loaded_odbc')
            || str_contains($testFileName, 'maintainer_gap_odbc');
    }

    /** Phantom-registration guards that assert odbc is withheld (#23969). */
    public static function isOdbcPhantomComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'odbc_phantom')
            || str_contains($testFileName, 'extension_loaded_odbc_phantom')
            || str_contains($testFileName, 'maintainer_gap_odbc_extension_phantom');
    }

    /**
     * Functional odbc cases set {@code PHP_COMPILER_ENABLE_ODBC} via {@code --ENV--}; module
     * phantom guards run only when odbc is withheld (#23969).
     */
    public static function runsOdbcCompliance(string $testFileName): bool
    {
        if (self::isOdbcPhantomComplianceCase($testFileName)) {
            return !self::advertisesExtension();
        }

        return true;
    }

}
