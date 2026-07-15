<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

use PHPCompiler\CompilerVersion;

/**
 * ext/sqlite3 advertisement — php-src ext/sqlite3/php_sqlite3.c (#7269, #17106, #17194, #19047).
 *
 * Pure-PHP SQLite3 ({@see VmSQLite3}) via libsqlite3 FFI — withheld on reference profile until
 * {@see CompilerVersion::supportsSqlite3()}. {@see advertisesExtensionLoaded()} and
 * {@see advertisesExceptionClass()} match Zend whenever the in-tree module is loaded (#19047).
 */
final class Sqlite3ExtensionPolicy
{
    /** extension_loaded('sqlite3') — Runtime always loads ext/sqlite3 (#19047). */
    public static function advertisesExtensionLoaded(): bool
    {
        return true;
    }

    /** SQLite3 class + procedural surface — forward profile only (#3434). */
    public static function advertisesExtension(): bool
    {
        return CompilerVersion::supportsSqlite3();
    }

    /** SQLite3Exception hierarchy — whenever ext/sqlite3 module is loaded (#7269, #19047). */
    public static function advertisesExceptionClass(): bool
    {
        return self::advertisesExtensionLoaded();
    }
}
