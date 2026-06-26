<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM hashtable read lowering split from HashTableHelper (#10031). */
final class HashTableReadLlvm
{
    private static int $seq = 0;

    private static function nextSeq(): int
    {
        return ++self::$seq;
    }

    public static function readIndexedToValueBox(Context $context, Value $ht, Value $index): Variable
    {
        $tag = 'rb'.(string) self::nextSeq();
        $slot = JitValueBox::alloc($context);
        $destPtr = JitValueBox::pointer($context, $slot);
        $entryPtr = HashTableHelper::listEntryPointer($context, $ht, $index);
        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($entryPtr, $valueMap['type'])
        );
        $i8 = $context->getTypeFromString('int8');

        $stringBlock = BasicBlockHelper::append($context, 'ht_rb_string_'.$tag);
        $htBlock = BasicBlockHelper::append($context, 'ht_rb_ht_'.$tag);
        $checkHt = BasicBlockHelper::append($context, 'ht_rb_check_ht_'.$tag);
        $checkObject = BasicBlockHelper::append($context, 'ht_rb_check_obj_'.$tag);
        $objectBlock = BasicBlockHelper::append($context, 'ht_rb_object_'.$tag);
        $enumCaseBlock = BasicBlockHelper::append($context, 'ht_rb_enum_case_'.$tag);
        $longBlock = BasicBlockHelper::append($context, 'ht_rb_long_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_rb_done_'.$tag);

        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING, false)
        );
        $context->builder->branchIf($isString, $stringBlock, $checkHt);

        $context->builder->positionAtEnd($checkHt);
        $isHt = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_HASHTABLE, false)
        );
        $context->builder->branchIf($isHt, $htBlock, $checkObject);

        $context->builder->positionAtEnd($checkObject);
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_OBJECT, false)
        );
        $checkEnumCase = BasicBlockHelper::append($context, 'ht_rb_check_enum_'.$tag);
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
            $entryPtr
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

        $context->builder->positionAtEnd($htBlock);
        $childHt = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $entryPtr
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $destPtr,
            $childHt
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($objectBlock);
        $obj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $entryPtr
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $destPtr,
            $obj
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($enumCaseBlock);
        $enumObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $entryPtr
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $destPtr,
            $enumObj
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($longBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $destPtr,
            $context->builder->call($context->lookupFunction('__value__readLong'), $entryPtr)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);

        BasicBlockHelper::branchToFreshContinue($context, 'ht_rb_continue_'.$tag);

        return new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $slot
        );
    }

    /**
     * Read an associative string-keyed element into a stack {@see __value__} slot.
     */
    /**
     * Lvalue marker for $arr['key'] = … without reading the old value first (#107).
     */

    public static function readStringKeyToValueBox(Context $context, Value $ht, Value $keyStr): Variable
    {
        $tag = 'sk'.(string) self::nextSeq();
        $slot = JitValueBox::alloc($context);
        $destPtr = JitValueBox::pointer($context, $slot);
        $valPtr = $context->builder->call(
            $context->lookupFunction('__hashtable__readStringKeyValue'),
            $ht,
            $keyStr
        );
        $valueMap = $context->structFieldMap['__value__'];
        $i8 = $context->getTypeFromString('int8');
        $done = BasicBlockHelper::append($context, 'ht_sk_done_'.$tag);
        $isNullPtr = $context->builder->icmp(
            Builder::INT_EQ,
            $valPtr,
            $valPtr->typeOf()->constNull()
        );
        $nullBlock = BasicBlockHelper::append($context, 'ht_sk_null_'.$tag);
        $checkType = BasicBlockHelper::append($context, 'ht_sk_check_type_'.$tag);
        $context->builder->branchIf($isNullPtr, $nullBlock, $checkType);

        $context->builder->positionAtEnd($nullBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            $destPtr
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($checkType);
        $typeByte = $context->builder->load(
            $context->builder->structGep($valPtr, $valueMap['type'])
        );

        $stringBlock = BasicBlockHelper::append($context, 'ht_sk_string_'.$tag);
        $htBlock = BasicBlockHelper::append($context, 'ht_sk_ht_'.$tag);
        $checkHt = BasicBlockHelper::append($context, 'ht_sk_check_ht_'.$tag);
        $checkObject = BasicBlockHelper::append($context, 'ht_sk_check_obj_'.$tag);
        $objectBlock = BasicBlockHelper::append($context, 'ht_sk_object_'.$tag);
        $longBlock = BasicBlockHelper::append($context, 'ht_sk_long_'.$tag);

        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING, false)
        );
        $context->builder->branchIf($isString, $stringBlock, $checkHt);

        $context->builder->positionAtEnd($checkHt);
        $isHt = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_HASHTABLE, false)
        );
        $context->builder->branchIf($isHt, $htBlock, $checkObject);

        $context->builder->positionAtEnd($checkObject);
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_OBJECT, false)
        );
        $context->builder->branchIf($isObject, $objectBlock, $longBlock);

        $context->builder->positionAtEnd($stringBlock);
        $str = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valPtr
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

        $context->builder->positionAtEnd($htBlock);
        $childHt = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $valPtr
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $destPtr,
            $childHt
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($objectBlock);
        $obj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valPtr
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $destPtr,
            $obj
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($longBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $destPtr,
            $context->builder->call($context->lookupFunction('__value__readLong'), $valPtr)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);

        return new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $slot
        );
    }

    public static function readObjectKeyToValueBox(Context $context, Value $ht, Value $keyObj): Variable
    {
        $slot = JitValueBox::alloc($context);
        $destPtr = JitValueBox::pointer($context, $slot);
        $valPtr = $context->builder->call(
            $context->lookupFunction('__hashtable__readObjectKeyValue'),
            $ht,
            $keyObj
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            $destPtr
        );
        $nullType = $context->getTypeFromString('int8')->constInt(0, false);
        $isNullPtr = $context->builder->icmp(
            Builder::INT_EQ,
            $valPtr,
            $valPtr->typeOf()->constNull()
        );
        $copy = BasicBlockHelper::append($context, 'ht_ok_copy');
        $done = BasicBlockHelper::append($context, 'ht_ok_done');
        $context->builder->branchIf($isNullPtr, $done, $copy);
        $context->builder->positionAtEnd($copy);
        $typeByte = $context->builder->load(
            $context->builder->structGep($valPtr, $context->structFieldMap['__value__']['type'])
        );
        $isSet = $context->builder->icmp(
            Builder::INT_NE,
            $typeByte,
            $nullType
        );
        $doCopy = BasicBlockHelper::append($context, 'ht_ok_do_copy');
        $context->builder->branchIf($isSet, $doCopy, $done);
        $context->builder->positionAtEnd($doCopy);
        JitValueBox::copyFromPointer($context, $destPtr, $valPtr);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);

        return new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $slot
        );
    }

}
