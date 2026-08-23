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
 * LLVM lowering for openssl_csr_get_public_key() (#34054 leftover of #33514 / #6421).
 *
 * Compile-time CSR PEM string → {@see VmOpensslCsrNative::getPublicKeyPem} → bake
 * OpenSSLAsymmetricKey with {@see OpensslPkeyNewJitSupport::PROP_PEM}.
 *
 * php-src: ext/openssl/xp.c PHP_FUNCTION(openssl_csr_get_public_key)
 */
final class JitOpensslCsrGetPublicKey
{
    public static function invoke(Context $context, JITVariable $csr): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'ossl_csr_get_public_key');

        $pemLit = JitStringArg::compileTimeLiteral($csr);
        if (null !== $pemLit) {
            return self::bakeFromCsrPemLiteral($context, $pemLit);
        }

        throw new \LogicException(
            'openssl_csr_get_public_key() csr must be a compile-time CSR PEM string literal '
            .'for JIT/AOT in this compiler build (issue #34054)'
        );
    }

    /**
     * Host FFI extract public key PEM + constant OpenSSLAsymmetricKey (#34054 bake path).
     */
    public static function bakeFromCsrPemLiteral(Context $context, string $csrPem): Value
    {
        if (!VmOpensslCsrNative::available()) {
            return self::boxedFalse($context);
        }

        $pubPem = VmOpensslCsrNative::getPublicKeyPem($csrPem);
        if (false === $pubPem || '' === $pubPem) {
            return self::boxedFalse($context);
        }

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $objectType = $context->type->object;
        $className = OpensslPkeyNewJitSupport::CLASS_NAME;
        $classId = $objectType->lookup($className);
        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);
        $pemStr = $context->builder->load($context->constantStringFromString($pubPem));
        $pemVar = new JITVariable(
            $context,
            JITVariable::TYPE_STRING,
            JITVariable::KIND_VALUE,
            $pemStr
        );
        $objectType->storeInstanceProperty(
            $obj,
            $className,
            OpensslPkeyNewJitSupport::PROP_PEM,
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
