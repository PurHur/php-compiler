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
 * openssl_pkey_new() happy-path bake — OpenSSLAsymmetricKey + runtime RSA keygen (#34015).
 *
 * php-src: ext/openssl/openssl.c PHP_FUNCTION(openssl_pkey_new)
 */
final class JitOpensslPkeyNew
{
    private static int $blockSerial = 0;

    /**
     * Emit RSA keygen (default 2048) and wrap as OpenSSLAsymmetricKey with PEM property.
     *
     * @param Value|null $bitsI64 native int64 bits; null → 2048
     */
    public static function emitRsaKeyObject(Context $context, ?Value $bitsI64 = null): Value
    {
        JitOpensslPkeyKernel::ensureKeygenLeaf($context);

        $i64 = $context->getTypeFromString('int64');
        $bits = $bitsI64 ?? $i64->constInt(2048, false);
        $pemStr = $context->builder->call(
            $context->lookupFunction(JitOpensslPkeyKernel::EVP_RSA_KEYGEN),
            $bits
        );

        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, 'opn_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'opn_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'opn_done_'.$id);

        $strPtrTy = $context->getTypeFromString('__string__*');
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $pemStr,
            $strPtrTy->constNull()
        );
        $context->builder->branchIf($isNull, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $failSlot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $failSlot, $context->constantFromBool(false));
        $failPtr = JitValueBox::pointer($context, $failSlot);
        $failTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $objectType = $context->type->object;
        $classId = $objectType->lookup(OpensslAsymmetricKeyJitSupport::CLASS_NAME);
        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);
        $pemVar = new JITVariable(
            $context,
            JITVariable::TYPE_STRING,
            JITVariable::KIND_VALUE,
            $pemStr
        );
        $objectType->storeInstanceProperty(
            $obj,
            OpensslAsymmetricKeyJitSupport::CLASS_NAME,
            OpensslAsymmetricKeyJitSupport::PROP_PEM,
            $pemVar
        );
        $okSlot = JitValueBox::alloc($context);
        $okPtr = JitValueBox::pointer($context, $okSlot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $okPtr,
            $obj
        );
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($context->getTypeFromString('__value__*'));
        $phi->addIncoming($failPtr, $failTail);
        $phi->addIncoming($okPtr, $okTail);

        return $phi;
    }
}
