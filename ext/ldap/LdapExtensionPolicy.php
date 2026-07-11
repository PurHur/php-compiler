<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ldap;

/**
 * ext/ldap advertisement — php-src ext/ldap/php_ldap.c (#17680, #6352).
 *
 * ldap_escape() is implemented in-tree for compile coverage, but extension_loaded('ldap') and
 * function_exists('ldap_*') stay false until a full ext/ldap module ships (php-src module gate).
 */
final class LdapExtensionPolicy
{
    public static function advertisesBuiltins(): bool
    {
        return self::advertisesExtension();
    }

    public static function advertisesExtension(): bool
    {
        return false;
    }
}
