<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for openssl_x509_parse() (#32496 leftover of #6274),
 * openssl_x509_fingerprint() (#32512 leftover of #6524), and
 * openssl_x509_check_private_key() (#32527 leftover of #20285).
 *
 * php-src: ext/openssl/xp.c — PHP_FUNCTION(openssl_x509_parse)
 * php-src: ext/openssl/openssl.c — PHP_FUNCTION(openssl_x509_fingerprint) / X509_digest
 * php-src: ext/openssl/openssl.c — PHP_FUNCTION(openssl_x509_check_private_key) / X509_check_private_key
 *
 * Thin-standalone AOT has no PHP FFI, so NestedJIT of {@see VmOpensslX509Native} cannot
 * call `$ffi->X509_free()` (peer JitOpensslError / #32336). Bake results in the
 * compiler process (which does have libcrypto FFI), like {@see JitOpensslMethods::certLocations()}.
 *
 * PEM and optional args must be compile-time literals. OpenSSLCertificate objects stay VM-only.
 */
final class JitOpensslX509
{
    public static function parse(Context $context, JITVariable $certificate, ?JITVariable $shortNames = null): Value
    {
        $pem = JitStringArg::compileTimeLiteral($certificate);
        if (null === $pem) {
            throw new \LogicException(
                'openssl_x509_parse() certificate must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32496)'
            );
        }
        $short = self::compileTimeBool($shortNames, true);
        if (null === $short) {
            throw new \LogicException(
                'openssl_x509_parse() short_names must be a compile-time bool '
                .'for JIT/AOT in this compiler build (issue #32496)'
            );
        }

        if (!VmOpensslX509Native::available()) {
            return self::boxedFalse($context);
        }

        $parsed = VmOpensslX509Native::parseCertificatePem($pem, $short);
        if (false === $parsed) {
            return self::boxedFalse($context);
        }

        $htVar = HashTableHelper::variableFromVmHashTable(
            $context,
            VmOpensslObjects::variableFromPhpValue($parsed)->toArray()
        );

        return $htVar->value;
    }

    /**
     * openssl_x509_fingerprint() — bake {@see VmOpensslX509Native::fingerprintCertificatePem}.
     *
     * php-src: ext/openssl/openssl.c PHP_FUNCTION(openssl_x509_fingerprint) / X509_digest
     */
    public static function fingerprint(
        Context $context,
        JITVariable $certificate,
        ?JITVariable $digestAlg = null,
        ?JITVariable $binary = null
    ): Value {
        $pem = JitStringArg::compileTimeLiteral($certificate);
        if (null === $pem) {
            throw new \LogicException(
                'openssl_x509_fingerprint() certificate must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32512)'
            );
        }
        $algo = 'sha1';
        if (null !== $digestAlg) {
            $lit = JitStringArg::compileTimeLiteral($digestAlg);
            if (null === $lit) {
                throw new \LogicException(
                    'openssl_x509_fingerprint() digest_alg must be a compile-time string '
                    .'for JIT/AOT in this compiler build (issue #32512)'
                );
            }
            $algo = $lit;
        }
        $raw = self::compileTimeBool($binary, false);
        if (null === $raw) {
            throw new \LogicException(
                'openssl_x509_fingerprint() binary must be a compile-time bool '
                .'for JIT/AOT in this compiler build (issue #32512)'
            );
        }

        if (!VmOpensslX509Native::available()) {
            return self::boxedFalse($context);
        }

        $fingerprint = VmOpensslX509Native::fingerprintCertificatePem($pem, $algo, $raw);
        if (false === $fingerprint) {
            return self::boxedFalse($context);
        }

        return self::boxedString($context, $fingerprint);
    }

    /**
     * openssl_x509_check_private_key() — bake {@see VmOpensslX509Native::checkPrivateKeyPem}.
     *
     * php-src: ext/openssl/openssl.c PHP_FUNCTION(openssl_x509_check_private_key) / X509_check_private_key
     */
    public static function checkPrivateKey(
        Context $context,
        JITVariable $certificate,
        JITVariable $privateKey
    ): Value {
        $certPem = JitStringArg::compileTimeLiteral($certificate);
        if (null === $certPem) {
            throw new \LogicException(
                'openssl_x509_check_private_key() certificate must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32527)'
            );
        }
        $keyPem = JitStringArg::compileTimeLiteral($privateKey);
        if (null === $keyPem) {
            throw new \LogicException(
                'openssl_x509_check_private_key() private_key must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32527)'
            );
        }

        if (!VmOpensslX509Native::available()) {
            return self::boxedBool($context, false);
        }

        return self::boxedBool(
            $context,
            VmOpensslX509Native::checkPrivateKeyPem($certPem, $keyPem)
        );
    }

    private static function compileTimeBool(?JITVariable $arg, bool $default): ?bool
    {
        if (null === $arg) {
            return $default;
        }
        if (null !== $arg->compileTimeLong) {
            return 0 !== (int) $arg->compileTimeLong;
        }
        $lit = JitStringArg::compileTimeLiteral($arg);
        if (null !== $lit) {
            return '' !== $lit && '0' !== $lit;
        }

        return null;
    }

    private static function boxedFalse(Context $context): Value
    {
        return self::boxedBool($context, false);
    }

    private static function boxedBool(Context $context, bool $value): Value
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool($value));

        return JitValueBox::pointer($context, $slot);
    }

    private static function boxedString(Context $context, string $value): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $str = $context->builder->load($context->constantStringFromString($value));
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $str);

        return $ptr;
    }
}
