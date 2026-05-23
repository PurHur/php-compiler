<?php

declare(strict_types=1);

/**
 * LLVM helpers for packed-list __hashtable__ (stdlib array builtins).
 */

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\string_trim;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class HashTableHelper
{
    private static int $seq = 0;

    private static function nextSeq(): int
    {
        return ++self::$seq;
    }

    /**
     * Unbox a {@see Variable::TYPE_VALUE} slot to a __hashtable__* (array_push, count, isset patterns).
     */
    public static function readHashtableFromValueBox(Context $context, Variable $container): Value
    {
        if (Variable::TYPE_VALUE !== $container->type && !JitValueBox::isValueOperand($container)) {
            throw new \LogicException('readHashtableFromValueBox requires TYPE_VALUE');
        }

        return self::ensureHashtablePointer($context, $container);
    }

    /** Materialize empty hashtables for null boxed arrays and object properties (#1086). */
    public static function ensureHashtablePointer(Context $context, Variable $array): Value
    {
        if (null !== $array->objectPropertySlot && Variable::TYPE_VALUE === ($array->objectPropertyType ?? null)) {
            $voidPtr = $context->getTypeFromString('void*');
            $slot = $array->objectPropertySlot;
            $loaded = $context->builder->load($slot);
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
            $newHt = self::alloc($context);
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
            $boxHt = self::alloc($context);
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
        $newHt = self::alloc($context);
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
     * HashTable view without objectPropertySlot — isset()/dim on $this->props['k'] (#764).
     */
    public static function asDetachedHashtable(Context $context, Variable $container): Variable
    {
        if (null === $container->objectPropertySlot) {
            return $container;
        }

        $detached = new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            self::loadHashtablePointer($context, $container)
        );
        $detached->superglobalName = $container->superglobalName;

        return $detached;
    }

    /**
     * Load a native {@see __hashtable__*} from a boxed or direct array variable (#107).
     */

    /** Persist in-place hashtable mutations on native/boxed array operands (#1086). */
    public static function storeHashtableInArrayVariable(Context $context, Variable $array, Value $ht): void
    {
        if (0 !== ($array->type & Variable::IS_NATIVE_ARRAY)) {
            if (Variable::KIND_VARIABLE !== $array->kind) {
                return;
            }
            $boxed = JitValueBox::alloc($context);
            $context->builder->call(
                $context->lookupFunction('__value__writeHashtable'),
                JitValueBox::pointer($context, $boxed),
                $ht
            );
            $voidPtr = $context->getTypeFromString('void*');
            $context->builder->store(
                $context->builder->pointerCast(JitValueBox::pointer($context, $boxed), $voidPtr),
                $array->value
            );
            $array->type = Variable::TYPE_VALUE;
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

    public static function loadHashtablePointer(Context $context, Variable $array): Value
    {
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

    /**
     * Stable string key for SplObjectStorage object offsets (pointer identity, issue #601).
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

    public static function alloc(Context $context): Value
    {
        return $context->builder->call($context->lookupFunction('__hashtable__alloc'));
    }

    public static function buildIntegerRange(
        Context $context,
        Value $start,
        Value $end,
        Value $step
    ): Value {
        $ht = self::alloc($context);
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $iSlot = $context->builder->alloca($i64, 1, 'range_i');
        $idxSlot = $context->builder->alloca($sizeT, 1, 'range_idx');
        $context->builder->store($start, $iSlot);
        $zero = $sizeT->constInt(0, false);
        $context->builder->store($zero, $idxSlot);

        $setLong = $context->lookupFunction('__hashtable__setLongAt');
        $done = BasicBlockHelper::append($context, 'range_done');
        $loopHead = BasicBlockHelper::append($context, 'range_head');
        $loopBody = BasicBlockHelper::append($context, 'range_body');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $stepPos = $context->builder->icmp(Builder::INT_SGT, $step, $i64->constInt(0, false));
        $condPos = $context->builder->icmp(Builder::INT_SLE, $i, $end);
        $condNeg = $context->builder->icmp(Builder::INT_SGE, $i, $end);
        $inRange = $context->builder->select($stepPos, $condPos, $condNeg);
        $context->builder->branchIf($inRange, $loopBody, $done);

        $context->builder->positionAtEnd($loopBody);
        $idx = $context->builder->load($idxSlot);
        $context->builder->call($setLong, $ht, $idx, $i);
        $context->builder->store(
            $context->builder->addNoSignedWrap($i, $step),
            $iSlot
        );
        $one = $sizeT->constInt(1, false);
        $context->builder->store(
            $context->builder->addNoSignedWrap($idx, $one),
            $idxSlot
        );
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($done);

        return $ht;
    }

    public static function buildArrayFill(
        Context $context,
        Value $startIndex,
        Value $count,
        Variable $value
    ): Value {
        $tag = 'af'.(string) self::nextSeq();
        $ht = self::alloc($context);
        $sizeT = $context->getTypeFromString('size_t');
        $iSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $zero = $sizeT->constInt(0, false);
        $context->builder->store($zero, $iSlot);

        $setLong = $context->lookupFunction('__hashtable__setLongAt');
        $setString = $context->lookupFunction('__hashtable__setStringAt');

        $done = BasicBlockHelper::append($context, 'fill_done_'.$tag);
        $loopHead = BasicBlockHelper::append($context, 'fill_head_'.$tag);
        $loopBody = BasicBlockHelper::append($context, 'fill_body_'.$tag);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $count);
        $context->builder->branchIf($atEnd, $done, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $index = $context->builder->addNoSignedWrap($startIndex, $i);
        switch ($value->type) {
            case Variable::TYPE_NATIVE_LONG:
                $context->builder->call(
                    $setLong,
                    $ht,
                    $index,
                    $context->helper->loadValue($value)
                );
                break;
            case Variable::TYPE_STRING:
                $str = $context->helper->loadValue($value);
                $strMap = $context->structFieldMap['__string__'];
                $hayPtr = $context->builder->structGep($str, $strMap['value']);
                $len = $context->builder->load(
                    $context->builder->structGep($str, $strMap['length'])
                );
                $owned = string_trim::jitCopySlice(
                    $context,
                    $str,
                    $hayPtr,
                    $context->getTypeFromString('int64')->constInt(0, false),
                    $len,
                    'fill_'.$tag
                );
                $context->builder->call(
                    $setString,
                    $ht,
                    $index,
                    $owned
                );
                break;
            default:
                throw new \LogicException(
                    'array_fill() value type not supported for JIT: '
                    .Variable::getStringType($value->type)
                );
        }
        $one = $sizeT->constInt(1, false);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($done);

        BasicBlockHelper::branchToFreshContinue($context, 'fill_continue_'.$tag);

        return $ht;
    }

    public static function listEntryPointer(Context $context, Value $ht, Value $index): Value
    {
        $map = $context->structFieldMap['__hashtable__'];
        $values = $context->builder->load(
            $context->builder->structGep($ht, $map['values'])
        );

        return $context->builder->inBoundsGep($values, $index);
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
     * Read a packed-list element into a stack {@see __value__} slot (for echo / mixed-type index).
     */
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
        $context->builder->branchIf($isHt, $htBlock, $longBlock);

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

    /**
     * Lvalue marker for $arr[0] = … on a native hashtable (#107).
     */
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
     * Writable __value__ slot for a string key (creates an empty string entry if missing; issue #103).
     */
    public static function writableStringKeyValueBox(Context $context, Value $ht, Value $keyStr): Variable
    {
        $i1 = $context->getTypeFromString('int1');
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

        $context->builder->positionAtEnd($hasValue);
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

        $context->builder->positionAtEnd($done);

        return new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $slot
        );
    }

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
        $context->builder->branchIf($isHt, $htBlock, $longBlock);

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

    public static function initArray(Context $context, Variable $result): void
    {
        $result->nextFreeElement = 0;
        if ($result->type & Variable::IS_NATIVE_ARRAY) {
            return;
        }
        $ht = self::alloc($context);
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

    public static function addElement(
        Context $context,
        Variable $array,
        Variable $element,
        ?Variable $key = null
    ): void {
        if ($array->type & Variable::IS_NATIVE_ARRAY) {
            self::addNativeElement($context, $array, $element, $key);

            return;
        }
        $ht = self::loadHashtablePointer($context, $array);
        $sizeT = $context->getTypeFromString('size_t');
        if (null === $key) {
            $index = $context->constantFromInteger($array->nextFreeElement, 'size_t');
            ++$array->nextFreeElement;
            self::setAtIndex($context, $ht, $index, $element);

            return;
        }
        if (Variable::TYPE_STRING === $key->type) {
            $keyPtr = $context->helper->loadValue($key);
            self::setAtStringKey($context, $ht, $keyPtr, $element);

            return;
        }
        $index = $context->builder->truncOrBitCast(
            $context->helper->loadValue($key),
            $sizeT
        );
        self::setAtIndex($context, $ht, $index, $element);
    }

    private static function addNativeElement(
        Context $context,
        Variable $array,
        Variable $element,
        ?Variable $key
    ): void {
        if (null !== $key) {
            $index = $context->builder->truncOrBitCast(
                $context->helper->loadValue($key),
                $context->getTypeFromString('size_t')
            );
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

        $ht = self::loadHashtablePointer($context, $array);
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $index = $context->constantFromInteger($array->nextFreeElement, 'size_t');
        ++$array->nextFreeElement;
        $one = $sizeT->constInt(1, false);
        $need = $context->builder->addNoSignedWrap($index, $one);
        $context->builder->call($context->lookupFunction('__hashtable__grow'), $ht, $need);
        $entry = self::listEntryPointer($context, $ht, $index);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $entry);

        $nextFree = $context->builder->load(
            $context->builder->structGep($ht, $map['nextFreeElement'])
        );
        $numElements = $context->builder->load(
            $context->builder->structGep($ht, $map['numElements'])
        );
        $updateNext = $context->builder->icmp(Builder::INT_UGE, $index, $nextFree);
        $newNext = $context->builder->select($updateNext, $need, $nextFree);
        $context->builder->store(
            $newNext,
            $context->builder->structGep($ht, $map['nextFreeElement'])
        );
        $updateNum = $context->builder->icmp(Builder::INT_UGE, $index, $numElements);
        $newNum = $context->builder->select($updateNum, $need, $numElements);
        $context->builder->store(
            $newNum,
            $context->builder->structGep($ht, $map['numElements'])
        );

        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $entry);
    }

    /**
     * Copy a string into an owned heap value before storing in a hashtable.
     * Temporary native-array literals and compile-time strings must not be stored by pointer alone.
     */
    private static function ownedString(Context $context, Variable $element): Value
    {
        $str = $context->helper->loadValue($element);

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
        $valuePtr = Variable::KIND_VARIABLE === $element->kind
            ? JitValueBox::pointer($context, $element->value)
            : $element->value;
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');

        $stringBlock = BasicBlockHelper::append($context, 'ht_idx_vb_string_'.$tag);
        $longBlock = BasicBlockHelper::append($context, 'ht_idx_vb_long_'.$tag);
        $boolBlock = BasicBlockHelper::append($context, 'ht_idx_vb_bool_'.$tag);
        $doubleBlock = BasicBlockHelper::append($context, 'ht_idx_vb_double_'.$tag);
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
        $context->builder->branchIf($isDouble, $doubleBlock, $longBlock);

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

        $context->builder->positionAtEnd($done);
    }

    public static function setAtIndex(Context $context, Value $ht, Value $index, Variable $element): void
    {
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
            case Variable::TYPE_VALUE:
                self::setValueBoxAtIndex($context, $ht, $index, $element);
                break;
            default:
                throw new \LogicException(
                    'Array element type not supported for JIT: '
                    .Variable::getStringType($element->type)
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
            case Variable::TYPE_VALUE:
                $valuePtr = Variable::KIND_VARIABLE === $element->kind
                    ? JitValueBox::pointer($context, $element->value)
                    : $element->value;
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
                    'String-key array element type not supported for JIT: '
                    .Variable::getStringType($element->type)
                );
        }
    }

    /**
     * SplObjectStorage-style map: object identity keys (issue #600 / self-host Compiler.php).
     */
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

    public static function writableObjectKeyValueBox(Context $context, Value $ht, Value $keyObj): Variable
    {
        $i1 = $context->getTypeFromString('int1');
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

    /**
     * Copy a compile-time native array into a refcounted __hashtable__ for calls/properties (issue #767).
     */
    public static function materializeNativeArrayForCall(Context $context, Variable $array): Value
    {
        if (0 === ($array->type & Variable::IS_NATIVE_ARRAY)) {
            throw new \LogicException('materializeNativeArrayForCall requires a native array');
        }
        $dest = self::alloc($context);
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
}
