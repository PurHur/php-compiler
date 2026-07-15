<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

/**
 * X509 PEM validate/normalize via libcrypto FFI — no host ext/openssl delegation (#8306, #7268).
 *
 * php-src: ext/openssl/xp.c — openssl_x509_read()
 */
final class VmOpensslX509Native
{
    /** @var \FFI|null */
    private static $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    /**
     * Parse PEM/DER certificate into php-src openssl_x509_parse() array shape (ext/openssl/xp.c; #6274).
     *
     * @return array<string, mixed>|false
     */
    public static function parseCertificatePem(string $pem, bool $shortnames = true): array|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $inBio = null;
        $x509 = null;

        try {
            $inBio = $ffi->BIO_new_mem_buf($pem, \strlen($pem));
            if (null === $inBio) {
                return false;
            }

            $x509 = $ffi->PEM_read_bio_X509($inBio, null, null, null);
            if (null === $x509) {
                return false;
            }

            return self::parseX509Struct($ffi, $x509, $shortnames);
        } finally {
            if (null !== $x509) {
                $ffi->X509_free($x509);
            }
            if (null !== $inBio) {
                $ffi->BIO_free($inBio);
            }
        }
    }

    /**
     * Certificate DER digest fingerprint (php-src ext/openssl/x509.c — openssl_x509_fingerprint).
     *
     * @return string|false lowercase hex or raw digest bytes when $rawOutput is true
     */
    public static function fingerprintCertificatePem(string $pem, string $hashAlgo, bool $rawOutput): string|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }
        if (!self::digestAvailable($hashAlgo)) {
            return false;
        }

        $inBio = null;
        $x509 = null;

        try {
            $inBio = $ffi->BIO_new_mem_buf($pem, \strlen($pem));
            if (null === $inBio) {
                return false;
            }

            $x509 = $ffi->PEM_read_bio_X509($inBio, null, null, null);
            if (null === $x509) {
                return false;
            }

            $md = $ffi->EVP_get_digestbyname($hashAlgo);
            if (null === $md) {
                return false;
            }

            $digestLen = $ffi->new('unsigned int');
            $buf = $ffi->new('unsigned char[64]');
            if (1 !== (int) $ffi->X509_digest($x509, $md, $buf, \FFI::addr($digestLen))) {
                return false;
            }

            $len = (int) $digestLen->cdata;
            if ($len <= 0) {
                return false;
            }

            $raw = \FFI::string($buf, $len);

            return $rawOutput ? $raw : \bin2hex($raw);
        } finally {
            if (null !== $x509) {
                $ffi->X509_free($x509);
            }
            if (null !== $inBio) {
                $ffi->BIO_free($inBio);
            }
        }
    }

    public static function digestAvailable(string $hashAlgo): bool
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        return null !== $ffi->EVP_get_digestbyname($hashAlgo);
    }

    /**
     * Extract PEM public key from certificate material (php-src ext/openssl/x509.c).
     */
    public static function extractPublicKeyPem(string $certPem): string|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $inBio = null;
        $outBio = null;
        $x509 = null;
        $pkey = null;

        try {
            $inBio = $ffi->BIO_new_mem_buf($certPem, \strlen($certPem));
            if (null === $inBio) {
                return false;
            }

            $x509 = $ffi->PEM_read_bio_X509($inBio, null, null, null);
            if (null === $x509) {
                return false;
            }

            $pkey = $ffi->X509_get_pubkey($x509);
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

            $pending = (int) $ffi->BIO_ctrl_pending($outBio);
            if ($pending <= 0) {
                return false;
            }

            $buf = $ffi->new("char[{$pending}]");
            $read = (int) $ffi->BIO_read($outBio, $buf, $pending);

            return $read > 0 ? \FFI::string($buf, $read) : false;
        } finally {
            if (null !== $pkey) {
                $ffi->EVP_PKEY_free($pkey);
            }
            if (null !== $x509) {
                $ffi->X509_free($x509);
            }
            if (null !== $inBio) {
                $ffi->BIO_free($inBio);
            }
            if (null !== $outBio) {
                $ffi->BIO_free($outBio);
            }
        }
    }

    /**
     * X509_verify certificate signature against a public/private key PEM (php-src openssl_x509_verify).
     *
     * @return int 1 valid, 0 invalid, -1 error
     */
    public static function verifyCertificatePem(string $certPem, string $publicKeyPem): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return -1;
        }

        $certBio = null;
        $x509 = null;
        $pkey = null;

        try {
            $certBio = $ffi->BIO_new_mem_buf($certPem, \strlen($certPem));
            if (null === $certBio) {
                return -1;
            }

            $x509 = $ffi->PEM_read_bio_X509($certBio, null, null, null);
            if (null === $x509) {
                return -1;
            }

            $pkey = self::readPublicOrPrivateKey($ffi, $publicKeyPem);
            if (null === $pkey) {
                return -1;
            }

            return (int) $ffi->X509_verify($x509, $pkey);
        } finally {
            if (null !== $pkey) {
                $ffi->EVP_PKEY_free($pkey);
            }
            if (null !== $x509) {
                $ffi->X509_free($x509);
            }
            if (null !== $certBio) {
                $ffi->BIO_free($certBio);
            }
        }
    }

    /**
     * Parse PEM and return normalized certificate PEM, or false when invalid/unavailable.
     */
    public static function normalizeCertificatePem(string $pem): string|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $inBio = null;
        $outBio = null;
        $x509 = null;

        try {
            $inBio = $ffi->BIO_new_mem_buf($pem, \strlen($pem));
            if (null === $inBio) {
                return false;
            }

            $x509 = $ffi->PEM_read_bio_X509($inBio, null, null, null);
            if (null === $x509) {
                return false;
            }

            $outBio = $ffi->BIO_new($ffi->BIO_s_mem());
            if (null === $outBio) {
                return false;
            }

            if (1 !== (int) $ffi->PEM_write_bio_X509($outBio, $x509)) {
                return false;
            }

            $pending = (int) $ffi->BIO_ctrl_pending($outBio);
            if ($pending <= 0) {
                return false;
            }

            $buf = $ffi->new("char[{$pending}]");
            $read = (int) $ffi->BIO_read($outBio, $buf, $pending);
            if ($read <= 0) {
                return false;
            }

            return \FFI::string($buf, $read);
        } finally {
            if (null !== $x509) {
                $ffi->X509_free($x509);
            }
            if (null !== $inBio) {
                $ffi->BIO_free($inBio);
            }
            if (null !== $outBio) {
                $ffi->BIO_free($outBio);
            }
        }
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
typedef struct bio_method_st BIO_METHOD;
typedef struct x509_st X509;
typedef struct x509_name_st X509_NAME;
typedef struct x509_name_entry_st X509_NAME_ENTRY;
typedef struct asn1_object_st ASN1_OBJECT;
typedef struct asn1_string_st ASN1_STRING;
typedef struct asn1_integer_st ASN1_INTEGER;
typedef struct asn1_time_st ASN1_TIME;

BIO *BIO_new_mem_buf(const void *buf, int len);
BIO *BIO_new(const BIO_METHOD *type);
const BIO_METHOD *BIO_s_mem(void);
void BIO_free(BIO *a);
X509 *PEM_read_bio_X509(BIO *bp, X509 **x, void *cb, void *u);
int PEM_write_bio_X509(BIO *bp, const X509 *x);
size_t BIO_ctrl_pending(BIO *b);
int BIO_read(BIO *b, void *data, int dlen);
void X509_free(X509 *a);
X509_NAME *X509_get_subject_name(X509 *a);
X509_NAME *X509_get_issuer_name(X509 *a);
char *X509_NAME_oneline(X509_NAME *name, char *buf, int size);
long X509_get_version(X509 *x);
ASN1_INTEGER *X509_get_serialNumber(X509 *x);
ASN1_TIME *X509_get0_notBefore(const X509 *x);
ASN1_TIME *X509_get0_notAfter(const X509 *x);
int ASN1_TIME_print(BIO *b, const ASN1_TIME *s);
int X509_NAME_entry_count(const X509_NAME *name);
X509_NAME_ENTRY *X509_NAME_get_entry(X509_NAME *name, int loc);
ASN1_OBJECT *X509_NAME_ENTRY_get_object(const X509_NAME_ENTRY *ne);
ASN1_STRING *X509_NAME_ENTRY_get_data(const X509_NAME_ENTRY *ne);
const char *OBJ_nid2sn(int n);
const char *OBJ_nid2ln(int n);
int OBJ_obj2nid(const ASN1_OBJECT *o);
const unsigned char *ASN1_STRING_get0_data(const ASN1_STRING *x);
int ASN1_STRING_length(const ASN1_STRING *x);
int i2a_ASN1_INTEGER(BIO *bp, const ASN1_INTEGER *a);
int X509_get_signature_nid(const X509 *x);
typedef struct evp_md_st EVP_MD;
const EVP_MD *EVP_get_digestbyname(const char *name);
int X509_digest(const X509 *data, const EVP_MD *type, unsigned char *md, unsigned int *len);
typedef struct evp_pkey_st EVP_PKEY;
EVP_PKEY *X509_get_pubkey(X509 *x);
int X509_verify(X509 *a, EVP_PKEY *r);
void EVP_PKEY_free(EVP_PKEY *pkey);
EVP_PKEY *PEM_read_bio_PUBKEY(BIO *bp, EVP_PKEY **x, void *cb, void *u);
EVP_PKEY *PEM_read_bio_PrivateKey(BIO *bp, EVP_PKEY **x, void *cb, void *u);
int PEM_write_bio_PUBKEY(BIO *bp, EVP_PKEY *x);
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

    /**
     * @return array<string, mixed>
     */
    private static function parseX509Struct(\FFI $ffi, \FFI\CData $x509, bool $shortnames): array
    {
        $subjectName = $ffi->X509_get_subject_name($x509);
        $issuerName = $ffi->X509_get_issuer_name($x509);
        $subject = self::parseX509Name($ffi, $subjectName, $shortnames);
        $issuer = self::parseX509Name($ffi, $issuerName, $shortnames);

        $nameBuf = $ffi->new('char[256]');
        $oneline = $ffi->X509_NAME_oneline($subjectName, $nameBuf, 256);
        $name = self::ffiCharPtrToString($oneline);

        $version = (int) $ffi->X509_get_version($x509) + 1;
        $serialHex = self::asn1IntegerToHex($ffi, $ffi->X509_get_serialNumber($x509));
        $validFrom = self::asn1TimeToString($ffi, $ffi->X509_get0_notBefore($x509));
        $validTo = self::asn1TimeToString($ffi, $ffi->X509_get0_notAfter($x509));
        $sigNid = (int) $ffi->X509_get_signature_nid($x509);

        return [
            'name' => $name,
            'subject' => $subject,
            'issuer' => $issuer,
            'version' => $version,
            'serialNumber' => self::hexToDecimalSerial($serialHex),
            'serialNumberHex' => $serialHex,
            'validFrom' => $validFrom,
            'validTo' => $validTo,
            'validFrom_time_t' => self::asn1TimeToTimestamp($validFrom),
            'validTo_time_t' => self::asn1TimeToTimestamp($validTo),
            'signatureTypeSN' => self::ffiCharPtrToString($ffi->OBJ_nid2sn($sigNid)),
            'signatureTypeLN' => self::ffiCharPtrToString($ffi->OBJ_nid2ln($sigNid)),
            'signatureTypeNID' => $sigNid,
            'purposes' => [],
        ];
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

    private static function asn1TimeToString(\FFI $ffi, \FFI\CData $time): string
    {
        $bio = $ffi->BIO_new($ffi->BIO_s_mem());
        if (null === $bio) {
            return '';
        }
        try {
            if (1 !== (int) $ffi->ASN1_TIME_print($bio, $time)) {
                return '';
            }
            $pending = (int) $ffi->BIO_ctrl_pending($bio);
            if ($pending <= 0) {
                return '';
            }
            $buf = $ffi->new("char[{$pending}]");
            $read = (int) $ffi->BIO_read($bio, $buf, $pending);

            return $read > 0 ? \FFI::string($buf, $read) : '';
        } finally {
            $ffi->BIO_free($bio);
        }
    }

    private static function asn1IntegerToHex(\FFI $ffi, \FFI\CData $integer): string
    {
        $bio = $ffi->BIO_new($ffi->BIO_s_mem());
        if (null === $bio) {
            return '';
        }
        try {
            if (0 >= (int) $ffi->i2a_ASN1_INTEGER($bio, $integer)) {
                return '';
            }
            $pending = (int) $ffi->BIO_ctrl_pending($bio);
            if ($pending <= 0) {
                return '';
            }
            $buf = $ffi->new("char[{$pending}]");
            $read = (int) $ffi->BIO_read($bio, $buf, $pending);
            $hex = $read > 0 ? \FFI::string($buf, $read) : '';

            return strtoupper(str_replace(':', '', $hex));
        } finally {
            $ffi->BIO_free($bio);
        }
    }

    private static function hexToDecimalSerial(string $hex): string
    {
        if ('' === $hex) {
            return '0';
        }
        if (\function_exists('gmp_init')) {
            return gmp_strval(gmp_init($hex, 16), 10);
        }

        return base_convert($hex, 16, 10);
    }

    private static function asn1TimeToTimestamp(string $asn1Time): int
    {
        if ('' === $asn1Time) {
            return 0;
        }
        $ts = strtotime($asn1Time);

        return false === $ts ? 0 : $ts;
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

    /**
     * @param \FFI $ffi
     *
     * @return \FFI\CData|null
     */
    private static function readPublicOrPrivateKey($ffi, string $pem)
    {
        $bio = $ffi->BIO_new_mem_buf($pem, \strlen($pem));
        if (null === $bio) {
            return null;
        }

        try {
            $pub = $ffi->PEM_read_bio_PUBKEY($bio, null, null, null);
            if (null !== $pub) {
                return $pub;
            }
        } finally {
            $ffi->BIO_free($bio);
        }

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
}
