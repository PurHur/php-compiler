<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ldap;

use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\VmActiveContextJitHelper;

/**
 * ldap_bind() / ldap_bind_ext() / ldap_unbind() / ldap_close() / ldap_set_option() /
 * ldap_get_option() / ldap_start_tls() for compiled JIT/AOT modules
 * (#32001, #32002, #32107, #32109, #32146).
 *
 * SSOT: {@see VmLdapCore::bind} / {@see VmLdapCore::bindExt} / {@see VmLdapConnection::close} /
 * {@see VmLdapNative::startTlsSync}
 * php-src: ext/ldap/ldap.c — PHP_FUNCTION(ldap_bind) / ldap_bind_ext / ldap_set_option / ldap_start_tls
 */
final class LdapLinkJitHelper
{
    public static function registerHandleArgv(int $handle): void
    {
        VmLdapConnection::claimPendingJitHandle($handle);
    }

    public static function bindArgv(int $handle, ?string $dn, ?string $password, int $hasDn, int $hasPassword): bool
    {
        $conn = self::requireConnection($handle, 'ldap_bind');

        return VmLdapCore::bind(
            $conn,
            1 === $hasDn ? $dn : null,
            1 === $hasPassword ? $password : null
        );
    }

    /**
     * ldap_bind_ext() — async simple bind → LDAP\Result|false (php-src ext/ldap/ldap.c; #32146).
     *
     * $controls is accepted by the builtin and ignored in v1 (same as VM execute()).
     */
    public static function bindExtArgv(int $handle, ?string $dn, ?string $password, int $hasDn, int $hasPassword): Variable
    {
        $conn = self::requireConnection($handle, 'ldap_bind_ext');
        $dnArg = 1 === $hasDn ? $dn : null;
        $passwordArg = 1 === $hasPassword ? $password : null;
        if (null !== $dnArg && str_contains($dnArg, "\0")) {
            throw new \TypeError('ldap_bind_ext(): Argument #2 ($dn) must not contain null bytes');
        }
        if (null !== $passwordArg && str_contains($passwordArg, "\0")) {
            throw new \TypeError('ldap_bind_ext(): Argument #3 ($password) must not contain null bytes');
        }
        $result = VmLdapCore::bindExt($conn, $dnArg, $passwordArg, VmActiveContextJitHelper::resolve());
        if (false === $result) {
            $out = new Variable();
            $out->bool(false);

            return $out;
        }

        return $result;
    }

    public static function unbindArgv(int $handle): bool
    {
        $conn = self::requireConnection($handle, 'ldap_unbind');

        return VmLdapConnection::close($conn);
    }

    public static function errnoArgv(int $handle): int
    {
        $conn = self::requireConnection($handle, 'ldap_errno');

        return VmLdapConnection::errno($conn);
    }

    public static function errorArgv(int $handle): string
    {
        $conn = self::requireConnection($handle, 'ldap_error');

        return VmLdapNative::err2string(VmLdapConnection::errno($conn));
    }

    public static function err2strArgv(int $errno): string
    {
        return VmLdapNative::err2string($errno);
    }

    /**
     * ldap_set_option() int/bool subset (php-src ext/ldap/ldap.c; #32107).
     *
     * $hasConn=0 means a null LDAP handle (session-wide options). $valueKind=0
     * matches VM "Type not supported for this option".
     */
    public static function setOptionIntArgv(int $handle, int $hasConn, int $option, int $value, int $valueKind): bool
    {
        $connObj = null;
        $ld = null;
        if (1 === $hasConn) {
            $connObj = self::requireConnection($handle, 'ldap_set_option');
            $ld = VmLdapConnection::native($connObj);
        }
        if (1 !== $valueKind) {
            @\trigger_error('ldap_set_option(): Type not supported for this option', \E_USER_WARNING);

            return false;
        }
        $rc = VmLdapNative::setOptionInt($ld, $option, $value);
        $ok = VmLdapNative::LDAP_SUCCESS === $rc;
        if (null !== $connObj && !$ok) {
            VmLdapConnection::setErrno($connObj, $rc);
        }

        return $ok;
    }

    /**
     * ldap_get_option() int path — stash value for {@see getOptionValueArgv} (#32107).
     *
     * @return int 1 on success, 0 on failure
     */
    public static function getOptionIntOkArgv(int $handle, int $hasConn, int $option): int
    {
        self::$lastGetOptionValue = 0;
        $connObj = null;
        $ld = null;
        if (1 === $hasConn) {
            $connObj = self::requireConnection($handle, 'ldap_get_option');
            $ld = VmLdapConnection::native($connObj);
        }
        $got = VmLdapNative::getOptionInt($ld, $option);
        if (!$got['ok']) {
            if (null !== $connObj) {
                VmLdapConnection::setErrno($connObj, VmLdapNative::LDAP_OPT_ERROR_NUMBER);
            }

            return 0;
        }
        if (null !== $connObj) {
            VmLdapConnection::setErrno($connObj, VmLdapNative::LDAP_SUCCESS);
        }
        self::$lastGetOptionValue = $got['value'];

        return 1;
    }

    public static function getOptionValueArgv(): int
    {
        return self::$lastGetOptionValue;
    }

    /**
     * ldap_start_tls() — STARTTLS on a live link (php-src ext/ldap/ldap.c; #32109).
     */
    public static function startTlsArgv(int $handle): bool
    {
        $conn = self::requireConnection($handle, 'ldap_start_tls');
        $ld = VmLdapConnection::native($conn);
        $rc = VmLdapNative::startTlsSync($ld);
        VmLdapConnection::setErrno($conn, $rc);
        if (VmLdapNative::LDAP_SUCCESS !== $rc) {
            @\trigger_error(
                'ldap_start_tls(): Unable to start TLS: '.VmLdapNative::err2string($rc),
                \E_USER_WARNING
            );

            return false;
        }

        return true;
    }

    private static int $lastGetOptionValue = 0;

    private static function requireConnection(int $handle, string $function): ObjectEntry
    {
        if (VmLdapConnection::isClosedLookupKey($handle)) {
            throw new \TypeError(
                $function.'(): supplied LDAP\\Connection is not a valid ldap link resource'
            );
        }
        $conn = VmLdapConnection::connectionForLookupKey($handle);
        if (null === $conn) {
            throw new \TypeError(
                $function.'(): Argument #1 ($ldap) must be of type LDAP\\Connection, mixed given'
            );
        }

        return $conn;
    }
}
