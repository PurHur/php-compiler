<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ExtensionRegistry;

/**
 * ext/sqlite3 advertisement — php-src ext/sqlite3/php_sqlite3.c (#7269, #17106, #19047, #22791).
 *
 * {@see advertisesExtension()} / {@see advertisesExtensionLoaded()} are folded to
 * ext.json advertise → ExtensionRegistry (#36204). Profile-gated exception / 8.5 APIs
 * and compliance helpers stay here.
 */
final class Sqlite3ExtensionPolicy
{
    /**
     * extension_loaded('sqlite3') — match Zend without phantom sqlite3 (#22791).
     */
    public static function advertisesExtensionLoaded(): bool
    {
        return ExtensionRegistry::advertisesExtensionFor('sqlite3');
    }

    /** SQLite3 class + procedural surface (#3434 / #22791). */
    public static function advertisesExtension(): bool
    {
        return ExtensionRegistry::advertisesExtensionFor('sqlite3');
    }

    /**
     * SQLite3Stmt::busy/explain/setExplain + EXPLAIN_MODE_* / SQLite3Result::fetchAll —
     * PHP 8.5+ only (#27594; php-src sqlite3.stub.php PHP-8.5 vs PHP-8.4).
     */
    public static function advertisesPhp85Apis(): bool
    {
        if (!self::advertisesExtension()) {
            return false;
        }

        return CompilerVersion::supportsSqlite3Php85Apis();
    }

    /**
     * SQLite3Exception hierarchy — PHP 8.3+ only when the extension is advertised (#7269, #22791).
     */
    public static function advertisesExceptionClass(): bool
    {
        if (!self::advertisesExtensionLoaded()) {
            return false;
        }

        return self::advertisesSqlite3ExceptionOnProfile();
    }

    /**
     * Withhold on 8.4.0-dev reference (Zend 8.2 has no SQLite3Exception even with ext/sqlite3).
     * Enable via stable 8.4.0+ or explicit `PHP_COMPILER_PROFILE=8.3` / `8.4`.
     */
    private static function advertisesSqlite3ExceptionOnProfile(): bool
    {
        if (version_compare(CompilerVersion::VERSION, '8.3', '<')) {
            return false;
        }
        if (version_compare(CompilerVersion::VERSION, '8.4.0', '>=')) {
            return true;
        }
        $raw = getenv('PHP_COMPILER_PROFILE');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        return version_compare(CompilerVersion::languageProfileVersion(), '8.3.0', '>=');
    }

    /** Compliance filenames that exercise SQLite3 / SQLite3Exception. */
    public static function isSqlite3ComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'sqlite3')
            || str_contains($testFileName, 'SQLite3');
    }

    /** Phantom-registration guards that assert sqlite3 is withheld (#22791). */
    public static function isSqlite3PhantomComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'sqlite3_phantom')
            || str_contains($testFileName, 'extension_loaded_sqlite3_phantom')
            || str_contains($testFileName, 'sqlite3_reference_profile');
    }

    /**
     * Phantom guards run only when sqlite3 is withheld; functional / forward_profile
     * cases set {@code PHP_COMPILER_PROFILE=8.4} via {@code --ENV--} and always run (#22791).
     */
    public static function runsSqlite3Compliance(string $testFileName): bool
    {
        if (self::isSqlite3PhantomComplianceCase($testFileName)) {
            return !self::advertisesExtension();
        }

        return true;
    }
}
