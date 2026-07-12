<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

/**
 * php-src ext/spl/spl_directory.c — zend_class_serialize_deny on file/directory iterators.
 */
final class SplFileIteratorSerializeDeny
{
    /** @var list<string> */
    private const DENIED_LC = [
        SplFileInfoBuiltin::CLASS_LC,
        SplFileObjectBuiltin::CLASS_LC,
        SplTempFileObjectBuiltin::CLASS_LC,
        DirectoryIteratorBuiltin::CLASS_LC,
        FilesystemIteratorBuiltin::CLASS_LC,
        RecursiveDirectoryIteratorBuiltin::CLASS_LC,
        GlobIteratorBuiltin::CLASS_LC,
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
