<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pdo;

/**
 * php-src @not-serializable PDO opaque handles:
 * - PDO — ext/pdo/pdo_dbh.stub.php (#23103)
 * - PDOStatement / PDORow — ext/pdo/pdo_stmt.stub.php (#23103)
 */
final class PdoSerializeDeny
{
    /** @var list<string> */
    private const DENIED_LC = [
        VmPDO::CLASS_LC,
        VmPDOStatement::CLASS_LC,
        VmPDORow::CLASS_LC,
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
