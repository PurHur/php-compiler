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
     * Oracle wallet TLS connect (php-src HAVE_ORALDAP / ldap_connect_wallet; #20638).
     *
     * True only when an Oracle LDAP library exports ldap_init_SSL — OpenLDAP
     * builds withhold the symbol and must not advertise the builtin.
     */
    public static function walletAvailable(): bool
    {
        return null !== self::oracleFfi();
    }

    /**
     * @return \FFI\CData|null LDAP*
     */
    public static function initializeWallet(string $uri, string $wallet, string $password, int $authMode): ?\FFI\CData
    {
        $ld = self::initialize($uri);
        if (null === $ld) {
            return null;
        }
        $ora = self::oracleFfi();
        if (null === $ora) {
            self::unbind($ld);

            return null;
        }
        if (0 !== $authMode) {
            // Oracle ldap_init_SSL(Sockbuf **, wallet, passwd, authmode) — ld_sb is
            // internal; when the ABI is present the call matches php-src.
            try {
                $rc = (int) $ora->ldap_init_SSL(null, $wallet, $password, $authMode);
                if (0 !== $rc) {
                    self::unbind($ld);

                    return null;
                }
            } catch (\Throwable) {
                self::unbind($ld);

                return null;
            }
        }

        return $ld;
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

    public static function getOptionInt(?\FFI\CData $ld, int $option): array
    {
        $ffi = self::requireFfi();
        $box = $ffi->new('int');
        $rc = (int) $ffi->ldap_get_option($ld, $option, \FFI::addr($box));
        if (self::LDAP_SUCCESS !== $rc) {
            return ['ok' => false, 'value' => 0];
        }

        return ['ok' => true, 'value' => (int) $box->cdata];
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

    /**
     * Synchronous EXOP (php-src ldap_exop with &$response_data).
     *
     * @return array{ok: bool, errno: int, oid: string, data: string}
     */
    public static function extendedOperationSync(\FFI\CData $ld, string $oid, ?string $data): array
    {
        $ffi = self::requireFfi();
        $req = null;
        if (null !== $data && '' !== $data) {
            $req = $ffi->new('BerValue');
            $req->bv_len = \strlen($data);
            $buf = $ffi->new('char['.\strlen($data).']', false);
            \FFI::memcpy($buf, $data, \strlen($data));
            $req->bv_val = $buf;
        }
        $oidp = $ffi->new('char*[1]');
        $datap = $ffi->new('BerValue*[1]');
        $oidp[0] = null;
        $datap[0] = null;
        $rc = (int) $ffi->ldap_extended_operation_s(
            $ld,
            $oid,
            $req,
            null,
            null,
            $oidp,
            $datap
        );
        $outOid = '';
        $outData = '';
        if (null !== $oidp[0]) {
            $outOid = self::ffiString($oidp[0]);
            $ffi->ldap_memfree($oidp[0]);
        }
        if (null !== $datap[0]) {
            $bv = $datap[0];
            $len = (int) $bv->bv_len;
            if ($len > 0 && null !== $bv->bv_val) {
                try {
                    $outData = \FFI::string($bv->bv_val, $len);
                } catch (\Throwable) {
                    $outData = '';
                }
            }
            $ffi->ldap_memfree($bv->bv_val);
            $ffi->ldap_memfree($bv);
        }

        return [
            'ok' => self::LDAP_SUCCESS === $rc,
            'errno' => $rc,
            'oid' => $outOid,
            'data' => $outData,
        ];
    }

    /**
     * Asynchronous EXOP → LDAPMessage* result (php-src ldap_exop without &$response_data).
     *
     * @return array{result: ?\FFI\CData, errno: int}
     */
    public static function extendedOperationAsync(\FFI\CData $ld, string $oid, ?string $data): array
    {
        $ffi = self::requireFfi();
        $req = null;
        if (null !== $data && '' !== $data) {
            $req = $ffi->new('BerValue');
            $req->bv_len = \strlen($data);
            $buf = $ffi->new('char['.\strlen($data).']', false);
            \FFI::memcpy($buf, $data, \strlen($data));
            $req->bv_val = $buf;
        }
        $msgid = $ffi->new('int');
        $rc = (int) $ffi->ldap_extended_operation($ld, $oid, $req, null, null, \FFI::addr($msgid));
        if (self::LDAP_SUCCESS !== $rc) {
            return ['result' => null, 'errno' => $rc];
        }
        $res = $ffi->new('LDAPMessage*[1]');
        $res[0] = null;
        $rrc = (int) $ffi->ldap_result($ld, (int) $msgid->cdata, 1, null, $res);
        if (-1 === $rrc || null === $res[0]) {
            return ['result' => null, 'errno' => -1];
        }

        return ['result' => $res[0], 'errno' => self::LDAP_SUCCESS];
    }

    /**
     * @return array{ok: bool, errno: int, oid: string, data: string}
     */
    public static function parseExtendedResult(\FFI\CData $ld, \FFI\CData $res): array
    {
        $ffi = self::requireFfi();
        $oidp = $ffi->new('char*[1]');
        $datap = $ffi->new('BerValue*[1]');
        $oidp[0] = null;
        $datap[0] = null;
        $rc = (int) $ffi->ldap_parse_extended_result($ld, $res, $oidp, $datap, 0);
        $outOid = '';
        $outData = '';
        if (null !== $oidp[0]) {
            $outOid = self::ffiString($oidp[0]);
            $ffi->ldap_memfree($oidp[0]);
        }
        if (null !== $datap[0]) {
            $bv = $datap[0];
            $len = (int) $bv->bv_len;
            if ($len > 0 && null !== $bv->bv_val) {
                try {
                    $outData = \FFI::string($bv->bv_val, $len);
                } catch (\Throwable) {
                    $outData = '';
                }
            }
            $ffi->ldap_memfree($bv->bv_val);
            $ffi->ldap_memfree($bv);
        }

        return [
            'ok' => self::LDAP_SUCCESS === $rc,
            'errno' => $rc,
            'oid' => $outOid,
            'data' => $outData,
        ];
    }

    /**
     * @return array{ok: bool, errno: int, ttl: int}
     */
    public static function refreshSync(\FFI\CData $ld, string $dn, int $ttl): array
    {
        $ffi = self::requireFfi();
        $bv = self::newBerValue($dn);
        $newttl = $ffi->new('int');
        $rc = (int) $ffi->ldap_refresh_s($ld, \FFI::addr($bv), $ttl, \FFI::addr($newttl), null, null);
        if (self::LDAP_SUCCESS !== $rc) {
            return ['ok' => false, 'errno' => $rc, 'ttl' => 0];
        }

        return ['ok' => true, 'errno' => self::LDAP_SUCCESS, 'ttl' => (int) $newttl->cdata];
    }

    /**
     * RFC 3062 password modify EXOP (php-src ldap_exop_passwd / ldap_passwd).
     *
     * @return array{ok: bool, errno: int, value: bool|string}
     */
    public static function passwdModifySync(\FFI\CData $ld, string $user, string $oldPw, string $newPw): array
    {
        $ffi = self::requireFfi();
        $luser = self::newBerValue($user);
        $lold = '' !== $oldPw ? self::newBerValue($oldPw) : null;
        $lnew = '' !== $newPw ? self::newBerValue($newPw) : null;
        $msgid = $ffi->new('int');
        $rc = (int) $ffi->ldap_passwd(
            $ld,
            \FFI::addr($luser),
            null !== $lold ? \FFI::addr($lold) : null,
            null !== $lnew ? \FFI::addr($lnew) : null,
            null,
            null,
            \FFI::addr($msgid)
        );
        if (self::LDAP_SUCCESS !== $rc) {
            return ['ok' => false, 'errno' => $rc, 'value' => false];
        }
        $res = $ffi->new('LDAPMessage*[1]');
        $res[0] = null;
        $rrc = (int) $ffi->ldap_result($ld, (int) $msgid->cdata, 1, null, $res);
        if (-1 === $rrc || null === $res[0]) {
            return ['ok' => false, 'errno' => self::getOptionInt($ld, self::LDAP_OPT_ERROR_NUMBER)['value'], 'value' => false];
        }
        $ldapRes = $res[0];
        $genPass = $ffi->new('BerValue*[1]');
        $genPass[0] = null;
        $prc = (int) $ffi->ldap_parse_passwd($ld, $ldapRes, $genPass);
        if (self::LDAP_SUCCESS !== $prc) {
            $ffi->ldap_msgfree($ldapRes);

            return ['ok' => false, 'errno' => $prc, 'value' => false];
        }
        $genStr = '';
        if (null !== $genPass[0]) {
            $bv = $genPass[0];
            $len = (int) $bv->bv_len;
            if ($len > 0 && null !== $bv->bv_val) {
                try {
                    $genStr = \FFI::string($bv->bv_val, $len);
                } catch (\Throwable) {
                    $genStr = '';
                }
            }
            $ffi->ldap_memfree($bv->bv_val);
            $ffi->ldap_memfree($bv);
        }
        $err = $ffi->new('int');
        $errmsg = $ffi->new('char*[1]');
        $errmsg[0] = null;
        $parseRc = (int) $ffi->ldap_parse_result($ld, $ldapRes, \FFI::addr($err), null, $errmsg, null, null, 0);
        $errVal = (int) $err->cdata;
        $errMsgStr = null !== $errmsg[0] ? self::ffiString($errmsg[0]) : '';
        if (null !== $errmsg[0]) {
            $ffi->ldap_memfree($errmsg[0]);
        }
        $ffi->ldap_msgfree($ldapRes);
        if (self::LDAP_SUCCESS !== $parseRc) {
            return ['ok' => false, 'errno' => $parseRc, 'value' => false];
        }
        if ('' === $newPw) {
            return ['ok' => true, 'errno' => self::LDAP_SUCCESS, 'value' => '' === $genStr ? '' : $genStr];
        }
        if (self::LDAP_SUCCESS === $errVal) {
            return ['ok' => true, 'errno' => self::LDAP_SUCCESS, 'value' => true];
        }

        return ['ok' => false, 'errno' => $errVal, 'value' => false, 'errmsg' => $errMsgStr];
    }

    /** @return \FFI\CData BerValue struct */
    private static function newBerValue(string $data): \FFI\CData
    {
        $ffi = self::requireFfi();
        $bv = $ffi->new('BerValue');
        $len = \strlen($data);
        $bv->bv_len = $len;
        if (0 === $len) {
            $bv->bv_val = null;

            return $bv;
        }
        $buf = $ffi->new('char['.$len.']', false);
        \FFI::memcpy($buf, $data, $len);
        $bv->bv_val = $buf;

        return $bv;
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
int ldap_extended_operation_s(LDAP *ld, const char *reqoid, BerValue *reqdata, void *serverctrls, void *clientctrls, char **retoidp, BerValue **retdatap);
int ldap_extended_operation(LDAP *ld, const char *reqoid, BerValue *reqdata, void *serverctrls, void *clientctrls, int *msgidp);
int ldap_result(LDAP *ld, int msgid, int all, timeval *timeout, LDAPMessage **result);
int ldap_parse_extended_result(LDAP *ld, LDAPMessage *res, char **retoidp, BerValue **retdatap, int freeit);
int ldap_refresh_s(LDAP *ld, BerValue *dn, int ttl, int *newttl, void *serverctrls, void *clientctrls);
int ldap_passwd(LDAP *ld, BerValue *user, BerValue *oldpw, BerValue *newpw, void *serverctrls, void *clientctrls, int *msgidp);
int ldap_parse_passwd(LDAP *ld, LDAPMessage *res, BerValue **newpasswd);
int ldap_parse_result(LDAP *ld, LDAPMessage *res, int *errcodep, char **matcheddnp, char **errmsgp, char ***referralsp, void **serverctrls, int freeit);
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

    /** @var \FFI|null|false */
    private static $oracleFfi = false;

    /** @return \FFI|null */
    private static function oracleFfi()
    {
        if (false !== self::$oracleFfi) {
            return self::$oracleFfi;
        }
        if (!self::ffiEnabled() || !\extension_loaded('ffi')) {
            self::$oracleFfi = null;

            return null;
        }
        $cdef = <<<'CDEF'
int ldap_init_SSL(void **sb, const char *wallet, const char *walletpasswd, int sslauth);
CDEF;
        foreach ([
            'libclntsh.so',
            'libclntsh.so.21.1',
            'libclntsh.so.19.1',
            'libclntshcore.so.21.1',
            'libnnz21.so',
        ] as $lib) {
            try {
                $ffi = \FFI::cdef($cdef, $lib);
                // Probe: symbol must resolve; OpenLDAP loads but call is absent at link —
                // FFI::cdef succeeds only if the symbol exists in the shared object.
                self::$oracleFfi = $ffi;

                return self::$oracleFfi;
            } catch (\Throwable) {
            }
        }
        self::$oracleFfi = null;

        return null;
    }
}
