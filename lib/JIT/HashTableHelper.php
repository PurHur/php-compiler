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
    /**
     * HashTable view without objectPropertySlot — isset()/dim on $this->props['k'] (#764).
     */
    public static function asDetachedHashtable(Context $context, Variable $container): Variable
    {
        if (Variable::TYPE_HASHTABLE !== $container->type || null === $container->objectPropertySlot) {
            return $container;
        }

        return new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            $context->helper->loadValue($container)
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
        static $seq = 0;
        $tag = 'af'.(string) ++$seq;
        $ht = self::alloc($context);
        $sizeT = $context->getTypeFromString('size_t');
        $iSlot = $context->builder->alloca($sizeT, 1, 'fill_i_'.$tag);
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
        static $seq = 0;
        $tag = 'rb'.(string) ++$seq;
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
        $slot = JitValueBox::alloc($context);
        $destPtr = JitValueBox::pointer($context, $slot);
        $valPtr = $context->builder->call(
            $context->lookupFunction('__hashtable__readStringKeyValue'),
            $ht,
            $keyStr
        );
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

        return new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $slot
        );
    }

    public static function readStringKeyToValueBox(Context $context, Value $ht, Value $keyStr): Variable
    {
        static $seq = 0;
        $tag = 'sk'.(string) ++$seq;
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
        $ht = $context->helper->loadValue($array);
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

        $ht = $context->helper->loadValue($array);
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

        $context->refcount->addref($dest);

        return $dest;
    }
}
