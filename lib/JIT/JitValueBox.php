<?php

declare(strict_types=1);

/**
 * Allocate and write boxed {@see __value__} slots in JIT code.
 */

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitValueBox
{
    private static int $copySeq = 0;

    public static function alloc(Context $context): Value
    {
        $slot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__'));
        // LLVM alloca is uninitialized; __value__write* calls valueDelref first (issue #AOT heap).
        $map = $context->structFieldMap['__value__'];
        $context->builder->store(
            $context->getTypeFromString('int8')->constInt(Variable::TYPE_NULL, false),
            $context->builder->structGep($slot, $map['type'])
        );

        return $slot;
    }

    public static function pointer(Context $context, Value $slot): Value
    {
        return $context->builder->pointerCast(
            $slot,
            $context->getTypeFromString('__value__*')
        );
    }

    /**
     * {@see __value__*} for a boxed {@see Variable::TYPE_VALUE} (by-value or alloca slot).
     */
    public static function valuePtrFromVariable(Context $context, Variable $var): Value
    {
        if (Variable::TYPE_VALUE !== $var->type) {
            throw new \LogicException('valuePtrFromVariable requires TYPE_VALUE');
        }
        if (Variable::KIND_VARIABLE === $var->kind) {
            $llvmType = $context->getStringFromType($var->value->typeOf());
            if ('__value__*' === $llvmType) {
                return $var->value;
            }
            if ('__value__' === $llvmType) {
                return self::pointer($context, $var->value);
            }
            if ('__string__**' === $llvmType) {
                $str = $context->builder->load($var->value);
                $slot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__'));
                $context->builder->call(
                    $context->lookupFunction('__value__writeString'),
                    self::pointer($context, $slot),
                    $str
                );

                return self::pointer($context, $slot);
            }
            if ('__string__*' === $llvmType) {
                $slot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__'));
                $context->builder->call(
                    $context->lookupFunction('__value__writeString'),
                    self::pointer($context, $slot),
                    $var->value
                );

                return self::pointer($context, $slot);
            }

            return self::pointer($context, $var->value);
        }
        if ('__value__*' === $context->getStringFromType($var->value->typeOf())) {
            return $var->value;
        }
        $slot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__'));
        $context->builder->store($var->value, $slot);

        return self::pointer($context, $slot);
    }

    public static function writeLong(Context $context, Value $slot, Value $long): void
    {
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            self::pointer($context, $slot),
            $long
        );
    }

    /**
     * Copy a boxed value from a {@see __value__*} slot into a stack {@see __value__} alloca.
     */
    public static function copyFromPointer(Context $context, Value $destSlot, Value $srcPtr): void
    {
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($srcPtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $destPtr = self::pointer($context, $destSlot);

        $tag = 'v'.(string) self::$copySeq++;
        $stringBlock = BasicBlockHelper::append($context, 'value_copy_string_'.$tag);
        $hashtableBlock = BasicBlockHelper::append($context, 'value_copy_hashtable_'.$tag);
        $objectBlock = BasicBlockHelper::append($context, 'value_copy_object_'.$tag);
        $longBlock = BasicBlockHelper::append($context, 'value_copy_long_'.$tag);
        $doubleBlock = BasicBlockHelper::append($context, 'value_copy_double_'.$tag);
        $boolBlock = BasicBlockHelper::append($context, 'value_copy_bool_'.$tag);
        $nullBlock = BasicBlockHelper::append($context, 'value_copy_null_'.$tag);
        $done = BasicBlockHelper::append($context, 'value_copy_done_'.$tag);

        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING, false)
        );
        $isHashtable = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_HASHTABLE, false)
        );
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_OBJECT, false)
        );
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false)
        );
        $isDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)
        );
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NULL, false)
        );

        $afterString = BasicBlockHelper::append($context, 'value_copy_after_string_'.$tag);
        $context->builder->branchIf($isString, $stringBlock, $afterString);

        $context->builder->positionAtEnd($stringBlock);
        $str = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $srcPtr
        );
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $str
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $destPtr,
            $owned
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterString);
        $afterHashtable = BasicBlockHelper::append($context, 'value_copy_after_hashtable_'.$tag);
        $context->builder->branchIf($isHashtable, $hashtableBlock, $afterHashtable);

        $context->builder->positionAtEnd($hashtableBlock);
        $ht = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $srcPtr
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $destPtr,
            $ht
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterHashtable);
        $afterObject = BasicBlockHelper::append($context, 'value_copy_after_object_'.$tag);
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
        $afterLong = BasicBlockHelper::append($context, 'value_copy_after_long_'.$tag);
        $context->builder->branchIf($isLong, $longBlock, $afterLong);

        $context->builder->positionAtEnd($longBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $destPtr,
            $context->builder->call($context->lookupFunction('__value__readLong'), $srcPtr)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterLong);
        $afterBool = BasicBlockHelper::append($context, 'value_copy_after_bool_'.$tag);
        $context->builder->branchIf($isBool, $boolBlock, $afterBool);

        $context->builder->positionAtEnd($boolBlock);
        self::writeBool(
            $context,
            $destSlot,
            $context->builder->truncOrBitCast(
                $context->builder->call($context->lookupFunction('__value__readLong'), $srcPtr),
                $context->getTypeFromString('int1')
            )
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterBool);
        $afterDouble = BasicBlockHelper::append($context, 'value_copy_after_double_'.$tag);
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
    }

    public static function writeBool(Context $context, Value $slot, Value $bool): void
    {
        $map = $context->structFieldMap['__value__'];
        $ptr = self::pointer($context, $slot);
        $i8 = $context->getTypeFromString('int8');
        $context->builder->store(
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false),
            $context->builder->structGep($ptr, $map['type'])
        );
        $boolByte = $context->builder->truncOrBitCast($bool, $i8);
        $valueField = $context->builder->structGep($ptr, $map['value']);
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $firstByte = $context->builder->inBoundsGEP(
            $valueField,
            $i32->constInt(0, false),
            $i64->constInt(0, false)
        );
        $context->builder->store($boolByte, $firstByte);
    }
}
