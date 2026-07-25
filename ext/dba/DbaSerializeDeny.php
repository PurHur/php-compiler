<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dba;

/**
 * php-src @not-serializable Dba\Connection (#23113):
 * - Dba\Connection — ext/dba/dba.stub.php
 */
final class DbaSerializeDeny
{
    /** @var list<string> */
    private const DENIED_LC = [
        VmDbaConnection::CLASS_LC,
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
