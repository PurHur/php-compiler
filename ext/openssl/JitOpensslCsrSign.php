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
 * Compile-time CSR PEM + private key PEM + days → {@see VmOpensslCsrNative::signCsrPem}
 * → bake OpenSSLCertificate with {@see OpensslCertificateJitSupport::PROP_PEM}.
 *
 * php-src: ext/openssl/openssl.c PHP_FUNCTION(openssl_csr_sign)
 */
final class JitOpensslCsrSign
{
    public static function invoke(
        Context $context,
        JITVariable $csr,
        JITVariable $caCertificate,
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

        $caPem = null;
        if (!(JITVariable::TYPE_NULL === $caCertificate->type || ($caCertificate->isNullConstant ?? false))) {
            $caPem = JitStringArg::compileTimeLiteral($caCertificate);
            if (null === $caPem) {
                // null literal often lowers as TYPE_VALUE without isNullConstant (#34060).
                if (JITVariable::TYPE_VALUE !== $caCertificate->type) {
                    throw new \LogicException(
                        'openssl_csr_sign() ca_certificate must be null or a compile-time PEM string '
                        .'literal for JIT/AOT in this compiler build (issue #34060)'
                    );
                }
            }
        }

        $keyPem = JitStringArg::compileTimeLiteral($privateKey);
        if (null === $keyPem) {
            throw new \LogicException(
                'openssl_csr_sign() private_key must be a compile-time PEM string literal '
                .'for JIT/AOT in this compiler build (issue #34060)'
            );
        }

        if (null === $days->compileTimeLong) {
            throw new \LogicException(
                'openssl_csr_sign() days must be a compile-time int literal '
                .'for JIT/AOT in this compiler build (issue #34060)'
            );
        }
        $daysLit = (int) $days->compileTimeLong;

        if (null !== $options) {
            $optIsNull = JITVariable::TYPE_NULL === $options->type
                || ($options->isNullConstant ?? false)
                || JITVariable::TYPE_VALUE === $options->type;
            if (!$optIsNull) {
                throw new \LogicException(
                    'openssl_csr_sign() options must be null for JIT/AOT bake in this compiler build '
                    .'(issue #34060); non-null options are not folded yet'
                );
            }
        }

        $serialLit = 0;
        if (null !== $serial) {
            $serIsNull = JITVariable::TYPE_NULL === $serial->type
                || ($serial->isNullConstant ?? false)
                || JITVariable::TYPE_VALUE === $serial->type;
            if (!$serIsNull) {
                if (null === $serial->compileTimeLong) {
                    throw new \LogicException(
                        'openssl_csr_sign() serial must be a compile-time int literal '
                        .'for JIT/AOT in this compiler build (issue #34060)'
                    );
                }
                $serialLit = (int) $serial->compileTimeLong;
            }
        }

        return self::bakeFromLiterals(
            $context,
            $csrPem,
            $caPem,
            $keyPem,
            $daysLit,
            'sha256',
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
        string $digestAlg,
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
            $digestAlg,
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

    private static function boxedFalse(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));

        return JitValueBox::pointer($context, $slot);
    }
}
