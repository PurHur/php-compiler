<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ldap;

/**
 * ext/ldap advertisement — php-src ext/ldap/php_ldap.c (#6352, #18211, #3369, #23857, #24536).
 *
 * OpenLDAP FFI stays in-tree ({@see VmLdapNative}) but introspection must match Zend
 * module registration on the reference harness (host without php-ldap), not FFI
 * availability or {@code PHP_COMPILER_PROFILE} alone — same host-module gate as
 * dba/gnupg (#24134 / #25360). Forward profiles must not invent ldap when host Zend
 * lacks it (#24536).
 *
 * Enable via host {@code extension_loaded('ldap')}, or explicit
 * {@code PHP_COMPILER_ENABLE_LDAP=1} plus libldap FFI (functional compliance sets
 * ENABLE via {@code --ENV--}; keep PROFILE for version-gated helpers).
 */
final class LdapExtensionPolicy
{
    public static function advertisesBuiltins(): bool
    {
        return self::advertisesExtension();
    }

    /**
     * extension_loaded('ldap') / CREDITS_MODULES — match Zend without phantom ldap (#23857 / #24536).
     */
    public static function advertisesExtension(): bool
    {
        if (\extension_loaded('ldap')) {
            return true;
        }

        if (!VmLdapNative::available()) {
            return false;
        }

        return self::explicitEnableRequested();
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

    /** Phantom-registration guards that assert ldap is withheld (#18211 / #23857 / #24536). */
    public static function isLdapPhantomComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'phantom_ldap')
            || str_contains($testFileName, 'ldap_extension_loaded_phantom')
            || str_contains($testFileName, 'ldap_escape_phantom')
            || str_contains($testFileName, 'get_defined_constants_phantom_ldap')
            || str_contains($testFileName, 'maintainer_gap_ldap')
            || (str_contains($testFileName, 'ldap_')
                && str_contains($testFileName, 'phantom')
                && !str_contains($testFileName, 'phantom_profile')
                && !str_contains($testFileName, 'exop_php83_phantom'));
    }

    /**
     * Functional ldap cases set {@code PHP_COMPILER_ENABLE_LDAP} via {@code --ENV--} and always run
     * when selected; phantom guards run only when ldap is withheld (#23857 / #24536).
     */
    public static function runsLdapCompliance(string $testFileName): bool
    {
        if (self::isLdapPhantomComplianceCase($testFileName)) {
            return !self::advertisesExtension();
        }

        return true;
    }

    /** Explicit side-load / functional-test opt-in when host Zend lacks php-ldap (#24536). */
    private static function explicitEnableRequested(): bool
    {
        $raw = getenv('PHP_COMPILER_ENABLE_LDAP');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        $v = strtolower(trim($raw));

        return !\in_array($v, ['0', 'false', 'off', 'no'], true);
    }
}
