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

    /**
     * PHP 8.3+ {@code ldap_exop_sync} / {@code ldap_exop_passwd} (#22731, re-#8688).
     *
     * Withheld on 8.4.0-dev reference / {@code PROFILE=8.2} (Zend 8.2 has neither). Enable via
     * stable 8.4.0+ or explicit {@code PHP_COMPILER_PROFILE=8.3} / {@code 8.4}.
     * php-src: ext/ldap/ldap.stub.php (absent on 8.2 stubs).
     */
    public static function advertisesPhp83ExopHelpers(): bool
    {
        if (!self::advertisesBuiltins()) {
            return false;
        }

        if (version_compare(\PHPCompiler\CompilerVersion::VERSION, '8.4.0', '>=')) {
            return true;
        }

        $raw = getenv('PHP_COMPILER_PROFILE');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        return version_compare(
            \PHPCompiler\CompilerVersion::languageProfileVersion(),
            '8.3.0',
            '>='
        );
    }
}
