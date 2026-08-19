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
 * openssl_x509_checkpurpose() (#32522 leftover of #20286).
 *
 * php-src: ext/openssl/xp.c — PHP_FUNCTION(openssl_x509_parse)
 * php-src: ext/openssl/openssl.c — PHP_FUNCTION(openssl_x509_fingerprint) / X509_digest
 * php-src: ext/openssl/openssl.c — PHP_FUNCTION(openssl_x509_checkpurpose) / check_cert / X509_verify_cert
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
     * openssl_x509_checkpurpose() — bake {@see VmOpensslX509Native::checkPurposeCertificatePem}.
     *
     * php-src: ext/openssl/openssl.c PHP_FUNCTION(openssl_x509_checkpurpose) / check_cert / X509_verify_cert
     * Return matches VM {@see VmOpensslObjects::checkPurpose}: bool for 0/1, int otherwise (-1 error).
     */
    public static function checkPurpose(
        Context $context,
        JITVariable $certificate,
        JITVariable $purpose,
        ?JITVariable $caInfo = null,
        ?JITVariable $untrusted = null
    ): Value {
        $pem = JitStringArg::compileTimeLiteral($certificate);
        if (null === $pem) {
            throw new \LogicException(
                'openssl_x509_checkpurpose() certificate must be a compile-time string literal '
                .'for JIT/AOT in this compiler build (issue #32522)'
            );
        }
        $purposeInt = self::compileTimeInt($purpose);
        if (null === $purposeInt) {
            throw new \LogicException(
                'openssl_x509_checkpurpose() purpose must be a compile-time int '
                .'for JIT/AOT in this compiler build (issue #32522)'
            );
        }
        $caList = [];
        if (null !== $caInfo) {
            $caList = self::compileTimeStringList($caInfo);
            if (null === $caList) {
                throw new \LogicException(
                    'openssl_x509_checkpurpose() ca_info must be a compile-time string array '
                    .'for JIT/AOT in this compiler build (issue #32522)'
                );
            }
        }
        $untrustedFile = null;
        if (null !== $untrusted) {
            if (JITVariable::TYPE_NULL === $untrusted->type || ($untrusted->isNullConstant ?? false)) {
                $untrustedFile = null;
            } else {
                $untrustedFile = JitStringArg::compileTimeLiteral($untrusted);
                if (null === $untrustedFile) {
                    throw new \LogicException(
                        'openssl_x509_checkpurpose() untrusted_certificates_file must be a compile-time string or null '
                        .'for JIT/AOT in this compiler build (issue #32522)'
                    );
                }
            }
        }

        if (!VmOpensslX509Native::available()) {
            return self::boxedLong($context, -1);
        }
        if (false === VmOpensslX509Native::normalizeCertificatePem($pem)) {
            return self::boxedLong($context, -1);
        }

        $ret = VmOpensslX509Native::checkPurposeCertificatePem(
            $pem,
            $purposeInt,
            $caList,
            $untrustedFile
        );
        if (0 !== $ret && 1 !== $ret) {
            return self::boxedLong($context, $ret);
        }

        return self::boxedBool($context, 1 === $ret);
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

    private static function compileTimeInt(JITVariable $arg): ?int
    {
        if (null !== $arg->compileTimeLong) {
            return (int) $arg->compileTimeLong;
        }
        $lit = JitStringArg::compileTimeLiteral($arg);
        if (null !== $lit && is_numeric($lit)) {
            return (int) $lit;
        }

        return null;
    }

    /**
     * Compile-time string list for $ca_info. Empty omitted-arg is handled by the caller.
     *
     * @return list<string>|null
     */
    private static function compileTimeStringList(JITVariable $arg): ?array
    {
        if (JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false)) {
            return null;
        }
        if (JITVariable::TYPE_HASHTABLE !== $arg->type) {
            return null;
        }
        if ($arg->compileTimeEmptyArrayLiteral) {
            return [];
        }
        if (!\is_array($arg->compileTimeArray)) {
            return null;
        }
        $out = [];
        foreach ($arg->compileTimeArray as $v) {
            if (!\is_string($v) && !\is_int($v) && !\is_float($v) && !\is_bool($v) && null !== $v) {
                return null;
            }
            $out[] = null === $v ? '' : (string) $v;
        }

        return $out;
    }

    private static function boxedFalse(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));

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

    private static function boxedLong(Context $context, int $value): Value
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong($context, $slot, $context->constantFromInteger($value));

        return JitValueBox::pointer($context, $slot);
    }

    private static function boxedBool(Context $context, bool $value): Value
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool($value));

        return JitValueBox::pointer($context, $slot);
    }
}
