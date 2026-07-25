<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

/**
 * php-src @not-serializable openssl opaque objects:
 * - OpenSSLAsymmetricKey / OpenSSLCertificate / OpenSSLCertificateSigningRequest
 *   — ext/openssl/openssl.stub.php (#23100)
 */
final class OpensslSerializeDeny
{
    /** @var list<string> */
    private const DENIED_LC = [
        VmOpensslObjects::KEY_LC,
        VmOpensslObjects::CERT_LC,
        VmOpensslObjects::CSR_LC,
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
