<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

/**
 * ext/sqlite3 advertisement — php-src ext/sqlite3/php_sqlite3.c (#7269, #17106).
 *
 * SQLite3Exception and extension_loaded('sqlite3') stay false until the query API ships (#3434)
 * and libsqlite is linked (HAVE_SQLITE3). Matches Zend reference Docker without ext/sqlite3.
 */
final class Sqlite3ExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return false;
    }

    public static function advertisesExceptionClass(): bool
    {
        return self::advertisesExtension();
    }
}
