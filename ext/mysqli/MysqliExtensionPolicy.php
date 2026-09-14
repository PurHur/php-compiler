<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCompiler\ExtensionRegistry;

/**
 * ext/mysqli advertisement — php-src ext/mysqli/mysqli.c (#3435, #23954).
 *
 * {@see advertisesExtension()} is folded to ext.json advertise → ExtensionRegistry (#36204).
 *
 * In-tree mysqli PHP stays compiled but must not flip
 * {@code extension_loaded('mysqli')} / {@code function_exists('mysqli_connect')} /
 * {@code class_exists('mysqli')} when host Zend has no ext/mysqli — same
 * host-module gate as odbc (#23969).
 *
 * Enable via host {@code extension_loaded('mysqli')}, or explicit
 * {@code PHP_COMPILER_ENABLE_MYSQLI=1} (functional PHPT / local runs).
 * {@see hasNativeDriver()} still probes host {@code \\mysqli_connect} for live I/O.
 */
final class MysqliExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return ExtensionRegistry::advertisesExtensionFor('mysqli');
    }

    public static function hasNativeDriver(): bool
    {
        return \function_exists('\\mysqli_connect');
    }

    public static function advertisesClasses(): bool
    {
        return self::advertisesExtension();
    }

    public static function advertisesBuiltins(): bool
    {
        return self::advertisesExtension();
    }

    /** Compliance filenames that exercise mysqli_* / mysqli classes. */
    public static function isMysqliComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'mysqli')
            || str_contains($testFileName, 'extension_loaded_mysqli')
            || str_contains($testFileName, 'maintainer_gap_mysqli');
    }

    /** Phantom-registration guards that assert mysqli is withheld (#23954). */
    public static function isMysqliPhantomComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'mysqli_phantom')
            || str_contains($testFileName, 'extension_loaded_mysqli_phantom')
            || str_contains($testFileName, 'maintainer_gap_mysqli_extension_phantom');
    }

    /**
     * Functional mysqli cases set {@code PHP_COMPILER_ENABLE_MYSQLI} via {@code --ENV--};
     * phantom guards run only when mysqli is withheld (#23954).
     */
    public static function runsMysqliCompliance(string $testFileName): bool
    {
        if (self::isMysqliPhantomComplianceCase($testFileName)) {
            return !self::advertisesExtension();
        }

        return true;
    }

}
