<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ldap;

/**
 * ext/ldap advertisement — php-src ext/ldap/php_ldap.c (#6352, #18211, #3369).
 *
 * Gate on libldap FFI so extension_loaded('ldap') matches builds that can
 * initialize a session handle (same honesty pattern as PgsqlExtensionPolicy).
 */
final class LdapExtensionPolicy
{
    public static function advertisesBuiltins(): bool
    {
        return self::advertisesExtension();
    }

    public static function advertisesExtension(): bool
    {
        return VmLdapNative::available();
    }

    public static function advertisesClasses(): bool
    {
        return self::advertisesExtension();
    }

    /**
     * ldap_connect_wallet + GSLC_SSL_* (php-src HAVE_ORALDAP; #20638).
     */
    public static function advertisesWalletConnect(): bool
    {
        return self::advertisesExtension() && VmLdapNative::walletAvailable();
    }
}
