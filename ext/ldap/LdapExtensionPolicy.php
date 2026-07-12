<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ldap;

/**
 * ext/ldap advertisement — php-src ext/ldap/php_ldap.c (#17680, #6352, #18173).
 *
 * Phase 1 ships {@see ldap_escape} in-tree ({@see VmLdapEscape}); {@see advertisesBuiltins()} is
 * true so function_exists() matches Zend when the escape builtin is available. Full
 * extension_loaded('ldap') / connect/bind/search remain gated until #3369.
 */
final class LdapExtensionPolicy
{
    public static function advertisesBuiltins(): bool
    {
        return true;
    }

    public static function advertisesExtension(): bool
    {
        return false;
    }
}
