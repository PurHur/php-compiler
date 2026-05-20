<?php

declare(strict_types=1);

/**
 * LLVM helpers for stdlib array builtins (packed __hashtable__).
 */

namespace PHPCompiler\JIT;

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
            throw new \LogicException(
                'This array builtin requires a dynamic array (hashtable), not a fixed native array'
            );
        }
        if (Variable::TYPE_HASHTABLE !== $array->type) {
            throw new \LogicException(
                'Expected array (hashtable), got '.Variable::getStringType($array->type)
            );
        }

        return $context->helper->loadValue($array);
    }

    public static function getNumElements(Context $context, Value $ht): Value
    {
        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $ht
        );

        return $context->builder->zExt($num, $context->getTypeFromString('int64'));
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

    public static function push(Context $context, Variable $array, Variable ...$values): Value
    {
        $ht = self::loadHashTable($context, $array);
        foreach ($values as $value) {
            self::appendElement($context, $ht, $value);
        }

        return self::getNumElements($context, $ht);
    }

    /**
     * @return Value __value__* (null when the array is empty)
     */
    public static function popLast(Context $context, Variable $array): Value
    {
        $ht = self::loadHashTable($context, $array);
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $ht
        );
        $zero = $sizeT->constInt(0, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $num, $zero);
        $resultSlot = JitValueBox::alloc($context);
        $resultPtr = JitValueBox::pointer($context, $resultSlot);

        $emptyBlock = BasicBlockHelper::append($context, 'array_pop_empty');
        $popBlock = BasicBlockHelper::append($context, 'array_pop_work');
        $doneBlock = BasicBlockHelper::append($context, 'array_pop_done');
        $context->builder->branchIf($isEmpty, $emptyBlock, $popBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            $resultPtr
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($popBlock);
        $nextFreePtr = $context->builder->structGep($ht, $map['nextFreeElement']);
        $nextFree = $context->builder->load($nextFreePtr);
        $one = $sizeT->constInt(1, false);
        $lastIndex = $context->builder->sub($nextFree, $one);
        $entry = self::listEntryAt($context, $ht, $lastIndex);
        $longVal = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $entry
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $resultPtr,
            $longVal
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            $entry
        );
        $numPtr = $context->builder->structGep($ht, $map['numElements']);
        $context->builder->store(
            $context->builder->sub($context->builder->load($numPtr), $one),
            $numPtr
        );
        $context->builder->store(
            $context->builder->sub($nextFree, $one),
            $nextFreePtr
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $resultPtr;
    }

    /**
     * @return Value __value__* (null when the array is empty)
     */
    public static function shiftFirst(Context $context, Variable $array): Value
    {
        $ht = self::loadHashTable($context, $array);
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $ht
        );
        $zero = $sizeT->constInt(0, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $num, $zero);
        $resultSlot = JitValueBox::alloc($context);
        $resultPtr = JitValueBox::pointer($context, $resultSlot);

        $emptyBlock = BasicBlockHelper::append($context, 'array_shift_empty');
        $shiftBlock = BasicBlockHelper::append($context, 'array_shift_work');
        $doneBlock = BasicBlockHelper::append($context, 'array_shift_done');
        $context->builder->branchIf($isEmpty, $emptyBlock, $shiftBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            $resultPtr
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($shiftBlock);
        $one = $sizeT->constInt(1, false);
        $zeroIndex = $sizeT->constInt(0, false);
        $firstEntry = self::listEntryAt($context, $ht, $zeroIndex);
        $firstLong = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $firstEntry
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $resultPtr,
            $firstLong
        );

        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_shift_idx');
        $context->builder->store($zeroIndex, $idxSlot);
        $loopHead = BasicBlockHelper::append($context, 'array_shift_head');
        $loopBody = BasicBlockHelper::append($context, 'array_shift_body');
        $afterLoop = BasicBlockHelper::append($context, 'array_shift_after_loop');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $idx = $context->builder->load($idxSlot);
        $lastIndex = $context->builder->sub($num, $one);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $lastIndex);
        $context->builder->branchIf($atEnd, $afterLoop, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $nextIdx = $context->builder->addNoSignedWrap($idx, $one);
        $fromEntry = self::listEntryAt($context, $ht, $nextIdx);
        $toEntry = self::listEntryAt($context, $ht, $idx);
        $movedLong = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $fromEntry
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $toEntry,
            $movedLong
        );
        $context->builder->store($nextIdx, $idxSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($afterLoop);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            self::listEntryAt($context, $ht, $lastIndex)
        );
        $nextFreePtr = $context->builder->structGep($ht, $map['nextFreeElement']);
        $numPtr = $context->builder->structGep($ht, $map['numElements']);
        $context->builder->store(
            $context->builder->sub($context->builder->load($numPtr), $one),
            $numPtr
        );
        $context->builder->store(
            $context->builder->sub($context->builder->load($nextFreePtr), $one),
            $nextFreePtr
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $resultPtr;
    }

    /**
     * Reverse a packed list array into a new packed array (array_reverse subset; matches VM reverseCopy).
     */
    public static function buildReverseArray(Context $context, Variable $array): Value
    {
        if (self::isNativeArray($array->type)) {
            return self::buildReverseFromNativeArray($context, $array);
        }

        return self::buildReverseFromHashTable($context, self::loadHashTable($context, $array));
    }

    /**
     * Copy defined list elements into a new packed array (array_values subset).
     */
    public static function buildValuesArray(Context $context, Variable $array): Value
    {
        if (self::isNativeArray($array->type)) {
            return self::buildValuesFromNativeArray($context, $array);
        }

        return self::buildValuesFromHashTable($context, self::loadHashTable($context, $array));
    }

    /**
     * Copy a sub-range of a packed list array (array_slice subset; matches VM HashTable::sliceCopy).
     *
     * @param Value $offset   int64 slice offset (negative offsets normalized against element count)
     * @param Value $hasLength int1 true when the optional length argument was provided
     * @param Value $length    int64 maximum elements to copy (ignored when $hasLength is false)
     */
    public static function buildSliceArray(
        Context $context,
        Variable $array,
        Value $offset,
        Value $hasLength,
        Value $length
    ): Value {
        if (self::isNativeArray($array->type)) {
            return self::buildSliceFromNativeArray($context, $array, $offset, $hasLength, $length);
        }

        return self::buildSliceFromHashTable(
            $context,
            self::loadHashTable($context, $array),
            $offset,
            $hasLength,
            $length
        );
    }

    private static function normalizeSliceOffset(Context $context, Value $offset, Value $count): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $isNegative = $context->builder->icmp(Builder::INT_SLT, $offset, $zero);

        $negBlock = BasicBlockHelper::append($context, 'array_slice_offset_neg');
        $posBlock = BasicBlockHelper::append($context, 'array_slice_offset_pos');
        $doneBlock = BasicBlockHelper::append($context, 'array_slice_offset_done');
        $context->builder->branchIf($isNegative, $negBlock, $posBlock);

        $context->builder->positionAtEnd($negBlock);
        $adjusted = $context->builder->add($count, $offset);
        $stillNegative = $context->builder->icmp(Builder::INT_SLT, $adjusted, $zero);
        $normalizedNeg = $context->builder->select($stillNegative, $zero, $adjusted);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($posBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($i64);
        $phi->addIncoming($normalizedNeg, $negBlock);
        $phi->addIncoming($offset, $posBlock);

        return $phi;
    }

    private static function buildSliceFromNativeArray(
        Context $context,
        Variable $array,
        Value $offset,
        Value $hasLength,
        Value $length
    ): Value {
        $elemType = $array->type & ~Variable::IS_NATIVE_ARRAY;
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $count = $context->constantFromInteger($array->nextFreeElement, 'size_t');
        $countI64 = $context->builder->zExt($count, $i64);
        $normOffsetI64 = self::normalizeSliceOffset($context, $offset, $countI64);
        $normOffset = $context->builder->truncOrBitCast($normOffsetI64, $sizeT);

        $emptyHt = HashTableHelper::alloc($context);
        $beyondEnd = $context->builder->icmp(Builder::INT_SGE, $normOffset, $count);
        $emptyBlock = BasicBlockHelper::append($context, 'array_slice_native_empty');
        $workBlock = BasicBlockHelper::append($context, 'array_slice_native_work');
        $doneBlock = BasicBlockHelper::append($context, 'array_slice_native_done');
        $context->builder->branchIf($beyondEnd, $emptyBlock, $workBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($workBlock);
        $dest = HashTableHelper::alloc($context);
        $srcIdxSlot = $context->builder->alloca($sizeT, 1, 'array_slice_native_src');
        $destIdxSlot = $context->builder->alloca($sizeT, 1, 'array_slice_native_dest');
        $takenSlot = $context->builder->alloca($sizeT, 1, 'array_slice_native_taken');
        $context->builder->store($normOffset, $srcIdxSlot);
        $context->builder->store($zero, $destIdxSlot);
        $context->builder->store($zero, $takenSlot);
        $lengthSized = $context->builder->truncOrBitCast($length, $sizeT);

        $head = BasicBlockHelper::append($context, 'array_slice_native_head');
        $body = BasicBlockHelper::append($context, 'array_slice_native_body');
        $advance = BasicBlockHelper::append($context, 'array_slice_native_advance');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $srcIdx = $context->builder->load($srcIdxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $srcIdx, $count);
        $context->builder->branchIf($atEnd, $doneBlock, $body);

        $limitExit = BasicBlockHelper::append($context, 'array_slice_native_limit_exit');
        $copyBlock = BasicBlockHelper::append($context, 'array_slice_native_copy');

        $context->builder->positionAtEnd($body);
        $taken = $context->builder->load($takenSlot);
        $limitReached = $context->builder->and(
            $hasLength,
            $context->builder->icmp(Builder::INT_SGE, $taken, $lengthSized)
        );
        $context->builder->branchIf($limitReached, $limitExit, $copyBlock);

        $context->builder->positionAtEnd($limitExit);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($copyBlock);
        $srcIdx = $context->builder->load($srcIdxSlot);
        $slot = $context->builder->inBoundsGep($array->value, $zero, $srcIdx);
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
        $destIdx = $context->builder->load($destIdxSlot);
        HashTableHelper::setAtIndex($context, $dest, $destIdx, $elem);
        $context->builder->store(
            $context->builder->addNoSignedWrap($destIdx, $one),
            $destIdxSlot
        );
        $context->builder->store(
            $context->builder->addNoSignedWrap(
                $context->builder->load($takenSlot),
                $one
            ),
            $takenSlot
        );
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store(
            $context->builder->addNoSignedWrap($srcIdx, $one),
            $srcIdxSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($emptyHt->typeOf());
        $phi->addIncoming($emptyHt, $emptyBlock);
        $phi->addIncoming($dest, $limitExit);
        $phi->addIncoming($dest, $head);

        return $phi;
    }

    private static function buildSliceFromHashTable(
        Context $context,
        Value $src,
        Value $offset,
        Value $hasLength,
        Value $length
    ): Value {
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $nextFree = $context->builder->load(
            $context->builder->structGep($src, $map['nextFreeElement'])
        );
        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $src
        );
        $countI64 = $context->builder->zExt($num, $i64);
        $normOffsetI64 = self::normalizeSliceOffset($context, $offset, $countI64);
        $normOffset = $context->builder->truncOrBitCast($normOffsetI64, $sizeT);
        $lengthSized = $context->builder->truncOrBitCast($length, $sizeT);

        $emptyHt = HashTableHelper::alloc($context);
        $beyondEnd = $context->builder->icmp(Builder::INT_SGE, $normOffset, $nextFree);
        $emptyBlock = BasicBlockHelper::append($context, 'array_slice_empty');
        $workBlock = BasicBlockHelper::append($context, 'array_slice_work');
        $doneBlock = BasicBlockHelper::append($context, 'array_slice_done');
        $context->builder->branchIf($beyondEnd, $emptyBlock, $workBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($workBlock);
        $dest = HashTableHelper::alloc($context);
        $srcIdxSlot = $context->builder->alloca($sizeT, 1, 'array_slice_src');
        $logicalIdxSlot = $context->builder->alloca($sizeT, 1, 'array_slice_logical');
        $destIdxSlot = $context->builder->alloca($sizeT, 1, 'array_slice_dest');
        $takenSlot = $context->builder->alloca($sizeT, 1, 'array_slice_taken');
        $context->builder->store($zero, $srcIdxSlot);
        $context->builder->store($zero, $logicalIdxSlot);
        $context->builder->store($zero, $destIdxSlot);
        $context->builder->store($zero, $takenSlot);

        $head = BasicBlockHelper::append($context, 'array_slice_head');
        $check = BasicBlockHelper::append($context, 'array_slice_check');
        $skipUnset = BasicBlockHelper::append($context, 'array_slice_skip_unset');
        $beforeOffset = BasicBlockHelper::append($context, 'array_slice_before_offset');
        $limitExit = BasicBlockHelper::append($context, 'array_slice_limit_exit');
        $limitDone = BasicBlockHelper::append($context, 'array_slice_limit_done');
        $copyBlock = BasicBlockHelper::append($context, 'array_slice_copy');
        $advanceLogical = BasicBlockHelper::append($context, 'array_slice_advance_logical');
        $advanceSrc = BasicBlockHelper::append($context, 'array_slice_advance_src');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $srcIdx = $context->builder->load($srcIdxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $srcIdx, $nextFree);
        $context->builder->branchIf($atEnd, $doneBlock, $check);

        $context->builder->positionAtEnd($check);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $src,
            $srcIdx
        );
        $context->builder->branchIf($isSet, $beforeOffset, $skipUnset);

        $context->builder->positionAtEnd($skipUnset);
        $context->builder->branch($advanceSrc);

        $context->builder->positionAtEnd($beforeOffset);
        $logicalIdx = $context->builder->load($logicalIdxSlot);
        $beforeSlice = $context->builder->icmp(Builder::INT_SLT, $logicalIdx, $normOffset);
        $context->builder->branchIf($beforeSlice, $advanceLogical, $copyBlock);

        $context->builder->positionAtEnd($copyBlock);
        $taken = $context->builder->load($takenSlot);
        $limitReached = $context->builder->and(
            $hasLength,
            $context->builder->icmp(Builder::INT_SGE, $taken, $lengthSized)
        );
        $context->builder->branchIf($limitReached, $limitExit, $limitDone);

        $context->builder->positionAtEnd($limitExit);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($limitDone);
        $srcIdx = $context->builder->load($srcIdxSlot);
        $destIdx = $context->builder->load($destIdxSlot);
        self::copyListEntry($context, $src, $srcIdx, $dest, $destIdx);
        $context->builder->store(
            $context->builder->addNoSignedWrap($destIdx, $one),
            $destIdxSlot
        );
        $context->builder->store(
            $context->builder->addNoSignedWrap(
                $context->builder->load($takenSlot),
                $one
            ),
            $takenSlot
        );
        $context->builder->branch($advanceLogical);

        $context->builder->positionAtEnd($advanceLogical);
        $context->builder->store(
            $context->builder->addNoSignedWrap($context->builder->load($srcIdxSlot), $one),
            $srcIdxSlot
        );
        $context->builder->store(
            $context->builder->addNoSignedWrap($context->builder->load($logicalIdxSlot), $one),
            $logicalIdxSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($advanceSrc);
        $context->builder->store(
            $context->builder->addNoSignedWrap($context->builder->load($srcIdxSlot), $one),
            $srcIdxSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($emptyHt->typeOf());
        $phi->addIncoming($emptyHt, $emptyBlock);
        $phi->addIncoming($dest, $head);
        $phi->addIncoming($dest, $limitExit);

        return $phi;
    }

    private static function buildValuesFromNativeArray(Context $context, Variable $array): Value
    {
        $elemType = $array->type & ~Variable::IS_NATIVE_ARRAY;
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $count = $context->constantFromInteger($array->nextFreeElement, 'size_t');
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $count, $zero);
        $emptyBlock = BasicBlockHelper::append($context, 'array_values_native_empty');
        $workBlock = BasicBlockHelper::append($context, 'array_values_native_work');
        $doneBlock = BasicBlockHelper::append($context, 'array_values_native_done');
        $context->builder->branchIf($isEmpty, $emptyBlock, $workBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptyHt = HashTableHelper::alloc($context);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($workBlock);
        $dest = HashTableHelper::alloc($context);
        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_values_native_idx');
        $destIdxSlot = $context->builder->alloca($sizeT, 1, 'array_values_native_dest');
        $context->builder->store($zero, $idxSlot);
        $context->builder->store($zero, $destIdxSlot);

        $head = BasicBlockHelper::append($context, 'array_values_native_head');
        $body = BasicBlockHelper::append($context, 'array_values_native_body');
        $advance = BasicBlockHelper::append($context, 'array_values_native_advance');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
        $context->builder->branchIf($atEnd, $doneBlock, $body);

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
        $destIdx = $context->builder->load($destIdxSlot);
        HashTableHelper::setAtIndex($context, $dest, $destIdx, $elem);
        $context->builder->store(
            $context->builder->addNoSignedWrap($destIdx, $one),
            $destIdxSlot
        );
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store(
            $context->builder->addNoSignedWrap($idx, $one),
            $idxSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($emptyHt->typeOf());
        $phi->addIncoming($emptyHt, $emptyBlock);
        $phi->addIncoming($dest, $head);

        return $phi;
    }

    private static function buildReverseFromNativeArray(Context $context, Variable $array): Value
    {
        $elemType = $array->type & ~Variable::IS_NATIVE_ARRAY;
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $count = $context->constantFromInteger($array->nextFreeElement, 'size_t');
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $count, $zero);
        $emptyBlock = BasicBlockHelper::append($context, 'array_reverse_native_empty');
        $workBlock = BasicBlockHelper::append($context, 'array_reverse_native_work');
        $doneBlock = BasicBlockHelper::append($context, 'array_reverse_native_done');
        $context->builder->branchIf($isEmpty, $emptyBlock, $workBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptyHt = HashTableHelper::alloc($context);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($workBlock);
        $dest = HashTableHelper::alloc($context);
        $destIdxSlot = $context->builder->alloca($sizeT, 1, 'array_reverse_native_dest');
        $context->builder->store($zero, $destIdxSlot);

        $head = BasicBlockHelper::append($context, 'array_reverse_native_head');
        $body = BasicBlockHelper::append($context, 'array_reverse_native_body');
        $advance = BasicBlockHelper::append($context, 'array_reverse_native_advance');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $destIdx = $context->builder->load($destIdxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $destIdx, $count);
        $context->builder->branchIf($atEnd, $doneBlock, $body);

        $context->builder->positionAtEnd($body);
        $lastIdx = $context->builder->sub($count, $one);
        $srcIdx = $context->builder->sub($lastIdx, $destIdx);
        $slot = $context->builder->inBoundsGep($array->value, $zero, $srcIdx);
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
        $writeIdx = $context->builder->load($destIdxSlot);
        HashTableHelper::setAtIndex($context, $dest, $writeIdx, $elem);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store(
            $context->builder->addNoSignedWrap($destIdx, $one),
            $destIdxSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($emptyHt->typeOf());
        $phi->addIncoming($emptyHt, $emptyBlock);
        $phi->addIncoming($dest, $head);

        return $phi;
    }

    private static function buildReverseFromHashTable(Context $context, Value $src): Value
    {
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $nextFree = $context->builder->load(
            $context->builder->structGep($src, $map['nextFreeElement'])
        );
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $nextFree, $zero);
        $emptyBlock = BasicBlockHelper::append($context, 'array_reverse_empty');
        $workBlock = BasicBlockHelper::append($context, 'array_reverse_work');
        $doneBlock = BasicBlockHelper::append($context, 'array_reverse_done');
        $context->builder->branchIf($isEmpty, $emptyBlock, $workBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptyHt = HashTableHelper::alloc($context);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($workBlock);
        $dest = HashTableHelper::alloc($context);
        $destIdxSlot = $context->builder->alloca($sizeT, 1, 'array_reverse_dest');
        $context->builder->store($zero, $destIdxSlot);

        $head = BasicBlockHelper::append($context, 'array_reverse_head');
        $body = BasicBlockHelper::append($context, 'array_reverse_body');
        $advance = BasicBlockHelper::append($context, 'array_reverse_advance');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $destIdx = $context->builder->load($destIdxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $destIdx, $nextFree);
        $context->builder->branchIf($atEnd, $doneBlock, $body);

        $context->builder->positionAtEnd($body);
        $lastIdx = $context->builder->sub($nextFree, $one);
        $srcIdx = $context->builder->sub($lastIdx, $destIdx);
        self::copyListEntry($context, $src, $srcIdx, $dest, $destIdx);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store(
            $context->builder->addNoSignedWrap($destIdx, $one),
            $destIdxSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($emptyHt->typeOf());
        $phi->addIncoming($emptyHt, $emptyBlock);
        $phi->addIncoming($dest, $head);

        return $phi;
    }

    private static function buildValuesFromHashTable(Context $context, Value $src): Value
    {
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $nextFree = $context->builder->load(
            $context->builder->structGep($src, $map['nextFreeElement'])
        );
        $zero = $sizeT->constInt(0, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $nextFree, $zero);
        $emptyBlock = BasicBlockHelper::append($context, 'array_values_empty');
        $workBlock = BasicBlockHelper::append($context, 'array_values_work');
        $doneBlock = BasicBlockHelper::append($context, 'array_values_done');
        $context->builder->branchIf($isEmpty, $emptyBlock, $workBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptyHt = HashTableHelper::alloc($context);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($workBlock);
        $dest = HashTableHelper::alloc($context);
        $srcIdxSlot = $context->builder->alloca($sizeT, 1, 'array_values_src');
        $destIdxSlot = $context->builder->alloca($sizeT, 1, 'array_values_dest');
        $context->builder->store($zero, $srcIdxSlot);
        $context->builder->store($zero, $destIdxSlot);
        $one = $sizeT->constInt(1, false);

        $head = BasicBlockHelper::append($context, 'array_values_head');
        $check = BasicBlockHelper::append($context, 'array_values_check');
        $copyBlock = BasicBlockHelper::append($context, 'array_values_copy');
        $skip = BasicBlockHelper::append($context, 'array_values_skip');
        $advance = BasicBlockHelper::append($context, 'array_values_advance');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $srcIdx = $context->builder->load($srcIdxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $srcIdx, $nextFree);
        $context->builder->branchIf($atEnd, $doneBlock, $check);

        $context->builder->positionAtEnd($check);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $src,
            $srcIdx
        );
        $context->builder->branchIf($isSet, $copyBlock, $skip);

        $context->builder->positionAtEnd($copyBlock);
        $destIdx = $context->builder->load($destIdxSlot);
        self::copyListEntry($context, $src, $srcIdx, $dest, $destIdx);
        $context->builder->store(
            $context->builder->addNoSignedWrap($destIdx, $one),
            $destIdxSlot
        );
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($skip);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store(
            $context->builder->addNoSignedWrap($srcIdx, $one),
            $srcIdxSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($emptyHt->typeOf());
        $phi->addIncoming($emptyHt, $emptyBlock);
        $phi->addIncoming($dest, $head);

        return $phi;
    }

    private static function copyListEntry(
        Context $context,
        Value $src,
        Value $srcIndex,
        Value $dest,
        Value $destIndex
    ): void {
        $tag = 'n'.self::$copyListEntrySeq++;
        $srcEntry = self::listEntryAt($context, $src, $srcIndex);
        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($srcEntry, $valueMap['type'])
        );
        $i8 = $context->getTypeFromString('int8');

        $longBlock = BasicBlockHelper::append($context, 'ht_copy_long_'.$tag);
        $stringBlock = BasicBlockHelper::append($context, 'ht_copy_string_'.$tag);
        $doubleBlock = BasicBlockHelper::append($context, 'ht_copy_double_'.$tag);
        $boolBlock = BasicBlockHelper::append($context, 'ht_copy_bool_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_copy_done_'.$tag);

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

        $afterString = BasicBlockHelper::append($context, 'ht_copy_after_string_'.$tag);
        $context->builder->branchIf($isString, $stringBlock, $afterString);

        $context->builder->positionAtEnd($stringBlock);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringAt'),
            $dest,
            $destIndex,
            $context->builder->call($context->lookupFunction('__value__readString'), $srcEntry)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterString);
        $afterLong = BasicBlockHelper::append($context, 'ht_copy_after_long_'.$tag);
        $context->builder->branchIf($isLong, $longBlock, $afterLong);

        $context->builder->positionAtEnd($longBlock);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setLongAt'),
            $dest,
            $destIndex,
            $context->builder->call($context->lookupFunction('__value__readLong'), $srcEntry)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterLong);
        $afterBool = BasicBlockHelper::append($context, 'ht_copy_after_bool_'.$tag);
        $context->builder->branchIf($isBool, $boolBlock, $afterBool);

        $context->builder->positionAtEnd($boolBlock);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setBoolAt'),
            $dest,
            $destIndex,
            $context->builder->truncOrBitCast(
                $context->builder->call($context->lookupFunction('__value__readLong'), $srcEntry),
                $context->getTypeFromString('int1')
            )
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterBool);
        $context->builder->branchIf($isDouble, $doubleBlock, $done);

        $context->builder->positionAtEnd($doubleBlock);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setDoubleAt'),
            $dest,
            $destIndex,
            $context->builder->call($context->lookupFunction('__value__readDouble'), $srcEntry)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    public static function buildKeysArray(Context $context, Value $ht): Value
    {
        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $ht
        );
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $num, $zero);
        $emptyBlock = BasicBlockHelper::append($context, 'array_keys_empty');
        $rangeBlock = BasicBlockHelper::append($context, 'array_keys_range');
        $doneBlock = BasicBlockHelper::append($context, 'array_keys_done');
        $context->builder->branchIf($isEmpty, $emptyBlock, $rangeBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptyHt = HashTableHelper::alloc($context);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($rangeBlock);
        $i64 = $context->getTypeFromString('int64');
        $start = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $end = $context->builder->zExt(
            $context->builder->sub($num, $sizeT->constInt(1, false)),
            $i64
        );
        $keysHt = HashTableHelper::buildIntegerRange($context, $start, $end, $one);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($emptyHt->typeOf());
        $phi->addIncoming($emptyHt, $emptyBlock);
        $phi->addIncoming($keysHt, $rangeBlock);

        return $phi;
    }

    public static function merge(Context $context, Variable ...$arrays): Value
    {
        if (\count($arrays) < 2) {
            throw new \LogicException('array_merge() requires at least two arguments');
        }
        $result = HashTableHelper::alloc($context);
        foreach ($arrays as $array) {
            self::copyInto($context, $result, self::loadHashTable($context, $array));
        }

        return $result;
    }

    public static function copyInto(Context $context, Value $dest, Value $src): void
    {
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $idxSlot = $context->builder->alloca($sizeT, 1, 'merge_idx');
        $context->builder->store($zero, $idxSlot);
        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $src
        );

        $done = BasicBlockHelper::append($context, 'merge_copy_done');
        $head = BasicBlockHelper::append($context, 'merge_copy_head');
        $body = BasicBlockHelper::append($context, 'merge_copy_body');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $num);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $elem = self::readListElement($context, $src, $idx, Variable::TYPE_NATIVE_LONG);
        self::appendElement($context, $dest, $elem);
        $one = $sizeT->constInt(1, false);
        $context->builder->store(
            $context->builder->addNoSignedWrap($idx, $one),
            $idxSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    public static function inArray(
        Context $context,
        Variable $needle,
        Variable $haystack,
        Value $strict
    ): Value {
        $ht = self::loadHashTable($context, $haystack);
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $idxSlot = $context->builder->alloca($sizeT, 1, 'in_array_idx');
        $context->builder->store($zero, $idxSlot);
        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $ht
        );

        $foundSlot = $context->builder->alloca(
            $context->getTypeFromString('int1'),
            1,
            'in_array_found'
        );
        $context->builder->store($context->getTypeFromString('int1')->constInt(0, false), $foundSlot);

        $done = BasicBlockHelper::append($context, 'in_array_done');
        $head = BasicBlockHelper::append($context, 'in_array_head');
        $body = BasicBlockHelper::append($context, 'in_array_body');
        $foundBlock = BasicBlockHelper::append($context, 'in_array_found_block');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $num);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $candidate = self::readListElement($context, $ht, $idx, $needle->type);
        $match = self::valuesEqual($context, $needle, $candidate, $strict);
        $continueBlock = BasicBlockHelper::append($context, 'in_array_continue');
        $context->builder->branchIf($match, $foundBlock, $continueBlock);

        $context->builder->positionAtEnd($continueBlock);
        $context->builder->store(
            $context->builder->addNoSignedWrap($idx, $one),
            $idxSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($foundBlock);
        $context->builder->store($context->getTypeFromString('int1')->constInt(1, false), $foundSlot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);

        return $context->builder->load($foundSlot);
    }

    private static function listEntryAt(Context $context, Value $ht, Value $index): Value
    {
        $map = $context->structFieldMap['__hashtable__'];
        $values = $context->builder->load(
            $context->builder->structGep($ht, $map['values'])
        );

        return $context->builder->inBoundsGep($values, $index);
    }

    private static function readListElement(
        Context $context,
        Value $ht,
        Value $index,
        int $preferredType
    ): Variable {
        if (Variable::TYPE_STRING === $preferredType) {
            $str = $context->builder->call(
                $context->lookupFunction('__hashtable__readStringAt'),
                $ht,
                $index
            );

            return new Variable($context, Variable::TYPE_STRING, Variable::KIND_VALUE, $str);
        }
        $val = $context->builder->call(
            $context->lookupFunction('__hashtable__readLongAt'),
            $ht,
            $index
        );

        return new Variable($context, Variable::TYPE_NATIVE_LONG, Variable::KIND_VALUE, $val);
    }

    private static function valuesEqual(
        Context $context,
        Variable $left,
        Variable $right,
        Value $strict
    ): Value {
        $strictEq = self::strictEqual($context, $left, $right);
        $looseEq = self::looseEqual($context, $left, $right);

        return $context->builder->select($strict, $strictEq, $looseEq);
    }

    private static function strictEqual(Context $context, Variable $left, Variable $right): Value
    {
        if ($left->type !== $right->type) {
            return $context->constantFromBool(false);
        }

        return self::sameTypeEqual($context, $left, $right);
    }

    private static function looseEqual(Context $context, Variable $left, Variable $right): Value
    {
        if ($left->type === $right->type) {
            return self::sameTypeEqual($context, $left, $right);
        }
        if (Variable::TYPE_NATIVE_LONG === $left->type && Variable::TYPE_NATIVE_DOUBLE === $right->type) {
            $l = $context->helper->loadValue($left);
            $r = $context->helper->loadValue($right);
            $lf = $context->builder->siToFp($l, $context->getTypeFromString('double'));

            return $context->builder->fcmp(Builder::REAL_OEQ, $lf, $r);
        }
        if (Variable::TYPE_NATIVE_DOUBLE === $left->type && Variable::TYPE_NATIVE_LONG === $right->type) {
            return self::looseEqual($context, $right, $left);
        }

        return $context->constantFromBool(false);
    }

    public static function sortPacked(Context $context, Variable $array): void
    {
        if (self::isNativeArray($array->type)) {
            throw new \LogicException(
                'sort() cannot compile fixed-size literal arrays in JIT/AOT yet; use bin/vm.php or bin/serve.php, or build the list with [] append'
            );
        }
        $ht = self::loadHashTable($context, $array);
        $context->builder->call($context->lookupFunction('__hashtable__sortPacked'), $ht);
    }

    private static function sameTypeEqual(Context $context, Variable $left, Variable $right): Value
    {
        switch ($left->type) {
            case Variable::TYPE_NATIVE_LONG:
                $l = $context->helper->loadValue($left);
                $r = $context->helper->loadValue($right);

                return $context->builder->icmp(Builder::INT_EQ, $l, $r);
            case Variable::TYPE_NATIVE_DOUBLE:
                $l = $context->helper->loadValue($left);
                $r = $context->helper->loadValue($right);

                return $context->builder->fcmp(Builder::REAL_OEQ, $l, $r);
            case Variable::TYPE_NATIVE_BOOL:
                $l = $context->helper->loadValue($left);
                $r = $context->helper->loadValue($right);

                return $context->builder->icmp(Builder::INT_EQ, $l, $r);
            case Variable::TYPE_STRING:
                $l = $context->helper->loadValue($left);
                $r = $context->helper->loadValue($right);
                $cmp = $context->builder->call($context->lookupFunction('strcmp'), $l, $r);

                return $context->builder->icmp(Builder::INT_EQ, $cmp, $cmp->typeOf()->constInt(0, false));
            case Variable::TYPE_NULL:
                return $context->constantFromBool(true);
            default:
                return $context->constantFromBool(false);
        }
    }
}
