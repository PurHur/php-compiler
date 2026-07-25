<?php

declare(strict_types=1);

namespace PHPCompiler\ext\fileinfo;

/**
 * php-src @not-serializable finfo — ext/fileinfo/fileinfo.stub.php (#23093).
 */
final class FinfoSerializeDeny
{
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
        return VmFinfo::CLASS_LC === strtolower(ltrim($className, '\\'));
    }

    private static function displayName(string $className): string
    {
        return ltrim($className, '\\');
    }
}
