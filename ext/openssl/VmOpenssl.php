<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

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
     * openssl_pkey_new() — generate asymmetric key pair (php-src ext/openssl/xp.c; #6295).
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
                    }
                    if ('private_key_type' === $key && Variable::TYPE_INTEGER === $valueVar->type) {
                        $type = $valueVar->toInt();
                    }
                }
            }
        }

        if (OpensslConstants::OPENSSL_KEYTYPE_RSA !== $type) {
            self::userWarning('openssl_pkey_new(): Unknown private key type', $frame);

            return false;
        }

        $pem = VmOpensslPkeyNative::generateRsa($bits);
        if (false === $pem) {
            self::userWarning('openssl_pkey_new(): Unable to generate key pair', $frame);

            return false;
        }

        return VmOpensslObjects::wrapKey($ctx, $pem);
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
            $contents = @\file_get_contents($material);
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
     * openssl_pkey_export() — export OpenSSLAsymmetricKey to PEM (php-src ext/openssl/xp.c; #6295).
     *
     * @return string|false
     */
    public static function pkeyExportPem(Variable $keyArg, ?Variable $configVar, ?Frame $frame = null): string|false
    {
        if (!VmOpensslPkeyNative::available()) {
            self::userWarning('openssl_pkey_export(): OpenSSL is unavailable in this compiler build', $frame);

            return false;
        }

        $pem = self::coercePkeyPem($keyArg, 'openssl_pkey_export', 0, 'key');
        $passphrase = null;
        if (null !== $configVar) {
            $configVar = $configVar->resolveIndirect();
            if (Variable::TYPE_ARRAY === $configVar->type) {
                foreach ($configVar->toArray()->iterateKeyed(true) as [$keyVar, $valueVar]) {
                    if (Variable::TYPE_STRING !== $keyVar->type) {
                        continue;
                    }
                    if ('passphrase' === $keyVar->toString()
                        && Variable::TYPE_STRING === $valueVar->resolveIndirect()->type) {
                        $passphrase = $valueVar->resolveIndirect()->toString();
                    }
                }
            }
        }

        $exported = VmOpensslPkeyNative::exportPrivateKeyPem($pem, $passphrase);
        if (false === $exported) {
            self::userWarning('openssl_pkey_export(): Cannot export key', $frame);

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
