<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\ext\standard\VmHash;
use PHPCompiler\Frame;
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
