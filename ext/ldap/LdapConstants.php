<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ldap;

/**
 * LDAP_ESCAPE_* flags (php-src ext/ldap/ldap.c; issue #6352).
 */
final class LdapConstants
{
    public const LDAP_ESCAPE_FILTER = 0x01;
    public const LDAP_ESCAPE_DN = 0x02;

    /** @return array<string, int> */
    public static function registeredConstants(): array
    {
        return [
            'LDAP_ESCAPE_FILTER' => self::LDAP_ESCAPE_FILTER,
            'LDAP_ESCAPE_DN' => self::LDAP_ESCAPE_DN,
        ];
    }
}
