<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ldap;

use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * ldap_compare() for compiled JIT/AOT modules (#32121).
 *
 * SSOT: {@see VmLdapCore::compare}
 * php-src: ext/ldap/ldap.c — PHP_FUNCTION(ldap_compare)
 */
final class LdapResultJitHelper
{
    public static function compareArgv(int $handle, string $dn, string $attribute, string $value): Variable
    {
        $conn = self::requireConnection($handle, 'ldap_compare');
        $result = VmLdapCore::compare($conn, $dn, $attribute, $value);
        $out = new Variable();
        if (\is_bool($result)) {
            $out->bool($result);
        } else {
            $out->int($result);
        }

        return $out;
    }

    private static function requireConnection(int $handle, string $function): ObjectEntry
    {
        if (VmLdapConnection::isClosedLookupKey($handle)) {
            throw new \TypeError(
                $function.'(): supplied LDAP\\Connection is not a valid ldap link resource'
            );
        }
        $conn = VmLdapConnection::connectionForLookupKey($handle);
        if (null === $conn) {
            throw new \TypeError(
                $function.'(): Argument #1 ($ldap) must be of type LDAP\\Connection, mixed given'
            );
        }

        return $conn;
    }
}
