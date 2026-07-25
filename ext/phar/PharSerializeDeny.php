<?php

declare(strict_types=1);

namespace PHPCompiler\ext\phar;

/**
 * php-src @not-serializable Phar archive / entry objects (#23154):
 * - Phar / PharData — ext/phar/phar.stub.php
 * - PharFileInfo — ext/phar/phar.stub.php
 */
final class PharSerializeDeny
{
    /** @var list<string> */
    private const DENIED_LC = [
        VmPhar::CLASS_LC,
        VmPharData::CLASS_LC,
        VmPharFileInfo::CLASS_LC,
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
