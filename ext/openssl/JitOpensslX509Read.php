<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for openssl_x509_read() (#34048 leftover of #33497 / #7268).
 *
 * Compile-time PEM string → bake OpenSSLCertificate with {@see OpensslCertificateJitSupport::PROP_PEM}.
 * Known OpenSSLCertificate object → passthrough.
 *
 * php-src: ext/openssl/xp.c PHP_FUNCTION(openssl_x509_read)
 */
final class JitOpensslX509Read
{
    public static function invoke(Context $context, JITVariable $certificate): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'ossl_x509_read');

        $pemLit = JitStringArg::compileTimeLiteral($certificate);
        if (null !== $pemLit) {
            return self::bakeFromPemLiteral($context, $pemLit);
        }

        // OpenSSLCertificate object or value-box result of a prior bake (#34048).
        if (JITVariable::TYPE_OBJECT === $certificate->type
            || self::isCertificateObject($certificate)
            || JITVariable::TYPE_VALUE === $certificate->type
        ) {
            return self::boxObjectArg($context, $certificate);
        }

        throw new \LogicException(
            'openssl_x509_read() certificate must be a compile-time PEM string literal or '
            .'OpenSSLCertificate for JIT/AOT in this compiler build (issue #34048)'
        );
    }

    /**
     * Host FFI normalize + constant PEM on OpenSSLCertificate (#34048 bake path).
     */
    public static function bakeFromPemLiteral(Context $context, string $pem): Value
    {
        if (!VmOpensslX509Native::available()) {
            return self::boxedFalse($context);
        }

        $normalized = VmOpensslX509Native::normalizeCertificatePem($pem);
        if (false === $normalized || '' === $normalized) {
            return self::boxedFalse($context);
        }

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $objectType = $context->type->object;
        $className = OpensslCertificateJitSupport::CLASS_NAME;
        $classId = $objectType->lookup($className);
        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);
        $pemStr = $context->builder->load($context->constantStringFromString($normalized));
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

    private static function isCertificateObject(JITVariable $arg): bool
    {
        if (JITVariable::TYPE_OBJECT !== $arg->type) {
            return false;
        }
        $class = $arg->classUserType;
        if (null === $class || '' === $class) {
            return true;
        }

        return 0 === \strcasecmp($class, OpensslCertificateJitSupport::CLASS_NAME);
    }

    private static function boxObjectArg(Context $context, JITVariable $certificate): Value
    {
        $obj = ReflectionSetup::loadObjectFromArg($context, $certificate);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
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
