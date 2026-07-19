<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ldap;

/**
 * LDAP_* flags/options (php-src ext/ldap/ldap.c; #6352 / #3369).
 */
final class LdapConstants
{
    public const LDAP_ESCAPE_FILTER = 0x01;
    public const LDAP_ESCAPE_DN = 0x02;

    public const LDAP_OPT_DEREF = 0x0002;
    public const LDAP_OPT_SIZELIMIT = 0x0003;
    public const LDAP_OPT_TIMELIMIT = 0x0004;
    public const LDAP_OPT_REFERRALS = 0x0008;
    public const LDAP_OPT_PROTOCOL_VERSION = 0x0011;
    public const LDAP_OPT_ERROR_NUMBER = 0x0031;
    public const LDAP_OPT_ERROR_STRING = 0x0032;

    public const LDAP_DEREF_NEVER = 0x00;
    public const LDAP_DEREF_SEARCHING = 0x01;
    public const LDAP_DEREF_FINDING = 0x02;
    public const LDAP_DEREF_ALWAYS = 0x03;

    public const LDAP_SCOPE_BASE = 0x0000;
    public const LDAP_SCOPE_ONELEVEL = 0x0001;
    public const LDAP_SCOPE_SUBTREE = 0x0002;

    public const LDAP_EXOP_START_TLS = '1.3.6.1.4.1.1466.20037';
    public const LDAP_EXOP_MODIFY_PASSWD = '1.3.6.1.4.1.4203.1.11.1';
    public const LDAP_EXOP_REFRESH = '1.3.6.1.4.1.1466.101.119.1';
    public const LDAP_EXOP_WHO_AM_I = '1.3.6.1.4.1.4203.1.11.3';
    public const LDAP_EXOP_TURN = '1.3.6.1.1.19';

    /** Oracle wallet SSL auth modes (php-src HAVE_ORALDAP; #20638). */
    public const GSLC_SSL_NO_AUTH = 1;
    public const GSLC_SSL_ONEWAY_AUTH = 2;
    public const GSLC_SSL_TWOWAY_AUTH = 3;

    /** @return array<string, int|string> */
    public static function registeredConstants(): array
    {
        $constants = [
            'LDAP_ESCAPE_FILTER' => self::LDAP_ESCAPE_FILTER,
            'LDAP_ESCAPE_DN' => self::LDAP_ESCAPE_DN,
            'LDAP_OPT_DEREF' => self::LDAP_OPT_DEREF,
            'LDAP_OPT_SIZELIMIT' => self::LDAP_OPT_SIZELIMIT,
            'LDAP_OPT_TIMELIMIT' => self::LDAP_OPT_TIMELIMIT,
            'LDAP_OPT_REFERRALS' => self::LDAP_OPT_REFERRALS,
            'LDAP_OPT_PROTOCOL_VERSION' => self::LDAP_OPT_PROTOCOL_VERSION,
            'LDAP_OPT_ERROR_NUMBER' => self::LDAP_OPT_ERROR_NUMBER,
            'LDAP_OPT_ERROR_STRING' => self::LDAP_OPT_ERROR_STRING,
            'LDAP_DEREF_NEVER' => self::LDAP_DEREF_NEVER,
            'LDAP_DEREF_SEARCHING' => self::LDAP_DEREF_SEARCHING,
            'LDAP_DEREF_FINDING' => self::LDAP_DEREF_FINDING,
            'LDAP_DEREF_ALWAYS' => self::LDAP_DEREF_ALWAYS,
            'LDAP_SCOPE_BASE' => self::LDAP_SCOPE_BASE,
            'LDAP_SCOPE_ONELEVEL' => self::LDAP_SCOPE_ONELEVEL,
            'LDAP_SCOPE_SUBTREE' => self::LDAP_SCOPE_SUBTREE,
            'LDAP_EXOP_START_TLS' => self::LDAP_EXOP_START_TLS,
            'LDAP_EXOP_MODIFY_PASSWD' => self::LDAP_EXOP_MODIFY_PASSWD,
            'LDAP_EXOP_REFRESH' => self::LDAP_EXOP_REFRESH,
            'LDAP_EXOP_WHO_AM_I' => self::LDAP_EXOP_WHO_AM_I,
            'LDAP_EXOP_TURN' => self::LDAP_EXOP_TURN,
        ];
        if (LdapExtensionPolicy::advertisesWalletConnect()) {
            $constants['GSLC_SSL_NO_AUTH'] = self::GSLC_SSL_NO_AUTH;
            $constants['GSLC_SSL_ONEWAY_AUTH'] = self::GSLC_SSL_ONEWAY_AUTH;
            $constants['GSLC_SSL_TWOWAY_AUTH'] = self::GSLC_SSL_TWOWAY_AUTH;
        }

        return $constants;
    }
}
