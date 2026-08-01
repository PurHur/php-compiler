<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\DynamicPropertyDeprecationGuard;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\HashTableReadLlvm;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT lowering for array_key_exists()/key_exists() (#13735, #14545, #9331).
 *
 * Hashtable + native arrays lower in LLVM; php-src: ext/standard/array.c — PHP_FUNCTION(array_key_exists).
 */
final class ArrayKeyExistsRuntime
{
    public static function keyExists(
        Context $context,
        JITVariable $key,
        JITVariable $array,
        string $function
    ): Value {
        if ($array->type & JITVariable::IS_NATIVE_ARRAY) {
            return self::nativeArrayKeyExists($context, $key, $array, $function);
        }
        if (JITVariable::TYPE_OBJECT === $key->type) {
            HashTableHelper::emitIllegalOffsetType($context, 'Illegal offset type');

            return $context->constantFromInteger(0, 'int1');
        }

        $ht = JITVariable::TYPE_HASHTABLE === $array->type
            ? $context->helper->loadValue($array)
            : ArrayBuiltinHelper::loadHashTable($context, $array);

        if (JITVariable::TYPE_NULL === $key->type || ($key->isNullConstant ?? false)) {
            DynamicPropertyDeprecationGuard::emitNullArrayKeyExists($context);
            if (JITVariable::TYPE_VALUE === $key->type) {
                return self::hashtableKeyExistsValueBoxKey($context, $ht, $key);
            }

            return self::hashtableKeyExistsStringKey(
                $context,
                $ht,
                $context->builder->load($context->constantStringFromString(''))
            );
        }
        if (JITVariable::TYPE_STRING === $key->type) {
            return self::hashtableKeyExistsStringKey(
                $context,
                $ht,
                JitStringArg::lower($context, $key, $function.'() key')
            );
        }
        if (JITVariable::TYPE_NATIVE_LONG === $key->type) {
            $index = $context->builder->truncOrBitCast(
                $context->helper->loadValue($key),
                $context->getTypeFromString('size_t')
            );

            return self::hashtableKeyExistsIndex($context, $ht, $index);
        }
        if (JITVariable::TYPE_NATIVE_DOUBLE === $key->type) {
            $index = $context->builder->truncOrBitCast(
                $context->builder->call(
                    $context->lookupFunction('__value__doubleToLong'),
                    $context->helper->loadValue($key)
                ),
                $context->getTypeFromString('size_t')
            );

            return self::hashtableKeyExistsIndex($context, $ht, $index);
        }
        if (JITVariable::TYPE_NATIVE_BOOL === $key->type) {
            $index = $context->builder->zExt(
                $context->helper->loadValue($key),
                $context->getTypeFromString('size_t')
            );

            return self::hashtableKeyExistsIndex($context, $ht, $index);
        }
        if (JITVariable::TYPE_VALUE === $key->type) {
            return self::hashtableKeyExistsValueBoxKey($context, $ht, $key);
        }

        throw new \LogicException(
            $function.'() key type not supported in this compiler build: '
            .JITVariable::getStringType($key->type)
        );
    }

    public static function ensureLinked(Context $context): void
    {
        // LLVM lowering only — no nested PHP helper bridge (#9331).
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
    }

    public static function implement(Context $context): void
    {
    }

    private static function nativeArrayKeyExists(
        Context $context,
        JITVariable $key,
        JITVariable $array,
        string $function
    ): Value {
        if (JITVariable::TYPE_NULL === $key->type
            || JITVariable::TYPE_STRING === $key->type
            || JITVariable::TYPE_VALUE === $key->type) {
            return $context->constantFromInteger(0, 'int1');
        }
        if (JITVariable::TYPE_NATIVE_LONG !== $key->type) {
            throw new \LogicException(
                $function.'() on native arrays only supports integer keys in this compiler build'
            );
        }
        $index = JitLongArg::lower($context, $key, $function.'() key');
        $size = $context->constantFromInteger($array->nextFreeElement, 'int32');
        $i32 = $context->getTypeFromString('int32');
        $inRange = $context->builder->icmp(Builder::INT_SLT, $index, $size);
        $nonNeg = $context->builder->icmp(Builder::INT_SGE, $index, $i32->constInt(0, false));

        return $context->builder->and($inRange, $nonNeg);
    }

    private static function hashtableKeyExistsStringKey(Context $context, Value $ht, Value $keyStr): Value
    {
        $valPtr = $context->builder->call(
            $context->lookupFunction('__hashtable__peekStringKeyValue'),
            $ht,
            $keyStr
        );

        return $context->builder->icmp(
            Builder::INT_NE,
            $valPtr,
            $valPtr->typeOf()->constNull()
        );
    }

    private static function hashtableKeyExistsIndex(Context $context, Value $ht, Value $index): Value
    {
        $map = $context->structFieldMap['__hashtable__'];
        $valueMap = $context->structFieldMap['__value__'];
        $i1 = $context->getTypeFromString('int1');
        $i8 = $context->getTypeFromString('int8');
        $nextFree = $context->builder->load(
            $context->builder->structGep($ht, $map['nextFreeElement'])
        );
        $inRange = $context->builder->icmp(Builder::INT_ULT, $index, $nextFree);
        $fn = $context->builder->getInsertBlock()->getParent();
        $ok = $fn->appendBasicBlock('ake_idx_ok');
        $no = $fn->appendBasicBlock('ake_idx_no');
        $merge = $fn->appendBasicBlock('ake_idx_merge');
        $context->builder->branchIf($inRange, $ok, $no);
        $context->builder->positionAtEnd($no);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($ok);
        $values = $context->builder->load($context->builder->structGep($ht, $map['values']));
        $entry = $context->builder->inBoundsGep($values, $index);
        $typeByte = $context->builder->load(
            $context->builder->structGep($entry, $valueMap['type'])
        );
        $exists = $context->builder->icmp(
            Builder::INT_NE,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_UNDEFINED, false)
        );
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($exists, $ok);
        $phi->addIncoming($i1->constInt(0, false), $no);

        return $phi;
    }

    private static function hashtableKeyExistsValueBoxKey(
        Context $context,
        Value $ht,
        JITVariable $dim
    ): Value {
        $valPtr = HashTableReadLlvm::valuePtrFromDim($context, $dim);
        $valueMap = $context->structFieldMap['__value__'];
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');
        $sizeT = $context->getTypeFromString('size_t');
        $typeByte = $context->builder->load(
            $context->builder->structGep($valPtr, $valueMap['type'])
        );
        $fn = $context->builder->getInsertBlock()->getParent();
        $nullBlock = $fn->appendBasicBlock('ake_vk_null');
        $stringBlock = $fn->appendBasicBlock('ake_vk_str');
        $longBlock = $fn->appendBasicBlock('ake_vk_long');
        $objectBlock = $fn->appendBasicBlock('ake_vk_obj');
        $falseBlock = $fn->appendBasicBlock('ake_vk_false');
        $merge = $fn->appendBasicBlock('ake_vk_merge');
        $afterNull = $fn->appendBasicBlock('ake_vk_after_null');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(JITVariable::TYPE_NULL, false)
            ),
            $nullBlock,
            $afterNull
        );
        $context->builder->positionAtEnd($nullBlock);
        DynamicPropertyDeprecationGuard::emitNullArrayKeyExists($context);
        $nullResult = self::hashtableKeyExistsStringKey(
            $context,
            $ht,
            $context->builder->load($context->constantStringFromString(''))
        );
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($afterNull);
        $afterString = $fn->appendBasicBlock('ake_vk_after_str');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(JITVariable::TYPE_STRING, false)
            ),
            $stringBlock,
            $afterString
        );
        $context->builder->positionAtEnd($stringBlock);
        $keyStr = $context->builder->call($context->lookupFunction('__value__readString'), $valPtr);
        $strResult = self::hashtableKeyExistsStringKey($context, $ht, $keyStr);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($afterString);
        $afterLong = $fn->appendBasicBlock('ake_vk_after_long');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(JITVariable::TYPE_NATIVE_LONG, false)
            ),
            $longBlock,
            $afterLong
        );
        $context->builder->positionAtEnd($longBlock);
        $index = $context->builder->truncOrBitCast(
            $context->builder->call($context->lookupFunction('__value__readLong'), $valPtr),
            $sizeT
        );
        $longResult = self::hashtableKeyExistsIndex($context, $ht, $index);
        $afterLongIdx = $fn->appendBasicBlock('ake_vk_after_long_idx');
        $context->builder->branch($afterLongIdx);
        $context->builder->positionAtEnd($afterLongIdx);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($afterLong);
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(JITVariable::TYPE_OBJECT, false)
            ),
            $objectBlock,
            $falseBlock
        );
        $context->builder->positionAtEnd($objectBlock);
        HashTableHelper::emitIllegalOffsetType($context, 'Illegal offset type');
        $objResult = $i1->constInt(0, false);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($falseBlock);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($nullResult, $nullBlock);
        $phi->addIncoming($strResult, $stringBlock);
        $phi->addIncoming($longResult, $afterLongIdx);
        $phi->addIncoming($objResult, $objectBlock);
        $phi->addIncoming($i1->constInt(0, false), $falseBlock);

        return $phi;
    }
}
