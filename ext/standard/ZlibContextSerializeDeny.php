<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * php-src @not-serializable zlib incremental contexts:
 * - DeflateContext / InflateContext — ext/zlib/zlib.stub.php (#23101)
 */
final class ZlibContextSerializeDeny
{
    /** @var list<string> */
    private const DENIED_LC = [
        VmZlibContext::DEFLATE_CLASS_LC,
        VmZlibContext::INFLATE_CLASS_LC,
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
