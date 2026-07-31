<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

/**
 * ext/mysqli advertisement — php-src ext/mysqli/mysqli.c (#3435, #23954).
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
        if (\extension_loaded('mysqli')) {
            return true;
        }

        return self::explicitEnableRequested();
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

    /** Explicit side-load / functional-test opt-in when host Zend lacks ext/mysqli (#23954). */
    private static function explicitEnableRequested(): bool
    {
        $raw = getenv('PHP_COMPILER_ENABLE_MYSQLI');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        $v = strtolower(trim($raw));

        return !\in_array($v, ['0', 'false', 'off', 'no'], true);
    }
}
