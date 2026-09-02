<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * SSOT for LLVM __value__copy lowering — one type-switch per module (#36193).
 *
 * Replaces 176 open-coded {@see \PHPCompiler\JIT\JitValueBox::copyBetweenPointers}
 * expansions (15 basic blocks each). JIT trampoline:
 * {@see \PHPCompiler\JIT\Builtin\ValueBoxCopyJit}
 *
 * php-src: zend/zend_variables.h ZVAL_COPY / zval_add_ref.
 */
final class VmValueCopy
{
    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__value__copy');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__value__copy', $probe);

            return;
        }

        $fn = $context->tryGetRegisteredFunction('__value__copy')
            ?? $context->module->getNamedFunction('__value__copy');
        if (null === $fn) {
            throw new \LogicException('__value__copy shell missing before implement (#36193)');
        }
        self::emitCopy($context, $fn);
        $context->registerFunction('__value__copy', $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function emitCopy(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $destPtr = $fn->getParam(0);
        $srcPtr = $fn->getParam(1);

        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($srcPtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        // Mask IS_REFCOUNTED — HT slots may store VM TYPE_STRING (4) or JIT (4|0x80).
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));

        $stringBlock = $fn->appendBasicBlock('value_copy_string');
        $hashtableBlock = $fn->appendBasicBlock('value_copy_hashtable');
        $objectBlock = $fn->appendBasicBlock('value_copy_object');
        $longBlock = $fn->appendBasicBlock('value_copy_long');
        $doubleBlock = $fn->appendBasicBlock('value_copy_double');
        $boolBlock = $fn->appendBasicBlock('value_copy_bool');
        $nullBlock = $fn->appendBasicBlock('value_copy_null');
        $done = $fn->appendBasicBlock('value_copy_done');

        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_STRING & 0x7f, false)
        );
        $isHashtable = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_HASHTABLE & 0x7f, false)
        );
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_OBJECT & 0x7f, false)
        );
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false)
        );
        $isDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)
        );
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_NULL, false)
        );

        $afterString = $fn->appendBasicBlock('value_copy_after_string');
        $context->builder->branchIf($isString, $stringBlock, $afterString);

        $context->builder->positionAtEnd($stringBlock);
        $str = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $srcPtr
        );
        self::writeStringToValuePtrByAddref($context, $destPtr, $str);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterString);
        $afterHashtable = $fn->appendBasicBlock('value_copy_after_hashtable');
        $context->builder->branchIf($isHashtable, $hashtableBlock, $afterHashtable);

        $context->builder->positionAtEnd($hashtableBlock);
        $ht = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $srcPtr
        );
        $context->refcount->addref($ht);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $destPtr,
            $ht
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterHashtable);
        $afterObject = $fn->appendBasicBlock('value_copy_after_object');
        $context->builder->branchIf($isObject, $objectBlock, $afterObject);

        $context->builder->positionAtEnd($objectBlock);
        $obj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $srcPtr
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $destPtr,
            $obj
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterObject);
        $afterLong = $fn->appendBasicBlock('value_copy_after_long');
        $context->builder->branchIf($isLong, $longBlock, $afterLong);

        $context->builder->positionAtEnd($longBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $destPtr,
            $context->builder->call($context->lookupFunction('__value__readLong'), $srcPtr)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterLong);
        $afterBool = $fn->appendBasicBlock('value_copy_after_bool');
        $context->builder->branchIf($isBool, $boolBlock, $afterBool);

        $context->builder->positionAtEnd($boolBlock);
        $boolByte = JitValueBox::readBoolByte($context, $srcPtr);
        $i32 = $context->getTypeFromString('int32');
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $destPtr,
            $context->builder->zExt(
                $context->builder->icmp(
                    Builder::INT_NE,
                    $boolByte,
                    $i8->constInt(0, false)
                ),
                $i32
            )
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterBool);
        $afterDouble = $fn->appendBasicBlock('value_copy_after_double');
        $context->builder->branchIf($isDouble, $doubleBlock, $afterDouble);

        $context->builder->positionAtEnd($doubleBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $destPtr,
            $context->builder->call($context->lookupFunction('__value__readDouble'), $srcPtr)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterDouble);
        $context->builder->branchIf($isNull, $nullBlock, $done);

        $context->builder->positionAtEnd($nullBlock);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $destPtr);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
    }

    /**
     * Zend zend_string_copy semantics — addref, not __string__separate (#36192).
     */
    private static function writeStringToValuePtrByAddref(Context $context, Value $destPtr, Value $strPtr): void
    {
        $context->refcount->addref($strPtr);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $destPtr,
            $strPtr
        );
    }
}
