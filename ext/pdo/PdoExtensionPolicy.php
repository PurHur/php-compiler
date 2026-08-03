<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pdo;

use PHPCompiler\CompilerVersion;

/**
 * ext/pdo advertisement — php-src ext/pdo/pdo.c (#3367, #24523).
 *
 * PDO + PDOException are always advertised (Zend ships ext/pdo). The sqlite driver
 * surface needs libsqlite3 FFI ({@see \PHPCompiler\ext\sqlite3\VmSqlite3Native}) and
 * must not flip {@code extension_loaded('pdo_sqlite')} / {@code PDO::getAvailableDrivers()}
 * when host Zend has no pdo_sqlite — same host-module gate as dba (#24134).
 *
 * Enable via host {@code extension_loaded('pdo_sqlite')}, or explicit
 * {@code PHP_COMPILER_ENABLE_PDO_SQLITE=1} (functional PHPT / local runs).
 *
 * PHP 8.4 {@see PDO::connect()} and driver subclasses ({@see Pdo\Sqlite}, {@see Pdo\Mysql},
 * {@see Pdo\Pgsql}) are advertised on language profile ≥ 8.4 (#20548, #22600, #22790).
 * {@see Pdo\Sqlite} additionally requires the sqlite driver gate ({@see advertisesSqliteSubclass()}).
 *
 * Logical {@code pdo_pgsql} follows the host {@code extension_loaded('pdo_pgsql')} gate
 * (sqlite-style; #26140) — not the PHP 8.4 {@see Pdo\Pgsql} subclass profile alone —
 * so reference builds match Zend driver advertisement / {@code PDO::getAvailableDrivers()}.
 * Enable via host module or {@code PHP_COMPILER_ENABLE_PDO_PGSQL=1} when libpq FFI is available.
 * Live COPY / LISTEN-NOTIFY / backend PID still need a live libpq handle (#3741).
 */
final class PdoExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return true;
    }

    public static function advertisesExceptionClass(): bool
    {
        return self::advertisesExtension();
    }

    /**
     * Logical pdo_sqlite driver name for extension_loaded()/getAvailableDrivers() (#24523).
     *
     * Host Zend without pdo_sqlite must stay false even when libsqlite3 FFI is present.
     */
    public static function advertisesSqliteDriver(): bool
    {
        if (!self::advertisesExtension()) {
            return false;
        }
        if (!\PHPCompiler\ext\sqlite3\VmSqlite3Native::available()) {
            return false;
        }
        if (\extension_loaded('pdo_sqlite')) {
            return true;
        }

        return self::explicitSqliteEnableRequested();
    }

    /**
     * Logical pdo_pgsql for extension_loaded() / getAvailableDrivers() (#26140).
     *
     * Host Zend without pdo_pgsql must stay false even on PROFILE≥8.4 (subclass API
     * remains separately gated). Mirror {@see advertisesSqliteDriver()}.
     */
    public static function advertisesPgsqlDriver(): bool
    {
        if (!self::advertisesExtension()) {
            return false;
        }
        if (\extension_loaded('pdo_pgsql')) {
            return true;
        }

        if (!self::explicitPgsqlEnableRequested()) {
            return false;
        }

        return \PHPCompiler\ext\pgsql\VmPgsqlNative::available();
    }

    /**
     * PHP 8.4+ Pdo\Mysql / Pdo\Pgsql subclass API (pdo_mysql.stub.php / pdo_pgsql.stub.php).
     *
     * Not gated on native client libs yet — class/constants/methods exist for reflection
     * and instanceof; live connections remain a follow-on (#3435 / #3741).
     */
    public static function advertisesDriverSpecificSubclasses(): bool
    {
        return self::advertisesExtension()
            && version_compare(CompilerVersion::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ PDO::connect() factory (pdo_dbh.stub.php; #20529, #22600).
     *
     * Same profile gate as {@see CompilerVersion::supportsPdoConnect()}.
     */
    public static function advertisesConnect(): bool
    {
        return self::advertisesExtension() && CompilerVersion::supportsPdoConnect();
    }

    /**
     * PHP 8.4+ {@see Pdo\Sqlite} subclass (pdo_sqlite.stub.php; #20529 / #22790).
     *
     * Requires both the sqlite driver (libsqlite3 + host/ENABLE gate) and the
     * driver-specific subclass profile gate. Legacy {@see PDO::sqliteCreateFunction}
     * etc. stay on PDO below 8.4.
     */
    public static function advertisesSqliteSubclass(): bool
    {
        return self::advertisesSqliteDriver()
            && self::advertisesDriverSpecificSubclasses();
    }

    public static function advertisesMysqlSubclass(): bool
    {
        return self::advertisesDriverSpecificSubclasses();
    }

    public static function advertisesPgsqlSubclass(): bool
    {
        return self::advertisesDriverSpecificSubclasses();
    }

    /** Compliance filenames that exercise pdo_sqlite / sqlite DSN / PDO::sqlite* (#24523). */
    public static function isPdoSqliteComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'pdo_sqlite')
            || str_contains($testFileName, 'pdo_drivers')
            || str_contains($testFileName, 'pdo_connect_sqlite')
            || str_contains($testFileName, 'pdo_connect_forward')
            || str_contains($testFileName, 'pdo_attr_emulate_prepares_sqlite')
            || str_contains($testFileName, 'extension_loaded_pdo_sqlite')
            || str_contains($testFileName, 'maintainer_gap_pdo_sqlite');
    }

    /** Phantom-registration guards that assert pdo_sqlite is withheld (#24523). */
    public static function isPdoSqlitePhantomComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'pdo_sqlite_phantom')
            || str_contains($testFileName, 'extension_loaded_pdo_sqlite_phantom')
            || str_contains($testFileName, 'maintainer_gap_pdo_sqlite_extension_phantom');
    }

    /**
     * Functional pdo_sqlite cases set {@code PHP_COMPILER_ENABLE_PDO_SQLITE} via {@code --ENV--};
     * module phantom guards run only when the driver is withheld (#24523).
     */
    public static function runsPdoSqliteCompliance(string $testFileName): bool
    {
        if (self::isPdoSqlitePhantomComplianceCase($testFileName)) {
            return !self::advertisesSqliteDriver();
        }

        return true;
    }

    /** Explicit side-load / functional-test opt-in when host Zend lacks pdo_sqlite (#24523). */
    private static function explicitSqliteEnableRequested(): bool
    {
        $raw = getenv('PHP_COMPILER_ENABLE_PDO_SQLITE');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        $v = strtolower(trim($raw));

        return !\in_array($v, ['0', 'false', 'off', 'no'], true);
    }

    /** Explicit side-load / functional-test opt-in when host Zend lacks pdo_pgsql (#26140). */
    private static function explicitPgsqlEnableRequested(): bool
    {
        $raw = getenv('PHP_COMPILER_ENABLE_PDO_PGSQL');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        $v = strtolower(trim($raw));

        return !\in_array($v, ['0', 'false', 'off', 'no'], true);
    }
}
