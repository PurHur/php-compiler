<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for openssl_pkey_get_public() / openssl_get_publickey() (#34038 leftover of #33499).
 *
 * Thin AOT + JIT: reuse {@see JitOpensslPkeyGetDetailsKernel::DETAILS_PUB} (PEM → public PEM),
 * then wrap {@see OpensslPkeyNewJitSupport::CLASS_NAME}. No new runtime/*.c.
 *
 * php-src: ext/openssl/openssl.c PHP_FUNCTION(openssl_pkey_get_public)
 */
final class JitOpensslPkeyGetPublic
{
    private static int $blockSerial = 0;

    public static function fromArg(Context $context, JITVariable $arg): Value
    {
        JitOpensslPkeyGetDetailsKernel::ensureLeaves($context);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'ossl_pkey_get_public');

        $pemStr = self::resolvePemString($context, $arg);
        $pubPem = $context->builder->call(
            $context->lookupFunction(JitOpensslPkeyGetDetailsKernel::DETAILS_PUB),
            $pemStr
        );

        $id = (string) (++self::$blockSerial);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, 'ossl_pkey_gp_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'ossl_pkey_gp_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'ossl_pkey_gp_done_'.$id);

        $strPtrTy = $context->getTypeFromString('__string__*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $pubPem, $strPtrTy->constNull());
        $context->builder->branchIf($isNull, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $objectType = $context->type->object;
        $className = OpensslPkeyNewJitSupport::CLASS_NAME;
        $classId = $objectType->lookup($className);
        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);
        $pemVar = new JITVariable(
            $context,
            JITVariable::TYPE_STRING,
            JITVariable::KIND_VALUE,
            $pubPem
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
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    /**
     * String / value-box string / OpenSSLAsymmetricKey::__osslPem → __string__*.
     * Non-key material yields an empty string so callers soft-fail (DETAILS_PUB / EVP sign).
     *
     * Shared with {@see JitOpensslSign} (openssl_sign/verify key args; #34715).
     * Value-boxed OpenSSLAsymmetricKey from openssl_pkey_new() is TYPE_VALUE at the
     * call site — must read the object tag, not only compile-time TYPE_OBJECT.
     */
    public static function resolvePemString(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_STRING === $arg->type) {
            return $context->helper->loadValue($arg);
        }

        if (JITVariable::TYPE_OBJECT === $arg->type) {
            $class = $arg->classUserType;
            if (null === $class || '' === $class
                || 0 === \strcasecmp($class, OpensslPkeyNewJitSupport::CLASS_NAME)) {
                return self::loadPemFromObjectValue($context, $arg);
            }
        }

        // Runtime value-box: string PEM or OpenSSLAsymmetricKey (#34038 / #34715).
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $typeKind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $stringTy = $i8->constInt(JITVariable::TYPE_STRING & 0x7f, false);
        $objectTy = $i8->constInt(JITVariable::TYPE_OBJECT & 0x7f, false);

        $strPtrTy = $context->getTypeFromString('__string__*');
        $id = (string) (++self::$blockSerial);
        $empty = $context->builder->load($context->constantStringFromString(''));
        $phiSlot = $context->builder->alloca($strPtrTy);

        $stringBlock = BasicBlockHelper::append($context, 'ossl_pkey_pem_str_'.$id);
        $objectBlock = BasicBlockHelper::append($context, 'ossl_pkey_pem_obj_'.$id);
        $emptyBlock = BasicBlockHelper::append($context, 'ossl_pkey_pem_empty_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'ossl_pkey_pem_done_'.$id);
        $afterStringCheck = BasicBlockHelper::append($context, 'ossl_pkey_pem_after_str_'.$id);

        $isString = $context->builder->icmp(Builder::INT_EQ, $typeKind, $stringTy);
        $context->builder->branchIf($isString, $stringBlock, $afterStringCheck);

        $context->builder->positionAtEnd($stringBlock);
        $fromStr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valuePtr
        );
        $context->builder->store($fromStr, $phiSlot);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterStringCheck);
        $isObject = $context->builder->icmp(Builder::INT_EQ, $typeKind, $objectTy);
        $context->builder->branchIf($isObject, $objectBlock, $emptyBlock);

        $context->builder->positionAtEnd($objectBlock);
        $obj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
        $strVar = $context->type->object->propertyFetch(
            $obj,
            OpensslPkeyNewJitSupport::CLASS_NAME,
            OpensslPkeyNewJitSupport::PROP_PEM
        );
        $pem = $context->helper->loadValue($strVar);
        $context->builder->store($pem, $phiSlot);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $context->builder->store($empty, $phiSlot);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $context->builder->load($phiSlot);
    }

    private static function loadPemFromObjectValue(Context $context, JITVariable $arg): Value
    {
        $obj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            JitValueBox::valuePtrFromVariable($context, $arg)
        );
        $strVar = $context->type->object->propertyFetch(
            $obj,
            OpensslPkeyNewJitSupport::CLASS_NAME,
            OpensslPkeyNewJitSupport::PROP_PEM
        );

        return $context->helper->loadValue($strVar);
    }
}
