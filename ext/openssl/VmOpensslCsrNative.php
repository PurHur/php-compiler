<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

/**
 * X509_REQ create/export/sign via libcrypto FFI (php-src ext/openssl/xp.c; #6421).
 */
final class VmOpensslCsrNative
{
    private const MBSTRING_UTF8 = 0x1000;

    /** @var \FFI|null */
    private static $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    /**
     * @param array<string, string> $distinguishedNames
     */
    public static function createCsrPem(array $distinguishedNames, string $privateKeyPem, string $digestAlg): string|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $pkey = null;
        $req = null;

        try {
            $pkey = self::readPrivateKey($ffi, $privateKeyPem);
            if (null === $pkey) {
                return false;
            }

            $req = $ffi->X509_REQ_new();
            if (null === $req) {
                return false;
            }

            if (1 !== (int) $ffi->X509_REQ_set_version($req, 0)) {
                return false;
            }

            $name = $ffi->X509_REQ_get_subject_name($req);
            if (null === $name) {
                return false;
            }

            foreach ($distinguishedNames as $field => $value) {
                if ('' === $field || '' === $value) {
                    continue;
                }
                if (!self::addNameEntry($ffi, $name, (string) $field, (string) $value)) {
                    return false;
                }
            }

            if (1 !== (int) $ffi->X509_REQ_set_pubkey($req, $pkey)) {
                return false;
            }

            $md = $ffi->EVP_get_digestbyname($digestAlg);
            if (null === $md) {
                return false;
            }

            if (0 >= (int) $ffi->X509_REQ_sign($req, $pkey, $md)) {
                return false;
            }

            return self::writeReqPem($ffi, $req);
        } finally {
            if (null !== $req) {
                $ffi->X509_REQ_free($req);
            }
            if (null !== $pkey) {
                $ffi->EVP_PKEY_free($pkey);
            }
        }
    }

    public static function normalizeCsrPem(string $pem): string|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $req = self::readReq($ffi, $pem);
        if (null === $req) {
            return false;
        }

        try {
            return self::writeReqPem($ffi, $req);
        } finally {
            $ffi->X509_REQ_free($req);
        }
    }

    /**
     * @return array<string, string>|false
     */
    public static function getSubject(string $pem, bool $shortnames): array|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $req = self::readReq($ffi, $pem);
        if (null === $req) {
            return false;
        }

        try {
            $name = $ffi->X509_REQ_get_subject_name($req);
            if (null === $name) {
                return false;
            }

            return self::parseX509Name($ffi, $name, $shortnames);
        } finally {
            $ffi->X509_REQ_free($req);
        }
    }

    public static function getPublicKeyPem(string $pem): string|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $req = null;
        $pkey = null;
        $outBio = null;

        try {
            $req = self::readReq($ffi, $pem);
            if (null === $req) {
                return false;
            }

            $pkey = $ffi->X509_REQ_get_pubkey($req);
            if (null === $pkey) {
                return false;
            }

            $outBio = $ffi->BIO_new($ffi->BIO_s_mem());
            if (null === $outBio) {
                return false;
            }

            if (1 !== (int) $ffi->PEM_write_bio_PUBKEY($outBio, $pkey)) {
                return false;
            }

            return self::bioToString($ffi, $outBio);
        } finally {
            if (null !== $pkey) {
                $ffi->EVP_PKEY_free($pkey);
            }
            if (null !== $req) {
                $ffi->X509_REQ_free($req);
            }
            if (null !== $outBio) {
                $ffi->BIO_free($outBio);
            }
        }
    }

    /**
     * Sign CSR into an X.509 certificate PEM (self-signed when $caCertPem is null).
     */
    public static function signCsrPem(
        string $csrPem,
        ?string $caCertPem,
        string $privateKeyPem,
        int $days,
        string $digestAlg,
        int $serial,
    ): string|false {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $req = null;
        $x509 = null;
        $ca = null;
        $pkey = null;
        $reqPub = null;

        try {
            $req = self::readReq($ffi, $csrPem);
            if (null === $req) {
                return false;
            }

            $pkey = self::readPrivateKey($ffi, $privateKeyPem);
            if (null === $pkey) {
                return false;
            }

            $x509 = $ffi->X509_new();
            if (null === $x509) {
                return false;
            }

            if (1 !== (int) $ffi->X509_set_version($x509, 2)) {
                return false;
            }

            if (1 !== (int) $ffi->ASN1_INTEGER_set($ffi->X509_get_serialNumber($x509), $serial)) {
                return false;
            }

            $subject = $ffi->X509_REQ_get_subject_name($req);
            if (null === $subject) {
                return false;
            }
            $subjectDup = $ffi->X509_NAME_dup($subject);
            if (null === $subjectDup) {
                return false;
            }
            if (1 !== (int) $ffi->X509_set_subject_name($x509, $subjectDup)) {
                return false;
            }

            if (null === $caCertPem) {
                if (1 !== (int) $ffi->X509_set_issuer_name($x509, $subjectDup)) {
                    return false;
                }
            } else {
                $ca = self::readCert($ffi, $caCertPem);
                if (null === $ca) {
                    return false;
                }
                $issuer = $ffi->X509_get_subject_name($ca);
                if (null === $issuer) {
                    return false;
                }
                $issuerDup = $ffi->X509_NAME_dup($issuer);
                if (null === $issuerDup) {
                    return false;
                }
                if (1 !== (int) $ffi->X509_set_issuer_name($x509, $issuerDup)) {
                    return false;
                }
            }

            $reqPub = $ffi->X509_REQ_get_pubkey($req);
            if (null === $reqPub) {
                return false;
            }
            if (1 !== (int) $ffi->X509_set_pubkey($x509, $reqPub)) {
                return false;
            }

            if (null === $ffi->X509_gmtime_adj($ffi->X509_getm_notBefore($x509), 0)) {
                return false;
            }
            if ($days < 0) {
                $days = 0;
            }
            if (null === $ffi->X509_gmtime_adj($ffi->X509_getm_notAfter($x509), $days * 24 * 3600)) {
                return false;
            }

            $md = $ffi->EVP_get_digestbyname($digestAlg);
            if (null === $md) {
                return false;
            }

            if (0 >= (int) $ffi->X509_sign($x509, $pkey, $md)) {
                return false;
            }

            return self::writeCertPem($ffi, $x509);
        } finally {
            if (null !== $reqPub) {
                $ffi->EVP_PKEY_free($reqPub);
            }
            if (null !== $pkey) {
                $ffi->EVP_PKEY_free($pkey);
            }
            if (null !== $ca) {
                $ffi->X509_free($ca);
            }
            if (null !== $x509) {
                $ffi->X509_free($x509);
            }
            if (null !== $req) {
                $ffi->X509_REQ_free($req);
            }
        }
    }

    /**
     * @param \FFI       $ffi
     * @param \FFI\CData $name
     */
    private static function addNameEntry($ffi, $name, string $field, string $value): bool
    {
        $len = \strlen($value);
        if ($len <= 0) {
            return true;
        }
        $buf = $ffi->new("unsigned char[{$len}]");
        \FFI::memcpy($buf, $value, $len);

        return 1 === (int) $ffi->X509_NAME_add_entry_by_txt($name, $field, self::MBSTRING_UTF8, $buf, $len, -1, 0);
    }

    /**
     * @return array<string, string>
     */
    private static function parseX509Name(\FFI $ffi, \FFI\CData $name, bool $shortnames): array
    {
        $result = [];
        $count = (int) $ffi->X509_NAME_entry_count($name);
        for ($i = 0; $i < $count; ++$i) {
            $entry = $ffi->X509_NAME_get_entry($name, $i);
            if (null === $entry) {
                continue;
            }
            $object = $ffi->X509_NAME_ENTRY_get_object($entry);
            $data = $ffi->X509_NAME_ENTRY_get_data($entry);
            if (null === $object || null === $data) {
                continue;
            }
            $nid = (int) $ffi->OBJ_obj2nid($object);
            $key = $shortnames
                ? self::ffiCharPtrToString($ffi->OBJ_nid2sn($nid))
                : self::ffiCharPtrToString($ffi->OBJ_nid2ln($nid));
            if ('' === $key) {
                continue;
            }
            $len = (int) $ffi->ASN1_STRING_length($data);
            $raw = $ffi->ASN1_STRING_get0_data($data);
            $result[$key] = $len > 0 && null !== $raw ? self::ffiCharPtrToString($raw, $len) : '';
        }

        return $result;
    }

    /**
     * @param \FFI $ffi
     *
     * @return \FFI\CData|null
     */
    private static function readPrivateKey($ffi, string $pem)
    {
        $bio = $ffi->BIO_new_mem_buf($pem, \strlen($pem));
        if (null === $bio) {
            return null;
        }

        try {
            return $ffi->PEM_read_bio_PrivateKey($bio, null, null, null);
        } finally {
            $ffi->BIO_free($bio);
        }
    }

    /**
     * @param \FFI $ffi
     *
     * @return \FFI\CData|null
     */
    private static function readReq($ffi, string $pem)
    {
        $bio = $ffi->BIO_new_mem_buf($pem, \strlen($pem));
        if (null === $bio) {
            return null;
        }

        try {
            return $ffi->PEM_read_bio_X509_REQ($bio, null, null, null);
        } finally {
            $ffi->BIO_free($bio);
        }
    }

    /**
     * @param \FFI $ffi
     *
     * @return \FFI\CData|null
     */
    private static function readCert($ffi, string $pem)
    {
        $bio = $ffi->BIO_new_mem_buf($pem, \strlen($pem));
        if (null === $bio) {
            return null;
        }

        try {
            return $ffi->PEM_read_bio_X509($bio, null, null, null);
        } finally {
            $ffi->BIO_free($bio);
        }
    }

    /**
     * @param \FFI       $ffi
     * @param \FFI\CData $req
     */
    private static function writeReqPem($ffi, $req): string|false
    {
        $outBio = $ffi->BIO_new($ffi->BIO_s_mem());
        if (null === $outBio) {
            return false;
        }

        try {
            if (1 !== (int) $ffi->PEM_write_bio_X509_REQ($outBio, $req)) {
                return false;
            }

            return self::bioToString($ffi, $outBio);
        } finally {
            $ffi->BIO_free($outBio);
        }
    }

    /**
     * @param \FFI       $ffi
     * @param \FFI\CData $x509
     */
    private static function writeCertPem($ffi, $x509): string|false
    {
        $outBio = $ffi->BIO_new($ffi->BIO_s_mem());
        if (null === $outBio) {
            return false;
        }

        try {
            if (1 !== (int) $ffi->PEM_write_bio_X509($outBio, $x509)) {
                return false;
            }

            return self::bioToString($ffi, $outBio);
        } finally {
            $ffi->BIO_free($outBio);
        }
    }

    /**
     * @param \FFI       $ffi
     * @param \FFI\CData $bio
     */
    private static function bioToString($ffi, $bio): string|false
    {
        $pending = (int) $ffi->BIO_ctrl_pending($bio);
        if ($pending <= 0) {
            return false;
        }
        $buf = $ffi->new("char[{$pending}]");
        $read = (int) $ffi->BIO_read($bio, $buf, $pending);
        if ($read <= 0) {
            return false;
        }

        return \FFI::string($buf, $read);
    }

    private static function ffiCharPtrToString(mixed $ptr, ?int $length = null): string
    {
        if (null === $ptr) {
            return '';
        }
        if (\is_string($ptr)) {
            return $ptr;
        }

        return null !== $length ? \FFI::string($ptr, $length) : \FFI::string($ptr);
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
typedef struct bio_st BIO;
typedef struct evp_pkey_st EVP_PKEY;
typedef struct evp_md_st EVP_MD;
typedef struct X509_req_st X509_REQ;
typedef struct X509_name_st X509_NAME;
typedef struct x509_name_entry_st X509_NAME_ENTRY;
typedef struct x509_st X509;
typedef struct asn1_object_st ASN1_OBJECT;
typedef struct asn1_string_st ASN1_STRING;
typedef struct asn1_string_st ASN1_INTEGER;
typedef struct asn1_string_st ASN1_TIME;

BIO *BIO_new_mem_buf(const void *buf, int len);
BIO *BIO_new(const void *type);
const void *BIO_s_mem(void);
int BIO_ctrl_pending(BIO *b);
int BIO_read(BIO *b, void *data, int dlen);
void BIO_free(BIO *a);

EVP_PKEY *PEM_read_bio_PrivateKey(BIO *bp, EVP_PKEY **x, void *cb, void *u);
void EVP_PKEY_free(EVP_PKEY *pkey);
int PEM_write_bio_PUBKEY(BIO *bp, EVP_PKEY *x);
const EVP_MD *EVP_get_digestbyname(const char *name);

X509_REQ *X509_REQ_new(void);
void X509_REQ_free(X509_REQ *a);
int X509_REQ_set_version(X509_REQ *req, long version);
X509_NAME *X509_REQ_get_subject_name(X509_REQ *req);
int X509_REQ_set_pubkey(X509_REQ *x, EVP_PKEY *pkey);
int X509_REQ_sign(X509_REQ *x, EVP_PKEY *pkey, const EVP_MD *md);
int PEM_write_bio_X509_REQ(BIO *bp, const X509_REQ *x);
X509_REQ *PEM_read_bio_X509_REQ(BIO *bp, X509_REQ **x, void *cb, void *u);
EVP_PKEY *X509_REQ_get_pubkey(X509_REQ *req);

int X509_NAME_add_entry_by_txt(X509_NAME *name, const char *field, int type,
    const unsigned char *bytes, int len, int loc, int set);
int X509_NAME_entry_count(const X509_NAME *name);
X509_NAME_ENTRY *X509_NAME_get_entry(X509_NAME *name, int loc);
ASN1_OBJECT *X509_NAME_ENTRY_get_object(const X509_NAME_ENTRY *ne);
ASN1_STRING *X509_NAME_ENTRY_get_data(const X509_NAME_ENTRY *ne);
X509_NAME *X509_NAME_dup(const X509_NAME *xn);
int OBJ_obj2nid(const ASN1_OBJECT *o);
const char *OBJ_nid2sn(int n);
const char *OBJ_nid2ln(int n);
int ASN1_STRING_length(const ASN1_STRING *x);
const unsigned char *ASN1_STRING_get0_data(const ASN1_STRING *x);

X509 *X509_new(void);
void X509_free(X509 *a);
X509 *PEM_read_bio_X509(BIO *bp, X509 **x, void *cb, void *u);
int PEM_write_bio_X509(BIO *bp, const X509 *x);
int X509_set_version(X509 *x, long version);
ASN1_INTEGER *X509_get_serialNumber(X509 *x);
int ASN1_INTEGER_set(ASN1_INTEGER *a, long v);
X509_NAME *X509_get_subject_name(const X509 *a);
int X509_set_subject_name(X509 *x, const X509_NAME *name);
int X509_set_issuer_name(X509 *x, const X509_NAME *name);
int X509_set_pubkey(X509 *x, EVP_PKEY *pkey);
ASN1_TIME *X509_getm_notBefore(const X509 *x);
ASN1_TIME *X509_getm_notAfter(const X509 *x);
ASN1_TIME *X509_gmtime_adj(ASN1_TIME *s, long adj);
int X509_sign(X509 *x, EVP_PKEY *pkey, const EVP_MD *md);
CDEF;

        foreach (['libcrypto.so.3', 'libcrypto.so'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);

                return self::$ffi;
            } catch (\Throwable) {
            }
        }

        self::$ffiUnavailable = true;

        return null;
    }

    private static function ffiEnabled(): bool
    {
        $v = getenv('PHP_COMPILER_DISABLE_FFI');
        if (false !== $v && '' !== $v && '0' !== $v && 'false' !== strtolower($v)) {
            return false;
        }

        return true;
    }
}
