<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * NestedJIT leaf for openssl_pkey_new() keygen (#34015 leftover of #33530 / #6295).
 *
 * Returns PEM private key material, or '' on failure (caller boxes false).
 * php-src: ext/openssl/openssl.c — PHP_FUNCTION(openssl_pkey_new) / php_openssl_generate_private_key
 */
final class OpensslPkeyNewJitHelper
{
    /**
     * @param int    $bits  RSA/DSA/DH bit length (ignored for EC)
     * @param int    $type  OpensslConstants::OPENSSL_KEYTYPE_*
     * @param string $curve EC curve short name (required when type is EC)
     */
    public static function generatePem(int $bits, int $type, string $curve = ''): string
    {
        if (!VmOpensslPkeyNative::available()) {
            return '';
        }

        $pem = match ($type) {
            OpensslConstants::OPENSSL_KEYTYPE_RSA => VmOpensslPkeyNative::generateRsa($bits),
            OpensslConstants::OPENSSL_KEYTYPE_EC => ('' === $curve)
                ? false
                : VmOpensslPkeyNative::generateEc($curve),
            OpensslConstants::OPENSSL_KEYTYPE_DH => VmOpensslPkeyNative::generateDh($bits),
            // DSA keygen is not exposed via VmOpensslPkeyNative in this build — soft-fail like VM.
            OpensslConstants::OPENSSL_KEYTYPE_DSA => false,
            default => false,
        };

        return \is_string($pem) ? $pem : '';
    }

    /**
     * Runtime options hashtable → PEM (#35866 leftover of #34015).
     *
     * Nested dh/ec/rsa/dsa arrays soft-fail here (thin AOT uses the EVP RSA leaf instead).
     */
    public static function generatePemFromOptions(HashTable $options): string
    {
        $bits = 2048;
        $type = OpensslConstants::OPENSSL_KEYTYPE_RSA;
        $curve = '';
        foreach ($options->iterateKeyed(true) as [$keyVar, $valueVar]) {
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
                $curve = $valueVar->toString();
            } elseif (
                ('dh' === $key || 'ec' === $key || 'dsa' === $key || 'rsa' === $key)
                && Variable::TYPE_ARRAY === $valueVar->type
            ) {
                return '';
            }
        }

        return self::generatePem($bits, $type, $curve);
    }
}
