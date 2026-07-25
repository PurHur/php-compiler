<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xml;

/**
 * php-src @not-serializable XMLParser — ext/xml/xml.stub.php (#23111).
 */
final class XmlParserSerializeDeny
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
        return XmlParserSupport::CLASS_LC === strtolower(ltrim($className, '\\'));
    }

    private static function displayName(string $className): string
    {
        return ltrim($className, '\\');
    }
}
