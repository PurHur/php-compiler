<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

/**
 * php-src ext/curl/curl_file.stub.php — @not-serializable on CURLFile (#23064).
 */
final class CurlFileSerializeDeny
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
        return CurlFileBuiltin::CLASS_LC === strtolower(ltrim($className, '\\'));
    }

    private static function displayName(string $className): string
    {
        return ltrim($className, '\\');
    }
}
