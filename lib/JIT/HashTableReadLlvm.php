<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\ErrorRaise;
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
        $entryPtr = self::listEntryPointer($context, $ht, $index);
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

    public static function valuePtrFromDim(Context $context, Variable $dim): Value
    {
        if (Variable::TYPE_VALUE !== $dim->type) {
            throw new \LogicException('valuePtrFromDim requires TYPE_VALUE');
        }

        return Variable::KIND_VARIABLE === $dim->kind
            ? JitValueBox::pointer($context, $dim->value)
            : $context->helper->loadValue($dim);
    }

    /** isset() / empty() on a boxed dimension key (#16390, split from HashTableHelper). */
    public static function offsetIsSetValueBoxKey(Context $context, Value $ht, Variable $dim): Value
    {
        $valPtr = self::valuePtrFromDim($context, $dim);
        $valueMap = $context->structFieldMap['__value__'];
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');
        $sizeT = $context->getTypeFromString('size_t');
        $typeByte = $context->builder->load(
            $context->builder->structGep($valPtr, $valueMap['type'])
        );
        $fn = $context->builder->getInsertBlock()->getParent();
        $stringBlock = $fn->appendBasicBlock('ht_isset_vk_str');
        $longBlock = $fn->appendBasicBlock('ht_isset_vk_long');
        $objectBlock = $fn->appendBasicBlock('ht_isset_vk_obj');
        $falseBlock = $fn->appendBasicBlock('ht_isset_vk_false');
        $merge = $fn->appendBasicBlock('ht_isset_vk_merge');
        $afterString = $fn->appendBasicBlock('ht_isset_vk_after_str');
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
        $strResult = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
            $ht,
            $keyStr
        );
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($afterString);
        $afterLong = $fn->appendBasicBlock('ht_isset_vk_after_long');
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
        $index = $context->builder->truncOrBitCast(
            $context->builder->call($context->lookupFunction('__value__readLong'), $valPtr),
            $sizeT
        );
        $longResult = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $ht,
            $index
        );
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($afterLong);
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(Variable::TYPE_OBJECT, false)
            ),
            $objectBlock,
            $falseBlock
        );
        $context->builder->positionAtEnd($objectBlock);
        HashTableHelper::emitIllegalOffsetType($context, 'Illegal offset type in isset or empty');
        $objResult = $context->getTypeFromString('int1')->constInt(0, false);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($falseBlock);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($strResult, $stringBlock);
        $phi->addIncoming($longResult, $longBlock);
        $phi->addIncoming($objResult, $objectBlock);
        $phi->addIncoming($i1->constInt(0, false), $falseBlock);

        return $phi;
    }

    /** Read an element keyed by a boxed dimension into a stack {@see __value__} slot (#16390). */
    public static function readValueBoxKeyToValueBox(
        Context $context,
        Value $ht,
        Variable $dim,
        ?string $superglobalName
    ): Variable {
        $valPtr = self::valuePtrFromDim($context, $dim);
        $valueMap = $context->structFieldMap['__value__'];
        $i8 = $context->getTypeFromString('int8');
        $slot = JitValueBox::alloc($context);
        $destPtr = JitValueBox::pointer($context, $slot);
        $typeByte = $context->builder->load(
            $context->builder->structGep($valPtr, $valueMap['type'])
        );
        $fn = $context->builder->getInsertBlock()->getParent();
        $stringBlock = $fn->appendBasicBlock('ht_read_vk_str');
        $longBlock = $fn->appendBasicBlock('ht_read_vk_long');
        $objectBlock = $fn->appendBasicBlock('ht_read_vk_obj');
        $nullBlock = $fn->appendBasicBlock('ht_read_vk_null');
        $merge = $fn->appendBasicBlock('ht_read_vk_merge');
        $afterString = $fn->appendBasicBlock('ht_read_vk_after_str');
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
        $strBox = null !== $superglobalName
            ? self::readSuperglobalStringKeyToValueBox($context, $ht, $keyStr)
            : self::readStringKeyToValueBox($context, $ht, $keyStr);
        JitValueBox::copyFromPointer(
            $context,
            $destPtr,
            JitValueBox::pointer($context, $strBox->value)
        );
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($afterString);
        $afterLong = $fn->appendBasicBlock('ht_read_vk_after_long');
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
        $index = $context->builder->truncOrBitCast(
            $context->builder->call($context->lookupFunction('__value__readLong'), $valPtr),
            $context->getTypeFromString('size_t')
        );
        $longBox = self::readIndexedToValueBox($context, $ht, $index);
        JitValueBox::copyFromPointer(
            $context,
            $destPtr,
            JitValueBox::pointer($context, $longBox->value)
        );
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($afterLong);
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(Variable::TYPE_OBJECT, false)
            ),
            $objectBlock,
            $nullBlock
        );
        $context->builder->positionAtEnd($objectBlock);
        $keyObj = $context->builder->call($context->lookupFunction('__value__readObject'), $valPtr);
        $objBox = self::readObjectKeyToValueBox($context, $ht, $keyObj);
        JitValueBox::copyFromPointer(
            $context,
            $destPtr,
            JitValueBox::pointer($context, $objBox->value)
        );
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($nullBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            $destPtr
        );
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);

        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
    }

    /**
     * Read a CGI superglobal string slot without multi-block type dispatch (issue #273).
     * Avoids LLVM dominance failures on ?? left branches when the key is absent at compile time.
     */
    public static function readSuperglobalStringKeyToValueBox(
        Context $context,
        Value $ht,
        Value $keyStr
    ): Variable {
        $tag = 'sg'.(string) self::nextSeq();
        $slot = JitValueBox::alloc($context);
        $destPtr = JitValueBox::pointer($context, $slot);
        $valPtr = $context->builder->call(
            $context->lookupFunction('__hashtable__peekStringKeyValue'),
            $ht,
            $keyStr
        );
        $hasValue = BasicBlockHelper::append($context, 'sg_sk_has_'.$tag);
        $missing = BasicBlockHelper::append($context, 'sg_sk_miss_'.$tag);
        $done = BasicBlockHelper::append($context, 'sg_sk_done_'.$tag);
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $valPtr,
            $valPtr->typeOf()->constNull()
        );
        $context->builder->branchIf($isNull, $missing, $hasValue);

        $context->builder->positionAtEnd($missing);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $destPtr);
        $context->builder->branch($done);

        // Typed copy — $_SESSION (and other supers) may hold i:/b:/N; scalars (#21948).
        // Always-string readString/separate segfaulted on TYPE_NATIVE_LONG / BOOL.
        $context->builder->positionAtEnd($hasValue);
        JitValueBox::copyFromPointer($context, $slot, $valPtr);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);

        return new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $slot
        );
    }

    /** isset() / empty() offset check for string, int, object, or boxed keys (#10031 v4). */
    public static function offsetIsSetDim(Context $context, Value $ht, Variable $dim): Value
    {
        if (Variable::TYPE_NULL === $dim->type) {
            $emptyKey = $context->builder->load($context->constantStringFromString(''));

            return $context->builder->call(
                $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
                $ht,
                $emptyKey
            );
        }
        if (Variable::TYPE_STRING === $dim->type) {
            return $context->builder->call(
                $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
                $ht,
                $context->helper->loadValue($dim)
            );
        }
        if (Variable::TYPE_NATIVE_LONG === $dim->type) {
            $index = $context->builder->truncOrBitCast(
                $context->helper->loadValue($dim),
                $context->getTypeFromString('size_t')
            );

            return $context->builder->call(
                $context->lookupFunction('__hashtable__offsetIsSet'),
                $ht,
                $index
            );
        }
        if (Variable::TYPE_OBJECT === $dim->type) {
            HashTableHelper::emitIllegalOffsetType($context, 'Illegal offset type in isset or empty');

            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        if (Variable::TYPE_VALUE === $dim->type) {
            return self::offsetIsSetValueBoxKey($context, $ht, $dim);
        }

        throw new \LogicException(
            'isset() on HashTable arrays only supports integer or string indices in this compiler build'
        );
    }

    /**
     * Read an element into a stack {@see __value__} slot (string/int/object/boxed keys; #10031 v4).
     *
     * @param string|null $superglobalName When set, string keys use superglobal-safe read (issue #273).
     */
    public static function readDimToValueBox(
        Context $context,
        Value $ht,
        Variable $dim,
        ?string $superglobalName = null
    ): Variable {
        if (Variable::TYPE_STRING === $dim->type) {
            $key = $context->helper->loadValue($dim);
            if (null !== $superglobalName) {
                return self::readSuperglobalStringKeyToValueBox($context, $ht, $key);
            }

            return self::readStringKeyToValueBox($context, $ht, $key);
        }
        if (Variable::TYPE_NATIVE_LONG === $dim->type) {
            $index = $context->builder->truncOrBitCast(
                $context->helper->loadValue($dim),
                $context->getTypeFromString('size_t')
            );

            return self::readIndexedToValueBox($context, $ht, $index);
        }
        if (Variable::TYPE_OBJECT === $dim->type) {
            return self::readObjectKeyToValueBox($context, $ht, $context->helper->loadValue($dim));
        }
        if (Variable::TYPE_VALUE === $dim->type) {
            return self::readValueBoxKeyToValueBox($context, $ht, $dim, $superglobalName);
        }

        throw new \LogicException(
            'Array fetch only supports integer or string indices in this compiler build'
        );
    }

    /** Materialize empty hashtables for null boxed arrays and object properties (#1086, #17865). */
    public static function ensureHashtablePointer(Context $context, Variable $array): Value
    {
        if (null !== $array->objectPropertySlot && Variable::TYPE_VALUE === ($array->objectPropertyType ?? null)) {
            $voidPtr = $context->getTypeFromString('void*');
            $slot = $array->objectPropertySlot;
            $loaded = $context->builder->pointerCast(
                $context->builder->load($slot),
                $voidPtr
            );
            $slotEmpty = $context->builder->icmp(
                Builder::INT_EQ,
                $loaded,
                $voidPtr->constNull()
            );
            $initSlot = BasicBlockHelper::append($context, 'ht_ensure_prop_slot_init');
            $useSlot = BasicBlockHelper::append($context, 'ht_ensure_prop_slot_use');
            $done = BasicBlockHelper::append($context, 'ht_ensure_prop_slot_done');
            $context->builder->branchIf($slotEmpty, $initSlot, $useSlot);

            $context->builder->positionAtEnd($initSlot);
            $newHt = HashTableHelper::alloc($context);
            $emptyHt = new Variable(
                $context,
                Variable::TYPE_HASHTABLE,
                Variable::KIND_VALUE,
                $newHt
            );
            $context->type->object->propertyStore($slot, $emptyHt, Variable::TYPE_VALUE);
            $context->builder->branch($done);

            $context->builder->positionAtEnd($useSlot);
            $valPtr = $context->builder->pointerCast(
                $loaded,
                $context->getTypeFromString('__value__*')
            );
            $existing = $context->builder->call(
                $context->lookupFunction('__value__readHashtable'),
                $valPtr
            );
            $needsInit = $context->builder->icmp(
                Builder::INT_EQ,
                $existing,
                $existing->typeOf()->constNull()
            );
            $initBox = BasicBlockHelper::append($context, 'ht_ensure_prop_box_init');
            $ready = BasicBlockHelper::append($context, 'ht_ensure_prop_box_ready');
            $context->builder->branchIf($needsInit, $initBox, $ready);

            $context->builder->positionAtEnd($initBox);
            $boxHt = HashTableHelper::alloc($context);
            $context->builder->call(
                $context->lookupFunction('__value__writeHashtable'),
                $valPtr,
                $boxHt
            );
            $context->builder->branch($done);

            $context->builder->positionAtEnd($ready);
            $context->builder->branch($done);

            $context->builder->positionAtEnd($done);
            $htPhi = $context->builder->phi($newHt->typeOf());
            $htPhi->addIncoming($newHt, $initSlot);
            $htPhi->addIncoming($boxHt, $initBox);
            $htPhi->addIncoming($existing, $ready);

            return $htPhi;
        }

        $valPtr = JitValueBox::valuePtrFromVariable($context, $array);
        $ht = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $valPtr
        );
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $ht,
            $ht->typeOf()->constNull()
        );
        $init = BasicBlockHelper::append($context, 'ht_ensure_box_init');
        $ready = BasicBlockHelper::append($context, 'ht_ensure_box_ready');
        $done = BasicBlockHelper::append($context, 'ht_ensure_box_done');
        $context->builder->branchIf($isNull, $init, $ready);

        $context->builder->positionAtEnd($init);
        $newHt = HashTableHelper::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $valPtr,
            $newHt
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($ready);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $result = $context->builder->phi($ht->typeOf());
        $result->addIncoming($newHt, $init);
        $result->addIncoming($ht, $ready);

        return $result;
    }

    /**
     * Load a native {@see __hashtable__*} from a boxed or direct array variable (#107, #18942 v8).
     */
    public static function loadHashtablePointer(Context $context, Variable $array): Value
    {
        if (Variable::TYPE_STRING === $array->type) {
            ErrorRaise::registerDeclarations($context);
            ErrorRaise::ensureLinked($context);
            ErrorRaise::emitRaise(
                $context,
                \PHPCompiler\VM\TypeCheck::SCALAR_USED_AS_ARRAY_MESSAGE
            );

            return $context->getTypeFromString('__hashtable__*')->constNull();
        }
        if (null !== $array->objectPropertySlot) {
            if (Variable::TYPE_HASHTABLE === ($array->objectPropertyType ?? null)) {
                return $context->builder->pointerCast(
                    $context->builder->load($array->objectPropertySlot),
                    $context->getTypeFromString('__hashtable__*')
                );
            }

            return self::ensureHashtablePointer($context, $array);
        }
        if (Variable::TYPE_HASHTABLE === $array->type) {
            return $context->helper->loadValue($array);
        }
        if (Variable::TYPE_VALUE === $array->type || $array->valueBoxHashtable) {
            return self::ensureHashtablePointer($context, $array);
        }

        throw new \LogicException(
            'Array offset access requires hashtable or boxed array, got '
            .Variable::getStringType($array->type)
        );
    }

    public static function listEntryPointer(Context $context, Value $ht, Value $index): Value
    {
        $map = $context->structFieldMap['__hashtable__'];
        $values = $context->builder->load(
            $context->builder->structGep($ht, $map['values'])
        );

        return $context->builder->inBoundsGep($values, $index);
    }

    /**
     * Live child hashtable at a packed index (nested FETCH_DIM_W intermediate, #24011).
     * Mirrors {@see Builtin\Type\HashTable} `__hashtable__readStringKeyHashtable` for int keys.
     */
    public static function readIndexedHashtable(Context $context, Value $ht, Value $index): Value
    {
        $entry = self::listEntryPointer($context, $ht, $index);

        return $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $entry
        );
    }

    public static function readStringAt(Context $context, Value $ht, Value $index): Value
    {
        $entry = self::listEntryPointer($context, $ht, $index);

        return $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $entry
        );
    }

    /**
     * Walk native __strkey_node__ list — shared by extract/compact scope import (#19035).
     *
     * @param callable(Context, Value, Value): void $body  ($keyStr, $valEntry)
     */
    public static function forEachStringKeyNode(
        Context $context,
        Value $ht,
        string $tagPrefix,
        callable $body
    ): void {
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');
        $tag = $tagPrefix.'_'.(string) self::nextSeq();
        $walkSlot = $context->builder->alloca($nodePtrType, 1, $tag.'_walk');
        $head = $context->builder->load($context->builder->structGep($ht, $map['strKeys']));
        $context->builder->store($head, $walkSlot);

        $headBb = BasicBlockHelper::append($context, $tag.'_head');
        $bodyBb = BasicBlockHelper::append($context, $tag.'_body');
        $nextBb = BasicBlockHelper::append($context, $tag.'_next');
        $doneBb = BasicBlockHelper::append($context, $tag.'_done');
        $context->builder->branch($headBb);

        $context->builder->positionAtEnd($headBb);
        $node = $context->builder->load($walkSlot);
        $nodeNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($nodeNull, $doneBb, $bodyBb);

        $context->builder->positionAtEnd($bodyBb);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $valEntry = $context->builder->structGep($node, $nodeMap['value']);
        $body($context, $keyStr, $valEntry);
        if (null === $context->builder->getInsertBlock()?->getTerminator()) {
            $context->builder->branch($nextBb);
        }

        $context->builder->positionAtEnd($nextBb);
        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($nextNode, $walkSlot);
        $context->builder->branch($headBb);

        $context->builder->positionAtEnd($doneBb);
    }

    /**
     * Walk packed list indices 0..count-1 reading string elements (#19035).
     *
     * @param callable(Context, Value, Value): void $body  ($index, $str)
     */
    public static function forEachIndexedStringAt(
        Context $context,
        Value $ht,
        Value $count,
        string $tagPrefix,
        callable $body
    ): void {
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $tag = $tagPrefix.'_'.(string) self::nextSeq();
        $idxSlot = $context->builder->alloca($sizeT, 1, $tag.'_idx');
        $context->builder->store($zero, $idxSlot);

        $headBb = BasicBlockHelper::append($context, $tag.'_head');
        $bodyBb = BasicBlockHelper::append($context, $tag.'_body');
        $advanceBb = BasicBlockHelper::append($context, $tag.'_advance');
        $doneBb = BasicBlockHelper::append($context, $tag.'_done');
        $context->builder->branch($headBb);

        $context->builder->positionAtEnd($headBb);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
        $context->builder->branchIf($atEnd, $doneBb, $bodyBb);

        $context->builder->positionAtEnd($bodyBb);
        $str = self::readStringAt($context, $ht, $idx);
        $body($context, $idx, $str);
        if (null === $context->builder->getInsertBlock()?->getTerminator()) {
            $context->builder->branch($advanceBb);
        }

        $context->builder->positionAtEnd($advanceBb);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($headBb);

        $context->builder->positionAtEnd($doneBb);
    }

}
