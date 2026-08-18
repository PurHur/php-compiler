<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ldap;

use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * ldap_compare() / ldap_count_entries() for compiled JIT/AOT modules (#32121, #32172).
 *
 * SSOT: {@see VmLdapCore::compare} / {@see VmLdapNative::countEntries}
 * php-src: ext/ldap/ldap.c — PHP_FUNCTION(ldap_compare) / ldap_count_entries
 */
final class LdapResultJitHelper
{
    public static function registerHandleArgv(int $handle): void
    {
        VmLdapResult::claimPendingJitHandle($handle);
    }

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

    public static function countEntriesArgv(int $connHandle, int $resultHandle): int
    {
        $conn = self::requireConnection($connHandle, 'ldap_count_entries');
        $result = self::requireResult($resultHandle, 'ldap_count_entries');

        return VmLdapNative::countEntries(
            VmLdapConnection::native($conn),
            VmLdapResult::resultNative($result)
        );
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

    private static function requireResult(int $handle, string $function): ObjectEntry
    {
        if (VmLdapResult::isFreedLookupKey($handle)) {
            throw new \TypeError(
                $function.'(): supplied LDAP\\Result is not a valid ldap result resource'
            );
        }
        $result = VmLdapResult::resultForLookupKey($handle);
        if (null === $result) {
            throw new \TypeError(
                $function.'(): Argument #2 ($result) must be of type LDAP\\Result, mixed given'
            );
        }

        return $result;
    }
}
