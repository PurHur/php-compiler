<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ldap;

use PHPCompiler\VM\Context as VmContext;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\VmActiveContextJitHelper;

/**
 * Lowered into JIT/AOT modules for ldap_dn2ufn() / ldap_explode_dn() / ldap_connect_wallet()
 * (#22212, #31984).
 *
 * SSOT: {@see VmLdapDn} / {@see LdapDnJitHelper::connect}
 * php-src: ext/ldap/ldap.c
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

    /**
     * ldap_connect_wallet() SSOT (php-src HAVE_ORALDAP; #20638 / #31984).
     */
    public static function connect(
        ?string $uri,
        string $wallet,
        string $password,
        int $authMode,
        ?VmContext $ctx
    ): Variable {
        $out = new Variable();
        if (!VmLdapNative::walletAvailable()) {
            @\trigger_error(
                'ldap_connect_wallet(): Oracle wallet LDAP support is not available in this build',
                \E_USER_WARNING
            );
            $out->bool(false);

            return $out;
        }
        if (null === $ctx) {
            throw new \LogicException('ldap_connect_wallet() requires a VM context');
        }
        $url = $uri ?? 'ldap://localhost';
        if (!str_contains($url, '://')) {
            $url = 'ldap://'.$url;
        }
        $native = VmLdapNative::initializeWallet($url, $wallet, $password, $authMode);
        if (null === $native) {
            @\trigger_error('ldap_connect_wallet(): Could not create session handle', \E_USER_WARNING);
            $out->bool(false);

            return $out;
        }

        return VmLdapConnection::wrap($native, $ctx);
    }

    public static function connectWallet(?string $uri, string $wallet, string $password, int $authMode): Variable
    {
        return self::connect(
            $uri,
            $wallet,
            $password,
            $authMode,
            VmActiveContextJitHelper::resolve()
        );
    }
}
