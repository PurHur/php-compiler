<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pdo;

use PHPCompiler\CompilerVersion;

/**
 * ext/pdo advertisement — php-src ext/pdo/pdo.c (#3367).
 *
 * PDO + PDOException are always advertised (Zend ships ext/pdo). The sqlite driver
 * surface needs libsqlite3 FFI ({@see \PHPCompiler\ext\sqlite3\VmSqlite3Native}).
 *
 * PHP 8.4 {@see PDO::connect()} and driver subclasses ({@see Pdo\Sqlite}, {@see Pdo\Mysql},
 * {@see Pdo\Pgsql}) are advertised on language profile ≥ 8.4 (#20548, #22600, #22790).
 * They are not listed in getAvailableDrivers() until a real connection factory exists
 * (sqlite-style lib gate for mysql/pgsql); PDO::connect('mysql:…'/'pgsql:…') therefore
 * throws "could not find driver" like Zend when the driver module is absent.
 * {@see Pdo\Sqlite} additionally requires libsqlite3 ({@see advertisesSqliteSubclass()}).
 *
 * Logical {@code pdo_pgsql} follows the subclass advertise gate so
 * {@code extension_loaded('pdo_pgsql')} matches builds that ship the Pdo\Pgsql API
 * (#20566). Live COPY / LISTEN-NOTIFY / backend PID need libpq (#3741).
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

    /** Logical pdo_sqlite driver name for extension_loaded()/getAvailableDrivers(). */
    public static function advertisesSqliteDriver(): bool
    {
        return self::advertisesExtension()
            && \PHPCompiler\ext\sqlite3\VmSqlite3Native::available();
    }

    /**
     * Logical pdo_pgsql for extension_loaded() (#20566).
     *
     * Same gate as {@see advertisesPgsqlSubclass()} — not listed in
     * getAvailableDrivers() until a libpq connection factory exists (#3741).
     */
    public static function advertisesPgsqlDriver(): bool
    {
        return self::advertisesPgsqlSubclass();
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
     * Requires both the sqlite driver (libsqlite3) and the driver-specific subclass
     * profile gate. Legacy {@see PDO::sqliteCreateFunction} etc. stay on PDO below 8.4.
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
}
