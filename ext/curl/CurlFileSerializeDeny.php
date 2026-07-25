<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

/**
 * php-src @not-serializable curl objects:
 * - CURLFile — ext/curl/curl_file.stub.php (#23064)
 * - CurlHandle / CurlMultiHandle / CurlShareHandle — ext/curl/curl.stub.php (#23074)
 */
final class CurlFileSerializeDeny
{
    /** @var list<string> */
    private const DENIED_LC = [
        CurlFileBuiltin::CLASS_LC,
        VmCurlEasy::CLASS_LC,
        VmCurlMulti::CLASS_LC,
        VmCurlShare::CLASS_LC,
        VmCurlShare::PERSISTENT_CLASS_LC,
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
