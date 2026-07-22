<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\ext\standard\VmFsReadNative;

/**
 * EVP_PKEY keygen / PEM import-export via libcrypto FFI (php-src ext/openssl/xp.c; #6295).
 */
final class VmOpensslPkeyNative
{
    private const EVP_PKEY_RSA = 6;
    private const EVP_PKEY_RSA2 = 19;
    private const EVP_PKEY_DSA = 116;
    private const EVP_PKEY_DSA1 = 67;
    private const EVP_PKEY_DSA2 = 66;
    private const EVP_PKEY_DSA3 = 113;
    private const EVP_PKEY_DSA4 = 70;
    private const EVP_PKEY_DH = 28;
    private const EVP_PKEY_EC = 408;

    /** @var \FFI|null */
    private static $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    public static function generateRsa(int $bits): string|false
    {
        if ($bits < 384) {
            return false;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $ctx = $ffi->EVP_PKEY_CTX_new_id(self::EVP_PKEY_RSA, null);
        if (null === $ctx) {
            return false;
        }

        try {
            if (1 !== (int) $ffi->EVP_PKEY_keygen_init($ctx)) {
                return false;
            }
            if (1 !== (int) $ffi->EVP_PKEY_CTX_set_rsa_keygen_bits($ctx, $bits)) {
                return false;
            }

            $pkeyOut = $ffi->new('EVP_PKEY *[1]');
            if (1 !== (int) $ffi->EVP_PKEY_keygen($ctx, $pkeyOut)) {
                return false;
            }
            if (null === $pkeyOut[0]) {
                return false;
            }

            try {
                return self::writePrivateKeyPem($ffi, $pkeyOut[0], null);
            } finally {
                $ffi->EVP_PKEY_free($pkeyOut[0]);
            }
        } finally {
            $ffi->EVP_PKEY_CTX_free($ctx);
        }
    }

    /**
     * Generate EC private key for a named curve (php-src php_openssl_generate_private_key EVP_PKEY_EC; #22335).
     *
     * @return string|false PEM, or false on failure
     */
    public static function generateEc(string $curveName): string|false
    {
        if ('' === $curveName) {
            return false;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $nid = (int) $ffi->OBJ_sn2nid($curveName);
        if (0 === $nid) {
            return false;
        }

        $ec = $ffi->EC_KEY_new_by_curve_name($nid);
        if (null === $ec) {
            return false;
        }

        try {
            if (1 !== (int) $ffi->EC_KEY_generate_key($ec)) {
                return false;
            }

            $pkey = $ffi->EVP_PKEY_new();
            if (null === $pkey) {
                return false;
            }
            try {
                if (1 !== (int) $ffi->EVP_PKEY_set1_EC_KEY($pkey, $ec)) {
                    return false;
                }

                return self::writePrivateKeyPem($ffi, $pkey, null);
            } finally {
                $ffi->EVP_PKEY_free($pkey);
            }
        } finally {
            $ffi->EC_KEY_free($ec);
        }
    }

    /**
     * Resolve curve short name via OBJ_sn2nid (NID_undef = 0).
     */
    public static function curveNid(string $curveName): int
    {
        if ('' === $curveName) {
            return 0;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return 0;
        }

        return (int) $ffi->OBJ_sn2nid($curveName);
    }

    /**
     * Generate DH key from explicit p/g (and optional q) BN binaries (php-src php_openssl_pkey_init_dh; #22335).
     *
     * @return string|false PEM
     */
    public static function generateDhFromParams(string $pBin, string $gBin, ?string $qBin = null): string|false
    {
        if ('' === $pBin || '' === $gBin) {
            return false;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $dh = $ffi->DH_new();
        if (null === $dh) {
            return false;
        }

        try {
            $p = self::bin2bn($ffi, $pBin);
            $g = self::bin2bn($ffi, $gBin);
            $q = null !== $qBin && '' !== $qBin ? self::bin2bn($ffi, $qBin) : null;
            if (null === $p || null === $g) {
                if (null !== $p) {
                    $ffi->BN_free($p);
                }
                if (null !== $g) {
                    $ffi->BN_free($g);
                }
                if (null !== $q) {
                    $ffi->BN_free($q);
                }

                return false;
            }

            // DH_set0_pqg takes ownership of p/q/g on success.
            if (1 !== (int) $ffi->DH_set0_pqg($dh, $p, $q, $g)) {
                $ffi->BN_free($p);
                $ffi->BN_free($g);
                if (null !== $q) {
                    $ffi->BN_free($q);
                }

                return false;
            }

            if (1 !== (int) $ffi->DH_generate_key($dh)) {
                return false;
            }

            $pkey = $ffi->EVP_PKEY_new();
            if (null === $pkey) {
                return false;
            }
            try {
                if (1 !== (int) $ffi->EVP_PKEY_set1_DH($pkey, $dh)) {
                    return false;
                }

                return self::writePrivateKeyPem($ffi, $pkey, null);
            } finally {
                $ffi->EVP_PKEY_free($pkey);
            }
        } finally {
            $ffi->DH_free($dh);
        }
    }

    /**
     * Generate DH key via paramgen + keygen (php-src php_openssl_generate_private_key EVP_PKEY_DH).
     *
     * @return string|false PEM
     */
    public static function generateDh(int $bits): string|false
    {
        if ($bits < 384) {
            return false;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $ctx = $ffi->EVP_PKEY_CTX_new_id(self::EVP_PKEY_DH, null);
        if (null === $ctx) {
            return false;
        }

        $params = null;
        try {
            if (1 !== (int) $ffi->EVP_PKEY_paramgen_init($ctx)) {
                return false;
            }
            // EVP_PKEY_CTRL_DH_PARAMGEN_PRIME_LEN = EVP_PKEY_ALG_CTRL + 1 (0x1001)
            // EVP_PKEY_OP_PARAMGEN = (1<<1)
            if (1 !== (int) $ffi->EVP_PKEY_CTX_ctrl($ctx, self::EVP_PKEY_DH, 1 << 1, 0x1001, $bits, null)) {
                return false;
            }

            $paramsOut = $ffi->new('EVP_PKEY *[1]');
            if (1 !== (int) $ffi->EVP_PKEY_paramgen($ctx, $paramsOut) || null === $paramsOut[0]) {
                return false;
            }
            $params = $paramsOut[0];

            $ffi->EVP_PKEY_CTX_free($ctx);
            $ctx = $ffi->EVP_PKEY_CTX_new($params, null);
            if (null === $ctx) {
                return false;
            }

            if (1 !== (int) $ffi->EVP_PKEY_keygen_init($ctx)) {
                return false;
            }

            $pkeyOut = $ffi->new('EVP_PKEY *[1]');
            if (1 !== (int) $ffi->EVP_PKEY_keygen($ctx, $pkeyOut) || null === $pkeyOut[0]) {
                return false;
            }

            try {
                return self::writePrivateKeyPem($ffi, $pkeyOut[0], null);
            } finally {
                $ffi->EVP_PKEY_free($pkeyOut[0]);
            }
        } finally {
            if (null !== $params) {
                $ffi->EVP_PKEY_free($params);
            }
            if (null !== $ctx) {
                $ffi->EVP_PKEY_CTX_free($ctx);
            }
        }
    }

    /**
     * @param \FFI $ffi
     *
     * @return \FFI\CData|null BIGNUM*
     */
    private static function bin2bn($ffi, string $bin)
    {
        $len = \strlen($bin);
        if ($len <= 0) {
            return null;
        }
        $buf = $ffi->new("unsigned char[{$len}]");
        \FFI::memcpy($buf, $bin, $len);

        return $ffi->BN_bin2bn($buf, $len, null);
    }

    public static function normalizePrivateKeyPem(string $pem, ?string $passphrase = null): string|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $pkey = self::readPrivateKey($ffi, $pem, $passphrase);
        if (null === $pkey) {
            return false;
        }

        try {
            return self::writePrivateKeyPem($ffi, $pkey, $passphrase);
        } finally {
            $ffi->EVP_PKEY_free($pkey);
        }
    }

    public static function exportPrivateKeyPem(string $pem, ?string $passphrase = null): string|false
    {
        return self::normalizePrivateKeyPem($pem, $passphrase);
    }

    public static function exportPublicKeyPem(string $pem): string|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $pkey = self::readAnyKey($ffi, $pem);
        if (null === $pkey) {
            return false;
        }

        try {
            return self::writePublicKeyPem($ffi, $pkey);
        } finally {
            $ffi->EVP_PKEY_free($pkey);
        }
    }

    /**
     * Normalize a public-key PEM only (php-src openssl_pkey_get_public; #20240).
     * Private-key PEMs must fail — do not coerce via readAnyKey().
     */
    public static function normalizePublicKeyPem(string $pem): string|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $pkey = self::readPublicKey($ffi, $pem);
        if (null === $pkey) {
            return false;
        }

        try {
            return self::writePublicKeyPem($ffi, $pkey);
        } finally {
            $ffi->EVP_PKEY_free($pkey);
        }
    }

    /**
     * openssl_pkey_get_details() array (php-src ext/openssl/openssl.c; #20240, #22335).
     *
     * @return array{bits: int, key: string, type: int, rsa?: array<string, string>, ec?: array<string, string>, dh?: array<string, string>}|false
     */
    public static function getDetails(string $pem): array|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $pkey = self::readAnyKey($ffi, $pem);
        if (null === $pkey) {
            return false;
        }

        try {
            $bits = (int) $ffi->EVP_PKEY_get_bits($pkey);
            if ($bits <= 0) {
                return false;
            }
            $pubPem = self::writePublicKeyPem($ffi, $pkey);
            if (false === $pubPem) {
                return false;
            }

            $baseId = (int) $ffi->EVP_PKEY_get_base_id($pkey);
            $type = match ($baseId) {
                self::EVP_PKEY_RSA, self::EVP_PKEY_RSA2 => OpensslConstants::OPENSSL_KEYTYPE_RSA,
                self::EVP_PKEY_DSA, self::EVP_PKEY_DSA1, self::EVP_PKEY_DSA2, self::EVP_PKEY_DSA3, self::EVP_PKEY_DSA4 => OpensslConstants::OPENSSL_KEYTYPE_DSA,
                self::EVP_PKEY_DH => OpensslConstants::OPENSSL_KEYTYPE_DH,
                self::EVP_PKEY_EC => OpensslConstants::OPENSSL_KEYTYPE_EC,
                default => -1,
            };

            $details = [
                'bits' => $bits,
                'key' => $pubPem,
                'type' => $type,
            ];

            if (OpensslConstants::OPENSSL_KEYTYPE_RSA === $type) {
                $rsaDetails = self::rsaDetails($ffi, $pkey);
                if (null !== $rsaDetails) {
                    $details['rsa'] = $rsaDetails;
                }
            } elseif (OpensslConstants::OPENSSL_KEYTYPE_EC === $type) {
                $ecDetails = self::ecDetails($ffi, $pkey);
                if (null !== $ecDetails) {
                    $details['ec'] = $ecDetails;
                }
            } elseif (OpensslConstants::OPENSSL_KEYTYPE_DH === $type) {
                $dhDetails = self::dhDetails($ffi, $pkey);
                if (null !== $dhDetails) {
                    $details['dh'] = $dhDetails;
                }
            }

            return $details;
        } finally {
            $ffi->EVP_PKEY_free($pkey);
        }
    }

    /**
     * @param \FFI $ffi
     *
     * @return \FFI\CData|null
     */
    private static function readPublicKey($ffi, string $pem)
    {
        $bio = $ffi->BIO_new_mem_buf($pem, \strlen($pem));
        if (null === $bio) {
            return null;
        }

        try {
            return $ffi->PEM_read_bio_PUBKEY($bio, null, null, null);
        } finally {
            $ffi->BIO_free($bio);
        }
    }

    /**
     * @param \FFI $ffi
     *
     * @return \FFI\CData|null
     */
    private static function readAnyKey($ffi, string $pem)
    {
        $pub = self::readPublicKey($ffi, $pem);
        if (null !== $pub) {
            return $pub;
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

    /**
     * @param \FFI $ffi
     * @param \FFI\CData $pkey
     *
     * @return array<string, string>|null
     */
    private static function rsaDetails($ffi, $pkey): ?array
    {
        $rsa = $ffi->EVP_PKEY_get0_RSA($pkey);
        if (null === $rsa) {
            return null;
        }

        $details = [];
        foreach ([
            'n' => 'RSA_get0_n',
            'e' => 'RSA_get0_e',
            'd' => 'RSA_get0_d',
            'p' => 'RSA_get0_p',
            'q' => 'RSA_get0_q',
            'dmp1' => 'RSA_get0_dmp1',
            'dmq1' => 'RSA_get0_dmq1',
            'iqmp' => 'RSA_get0_iqmp',
        ] as $key => $getter) {
            $bn = $ffi->$getter($rsa);
            if (null === $bn) {
                continue;
            }
            $bin = self::bn2bin($ffi, $bn);
            if (null === $bin) {
                continue;
            }
            $details[$key] = $bin;
        }

        return [] === $details ? null : $details;
    }

    /**
     * @param \FFI $ffi
     * @param \FFI\CData $pkey
     *
     * @return array<string, string>|null
     */
    private static function ecDetails($ffi, $pkey): ?array
    {
        $ecKey = $ffi->EVP_PKEY_get0_EC_KEY($pkey);
        if (null === $ecKey) {
            return null;
        }

        $group = $ffi->EC_KEY_get0_group($ecKey);
        if (null === $group) {
            return null;
        }

        $nid = (int) $ffi->EC_GROUP_get_curve_name($group);
        if (0 === $nid) {
            return null;
        }

        $details = [];
        $sn = $ffi->OBJ_nid2sn($nid);
        if (null !== $sn && '' !== $sn) {
            $details['curve_name'] = \is_string($sn) ? $sn : \FFI::string($sn);
        }

        $obj = $ffi->OBJ_nid2obj($nid);
        if (null !== $obj) {
            $buf = $ffi->new('char[80]');
            $len = (int) $ffi->OBJ_obj2txt($buf, 80, $obj, 1);
            $ffi->ASN1_OBJECT_free($obj);
            if ($len > 0) {
                $details['curve_oid'] = \FFI::string($buf, $len);
            }
        }

        $pub = $ffi->EC_KEY_get0_public_key($ecKey);
        if (null !== $pub) {
            $x = $ffi->BN_new();
            $y = $ffi->BN_new();
            if (null !== $x && null !== $y) {
                try {
                    if (1 === (int) $ffi->EC_POINT_get_affine_coordinates($group, $pub, $x, $y, null)) {
                        $xBin = self::bn2bin($ffi, $x);
                        $yBin = self::bn2bin($ffi, $y);
                        if (null !== $xBin) {
                            $details['x'] = $xBin;
                        }
                        if (null !== $yBin) {
                            $details['y'] = $yBin;
                        }
                    }
                } finally {
                    $ffi->BN_free($x);
                    $ffi->BN_free($y);
                }
            }
        }

        $d = $ffi->EC_KEY_get0_private_key($ecKey);
        if (null !== $d) {
            $dBin = self::bn2bin($ffi, $d);
            if (null !== $dBin) {
                $details['d'] = $dBin;
            }
        }

        return [] === $details ? null : $details;
    }

    /**
     * @param \FFI $ffi
     * @param \FFI\CData $pkey
     *
     * @return array<string, string>|null
     */
    private static function dhDetails($ffi, $pkey): ?array
    {
        $dh = $ffi->EVP_PKEY_get0_DH($pkey);
        if (null === $dh) {
            return null;
        }

        $pPtr = $ffi->new('const BIGNUM *[1]');
        $qPtr = $ffi->new('const BIGNUM *[1]');
        $gPtr = $ffi->new('const BIGNUM *[1]');
        $pPtr[0] = null;
        $qPtr[0] = null;
        $gPtr[0] = null;
        $ffi->DH_get0_pqg($dh, $pPtr, $qPtr, $gPtr);

        $pubPtr = $ffi->new('const BIGNUM *[1]');
        $privPtr = $ffi->new('const BIGNUM *[1]');
        $pubPtr[0] = null;
        $privPtr[0] = null;
        $ffi->DH_get0_key($dh, $pubPtr, $privPtr);

        $details = [];
        foreach ([
            'p' => $pPtr[0],
            'g' => $gPtr[0],
            'priv_key' => $privPtr[0],
            'pub_key' => $pubPtr[0],
        ] as $key => $bn) {
            if (null === $bn) {
                continue;
            }
            $bin = self::bn2bin($ffi, $bn);
            if (null === $bin) {
                continue;
            }
            $details[$key] = $bin;
        }

        return [] === $details ? null : $details;
    }

    /**
     * @param \FFI $ffi
     * @param \FFI\CData $bn
     */
    private static function bn2bin($ffi, $bn): ?string
    {
        $bits = (int) $ffi->BN_num_bits($bn);
        if ($bits <= 0) {
            return '';
        }
        $len = intdiv($bits + 7, 8);
        if ($len <= 0) {
            return '';
        }
        $buf = $ffi->new("unsigned char[{$len}]");
        $written = (int) $ffi->BN_bn2bin($bn, $buf);
        if ($written <= 0) {
            return null;
        }

        return \FFI::string($buf, $written);
    }

    /**
     * @param \FFI       $ffi
     * @param \FFI\CData $pkey
     */
    private static function writePublicKeyPem($ffi, $pkey): string|false
    {
        $tmp = \tempnam(\sys_get_temp_dir(), 'phpc-pubkey-');
        if (false === $tmp) {
            return false;
        }

        $bio = $ffi->BIO_new_file($tmp, 'wb');
        if (null === $bio) {
            @\unlink($tmp);

            return false;
        }

        try {
            if (1 !== (int) $ffi->PEM_write_bio_PUBKEY($bio, $pkey)) {
                return false;
            }
        } finally {
            $ffi->BIO_free($bio);
        }

        $pem = VmFsReadNative::read($tmp);
        @\unlink($tmp);
        if (false === $pem || '' === $pem) {
            return false;
        }

        return $pem;
    }

    public static function encrypt(string $data, string $publicKeyPem, int $padding): string|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $pkey = self::readAnyKey($ffi, $publicKeyPem);
        if (null === $pkey) {
            return false;
        }

        $ctx = $ffi->EVP_PKEY_CTX_new($pkey, null);
        if (null === $ctx) {
            $ffi->EVP_PKEY_free($pkey);

            return false;
        }

        try {
            if (1 !== (int) $ffi->EVP_PKEY_encrypt_init($ctx)) {
                return false;
            }
            if (1 !== (int) $ffi->EVP_PKEY_CTX_set_rsa_padding($ctx, $padding)) {
                return false;
            }

            $inLen = \strlen($data);
            if ($inLen <= 0) {
                return false;
            }
            $inBuf = $ffi->new("unsigned char[{$inLen}]");
            \FFI::memcpy($inBuf, $data, $inLen);
            $outlen = $ffi->new('size_t');
            $outlen->cdata = 0;
            if (1 !== (int) $ffi->EVP_PKEY_encrypt($ctx, null, \FFI::addr($outlen), $inBuf, $inLen)) {
                return false;
            }

            $length = (int) $outlen->cdata;
            if ($length <= 0) {
                return false;
            }

            $buf = $ffi->new("unsigned char[{$length}]");
            if (1 !== (int) $ffi->EVP_PKEY_encrypt($ctx, $buf, \FFI::addr($outlen), $inBuf, $inLen)) {
                return false;
            }

            return \FFI::string($buf, (int) $outlen->cdata);
        } finally {
            $ffi->EVP_PKEY_CTX_free($ctx);
            $ffi->EVP_PKEY_free($pkey);
        }
    }

    public static function decrypt(string $data, string $privateKeyPem, int $padding): string|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $pkey = self::readPrivateKey($ffi, $privateKeyPem, null);
        if (null === $pkey) {
            return false;
        }

        $ctx = $ffi->EVP_PKEY_CTX_new($pkey, null);
        if (null === $ctx) {
            $ffi->EVP_PKEY_free($pkey);

            return false;
        }

        try {
            if (1 !== (int) $ffi->EVP_PKEY_decrypt_init($ctx)) {
                return false;
            }
            if (1 !== (int) $ffi->EVP_PKEY_CTX_set_rsa_padding($ctx, $padding)) {
                return false;
            }

            $inLen = \strlen($data);
            if ($inLen <= 0) {
                return false;
            }
            $inBuf = $ffi->new("unsigned char[{$inLen}]");
            \FFI::memcpy($inBuf, $data, $inLen);
            $outlen = $ffi->new('size_t');
            $outlen->cdata = 0;
            if (1 !== (int) $ffi->EVP_PKEY_decrypt($ctx, null, \FFI::addr($outlen), $inBuf, $inLen)) {
                return false;
            }

            $length = (int) $outlen->cdata;
            if ($length <= 0) {
                return false;
            }

            $buf = $ffi->new("unsigned char[{$length}]");
            if (1 !== (int) $ffi->EVP_PKEY_decrypt($ctx, $buf, \FFI::addr($outlen), $inBuf, $inLen)) {
                return false;
            }

            return \FFI::string($buf, (int) $outlen->cdata);
        } finally {
            $ffi->EVP_PKEY_CTX_free($ctx);
            $ffi->EVP_PKEY_free($pkey);
        }
    }

    public static function privateEncrypt(string $data, string $privateKeyPem, int $padding): string|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $pkey = self::readPrivateKey($ffi, $privateKeyPem, null);
        if (null === $pkey) {
            return false;
        }

        $ctx = $ffi->EVP_PKEY_CTX_new($pkey, null);
        if (null === $ctx) {
            $ffi->EVP_PKEY_free($pkey);

            return false;
        }

        try {
            if (1 !== (int) $ffi->EVP_PKEY_sign_init($ctx)) {
                return false;
            }
            if (1 !== (int) $ffi->EVP_PKEY_CTX_set_rsa_padding($ctx, $padding)) {
                return false;
            }

            $inLen = \strlen($data);
            if ($inLen <= 0) {
                return false;
            }
            $inBuf = $ffi->new("unsigned char[{$inLen}]");
            \FFI::memcpy($inBuf, $data, $inLen);
            $outlen = $ffi->new('size_t');
            $outlen->cdata = 0;
            if (1 !== (int) $ffi->EVP_PKEY_sign($ctx, null, \FFI::addr($outlen), $inBuf, $inLen)) {
                return false;
            }

            $length = (int) $outlen->cdata;
            if ($length <= 0) {
                return false;
            }

            $buf = $ffi->new("unsigned char[{$length}]");
            if (1 !== (int) $ffi->EVP_PKEY_sign($ctx, $buf, \FFI::addr($outlen), $inBuf, $inLen)) {
                return false;
            }

            return \FFI::string($buf, (int) $outlen->cdata);
        } finally {
            $ffi->EVP_PKEY_CTX_free($ctx);
            $ffi->EVP_PKEY_free($pkey);
        }
    }

    public static function publicDecrypt(string $data, string $publicKeyPem, int $padding): string|false
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $pkey = self::readAnyKey($ffi, $publicKeyPem);
        if (null === $pkey) {
            return false;
        }

        $ctx = $ffi->EVP_PKEY_CTX_new($pkey, null);
        if (null === $ctx) {
            $ffi->EVP_PKEY_free($pkey);

            return false;
        }

        try {
            if (1 !== (int) $ffi->EVP_PKEY_verify_recover_init($ctx)) {
                return false;
            }
            if (1 !== (int) $ffi->EVP_PKEY_CTX_set_rsa_padding($ctx, $padding)) {
                return false;
            }

            $inLen = \strlen($data);
            if ($inLen <= 0) {
                return false;
            }
            $inBuf = $ffi->new("unsigned char[{$inLen}]");
            \FFI::memcpy($inBuf, $data, $inLen);
            $outlen = $ffi->new('size_t');
            $outlen->cdata = 0;
            if (1 !== (int) $ffi->EVP_PKEY_verify_recover($ctx, null, \FFI::addr($outlen), $inBuf, $inLen)) {
                return false;
            }

            $length = (int) $outlen->cdata;
            if ($length <= 0) {
                return false;
            }

            $buf = $ffi->new("unsigned char[{$length}]");
            if (1 !== (int) $ffi->EVP_PKEY_verify_recover($ctx, $buf, \FFI::addr($outlen), $inBuf, $inLen)) {
                return false;
            }

            return \FFI::string($buf, (int) $outlen->cdata);
        } finally {
            $ffi->EVP_PKEY_CTX_free($ctx);
            $ffi->EVP_PKEY_free($pkey);
        }
    }

    /**
     * @param \FFI $ffi
     *
     * @return \FFI\CData|null
     */
    private static function readPrivateKey($ffi, string $pem, ?string $passphrase)
    {
        $bio = $ffi->BIO_new_mem_buf($pem, \strlen($pem));
        if (null === $bio) {
            return null;
        }

        try {
            if (null !== $passphrase && '' !== $passphrase) {
                return $ffi->PEM_read_bio_PrivateKey($bio, null, null, $passphrase);
            }

            return $ffi->PEM_read_bio_PrivateKey($bio, null, null, null);
        } finally {
            $ffi->BIO_free($bio);
        }
    }

    /**
     * @param \FFI       $ffi
     * @param \FFI\CData $pkey
     */
    private static function writePrivateKeyPem($ffi, $pkey, ?string $passphrase): string|false
    {
        $tmp = \tempnam(\sys_get_temp_dir(), 'phpc-pkey-');
        if (false === $tmp) {
            return false;
        }

        $bio = $ffi->BIO_new_file($tmp, 'wb');
        if (null === $bio) {
            @\unlink($tmp);

            return false;
        }

        try {
            // php-src openssl_pkey_export(_to_file): encrypt with 3DES when passphrase set
            // (priv_key_encrypt default true; ext/openssl/openssl.c PEM_write_bio_PrivateKey).
            $cipher = null;
            $kstr = null;
            $klen = 0;
            if (null !== $passphrase && '' !== $passphrase) {
                $cipher = $ffi->EVP_des_ede3_cbc();
                $klen = \strlen($passphrase);
                $kstr = $ffi->new('unsigned char['.$klen.']');
                \FFI::memcpy($kstr, $passphrase, $klen);
            }
            if (1 !== (int) $ffi->PEM_write_bio_PrivateKey($bio, $pkey, $cipher, $kstr, $klen, null, null)) {
                return false;
            }
        } finally {
            $ffi->BIO_free($bio);
        }

        $pem = VmFsReadNative::read($tmp);
        @\unlink($tmp);
        if (false === $pem || '' === $pem) {
            return false;
        }

        return $pem;
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
typedef struct evp_pkey_ctx_st EVP_PKEY_CTX;
typedef struct evp_cipher_st EVP_CIPHER;
typedef struct rsa_st RSA;
typedef struct bignum_st BIGNUM;
typedef struct ec_key_st EC_KEY;
typedef struct ec_group_st EC_GROUP;
typedef struct ec_point_st EC_POINT;
typedef struct dh_st DH;
typedef struct asn1_object_st ASN1_OBJECT;

BIO *BIO_new_mem_buf(const void *buf, int len);
BIO *BIO_new_file(const char *filename, const char *mode);
void BIO_free(BIO *a);
EVP_PKEY *PEM_read_bio_PrivateKey(BIO *bp, EVP_PKEY **x, void *cb, void *u);
EVP_PKEY *PEM_read_bio_PUBKEY(BIO *bp, EVP_PKEY **x, void *cb, void *u);
const EVP_CIPHER *EVP_des_ede3_cbc(void);
void EVP_PKEY_free(EVP_PKEY *pkey);
EVP_PKEY *EVP_PKEY_new(void);
EVP_PKEY_CTX *EVP_PKEY_CTX_new_id(int id, void *e);
EVP_PKEY_CTX *EVP_PKEY_CTX_new(EVP_PKEY *pkey, void *e);
void EVP_PKEY_CTX_free(EVP_PKEY_CTX *ctx);
int EVP_PKEY_keygen_init(EVP_PKEY_CTX *ctx);
int EVP_PKEY_CTX_set_rsa_keygen_bits(EVP_PKEY_CTX *ctx, int bits);
int EVP_PKEY_keygen(EVP_PKEY_CTX *ctx, EVP_PKEY **ppkey);
int EVP_PKEY_paramgen_init(EVP_PKEY_CTX *ctx);
int EVP_PKEY_paramgen(EVP_PKEY_CTX *ctx, EVP_PKEY **ppkey);
int EVP_PKEY_CTX_ctrl(EVP_PKEY_CTX *ctx, int keytype, int optype, int cmd, int p1, void *p2);
int EVP_PKEY_encrypt_init(EVP_PKEY_CTX *ctx);
int EVP_PKEY_encrypt(EVP_PKEY_CTX *ctx, unsigned char *out, size_t *outlen,
    const unsigned char *in, size_t inlen);
int EVP_PKEY_decrypt_init(EVP_PKEY_CTX *ctx);
int EVP_PKEY_decrypt(EVP_PKEY_CTX *ctx, unsigned char *out, size_t *outlen,
    const unsigned char *in, size_t inlen);
int EVP_PKEY_sign_init(EVP_PKEY_CTX *ctx);
int EVP_PKEY_sign(EVP_PKEY_CTX *ctx, unsigned char *sig, size_t *siglen,
    const unsigned char *tbs, size_t tbslen);
int EVP_PKEY_verify_recover_init(EVP_PKEY_CTX *ctx);
int EVP_PKEY_verify_recover(EVP_PKEY_CTX *ctx, unsigned char *rout, size_t *routlen,
    const unsigned char *sig, size_t siglen);
int EVP_PKEY_CTX_set_rsa_padding(EVP_PKEY_CTX *ctx, int pad);
int EVP_PKEY_get_bits(const EVP_PKEY *pkey);
int EVP_PKEY_get_base_id(const EVP_PKEY *pkey);
int EVP_PKEY_set1_EC_KEY(EVP_PKEY *pkey, EC_KEY *key);
int EVP_PKEY_set1_DH(EVP_PKEY *pkey, DH *key);
EC_KEY *EVP_PKEY_get0_EC_KEY(EVP_PKEY *pkey);
DH *EVP_PKEY_get0_DH(EVP_PKEY *pkey);
RSA *EVP_PKEY_get0_RSA(EVP_PKEY *pkey);
BIGNUM *RSA_get0_n(const RSA *d);
BIGNUM *RSA_get0_e(const RSA *d);
BIGNUM *RSA_get0_d(const RSA *d);
BIGNUM *RSA_get0_p(const RSA *d);
BIGNUM *RSA_get0_q(const RSA *d);
BIGNUM *RSA_get0_dmp1(const RSA *d);
BIGNUM *RSA_get0_dmq1(const RSA *d);
BIGNUM *RSA_get0_iqmp(const RSA *d);
BIGNUM *BN_new(void);
void BN_free(BIGNUM *a);
BIGNUM *BN_bin2bn(const unsigned char *s, int len, BIGNUM *ret);
int BN_num_bits(const BIGNUM *a);
int BN_bn2bin(const BIGNUM *a, unsigned char *to);
int OBJ_sn2nid(const char *s);
const char *OBJ_nid2sn(int n);
ASN1_OBJECT *OBJ_nid2obj(int n);
int OBJ_obj2txt(char *buf, int buf_len, const ASN1_OBJECT *a, int no_name);
void ASN1_OBJECT_free(ASN1_OBJECT *a);
EC_KEY *EC_KEY_new_by_curve_name(int nid);
int EC_KEY_generate_key(EC_KEY *key);
void EC_KEY_free(EC_KEY *key);
const EC_GROUP *EC_KEY_get0_group(const EC_KEY *key);
const EC_POINT *EC_KEY_get0_public_key(const EC_KEY *key);
const BIGNUM *EC_KEY_get0_private_key(const EC_KEY *key);
int EC_GROUP_get_curve_name(const EC_GROUP *group);
int EC_POINT_get_affine_coordinates(const EC_GROUP *group, const EC_POINT *p,
    BIGNUM *x, BIGNUM *y, void *ctx);
DH *DH_new(void);
void DH_free(DH *dh);
int DH_set0_pqg(DH *dh, BIGNUM *p, BIGNUM *q, BIGNUM *g);
int DH_generate_key(DH *dh);
void DH_get0_pqg(const DH *dh, const BIGNUM **p, const BIGNUM **q, const BIGNUM **g);
void DH_get0_key(const DH *dh, const BIGNUM **pub_key, const BIGNUM **priv_key);
int PEM_write_bio_PrivateKey(BIO *bp, EVP_PKEY *x, const EVP_CIPHER *enc,
    const unsigned char *kstr, int klen, void *cb, void *u);
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
}
