<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ldap;

/**
 * ext/ldap advertisement — php-src ext/ldap/php_ldap.c (#6352, #18173, #18211).
 *
 * ldap_escape() compiles in-tree but is withheld from extension_loaded(),
 * function_exists(), and get_defined_constants() module buckets until Zend ships
 * ext/ldap on the host (php-src-strict parity on reference profile).
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
