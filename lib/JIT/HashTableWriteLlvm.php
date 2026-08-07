<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\VM\ArraySpread;
use PHPCompiler\VM\HashTable as VmHashTable;
use PHPCompiler\VM\HashTableJitHelper;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Builtin\ListUnpackRuntime;
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
        $hashtableBlock = BasicBlockHelper::append($context, 'ht_idx_vb_ht_'.$tag);
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
        // Nested arrays in value boxes must not fall through to setLongAt (#24055 / [$a] AOT).
        $checkHt = BasicBlockHelper::append($context, 'ht_idx_vb_check_ht_'.$tag);
        $context->builder->branchIf($isEnumCase, $enumCaseBlock, $checkHt);

        $context->builder->positionAtEnd($checkHt);
        // Accept JIT TYPE_HASHTABLE (7|IS_REFCOUNTED) and VM TYPE_ARRAY (6) — peer
        // ArrayColumnLlvm (#26955). Masked kind 7 also matches when the refcounted bit
        // is cleared. Silent fall-through left nested array_chunk slots empty for
        // NestedJIT json_encode (#27182).
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $isJitHt = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_HASHTABLE, false)
        );
        $isVmArray = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(\PHPCompiler\VM\Variable::TYPE_ARRAY, false)
        );
        $isKindHt = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_HASHTABLE & 0x7f, false)
        );
        $isHt = $context->builder->or($isJitHt, $context->builder->or($isVmArray, $isKindHt));
        $context->builder->branchIf($isHt, $hashtableBlock, $done);

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

        $context->builder->positionAtEnd($hashtableBlock);
        $childHt = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $valuePtr
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setHashtableAt'),
            $ht,
            $index,
            $childHt
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    public static function setAtIndex(Context $context, Value $ht, Value $index, Variable $element): void
    {
        if (0 !== ($element->type & Variable::IS_NATIVE_ARRAY)) {
            $materialized = self::materializeNativeArrayForCall($context, $element);
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
            case Variable::TYPE_OBJECT:
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setStringKeyObject'),
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
                // Runtime-typed value boxes (incl. null) must dispatch like index writes (#21947).
                self::setValueBoxAtStringKey($context, $ht, $keyPtr, $element);
                break;
            default:
                throw new \LogicException(
                    HashTableJitHelper::unsupportedStringKeyElementTypeMessage($element->type)
                );
        }
    }

    /** Mirror {@see setValueBoxAtIndex} for string keys (null / mixed boxed RHS). */
    private static function setValueBoxAtStringKey(
        Context $context,
        Value $ht,
        Value $keyPtr,
        Variable $element
    ): void {
        $tag = (string) self::nextSeq();
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $element);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');

        $stringBlock = BasicBlockHelper::append($context, 'ht_sk_vb_string_'.$tag);
        $longBlock = BasicBlockHelper::append($context, 'ht_sk_vb_long_'.$tag);
        $boolBlock = BasicBlockHelper::append($context, 'ht_sk_vb_bool_'.$tag);
        $doubleBlock = BasicBlockHelper::append($context, 'ht_sk_vb_double_'.$tag);
        $nullBlock = BasicBlockHelper::append($context, 'ht_sk_vb_null_'.$tag);
        $objectBlock = BasicBlockHelper::append($context, 'ht_sk_vb_object_'.$tag);
        $enumCaseBlock = BasicBlockHelper::append($context, 'ht_sk_vb_enum_'.$tag);
        $hashtableBlock = BasicBlockHelper::append($context, 'ht_sk_vb_ht_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_sk_vb_done_'.$tag);

        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING, false)
        );
        $checkLong = BasicBlockHelper::append($context, 'ht_sk_vb_check_long_'.$tag);
        $context->builder->branchIf($isString, $stringBlock, $checkLong);

        $context->builder->positionAtEnd($checkLong);
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );
        $checkBool = BasicBlockHelper::append($context, 'ht_sk_vb_check_bool_'.$tag);
        $context->builder->branchIf($isLong, $longBlock, $checkBool);

        $context->builder->positionAtEnd($checkBool);
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false)
        );
        $checkDouble = BasicBlockHelper::append($context, 'ht_sk_vb_check_double_'.$tag);
        $context->builder->branchIf($isBool, $boolBlock, $checkDouble);

        $context->builder->positionAtEnd($checkDouble);
        $isDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)
        );
        $checkNull = BasicBlockHelper::append($context, 'ht_sk_vb_check_null_'.$tag);
        $context->builder->branchIf($isDouble, $doubleBlock, $checkNull);

        $context->builder->positionAtEnd($checkNull);
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NULL, false)
        );
        $checkObject = BasicBlockHelper::append($context, 'ht_sk_vb_check_object_'.$tag);
        $context->builder->branchIf($isNull, $nullBlock, $checkObject);

        $context->builder->positionAtEnd($checkObject);
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_OBJECT, false)
        );
        $checkEnumCase = BasicBlockHelper::append($context, 'ht_sk_vb_check_enum_'.$tag);
        $context->builder->branchIf($isObject, $objectBlock, $checkEnumCase);

        $context->builder->positionAtEnd($checkEnumCase);
        $isEnumCase = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(\PHPCompiler\VM\Variable::TYPE_ENUM_CASE, false)
        );
        $checkHt = BasicBlockHelper::append($context, 'ht_sk_vb_check_ht_'.$tag);
        $context->builder->branchIf($isEnumCase, $enumCaseBlock, $checkHt);

        $context->builder->positionAtEnd($checkHt);
        // Same dual HT tag acceptance as setValueBoxAtIndex (#27182 / peer #26955).
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $isJitHt = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_HASHTABLE, false)
        );
        $isVmArray = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(\PHPCompiler\VM\Variable::TYPE_ARRAY, false)
        );
        $isKindHt = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_HASHTABLE & 0x7f, false)
        );
        $isHt = $context->builder->or($isJitHt, $context->builder->or($isVmArray, $isKindHt));
        $context->builder->branchIf($isHt, $hashtableBlock, $done);

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
            $context->lookupFunction('__hashtable__setStringKeyString'),
            $ht,
            $keyPtr,
            $owned
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($longBlock);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $ht,
            $keyPtr,
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
            $context->lookupFunction('__hashtable__setStringKeyBool'),
            $ht,
            $keyPtr,
            $context->builder->icmp(Builder::INT_NE, $boolByte, $i8->constInt(0, false))
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($doubleBlock);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyDouble'),
            $ht,
            $keyPtr,
            $context->builder->call($context->lookupFunction('__value__readDouble'), $valuePtr)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($nullBlock);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyNull'),
            $ht,
            $keyPtr
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($objectBlock);
        $obj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyObject'),
            $ht,
            $keyPtr,
            $obj
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($enumCaseBlock);
        $enumObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyObject'),
            $ht,
            $keyPtr,
            $enumObj
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($hashtableBlock);
        $childHt = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $valuePtr
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyHashtable'),
            $ht,
            $keyPtr,
            $childHt
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    /** unset() on a boxed array dimension (#17710). */
    public static function unsetValueBoxKey(Context $context, Value $ht, Variable $dim): void
    {
        $valPtr = HashTableReadLlvm::valuePtrFromDim($context, $dim);
        $valueMap = $context->structFieldMap['__value__'];
        $i8 = $context->getTypeFromString('int8');
        $typeByte = $context->builder->load(
            $context->builder->structGep($valPtr, $valueMap['type'])
        );
        $fn = $context->builder->getInsertBlock()->getParent();
        $stringBlock = $fn->appendBasicBlock('ht_unset_vk_str');
        $longBlock = $fn->appendBasicBlock('ht_unset_vk_long');
        $illegalBlock = $fn->appendBasicBlock('ht_unset_vk_illegal');
        $done = $fn->appendBasicBlock('ht_unset_vk_done');
        $afterString = $fn->appendBasicBlock('ht_unset_vk_after_str');
        $afterLong = $fn->appendBasicBlock('ht_unset_vk_after_long');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(Variable::TYPE_STRING, false)
            ),
            $stringBlock,
            $afterString
        );
        $context->builder->positionAtEnd($stringBlock);
        $keyStr = $context->builder->call($context->lookupFunction('__value__readString'), $valPtr);
        $context->builder->call(
            $context->lookupFunction('__hashtable__unsetStringKey'),
            $ht,
            $keyStr
        );
        $context->builder->branch($done);
        $context->builder->positionAtEnd($afterString);
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
            ),
            $longBlock,
            $afterLong
        );
        $context->builder->positionAtEnd($longBlock);
        $context->builder->call(
            $context->lookupFunction('__hashtable__unsetLongAt'),
            $ht,
            $context->builder->truncOrBitCast(
                $context->builder->call($context->lookupFunction('__value__readLong'), $valPtr),
                $context->getTypeFromString('size_t')
            )
        );
        $context->builder->branch($done);
        $context->builder->positionAtEnd($afterLong);
        $floatBlock = $fn->appendBasicBlock('ht_unset_vk_float');
        $afterFloat = $fn->appendBasicBlock('ht_unset_vk_after_float');
        $isNativeDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)
        );
        $isVmFloat = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(\PHPCompiler\VM\Variable::TYPE_FLOAT, false)
        );
        $context->builder->branchIf(
            $context->builder->or($isNativeDouble, $isVmFloat),
            $floatBlock,
            $afterFloat
        );
        $context->builder->positionAtEnd($floatBlock);
        $doubleVal = $context->builder->call($context->lookupFunction('__value__readDouble'), $valPtr);
        $truncatedLong = \PHPCompiler\ext\standard\JitIntdiv::floatToLongWithPrecisionWarning(
            $context,
            $doubleVal
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__unsetLongAt'),
            $ht,
            $context->builder->truncOrBitCast(
                $truncatedLong,
                $context->getTypeFromString('size_t')
            )
        );
        $context->builder->branch($done);
        $context->builder->positionAtEnd($afterFloat);
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_OBJECT, false)
        );
        $afterObject = $fn->appendBasicBlock('ht_unset_vk_after_obj');
        $context->builder->branchIf($isObject, $illegalBlock, $afterObject);
        $context->builder->positionAtEnd($afterObject);
        $isEnumCase = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(\PHPCompiler\VM\Variable::TYPE_ENUM_CASE, false)
        );
        $afterEnumCase = $fn->appendBasicBlock('ht_unset_vk_after_enum');
        $context->builder->branchIf($isEnumCase, $illegalBlock, $afterEnumCase);
        $context->builder->positionAtEnd($afterEnumCase);
        $isArray = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_HASHTABLE, false)
        );
        $context->builder->branchIf($isArray, $illegalBlock, $done);
        $context->builder->positionAtEnd($illegalBlock);
        HashTableHelper::emitIllegalOffsetType($context, 'Illegal offset type in unset');
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
    }

    /** unset() on array/container dimensions (#10031 v4). */
    public static function offsetUnset(Context $context, Variable $container, Variable $dim): void
    {
        $ht = HashTableReadLlvm::loadHashtablePointer($context, $container);
        if (Variable::TYPE_NATIVE_LONG === $dim->type) {
            $index = $context->helper->loadValue($dim);
            $context->builder->call(
                $context->lookupFunction('__hashtable__unsetLongAt'),
                $ht,
                $index
            );

            return;
        }
        if (Variable::TYPE_NATIVE_DOUBLE === $dim->type) {
            $truncated = \PHPCompiler\ext\standard\JitIntdiv::floatToLongWithPrecisionWarning(
                $context,
                $context->helper->loadValue($dim)
            );
            $context->builder->call(
                $context->lookupFunction('__hashtable__unsetLongAt'),
                $ht,
                $context->builder->truncOrBitCast(
                    $truncated,
                    $context->getTypeFromString('size_t')
                )
            );

            return;
        }
        if (Variable::TYPE_STRING === $dim->type) {
            $key = $context->helper->loadValue($dim);
            $context->builder->call(
                $context->lookupFunction('__hashtable__unsetStringKey'),
                $ht,
                $key
            );

            return;
        }
        if (Variable::TYPE_VALUE === $dim->type) {
            self::unsetValueBoxKey($context, $ht, $dim);

            return;
        }
        if (Variable::TYPE_OBJECT === $dim->type) {
            HashTableHelper::emitIllegalOffsetType($context, 'Illegal offset type in unset');

            return;
        }
        throw new \LogicException('unset() array offset requires int or string index in this compiler build');
    }

    /** SplObjectStorage-style map: writable object-identity key slot (#10031 v4). */
    public static function writableObjectKeyValueBox(Context $context, Value $ht, Value $keyObj): Variable
    {
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSetObjectKey'),
            $ht,
            $keyObj
        );
        $create = BasicBlockHelper::append($context, 'ht_ok_write_create');
        $ready = BasicBlockHelper::append($context, 'ht_ok_write_ready');
        $context->builder->branchIf($isSet, $ready, $create);

        $context->builder->positionAtEnd($create);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setObjectKeyLong'),
            $ht,
            $keyObj,
            $context->constantFromInteger(0, 'int64')
        );
        $context->builder->branch($ready);

        $context->builder->positionAtEnd($ready);
        $valPtr = $context->builder->call(
            $context->lookupFunction('__hashtable__readObjectKeyValue'),
            $ht,
            $keyObj
        );
        $var = new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $valPtr
        );
        $var->writableHt = $ht;
        $var->writableObjectKey = $keyObj;

        return $var;
    }

    public static function addElement(
        Context $context,
        Variable $array,
        Variable $element,
        ?Variable $key = null
    ): void {
        $array->compileTimeEmptyArrayLiteral = false;
        if ($array->type & Variable::IS_NATIVE_ARRAY) {
            if (self::nativeArrayNeedsHashtablePromotion($array, $element)) {
                self::promoteNativeArrayVariableToHashtable($context, $array);
            } else {
                self::addNativeElement($context, $array, $element, $key);

                return;
            }
        }
        $ht = HashTableReadLlvm::loadHashtablePointer($context, $array);
        if (null === $key) {
            // Always load nextFreeElement at runtime. A compile-time counter is safe only for
            // straight-line INIT_ARRAY / sequential `$a[]=` sites compiled once each; a single
            // `$x[] =` inside foreach/while is one call site that runs N times and would keep
            // writing the same baked index (generator foreach → only last yield kept, #24145;
            // same shape as spread loops #23971).
            $map = $context->structFieldMap['__hashtable__'];
            $index = $context->builder->load(
                $context->builder->structGep($ht, $map['nextFreeElement'])
            );
            self::emitAppendOccupiedIfNextFreeOverflowed($context, $index);
            self::setAtIndex($context, $ht, $index, $element);
            $array->nextFreeElementFromRuntime = true;
            // Track string literals for compile-time folds (preg_filter #27181 / str_replace peers).
            $cts = $element->compileTimeString;
            if (null !== $cts) {
                if (!\is_array($array->compileTimeArray)) {
                    $array->compileTimeArray = [];
                }
                $array->compileTimeArray[$array->nextFreeElement] = $cts;
            } else {
                $array->compileTimeArray = null;
            }
            ++$array->nextFreeElement;

            return;
        }
        // Keyed writes invalidate packed compile-time string tracking (#27181).
        $array->compileTimeArray = null;
        if (Variable::TYPE_OBJECT === $key->type
            || Variable::TYPE_HASHTABLE === $key->type) {
            // Array-literal enum/object keys: typed TypeError under PROFILE≥8.3 (#28628).
            HashTableHelper::emitIllegalOffsetTypeForKey($context, $key);

            return;
        }
        if (Variable::TYPE_NULL === $key->type) {
            DynamicPropertyDeprecationGuard::emitNullArrayOffset($context);
            $emptyKey = $context->builder->load($context->constantStringFromString(''));
            self::setAtStringKey($context, $ht, $emptyKey, $element);

            return;
        }
        if (Variable::TYPE_STRING === $key->type) {
            $keyPtr = $context->helper->loadValue($key);
            self::setAtKeyCoercingNumericString($context, $ht, $keyPtr, $element);

            return;
        }
        if (Variable::TYPE_VALUE === $key->type || JitValueBox::isValueOperand($key)) {
            self::setValueBoxKey($context, $ht, $key, $element);

            return;
        }
        $index = self::arrayKeyToIndex($context, $key);
        self::setAtIndex($context, $ht, $index, $element);
    }

    /** Native packed arrays inferred as scalar must widen when storing enum case objects (#5722, #5638). */
    public static function nativeArrayNeedsHashtablePromotion(Variable $array, Variable $element): bool
    {
        if (0 === ($array->type & Variable::IS_NATIVE_ARRAY)) {
            return false;
        }
        $elemType = $array->type & ~Variable::IS_NATIVE_ARRAY;

        return $element->type !== $elemType;
    }

    public static function promoteNativeArrayVariableToHashtable(Context $context, Variable $array): void
    {
        if (0 === ($array->type & Variable::IS_NATIVE_ARRAY)) {
            return;
        }
        $ht = self::materializeNativeArrayForCall($context, $array);
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            JitValueBox::pointer($context, $slot),
            $ht
        );
        $array->type = Variable::TYPE_VALUE;
        $array->value = $slot;
        $array->valueBoxHashtable = true;
    }

    /** php-src: float array keys truncate toward zero (zend_dval_to_lval); warn on write (#19730). */
    private static function arrayKeyToIndex(Context $context, Variable $key): Value
    {
        $sizeT = $context->getTypeFromString('size_t');
        if (Variable::TYPE_NATIVE_DOUBLE === $key->type) {
            $truncated = \PHPCompiler\ext\standard\JitIntdiv::floatToLongWithPrecisionWarning(
                $context,
                $context->helper->loadValue($key)
            );

            return $context->builder->truncOrBitCast($truncated, $sizeT);
        }

        return $context->builder->truncOrBitCast(
            $context->helper->loadValue($key),
            $sizeT
        );
    }

    public static function addNativeElement(
        Context $context,
        Variable $array,
        Variable $element,
        ?Variable $key
    ): void {
        if (null !== $key) {
            $index = self::arrayKeyToIndex($context, $key);
        } else {
            $index = $context->constantFromInteger($array->nextFreeElement, 'size_t');
            ++$array->nextFreeElement;
        }
        $zero = $context->constantFromInteger(0, 'size_t');
        $slot = $context->builder->inBoundsGep($array->value, $zero, $index);
        $elemType = $array->type & ~Variable::IS_NATIVE_ARRAY;
        if (Variable::TYPE_STRING === $elemType) {
            $context->builder->store(self::ownedString($context, $element), $slot);
        } else {
            $context->builder->store($context->helper->loadValue($element), $slot);
        }
    }

    public static function setValueBoxKey(
        Context $context,
        Value $ht,
        Variable $dim,
        Variable $element
    ): void {
        $valPtr = HashTableReadLlvm::valuePtrFromDim($context, $dim);
        $valueMap = $context->structFieldMap['__value__'];
        $i8 = $context->getTypeFromString('int8');
        $typeByte = $context->builder->load(
            $context->builder->structGep($valPtr, $valueMap['type'])
        );
        // Mask IS_REFCOUNTED — value boxes from export/writeString may differ on the high bit (#27217).
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $fn = $context->builder->getInsertBlock()->getParent();
        $stringBlock = $fn->appendBasicBlock('ht_set_vk_str');
        $longBlock = $fn->appendBasicBlock('ht_set_vk_long');
        $done = $fn->appendBasicBlock('ht_set_vk_done');
        $afterString = $fn->appendBasicBlock('ht_set_vk_after_str');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $kind,
                $i8->constInt(Variable::TYPE_STRING & 0x7f, false)
            ),
            $stringBlock,
            $afterString
        );
        $context->builder->positionAtEnd($stringBlock);
        $keyStr = $context->builder->call($context->lookupFunction('__value__readString'), $valPtr);
        self::setAtStringKey($context, $ht, $keyStr, $element);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($afterString);
        $afterLong = $fn->appendBasicBlock('ht_set_vk_after_long');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $kind,
                $i8->constInt(Variable::TYPE_NATIVE_LONG & 0x7f, false)
            ),
            $longBlock,
            $afterLong
        );
        $context->builder->positionAtEnd($longBlock);
        $index = $context->builder->truncOrBitCast(
            $context->builder->call($context->lookupFunction('__value__readLong'), $valPtr),
            $context->getTypeFromString('size_t')
        );
        self::setAtIndex($context, $ht, $index, $element);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($afterLong);
        $floatBlock = $fn->appendBasicBlock('ht_set_vk_float');
        $afterFloat = $fn->appendBasicBlock('ht_set_vk_after_float');
        // Value-box type bytes use VM Variable constants (TYPE_FLOAT=2); mask IS_REFCOUNTED.
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $kind,
                $i8->constInt(\PHPCompiler\VM\Variable::TYPE_FLOAT & 0x7f, false)
            ),
            $floatBlock,
            $afterFloat
        );
        $context->builder->positionAtEnd($floatBlock);
        $doubleVal = $context->builder->call($context->lookupFunction('__value__readDouble'), $valPtr);
        $truncatedLong = \PHPCompiler\ext\standard\JitIntdiv::floatToLongWithPrecisionWarning($context, $doubleVal);
        $floatIndex = $context->builder->truncOrBitCast(
            $truncatedLong,
            $context->getTypeFromString('size_t')
        );
        self::setAtIndex($context, $ht, $floatIndex, $element);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($afterFloat);
        $nullBlock = $fn->appendBasicBlock('ht_set_vk_null');
        $afterNull = $fn->appendBasicBlock('ht_set_vk_after_null');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $kind,
                $i8->constInt(Variable::TYPE_NULL & 0x7f, false)
            ),
            $nullBlock,
            $afterNull
        );
        $context->builder->positionAtEnd($nullBlock);
        DynamicPropertyDeprecationGuard::emitNullArrayOffset($context);
        $emptyKey = $context->builder->load($context->constantStringFromString(''));
        self::setAtStringKey($context, $ht, $emptyKey, $element);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($afterNull);
        $illegalBlock = $fn->appendBasicBlock('ht_set_vk_illegal');
        $afterObject = $fn->appendBasicBlock('ht_set_vk_after_obj');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $kind,
                $i8->constInt(Variable::TYPE_OBJECT & 0x7f, false)
            ),
            $illegalBlock,
            $afterObject
        );
        $context->builder->positionAtEnd($afterObject);
        $isEnumCase = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(\PHPCompiler\VM\Variable::TYPE_ENUM_CASE & 0x7f, false)
        );
        $afterEnumCase = $fn->appendBasicBlock('ht_set_vk_after_enum');
        $context->builder->branchIf($isEnumCase, $illegalBlock, $afterEnumCase);
        $context->builder->positionAtEnd($afterEnumCase);
        $isArray = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_HASHTABLE & 0x7f, false)
        );
        $context->builder->branchIf($isArray, $illegalBlock, $done);
        $context->builder->positionAtEnd($illegalBlock);
        HashTableHelper::emitIllegalOffsetType($context);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
    }

    public static function setAtObjectKey(
        Context $context,
        Value $ht,
        Value $keyObj,
        Variable $element
    ): void {
        switch ($element->type) {
            case Variable::TYPE_NATIVE_LONG:
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setObjectKeyLong'),
                    $ht,
                    $keyObj,
                    $context->helper->loadValue($element)
                );
                break;
            case Variable::TYPE_NATIVE_BOOL:
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setObjectKeyLong'),
                    $ht,
                    $keyObj,
                    $context->builder->zext(
                        $context->helper->loadValue($element),
                        $context->getTypeFromString('int64')
                    )
                );
                break;
            case Variable::TYPE_OBJECT:
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setObjectKeyObject'),
                    $ht,
                    $keyObj,
                    $context->helper->loadValue($element)
                );
                break;
            case Variable::TYPE_VALUE:
                self::setValueBoxAtObjectKey($context, $ht, $keyObj, $element);
                break;
            case Variable::TYPE_STRING:
            case Variable::TYPE_NULL:
            case Variable::TYPE_NATIVE_DOUBLE:
                // No __hashtable__setObjectKeyString — write through the value-box slot (#26787).
                $writable = self::writableObjectKeyValueBox($context, $ht, $keyObj);
                $dest = JitValueBox::valuePtrFromVariable($context, $writable);
                if (Variable::TYPE_STRING === $element->type) {
                    $context->builder->call(
                        $context->lookupFunction('__value__writeString'),
                        $dest,
                        self::ownedString($context, $element)
                    );
                } elseif (Variable::TYPE_NATIVE_DOUBLE === $element->type) {
                    $context->builder->call(
                        $context->lookupFunction('__value__writeDouble'),
                        $dest,
                        $context->helper->loadValue($element)
                    );
                } else {
                    $context->builder->call(
                        $context->lookupFunction('__value__writeNull'),
                        $dest
                    );
                }
                break;
            default:
                throw new \LogicException(
                    'Object-key array element type not supported for JIT: '
                    .Variable::getStringType($element->type)
                );
        }
    }

    private static function setValueBoxAtObjectKey(
        Context $context,
        Value $ht,
        Value $keyObj,
        Variable $element
    ): void {
        $tag = (string) self::nextSeq();
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $element);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $longBlock = BasicBlockHelper::append($context, 'ht_ok_vb_long_'.$tag);
        $objectBlock = BasicBlockHelper::append($context, 'ht_ok_vb_obj_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_ok_vb_done_'.$tag);
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );
        $context->builder->branchIf($isLong, $longBlock, $objectBlock);
        $context->builder->positionAtEnd($longBlock);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setObjectKeyLong'),
            $ht,
            $keyObj,
            $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr)
        );
        $context->builder->branch($done);
        $context->builder->positionAtEnd($objectBlock);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setObjectKeyObject'),
            $ht,
            $keyObj,
            $context->builder->call($context->lookupFunction('__value__readObject'), $valuePtr)
        );
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
    }

    /**
     * Array element write: numeric strings use the int index slot (Zend zend_hash.c; #4151).
     */
    public static function setAtKeyCoercingNumericString(
        Context $context,
        Value $ht,
        Value $keyPtr,
        Variable $element
    ): void {
        $insert = $context->builder->getInsertBlock();
        if ($context->emitsInitLinearIR()
            || (null !== $insert && self::emitsInInitFunction($context, $insert))) {
            // __init__ must stay a linear basic-block chain; no strtol coercion CFG (#8559).
            self::setAtStringKey($context, $ht, $keyPtr, $element);

            return;
        }
        self::setAtKeyCoercingNumericStringBody($context, $ht, $keyPtr, $element);
    }

    private static function sameLlvmBasicBlock(\PHPLLVM\BasicBlock $a, \PHPLLVM\BasicBlock $b): bool
    {
        if ($a === $b) {
            return true;
        }
        if ($a instanceof \PHPLLVM\LLVMAbstract\BasicBlock
            && $b instanceof \PHPLLVM\LLVMAbstract\BasicBlock) {
            return $a->block === $b->block;
        }

        return false;
    }

    private static function sameLlvmFunction(?\PHPLLVM\Value $a, ?\PHPLLVM\Value $b): bool
    {
        if (null === $a || null === $b) {
            return false;
        }
        if ($a === $b) {
            return true;
        }
        if ($a instanceof \PHPLLVM\LLVMAbstract\Value
            && $b instanceof \PHPLLVM\LLVMAbstract\Value) {
            return $a->value === $b->value;
        }

        return false;
    }

    private static function emitsInInitFunction(Context $context, \PHPLLVM\BasicBlock $insert): bool
    {
        if (self::sameLlvmBasicBlock($insert, $context->initBlock)) {
            return true;
        }
        $linear = $context->initLinearBlock;
        if (null !== $linear && self::sameLlvmBasicBlock($insert, $linear)) {
            return true;
        }
        $initParent = $context->initBlock->getParent();
        $insertParent = $insert->getParent();
        if (self::sameLlvmFunction($initParent, $insertParent)) {
            return true;
        }

        return false;
    }

    private static function setAtKeyCoercingNumericStringBody(
        Context $context,
        Value $ht,
        Value $keyPtr,
        Variable $element
    ): void {
        $builder = $context->builder;
        $map = $context->structFieldMap['__string__'];
        $len = $builder->load($builder->structGep($keyPtr, $map['length']));
        $charPtr = $builder->structGep($keyPtr, $map['value']);
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $zeroLen = $len->typeOf()->constInt(0, false);

        $useStr = BasicBlockHelper::append($context, 'arr_key_str_'.self::nextSeq());
        $tryInt = BasicBlockHelper::append($context, 'arr_key_try_int_'.self::nextSeq());
        $useInt = BasicBlockHelper::append($context, 'arr_key_int_'.self::nextSeq());
        $done = BasicBlockHelper::append($context, 'arr_key_done_'.self::nextSeq());

        $isEmpty = $builder->icmp(Builder::INT_EQ, $len, $zeroLen);
        $builder->branchIf($isEmpty, $useStr, $tryInt);

        $builder->positionAtEnd($tryInt);
        $endPtrSlot = $builder->alloca($i8p, 1, 'arr_key_strtol_end');
        $builder->store($i8p->constNull(), $endPtrSlot);
        $parsed = $builder->call(
            $context->lookupFunction('strtol'),
            $charPtr,
            $endPtrSlot,
            $context->getTypeFromString('int32')->constInt(10, false)
        );
        $endPtr = $builder->load($endPtrSlot);
        $endOffset = $builder->sub(
            $builder->ptrToInt($endPtr, $i64),
            $builder->ptrToInt($charPtr, $i64)
        );
        $consumedAll = $builder->icmp(Builder::INT_EQ, $endOffset, $len);
        $builder->branchIf($consumedAll, $useInt, $useStr);

        $builder->positionAtEnd($useInt);
        $index = $builder->truncOrBitCast($parsed, $sizeT);
        self::setAtIndex($context, $ht, $index, $element);
        $builder->branch($done);

        $builder->positionAtEnd($useStr);
        self::setAtStringKey($context, $ht, $keyPtr, $element);
        $builder->branch($done);

        $builder->positionAtEnd($done);
    }

    /**
     * Spread merge for string keys: numeric strings append; other strings overwrite (#5072).
     */
    public static function spreadAddElement(
        Context $context,
        Variable $array,
        Variable $element,
        Variable $key
    ): void {
        if ($array->type & Variable::IS_NATIVE_ARRAY) {
            if (self::nativeArrayNeedsHashtablePromotion($array, $element)) {
                self::promoteNativeArrayVariableToHashtable($context, $array);
            } else {
                self::addNativeElement($context, $array, $element, $key);

                return;
            }
        }
        $ht = HashTableReadLlvm::loadHashtablePointer($context, $array);
        if (Variable::TYPE_STRING !== $key->type) {
            if (Variable::TYPE_OBJECT === $key->type || Variable::TYPE_HASHTABLE === $key->type) {
                HashTableHelper::emitIllegalOffsetTypeForKey($context, $key);

                return;
            }
            $index = $array->nextFreeElementFromRuntime
                ? $context->builder->load(
                    $context->builder->structGep(
                        $ht,
                        $context->structFieldMap['__hashtable__']['nextFreeElement']
                    )
                )
                : $context->constantFromInteger($array->nextFreeElement, 'size_t');
            if (!$array->nextFreeElementFromRuntime) {
                ++$array->nextFreeElement;
            }
            self::setAtIndex($context, $ht, $index, $element);

            return;
        }
        $keyPtr = $context->helper->loadValue($key);
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load($context->builder->structGep($keyPtr, $map['length']));
        $charPtr = $context->builder->structGep($keyPtr, $map['value']);
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $zeroLen = $len->typeOf()->constInt(0, false);
        $tag = (string) self::nextSeq();
        $useStr = BasicBlockHelper::append($context, 'ht_spread_add_str_'.$tag);
        $tryInt = BasicBlockHelper::append($context, 'ht_spread_add_try_'.$tag);
        $append = BasicBlockHelper::append($context, 'ht_spread_add_append_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_spread_add_done_'.$tag);

        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $len, $zeroLen);
        $context->builder->branchIf($isEmpty, $useStr, $tryInt);

        $context->builder->positionAtEnd($tryInt);
        $endPtrSlot = $context->builder->alloca($i8p, 1, 'ht_spread_add_end');
        $context->builder->store($i8p->constNull(), $endPtrSlot);
        $parsed = $context->builder->call(
            $context->lookupFunction('strtol'),
            $charPtr,
            $endPtrSlot,
            $context->getTypeFromString('int32')->constInt(10, false)
        );
        $endPtr = $context->builder->load($endPtrSlot);
        $endOffset = $context->builder->sub(
            $context->builder->ptrToInt($endPtr, $i64),
            $context->builder->ptrToInt($charPtr, $i64)
        );
        $consumedAll = $context->builder->icmp(Builder::INT_EQ, $endOffset, $len);
        $context->builder->branchIf($consumedAll, $append, $useStr);

        $context->builder->positionAtEnd($append);
        $index = $array->nextFreeElementFromRuntime
            ? $context->builder->load(
                $context->builder->structGep(
                    $ht,
                    $context->structFieldMap['__hashtable__']['nextFreeElement']
                )
            )
            : $context->constantFromInteger($array->nextFreeElement, 'size_t');
        if (!$array->nextFreeElementFromRuntime) {
            ++$array->nextFreeElement;
        }
        self::setAtIndex($context, $ht, $index, $element);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($useStr);
        self::setAtStringKey($context, $ht, $keyPtr, $element);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    /**
     * Reserve the next packed-list slot for $arr[] = … (issue #116).
     *
     * Returns a {@see Variable::TYPE_VALUE} lvalue pointing at the new __value__ entry.
     */
    public static function reserveAppendSlot(Context $context, Variable $array): Variable
    {
        if ($array->type & Variable::IS_NATIVE_ARRAY) {
            $sizeT = $context->getTypeFromString('size_t');
            $index = $context->constantFromInteger($array->nextFreeElement, 'size_t');
            ++$array->nextFreeElement;
            $zero = $sizeT->constInt(0, false);
            $slot = $context->builder->inBoundsGep($array->value, $zero, $index);
            $elementType = $array->type & (~Variable::IS_NATIVE_ARRAY);

            return new Variable($context, $elementType, Variable::KIND_VARIABLE, $slot);
        }

        $ht = HashTableReadLlvm::loadHashtablePointer($context, $array);
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        // Runtime nextFreeElement — a compile-time counter is one index for the whole
        // `$x[] =` call site; inside foreach/while that overwrites slot 0 (#24145, #23971).
        $index = $context->builder->load(
            $context->builder->structGep($ht, $map['nextFreeElement'])
        );
        self::emitAppendOccupiedIfNextFreeOverflowed($context, $index);
        $array->nextFreeElementFromRuntime = true;
        ++$array->nextFreeElement;
        $one = $sizeT->constInt(1, false);
        $maxIdx = $sizeT->constInt(\PHP_INT_MAX, false);
        $overflowSentinel = $sizeT->constInt(\PHP_INT_MIN, true);
        $isMax = $context->builder->icmp(Builder::INT_EQ, $index, $maxIdx);
        $need = $context->builder->addNoSignedWrap($index, $one);
        $advanced = $context->builder->select($isMax, $overflowSentinel, $need);
        $context->builder->call($context->lookupFunction('__hashtable__grow'), $ht, $advanced);
        $entry = HashTableReadLlvm::listEntryPointer($context, $ht, $index);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $entry);

        $nextFree = $context->builder->load(
            $context->builder->structGep($ht, $map['nextFreeElement'])
        );
        $numElements = $context->builder->load(
            $context->builder->structGep($ht, $map['numElements'])
        );
        $updateNext = $context->builder->icmp(Builder::INT_UGE, $index, $nextFree);
        $newNext = $context->builder->select($updateNext, $advanced, $nextFree);
        $context->builder->store(
            $newNext,
            $context->builder->structGep($ht, $map['nextFreeElement'])
        );
        $updateNum = $context->builder->icmp(Builder::INT_UGE, $index, $numElements);
        $newNum = $context->builder->select($updateNum, $advanced, $numElements);
        $context->builder->store(
            $newNum,
            $context->builder->structGep($ht, $map['numElements'])
        );

        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $entry);
    }

    /**
     * zend_hash_next_index_insert: nNextFreeElement < 0 → Error (#28762).
     * Continues on the ok block when nextFree is still valid.
     */
    private static function emitAppendOccupiedIfNextFreeOverflowed(Context $context, Value $nextFree): void
    {
        $i64 = $context->getTypeFromString('int64');
        $asSigned = $nextFree->typeOf() === $i64
            ? $nextFree
            : $context->builder->truncOrBitCast($nextFree, $i64);
        $overflowed = $context->builder->icmp(
            Builder::INT_SLT,
            $asSigned,
            $i64->constInt(0, true)
        );
        $tag = (string) self::nextSeq();
        $errBb = BasicBlockHelper::append($context, 'ht_append_occupied_'.$tag);
        $okBb = BasicBlockHelper::append($context, 'ht_append_ok_'.$tag);
        $context->builder->branchIf($overflowed, $errBb, $okBb);
        $context->builder->positionAtEnd($errBb);
        self::emitNextElementOccupiedError($context);
        $context->builder->positionAtEnd($okBb);
    }

    private static function emitNextElementOccupiedError(Context $context): void
    {
        $message = VmHashTable::NEXT_ELEMENT_OCCUPIED_MESSAGE;
        if ([] !== $context->tryCatch->handlerStack) {
            TryCatchHelper::emitCatchableClassError($context, 'Error', $message, null);

            return;
        }
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);
        ErrorRaise::ensureStandaloneBodies($context);
        ErrorRaise::emitRaise($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
        $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);
    }

    /**
     * Array-literal spread: append packed list then string keys (issue #141, #1361, #4453).
     * Non-array / non-Traversable at runtime → catchable Error (#27952).
     */
    public static function spreadInto(Context $context, Variable $dest, Variable $source): void
    {
        $dest->compileTimeEmptyArrayLiteral = false;
        if (self::emitArraySpreadNonTraversableGuard($context, $source)) {
            return;
        }
        if (self::needsTraversableMaterialization($context, $source)) {
            $srcPtr = \PHPCompiler\ext\standard\JitIteratorToArray::materializeHashtable(
                $context,
                $source,
                true,
                $source->userType ?? null
            );
            self::spreadPackedInto($context, $dest, $srcPtr);
            self::spreadStringKeysInto($context, $dest, $srcPtr);

            return;
        }
        $srcHt = HashTableHelper::coerceToPackedHashtable($context, $source);
        $srcPtr = $context->helper->loadValue($srcHt);
        self::spreadPackedInto($context, $dest, $srcPtr);
        self::spreadStringKeysInto($context, $dest, $srcPtr);
        $dest->nextFreeElementFromRuntime = true;
    }

    /**
     * Guard `[...$x]` when $x is known non-array at compile time, or boxed and not
     * array/object at runtime. Scalars → catchable Error (zend_vm_def.h / #27952).
     *
     * @return bool true when the fail path already terminated this block
     */
    private static function emitArraySpreadNonTraversableGuard(Context $context, Variable $source): bool
    {
        if (ListUnpackHelper::isDefinitelyArrayAtCompileTime($source)) {
            return false;
        }
        if (GeneratorHelper::isGeneratorVariable($source)) {
            return false;
        }
        if (IteratorProtocolHelper::canLowerIteratorProtocol($context, $source, $source->userType ?? null)) {
            return false;
        }
        if (Variable::TYPE_OBJECT === $source->type) {
            // Traversable materialization path handles objects; non-Traversable → TypeError there.
            return false;
        }
        if (ListUnpackHelper::isDefinitelyNonArrayAtCompileTime($context, $source)) {
            self::emitArraySpreadCatchableError($context);

            return true;
        }
        if (Variable::TYPE_VALUE === $source->type || JitValueBox::isValueOperand($source)) {
            $isArray = ListUnpackHelper::isArrayValue($context, $source);
            // Also allow object boxes (Traversable / iterator materialization).
            ListUnpackRuntime::ensureLinked($context);
            $typeByte = ListUnpackRuntime::loadValueBoxTypeByte($context, $source);
            $i8 = $context->getTypeFromString('int8');
            $isObject = $context->builder->icmp(
                Builder::INT_EQ,
                $context->builder->and(
                    $context->builder->trunc($typeByte, $i8),
                    $i8->constInt(0x7f, false)
                ),
                $i8->constInt(\PHPCompiler\VM\Variable::TYPE_OBJECT & 0x7f, false)
            );
            $ok = $context->builder->or($isArray, $isObject);
            $failBb = BasicBlockHelper::append($context, 'array_spread_non_traversable');
            $okBb = BasicBlockHelper::append($context, 'array_spread_ok');
            $context->builder->branchIf($ok, $okBb, $failBb);
            $context->builder->positionAtEnd($failBb);
            self::emitArraySpreadCatchableError($context);
            $context->builder->positionAtEnd($okBb);

            return false;
        }

        return false;
    }

    private static function emitArraySpreadCatchableError(Context $context): void
    {
        if ([] !== $context->tryCatch->handlerStack) {
            TryCatchHelper::emitCatchableClassError(
                $context,
                'Error',
                ArraySpread::NON_TRAVERSABLE_MESSAGE,
                null
            );

            return;
        }
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);
        ErrorRaise::ensureStandaloneBodies($context);
        ErrorRaise::emitRaise($context, ArraySpread::NON_TRAVERSABLE_MESSAGE);
        $context->builder->call($context->lookupFunction('abort'));
        $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);
    }

    private static function needsTraversableMaterialization(Context $context, Variable $source): bool
    {
        if (ListUnpackHelper::isDefinitelyArrayAtCompileTime($source)) {
            return false;
        }
        if (GeneratorHelper::isGeneratorVariable($source)) {
            return true;
        }
        if (IteratorProtocolHelper::canLowerIteratorProtocol($context, $source, $source->userType ?? null)) {
            return true;
        }

        return false;
    }

    private static function spreadPackedInto(Context $context, Variable $dest, Value $srcHt): void
    {
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $count = $context->builder->load($context->builder->structGep($srcHt, $map['nextFreeElement']));
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);
        $tag = (string) self::nextSeq();
        $head = BasicBlockHelper::append($context, 'ht_spread_packed_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'ht_spread_packed_body_'.$tag);
        $advance = BasicBlockHelper::append($context, 'ht_spread_packed_advance_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_spread_packed_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $srcHt,
            $idx
        );
        $skip = BasicBlockHelper::append($context, 'ht_spread_packed_skip_'.$tag);
        $append = BasicBlockHelper::append($context, 'ht_spread_packed_append_'.$tag);
        $context->builder->branchIf($isSet, $append, $skip);

        $context->builder->positionAtEnd($append);
        // Must use runtime nextFreeElement — addElement() bakes a compile-time constant
        // index, so a loop would overwrite one slot ([0, ...[1,2,3]] → [0,3], #23971).
        $destHt = HashTableReadLlvm::loadHashtablePointer($context, $dest);
        $destNext = $context->builder->load(
            $context->builder->structGep($destHt, $map['nextFreeElement'])
        );
        $elem = HashTableReadLlvm::readIndexedToValueBox($context, $srcHt, $idx);
        self::setAtIndex($context, $destHt, $destNext, $elem);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($skip);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function spreadStringKeysInto(Context $context, Variable $dest, Value $srcHt): void
    {
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');
        $tag = (string) self::nextSeq();
        $head = BasicBlockHelper::append($context, 'ht_spread_str_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'ht_spread_str_body_'.$tag);
        $advance = BasicBlockHelper::append($context, 'ht_spread_str_advance_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_spread_str_done_'.$tag);
        $nodeSlot = BasicBlockHelper::entryAlloca($context, $nodePtrType);
        $context->builder->store(
            $context->builder->load($context->builder->structGep($srcHt, $map['strKeys'])),
            $nodeSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $node = $context->builder->load($nodeSlot);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($isNull, $done, $body);

        $context->builder->positionAtEnd($body);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $keyVar = new Variable(
            $context,
            Variable::TYPE_STRING,
            Variable::KIND_VALUE,
            $keyStr
        );
        $valField = $context->builder->structGep($node, $nodeMap['value']);
        $elem = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $valField);
        self::spreadAddElement($context, $dest, $elem, $keyVar);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $next = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($next, $nodeSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    /** Persist in-place hashtable mutations on native/boxed array operands (#1086, #17865, #24010). */
    public static function storeHashtableInArrayVariable(Context $context, Variable $array, Value $ht): void
    {
        if (0 !== ($array->type & Variable::IS_NATIVE_ARRAY)) {
            if (Variable::KIND_VARIABLE !== $array->kind) {
                return;
            }
            // Rebind the Variable onto a fresh __value__ box holding $ht — do not store a
            // pointer into the native element alloca (that left `$a[0]` reading unsorted
            // ints after sort()/array_push on literal arrays; peer promoteNativeArray…).
            $boxed = JitValueBox::alloc($context);
            $context->builder->call(
                $context->lookupFunction('__value__writeHashtable'),
                JitValueBox::pointer($context, $boxed),
                $ht
            );
            $array->type = Variable::TYPE_VALUE;
            $array->value = $boxed;
            $array->valueBoxHashtable = true;

            return;
        }
        if (Variable::TYPE_HASHTABLE === $array->type) {
            return;
        }
        if (Variable::TYPE_VALUE === $array->type || JitValueBox::isValueOperand($array)) {
            if (null !== $array->objectPropertySlot) {
                $valPtr = $context->builder->pointerCast(
                    $context->builder->load($array->objectPropertySlot),
                    $context->getTypeFromString('__value__*')
                );
            } else {
                $valPtr = JitValueBox::valuePtrFromVariable($context, $array);
            }
            $context->builder->call(
                $context->lookupFunction('__value__writeHashtable'),
                $valPtr,
                $ht
            );
        }
    }

    /**
     * Lvalue marker for $arr['key'] = … without reading the old value first (#107, #17865).
     */
    public static function prepareStringKeyWrite(Context $context, Value $ht, Value $keyStr): Variable
    {
        $slot = JitValueBox::alloc($context);
        $var = new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $slot
        );
        $var->writableHt = $ht;
        $var->writableStringKey = $keyStr;

        return $var;
    }

    /** Lvalue marker for $arr[$key] = … when $key is a boxed __value__ (issue #86, #17865). */
    public static function prepareValueBoxKeyWrite(Context $context, Value $ht, Variable $dim): Variable
    {
        $slot = JitValueBox::alloc($context);
        $var = new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $slot
        );
        $var->writableHt = $ht;
        $var->writableValueBoxKey = $dim;

        return $var;
    }

    /** Lvalue marker for $arr[0] = … on a native hashtable (#107, #17865). */
    public static function prepareIndexWrite(Context $context, Value $ht, Value $index): Variable
    {
        $slot = JitValueBox::alloc($context);
        $var = new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $slot
        );
        $var->writableHt = $ht;
        $var->writableIndex = $index;

        return $var;
    }

    /**
     * Writable __value__ slot for a string key (creates an empty string entry if missing; #103, #17865).
     */
    public static function writableStringKeyValueBox(Context $context, Value $ht, Value $keyStr): Variable
    {
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
            $ht,
            $keyStr
        );
        $create = BasicBlockHelper::append($context, 'ht_sk_write_create');
        $ready = BasicBlockHelper::append($context, 'ht_sk_write_ready');
        $context->builder->branchIf($isSet, $ready, $create);

        $context->builder->positionAtEnd($create);
        $empty = $context->builder->call($context->lookupFunction('__string__alloc'), $context->constantFromInteger(0, 'size_t'));
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyString'),
            $ht,
            $keyStr,
            $empty
        );
        $context->builder->branch($ready);

        $context->builder->positionAtEnd($ready);
        $valPtr = $context->builder->call(
            $context->lookupFunction('__hashtable__readStringKeyValue'),
            $ht,
            $keyStr
        );
        $var = new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $valPtr
        );
        $var->writableHt = $ht;
        $var->writableStringKey = $keyStr;

        return $var;
    }

    /**
     * Copy a compile-time native array into a refcounted __hashtable__ for calls/properties (#767, #17865).
     */
    public static function materializeNativeArrayForCall(Context $context, Variable $array): Value
    {
        if (0 === ($array->type & Variable::IS_NATIVE_ARRAY)) {
            throw new \LogicException('materializeNativeArrayForCall requires a native array');
        }
        $dest = HashTableHelper::alloc($context);
        $map = $context->structFieldMap['__hashtable__'];
        $elemType = $array->type & ~Variable::IS_NATIVE_ARRAY;
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $count = $context->constantFromInteger($array->nextFreeElement, 'size_t');
        $idxSlot = $context->builder->alloca($sizeT, 1, 'native_ht_idx');
        $context->builder->store($zero, $idxSlot);
        $head = BasicBlockHelper::append($context, 'native_ht_head');
        $body = BasicBlockHelper::append($context, 'native_ht_body');
        $advance = BasicBlockHelper::append($context, 'native_ht_advance');
        $done = BasicBlockHelper::append($context, 'native_ht_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $slot = $context->builder->inBoundsGep($array->value, $zero, $idx);
        if (Variable::TYPE_STRING === $elemType) {
            $elem = new Variable($context, $elemType, Variable::KIND_VARIABLE, $slot);
        } else {
            $elem = new Variable(
                $context,
                $elemType,
                Variable::KIND_VALUE,
                $context->builder->load($slot)
            );
        }
        self::setAtIndex($context, $dest, $idx, $elem);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        $context->builder->store($count, $context->builder->structGep($dest, $map['numElements']));
        $context->builder->store($count, $context->builder->structGep($dest, $map['nextFreeElement']));

        $context->refcount->addref($dest);

        return $dest;
    }

    public static function initArray(Context $context, Variable $result): void
    {
        $result->nextFreeElement = 0;
        $result->compileTimeArray = [];
        if ($result->type & Variable::IS_NATIVE_ARRAY) {
            return;
        }
        if (
            Variable::TYPE_NULL === $result->type
            || Variable::TYPE_NATIVE_BOOL === $result->type
        ) {
            // FETCH_DIM_W / []= on null/false auto-vivifies (#21992, #22650, zend_execute.c).
            $slot = BasicBlockHelper::entryAlloca(
                $context,
                $context->getTypeFromString('__hashtable__*')
            );
            $result->free();
            $result->type = Variable::TYPE_HASHTABLE;
            $result->kind = Variable::KIND_VARIABLE;
            $result->value = $slot;
            $result->initialize();
        }
        if (Variable::TYPE_STRING === $result->type) {
            // Inline include may bind array-literal temps to inherited string slots (#16866).
            $slot = BasicBlockHelper::entryAlloca(
                $context,
                $context->getTypeFromString('__hashtable__*')
            );
            $result->free();
            $result->type = Variable::TYPE_HASHTABLE;
            $result->kind = Variable::KIND_VARIABLE;
            $result->value = $slot;
            $result->initialize();
        }
        self::ensureHashtableInitLvalueSlot($context, $result);
        $ht = HashTableHelper::alloc($context);
        if (Variable::TYPE_VALUE === $result->type) {
            $context->builder->call(
                $context->lookupFunction('__value__writeHashtable'),
                $result->value,
                $ht
            );
            $result->valueBoxHashtable = true;

            return;
        }
        $context->builder->store($ht, $result->value);
    }

    /**
     * Nested array literals may bind INIT_ARRAY temps to a direct __hashtable__* rvalue; initArray
     * must store through an alloca slot (__hashtable__**), not the pointer itself (#827, bootstrap-aot-link).
     */
    private static function ensureHashtableInitLvalueSlot(Context $context, Variable $result): void
    {
        if (Variable::TYPE_HASHTABLE !== $result->type) {
            return;
        }
        $slotTy = $context->getStringFromType($result->value->typeOf());
        if (Variable::KIND_VARIABLE === $result->kind && '__hashtable__**' === $slotTy) {
            return;
        }
        $slot = BasicBlockHelper::entryAlloca(
            $context,
            $context->getTypeFromString('__hashtable__*')
        );
        $result->kind = Variable::KIND_VARIABLE;
        $result->value = $slot;
        $result->initialize();
    }

    /**
     * Stable string key for SplObjectStorage object offsets (pointer identity, issue #601, #18942 v8).
     */
    public static function objectPointerAsStringKey(Context $context, Variable $keyObject): Variable
    {
        if (Variable::TYPE_OBJECT !== $keyObject->type) {
            throw new \LogicException('SplObjectStorage keys must be objects in this compiler build');
        }
        $objPtr = $context->helper->loadValue($keyObject);
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $ptrInt = $context->builder->ptrToInt($objPtr, $sizeT);
        $buf = $context->builder->alloca($context->getTypeFromString('int8'), $sizeT->constInt(32, false), 'spl_key_buf');
        $bufC = $context->builder->pointerCast($buf, $i8p);
        $fmt = $context->builder->pointerCast($context->constantFromString('%zu'), $i8p);
        $context->builder->call($context->lookupFunction('sprintf'), $bufC, $fmt, $ptrInt);
        $len = $context->builder->call($context->lookupFunction('strlen'), $bufC);
        $lenI64 = $context->getTypeFromString('int64');
        $lenForInit = $len->typeOf() === $lenI64
            ? $len
            : $context->builder->zExt($len, $lenI64);
        $str = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $lenForInit,
            $bufC
        );

        return new Variable(
            $context,
            Variable::TYPE_STRING,
            Variable::KIND_VALUE,
            $str
        );
    }

    /**
     * Pack JIT call/recv arguments into a list hashtable (issue #197, #18942 v8).
     *
     * @param list<Variable> $vars
     */
    public static function packVariables(Context $context, array $vars): Variable
    {
        $ht = HashTableHelper::alloc($context);
        $i64 = $context->getTypeFromString('int64');
        foreach ($vars as $index => $var) {
            if (!$var instanceof Variable) {
                continue;
            }
            self::setAtIndex($context, $ht, $i64->constInt($index, false), $var);
        }

        return new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            $ht
        );
    }

    public static function variableFromVmHashTable(Context $context, \PHPCompiler\VM\HashTable $table): Variable
    {
        $ht = HashTableHelper::alloc($context);
        $setLong = $context->lookupFunction('__hashtable__setLongAt');
        $setStringAt = $context->lookupFunction('__hashtable__setStringAt');
        $setStringKey = $context->lookupFunction('__hashtable__setStringKeyString');
        foreach ($table->iterateKeyed(true) as [$keyVar, $valueVar]) {
            $resolved = $valueVar->resolveIndirect();
            if (\PHPCompiler\VM\Variable::TYPE_INTEGER === $keyVar->type) {
                $idx = $context->constantFromInteger($keyVar->toInt(), 'size_t');
                if (\PHPCompiler\VM\Variable::TYPE_INTEGER === $resolved->type) {
                    $context->builder->call(
                        $setLong,
                        $ht,
                        $idx,
                        $context->getTypeFromString('int64')->constInt($resolved->toInt(), false)
                    );
                } elseif (\PHPCompiler\VM\Variable::TYPE_STRING === $resolved->type) {
                    $str = $context->builder->load(
                        $context->constantStringFromString($resolved->toString())
                    );
                    $context->builder->call($setStringAt, $ht, $idx, $str);
                } elseif (\PHPCompiler\VM\Variable::TYPE_BOOLEAN === $resolved->type) {
                    $context->builder->call(
                        $setLong,
                        $ht,
                        $idx,
                        $context->getTypeFromString('int64')->constInt($resolved->toBool() ? 1 : 0, false)
                    );
                } elseif (\PHPCompiler\VM\Variable::TYPE_FLOAT === $resolved->type) {
                    $context->builder->call(
                        $context->lookupFunction('__hashtable__setDoubleAt'),
                        $ht,
                        $idx,
                        $context->constantFromFloat($resolved->toFloat())
                    );
                } elseif (\PHPCompiler\VM\Variable::TYPE_NULL === $resolved->type) {
                    $context->builder->call(
                        $context->lookupFunction('__hashtable__setNullAt'),
                        $ht,
                        $idx
                    );
                } elseif (\PHPCompiler\VM\Variable::TYPE_ARRAY === $resolved->type) {
                    self::setAtIndex(
                        $context,
                        $ht,
                        $idx,
                        self::variableFromVmHashTable($context, $resolved->toArray())
                    );
                } elseif (\PHPCompiler\VM\Variable::TYPE_OBJECT === $resolved->type
                    || \PHPCompiler\VM\Variable::TYPE_ENUM_CASE === $resolved->type) {
                    $context->type->object->embedClassConstArrayVmElementAtIndex($context, $ht, $idx, $resolved);
                } else {
                    throw new \LogicException(
                        'Unsupported class constant array element type for JIT: '
                        .Variable::getStringType(Variable::fromVMVariable($resolved->type))
                    );
                }

                continue;
            }
            if (\PHPCompiler\VM\Variable::TYPE_STRING !== $keyVar->type) {
                continue;
            }
            $key = $context->builder->load(
                $context->constantStringFromString($keyVar->toString())
            );
            if (\PHPCompiler\VM\Variable::TYPE_STRING === $resolved->type) {
                $str = $context->builder->load(
                    $context->constantStringFromString($resolved->toString())
                );
                $context->builder->call($setStringKey, $ht, $key, $str);
            } elseif (\PHPCompiler\VM\Variable::TYPE_INTEGER === $resolved->type) {
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setStringKeyLong'),
                    $ht,
                    $key,
                    $context->getTypeFromString('int64')->constInt($resolved->toInt(), false)
                );
            } elseif (\PHPCompiler\VM\Variable::TYPE_BOOLEAN === $resolved->type) {
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setStringKeyBool'),
                    $ht,
                    $key,
                    $context->getTypeFromString('bool')->constInt($resolved->toBool() ? 1 : 0, false)
                );
            } elseif (\PHPCompiler\VM\Variable::TYPE_FLOAT === $resolved->type) {
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setStringKeyDouble'),
                    $ht,
                    $key,
                    $context->constantFromFloat($resolved->toFloat())
                );
            } elseif (\PHPCompiler\VM\Variable::TYPE_ARRAY === $resolved->type) {
                self::setAtKeyCoercingNumericString(
                    $context,
                    $ht,
                    $key,
                    self::variableFromVmHashTable($context, $resolved->toArray())
                );
            } elseif (\PHPCompiler\VM\Variable::TYPE_NULL === $resolved->type) {
                self::setAtKeyCoercingNumericString(
                    $context,
                    $ht,
                    $key,
                    new Variable(
                        $context,
                        Variable::TYPE_NULL,
                        Variable::KIND_VALUE,
                        $context->getTypeFromString('__value__*')->constNull()
                    )
                );
            } elseif (\PHPCompiler\VM\Variable::TYPE_OBJECT === $resolved->type
                || \PHPCompiler\VM\Variable::TYPE_ENUM_CASE === $resolved->type) {
                $context->type->object->embedClassConstArrayVmElementAtStringKey($context, $ht, $key, $resolved);
            } else {
                throw new \LogicException(
                    'Unsupported class constant array element type for JIT: '
                    .Variable::getStringType(Variable::fromVMVariable($resolved->type))
                );
            }
        }

        return new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            $ht
        );
    }

    public static function unsetStringKey(Context $context, Value $ht, Value $keyStr): void
    {
        $context->builder->call(
            $context->lookupFunction('__hashtable__unsetStringKey'),
            $ht,
            $keyStr
        );
    }
}
