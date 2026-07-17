<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pdo;

/**
 * ext/pdo advertisement — php-src ext/pdo/pdo.c (#3367).
 *
 * PDO + PDOException are always advertised (Zend ships ext/pdo). The sqlite driver
 * surface needs libsqlite3 FFI ({@see \PHPCompiler\ext\sqlite3\VmSqlite3Native}).
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
}
