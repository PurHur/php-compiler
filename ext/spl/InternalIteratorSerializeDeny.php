<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

/**
 * php-src @not-serializable InternalIterator (#23167):
 * - InternalIterator — Zend/zend_interfaces.stub.php
 */
final class InternalIteratorSerializeDeny
{
    /** @var list<string> */
    private const DENIED_LC = [
        InternalIteratorBuiltin::CLASS_LC,
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
