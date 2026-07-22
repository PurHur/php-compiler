<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ldap;

use PHPCompiler\VM\Variable;

/**
 * Lowered into JIT/AOT modules for ldap_dn2ufn() / ldap_explode_dn() (#22212).
 *
 * SSOT: {@see VmLdapDn}
 * php-src: ext/ldap/ldap.c — PHP_FUNCTION(ldap_dn2ufn) / PHP_FUNCTION(ldap_explode_dn)
 */
final class LdapDnJitHelper
{
    public static function dn2ufn(string $dn): Variable
    {
        return VmLdapDn::dn2ufnResultToVariable(VmLdapDn::dn2ufn($dn));
    }

    public static function explodeDn(string $dn, int $withAttrib): Variable
    {
        return VmLdapDn::explodeResultToVariable(VmLdapDn::explodeDn($dn, $withAttrib));
    }
}
