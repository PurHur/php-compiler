<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ldap;

/**
 * php-src @not-serializable LDAP opaque handles (#23169):
 * - LDAP\Connection / Result / ResultEntry — ext/ldap/ldap.stub.php
 */
final class LdapSerializeDeny
{
    /** @var list<string> */
    private const DENIED_LC = [
        VmLdapConnection::CLASS_LC,
        VmLdapResult::RESULT_CLASS_LC,
        VmLdapResult::ENTRY_CLASS_LC,
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
