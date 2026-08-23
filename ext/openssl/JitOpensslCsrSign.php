<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for openssl_csr_sign() (#34060 leftover of #33517 / #6421).
 *
 * Compile-time CSR PEM + null CA + private key PEM + days (+ optional serial)
 * → {@see VmOpensslCsrNative::signCsrPem} → bake OpenSSLCertificate with
 * {@see OpensslCertificateJitSupport::PROP_PEM}.
 *
 * php-src: ext/openssl/openssl.c PHP_FUNCTION(openssl_csr_sign)
 */
final class JitOpensslCsrSign
{
    /**
     * @param JITVariable      $csr         OpenSSLCertificateSigningRequest|string
     * @param JITVariable      $caCert      OpenSSLCertificate|string|null
     * @param JITVariable      $privateKey  OpenSSLAsymmetricKey|string
     * @param JITVariable      $days        int
     * @param JITVariable|null $options     ?array
     * @param JITVariable|null $serial      int
     */
    public static function invoke(
        Context $context,
        JITVariable $csr,
        JITVariable $caCert,
        JITVariable $privateKey,
        JITVariable $days,
        ?JITVariable $options = null,
        ?JITVariable $serial = null,
    ): Value {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'ossl_csr_sign');

        $csrPem = JitStringArg::compileTimeLiteral($csr);
        if (null === $csrPem) {
            throw new \LogicException(
                'openssl_csr_sign() csr must be a compile-time CSR PEM string literal '
                .'for JIT/AOT in this compiler build (issue #34060)'
            );
        }

        if (!self::isCompileTimeNull($caCert)) {
            $caPem = JitStringArg::compileTimeLiteral($caCert);
            if (null === $caPem) {
                throw new \LogicException(
                    'openssl_csr_sign() ca_certificate must be null or a compile-time PEM '
                    .'string literal for JIT/AOT in this compiler build (issue #34060)'
                );
            }
        } else {
            $caPem = null;
        }

        $keyPem = JitStringArg::compileTimeLiteral($privateKey);
        if (null === $keyPem) {
            throw new \LogicException(
                'openssl_csr_sign() private_key must be a compile-time PEM string literal '
                .'for JIT/AOT in this compiler build (issue #34060)'
            );
        }

        $daysLit = self::compileTimeInt($days);
        if (null === $daysLit) {
            throw new \LogicException(
                'openssl_csr_sign() days must be a compile-time int '
                .'for JIT/AOT in this compiler build (issue #34060)'
            );
        }

        if (null !== $options && !self::isCompileTimeNull($options)) {
            throw new \LogicException(
                'openssl_csr_sign() options must be null or omitted '
                .'for JIT/AOT in this compiler build (issue #34060)'
            );
        }

        $serialLit = 0;
        if (null !== $serial) {
            $parsed = self::compileTimeInt($serial);
            if (null === $parsed) {
                throw new \LogicException(
                    'openssl_csr_sign() serial must be a compile-time int '
                    .'for JIT/AOT in this compiler build (issue #34060)'
                );
            }
            $serialLit = $parsed;
        }

        return self::bakeFromLiterals(
            $context,
            $csrPem,
            $caPem,
            $keyPem,
            $daysLit,
            $serialLit
        );
    }

    /**
     * Host FFI sign + constant OpenSSLCertificate (#34060 bake path).
     */
    public static function bakeFromLiterals(
        Context $context,
        string $csrPem,
        ?string $caCertPem,
        string $privateKeyPem,
        int $days,
        int $serial,
    ): Value {
        if (!VmOpensslCsrNative::available()) {
            return self::boxedFalse($context);
        }

        $certPem = VmOpensslCsrNative::signCsrPem(
            $csrPem,
            $caCertPem,
            $privateKeyPem,
            $days,
            'sha256',
            $serial
        );
        if (false === $certPem || '' === $certPem) {
            return self::boxedFalse($context);
        }

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $objectType = $context->type->object;
        $className = OpensslCertificateJitSupport::CLASS_NAME;
        $classId = $objectType->lookup($className);
        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);
        $pemStr = $context->builder->load($context->constantStringFromString($certPem));
        $pemVar = new JITVariable(
            $context,
            JITVariable::TYPE_STRING,
            JITVariable::KIND_VALUE,
            $pemStr
        );
        $objectType->storeInstanceProperty(
            $obj,
            $className,
            OpensslCertificateJitSupport::PROP_PEM,
            $pemVar
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $obj
        );

        return $ptr;
    }

    private static function isCompileTimeNull(JITVariable $arg): bool
    {
        return JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false);
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

    private static function boxedFalse(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));

        return JitValueBox::pointer($context, $slot);
    }
}
