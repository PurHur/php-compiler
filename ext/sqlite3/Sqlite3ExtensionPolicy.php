<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

use PHPCompiler\CompilerVersion;

/**
 * ext/sqlite3 advertisement — php-src ext/sqlite3/php_sqlite3.c (#7269, #17106, #17194).
 *
 * Reference profile withholds extension_loaded('sqlite3') and SQLite3Exception to match Zend
 * Docker without HAVE_SQLITE3. Forward profile ({@see CompilerVersion::supportsSqlite3()})
 * restores the #7269 exception hierarchy before the SQLite3 query API ships (#3434).
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
