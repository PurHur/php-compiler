<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ldap;

/**
 * OpenLDAP libldap FFI bridge (php-src ext/ldap/ldap.c; #3369).
 *
 * No runtime/*.c growth — connection/search logic stays in PHP; C is thin ABI only.
 */
final class VmLdapNative
{
    public const LDAP_SUCCESS = 0;

    public const LDAP_OPT_DEREF = 0x0002;

    public const LDAP_OPT_SIZELIMIT = 0x0003;

    public const LDAP_OPT_TIMELIMIT = 0x0004;

    public const LDAP_OPT_REFERRALS = 0x0008;

    public const LDAP_OPT_PROTOCOL_VERSION = 0x0011;

    public const LDAP_OPT_ERROR_NUMBER = 0x0031;

    public const LDAP_OPT_ERROR_STRING = 0x0032;

    public const LDAP_SCOPE_BASE = 0x0000;

    public const LDAP_SCOPE_ONELEVEL = 0x0001;

    public const LDAP_SCOPE_SUBTREE = 0x0002;

    public const LDAP_DEREF_NEVER = 0x00;

    /** @var \FFI|null */
    private static $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    /**
     * @return \FFI\CData|null LDAP*
     */
    public static function initialize(string $uri): ?\FFI\CData
    {
        $ffi = self::requireFfi();
        $ld = $ffi->new('LDAP*');
        $rc = (int) $ffi->ldap_initialize(\FFI::addr($ld), $uri);
        if (self::LDAP_SUCCESS !== $rc || null === $ld) {
            return null;
        }

        return $ld;
    }

    public static function setOptionInt(?\FFI\CData $ld, int $option, int $value): int
    {
        $ffi = self::requireFfi();
        $box = $ffi->new('int');
        $box->cdata = $value;

        return (int) $ffi->ldap_set_option($ld, $option, \FFI::addr($box));
    }

    public static function getOptionInt(\FFI\CData $ld, int $option): int
    {
        $ffi = self::requireFfi();
        $box = $ffi->new('int');
        $rc = (int) $ffi->ldap_get_option($ld, $option, \FFI::addr($box));
        if (self::LDAP_SUCCESS !== $rc) {
            return 0;
        }

        return (int) $box->cdata;
    }

    public static function simpleBind(\FFI\CData $ld, ?string $dn, ?string $password): int
    {
        return (int) self::requireFfi()->ldap_simple_bind_s($ld, $dn, $password);
    }

    public static function unbind(\FFI\CData $ld): int
    {
        return (int) self::requireFfi()->ldap_unbind_ext_s($ld, null, null);
    }

    /**
     * @param list<string>|null $attributes
     * @return \FFI\CData|null LDAPMessage*
     */
    public static function search(
        \FFI\CData $ld,
        string $base,
        int $scope,
        string $filter,
        ?array $attributes,
        int $attrsonly,
        int $sizelimit,
        int $timelimit
    ): ?\FFI\CData {
        $ffi = self::requireFfi();
        $attrsPtr = null;
        if (null !== $attributes && [] !== $attributes) {
            $n = \count($attributes);
            $attrs = $ffi->new('char*['.($n + 1).']');
            for ($i = 0; $i < $n; ++$i) {
                $attrs[$i] = $attributes[$i];
            }
            $attrs[$n] = null;
            $attrsPtr = $attrs;
        }
        $timeout = null;
        if ($timelimit > 0) {
            $timeout = $ffi->new('timeval');
            $timeout->tv_sec = $timelimit;
            $timeout->tv_usec = 0;
        }
        $res = $ffi->new('LDAPMessage*');
        $rc = (int) $ffi->ldap_search_ext_s(
            $ld,
            $base,
            $scope,
            $filter,
            $attrsPtr,
            $attrsonly,
            null,
            null,
            $timeout,
            $sizelimit,
            \FFI::addr($res)
        );
        if (self::LDAP_SUCCESS !== $rc) {
            return null;
        }

        return $res;
    }

    public static function countEntries(\FFI\CData $ld, \FFI\CData $res): int
    {
        return (int) self::requireFfi()->ldap_count_entries($ld, $res);
    }

    /**
     * @return \FFI\CData|null LDAPMessage*
     */
    public static function firstEntry(\FFI\CData $ld, \FFI\CData $res): ?\FFI\CData
    {
        $entry = self::requireFfi()->ldap_first_entry($ld, $res);

        return null === $entry ? null : $entry;
    }

    /**
     * @return \FFI\CData|null LDAPMessage*
     */
    public static function nextEntry(\FFI\CData $ld, \FFI\CData $entry): ?\FFI\CData
    {
        $next = self::requireFfi()->ldap_next_entry($ld, $entry);

        return null === $next ? null : $next;
    }

    public static function getDn(\FFI\CData $ld, \FFI\CData $entry): ?string
    {
        $ffi = self::requireFfi();
        $dn = $ffi->ldap_get_dn($ld, $entry);
        if (null === $dn) {
            return null;
        }
        $str = self::ffiString($dn);
        $ffi->ldap_memfree($dn);

        return $str;
    }

    /**
     * @return list<string>
     */
    public static function getValuesLen(\FFI\CData $ld, \FFI\CData $entry, string $attribute): array
    {
        $ffi = self::requireFfi();
        $vals = $ffi->ldap_get_values_len($ld, $entry, $attribute);
        if (null === $vals) {
            return [];
        }
        $out = [];
        for ($i = 0; ; ++$i) {
            $bv = $vals[$i];
            if (null === $bv) {
                break;
            }
            $len = (int) $bv->bv_len;
            if ($len <= 0 || null === $bv->bv_val) {
                $out[] = '';
                continue;
            }
            try {
                $out[] = \FFI::string($bv->bv_val, $len);
            } catch (\Throwable) {
                $out[] = '';
            }
        }
        $ffi->ldap_value_free_len($vals);

        return $out;
    }

    /**
     * @return list<string>
     */
    public static function getAttributes(\FFI\CData $ld, \FFI\CData $entry): array
    {
        $ffi = self::requireFfi();
        $ber = $ffi->new('void*');
        $attr = $ffi->ldap_first_attribute($ld, $entry, \FFI::addr($ber));
        $out = [];
        while (null !== $attr) {
            $out[] = self::ffiString($attr);
            $ffi->ldap_memfree($attr);
            $attr = $ffi->ldap_next_attribute($ld, $entry, $ber);
        }
        if (null !== $ber) {
            // ber_free is optional; OpenLDAP ldap_next_attribute frees via ber cookie.
        }

        return $out;
    }

    public static function msgFree(\FFI\CData $msg): void
    {
        self::requireFfi()->ldap_msgfree($msg);
    }

    public static function err2string(int $errno): string
    {
        return self::ffiString(self::requireFfi()->ldap_err2string($errno));
    }

    /** @return \FFI */
    private static function requireFfi()
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            throw new \LogicException('ldap requires libldap FFI (#3369)');
        }

        return $ffi;
    }

    private static function ffiString(mixed $ptr): string
    {
        if (null === $ptr || false === $ptr) {
            return '';
        }
        try {
            return \FFI::string($ptr);
        } catch (\Throwable) {
            return '';
        }
    }

    private static function ffiEnabled(): bool
    {
        return !isset($_ENV['PHP_COMPILER_DISABLE_LDAP_FFI'])
            && !isset($_SERVER['PHP_COMPILER_DISABLE_LDAP_FFI'])
            && '1' !== \getenv('PHP_COMPILER_DISABLE_LDAP_FFI');
    }

    /** @return \FFI|null */
    private static function ffi()
    {
        if (!self::ffiEnabled()) {
            return null;
        }
        if (self::$ffiUnavailable) {
            return null;
        }
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!\extension_loaded('ffi')) {
            self::$ffiUnavailable = true;

            return null;
        }

        $cdef = <<<'CDEF'
typedef void LDAP;
typedef void LDAPMessage;
typedef struct berval {
    size_t bv_len;
    char *bv_val;
} BerValue;
typedef struct timeval {
    long tv_sec;
    long tv_usec;
} timeval;
int ldap_initialize(LDAP **ldp, const char *url);
int ldap_unbind_ext_s(LDAP *ld, void *serverctrls, void *clientctrls);
char *ldap_err2string(int err);
int ldap_set_option(LDAP *ld, int option, const void *invalue);
int ldap_get_option(LDAP *ld, int option, void *outvalue);
int ldap_simple_bind_s(LDAP *ld, const char *who, const char *passwd);
int ldap_search_ext_s(LDAP *ld, const char *base, int scope, const char *filter, char **attrs, int attrsonly, void *serverctrls, void *clientctrls, timeval *timeout, int sizelimit, LDAPMessage **res);
int ldap_count_entries(LDAP *ld, LDAPMessage *res);
LDAPMessage *ldap_first_entry(LDAP *ld, LDAPMessage *res);
LDAPMessage *ldap_next_entry(LDAP *ld, LDAPMessage *entry);
char *ldap_get_dn(LDAP *ld, LDAPMessage *entry);
char *ldap_first_attribute(LDAP *ld, LDAPMessage *entry, void **ber);
char *ldap_next_attribute(LDAP *ld, LDAPMessage *entry, void *ber);
BerValue **ldap_get_values_len(LDAP *ld, LDAPMessage *entry, const char *attr);
void ldap_value_free_len(BerValue **vals);
void ldap_memfree(void *p);
int ldap_msgfree(LDAPMessage *msg);
CDEF;

        foreach (['libldap-2.5.so.0', 'libldap.so.2', 'libldap.so'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);

                return self::$ffi;
            } catch (\Throwable) {
            }
        }
        self::$ffiUnavailable = true;

        return null;
    }
}
