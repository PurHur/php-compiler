<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

/**
 * php-src @not-serializable socket objects:
 * - Socket / AddressInfo — ext/sockets/sockets.stub.php (#23094)
 */
final class SocketSerializeDeny
{
    /** @var list<string> */
    private const DENIED_LC = [
        VmSocket::CLASS_LC,
        VmAddressInfo::CLASS_LC,
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
