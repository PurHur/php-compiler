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
 * LLVM lowering for openssl_pkey_get_private() (#34037 leftover of #33508 / #6295).
 *
 * Compile-time PEM string → bake OpenSSLAsymmetricKey with {@see OpensslPkeyNewJitSupport::PROP_PEM}.
 * Known OpenSSLAsymmetricKey object → passthrough.
 *
 * php-src: ext/openssl/openssl.c PHP_FUNCTION(openssl_pkey_get_private)
 */
final class JitOpensslPkeyGetPrivate
{
    /**
     * @param JITVariable      $key         OpenSSLAsymmetricKey|string
     * @param JITVariable|null $passphrase  ?string
     */
    public static function invoke(Context $context, JITVariable $key, ?JITVariable $passphrase = null): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'ossl_pkey_get_private');

        $pass = self::compileTimePassphrase($passphrase);
        if (null === $pass) {
            throw new \LogicException(
                'openssl_pkey_get_private() passphrase must be a compile-time string or null '
                .'for JIT/AOT in this compiler build (issue #34037)'
            );
        }

        $pemLit = JitStringArg::compileTimeLiteral($key);
        if (null !== $pemLit) {
            return self::bakeFromPemLiteral($context, $pemLit, $pass[0]);
        }

        if (self::isAsymmetricKeyObject($key)) {
            // Zend re-wraps; passthrough of the same object is observationally fine for class/PEM.
            return self::boxObjectArg($context, $key);
        }

        throw new \LogicException(
            'openssl_pkey_get_private() key must be a compile-time PEM string literal or '
            .'OpenSSLAsymmetricKey for JIT/AOT in this compiler build (issue #34037)'
        );
    }

    /**
     * Host FFI normalize + constant PEM on OpenSSLAsymmetricKey (#34037 bake path).
     */
    public static function bakeFromPemLiteral(Context $context, string $pem, ?string $passphrase): Value
    {
        if (!VmOpensslPkeyNative::available()) {
            return self::boxedFalse($context);
        }

        $normalized = VmOpensslPkeyNative::normalizePrivateKeyPem($pem, $passphrase);
        if (false === $normalized || '' === $normalized) {
            return self::boxedFalse($context);
        }

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $objectType = $context->type->object;
        $className = OpensslPkeyNewJitSupport::CLASS_NAME;
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

    /**
     * @return array{0: ?string}|null null when passphrase is not compile-time foldable
     */
    private static function compileTimePassphrase(?JITVariable $passphrase): ?array
    {
        if (null === $passphrase) {
            return [null];
        }
        if (JITVariable::TYPE_NULL === $passphrase->type || ($passphrase->isNullConstant ?? false)) {
            return [null];
        }
        $lit = JitStringArg::compileTimeLiteral($passphrase);
        if (null !== $lit) {
            return [$lit];
        }

        return null;
    }

    private static function isAsymmetricKeyObject(JITVariable $arg): bool
    {
        if (JITVariable::TYPE_OBJECT !== $arg->type) {
            return false;
        }
        $class = $arg->classUserType;
        if (null === $class || '' === $class) {
            return true;
        }

        return 0 === \strcasecmp($class, 'OpenSSLAsymmetricKey');
    }

    private static function boxObjectArg(Context $context, JITVariable $key): Value
    {
        $obj = ReflectionSetup::loadObjectFromArg($context, $key);
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
