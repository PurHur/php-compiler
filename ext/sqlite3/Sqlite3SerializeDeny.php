<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

/**
 * php-src @not-serializable SQLite3 opaque handles (#23137):
 * - SQLite3 / SQLite3Stmt / SQLite3Result — ext/sqlite3/sqlite3.stub.php
 */
final class Sqlite3SerializeDeny
{
    /** @var list<string> */
    private const DENIED_LC = [
        VmSQLite3::CLASS_LC,
        VmSQLite3Stmt::CLASS_LC,
        VmSQLite3Result::CLASS_LC,
    ];

    public static function rejectSerialization(string $className): void
    {
        if (self::isDenied($className)) {
            throw new \Exception("Serialization of '".self::displayName($className)."' is not allowed");
        }
    }

    public static function rejectUnserialization(string $className): void
    {
        if (self::isDenied($className)) {
            throw new \Exception("Unserialization of '".self::displayName($className)."' is not allowed");
        }
    }

    private static function isDenied(string $className): bool
    {
        return \in_array(strtolower(ltrim($className, '\\')), self::DENIED_LC, true);
    }

    private static function displayName(string $className): string
    {
        return ltrim($className, '\\');
    }
}
