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
     * Non-string material yields an empty string so DETAILS_PUB soft-fails to false.
     */
    private static function resolvePemString(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_STRING === $arg->type) {
            return $context->helper->loadValue($arg);
        }

        if (JITVariable::TYPE_OBJECT === $arg->type) {
            $class = $arg->classUserType;
            if (null === $class || '' === $class
                || 0 === \strcasecmp($class, OpensslPkeyNewJitSupport::CLASS_NAME)) {
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

        // Hashtable dim fetch / mixed value-box from openssl_pkey_get_details()['key'] (#34038).
        // __value__readString returns null for non-string tags — map to empty so DETAILS_PUB fails soft.
        $fromBox = JitValueBox::readStringOrNull($context, $arg);
        $strPtrTy = $context->getTypeFromString('__string__*');
        $id = (string) (++self::$blockSerial);
        $empty = $context->builder->load($context->constantStringFromString(''));
        $nullBlock = BasicBlockHelper::append($context, 'ossl_pkey_gp_pem_null_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'ossl_pkey_gp_pem_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'ossl_pkey_gp_pem_done_'.$id);
        $phiSlot = $context->builder->alloca($strPtrTy);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $fromBox, $strPtrTy->constNull());
        $context->builder->branchIf($isNull, $nullBlock, $okBlock);

        $context->builder->positionAtEnd($nullBlock);
        $context->builder->store($empty, $phiSlot);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->store($fromBox, $phiSlot);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $context->builder->load($phiSlot);
    }
}
