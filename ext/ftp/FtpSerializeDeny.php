<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ftp;

/**
 * php-src @not-serializable FTP opaque handle:
 * - FTP\Connection — ext/ftp/ftp.stub.php (#23134)
 */
final class FtpSerializeDeny
{
    /** @var list<string> */
    private const DENIED_LC = [
        VmFtpConnection::CLASS_LC,
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
