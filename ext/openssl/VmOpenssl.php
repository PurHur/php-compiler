<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\ext\standard\VmFsReadNative;
use PHPCompiler\ext\standard\VmHash;
use PHPCompiler\ext\standard\VmHashNative;
use PHPCompiler\Frame;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * OpenSSL VM helpers without host ext/openssl delegation (#6228, #7331).
 *
 * php-src: ext/openssl/openssl.c
 */
final class VmOpenssl
{
    /**
     * openssl_cipher_iv_length() — required IV length for a cipher.
     *
     * @return int|false
     */
    public static function cipher_iv_length(string $cipherAlgo, ?Frame $frame = null): int|false
    {
        $length = OpensslCipherRegistry::cipherIvLength($cipherAlgo);
        if (false === $length) {
            self::userWarning('openssl_cipher_iv_length(): Unknown cipher algorithm', $frame);
        }

        return $length;
    }

    /**
     * openssl_cipher_key_length() — required key length for a cipher.
     *
     * @return int|false
     */
    public static function cipher_key_length(string $cipherAlgo, ?Frame $frame = null): int|false
    {
        $length = OpensslCipherRegistry::cipherKeyLength($cipherAlgo);
        if (false === $length) {
            self::userWarning('openssl_cipher_key_length(): Unknown cipher algorithm', $frame);
        }

        return $length;
    }

    public static function cipherMethods(bool $aliases = false): HashTable
    {
        return self::stringListToHashTable(OpensslCipherRegistry::cipherMethods($aliases));
    }

    public static function mdMethods(bool $aliases = false): HashTable
    {
        return self::stringListToHashTable(OpensslCipherRegistry::mdMethods($aliases));
    }

    /**
     * openssl_get_cert_locations() — X509 default path metadata (php-src ext/openssl/openssl.c; #6560).
     */
    public static function certLocations(): HashTable
    {
        $locations = VmOpensslConfigNative::certLocations();
        if (null === $locations) {
            return self::assocStringArrayToHashTable([
                'default_cert_file' => '',
                'default_cert_file_env' => 'SSL_CERT_FILE',
                'default_cert_dir' => '',
                'default_cert_dir_env' => 'SSL_CERT_DIR',
                'default_private_dir' => '',
                'default_default_cert_area' => '',
                'ini_cafile' => '',
                'ini_capath' => '',
            ]);
        }

        return self::assocStringArrayToHashTable($locations);
    }

    /**
     * openssl_get_curve_names() — OBJ_nid2sn names from EC_get_builtin_curves (#6560).
     */
    public static function curveNames(): HashTable
    {
        $names = VmOpensslConfigNative::curveNames();
        if (null === $names) {
            return new HashTable();
        }

        return self::stringListToHashTable($names);
    }

    /**
     * openssl_digest() — one-shot digest (EVP_Digest parity via VmHashNative).
     *
     * @return string|false
     */
    public static function digest(string $data, string $method, bool $rawOutput = false, ?Frame $frame = null): string|false
    {
        if (!OpensslCipherRegistry::digestImplemented($method)) {
            self::userWarning('openssl_digest(): Unknown digest algorithm', $frame);

            return false;
        }
        try {
            return VmHash::hash(strtolower($method), $data, $rawOutput);
        } catch (\ValueError) {
            self::userWarning('openssl_digest(): Unknown digest algorithm', $frame);

            return false;
        }
    }

    /**
     * openssl_pbkdf2() — PKCS#5 PBKDF2 via VmHashNative (php-src ext/openssl/kdf.c; #6488).
     *
     * @return string|false raw key bytes
     */
    public static function pbkdf2(
        string $password,
        string $salt,
        int $keyLength,
        int $iterations,
        string $digestAlgo = 'sha1',
        ?Frame $frame = null
    ): string|false {
        if ($keyLength <= 0) {
            throw new \ValueError('openssl_pbkdf2(): Argument #3 ($key_length) must be greater than 0');
        }
        if ($iterations <= 0) {
            return false;
        }
        $method = strtolower($digestAlgo);
        if (!OpensslCipherRegistry::digestImplemented($method)) {
            self::userWarning('openssl_pbkdf2(): Unknown digest algorithm', $frame);

            return false;
        }
        $derived = VmHashNative::hashPbkdf2($method, $password, $salt, $iterations, $keyLength, true);
        if ('' === $derived) {
            return false;
        }

        return $derived;
    }

    /**
     * openssl_sign() — EVP_DigestSign via libcrypto FFI (#11535).
     *
     * @return string|false signature bytes
     */
    public static function sign(string $data, string $privateKeyPem, int|string $algorithm, ?Frame $frame = null): string|false
    {
        $digestName = self::resolveDigestName($algorithm, 'openssl_sign', $frame);
        if (false === $digestName) {
            return false;
        }
        if (!VmOpensslSignNative::available()) {
            self::userWarning('openssl_sign(): OpenSSL signing is unavailable in this compiler build', $frame);

            return false;
        }

        return VmOpensslSignNative::sign($data, $privateKeyPem, $digestName);
    }

    /**
     * openssl_verify() — EVP_DigestVerify via libcrypto FFI (#11535).
     *
     * @return int 1 valid, 0 invalid, -1 error
     */
    public static function verify(string $data, string $signature, string $publicKeyPem, int|string $algorithm, ?Frame $frame = null): int
    {
        $digestName = self::resolveDigestName($algorithm, 'openssl_verify', $frame);
        if (false === $digestName) {
            return -1;
        }
        if (!VmOpensslSignNative::available()) {
            self::userWarning('openssl_verify(): OpenSSL verification is unavailable in this compiler build', $frame);

            return -1;
        }

        return VmOpensslSignNative::verify($data, $signature, $publicKeyPem, $digestName);
    }

    /**
     * openssl_public_encrypt() — EVP_PKEY_encrypt (php-src ext/openssl/xp.c; #6666).
     *
     * @return string|false ciphertext bytes
     */
    public static function publicEncrypt(
        string $data,
        string $publicKeyPem,
        int $padding,
        ?Frame $frame = null,
    ): string|false {
        if (!VmOpensslPkeyNative::available()) {
            self::userWarning('openssl_public_encrypt(): OpenSSL asymmetric encryption is unavailable in this compiler build', $frame);

            return false;
        }

        $encrypted = VmOpensslPkeyNative::encrypt($data, $publicKeyPem, $padding);
        if (false === $encrypted) {
            self::userWarning('openssl_public_encrypt(): Encryption failed', $frame);
        }

        return $encrypted;
    }

    /**
     * openssl_private_decrypt() — EVP_PKEY_decrypt (php-src ext/openssl/xp.c; #6666).
     *
     * @return string|false plaintext bytes
     */
    public static function privateDecrypt(
        string $data,
        string $privateKeyPem,
        int $padding,
        ?Frame $frame = null,
    ): string|false {
        if (!VmOpensslPkeyNative::available()) {
            self::userWarning('openssl_private_decrypt(): OpenSSL asymmetric decryption is unavailable in this compiler build', $frame);

            return false;
        }

        $decrypted = VmOpensslPkeyNative::decrypt($data, $privateKeyPem, $padding);
        if (false === $decrypted) {
            self::userWarning('openssl_private_decrypt(): Decryption failed', $frame);
        }

        return $decrypted;
    }

    /**
     * openssl_private_encrypt() — EVP_PKEY_sign (php-src ext/openssl/xp.c; #6666).
     *
     * @return string|false ciphertext bytes
     */
    public static function privateEncrypt(
        string $data,
        string $privateKeyPem,
        int $padding,
        ?Frame $frame = null,
    ): string|false {
        if (!VmOpensslPkeyNative::available()) {
            self::userWarning('openssl_private_encrypt(): OpenSSL asymmetric encryption is unavailable in this compiler build', $frame);

            return false;
        }

        $encrypted = VmOpensslPkeyNative::privateEncrypt($data, $privateKeyPem, $padding);
        if (false === $encrypted) {
            self::userWarning('openssl_private_encrypt(): Encryption failed', $frame);
        }

        return $encrypted;
    }

    /**
     * openssl_public_decrypt() — EVP_PKEY_verify_recover (php-src ext/openssl/xp.c; #6666).
     *
     * @return string|false plaintext bytes
     */
    public static function publicDecrypt(
        string $data,
        string $publicKeyPem,
        int $padding,
        ?Frame $frame = null,
    ): string|false {
        if (!VmOpensslPkeyNative::available()) {
            self::userWarning('openssl_public_decrypt(): OpenSSL asymmetric decryption is unavailable in this compiler build', $frame);

            return false;
        }

        $decrypted = VmOpensslPkeyNative::publicDecrypt($data, $publicKeyPem, $padding);
        if (false === $decrypted) {
            self::userWarning('openssl_public_decrypt(): Decryption failed', $frame);
        }

        return $decrypted;
    }

    /**
     * openssl_spki_new() — create Netscape SPKAC (php-src ext/openssl/openssl.c; #8690).
     *
     * @return string|false SPKAC=… encoded certificate request
     */
    public static function spkiNew(
        Variable $keyArg,
        string $challenge,
        int|string $algorithm,
        ?Frame $frame = null
    ): string|false {
        if (!VmOpensslSpkiNative::available()) {
            self::userWarning('openssl_spki_new(): OpenSSL SPKI is unavailable in this compiler build', $frame);

            return false;
        }

        $pem = self::coercePkeyPem($keyArg, 'openssl_spki_new', 0, 'private_key');
        $digestName = self::resolveDigestName($algorithm, 'openssl_spki_new', $frame);
        if (false === $digestName) {
            return false;
        }

        $spkac = VmOpensslSpkiNative::spkiNew($pem, $challenge, $digestName);
        if (false === $spkac) {
            self::userWarning('openssl_spki_new(): Unable to create new SPKAC', $frame);

            return false;
        }

        return $spkac;
    }

    /**
     * openssl_spki_verify() — verify Netscape SPKAC (php-src ext/openssl/openssl.c; #8690).
     */
    public static function spkiVerify(string $spkac, ?Frame $frame = null): bool
    {
        if (!VmOpensslSpkiNative::available()) {
            self::userWarning('openssl_spki_verify(): OpenSSL SPKI is unavailable in this compiler build', $frame);

            return false;
        }

        $cleaned = VmOpensslSpkiNative::spkiCleanup($spkac);
        if ('' === $cleaned) {
            self::userWarning('openssl_spki_verify(): Invalid SPKAC', $frame);

            return false;
        }

        $verified = VmOpensslSpkiNative::spkiVerify($spkac);
        if (!$verified) {
            self::userWarning('openssl_spki_verify(): Unable to decode supplied SPKAC', $frame);
        }

        return $verified;
    }

    /**
     * openssl_spki_export() — PEM public key from Netscape SPKAC (php-src ext/openssl/openssl.c; #6423).
     */
    public static function spkiExport(string $spkac, ?Frame $frame = null): string|false
    {
        if (!VmOpensslSpkiNative::available()) {
            self::userWarning('openssl_spki_export(): OpenSSL SPKI is unavailable in this compiler build', $frame);

            return false;
        }

        $cleaned = VmOpensslSpkiNative::spkiCleanup($spkac);
        if ('' === $cleaned) {
            self::userWarning('openssl_spki_export(): Invalid SPKAC', $frame);

            return false;
        }

        $pem = VmOpensslSpkiNative::spkiExport($spkac);
        if (false === $pem) {
            if (!VmOpensslSpkiNative::spkiDecodeable($spkac)) {
                self::userWarning('openssl_spki_export(): Unable to decode supplied SPKAC', $frame);
            } else {
                self::userWarning('openssl_spki_export(): Unable to acquire signed public key', $frame);
            }
        }

        return $pem;
    }

    /**
     * openssl_spki_export_challenge() — challenge string from Netscape SPKAC (php-src ext/openssl/openssl.c; #6423).
     */
    public static function spkiExportChallenge(string $spkac, ?Frame $frame = null): string|false
    {
        if (!VmOpensslSpkiNative::available()) {
            self::userWarning('openssl_spki_export_challenge(): OpenSSL SPKI is unavailable in this compiler build', $frame);

            return false;
        }

        $cleaned = VmOpensslSpkiNative::spkiCleanup($spkac);
        if ('' === $cleaned) {
            self::userWarning('openssl_spki_export_challenge(): Invalid SPKAC', $frame);

            return false;
        }

        $challenge = VmOpensslSpkiNative::spkiExportChallenge($spkac);
        if (false === $challenge) {
            self::userWarning('openssl_spki_export_challenge(): Unable to decode SPKAC', $frame);
        }

        return $challenge;
    }

    /**
     * openssl_seal() — public-key envelope encryption (php-src ext/openssl/openssl.c; #6523).
     *
     * @param list<string> $publicKeyPems
     *
     * @return array{length: int, sealed: string, encrypted_keys: list<string>, iv: string}|false
     */
    public static function seal(
        string $data,
        array $publicKeyPems,
        string $cipherAlgo,
        bool $assignIv,
        ?Frame $frame = null
    ): array|false {
        if (!VmOpensslSealNative::available()) {
            self::userWarning('openssl_seal(): OpenSSL envelope encryption is unavailable in this compiler build', $frame);

            return false;
        }

        $ivLen = OpensslCipherRegistry::cipherIvLength($cipherAlgo);
        if (false === $ivLen) {
            self::userWarning('openssl_seal(): Unknown cipher algorithm', $frame);

            return false;
        }
        if ($ivLen > 0 && !$assignIv) {
            throw new \ValueError('openssl_seal(): Argument #6 ($iv) cannot be null for the chosen cipher algorithm');
        }

        if ([] === $publicKeyPems) {
            throw new \ValueError('openssl_seal(): Argument #4 ($public_key) cannot be empty');
        }

        $result = VmOpensslSealNative::seal($data, $publicKeyPems, $cipherAlgo, $assignIv);
        if (false === $result) {
            self::userWarning('openssl_seal(): Seal operation failed', $frame);

            return false;
        }

        return $result;
    }

    /**
     * openssl_open() — decrypt openssl_seal() output (php-src ext/openssl/openssl.c; #6523).
     */
    public static function open(
        string $sealedData,
        string $encryptedKey,
        string $privateKeyPem,
        string $cipherAlgo,
        ?string $iv,
        ?Frame $frame = null
    ): string|false {
        if (!VmOpensslSealNative::available()) {
            self::userWarning('openssl_open(): OpenSSL envelope decryption is unavailable in this compiler build', $frame);

            return false;
        }

        $ivLen = OpensslCipherRegistry::cipherIvLength($cipherAlgo);
        if (false === $ivLen) {
            self::userWarning('openssl_open(): Unknown cipher algorithm', $frame);

            return false;
        }
        if ($ivLen > 0) {
            if (null === $iv) {
                throw new \ValueError('openssl_open(): Argument #6 ($iv) cannot be null for the chosen cipher algorithm');
            }
            if (\strlen($iv) !== $ivLen) {
                self::userWarning('openssl_open(): IV length is invalid', $frame);

                return false;
            }
        }

        $plain = VmOpensslSealNative::open($sealedData, $encryptedKey, $privateKeyPem, $cipherAlgo, $iv);
        if (false === $plain) {
            self::userWarning('openssl_open(): Open operation failed', $frame);

            return false;
        }

        return $plain;
    }

    /**
     * openssl_encrypt() — symmetric EVP cipher (php-src ext/openssl/openssl.c; #18594, AEAD #21135).
     *
     * @return string|false ciphertext bytes (caller applies base64 unless OPENSSL_RAW_DATA)
     */
    /**
     * Pad/truncate key + IV like php-src {@code php_openssl_cipher_init} / {@code php_openssl_validate_iv}
     * (ext/openssl/openssl_backend_common.c; #22326).
     *
     * @return array{0: string, 1: string}|false [passphrase, iv]
     */
    public static function normalizeCipherKeyAndIv(
        string $funcName,
        string $passphrase,
        string $iv,
        int $keyLen,
        int $ivLen,
        bool $isAead,
        int $options,
        bool $isEncrypt,
        ?Frame $frame
    ): array|false {
        if (!$isAead && $ivLen > 0) {
            $ivBytes = \strlen($iv);
            if (0 === $ivBytes) {
                if ($isEncrypt) {
                    self::userWarning(
                        $funcName.'(): Using an empty Initialization Vector (iv) is potentially insecure and not recommended',
                        $frame
                    );
                }
                $iv = \str_repeat("\0", $ivLen);
            } elseif ($ivBytes < $ivLen) {
                self::userWarning(\sprintf(
                    '%s(): IV passed is only %d bytes long, cipher expects an IV of precisely %d bytes, padding with \\0',
                    $funcName,
                    $ivBytes,
                    $ivLen
                ), $frame);
                $iv .= \str_repeat("\0", $ivLen - $ivBytes);
            } elseif ($ivBytes > $ivLen) {
                self::userWarning(\sprintf(
                    '%s(): IV passed is %d bytes long which is longer than the %d expected by selected cipher, truncating',
                    $funcName,
                    $ivBytes,
                    $ivLen
                ), $frame);
                $iv = \substr($iv, 0, $ivLen);
            }
        }

        $passLen = \strlen($passphrase);
        if ($keyLen > $passLen) {
            if (0 !== ($options & OpensslConstants::OPENSSL_DONT_ZERO_PAD_KEY)) {
                // Fixed-length ciphers (AES) cannot adopt a shorter key — php-src EVP_CIPHER_CTX_set_key_length fails.
                self::userWarning($funcName.'(): Key length cannot be set for the cipher algorithm', $frame);

                return false;
            }
            $passphrase .= \str_repeat("\0", $keyLen - $passLen);
        } elseif ($passLen > $keyLen) {
            // Variable-length ciphers may accept longer keys via EVP; AES truncates to cipher key_len (Zend).
            $passphrase = \substr($passphrase, 0, $keyLen);
        }

        return [$passphrase, $iv];
    }

    public static function encrypt(
        string $data,
        string $cipherAlgo,
        string $passphrase,
        int $options,
        string $iv,
        ?Frame $frame = null,
        ?Variable $tagVar = null,
        string $aad = '',
        int $tagLength = 16
    ): string|false {
        if (!VmOpensslCipherNative::available()) {
            self::userWarning('openssl_encrypt(): OpenSSL cipher encryption is unavailable in this compiler build', $frame);

            return false;
        }

        $cipher = strtolower($cipherAlgo);
        $ivLen = OpensslCipherRegistry::cipherIvLength($cipher);
        if (false === $ivLen) {
            self::userWarning('openssl_encrypt(): Unknown cipher algorithm', $frame);

            return false;
        }

        $isAead = OpensslCipherRegistry::isAeadCipher($cipher);
        $keyLen = OpensslCipherRegistry::cipherKeyLength($cipher);
        if (false === $keyLen || $keyLen <= 0) {
            self::userWarning('openssl_encrypt(): Invalid key length', $frame);

            return false;
        }

        $normalized = self::normalizeCipherKeyAndIv(
            'openssl_encrypt',
            $passphrase,
            $iv,
            $keyLen,
            $ivLen,
            $isAead,
            $options,
            true,
            $frame
        );
        if (false === $normalized) {
            return false;
        }
        [$passphrase, $iv] = $normalized;

        $wantTag = null !== $tagVar;
        if ($isAead && !$wantTag) {
            self::userWarning('openssl_encrypt(): A tag should be provided when using AEAD mode', $frame);

            return false;
        }

        $zeroPadding = 0 !== ($options & OpensslConstants::OPENSSL_ZERO_PADDING);
        $encrypted = VmOpensslCipherNative::encrypt(
            $data,
            $cipher,
            $passphrase,
            $iv,
            $zeroPadding,
            $aad,
            $tagLength,
            $wantTag
        );
        if (false === $encrypted) {
            if ($isAead && $wantTag) {
                self::userWarning('openssl_encrypt(): Retrieving verification tag failed', $frame);
            } else {
                self::userWarning('openssl_encrypt(): Encryption failed', $frame);
            }

            return false;
        }

        if ($wantTag) {
            $tagTarget = $tagVar->resolveIndirect();
            if ($isAead) {
                if (null === $encrypted['tag']) {
                    self::userWarning('openssl_encrypt(): Retrieving verification tag failed', $frame);

                    return false;
                }
                $tagTarget->string($encrypted['tag']);
            } else {
                $tagTarget->null();
            }
        }

        return $encrypted['ciphertext'];
    }

    /**
     * openssl_decrypt() — symmetric EVP cipher (php-src ext/openssl/openssl.c; #18594, AEAD #21135).
     *
     * @return string|false plaintext bytes (caller decodes base64 unless OPENSSL_RAW_DATA)
     */
    public static function decrypt(
        string $data,
        string $cipherAlgo,
        string $passphrase,
        int $options,
        string $iv,
        ?Frame $frame = null,
        string $tag = '',
        string $aad = ''
    ): string|false {
        if (!VmOpensslCipherNative::available()) {
            self::userWarning('openssl_decrypt(): OpenSSL cipher decryption is unavailable in this compiler build', $frame);

            return false;
        }

        $cipher = strtolower($cipherAlgo);
        $ivLen = OpensslCipherRegistry::cipherIvLength($cipher);
        if (false === $ivLen) {
            self::userWarning('openssl_decrypt(): Unknown cipher algorithm', $frame);

            return false;
        }

        $isAead = OpensslCipherRegistry::isAeadCipher($cipher);
        $keyLen = OpensslCipherRegistry::cipherKeyLength($cipher);
        if (false === $keyLen || $keyLen <= 0) {
            self::userWarning('openssl_decrypt(): Invalid key length', $frame);

            return false;
        }

        $normalized = self::normalizeCipherKeyAndIv(
            'openssl_decrypt',
            $passphrase,
            $iv,
            $keyLen,
            $ivLen,
            $isAead,
            $options,
            false,
            $frame
        );
        if (false === $normalized) {
            return false;
        }
        [$passphrase, $iv] = $normalized;

        if ('' !== $tag && !$isAead) {
            self::userWarning(
                'openssl_decrypt(): The tag cannot be used because the cipher algorithm does not support AEAD',
                $frame
            );
        }

        $zeroPadding = 0 !== ($options & OpensslConstants::OPENSSL_ZERO_PADDING);
        $plain = VmOpensslCipherNative::decrypt(
            $data,
            $cipher,
            $passphrase,
            $iv,
            $zeroPadding,
            $aad,
            $tag
        );
        // php-src ext/openssl/openssl.c — EVP decrypt failure returns false without a user
        // warning (Zend 8.4+; #21465 after null-coerce empty ciphertext from #21445).
        if (false === $plain) {
            return false;
        }

        return $plain;
    }

    /**
     * @return list<string>|false
     */
    public static function coercePublicKeyPemList(
        Variable $arrayVar,
        string $function,
        int $argIndex,
        string $paramName,
        ?Frame $frame = null
    ): array|false {
        $arrayVar = $arrayVar->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $arrayVar->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type array, %s given',
                $function,
                $argIndex + 1,
                $paramName,
                match ($arrayVar->type) {
                    Variable::TYPE_NULL => 'null',
                    Variable::TYPE_BOOLEAN => 'bool',
                    Variable::TYPE_INTEGER => 'int',
                    Variable::TYPE_FLOAT => 'float',
                    Variable::TYPE_STRING => 'string',
                    Variable::TYPE_OBJECT => 'object',
                    default => 'mixed',
                }
            ));
        }

        $ht = $arrayVar->toArray();
        $pems = [];
        $index = 0;
        foreach ($ht->iterateKeyed(true) as [, $valueVar]) {
            $pem = self::coerceSealPublicKeyPem($valueVar, $function, $index, $frame);
            if (false === $pem) {
                self::userWarning(\sprintf(
                    '%s(): Not a public key (%dth member of pubkeys)',
                    $function,
                    $index + 1
                ), $frame);

                return false;
            }
            $pems[] = $pem;
            ++$index;
        }

        if ([] === $pems) {
            throw new \ValueError($function.'(): Argument #'.($argIndex + 1).' ($'.$paramName.') cannot be empty');
        }

        return $pems;
    }

    private static function coerceSealPublicKeyPem(
        Variable $var,
        string $function,
        int $memberIndex,
        ?Frame $frame = null
    ): string|false {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_STRING === $var->type) {
            $pem = $var->toString();

            return '' !== $pem ? $pem : false;
        }
        if (Variable::TYPE_OBJECT === $var->type) {
            $pem = VmOpensslObjects::keyPem($var->toObject());
            if ('' === $pem) {
                return false;
            }
            if (!VmOpensslPkeyNative::available()) {
                return false;
            }
            $publicPem = VmOpensslPkeyNative::exportPublicKeyPem($pem);
            if (false === $publicPem) {
                self::userWarning(\sprintf(
                    '%s(): Don\'t know how to get public key from this private key',
                    $function
                ), $frame);
            }

            return $publicPem;
        }

        return false;
    }

    /**
     * openssl_pkey_derive() — ECDH/X25519 shared secret (EVP_PKEY_derive; issue #15428).
     *
     * @return string|false
     */
    public static function pkeyDerive(
        string $publicKeyPem,
        string $privateKeyPem,
        int $keyLength = 0,
        ?Frame $frame = null
    ): string|false {
        if (!VmOpensslPkeyDeriveNative::available()) {
            self::userWarning(
                'openssl_pkey_derive(): OpenSSL key derivation is unavailable in this compiler build',
                $frame
            );

            return false;
        }

        return VmOpensslPkeyDeriveNative::derive($publicKeyPem, $privateKeyPem, $keyLength);
    }

    /**
     * openssl_dh_compute_key() — DH shared secret from raw peer public bytes (php-src ext/openssl/openssl_backend_v3.c; #6596).
     *
     * @return string|false
     */
    public static function dhComputeKey(
        string $pubKeyBytes,
        string $privateKeyPem,
        ?Frame $frame = null
    ): string|false {
        if (!VmOpensslPkeyDeriveNative::available()) {
            self::userWarning(
                'openssl_dh_compute_key(): OpenSSL DH key agreement is unavailable in this compiler build',
                $frame
            );

            return false;
        }

        return VmOpensslPkeyDeriveNative::dhComputeKey($privateKeyPem, $pubKeyBytes);
    }

    /**
     * openssl_csr_new() — create certificate signing request (php-src ext/openssl/xp.c; #6421).
     *
     * @return Variable|false OpenSSLCertificateSigningRequest wrapper
     */
    public static function csrNew(
        Variable $dnVar,
        Variable $privateKeyVar,
        ?Variable $optionsVar,
        Context $ctx,
        ?Frame $frame = null,
    ): Variable|false {
        if (!VmOpensslCsrNative::available()) {
            self::userWarning('openssl_csr_new(): OpenSSL CSR is unavailable in this compiler build', $frame);

            return false;
        }

        $dn = self::assocStringArrayFromVariable($dnVar, 'openssl_csr_new', 0, 'distinguished_names');
        $digestAlg = 'sha256';
        $bits = 2048;
        if (null !== $optionsVar) {
            $optionsVar = $optionsVar->resolveIndirect();
            if (Variable::TYPE_NULL !== $optionsVar->type && Variable::TYPE_ARRAY !== $optionsVar->type) {
                throw new \TypeError(\sprintf(
                    'openssl_csr_new(): Argument #3 ($options) must be of type ?array, %s given',
                    self::typeLabel($optionsVar)
                ));
            }
            if (Variable::TYPE_ARRAY === $optionsVar->type) {
                foreach ($optionsVar->toArray()->iterateKeyed(true) as [$keyVar, $valueVar]) {
                    if (Variable::TYPE_STRING !== $keyVar->type) {
                        continue;
                    }
                    $key = $keyVar->toString();
                    $valueVar = $valueVar->resolveIndirect();
                    if ('digest_alg' === $key) {
                        if (Variable::TYPE_STRING === $valueVar->type) {
                            $digestAlg = strtolower($valueVar->toString());
                        }
                    }
                    if ('private_key_bits' === $key && Variable::TYPE_INTEGER === $valueVar->type) {
                        $bits = $valueVar->toInt();
                    }
                }
            }
        }

        $keyPem = self::resolveOrCreatePrivateKeyPem($privateKeyVar, $bits, $ctx, $frame);
        if (null === $keyPem) {
            return false;
        }

        $csrPem = VmOpensslCsrNative::createCsrPem($dn, $keyPem, $digestAlg);
        if (false === $csrPem) {
            self::userWarning('openssl_csr_new(): Unable to create CSR', $frame);

            return false;
        }

        return VmOpensslObjects::wrapCsr($ctx, $csrPem);
    }

    /**
     * openssl_csr_export() — PEM export (php-src ext/openssl/xp.c; #6421).
     */
    public static function csrExportPem(Variable $csrArg, ?Frame $frame = null): string|false
    {
        if (!VmOpensslCsrNative::available()) {
            self::userWarning('openssl_csr_export(): OpenSSL CSR is unavailable in this compiler build', $frame);

            return false;
        }

        $pem = VmOpensslObjects::resolveCsrPem($csrArg, 'openssl_csr_export');
        if (null === $pem) {
            return false;
        }

        $normalized = VmOpensslCsrNative::normalizeCsrPem($pem);
        if (false === $normalized) {
            self::userWarning('openssl_csr_export(): cannot get CSR from file', $frame);

            return false;
        }

        return $normalized;
    }

    /**
     * openssl_x509_export() — PEM (+ optional text) export (php-src ext/openssl/openssl.c; #20273).
     */
    public static function x509ExportPem(
        Variable $certArg,
        bool $noText = true,
        ?Frame $frame = null,
        string $function = 'openssl_x509_export',
    ): string|false {
        if (!VmOpensslX509Native::available()) {
            self::userWarning($function.'(): OpenSSL X.509 is unavailable in this compiler build', $frame);

            return false;
        }

        $material = self::coerceCertificatePem($certArg, $function, 0, 'certificate');
        $pem = self::resolvePemMaterial($material, $function, $frame);
        if (false === $pem) {
            return false;
        }

        $exported = VmOpensslX509Native::exportCertificatePem($pem, $noText);
        if (false === $exported) {
            self::userWarningForFrame($function.'(): X.509 Certificate cannot be retrieved', $frame);

            return false;
        }

        return $exported;
    }

    /**
     * openssl_csr_get_subject() — DN fields (php-src ext/openssl/xp.c; #6421).
     *
     * @return Variable|false
     */
    public static function csrGetSubject(Variable $csrArg, bool $shortnames, ?Frame $frame = null): Variable|false
    {
        if (!VmOpensslCsrNative::available()) {
            self::userWarning('openssl_csr_get_subject(): OpenSSL CSR is unavailable in this compiler build', $frame);

            return false;
        }

        $pem = VmOpensslObjects::resolveCsrPem($csrArg, 'openssl_csr_get_subject');
        if (null === $pem) {
            return false;
        }

        $subject = VmOpensslCsrNative::getSubject($pem, $shortnames);
        if (false === $subject) {
            self::userWarning('openssl_csr_get_subject(): cannot get CSR from file', $frame);

            return false;
        }

        $result = new Variable();
        $result->array(self::assocStringArrayToHashTable($subject));

        return $result;
    }

    /**
     * openssl_csr_get_public_key() (php-src ext/openssl/xp.c; #6421).
     *
     * @return Variable|false
     */
    public static function csrGetPublicKey(Variable $csrArg, Context $ctx, ?Frame $frame = null): Variable|false
    {
        if (!VmOpensslCsrNative::available()) {
            self::userWarning('openssl_csr_get_public_key(): OpenSSL CSR is unavailable in this compiler build', $frame);

            return false;
        }

        $pem = VmOpensslObjects::resolveCsrPem($csrArg, 'openssl_csr_get_public_key');
        if (null === $pem) {
            return false;
        }

        $pubPem = VmOpensslCsrNative::getPublicKeyPem($pem);
        if (false === $pubPem) {
            self::userWarning('openssl_csr_get_public_key(): cannot get CSR from file', $frame);

            return false;
        }

        return VmOpensslObjects::wrapKey($ctx, $pubPem);
    }

    /**
     * openssl_csr_sign() — issue certificate from CSR (php-src ext/openssl/xp.c; #6421).
     *
     * @return Variable|false OpenSSLCertificate wrapper
     */
    public static function csrSign(
        Variable $csrArg,
        ?Variable $caCertArg,
        Variable $privateKeyArg,
        int $days,
        ?Variable $optionsVar,
        int $serial,
        Context $ctx,
        ?Frame $frame = null,
    ): Variable|false {
        if (!VmOpensslCsrNative::available()) {
            self::userWarning('openssl_csr_sign(): OpenSSL CSR is unavailable in this compiler build', $frame);

            return false;
        }

        $csrPem = VmOpensslObjects::resolveCsrPem($csrArg, 'openssl_csr_sign');
        if (null === $csrPem) {
            return false;
        }

        $caPem = null;
        if (null !== $caCertArg) {
            $caCertArg = $caCertArg->resolveIndirect();
            if (Variable::TYPE_NULL !== $caCertArg->type) {
                $caPem = self::coerceCertificatePem($caCertArg, 'openssl_csr_sign', 1, 'ca_certificate');
            }
        }

        $keyPem = self::resolvePemMaterial(
            self::coercePkeyPem($privateKeyArg, 'openssl_csr_sign', 2, 'private_key'),
            'openssl_csr_sign',
            $frame
        );
        if (false === $keyPem) {
            return false;
        }

        $digestAlg = 'sha256';
        if (null !== $optionsVar) {
            $optionsVar = $optionsVar->resolveIndirect();
            if (Variable::TYPE_NULL !== $optionsVar->type && Variable::TYPE_ARRAY !== $optionsVar->type) {
                throw new \TypeError(\sprintf(
                    'openssl_csr_sign(): Argument #5 ($options) must be of type ?array, %s given',
                    self::typeLabel($optionsVar)
                ));
            }
            if (Variable::TYPE_ARRAY === $optionsVar->type) {
                foreach ($optionsVar->toArray()->iterateKeyed(true) as [$keyVar, $valueVar]) {
                    if (Variable::TYPE_STRING !== $keyVar->type) {
                        continue;
                    }
                    if ('digest_alg' === $keyVar->toString()) {
                        $valueVar = $valueVar->resolveIndirect();
                        if (Variable::TYPE_STRING === $valueVar->type) {
                            $digestAlg = strtolower($valueVar->toString());
                        }
                    }
                }
            }
        }

        $certPem = VmOpensslCsrNative::signCsrPem($csrPem, $caPem, $keyPem, $days, $digestAlg, $serial);
        if (false === $certPem) {
            self::userWarning('openssl_csr_sign(): Error signing request', $frame);

            return false;
        }

        return VmOpensslObjects::wrapCertificate($ctx, $certPem);
    }

    /**
     * @return array<string, string>
     */
    private static function assocStringArrayFromVariable(
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName,
    ): array {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type array, %s given',
                $function,
                $argIndex + 1,
                $paramName,
                self::typeLabel($var)
            ));
        }

        $out = [];
        foreach ($var->toArray()->iterateKeyed(true) as [$keyVar, $valueVar]) {
            if (Variable::TYPE_STRING !== $keyVar->type) {
                continue;
            }
            $valueVar = $valueVar->resolveIndirect();
            if (Variable::TYPE_STRING === $valueVar->type) {
                $out[$keyVar->toString()] = $valueVar->toString();
            } elseif (Variable::TYPE_INTEGER === $valueVar->type) {
                $out[$keyVar->toString()] = (string) $valueVar->toInt();
            }
        }

        return $out;
    }

    /**
     * Resolve by-ref private key for openssl_csr_new(); generate when null.
     */
    private static function resolveOrCreatePrivateKeyPem(
        Variable $privateKeyVar,
        int $bits,
        Context $ctx,
        ?Frame $frame,
    ): ?string {
        $resolved = $privateKeyVar->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            if (!VmOpensslPkeyNative::available()) {
                self::userWarning('openssl_csr_new(): Unable to generate key pair', $frame);

                return null;
            }
            $pem = VmOpensslPkeyNative::generateRsa($bits);
            if (false === $pem) {
                self::userWarning('openssl_csr_new(): Unable to generate key pair', $frame);

                return null;
            }
            $wrapped = VmOpensslObjects::wrapKey($ctx, $pem);
            $resolved->object($wrapped->toObject());

            return $pem;
        }

        return self::resolveOrNormalizePrivateKeyPem($resolved, 'openssl_csr_new', 1, 'private_key', $frame);
    }

    /**
     * @return string|null private key PEM, or null after warning
     */
    private static function resolveOrNormalizePrivateKeyPem(
        Variable $arg,
        string $function,
        int $argIndex,
        string $paramName,
        ?Frame $frame,
    ): ?string {
        $material = self::coercePkeyPem($arg, $function, $argIndex, $paramName);
        $pem = self::resolvePemMaterial($material, $function, $frame);
        if (false === $pem) {
            return null;
        }
        if (str_contains($pem, 'BEGIN PUBLIC KEY') || str_contains($pem, 'BEGIN RSA PUBLIC KEY')) {
            return $pem;
        }
        $normalized = VmOpensslPkeyNative::normalizePrivateKeyPem($pem, null);
        if (false === $normalized) {
            self::userWarning($function.'(): Unable to load private key', $frame);

            return null;
        }

        return $normalized;
    }

    private static function typeLabel(Variable $var): string
    {
        return match ($var->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => $var->toObject()->class->name,
            Variable::TYPE_RESOURCE => 'resource',
            default => 'mixed',
        };
    }

    /**
     * openssl_pkey_new() — generate asymmetric key pair (php-src ext/openssl/openssl.c; #6295, #22335).
     *
     * @return \PHPCompiler\VM\Variable|false OpenSSLAsymmetricKey wrapper
     */
    public static function pkeyNew(?Variable $configVar, Context $ctx, ?Frame $frame = null): Variable|false
    {
        if (!VmOpensslPkeyNative::available()) {
            self::userWarning('openssl_pkey_new(): OpenSSL key generation is unavailable in this compiler build', $frame);

            return false;
        }

        $bits = 2048;
        $type = OpensslConstants::OPENSSL_KEYTYPE_RSA;
        $curveName = null;
        $dhParams = null;
        $ecCurveFromNested = null;

        if (null !== $configVar) {
            $configVar = $configVar->resolveIndirect();
            if (Variable::TYPE_ARRAY === $configVar->type) {
                foreach ($configVar->toArray()->iterateKeyed(true) as [$keyVar, $valueVar]) {
                    if (Variable::TYPE_STRING !== $keyVar->type) {
                        continue;
                    }
                    $key = $keyVar->toString();
                    $valueVar = $valueVar->resolveIndirect();
                    if ('private_key_bits' === $key && Variable::TYPE_INTEGER === $valueVar->type) {
                        $bits = $valueVar->toInt();
                    } elseif ('private_key_type' === $key && Variable::TYPE_INTEGER === $valueVar->type) {
                        $type = $valueVar->toInt();
                    } elseif ('curve_name' === $key && Variable::TYPE_STRING === $valueVar->type) {
                        $curveName = $valueVar->toString();
                    } elseif ('dh' === $key && Variable::TYPE_ARRAY === $valueVar->type) {
                        $dhParams = self::extractDhParams($valueVar);
                    } elseif ('ec' === $key && Variable::TYPE_ARRAY === $valueVar->type) {
                        $ecCurveFromNested = self::extractNestedString($valueVar, 'curve_name');
                    }
                }
            }
        }

        // php-src openssl_pkey_new: nested dh/ec/rsa/dsa arrays take precedence over private_key_type.
        if (null !== $dhParams) {
            if (null === $dhParams['p'] || null === $dhParams['g']) {
                return false;
            }
            $pem = VmOpensslPkeyNative::generateDhFromParams($dhParams['p'], $dhParams['g'], $dhParams['q']);
            if (false === $pem) {
                return false;
            }

            return VmOpensslObjects::wrapKey($ctx, $pem);
        }

        if (null !== $ecCurveFromNested) {
            if ('' === $ecCurveFromNested) {
                return false;
            }
            if (0 === VmOpensslPkeyNative::curveNid($ecCurveFromNested)) {
                self::userWarning(
                    'openssl_pkey_new(): Unknown elliptic curve (short) name '.$ecCurveFromNested,
                    $frame
                );

                return false;
            }
            $pem = VmOpensslPkeyNative::generateEc($ecCurveFromNested);
            if (false === $pem) {
                self::userWarning('openssl_pkey_new(): Unable to generate key pair', $frame);

                return false;
            }

            return VmOpensslObjects::wrapKey($ctx, $pem);
        }

        if (OpensslConstants::OPENSSL_KEYTYPE_RSA === $type) {
            if ($bits < 384) {
                self::userWarning(
                    \sprintf(
                        'openssl_pkey_new(): Private key length must be at least %d bits, configured to %d',
                        384,
                        $bits
                    ),
                    $frame
                );

                return false;
            }
            $pem = VmOpensslPkeyNative::generateRsa($bits);
        } elseif (OpensslConstants::OPENSSL_KEYTYPE_EC === $type) {
            if (null === $curveName) {
                self::userWarning(
                    'openssl_pkey_new(): Missing configuration value: "curve_name" not set',
                    $frame
                );

                return false;
            }
            if (0 === VmOpensslPkeyNative::curveNid($curveName)) {
                self::userWarning(
                    'openssl_pkey_new(): Unknown elliptic curve (short) name '.$curveName,
                    $frame
                );

                return false;
            }
            $pem = VmOpensslPkeyNative::generateEc($curveName);
        } elseif (OpensslConstants::OPENSSL_KEYTYPE_DH === $type) {
            if ($bits < 384) {
                self::userWarning(
                    \sprintf(
                        'openssl_pkey_new(): Private key length must be at least %d bits, configured to %d',
                        384,
                        $bits
                    ),
                    $frame
                );

                return false;
            }
            $pem = VmOpensslPkeyNative::generateDh($bits);
        } else {
            self::userWarning('openssl_pkey_new(): Unknown private key type', $frame);

            return false;
        }

        if (false === $pem) {
            self::userWarning('openssl_pkey_new(): Unable to generate key pair', $frame);

            return false;
        }

        return VmOpensslObjects::wrapKey($ctx, $pem);
    }

    /**
     * @return array{p: ?string, g: ?string, q: ?string}
     */
    private static function extractDhParams(Variable $dhArray): array
    {
        $p = null;
        $g = null;
        $q = null;
        foreach ($dhArray->toArray()->iterateKeyed(true) as [$keyVar, $valueVar]) {
            if (Variable::TYPE_STRING !== $keyVar->type) {
                continue;
            }
            $valueVar = $valueVar->resolveIndirect();
            if (Variable::TYPE_STRING !== $valueVar->type) {
                continue;
            }
            $name = $keyVar->toString();
            if ('p' === $name) {
                $p = $valueVar->toString();
            } elseif ('g' === $name) {
                $g = $valueVar->toString();
            } elseif ('q' === $name) {
                $q = $valueVar->toString();
            }
        }

        return ['p' => $p, 'g' => $g, 'q' => $q];
    }

    private static function extractNestedString(Variable $arrayVar, string $key): ?string
    {
        foreach ($arrayVar->toArray()->iterateKeyed(true) as [$keyVar, $valueVar]) {
            if (Variable::TYPE_STRING !== $keyVar->type || $key !== $keyVar->toString()) {
                continue;
            }
            $valueVar = $valueVar->resolveIndirect();
            if (Variable::TYPE_STRING === $valueVar->type) {
                return $valueVar->toString();
            }
        }

        return null;
    }

    /**
     * openssl_pkey_get_private() — load private key from PEM/path (php-src ext/openssl/xp.c; #6295).
     *
     * @return \PHPCompiler\VM\Variable|false
     */
    public static function pkeyGetPrivate(Variable $arg, ?string $passphrase, Context $ctx, ?Frame $frame = null): Variable|false
    {
        if (!VmOpensslPkeyNative::available()) {
            self::userWarning('openssl_pkey_get_private(): OpenSSL is unavailable in this compiler build', $frame);

            return false;
        }

        $arg = $arg->resolveIndirect();
        if (Variable::TYPE_OBJECT === $arg->type && VmOpensslObjects::isAsymmetricKey($arg)) {
            return VmOpensslObjects::wrapKey($ctx, VmOpensslObjects::keyPem($arg->toObject()));
        }
        if (Variable::TYPE_STRING !== $arg->type) {
            throw new \TypeError(\sprintf(
                'openssl_pkey_get_private(): Argument #1 ($private_key) must be of type OpenSSLAsymmetricKey|string, %s given',
                match ($arg->type) {
                    Variable::TYPE_NULL => 'null',
                    Variable::TYPE_BOOLEAN => 'bool',
                    Variable::TYPE_INTEGER => 'int',
                    Variable::TYPE_FLOAT => 'float',
                    Variable::TYPE_ARRAY => 'array',
                    Variable::TYPE_OBJECT => $arg->toObject()->class->name,
                    default => 'mixed',
                }
            ));
        }

        $material = $arg->toString();
        if ('' !== $material && @\is_file($material)) {
            $contents = VmFsReadNative::read($material);
            if (false === $contents) {
                self::userWarning('openssl_pkey_get_private(): Unable to read key file', $frame);

                return false;
            }
            $material = $contents;
        }

        $pem = VmOpensslPkeyNative::normalizePrivateKeyPem($material, $passphrase);
        if (false === $pem) {
            self::userWarning('openssl_pkey_get_private(): Unable to load key', $frame);

            return false;
        }

        return VmOpensslObjects::wrapKey($ctx, $pem);
    }

    /**
     * openssl_pkey_get_public() / openssl_get_publickey() (php-src ext/openssl/openssl.c; #20240).
     * Pair: openssl_pkey_get_private() / openssl_get_privatekey() (#20306).
     *
     * @return \PHPCompiler\VM\Variable|false
     */
    public static function pkeyGetPublic(
        Variable $arg,
        Context $ctx,
        string $function = 'openssl_pkey_get_public',
        ?Frame $frame = null
    ): Variable|false {
        if (!VmOpensslPkeyNative::available()) {
            self::userWarning($function.'(): OpenSSL is unavailable in this compiler build', $frame);

            return false;
        }

        $arg = $arg->resolveIndirect();
        if (Variable::TYPE_OBJECT === $arg->type) {
            $object = $arg->toObject();
            $lc = strtolower($object->class->name);
            if (VmOpensslObjects::isAsymmetricKey($arg)) {
                $pem = VmOpensslObjects::keyPem($object);
                $normalized = VmOpensslPkeyNative::normalizePublicKeyPem($pem);
                if (false === $normalized) {
                    self::userWarning(
                        $function.'(): Don\'t know how to get public key from this private key',
                        $frame
                    );

                    return false;
                }

                return VmOpensslObjects::wrapKey($ctx, $normalized);
            }
            if (VmOpensslObjects::isCertificate($arg)) {
                $certPem = VmOpensslObjects::certificatePem($object);
                $pub = VmOpensslX509Native::extractPublicKeyPem($certPem);
                if (false === $pub) {
                    self::userWarning($function.'(): Unable to load key', $frame);

                    return false;
                }

                return VmOpensslObjects::wrapKey($ctx, $pub);
            }

            throw new \TypeError(\sprintf(
                '%s(): Argument #1 ($public_key) must be of type OpenSSLAsymmetricKey|OpenSSLCertificate|array|string, %s given',
                $function,
                $object->class->name
            ));
        }
        if (Variable::TYPE_ARRAY === $arg->type) {
            // php-src accepts array DN form for some key APIs; public load treats it as invalid material.
            self::userWarning($function.'(): Unable to load key', $frame);

            return false;
        }
        if (Variable::TYPE_STRING !== $arg->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #1 ($public_key) must be of type OpenSSLAsymmetricKey|OpenSSLCertificate|array|string, %s given',
                $function,
                match ($arg->type) {
                    Variable::TYPE_NULL => 'null',
                    Variable::TYPE_BOOLEAN => 'bool',
                    Variable::TYPE_INTEGER => 'int',
                    Variable::TYPE_FLOAT => 'float',
                    default => 'mixed',
                }
            ));
        }

        $material = self::resolvePemMaterial($arg->toString(), $function, $frame);
        if (false === $material) {
            return false;
        }

        if (str_contains($material, 'BEGIN CERTIFICATE')) {
            $pub = VmOpensslX509Native::extractPublicKeyPem($material);
            if (false === $pub) {
                self::userWarning($function.'(): Unable to load key', $frame);

                return false;
            }

            return VmOpensslObjects::wrapKey($ctx, $pub);
        }

        $pem = VmOpensslPkeyNative::normalizePublicKeyPem($material);
        if (false === $pem) {
            self::userWarning($function.'(): Unable to load key', $frame);

            return false;
        }

        return VmOpensslObjects::wrapKey($ctx, $pem);
    }

    /**
     * openssl_pkey_get_details() (php-src ext/openssl/openssl.c; #20240).
     *
     * @return \PHPCompiler\VM\Variable|false
     */
    public static function pkeyGetDetails(Variable $arg, ?Frame $frame = null): Variable|false
    {
        if (!VmOpensslPkeyNative::available()) {
            self::userWarning('openssl_pkey_get_details(): OpenSSL is unavailable in this compiler build', $frame);

            return false;
        }

        $arg = $arg->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $arg->type || !VmOpensslObjects::isAsymmetricKey($arg)) {
            throw new \TypeError(\sprintf(
                'openssl_pkey_get_details(): Argument #1 ($key) must be of type OpenSSLAsymmetricKey, %s given',
                match ($arg->type) {
                    Variable::TYPE_NULL => 'null',
                    Variable::TYPE_BOOLEAN => 'bool',
                    Variable::TYPE_INTEGER => 'int',
                    Variable::TYPE_FLOAT => 'float',
                    Variable::TYPE_STRING => 'string',
                    Variable::TYPE_ARRAY => 'array',
                    Variable::TYPE_OBJECT => $arg->toObject()->class->name,
                    default => 'mixed',
                }
            ));
        }

        $pem = VmOpensslObjects::keyPem($arg->toObject());
        $details = VmOpensslPkeyNative::getDetails($pem);
        if (false === $details) {
            self::userWarning('openssl_pkey_get_details(): Unable to get key details', $frame);

            return false;
        }

        return VmOpensslObjects::variableFromPhpValue($details);
    }

    /**
     * openssl_pkey_export() — export OpenSSLAsymmetricKey to PEM (php-src ext/openssl/xp.c; #6295).
     *
     * Third arg is passphrase (string|null), matching openssl.stub.php — not a config array (#24492).
     *
     * @return string|false
     */
    public static function pkeyExportPem(Variable $keyArg, ?string $passphrase, ?Frame $frame = null): string|false
    {
        if (!VmOpensslPkeyNative::available()) {
            self::userWarning('openssl_pkey_export(): OpenSSL is unavailable in this compiler build', $frame);

            return false;
        }

        $pem = self::coercePkeyPem($keyArg, 'openssl_pkey_export', 0, 'key');
        $exported = VmOpensslPkeyNative::exportPrivateKeyPem($pem, $passphrase);
        if (false === $exported) {
            self::userWarning('openssl_pkey_export(): Cannot export key', $frame);

            return false;
        }

        return $exported;
    }

    /**
     * openssl_pkey_export_to_file() — PEM to path (php-src ext/openssl/openssl.c; #20287).
     *
     * @return string|false
     */
    public static function pkeyExportPemToFile(
        Variable $keyArg,
        ?string $passphrase,
        ?Frame $frame = null
    ): string|false {
        if (!VmOpensslPkeyNative::available()) {
            self::userWarning(
                'openssl_pkey_export_to_file(): OpenSSL is unavailable in this compiler build',
                $frame
            );

            return false;
        }

        $pem = self::coercePkeyPem($keyArg, 'openssl_pkey_export_to_file', 0, 'key');
        $exported = VmOpensslPkeyNative::exportPrivateKeyPem($pem, $passphrase);
        if (false === $exported) {
            self::userWarning('openssl_pkey_export_to_file(): Cannot get key from parameter 1', $frame);

            return false;
        }

        return $exported;
    }

    public static function coercePkeyPem(Variable $var, string $function, int $argIndex, string $paramName): string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_STRING === $var->type) {
            return $var->toString();
        }
        if (Variable::TYPE_OBJECT === $var->type) {
            $pem = VmOpensslObjects::keyPem($var->toObject());
            if ('' !== $pem) {
                return $pem;
            }
        }

        throw new \TypeError(\sprintf(
            '%s(): Argument #%d ($%s) must be of type OpenSSLAsymmetricKey|string, %s given',
            $function,
            $argIndex + 1,
            $paramName,
            match ($var->type) {
                Variable::TYPE_NULL => 'null',
                Variable::TYPE_BOOLEAN => 'bool',
                Variable::TYPE_INTEGER => 'int',
                Variable::TYPE_FLOAT => 'float',
                Variable::TYPE_ARRAY => 'array',
                Variable::TYPE_OBJECT => $var->toObject()->class->name,
                default => 'mixed',
            }
        ));
    }

    public static function coerceSignatureArg(Variable $var, string $function, int $argIndex, string $paramName): string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_STRING === $var->type) {
            return $var->toString();
        }

        throw new \TypeError(\sprintf(
            '%s(): Argument #%d ($%s) must be of type string, %s given',
            $function,
            $argIndex + 1,
            $paramName,
            match ($var->type) {
                Variable::TYPE_NULL => 'null',
                Variable::TYPE_BOOLEAN => 'bool',
                Variable::TYPE_INTEGER => 'int',
                Variable::TYPE_FLOAT => 'float',
                Variable::TYPE_ARRAY => 'array',
                Variable::TYPE_OBJECT => 'object',
                default => 'mixed',
            }
        ));
    }

    public static function coercePaddingArg(Variable $var, string $function, int $argIndex, string $paramName): int
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_INTEGER === $var->type) {
            return $var->toInt();
        }
        if (Variable::TYPE_STRING === $var->type) {
            return (int) $var->toString();
        }
        if (Variable::TYPE_NULL === $var->type) {
            return OpensslConstants::OPENSSL_PKCS1_PADDING;
        }

        throw new \TypeError(\sprintf(
            '%s(): Argument #%d ($%s) must be of type int, %s given',
            $function,
            $argIndex + 1,
            $paramName,
            match ($var->type) {
                Variable::TYPE_BOOLEAN => 'bool',
                Variable::TYPE_FLOAT => 'float',
                Variable::TYPE_ARRAY => 'array',
                Variable::TYPE_OBJECT => $var->toObject()->class->name,
                default => 'mixed',
            }
        ));
    }

    /**
     * @return string|false EVP digest name
     */
    public static function resolveDigestName(int|string $algorithm, string $function, ?Frame $frame = null): string|false
    {
        if (\is_int($algorithm)) {
            $name = match ($algorithm) {
                OpensslConstants::OPENSSL_ALGO_MD4 => 'md4',
                OpensslConstants::OPENSSL_ALGO_MD5 => 'md5',
                OpensslConstants::OPENSSL_ALGO_SHA1 => 'sha1',
                OpensslConstants::OPENSSL_ALGO_SHA224 => 'sha224',
                OpensslConstants::OPENSSL_ALGO_SHA256 => 'sha256',
                OpensslConstants::OPENSSL_ALGO_SHA384 => 'sha384',
                OpensslConstants::OPENSSL_ALGO_SHA512 => 'sha512',
                OpensslConstants::OPENSSL_ALGO_RMD160 => 'ripemd160',
                default => null,
            };
            if (null === $name) {
                self::userWarning($function.'(): Unknown signature algorithm', $frame);

                return false;
            }

            return $name;
        }

        $name = strtolower($algorithm);
        if (!OpensslCipherRegistry::digestImplemented($name) && !\in_array($name, ['sha224', 'sha384', 'sha512', 'ripemd160', 'md4'], true)) {
            self::userWarning($function.'(): Unknown signature algorithm', $frame);

            return false;
        }

        return $name;
    }

    public static function coerceBoolArg(Variable $var, string $function, int $argIndex, string $paramName): bool
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_BOOLEAN === $var->type) {
            return $var->toBool();
        }

        throw new \TypeError(sprintf(
            '%s(): Argument #%d ($%s) must be of type bool, %s given',
            $function,
            $argIndex + 1,
            $paramName,
            match ($var->type) {
                Variable::TYPE_NULL => 'null',
                Variable::TYPE_INTEGER => 'int',
                Variable::TYPE_FLOAT => 'float',
                Variable::TYPE_STRING => 'string',
                Variable::TYPE_ARRAY => 'array',
                Variable::TYPE_OBJECT => 'object',
                default => 'mixed',
            }
        ));
    }

    /**
     * openssl_pkcs12_read() — parse PKCS#12 blob (php-src ext/openssl/pkcs12.c; #6420).
     *
     * @return array{cert: string, pkey: string}|false
     */
    public static function pkcs12Read(string $pkcs12, string $passphrase, ?Frame $frame = null): array|false
    {
        if (!VmOpensslPkcs12Native::available()) {
            self::userWarning('openssl_pkcs12_read(): OpenSSL PKCS#12 is unavailable in this compiler build', $frame);

            return false;
        }

        $parsed = VmOpensslPkcs12Native::parsePkcs12($pkcs12, $passphrase);
        if (false === $parsed) {
            return false;
        }

        return $parsed;
    }

    /**
     * openssl_pkcs12_export() — create PKCS#12 blob (php-src ext/openssl/pkcs12.c; #6420).
     *
     * @param list<string> $extraCertPems
     */
    public static function pkcs12Export(
        Variable $certArg,
        Variable $keyArg,
        string $passphrase,
        ?Variable $optionsVar,
        ?Frame $frame = null
    ): string|false {
        if (!VmOpensslPkcs12Native::available()) {
            self::userWarning('openssl_pkcs12_export(): OpenSSL PKCS#12 is unavailable in this compiler build', $frame);

            return false;
        }

        $certPem = self::coerceCertificatePem($certArg, 'openssl_pkcs12_export', 0, 'certificate');
        $keyPem = self::coercePkeyPem($keyArg, 'openssl_pkcs12_export', 2, 'private_key');

        $friendlyName = '';
        $extraCerts = [];
        if (null !== $optionsVar) {
            $optionsVar = $optionsVar->resolveIndirect();
            if (Variable::TYPE_ARRAY === $optionsVar->type) {
                foreach ($optionsVar->toArray()->iterateKeyed(true) as [$keyVar, $valueVar]) {
                    if (Variable::TYPE_STRING !== $keyVar->type) {
                        continue;
                    }
                    $key = $keyVar->toString();
                    $valueVar = $valueVar->resolveIndirect();
                    if ('friendly_name' === $key && Variable::TYPE_STRING === $valueVar->type) {
                        $friendlyName = $valueVar->toString();
                    }
                    if ('extracerts' === $key && Variable::TYPE_ARRAY === $valueVar->type) {
                        foreach ($valueVar->toArray()->iterateKeyed(true) as [, $certVar]) {
                            $extraPem = self::coerceCertificatePem(
                                $certVar,
                                'openssl_pkcs12_export',
                                4,
                                'options'
                            );
                            $extraCerts[] = $extraPem;
                        }
                    }
                }
            }
        }

        $blob = VmOpensslPkcs12Native::createPkcs12(
            $certPem,
            $keyPem,
            $passphrase,
            $friendlyName,
            $extraCerts
        );
        if (false === $blob) {
            return false;
        }

        return $blob;
    }

    public static function coerceCertificatePem(
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName
    ): string {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_STRING === $var->type) {
            return $var->toString();
        }
        if (Variable::TYPE_OBJECT === $var->type) {
            if (VmOpensslObjects::isCertificate($var)) {
                $pem = VmOpensslObjects::certificatePem($var->toObject());
                if ('' !== $pem) {
                    return $pem;
                }
            }
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type OpenSSLCertificate|string, %s given',
                $function,
                $argIndex + 1,
                $paramName,
                $var->toObject()->class->name
            ));
        }

        throw new \TypeError(\sprintf(
            '%s(): Argument #%d ($%s) must be of type OpenSSLCertificate|string, %s given',
            $function,
            $argIndex + 1,
            $paramName,
            match ($var->type) {
                Variable::TYPE_NULL => 'null',
                Variable::TYPE_BOOLEAN => 'bool',
                Variable::TYPE_INTEGER => 'int',
                Variable::TYPE_FLOAT => 'float',
                Variable::TYPE_ARRAY => 'array',
                default => 'mixed',
            }
        ));
    }

    /**
     * openssl_pkcs7_sign() — S/MIME sign (php-src ext/openssl/openssl.c; #6804).
     *
     * @return bool
     */
    public static function pkcs7Sign(
        string $inputFilename,
        string $outputFilename,
        Variable $certArg,
        Variable $keyArg,
        ?Variable $headersVar,
        int $flags,
        ?Frame $frame = null
    ): bool {
        if (!VmOpensslPkcs7Native::available()) {
            self::userWarning('openssl_pkcs7_sign(): OpenSSL PKCS#7 is unavailable in this compiler build', $frame);

            return false;
        }

        $certPem = self::resolvePemMaterial(
            self::coerceCertificatePem($certArg, 'openssl_pkcs7_sign', 2, 'certificate'),
            'openssl_pkcs7_sign',
            $frame
        );
        $keyPem = self::resolvePemMaterial(
            self::coercePkeyPem($keyArg, 'openssl_pkcs7_sign', 3, 'private_key'),
            'openssl_pkcs7_sign',
            $frame
        );
        if (false === $certPem || false === $keyPem) {
            return false;
        }

        $headers = self::coercePkcs7Headers($headersVar, 'openssl_pkcs7_sign');
        if (false === VmOpensslPkcs7Native::sign(
            $inputFilename,
            $outputFilename,
            $certPem,
            $keyPem,
            $headers,
            $flags
        )) {
            self::userWarning('openssl_pkcs7_sign(): Error creating PKCS7 structure!', $frame);

            return false;
        }

        return true;
    }

    /**
     * openssl_pkcs7_verify() — S/MIME verify (php-src ext/openssl/openssl.c; #6804).
     *
     * @return bool|int
     */
    public static function pkcs7Verify(
        string $inputFilename,
        int $flags,
        ?string $signersCertificatesFilename,
        ?string $contentOutputFilename,
        ?Frame $frame = null
    ): bool|int {
        if (!VmOpensslPkcs7Native::available()) {
            self::userWarning('openssl_pkcs7_verify(): OpenSSL PKCS#7 is unavailable in this compiler build', $frame);

            return -1;
        }

        return VmOpensslPkcs7Native::verify(
            $inputFilename,
            $flags,
            $signersCertificatesFilename,
            $contentOutputFilename
        );
    }

    /**
     * openssl_pkcs7_read() — extract cert PEMs from PKCS#7 PEM content (php-src ext/openssl/openssl.c; #20305).
     *
     * @return list<string>|false
     */
    public static function pkcs7Read(string $pkcs7PemContent, ?Frame $frame = null): array|false
    {
        if (!VmOpensslPkcs7Native::available()) {
            self::userWarning('openssl_pkcs7_read(): OpenSSL PKCS#7 is unavailable in this compiler build', $frame);

            return false;
        }

        return VmOpensslPkcs7Native::read($pkcs7PemContent);
    }

    /**
     * openssl_pkcs7_encrypt() — S/MIME encrypt (php-src ext/openssl/openssl.c; #6804).
     *
     * @return bool
     */
    public static function pkcs7Encrypt(
        string $inputFilename,
        string $outputFilename,
        Variable $certsArg,
        ?Variable $headersVar,
        int $flags,
        int $cipherId,
        ?Frame $frame = null
    ): bool {
        if (!VmOpensslPkcs7Native::available()) {
            self::userWarning('openssl_pkcs7_encrypt(): OpenSSL PKCS#7 is unavailable in this compiler build', $frame);

            return false;
        }

        $certPems = self::coerceRecipientCertPems($certsArg, 'openssl_pkcs7_encrypt', 2, 'certificate');
        if ([] === $certPems) {
            return false;
        }
        $resolved = [];
        foreach ($certPems as $pem) {
            $material = self::resolvePemMaterial($pem, 'openssl_pkcs7_encrypt', $frame);
            if (false === $material) {
                return false;
            }
            $resolved[] = $material;
        }

        $headers = self::coercePkcs7Headers($headersVar, 'openssl_pkcs7_encrypt');
        if (false === VmOpensslPkcs7Native::encrypt(
            $inputFilename,
            $outputFilename,
            $resolved,
            $headers,
            $flags,
            $cipherId
        )) {
            self::userWarning('openssl_pkcs7_encrypt(): Error encrypting message!', $frame);

            return false;
        }

        return true;
    }

    /**
     * openssl_pkcs7_decrypt() — S/MIME decrypt (php-src ext/openssl/openssl.c; #6804).
     */
    public static function pkcs7Decrypt(
        string $inputFilename,
        string $outputFilename,
        Variable $certArg,
        Variable $keyArg,
        ?Frame $frame = null
    ): bool {
        if (!VmOpensslPkcs7Native::available()) {
            self::userWarning('openssl_pkcs7_decrypt(): OpenSSL PKCS#7 is unavailable in this compiler build', $frame);

            return false;
        }

        $certPem = self::resolvePemMaterial(
            self::coerceCertificatePem($certArg, 'openssl_pkcs7_decrypt', 2, 'certificate'),
            'openssl_pkcs7_decrypt',
            $frame
        );
        $keyPem = self::resolvePemMaterial(
            self::coercePkeyPem($keyArg, 'openssl_pkcs7_decrypt', 3, 'private_key'),
            'openssl_pkcs7_decrypt',
            $frame
        );
        if (false === $certPem || false === $keyPem) {
            return false;
        }

        if (false === VmOpensslPkcs7Native::decrypt($inputFilename, $outputFilename, $certPem, $keyPem)) {
            self::userWarning('openssl_pkcs7_decrypt(): Error decrypting PKCS7 message', $frame);

            return false;
        }

        return true;
    }

    /**
     * openssl_cms_sign() — CMS/S/MIME sign (php-src ext/openssl/openssl.c; #6592).
     */
    public static function cmsSign(
        string $inputFilename,
        string $outputFilename,
        Variable $certArg,
        Variable $keyArg,
        ?Variable $headersVar,
        int $flags,
        int $encoding,
        ?Frame $frame = null
    ): bool {
        if (!VmOpensslCmsNative::available()) {
            self::userWarning('openssl_cms_sign(): OpenSSL CMS is unavailable in this compiler build', $frame);

            return false;
        }

        if (OpensslConstants::OPENSSL_ENCODING_SMIME === $encoding
            && 0 !== ($flags & OpensslConstants::OPENSSL_CMS_DETACHED)
        ) {
            self::userWarning('openssl_cms_sign(): Detached signatures not possible with S/MIME encoding', $frame);

            return false;
        }

        $certPem = self::resolvePemMaterial(
            self::coerceCertificatePem($certArg, 'openssl_cms_sign', 2, 'certificate'),
            'openssl_cms_sign',
            $frame
        );
        $keyPem = self::resolvePemMaterial(
            self::coercePkeyPem($keyArg, 'openssl_cms_sign', 3, 'private_key'),
            'openssl_cms_sign',
            $frame
        );
        if (false === $certPem || false === $keyPem) {
            return false;
        }

        $headers = self::coercePkcs7Headers($headersVar, 'openssl_cms_sign');
        if (false === VmOpensslCmsNative::sign(
            $inputFilename,
            $outputFilename,
            $certPem,
            $keyPem,
            $headers,
            $flags,
            $encoding
        )) {
            self::userWarning('openssl_cms_sign(): Error creating CMS structure!', $frame);

            return false;
        }

        return true;
    }

    /**
     * openssl_cms_verify() — CMS/S/MIME verify (php-src ext/openssl/openssl.c; #6592).
     */
    public static function cmsVerify(
        string $inputFilename,
        int $flags,
        ?string $signersCertificatesFilename,
        ?string $contentOutputFilename,
        int $encoding,
        ?Frame $frame = null
    ): bool {
        if (!VmOpensslCmsNative::available()) {
            self::userWarning('openssl_cms_verify(): OpenSSL CMS is unavailable in this compiler build', $frame);

            return false;
        }

        return VmOpensslCmsNative::verify(
            $inputFilename,
            $flags,
            $signersCertificatesFilename,
            $contentOutputFilename,
            $encoding
        );
    }

    /**
     * openssl_cms_encrypt() — CMS/S/MIME encrypt (php-src ext/openssl/openssl.c; #6592).
     */
    public static function cmsEncrypt(
        string $inputFilename,
        string $outputFilename,
        Variable $certsArg,
        ?Variable $headersVar,
        int $flags,
        int $encoding,
        int $cipherId,
        ?Frame $frame = null
    ): bool {
        if (!VmOpensslCmsNative::available()) {
            self::userWarning('openssl_cms_encrypt(): OpenSSL CMS is unavailable in this compiler build', $frame);

            return false;
        }

        $certPems = self::coerceRecipientCertPems($certsArg, 'openssl_cms_encrypt', 2, 'certificate');
        if ([] === $certPems) {
            return false;
        }
        $resolved = [];
        foreach ($certPems as $pem) {
            $material = self::resolvePemMaterial($pem, 'openssl_cms_encrypt', $frame);
            if (false === $material) {
                return false;
            }
            $resolved[] = $material;
        }

        $headers = self::coercePkcs7Headers($headersVar, 'openssl_cms_encrypt');
        if (false === VmOpensslCmsNative::encrypt(
            $inputFilename,
            $outputFilename,
            $resolved,
            $headers,
            $flags,
            $encoding,
            $cipherId
        )) {
            self::userWarning('openssl_cms_encrypt(): Error encrypting message!', $frame);

            return false;
        }

        return true;
    }

    /**
     * openssl_cms_decrypt() — CMS/S/MIME decrypt (php-src ext/openssl/openssl.c; #6592).
     */
    public static function cmsDecrypt(
        string $inputFilename,
        string $outputFilename,
        Variable $certArg,
        Variable $keyArg,
        int $encoding,
        ?Frame $frame = null
    ): bool {
        if (!VmOpensslCmsNative::available()) {
            self::userWarning('openssl_cms_decrypt(): OpenSSL CMS is unavailable in this compiler build', $frame);

            return false;
        }

        if ($encoding < OpensslConstants::OPENSSL_ENCODING_DER
            || $encoding > OpensslConstants::OPENSSL_ENCODING_PEM
        ) {
            throw new \ValueError('openssl_cms_decrypt(): Argument #5 ($encoding) must be an OPENSSL_ENCODING_* constant');
        }

        $certPem = self::resolvePemMaterial(
            self::coerceCertificatePem($certArg, 'openssl_cms_decrypt', 2, 'certificate'),
            'openssl_cms_decrypt',
            $frame
        );
        $keyPem = self::resolvePemMaterial(
            self::coercePkeyPem($keyArg, 'openssl_cms_decrypt', 3, 'private_key'),
            'openssl_cms_decrypt',
            $frame
        );
        if (false === $certPem || false === $keyPem) {
            return false;
        }

        if (false === VmOpensslCmsNative::decrypt($inputFilename, $outputFilename, $certPem, $keyPem, $encoding)) {
            return false;
        }

        return true;
    }

    /**
     * openssl_cms_read() — extract certs from CMS PEM content (php-src ext/openssl/openssl.c; #6592).
     *
     * @return list<string>|false
     */
    public static function cmsRead(string $cmsPemContent, ?Frame $frame = null): array|false
    {
        if (!VmOpensslCmsNative::available()) {
            self::userWarning('openssl_cms_read(): OpenSSL CMS is unavailable in this compiler build', $frame);

            return false;
        }

        return VmOpensslCmsNative::read($cmsPemContent);
    }

    /**
     * Load PEM from inline string, filesystem path, or file:// URI.
     *
     * @return string|false
     */
    public static function resolvePemMaterial(string $material, string $function, ?Frame $frame = null): string|false
    {
        if (str_starts_with($material, 'file://')) {
            $material = substr($material, 7);
        }
        if ('' !== $material && @\is_file($material)) {
            $contents = VmFsReadNative::read($material);
            if (false === $contents) {
                self::userWarning($function.': Unable to read certificate/key file', $frame);

                return false;
            }

            return $contents;
        }

        return $material;
    }

    /**
     * @return list<array{0: ?string, 1: string}>
     */
    private static function coercePkcs7Headers(?Variable $headersVar, string $function): array
    {
        if (null === $headersVar) {
            return [];
        }
        $headersVar = $headersVar->resolveIndirect();
        if (Variable::TYPE_NULL === $headersVar->type) {
            return [];
        }
        if (Variable::TYPE_ARRAY !== $headersVar->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #5 ($headers) must be of type ?array, %s given',
                $function,
                match ($headersVar->type) {
                    Variable::TYPE_BOOLEAN => 'bool',
                    Variable::TYPE_INTEGER => 'int',
                    Variable::TYPE_FLOAT => 'float',
                    Variable::TYPE_STRING => 'string',
                    Variable::TYPE_OBJECT => 'object',
                    default => 'mixed',
                }
            ));
        }

        $headers = [];
        foreach ($headersVar->toArray()->iterateKeyed(true) as [$keyVar, $valueVar]) {
            $valueVar = $valueVar->resolveIndirect();
            $value = $valueVar->toString();
            if (Variable::TYPE_STRING === $keyVar->type) {
                $headers[] = [$keyVar->toString(), $value];
            } else {
                $headers[] = [null, $value];
            }
        }

        return $headers;
    }

    /**
     * @return list<string>
     */
    private static function coerceRecipientCertPems(
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName
    ): array {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_ARRAY === $var->type) {
            $pems = [];
            foreach ($var->toArray()->iterateKeyed(true) as [, $certVar]) {
                $pems[] = self::coerceCertificatePem($certVar, $function, $argIndex, $paramName);
            }

            return $pems;
        }

        return [self::coerceCertificatePem($var, $function, $argIndex, $paramName)];
    }

    /** @param list<string> $items */
    private static function stringListToHashTable(array $items): HashTable
    {
        $ht = new HashTable();
        foreach ($items as $item) {
            $var = new Variable();
            $var->string($item);
            $ht->append($var);
        }

        return $ht;
    }

    /** @param array<string, string> $items */
    private static function assocStringArrayToHashTable(array $items): HashTable
    {
        $ht = new HashTable();
        foreach ($items as $key => $value) {
            $var = new Variable();
            $var->string($value);
            $ht->update($key, $var);
        }

        return $ht;
    }

    public static function userWarningForFrame(string $message, ?Frame $frame): void
    {
        self::userWarning($message, $frame);
    }

    private static function userWarning(string $message, ?Frame $frame): void
    {
        if (null === $frame?->vmContext) {
            trigger_error($message, E_USER_WARNING);

            return;
        }
        $frame->vmContext->errors->triggerError(
            $message,
            ErrorReporter::E_USER_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }
}
