<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ldap;

/**
 * ext/ldap advertisement — php-src ext/ldap/php_ldap.c (#6352, #18173).
 *
 * ldap_escape() ships on the reference profile; connect/bind/search remain #3369.
 */
final class LdapExtensionPolicy
{
    public static function advertisesBuiltins(): bool
    {
        return self::advertisesExtension();
    }

    public static function advertisesExtension(): bool
    {
        return true;
    }
}
