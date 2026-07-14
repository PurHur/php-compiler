<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

use PHPCompiler\CompilerVersion;

/**
 * ext/sqlite3 advertisement — php-src ext/sqlite3/php_sqlite3.c (#7269, #17106, #17194).
 *
 * Pure-PHP SQLite3 ({@see VmSQLite3}) via libsqlite3 FFI — withheld on reference profile until
 * {@see CompilerVersion::supportsSqlite3()}. Exception hierarchy on forward profile since #7269.
 */
final class Sqlite3ExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return CompilerVersion::supportsSqlite3();
    }

    public static function advertisesExceptionClass(): bool
    {
        return self::advertisesExtension();
    }
}
