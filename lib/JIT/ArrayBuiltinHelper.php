<?php

declare(strict_types=1);

/**
 * LLVM helpers for stdlib array builtins (packed __hashtable__).
 */

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\StdlibConstants;
use PHPCompiler\ext\standard\array_combine;
use PHPCompiler\ext\standard\JitArrayCountRecursive;
use PHPCompiler\ext\standard\lcfirst;
use PHPCompiler\JIT\Builtin\ArrayMapRuntime;
use PHPCompiler\JIT\Builtin\SortRuntime;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Call;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class ArrayBuiltinHelper
{
    /** Monotonic id so copyListEntry basic blocks stay unique per LLVM function. */
    private static int $copyListEntrySeq = 0;

    public static function isNativeArray(int $type): bool
    {
        return 0 !== ($type & Variable::IS_NATIVE_ARRAY);
    }

    public static function loadHashTable(Context $context, Variable $array): Value
    {
        if (self::isNativeArray($array->type)) {
            return HashTableHelper::materializeNativeArrayForCall($context, $array);
        }
        if (Variable::TYPE_HASHTABLE === $array->type) {
            return $context->helper->loadValue($array);
        }
        if (Variable::TYPE_VALUE === $array->type || JitValueBox::isValueOperand($array)) {
            return HashTableHelper::ensureHashtablePointer($context, $array);
        }
        if (Variable::TYPE_STRING === $array->type) {
            throw new \LogicException(
                'Expected array (hashtable), got string (path strings cannot be used as arrays in this compiler build)'
            );
        }

        throw new \LogicException(
            'Expected array (hashtable), got '.Variable::getStringType($array->type)
        );
    }

    public static function getNumElements(Context $context, Value $ht): Value
    {
        $map = $context->structFieldMap['__hashtable__'];
        $num = $context->builder->load(
            $context->builder->structGep($ht, $map['numElements'])
        );

        return $context->builder->zExt($num, $context->getTypeFromString('int64'));
    }

    /**
     * count($array, COUNT_RECURSIVE) — mirrors VmArray::countRecursive (#3511, #4584).
     */
    public static function countRecursive(Context $context, Variable $array): Value
    {
        $ht = self::isNativeArray($array->type)
            ? self::nativeListToHashTable($context, $array)
            : self::loadHashTable($context, $array);

        return JitArrayCountRecursive::invoke($context, $ht);
    }

    public static function appendElement(Context $context, Value $ht, Variable $element): void
    {
        $map = $context->structFieldMap['__hashtable__'];
        $nextPtr = $context->builder->structGep($ht, $map['nextFreeElement']);
        $index = $context->builder->load($nextPtr);
        HashTableHelper::setAtIndex($context, $ht, $index, $element);
        $one = $context->getTypeFromString('size_t')->constInt(1, false);
        $context->builder->store(
            $context->builder->addNoSignedWrap($index, $one),
            $nextPtr
        );
    }

    /**
     * array_push($stack, ...$values) when JIT merges call-time unpack into one packed list (#1361, #4721).
     *
     * php-src: ext/standard/array.c — zero-length spread is a no-op; stack is argument #1.
     */
    public static function pushMergedCallUnpack(Context $context, Variable $packed): Value
    {
        $packedPtr = $context->helper->loadValue($packed);
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $stackBox = HashTableHelper::readIndexedToValueBox($context, $packedPtr, $zero);
        $stackVar = new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $stackBox->value
        );
        $stackHt = self::loadHashTable($context, $stackVar);
        $count = $context->builder->truncOrBitCast(
            self::getNumElements($context, $packedPtr),
            $sizeT
        );
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($one, $idxSlot);
        $tag = (string) ++self::$copyListEntrySeq;
        $head = BasicBlockHelper::append($context, 'array_push_unpack_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'array_push_unpack_body_'.$tag);
        $advance = BasicBlockHelper::append($context, 'array_push_unpack_advance_'.$tag);
        $done = BasicBlockHelper::append($context, 'array_push_unpack_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $value = HashTableHelper::readIndexedToValueBox($context, $packedPtr, $idx);
        self::appendElement($context, $stackHt, $value);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        HashTableHelper::storeHashtableInArrayVariable($context, $stackVar, $stackHt);

        return self::getNumElements($context, $stackHt);
    }

    private static function copyValueEntrySlot(Context $context, Value $fromEntry, Value $toEntry): void
    {
        $tag = 'vs'.(string) ++self::$copyListEntrySeq;
        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($fromEntry, $valueMap['type'])
        );
        $i8 = $context->getTypeFromString('int8');

        $stringBlock = BasicBlockHelper::append($context, 'value_slot_str_'.$tag);
        $longBlock = BasicBlockHelper::append($context, 'value_slot_long_'.$tag);
        $doubleBlock = BasicBlockHelper::append($context, 'value_slot_double_'.$tag);
        $boolBlock = BasicBlockHelper::append($context, 'value_slot_bool_'.$tag);
        $nullBlock = BasicBlockHelper::append($context, 'value_slot_null_'.$tag);
        $doneBlock = BasicBlockHelper::append($context, 'value_slot_done_'.$tag);

        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING, false)
        );
        $afterString = BasicBlockHelper::append($context, 'value_slot_after_str_'.$tag);
        $context->builder->branchIf($isString, $stringBlock, $afterString);

        $context->builder->positionAtEnd($stringBlock);
        $str = $context->builder->call($context->lookupFunction('__value__readString'), $fromEntry);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $toEntry,
            $context->builder->call($context->lookupFunction('__string__separate'), $str)
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterString);
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );
        $afterLong = BasicBlockHelper::append($context, 'value_slot_after_long_'.$tag);
        $context->builder->branchIf($isLong, $longBlock, $afterLong);

        $context->builder->positionAtEnd($longBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $toEntry,
            $context->builder->call($context->lookupFunction('__value__readLong'), $fromEntry)
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterLong);
        $isDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)
        );
        $afterDouble = BasicBlockHelper::append($context, 'value_slot_after_double_'.$tag);
        $context->builder->branchIf($isDouble, $doubleBlock, $afterDouble);

        $context->builder->positionAtEnd($doubleBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $toEntry,
            $context->builder->call($context->lookupFunction('__value__readDouble'), $fromEntry)
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterDouble);
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false)
        );
        $context->builder->branchIf($isBool, $boolBlock, $nullBlock);

        $context->builder->positionAtEnd($boolBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $toEntry,
            $context->builder->call($context->lookupFunction('__value__readLong'), $fromEntry)
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($nullBlock);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $toEntry);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
    }

    /**
     * array_map() with multiple source arrays — null zip or closure (#4539, ext/standard/array.c).
     *
     * @param list<Variable> $arrays
     */
    public static function buildMapMultipleArrays(Context $context, Variable $callback, array $arrays): Value
    {
        if (Variable::TYPE_NULL === $callback->type || $callback->isNullConstant) {
            return ArrayMapRuntime::mapNullZipMultiple($context, $arrays);
        }
        if (ArrayMapCallbackPolicy::isClosureJitLowerable($callback)) {
            return ArrayMapRuntime::mapMultipleWithClosure($context, $callback, $arrays);
        }
        if (ArrayMapCallbackPolicy::isJitLowerableScalar(
            $callback->type,
            $callback->isNullConstant,
            $callback->compileTimeString
        ) && null !== $callback->compileTimeString) {
            return ArrayMapRuntime::mapMultipleWithBuiltin($context, $arrays, $callback->compileTimeString);
        }

        throw new \LogicException(
            'array_map() with multiple arrays requires a null, closure, or compile-time string builtin callback for JIT/AOT in this compiler build'
        );
    }

    /**
     * Copy a zero-based native list array into a packed hashtable (indices 0..n-1).
     */
    public static function nativeListToHashTable(Context $context, Variable $array): Value
    {
        $dest = HashTableHelper::alloc($context);
        $elemType = $array->type & ~Variable::IS_NATIVE_ARRAY;
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $count = $context->constantFromInteger($array->nextFreeElement, 'size_t');

        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_flip_native_idx');
        $context->builder->store($zero, $idxSlot);
        $head = BasicBlockHelper::append($context, 'array_flip_native_head');
        $body = BasicBlockHelper::append($context, 'array_flip_native_body');
        $advance = BasicBlockHelper::append($context, 'array_flip_native_advance');
        $done = BasicBlockHelper::append($context, 'array_flip_native_done');
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
        HashTableHelper::setAtIndex($context, $dest, $idx, $elem);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        BasicBlockHelper::branchToFreshContinue($context, 'array_flip_native_continue');

        return $dest;
    }



    /**
     * array_combine() length guard — ValueError when counts differ (php-src ext/standard/array.c; #16080).
     *
     * On success, positions the builder at {@see BasicBlockHelper::append()} `{prefix}_work` (or {@param $okSuffix}).
     */
    public static function guardCombinePackedListLengthMismatch(
        Context $context,
        Variable $keys,
        Variable $values,
        string $okSuffix = 'work'
    ): void {
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $keysNum = self::packedListElementCount($context, $keys);
        $valsNum = self::packedListElementCount($context, $values);
        $keysEmpty = $context->builder->icmp(Builder::INT_EQ, $keysNum, $zero);
        $valsEmpty = $context->builder->icmp(Builder::INT_EQ, $valsNum, $zero);
        $bothEmpty = $context->builder->and($keysEmpty, $valsEmpty);
        $eitherEmpty = $context->builder->or($keysEmpty, $valsEmpty);
        $lengthMismatch = $context->builder->icmp(Builder::INT_NE, $keysNum, $valsNum);
        $returnFalse = $context->builder->or(
            $context->builder->and($eitherEmpty, $context->builder->not($bothEmpty)),
            $lengthMismatch
        );

        TypeErrorRaise::emitBranchOrAbortOnValueErrorFailure(
            $context,
            $context->builder->not($returnFalse),
            'array_combine',
            array_combine::LENGTH_MISMATCH_ERROR,
            $okSuffix,
            'count_mismatch'
        );
    }

    /**
     * Packed list length for array_combine() guards (mirrors ext/standard/array_count.php).
     */
    private static function packedListElementCount(Context $context, Variable $array): Value
    {
        if (0 !== ($array->type & Variable::IS_NATIVE_ARRAY)) {
            return $context->constantFromInteger($array->nextFreeElement, 'size_t');
        }
        if (Variable::TYPE_HASHTABLE === $array->type) {
            return $context->builder->call(
                $context->lookupFunction('__hashtable__getNumElements'),
                self::loadHashTable($context, $array)
            );
        }
        if (Variable::TYPE_VALUE === $array->type || JitValueBox::isValueOperand($array)) {
            return $context->builder->call(
                $context->lookupFunction('__hashtable__getNumElements'),
                self::loadHashTable($context, $array)
            );
        }

        throw new \LogicException('array_combine() packedListElementCount: unsupported array operand type');
    }


    public static function copyInto(Context $context, Value $dest, Value $src): void
    {
        self::copyReindexableInto($context, $dest, $src);
    }

    /** Deep copy hashtable (packed + string keys) for (array) cast (#4887, VM CastSupport::toArray). */
    public static function duplicateHashtable(Context $context, Value $src): Value
    {
        $dest = HashTableHelper::alloc($context);
        self::copyReindexableInto($context, $dest, $src);
        self::mergeStringKeysInto($context, $dest, $src);

        return $dest;
    }

    /**
     * Append values from integer-key / numeric-string-key arrays (#3607, #4231).
     */
    public static function copyReindexableInto(Context $context, Value $dest, Value $src): void
    {
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $oneI64 = $i64->constInt(1, false);

        $nextFree = $context->builder->load($context->builder->structGep($src, $map['nextFreeElement']));
        $idxSlot = $context->builder->alloca($sizeT, 1, 'merge_idx');
        $context->builder->store($zero, $idxSlot);

        $done = BasicBlockHelper::append($context, 'merge_copy_done');
        $head = BasicBlockHelper::append($context, 'merge_copy_head');
        $body = BasicBlockHelper::append($context, 'merge_copy_body');
        $bodyInt = BasicBlockHelper::append($context, 'merge_copy_body_int');
        $bodyStr = BasicBlockHelper::append($context, 'merge_copy_body_str');
        $bodyStore = BasicBlockHelper::append($context, 'merge_copy_body_store');
        $bodySkip = BasicBlockHelper::append($context, 'merge_copy_body_skip');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $presentInt = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $src,
            $idx
        );
        $context->builder->branchIf($presentInt, $bodyInt, $bodyStr);

        $context->builder->positionAtEnd($bodyInt);
        $srcEntryInt = self::listEntryAt($context, $src, $idx);
        $context->builder->branch($bodyStore);

        $context->builder->positionAtEnd($bodyStr);
        $idxI64 = $context->builder->zExt($idx, $i64);
        $keyStr = \PHPCompiler\JIT\JitNativeString::formatIndexKey($context, $idxI64);
        $presentStr = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
            $src,
            $keyStr
        );
        $bodyStrHit = BasicBlockHelper::append($context, 'merge_copy_body_str_hit');
        $context->builder->branchIf($presentStr, $bodyStrHit, $bodySkip);

        $context->builder->positionAtEnd($bodyStrHit);
        $srcEntryStr = $context->builder->call(
            $context->lookupFunction('__hashtable__readStringKeyValue'),
            $src,
            $keyStr
        );
        $context->builder->branch($bodyStore);

        $context->builder->positionAtEnd($bodyStore);
        $srcEntry = $context->builder->phi($srcEntryInt->typeOf());
        $srcEntry->addIncoming($srcEntryInt, $bodyInt);
        $srcEntry->addIncoming($srcEntryStr, $bodyStrHit);
        self::appendValueEntryToPacked($context, $dest, $srcEntry);
        $context->builder->branch($bodySkip);

        $context->builder->positionAtEnd($bodySkip);
        $context->builder->store(
            $context->builder->addNoSignedWrap($idx, $one),
            $idxSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function listEntryAt(Context $context, Value $ht, Value $index): Value
    {
        $map = $context->structFieldMap['__hashtable__'];
        $values = $context->builder->load(
            $context->builder->structGep($ht, $map['values'])
        );

        return $context->builder->inBoundsGep($values, $index);
    }


    private static function stringPtrIsIntegerNumeric(Context $context, Value $strPtr): Value
    {
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $len = $context->builder->load(
            $context->builder->structGep($strPtr, $map['length'])
        );
        $charPtr = $context->builder->structGep($strPtr, $map['value']);
        $endPtrSlot = $context->builder->alloca($i8p, 1, 'array_sum_str_long_end');
        $nullEnd = $i8p->constNull();
        $context->builder->store($nullEnd, $endPtrSlot);
        $context->builder->call(
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

        return $context->builder->icmp(Builder::INT_EQ, $endOffset, $len);
    }

    /**
     * True when __string__* is fully consumed by strtod (float numeric string; #4262).
     */
    private static function stringPtrIsDoubleNumeric(Context $context, Value $strPtr): Value
    {
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $len = $context->builder->load(
            $context->builder->structGep($strPtr, $map['length'])
        );
        $charPtr = $context->builder->structGep($strPtr, $map['value']);
        $endPtrSlot = $context->builder->alloca($i8p, 1, 'array_product_str_double_end');
        $context->builder->store($i8p->constNull(), $endPtrSlot);
        $context->builder->call(
            $context->lookupFunction('strtod'),
            $charPtr,
            $endPtrSlot
        );
        $endPtr = $context->builder->load($endPtrSlot);
        $endOffset = $context->builder->sub(
            $context->builder->ptrToInt($endPtr, $i64),
            $context->builder->ptrToInt($charPtr, $i64)
        );

        return $context->builder->icmp(Builder::INT_EQ, $endOffset, $len);
    }

    /** True when __string__* is a Zend numeric string (int or float form; #4262). */
    private static function stringPtrIsNumericString(Context $context, Value $strPtr): Value
    {
        $isIntNumeric = self::stringPtrIsIntegerNumeric($context, $strPtr);
        $isDoubleNumeric = self::stringPtrIsDoubleNumeric($context, $strPtr);

        return $context->builder->or($isIntNumeric, $isDoubleNumeric);
    }


    /**
     * Append a packed list entry (int or string) to dest; preserves count() vs sparse setLongAt.
     */
    private static function appendListEntryScalars(
        Context $context,
        Value $src,
        Value $srcIndex,
        Value $dest
    ): void {
        $tag = 'n'.self::$copyListEntrySeq++;
        $srcEntry = self::listEntryAt($context, $src, $srcIndex);
        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($srcEntry, $valueMap['type'])
        );
        $i8 = $context->getTypeFromString('int8');

        $longBlock = BasicBlockHelper::append($context, 'ht_unique_append_long_'.$tag);
        $stringBlock = BasicBlockHelper::append($context, 'ht_unique_append_string_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_unique_append_done_'.$tag);

        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING, false)
        );
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );

        $afterString = BasicBlockHelper::append($context, 'ht_unique_append_after_string_'.$tag);
        $context->builder->branchIf($isString, $stringBlock, $afterString);

        $context->builder->positionAtEnd($stringBlock);
        self::appendElement(
            $context,
            $dest,
            new Variable(
                $context,
                Variable::TYPE_STRING,
                Variable::KIND_VALUE,
                $context->builder->call($context->lookupFunction('__value__readString'), $srcEntry)
            )
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterString);
        $context->builder->branchIf($isLong, $longBlock, $done);

        $context->builder->positionAtEnd($longBlock);
        self::appendElement(
            $context,
            $dest,
            new Variable(
                $context,
                Variable::TYPE_NATIVE_LONG,
                Variable::KIND_VALUE,
                $context->builder->call($context->lookupFunction('__value__readLong'), $srcEntry)
            )
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

}
