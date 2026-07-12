<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ldap;

/**
 * Lowered into JIT/AOT modules for ldap_escape() runtime (#6352, #18173, php-in-PHP).
 *
 * php-src: ext/ldap/ldap.c — php_ldap_do_escape
 * SSOT: {@see VmLdapEscape}
 */
final class LdapEscapeJitHelper
{
    public static function ldapEscape(string $value, string $ignore, int $flags): string
    {
        return VmLdapEscape::escape($value, $ignore, $flags);
    }
}
