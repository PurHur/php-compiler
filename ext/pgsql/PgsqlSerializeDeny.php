<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pgsql;

/**
 * php-src @not-serializable PgSql opaque handles (#23135):
 * - PgSql\Connection / Result / Lob — ext/pgsql/pgsql.stub.php
 */
final class PgsqlSerializeDeny
{
    /** @var list<string> */
    private const DENIED_LC = [
        VmPgsqlConnection::CLASS_LC,
        VmPgsqlResult::CLASS_LC,
        VmPgsqlLob::CLASS_LC,
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
