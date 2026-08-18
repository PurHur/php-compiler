<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ldap;

use PHPCompiler\VM\ObjectEntry;

/**
 * ldap_bind() / ldap_unbind() / ldap_close() for compiled JIT/AOT modules (#32001, #32002).
 *
 * SSOT: {@see VmLdapCore::bind} / {@see VmLdapConnection::close}
 * php-src: ext/ldap/ldap.c — PHP_FUNCTION(ldap_bind) / ldap_unbind (ldap_close alias)
 */
final class LdapLinkJitHelper
{
    public static function registerHandleArgv(int $handle): void
    {
        VmLdapConnection::claimPendingJitHandle($handle);
    }

    public static function bindArgv(int $handle, ?string $dn, ?string $password, int $hasDn, int $hasPassword): bool
    {
        $conn = self::requireConnection($handle, 'ldap_bind');

        return VmLdapCore::bind(
            $conn,
            1 === $hasDn ? $dn : null,
            1 === $hasPassword ? $password : null
        );
    }

    public static function unbindArgv(int $handle): bool
    {
        $conn = self::requireConnection($handle, 'ldap_unbind');

        return VmLdapConnection::close($conn);
    }

    private static function requireConnection(int $handle, string $function): ObjectEntry
    {
        if (VmLdapConnection::isClosedLookupKey($handle)) {
            throw new \TypeError(\sprintf(
                '%s(): supplied LDAP\\Connection is not a valid ldap link resource',
                $function
            ));
        }
        $conn = VmLdapConnection::connectionForLookupKey($handle);
        if (null === $conn) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #1 ($ldap) must be of type LDAP\\Connection, mixed given',
                $function
            ));
        }

        return $conn;
    }
}
