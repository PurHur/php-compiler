<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ldap;

/**
 * ext/ldap advertisement — php-src ext/ldap/php_ldap.c (#6352, #18211, #3369, #23857).
 *
 * OpenLDAP FFI stays in-tree ({@see VmLdapNative}) but introspection must match Zend
 * module registration on the reference harness (host without php-ldap), not FFI
 * availability alone — same shape as soap/sqlite3/gmp (#22859 / #22791 / #22860).
 *
 * Enable via host {@code extension_loaded('ldap')}, or an explicit
 * {@code PHP_COMPILER_PROFILE} override plus libldap FFI (functional compliance sets
 * PROFILE via {@code --ENV--}).
 */
final class LdapExtensionPolicy
{
    public static function advertisesBuiltins(): bool
    {
        return self::advertisesExtension();
    }

    /**
     * extension_loaded('ldap') / CREDITS_MODULES — match Zend without phantom ldap (#23857).
     */
    public static function advertisesExtension(): bool
    {
        if (\extension_loaded('ldap')) {
            return true;
        }

        if (!VmLdapNative::available()) {
            return false;
        }

        // Reference profile (unset PROFILE): withhold like Zend without php-ldap (#18211 / #23857).
        $raw = getenv('PHP_COMPILER_PROFILE');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        // Explicit PROFILE (8.2 / 8.3 / 8.4) + FFI — functional / version-gate cases (#3369 / #22731).
        return true;
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

    /** Compliance filenames that exercise ldap_* / LDAP\\*. */
    public static function isLdapComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'ldap_')
            || str_contains($testFileName, '/ldap/')
            || str_contains($testFileName, 'phantom_ldap')
            || str_contains($testFileName, 'extension_loaded_ldap');
    }

    /** Phantom-registration guards that assert ldap is withheld (#18211 / #23857). */
    public static function isLdapPhantomComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'phantom_ldap')
            || (str_contains($testFileName, 'ldap_')
                && str_contains($testFileName, 'phantom')
                && !str_contains($testFileName, 'phantom_profile'));
    }

    /**
     * Functional ldap cases set {@code PHP_COMPILER_PROFILE} via {@code --ENV--} and always run
     * when selected; phantom guards run only when ldap is withheld (#23857 / #22791 shape).
     */
    public static function runsLdapCompliance(string $testFileName): bool
    {
        if (self::isLdapPhantomComplianceCase($testFileName)) {
            return !self::advertisesExtension();
        }

        return true;
    }
}
