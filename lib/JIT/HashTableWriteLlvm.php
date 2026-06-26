<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\VM\HashTableJitHelper;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM hashtable write lowering split from HashTableHelper (#10031). */
final class HashTableWriteLlvm
{
    private static int $seq = 0;

    private static function nextSeq(): int
    {
        return ++self::$seq;
    }

    private static function ownedString(Context $context, Variable $element): Value
    {
        $str = JitStringArg::stringPtrFromVariable($context, $element);

        return $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $str
        );
    }

    private static function setValueBoxAtIndex(
        Context $context,
        Value $ht,
        Value $index,
        Variable $element
    ): void {
        $tag = (string) self::nextSeq();
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $element);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');

        $stringBlock = BasicBlockHelper::append($context, 'ht_idx_vb_string_'.$tag);
        $longBlock = BasicBlockHelper::append($context, 'ht_idx_vb_long_'.$tag);
        $boolBlock = BasicBlockHelper::append($context, 'ht_idx_vb_bool_'.$tag);
        $doubleBlock = BasicBlockHelper::append($context, 'ht_idx_vb_double_'.$tag);
        $nullBlock = BasicBlockHelper::append($context, 'ht_idx_vb_null_'.$tag);
        $objectBlock = BasicBlockHelper::append($context, 'ht_idx_vb_object_'.$tag);
        $enumCaseBlock = BasicBlockHelper::append($context, 'ht_idx_vb_enum_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_idx_vb_done_'.$tag);

        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING, false)
        );
        $checkLong = BasicBlockHelper::append($context, 'ht_idx_vb_check_long_'.$tag);
        $context->builder->branchIf($isString, $stringBlock, $checkLong);

        $context->builder->positionAtEnd($checkLong);
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );
        $checkBool = BasicBlockHelper::append($context, 'ht_idx_vb_check_bool_'.$tag);
        $context->builder->branchIf($isLong, $longBlock, $checkBool);

        $context->builder->positionAtEnd($checkBool);
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false)
        );
        $checkDouble = BasicBlockHelper::append($context, 'ht_idx_vb_check_double_'.$tag);
        $context->builder->branchIf($isBool, $boolBlock, $checkDouble);

        $context->builder->positionAtEnd($checkDouble);
        $isDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)
        );
        $checkNull = BasicBlockHelper::append($context, 'ht_idx_vb_check_null_'.$tag);
        $context->builder->branchIf($isDouble, $doubleBlock, $checkNull);

        $context->builder->positionAtEnd($checkNull);
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NULL, false)
        );
        $checkObject = BasicBlockHelper::append($context, 'ht_idx_vb_check_object_'.$tag);
        $context->builder->branchIf($isNull, $nullBlock, $checkObject);

        $context->builder->positionAtEnd($checkObject);
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_OBJECT, false)
        );
        $checkEnumCase = BasicBlockHelper::append($context, 'ht_idx_vb_check_enum_'.$tag);
        $context->builder->branchIf($isObject, $objectBlock, $checkEnumCase);

        $context->builder->positionAtEnd($checkEnumCase);
        $isEnumCase = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(\PHPCompiler\VM\Variable::TYPE_ENUM_CASE, false)
        );
        $context->builder->branchIf($isEnumCase, $enumCaseBlock, $longBlock);

        $context->builder->positionAtEnd($stringBlock);
        $str = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valuePtr
        );
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $str
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringAt'),
            $ht,
            $index,
            $owned
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($longBlock);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setLongAt'),
            $ht,
            $index,
            $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($boolBlock);
        $valueField = $context->builder->structGep($valuePtr, $map['value']);
        $boolByte = $context->builder->load(
            $context->builder->inBoundsGEP(
                $valueField,
                $context->getTypeFromString('int32')->constInt(0, false),
                $context->getTypeFromString('int64')->constInt(0, false)
            )
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setBoolAt'),
            $ht,
            $index,
            $context->builder->icmp(Builder::INT_NE, $boolByte, $i8->constInt(0, false))
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($doubleBlock);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setDoubleAt'),
            $ht,
            $index,
            $context->builder->call($context->lookupFunction('__value__readDouble'), $valuePtr)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($nullBlock);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setNullAt'),
            $ht,
            $index
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($objectBlock);
        $obj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setObjectAt'),
            $ht,
            $index,
            $obj
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($enumCaseBlock);
        $enumObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setObjectAt'),
            $ht,
            $index,
            $enumObj
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    public static function setAtIndex(Context $context, Value $ht, Value $index, Variable $element): void
    {
        if (0 !== ($element->type & Variable::IS_NATIVE_ARRAY)) {
            $materialized = HashTableHelper::materializeNativeArrayForCall($context, $element);
            $context->builder->call(
                $context->lookupFunction('__hashtable__setHashtableAt'),
                $ht,
                $index,
                $materialized
            );

            return;
        }
        switch ($element->type) {
            case Variable::TYPE_NATIVE_LONG:
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setLongAt'),
                    $ht,
                    $index,
                    $context->helper->loadValue($element)
                );
                break;
            case Variable::TYPE_STRING:
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setStringAt'),
                    $ht,
                    $index,
                    self::ownedString($context, $element)
                );
                break;
            case Variable::TYPE_NATIVE_BOOL:
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setBoolAt'),
                    $ht,
                    $index,
                    $context->helper->loadValue($element)
                );
                break;
            case Variable::TYPE_NATIVE_DOUBLE:
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setDoubleAt'),
                    $ht,
                    $index,
                    $context->helper->loadValue($element)
                );
                break;
            case Variable::TYPE_HASHTABLE:
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setHashtableAt'),
                    $ht,
                    $index,
                    $context->helper->loadValue($element)
                );
                break;
            case Variable::TYPE_OBJECT:
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setObjectAt'),
                    $ht,
                    $index,
                    $context->helper->loadValue($element)
                );
                break;
            case Variable::TYPE_NULL:
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setNullAt'),
                    $ht,
                    $index
                );
                break;
            case Variable::TYPE_VALUE:
                self::setValueBoxAtIndex($context, $ht, $index, $element);
                break;
            default:
                throw new \LogicException(
                    HashTableJitHelper::unsupportedIndexElementTypeMessage($element->type)
                );
        }
    }

    public static function setAtStringKey(
        Context $context,
        Value $ht,
        Value $keyPtr,
        Variable $element
    ): void {
        switch ($element->type) {
            case Variable::TYPE_STRING:
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setStringKeyString'),
                    $ht,
                    $keyPtr,
                    self::ownedString($context, $element)
                );
                break;
            case Variable::TYPE_NATIVE_LONG:
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setStringKeyLong'),
                    $ht,
                    $keyPtr,
                    $context->helper->loadValue($element)
                );
                break;
            case Variable::TYPE_NATIVE_BOOL:
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setStringKeyBool'),
                    $ht,
                    $keyPtr,
                    $context->builder->truncOrBitCast(
                        $context->helper->loadValue($element),
                        $context->getTypeFromString('int1')
                    )
                );
                break;
            case Variable::TYPE_NATIVE_DOUBLE:
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setStringKeyDouble'),
                    $ht,
                    $keyPtr,
                    $context->helper->loadValue($element)
                );
                break;
            case Variable::TYPE_HASHTABLE:
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setStringKeyHashtable'),
                    $ht,
                    $keyPtr,
                    $context->helper->loadValue($element)
                );
                break;
            case Variable::TYPE_NULL:
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setStringKeyNull'),
                    $ht,
                    $keyPtr
                );
                break;
            case Variable::TYPE_VALUE:
                $valuePtr = JitValueBox::valuePtrFromVariable($context, $element);
                $str = $context->builder->call(
                    $context->lookupFunction('__value__readString'),
                    $valuePtr
                );
                $owned = $context->builder->call(
                    $context->lookupFunction('__string__separate'),
                    $str
                );
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setStringKeyString'),
                    $ht,
                    $keyPtr,
                    $owned
                );
                break;
            default:
                throw new \LogicException(
                    HashTableJitHelper::unsupportedStringKeyElementTypeMessage($element->type)
                );
        }
    }
}
