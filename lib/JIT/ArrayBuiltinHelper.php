<?php

declare(strict_types=1);

/**
 * LLVM helpers for stdlib array builtins (packed __hashtable__).
 */

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\boolval;
use PHPCompiler\ext\standard\floatval;
use PHPCompiler\ext\standard\intval;
use PHPCompiler\ext\standard\lcfirst;
use PHPCompiler\ext\standard\strval;
use PHPCompiler\ext\standard\string_ltrim;
use PHPCompiler\ext\standard\string_rtrim;
use PHPCompiler\ext\standard\string_trim;
use PHPCompiler\ext\standard\strtolower;
use PHPCompiler\ext\standard\strtoupper;
use PHPCompiler\ext\standard\VmInternalCall;
use PHPCompiler\ext\types\strlen;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Call\ClosureWithCaptures;
use PHPCompiler\JIT\Call\ExternalMethod;
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
        HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);

        return self::getNumElements($context, $ht);
    }

    /**
     * Prepend values to a packed list hashtable; returns new element count.
     */
    public static function unshift(Context $context, Variable $array, Variable ...$values): Value
    {
        $k = \count($values);
        if (0 === $k) {
            return self::getNumElements($context, self::loadHashTable($context, $array));
        }
        $ht = self::loadHashTable($context, $array);
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $ht
        );
        $zero = $sizeT->constInt(0, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $num, $zero);
        $offset = $sizeT->constInt($k, false);

        $emptyBlock = BasicBlockHelper::append($context, 'array_unshift_empty');
        $shiftBlock = BasicBlockHelper::append($context, 'array_unshift_shift');
        $doneBlock = BasicBlockHelper::append($context, 'array_unshift_done');
        $context->builder->branchIf($isEmpty, $emptyBlock, $shiftBlock);

        $context->builder->positionAtEnd($emptyBlock);
        for ($i = 0; $i < $k; ++$i) {
            self::appendElement($context, $ht, $values[$i]);
        }
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($shiftBlock);
        $nextFree = $context->builder->load(
            $context->builder->structGep($ht, $map['nextFreeElement'])
        );
        $hasPacked = $context->builder->icmp(Builder::INT_NE, $nextFree, $zero);
        $prependBlock = BasicBlockHelper::append($context, 'array_unshift_prepend');
        $loopSetup = BasicBlockHelper::append($context, 'array_unshift_setup');
        $loopHead = BasicBlockHelper::append($context, 'array_unshift_head');
        $context->builder->branchIf($hasPacked, $loopSetup, $prependBlock);

        $context->builder->positionAtEnd($loopSetup);
        $minCap = $context->builder->addNoSignedWrap($num, $offset);
        $context->builder->call(
            $context->lookupFunction('__hashtable__grow'),
            $ht,
            $minCap
        );
        $one = $sizeT->constInt(1, false);
        $lastIdx = $context->builder->sub($num, $one);
        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_unshift_idx');
        $context->builder->store($lastIdx, $idxSlot);
        $loopBody = BasicBlockHelper::append($context, 'array_unshift_body');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $idx = $context->builder->load($idxSlot);
        $belowZero = $context->builder->icmp(Builder::INT_SLT, $idx, $zero);
        $context->builder->branchIf($belowZero, $prependBlock, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $destIdx = $context->builder->addNoSignedWrap($idx, $offset);
        $fromEntry = self::listEntryAt($context, $ht, $idx);
        $toEntry = self::listEntryAt($context, $ht, $destIdx);
        self::copyValueEntrySlot($context, $fromEntry, $toEntry);
        $context->builder->store(
            $context->builder->sub($idx, $one),
            $idxSlot
        );
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($prependBlock);
        for ($i = 0; $i < $k; ++$i) {
            HashTableHelper::setAtIndex(
                $context,
                $ht,
                $sizeT->constInt($i, false),
                $values[$i]
            );
        }
        $numPtr = $context->builder->structGep($ht, $map['numElements']);
        $nextPtr = $context->builder->structGep($ht, $map['nextFreeElement']);
        $context->builder->store(
            $context->builder->addNoSignedWrap($context->builder->load($numPtr), $offset),
            $numPtr
        );
        $context->builder->store(
            $context->builder->addNoSignedWrap($context->builder->load($nextPtr), $offset),
            $nextPtr
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);
        if (0 !== ($array->type & Variable::IS_NATIVE_ARRAY)) {
            $array->nextFreeElement += $k;
        }

        return self::getNumElements($context, $ht);
    }

    /**
     * @return Value
     * (null when the array is empty)
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

    private static function copyValueEntryToBox(Context $context, Value $destPtr, Value $entry): void
    {
        $tag = 'vb'.(string) ++self::$copyListEntrySeq;
        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($entry, $valueMap['type'])
        );
        $i8 = $context->getTypeFromString('int8');

        $stringBlock = BasicBlockHelper::append($context, 'value_box_str_'.$tag);
        $longBlock = BasicBlockHelper::append($context, 'value_box_long_'.$tag);
        $doubleBlock = BasicBlockHelper::append($context, 'value_box_double_'.$tag);
        $boolBlock = BasicBlockHelper::append($context, 'value_box_bool_'.$tag);
        $nullBlock = BasicBlockHelper::append($context, 'value_box_null_'.$tag);
        $doneBlock = BasicBlockHelper::append($context, 'value_box_done_'.$tag);

        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING, false)
        );
        $afterString = BasicBlockHelper::append($context, 'value_box_after_str_'.$tag);
        $context->builder->branchIf($isString, $stringBlock, $afterString);

        $context->builder->positionAtEnd($stringBlock);
        $str = $context->builder->call($context->lookupFunction('__value__readString'), $entry);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $destPtr,
            $context->builder->call($context->lookupFunction('__string__separate'), $str)
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterString);
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );
        $afterLong = BasicBlockHelper::append($context, 'value_box_after_long_'.$tag);
        $context->builder->branchIf($isLong, $longBlock, $afterLong);

        $context->builder->positionAtEnd($longBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $destPtr,
            $context->builder->call($context->lookupFunction('__value__readLong'), $entry)
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterLong);
        $isDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)
        );
        $afterDouble = BasicBlockHelper::append($context, 'value_box_after_double_'.$tag);
        $context->builder->branchIf($isDouble, $doubleBlock, $afterDouble);

        $context->builder->positionAtEnd($doubleBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $destPtr,
            $context->builder->call($context->lookupFunction('__value__readDouble'), $entry)
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
            $destPtr,
            $context->builder->call($context->lookupFunction('__value__readLong'), $entry)
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($nullBlock);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $destPtr);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
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
     * @return Value
     * (null when the array is empty)
     */
    public static function shiftFirst(Context $context, Variable $array): Value
    {
        $ht = self::loadHashTable($context, $array);
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
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
        $nextFree = $context->builder->load(
            $context->builder->structGep($ht, $map['nextFreeElement'])
        );
        $hasPacked = $context->builder->icmp(Builder::INT_NE, $nextFree, $zero);
        $packedShift = BasicBlockHelper::append($context, 'array_shift_packed');
        $stringShift = BasicBlockHelper::append($context, 'array_shift_string');
        $context->builder->branchIf($hasPacked, $packedShift, $stringShift);

        $context->builder->positionAtEnd($packedShift);
        $firstEntry = self::listEntryAt($context, $ht, $zeroIndex);
        self::copyValueEntryToBox($context, $resultPtr, $firstEntry);

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
        self::copyValueEntrySlot(
            $context,
            self::listEntryAt($context, $ht, $nextIdx),
            self::listEntryAt($context, $ht, $idx)
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

        $context->builder->positionAtEnd($stringShift);
        $headPtr = $context->builder->structGep($ht, $map['strKeys']);
        $head = $context->builder->load($headPtr);
        $valField = $context->builder->structGep($head, $nodeMap['value']);
        self::copyValueEntryToBox($context, $resultPtr, $valField);
        $nextNode = $context->builder->load(
            $context->builder->structGep($head, $nodeMap['next'])
        );
        $context->builder->store($nextNode, $headPtr);
        $numPtr = $context->builder->structGep($ht, $map['numElements']);
        $context->builder->store(
            $context->builder->sub($context->builder->load($numPtr), $one),
            $numPtr
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
     * array_column() for a list of associative arrays with a compile-time string column key.
     */
    public static function buildColumnArray(Context $context, Variable $array, Value $columnKeyStr): Value
    {
        if (self::isNativeArray($array->type)) {
            return self::buildColumnFromHashTable(
                $context,
                self::nativeListToHashTable($context, $array),
                $columnKeyStr
            );
        }

        return self::buildColumnFromHashTable(
            $context,
            self::loadHashTable($context, $array),
            $columnKeyStr
        );
    }

    /**
     * array_column() with compile-time string column + index_key (ext/standard/array.c php_array_column).
     */
    public static function buildColumnArrayWithIndex(
        Context $context,
        Variable $array,
        Value $columnKeyStr,
        Value $indexKeyStr
    ): Value {
        if (self::isNativeArray($array->type)) {
            return self::buildColumnWithIndexFromHashTable(
                $context,
                self::nativeListToHashTable($context, $array),
                $columnKeyStr,
                $indexKeyStr
            );
        }

        return self::buildColumnWithIndexFromHashTable(
            $context,
            self::loadHashTable($context, $array),
            $columnKeyStr,
            $indexKeyStr
        );
    }

    /**
     * array_filter() default mask: copy elements that are truthy, preserving keys (subset of PHP).
     */
    public static function buildFilterArray(Context $context, Variable $array): Value
    {
        if (self::isNativeArray($array->type)) {
            return self::buildFilterFromNativeArray($context, $array);
        }

        return self::buildFilterFromHashTable($context, self::loadHashTable($context, $array));
    }

    /**
     * array_map() with null or compile-time string builtin callback (subset of PHP).
     */
    public static function buildMapArray(Context $context, Variable $callback, Variable $array): Value
    {
        if (Variable::TYPE_NULL === $callback->type || $callback->isNullConstant) {
            if (self::isNativeArray($array->type)) {
                return self::buildMapNullFromNativeArray($context, $array);
            }

            return self::buildMapNullFromHashTable($context, self::loadHashTable($context, $array));
        }

        $handler = self::resolveMapCallback($callback);
        if (self::isNativeArray($array->type)) {
            return self::buildMapFromNativeArray($context, $handler, $array);
        }

        return self::buildMapFromHashTable($context, $handler, self::loadHashTable($context, $array));
    }

    /**
     * array_map() with closure / arrow callback (issue #142, #1154).
     */
    public static function buildMapArrayWithClosure(Context $context, Variable $callback, Variable $array): Value
    {
        $closureCall = $callback->closureCall;
        if (null === $closureCall) {
            throw new \LogicException(ArrayMapCallbackPolicy::jitRejectionMessage());
        }
        if (self::isNativeArray($array->type)) {
            return self::buildMapFromNativeArrayWithClosure($context, $closureCall, $array);
        }

        return self::buildMapFromHashTableWithClosure(
            $context,
            $closureCall,
            self::loadHashTable($context, $array)
        );
    }

    /**
     * array_reduce() with compile-time string user-function callbacks (issue #1213).
     */
    public static function buildReduceArray(
        Context $context,
        Variable $array,
        Variable $callback,
        ?Variable $initial
    ): Value {
        if (!ArrayReduceCallbackPolicy::isJitLowerable($callback)) {
            throw new \LogicException(ArrayReduceCallbackPolicy::jitRejectionMessage());
        }
        $proxy = self::resolveReduceCallback($context, $callback);
        if ($proxy instanceof ExternalMethod) {
            throw new \LogicException(
                "array_reduce() callback '{$callback->compileTimeString}' is not a defined function in this compile unit"
            );
        }

        return self::buildReduceFromHashTable(
            $context,
            self::loadHashTable($context, $array),
            $proxy,
            $callback->compileTimeString ?? '',
            $initial
        );
    }

    private static function resolveReduceCallback(Context $context, Variable $callback): Call
    {
        $name = $callback->compileTimeString ?? null;
        if (null === $name) {
            throw new \LogicException(ArrayReduceCallbackPolicy::jitRejectionMessage());
        }
        if (!$context->functionIsRegistered($name)) {
            throw new \LogicException(
                "array_reduce() callback '{$name}' is not a defined function in this compile unit"
            );
        }

        return $context->resolveFunctionProxy($name);
    }

    private static function storeReduceCarryFromCallResult(
        Context $context,
        Value $carrySlot,
        Value $folded,
        string $callbackName
    ): void {
        $retTy = $context->functionReturnType[strtolower($callbackName)] ?? '__value__';
        if ('int64' === $retTy) {
            JitValueBox::writeLong($context, $carrySlot, $folded);

            return;
        }
        if ('double' === $retTy) {
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                JitValueBox::pointer($context, $carrySlot),
                $folded
            );

            return;
        }
        if ('bool' === $retTy) {
            JitValueBox::writeBool($context, $carrySlot, $folded);

            return;
        }
        JitValueBox::copyFromPointer(
            $context,
            $carrySlot,
            JitValueBox::normalizeValuePtr($context, $folded)
        );
    }

    private static function buildReduceFromHashTable(
        Context $context,
        Value $src,
        Call $proxy,
        string $callbackName,
        ?Variable $initial
    ): Value {
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $nextFree = $context->builder->load(
            $context->builder->structGep($src, $map['nextFreeElement'])
        );
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $nextFree, $zero);
        $emptyBlock = BasicBlockHelper::append($context, 'array_reduce_empty');
        $workBlock = BasicBlockHelper::append($context, 'array_reduce_work');
        $doneBlock = BasicBlockHelper::append($context, 'array_reduce_done');
        $context->builder->branchIf($isEmpty, $emptyBlock, $workBlock);

        $carrySlot = JitValueBox::alloc($context);
        $carryPtr = JitValueBox::pointer($context, $carrySlot);
        $hasCarrySlot = $context->builder->alloca($context->getTypeFromString('int1'), 1, 'array_reduce_has_carry');
        $i1 = $context->getTypeFromString('int1');
        $context->builder->store($i1->constInt(0, false), $hasCarrySlot);

        $context->builder->positionAtEnd($emptyBlock);
        if (null !== $initial) {
            JitValueBox::copyFromPointer(
                $context,
                $carrySlot,
                JitValueBox::valuePtrFromVariable($context, $initial)
            );
        } else {
            $context->builder->call(
                $context->lookupFunction('__value__writeNull'),
                JitValueBox::pointer($context, $carrySlot)
            );
        }
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($workBlock);
        if (null !== $initial) {
            JitValueBox::copyFromPointer(
                $context,
                $carrySlot,
                JitValueBox::valuePtrFromVariable($context, $initial)
            );
            $context->builder->store($i1->constInt(1, false), $hasCarrySlot);
        }
        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_reduce_idx');
        $context->builder->store($zero, $idxSlot);
        $head = BasicBlockHelper::append($context, 'array_reduce_head');
        $check = BasicBlockHelper::append($context, 'array_reduce_check');
        $reduceBlock = BasicBlockHelper::append($context, 'array_reduce_reduce');
        $skip = BasicBlockHelper::append($context, 'array_reduce_skip');
        $advance = BasicBlockHelper::append($context, 'array_reduce_advance');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $doneBlock, $check);

        $context->builder->positionAtEnd($check);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $src,
            $idx
        );
        $context->builder->branchIf($isSet, $reduceBlock, $skip);

        $context->builder->positionAtEnd($reduceBlock);
        $elem = HashTableHelper::readIndexedToValueBox($context, $src, $idx);
        $hasCarry = $context->builder->load($hasCarrySlot);
        $seedBlock = BasicBlockHelper::append($context, 'array_reduce_seed');
        $foldBlock = BasicBlockHelper::append($context, 'array_reduce_fold');
        $afterFold = BasicBlockHelper::append($context, 'array_reduce_after_fold');
        $context->builder->branchIf($hasCarry, $foldBlock, $seedBlock);

        $context->builder->positionAtEnd($seedBlock);
        JitValueBox::copyFromPointer(
            $context,
            $carrySlot,
            JitValueBox::valuePtrFromVariable($context, $elem)
        );
        $context->builder->store($i1->constInt(1, false), $hasCarrySlot);
        $context->builder->branch($afterFold);

        $context->builder->positionAtEnd($foldBlock);
        $carryVar = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VALUE, $carryPtr);
        $folded = $proxy->call($context, $carryVar, $elem);
        self::storeReduceCarryFromCallResult($context, $carrySlot, $folded, $callbackName);
        $context->builder->branch($afterFold);

        $context->builder->positionAtEnd($afterFold);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($skip);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($doneBlock);
        $resultSlot = JitValueBox::alloc($context);
        JitValueBox::copyFromPointer($context, $resultSlot, $carryPtr);

        return JitValueBox::pointer($context, $resultSlot);
    }

    /** @var array<class-string<Internal>, int> */
    private const MAP_CALLBACK_RESULT_TYPE = [
        strval::class => Variable::TYPE_STRING,
        intval::class => Variable::TYPE_NATIVE_LONG,
        floatval::class => Variable::TYPE_NATIVE_DOUBLE,
        doubleval::class => Variable::TYPE_NATIVE_DOUBLE,
        boolval::class => Variable::TYPE_NATIVE_BOOL,
        strtolower::class => Variable::TYPE_STRING,
        strtoupper::class => Variable::TYPE_STRING,
        string_trim::class => Variable::TYPE_STRING,
        string_ltrim::class => Variable::TYPE_STRING,
        string_rtrim::class => Variable::TYPE_STRING,
        strlen::class => Variable::TYPE_NATIVE_LONG,
    ];

    private static function resolveMapCallback(Variable $callback): Internal
    {
        $name = $callback->compileTimeString ?? null;
        if (null === $name) {
            throw new \LogicException(ArrayMapCallbackPolicy::jitRejectionMessage());
        }

        return VmInternalCall::resolveStringCallback($name);
    }

    private static function mapCallbackResultType(Internal $handler): int
    {
        $type = self::MAP_CALLBACK_RESULT_TYPE[$handler::class] ?? null;
        if (null === $type) {
            throw new \LogicException(
                'array_map() callback is not supported by the JIT compiler in this build'
            );
        }

        return $type;
    }

    private static function closureMapReturnTypeTag(Context $context, Call $call): string
    {
        $native = self::unwrapNativeClosureCall($call);
        if (null === $native) {
            throw new \LogicException(
                'array_map() closure callback must be a compiled user closure in this build'
            );
        }
        $retTy = $context->functionReturnType[strtolower($native->name)] ?? null;
        if (null === $retTy) {
            throw new \LogicException('array_map() closure return type unknown for JIT');
        }

        return $retTy;
    }

    private static function unwrapNativeClosureCall(Call $call): ?Call\Native
    {
        if ($call instanceof ClosureWithCaptures) {
            $call = $call->innerNative();
        }

        return $call instanceof Call\Native ? $call : null;
    }

    private static function storeClosureMappedAtIndex(
        Context $context,
        Value $dest,
        Value $index,
        Value $mapped,
        string $returnTypeTag
    ): void {
        if ('int64' === $returnTypeTag) {
            self::storeMappedAtIndex(
                $context,
                $dest,
                $index,
                new Variable($context, Variable::TYPE_NATIVE_LONG, Variable::KIND_VALUE, $mapped),
                Variable::TYPE_NATIVE_LONG
            );

            return;
        }
        if ('double' === $returnTypeTag) {
            self::storeMappedAtIndex(
                $context,
                $dest,
                $index,
                new Variable($context, Variable::TYPE_NATIVE_DOUBLE, Variable::KIND_VALUE, $mapped),
                Variable::TYPE_NATIVE_DOUBLE
            );

            return;
        }
        if ('__value__' === $returnTypeTag) {
            $valuePtr = $mapped;
            if ('__value__' === $context->getStringFromType($mapped->typeOf())) {
                $slot = $context->builder->alloca($mapped->typeOf(), 1, 'array_map_closure_ret');
                $context->builder->store($mapped, $slot);
                $valuePtr = JitValueBox::pointer($context, $slot);
            }
            $longVal = $context->builder->call(
                $context->lookupFunction('__value__readLong'),
                $valuePtr
            );
            self::storeMappedAtIndex(
                $context,
                $dest,
                $index,
                new Variable($context, Variable::TYPE_NATIVE_LONG, Variable::KIND_VALUE, $longVal),
                Variable::TYPE_NATIVE_LONG
            );

            return;
        }

        throw new \LogicException(
            'array_map() closure return type not supported for JIT: '.$returnTypeTag
        );
    }

    private static function buildMapFromHashTableWithClosure(Context $context, Call $closureCall, Value $src): Value
    {
        $returnTypeTag = self::closureMapReturnTypeTag($context, $closureCall);
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $nextFree = $context->builder->load(
            $context->builder->structGep($src, $map['nextFreeElement'])
        );
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $nextFree, $zero);
        $emptyBlock = BasicBlockHelper::append($context, 'array_map_closure_empty');
        $workBlock = BasicBlockHelper::append($context, 'array_map_closure_work');
        $doneBlock = BasicBlockHelper::append($context, 'array_map_closure_done');
        $context->builder->branchIf($isEmpty, $emptyBlock, $workBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptyHt = HashTableHelper::alloc($context);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($workBlock);
        $dest = HashTableHelper::alloc($context);
        $srcIdxSlot = $context->builder->alloca($sizeT, 1, 'array_map_closure_src');
        $context->builder->store($zero, $srcIdxSlot);
        $head = BasicBlockHelper::append($context, 'array_map_closure_head');
        $check = BasicBlockHelper::append($context, 'array_map_closure_check');
        $mapBlock = BasicBlockHelper::append($context, 'array_map_closure_map');
        $skip = BasicBlockHelper::append($context, 'array_map_closure_skip');
        $advance = BasicBlockHelper::append($context, 'array_map_closure_advance');
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
        $context->builder->branchIf($isSet, $mapBlock, $skip);

        $context->builder->positionAtEnd($mapBlock);
        $elem = HashTableHelper::readIndexedToValueBox($context, $src, $srcIdx);
        $mapped = $closureCall->call($context, $elem);
        self::storeClosureMappedAtIndex($context, $dest, $srcIdx, $mapped, $returnTypeTag);
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

    private static function buildMapFromNativeArrayWithClosure(
        Context $context,
        Call $closureCall,
        Variable $array
    ): Value {
        $returnTypeTag = self::closureMapReturnTypeTag($context, $closureCall);
        $elemType = $array->type & ~Variable::IS_NATIVE_ARRAY;
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $count = $context->constantFromInteger($array->nextFreeElement, 'size_t');
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $count, $zero);
        $emptyBlock = BasicBlockHelper::append($context, 'array_map_closure_native_empty');
        $workBlock = BasicBlockHelper::append($context, 'array_map_closure_native_work');
        $doneBlock = BasicBlockHelper::append($context, 'array_map_closure_native_done');
        $context->builder->branchIf($isEmpty, $emptyBlock, $workBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptyHt = HashTableHelper::alloc($context);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($workBlock);
        $dest = HashTableHelper::alloc($context);
        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_map_closure_native_idx');
        $context->builder->store($zero, $idxSlot);
        $head = BasicBlockHelper::append($context, 'array_map_closure_native_head');
        $body = BasicBlockHelper::append($context, 'array_map_closure_native_body');
        $advance = BasicBlockHelper::append($context, 'array_map_closure_native_advance');
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
        $mapped = $closureCall->call($context, $elem);
        self::storeClosureMappedAtIndex($context, $dest, $idx, $mapped, $returnTypeTag);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($emptyHt->typeOf());
        $phi->addIncoming($emptyHt, $emptyBlock);
        $phi->addIncoming($dest, $head);

        return $phi;
    }

    private static function buildMapNullFromHashTable(Context $context, Value $src): Value
    {
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $nextFree = $context->builder->load(
            $context->builder->structGep($src, $map['nextFreeElement'])
        );
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $nextFree, $zero);
        $emptyBlock = BasicBlockHelper::append($context, 'array_map_null_empty');
        $workBlock = BasicBlockHelper::append($context, 'array_map_null_work');
        $doneBlock = BasicBlockHelper::append($context, 'array_map_null_done');
        $context->builder->branchIf($isEmpty, $emptyBlock, $workBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptyHt = HashTableHelper::alloc($context);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($workBlock);
        $dest = HashTableHelper::alloc($context);
        $srcIdxSlot = $context->builder->alloca($sizeT, 1, 'array_map_null_src');
        $context->builder->store($zero, $srcIdxSlot);
        $head = BasicBlockHelper::append($context, 'array_map_null_head');
        $check = BasicBlockHelper::append($context, 'array_map_null_check');
        $copyBlock = BasicBlockHelper::append($context, 'array_map_null_copy');
        $skip = BasicBlockHelper::append($context, 'array_map_null_skip');
        $advance = BasicBlockHelper::append($context, 'array_map_null_advance');
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
        self::copyMapNullListEntry($context, $src, $srcIdx, $dest, $srcIdx);
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

    private static function copyPackedListEntry(
        Context $context,
        Value $src,
        Value $srcIndex,
        Value $dest,
        Value $destIndex
    ): void {
        self::copyPackedValueEntry(
            $context,
            self::listEntryAt($context, $src, $srcIndex),
            $dest,
            $destIndex
        );
    }

    /**
     * Store a __value__ list entry into a packed hashtable (string/long only; AOT linker subset).
     */
    private static function copyPackedValueEntry(
        Context $context,
        Value $srcEntry,
        Value $dest,
        Value $destIndex
    ): void {
        $tag = 'pv'.(string) ++self::$copyListEntrySeq;
        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($srcEntry, $valueMap['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $stringBlock = BasicBlockHelper::append($context, 'ht_copy_packed_str_'.$tag);
        $longBlock = BasicBlockHelper::append($context, 'ht_copy_packed_long_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_copy_packed_done_'.$tag);

        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING & 0xff, false)
        );
        $context->builder->branchIf($isString, $stringBlock, $longBlock);

        $context->builder->positionAtEnd($stringBlock);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringAt'),
            $dest,
            $destIndex,
            $context->builder->call($context->lookupFunction('__value__readString'), $srcEntry)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($longBlock);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setLongAt'),
            $dest,
            $destIndex,
            $context->builder->call($context->lookupFunction('__value__readLong'), $srcEntry)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    /**
     * Identity copy for array_map(null, …): long and string elements only (AOT-safe linker subset).
     */
    private static function copyMapNullListEntry(
        Context $context,
        Value $src,
        Value $srcIndex,
        Value $dest,
        Value $destIndex
    ): void {
        self::copyPackedListEntry($context, $src, $srcIndex, $dest, $destIndex);
    }

    private static function buildMapNullFromNativeArray(Context $context, Variable $array): Value
    {
        return self::buildMapNullFromHashTable($context, self::nativeListToHashTable($context, $array));
    }

    private static function buildMapFromHashTable(Context $context, Internal $handler, Value $src): Value
    {
        $resultType = self::mapCallbackResultType($handler);
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $nextFree = $context->builder->load(
            $context->builder->structGep($src, $map['nextFreeElement'])
        );
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $nextFree, $zero);
        $emptyBlock = BasicBlockHelper::append($context, 'array_map_empty');
        $workBlock = BasicBlockHelper::append($context, 'array_map_work');
        $doneBlock = BasicBlockHelper::append($context, 'array_map_done');
        $context->builder->branchIf($isEmpty, $emptyBlock, $workBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptyHt = HashTableHelper::alloc($context);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($workBlock);
        $dest = HashTableHelper::alloc($context);
        $srcIdxSlot = $context->builder->alloca($sizeT, 1, 'array_map_src');
        $context->builder->store($zero, $srcIdxSlot);
        $head = BasicBlockHelper::append($context, 'array_map_head');
        $check = BasicBlockHelper::append($context, 'array_map_check');
        $mapBlock = BasicBlockHelper::append($context, 'array_map_map');
        $skip = BasicBlockHelper::append($context, 'array_map_skip');
        $advance = BasicBlockHelper::append($context, 'array_map_advance');
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
        $context->builder->branchIf($isSet, $mapBlock, $skip);

        $context->builder->positionAtEnd($mapBlock);
        $elem = HashTableHelper::readIndexedToValueBox($context, $src, $srcIdx);
        $mapped = $handler->call($context, $elem);
        self::storeMappedAtIndex(
            $context,
            $dest,
            $srcIdx,
            new Variable($context, $resultType, Variable::KIND_VALUE, $mapped),
            $resultType
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

    private static function buildMapFromNativeArray(Context $context, Internal $handler, Variable $array): Value
    {
        $resultType = self::mapCallbackResultType($handler);
        $elemType = $array->type & ~Variable::IS_NATIVE_ARRAY;
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $count = $context->constantFromInteger($array->nextFreeElement, 'size_t');
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $count, $zero);
        $emptyBlock = BasicBlockHelper::append($context, 'array_map_native_empty');
        $workBlock = BasicBlockHelper::append($context, 'array_map_native_work');
        $doneBlock = BasicBlockHelper::append($context, 'array_map_native_done');
        $context->builder->branchIf($isEmpty, $emptyBlock, $workBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptyHt = HashTableHelper::alloc($context);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($workBlock);
        $dest = HashTableHelper::alloc($context);
        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_map_native_idx');
        $context->builder->store($zero, $idxSlot);
        $head = BasicBlockHelper::append($context, 'array_map_native_head');
        $body = BasicBlockHelper::append($context, 'array_map_native_body');
        $advance = BasicBlockHelper::append($context, 'array_map_native_advance');
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
        $mapped = $handler->call($context, $elem);
        self::storeMappedAtIndex(
            $context,
            $dest,
            $idx,
            new Variable($context, $resultType, Variable::KIND_VALUE, $mapped),
            $resultType
        );
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($emptyHt->typeOf());
        $phi->addIncoming($emptyHt, $emptyBlock);
        $phi->addIncoming($dest, $head);

        return $phi;
    }

    private static function storeMappedAtIndex(
        Context $context,
        Value $dest,
        Value $index,
        Variable $element,
        int $resultType
    ): void {
        switch ($resultType) {
            case Variable::TYPE_NATIVE_LONG:
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setLongAt'),
                    $dest,
                    $index,
                    $context->helper->loadValue($element)
                );
                break;
            case Variable::TYPE_STRING:
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setStringAt'),
                    $dest,
                    $index,
                    $context->helper->loadValue($element)
                );
                break;
            case Variable::TYPE_NATIVE_BOOL:
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setBoolAt'),
                    $dest,
                    $index,
                    $context->helper->loadValue($element)
                );
                break;
            case Variable::TYPE_NATIVE_DOUBLE:
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setDoubleAt'),
                    $dest,
                    $index,
                    $context->helper->loadValue($element)
                );
                break;
            default:
                throw new \LogicException(
                    'array_map() mapped value type not supported for JIT: '
                    .Variable::getStringType($resultType)
                );
        }
    }

    /**
     * array_flip() for hashtable arrays (int/string keys and values; subset of PHP).
     */
    public static function buildFlipArray(Context $context, Variable $array): Value
    {
        if (self::isNativeArray($array->type)) {
            return self::buildFlipHashTable($context, self::nativeListToHashTable($context, $array));
        }

        return self::buildFlipHashTable($context, self::loadHashTable($context, $array));
    }

    /**
     * array_change_key_case() — copy hashtable with ASCII-normalized string keys (#78 Phase 2 stdlib).
     */
    public static function buildChangeKeyCaseArray(Context $context, Variable $array, Value $case): Value
    {
        if (self::isNativeArray($array->type)) {
            return self::buildChangeKeyCaseHashTable($context, self::nativeListToHashTable($context, $array), $case);
        }

        return self::buildChangeKeyCaseHashTable($context, self::loadHashTable($context, $array), $case);
    }

    /**
     * Copy a zero-based native list array into a packed hashtable (indices 0..n-1).
     */
    private static function nativeListToHashTable(Context $context, Variable $array): Value
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

    private static function buildFlipHashTable(Context $context, Value $src): Value
    {
        $dest = HashTableHelper::alloc($context);
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');

        $nextFree = $context->builder->load($context->builder->structGep($src, $map['nextFreeElement']));
        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_flip_packed_idx');
        $context->builder->store($zero, $idxSlot);

        $packedHead = BasicBlockHelper::append($context, 'array_flip_packed_head');
        $packedBody = BasicBlockHelper::append($context, 'array_flip_packed_body');
        $packedFlip = BasicBlockHelper::append($context, 'array_flip_packed_flip');
        $packedNext = BasicBlockHelper::append($context, 'array_flip_packed_next');
        $packedDone = BasicBlockHelper::append($context, 'array_flip_packed_done');
        $context->builder->branch($packedHead);

        $context->builder->positionAtEnd($packedHead);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $packedDone, $packedBody);

        $context->builder->positionAtEnd($packedBody);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $src,
            $idx
        );
        $context->builder->branchIf($isSet, $packedFlip, $packedNext);

        $context->builder->positionAtEnd($packedFlip);
        $valEntry = self::listEntryAt($context, $src, $idx);
        self::flipStorePackedEntry($context, $dest, $valEntry, $idx);
        $context->builder->branch($packedNext);

        $context->builder->positionAtEnd($packedNext);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($packedHead);

        $strInit = BasicBlockHelper::append($context, 'array_flip_str_init');
        $strHead = BasicBlockHelper::append($context, 'array_flip_str_head');
        $context->builder->positionAtEnd($packedDone);
        $context->builder->branch($strInit);

        $context->builder->positionAtEnd($strInit);
        $walkSlot = $context->builder->alloca($nodePtrType, 1, 'array_flip_walk');
        $head = $context->builder->load($context->builder->structGep($src, $map['strKeys']));
        $context->builder->store($head, $walkSlot);
        $strBody = BasicBlockHelper::append($context, 'array_flip_str_body');
        $strNext = BasicBlockHelper::append($context, 'array_flip_str_next');
        $strDone = BasicBlockHelper::append($context, 'array_flip_str_done');
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strHead);
        $node = $context->builder->load($walkSlot);
        $nodeNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($nodeNull, $strDone, $strBody);

        $context->builder->positionAtEnd($strBody);
        $valEntry = $context->builder->structGep($node, $nodeMap['value']);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $keySlot = JitValueBox::alloc($context);
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $keyStr);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $keySlot),
            $owned
        );
        $keyEntry = JitValueBox::pointer($context, $keySlot);
        self::flipStoreEntry($context, $dest, $valEntry, $keyEntry);
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($strNext);
        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($nextNode, $walkSlot);
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strDone);
        BasicBlockHelper::branchToFreshContinue($context, 'array_flip_ht_continue');

        return $dest;
    }

    private static function buildChangeKeyCaseHashTable(Context $context, Value $src, Value $case): Value
    {
        $dest = HashTableHelper::alloc($context);
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');
        $caseUpper = $context->builder->icmp(
            Builder::INT_EQ,
            $case,
            $i64->constInt(\PHPCompiler\ext\standard\StdlibConstants::CASE_UPPER, false)
        );

        $nextFree = $context->builder->load($context->builder->structGep($src, $map['nextFreeElement']));
        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_ckc_packed_idx');
        $context->builder->store($zero, $idxSlot);

        $packedHead = BasicBlockHelper::append($context, 'array_ckc_packed_head');
        $packedBody = BasicBlockHelper::append($context, 'array_ckc_packed_body');
        $packedCopy = BasicBlockHelper::append($context, 'array_ckc_packed_copy');
        $packedNext = BasicBlockHelper::append($context, 'array_ckc_packed_next');
        $packedDone = BasicBlockHelper::append($context, 'array_ckc_packed_done');
        $context->builder->branch($packedHead);

        $context->builder->positionAtEnd($packedHead);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $packedDone, $packedBody);

        $context->builder->positionAtEnd($packedBody);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $src,
            $idx
        );
        $context->builder->branchIf($isSet, $packedCopy, $packedNext);

        $context->builder->positionAtEnd($packedCopy);
        $valEntry = self::listEntryAt($context, $src, $idx);
        self::flipStoreValueAtIndex($context, $dest, $idx, $valEntry);
        $context->builder->branch($packedNext);

        $context->builder->positionAtEnd($packedNext);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($packedHead);

        $strInit = BasicBlockHelper::append($context, 'array_ckc_str_init');
        $strHead = BasicBlockHelper::append($context, 'array_ckc_str_head');
        $context->builder->positionAtEnd($packedDone);
        $context->builder->branch($strInit);

        $context->builder->positionAtEnd($strInit);
        $walkSlot = $context->builder->alloca($nodePtrType, 1, 'array_ckc_walk');
        $head = $context->builder->load($context->builder->structGep($src, $map['strKeys']));
        $context->builder->store($head, $walkSlot);
        $strBody = BasicBlockHelper::append($context, 'array_ckc_str_body');
        $strNext = BasicBlockHelper::append($context, 'array_ckc_str_next');
        $strDone = BasicBlockHelper::append($context, 'array_ckc_str_done');
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strHead);
        $node = $context->builder->load($walkSlot);
        $nodeNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($nodeNull, $strDone, $strBody);

        $context->builder->positionAtEnd($strBody);
        $valEntry = $context->builder->structGep($node, $nodeMap['value']);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $keyStr);
        $lowerBb = BasicBlockHelper::append($context, 'array_ckc_key_lower');
        $upperBb = BasicBlockHelper::append($context, 'array_ckc_key_upper');
        $afterCase = BasicBlockHelper::append($context, 'array_ckc_key_done');
        $context->builder->branchIf($caseUpper, $upperBb, $lowerBb);

        $context->builder->positionAtEnd($lowerBb);
        lcfirst::transformAllAscii($context, $owned, ord('A'), ord('Z'), 32);
        $context->builder->branch($afterCase);

        $context->builder->positionAtEnd($upperBb);
        lcfirst::transformAllAscii($context, $owned, ord('a'), ord('z'), -32);
        $context->builder->branch($afterCase);

        $context->builder->positionAtEnd($afterCase);
        self::flipStoreValueAtStringKey($context, $dest, $owned, $valEntry);
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($strNext);
        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($nextNode, $walkSlot);
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strDone);
        BasicBlockHelper::branchToFreshContinue($context, 'array_ckc_ht_continue');

        return $dest;
    }

    /**
     * array_count_values() for packed lists of string or integer values (subset of PHP; #2356).
     */
    public static function arrayCountValues(Context $context, Variable $array): Value
    {
        if (self::isNativeArray($array->type)) {
            return self::countValuesHashTable($context, self::nativeListToHashTable($context, $array));
        }

        return self::countValuesHashTable($context, self::loadHashTable($context, $array));
    }

    private static function countValuesHashTable(Context $context, Value $src): Value
    {
        $dest = HashTableHelper::alloc($context);
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);

        $nextFree = $context->builder->load($context->builder->structGep($src, $map['nextFreeElement']));
        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_count_values_idx');
        $context->builder->store($zero, $idxSlot);

        $head = BasicBlockHelper::append($context, 'array_count_values_head');
        $body = BasicBlockHelper::append($context, 'array_count_values_body');
        $count = BasicBlockHelper::append($context, 'array_count_values_count');
        $next = BasicBlockHelper::append($context, 'array_count_values_next');
        $done = BasicBlockHelper::append($context, 'array_count_values_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $src,
            $idx
        );
        $context->builder->branchIf($isSet, $count, $next);

        $context->builder->positionAtEnd($count);
        $valEntry = self::listEntryAt($context, $src, $idx);
        self::countIncrementPackedEntry($context, $dest, $valEntry);
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        BasicBlockHelper::branchToFreshContinue($context, 'array_count_values_continue');

        return $dest;
    }

    /**
     * Increment occurrence count for one packed input value in the result hashtable.
     */
    private static function countIncrementPackedEntry(Context $context, Value $dest, Value $valEntry): void
    {
        static $serial = 0;
        $id = (string) (++$serial);

        $valueMap = $context->structFieldMap['__value__'];
        $valType = $context->builder->load(
            $context->builder->structGep($valEntry, $valueMap['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $oneLong = $i64->constInt(1, false);

        $stringBlock = BasicBlockHelper::append($context, 'array_count_values_val_string_'.$id);
        $longBlock = BasicBlockHelper::append($context, 'array_count_values_val_long_'.$id);
        $done = BasicBlockHelper::append($context, 'array_count_values_val_done_'.$id);

        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $valType,
            $i8->constInt(Variable::TYPE_STRING, false)
        );
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $valType,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );

        $afterString = BasicBlockHelper::append($context, 'array_count_values_after_string_'.$id);
        $context->builder->branchIf($isString, $stringBlock, $afterString);

        $context->builder->positionAtEnd($stringBlock);
        $keyStr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valEntry
        );
        $ownedKey = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $keyStr
        );
        $exists = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
            $dest,
            $ownedKey
        );
        $strNew = BasicBlockHelper::append($context, 'array_count_values_str_new_'.$id);
        $strInc = BasicBlockHelper::append($context, 'array_count_values_str_inc_'.$id);
        $strDone = BasicBlockHelper::append($context, 'array_count_values_str_done_'.$id);
        $context->builder->branchIf($exists, $strInc, $strNew);

        $context->builder->positionAtEnd($strNew);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $dest,
            $ownedKey,
            $oneLong
        );
        $context->builder->branch($strDone);

        $context->builder->positionAtEnd($strInc);
        $existing = $context->builder->call(
            $context->lookupFunction('__hashtable__peekStringKeyValue'),
            $dest,
            $ownedKey
        );
        $oldCount = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $existing
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $dest,
            $ownedKey,
            $context->builder->addNoSignedWrap($oldCount, $oneLong)
        );
        $context->builder->branch($strDone);

        $context->builder->positionAtEnd($strDone);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterString);
        $context->builder->branchIf($isLong, $longBlock, $done);

        $context->builder->positionAtEnd($longBlock);
        $keyIdx = $context->builder->truncOrBitCast(
            $context->builder->call($context->lookupFunction('__value__readLong'), $valEntry),
            $sizeT
        );
        $existsIdx = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $dest,
            $keyIdx
        );
        $longNew = BasicBlockHelper::append($context, 'array_count_values_long_new_'.$id);
        $longInc = BasicBlockHelper::append($context, 'array_count_values_long_inc_'.$id);
        $longDone = BasicBlockHelper::append($context, 'array_count_values_long_done_'.$id);
        $context->builder->branchIf($existsIdx, $longInc, $longNew);

        $context->builder->positionAtEnd($longNew);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setLongAt'),
            $dest,
            $keyIdx,
            $oneLong
        );
        $context->builder->branch($longDone);

        $context->builder->positionAtEnd($longInc);
        $oldCountIdx = $context->builder->call(
            $context->lookupFunction('__hashtable__readLongAt'),
            $dest,
            $keyIdx
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setLongAt'),
            $dest,
            $keyIdx,
            $context->builder->addNoSignedWrap($oldCountIdx, $oneLong)
        );
        $context->builder->branch($longDone);

        $context->builder->positionAtEnd($longDone);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
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
        self::copyPackedListEntry($context, $src, $srcIdx, $dest, $destIdx);
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

    /**
     * array_splice() for packed list arrays — returns removed slice, mutates source (issue #1205).
     *
     * @param Value $offset   int64
     * @param Value $hasLength int1
     * @param Value $length    int64 (ignored when $hasLength is false)
     */
    public static function buildSpliceArray(
        Context $context,
        Variable $array,
        Value $offset,
        Value $hasLength,
        Value $length,
        ?Variable $replacement,
        bool $hasReplacement
    ): Value {
        if (self::isNativeArray($array->type)) {
            $ht = self::nativeListToHashTable($context, $array);
            $removed = self::buildSpliceFromHashTable(
                $context,
                $ht,
                $offset,
                $hasLength,
                $length,
                $replacement,
                $hasReplacement
            );
            HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);

            return $removed;
        }
        $ht = self::loadHashTable($context, $array);
        $removed = self::buildSpliceFromHashTable(
            $context,
            $ht,
            $offset,
            $hasLength,
            $length,
            $replacement,
            $hasReplacement
        );
        HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);

        return $removed;
    }

    private static function buildSpliceFromHashTable(
        Context $context,
        Value $src,
        Value $offset,
        Value $hasLength,
        Value $length,
        ?Variable $replacement,
        bool $hasReplacement
    ): Value {
        $snapshot = self::clonePackedHashTable($context, $src);
        $removed = self::buildSliceFromHashTable($context, $snapshot, $offset, $hasLength, $length);

        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $src
        );
        $countI64 = $context->builder->zExt($num, $i64);
        $normOffsetI64 = self::normalizeSliceOffset($context, $offset, $countI64);
        $normOffset = $context->builder->truncOrBitCast($normOffsetI64, $sizeT);
        $removeLen = self::computeSpliceRemoveLen($context, $num, $normOffset, $hasLength, $length);

        $replHt = null;
        $replCount = $zero;
        if ($hasReplacement && null !== $replacement) {
            $replHt = self::isNativeArray($replacement->type)
                ? self::nativeListToHashTable($context, $replacement)
                : self::loadHashTable($context, $replacement);
            $replCount = $context->builder->load(
                $context->builder->structGep($replHt, $map['nextFreeElement'])
            );
        }

        $tailStart = $context->builder->add($normOffset, $removeLen);
        $tailLen = $context->builder->sub($num, $tailStart);
        $newNum = $context->builder->add(
            $context->builder->add($normOffset, $replCount),
            $tailLen
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__grow'),
            $src,
            $newNum
        );

        $temp = HashTableHelper::alloc($context);
        $destIdxSlot = $context->builder->alloca($sizeT, 1, 'array_splice_dest');
        $context->builder->store($zero, $destIdxSlot);

        self::copyPackedPrefixToTemp(
            $context,
            $snapshot,
            $zero,
            $normOffset,
            $temp,
            $destIdxSlot
        );
        if ($hasReplacement && null !== $replHt) {
            self::copyPackedHashtableToTemp(
                $context,
                $replHt,
                $temp,
                $destIdxSlot
            );
        }
        self::copyPackedPrefixToTemp(
            $context,
            $snapshot,
            $tailStart,
            $tailLen,
            $temp,
            $destIdxSlot
        );

        self::copyTempPackedIntoHashtable($context, $temp, $src, $newNum);

        return $removed;
    }

    private static function computeSpliceRemoveLen(
        Context $context,
        Value $num,
        Value $normOffset,
        Value $hasLength,
        Value $length
    ): Value {
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $zero = $sizeT->constInt(0, false);
        $lengthSized = $context->builder->truncOrBitCast($length, $sizeT);
        $lengthI64 = $context->builder->sext($length, $i64);
        $defaultLen = $context->builder->sub($num, $normOffset);
        $negAdjLen = $context->builder->truncOrBitCast(
            $context->builder->add(
                $context->builder->sext($defaultLen, $i64),
                $lengthI64
            ),
            $sizeT
        );
        $withLength = $context->builder->select($hasLength, $lengthSized, $defaultLen);
        $negLength = $context->builder->and(
            $hasLength,
            $context->builder->icmp(Builder::INT_SLT, $lengthI64, $i64->constInt(0, false))
        );
        $removeLen = $context->builder->select($negLength, $negAdjLen, $withLength);

        $isNegative = $context->builder->icmp(Builder::INT_SLT, $removeLen, $zero);
        $removeLen = $context->builder->select($isNegative, $zero, $removeLen);

        $offsetAtOrPastEnd = $context->builder->icmp(Builder::INT_SGE, $normOffset, $num);
        $maxRem = $context->builder->sub($num, $normOffset);
        $tooMuch = $context->builder->icmp(Builder::INT_SGT, $removeLen, $maxRem);
        $capped = $context->builder->select($tooMuch, $maxRem, $removeLen);

        return $context->builder->select($offsetAtOrPastEnd, $zero, $capped);
    }

    private static function copyPackedPrefixToTemp(
        Context $context,
        Value $src,
        Value $srcStart,
        Value $count,
        Value $temp,
        Value $destIdxSlot
    ): void {
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $srcIdxSlot = $context->builder->alloca($sizeT, 1, 'array_splice_src');
        $takenSlot = $context->builder->alloca($sizeT, 1, 'array_splice_taken');
        $context->builder->store($srcStart, $srcIdxSlot);
        $context->builder->store($zero, $takenSlot);

        $head = BasicBlockHelper::append($context, 'array_splice_copy_head');
        $body = BasicBlockHelper::append($context, 'array_splice_copy_body');
        $done = BasicBlockHelper::append($context, 'array_splice_copy_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $taken = $context->builder->load($takenSlot);
        $atLimit = $context->builder->icmp(Builder::INT_SGE, $taken, $count);
        $context->builder->branchIf($atLimit, $done, $body);

        $context->builder->positionAtEnd($body);
        $srcIdx = $context->builder->load($srcIdxSlot);
        $destIdx = $context->builder->load($destIdxSlot);
        self::copyPackedListEntry($context, $src, $srcIdx, $temp, $destIdx);
        $context->builder->store($context->builder->addNoSignedWrap($destIdx, $one), $destIdxSlot);
        $context->builder->store($context->builder->addNoSignedWrap($srcIdx, $one), $srcIdxSlot);
        $context->builder->store($context->builder->addNoSignedWrap($taken, $one), $takenSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function copyPackedHashtableToTemp(
        Context $context,
        Value $replHt,
        Value $temp,
        Value $destIdxSlot
    ): void {
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $replEnd = $context->builder->load(
            $context->builder->structGep($replHt, $map['nextFreeElement'])
        );
        $srcIdxSlot = $context->builder->alloca($sizeT, 1, 'array_splice_repl_src');
        $context->builder->store($zero, $srcIdxSlot);

        $head = BasicBlockHelper::append($context, 'array_splice_repl_head');
        $body = BasicBlockHelper::append($context, 'array_splice_repl_body');
        $done = BasicBlockHelper::append($context, 'array_splice_repl_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $srcIdx = $context->builder->load($srcIdxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $srcIdx, $replEnd);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $destIdx = $context->builder->load($destIdxSlot);
        self::copyPackedListEntry($context, $replHt, $srcIdx, $temp, $destIdx);
        $context->builder->store($context->builder->addNoSignedWrap($destIdx, $one), $destIdxSlot);
        $context->builder->store($context->builder->addNoSignedWrap($srcIdx, $one), $srcIdxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function copyTempPackedIntoHashtable(
        Context $context,
        Value $temp,
        Value $dest,
        Value $newNum
    ): void {
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $context->builder->store($newNum, $context->builder->structGep($dest, $map['numElements']));
        $context->builder->store($newNum, $context->builder->structGep($dest, $map['nextFreeElement']));

        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_splice_write_idx');
        $context->builder->store($zero, $idxSlot);
        $head = BasicBlockHelper::append($context, 'array_splice_write_head');
        $body = BasicBlockHelper::append($context, 'array_splice_write_body');
        $done = BasicBlockHelper::append($context, 'array_splice_write_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $newNum);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        self::copyPackedListEntry($context, $temp, $idx, $dest, $idx);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    /**
     * array_walk() in-place with compile-time string builtin callbacks (issue #1209).
     */
    public static function walkInPlace(Context $context, Variable $array, Variable $callback): Value
    {
        if (!ArrayMapCallbackPolicy::isJitLowerable($callback)) {
            throw new \LogicException(ArrayMapCallbackPolicy::jitRejectionMessage());
        }
        if (self::isNativeArray($array->type)) {
            throw new \LogicException(
                'array_walk() cannot compile fixed-size literal arrays in JIT/AOT yet; assign to a variable first'
            );
        }
        $handler = self::resolveMapCallback($callback);
        $resultType = self::mapCallbackResultType($handler);
        $ht = self::loadHashTable($context, $array);
        self::walkInPlaceHashTable($context, $ht, $handler, $resultType);
        HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);

        return $context->getTypeFromString('int1')->constInt(1, false);
    }

    private static function walkInPlaceHashTable(
        Context $context,
        Value $src,
        Internal $handler,
        int $resultType
    ): void {
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $nextFree = $context->builder->load(
            $context->builder->structGep($src, $map['nextFreeElement'])
        );
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $nextFree, $zero);
        $doneBlock = BasicBlockHelper::append($context, 'array_walk_done');
        $workBlock = BasicBlockHelper::append($context, 'array_walk_work');
        $context->builder->branchIf($isEmpty, $doneBlock, $workBlock);

        $context->builder->positionAtEnd($workBlock);
        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_walk_idx');
        $context->builder->store($zero, $idxSlot);
        $head = BasicBlockHelper::append($context, 'array_walk_head');
        $check = BasicBlockHelper::append($context, 'array_walk_check');
        $walkBlock = BasicBlockHelper::append($context, 'array_walk_map');
        $skip = BasicBlockHelper::append($context, 'array_walk_skip');
        $advance = BasicBlockHelper::append($context, 'array_walk_advance');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $doneBlock, $check);

        $context->builder->positionAtEnd($check);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $src,
            $idx
        );
        $context->builder->branchIf($isSet, $walkBlock, $skip);

        $context->builder->positionAtEnd($walkBlock);
        $elem = HashTableHelper::readIndexedToValueBox($context, $src, $idx);
        $mapped = $handler->call($context, $elem);
        self::storeMappedAtIndex(
            $context,
            $src,
            $idx,
            new Variable($context, $resultType, Variable::KIND_VALUE, $mapped),
            $resultType
        );
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($skip);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($doneBlock);
    }

    /**
     * array_multisort() for homogeneous packed string or integer arrays (issue #1212).
     *
     * @param list<Variable> $arrays
     */
    public static function multisortPacked(Context $context, array $arrays, bool $descending): void
    {
        if (\count($arrays) < 2) {
            throw new \LogicException(
                'array_multisort() requires at least two array arguments in this compiler build'
            );
        }
        $primary = $arrays[0];
        $primaryHt = self::isNativeArray($primary->type)
            ? self::nativeListToHashTable($context, $primary)
            : self::loadHashTable($context, $primary);
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $two = $sizeT->constInt(2, false);
        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $primaryHt
        );
        $tooSmall = $context->builder->icmp(Builder::INT_ULT, $num, $two);
        $done = BasicBlockHelper::append($context, 'array_multisort_done');
        $work = BasicBlockHelper::append($context, 'array_multisort_work');
        $context->builder->branchIf($tooSmall, $done, $work);

        $context->builder->positionAtEnd($work);
        $hts = [];
        foreach ($arrays as $array) {
            if (self::isNativeArray($array->type)) {
                throw new \LogicException(
                    'array_multisort() cannot compile fixed-size literal arrays in JIT/AOT yet; assign to variables first'
                );
            }
            $hts[] = self::loadHashTable($context, $array);
        }
        self::multisortCoupledBubble($context, $hts, $num, $descending);
        foreach ($arrays as $i => $array) {
            HashTableHelper::storeHashtableInArrayVariable($context, $array, $hts[$i]);
        }

        $context->builder->positionAtEnd($done);
    }

    /**
     * @param list<Value> $hts packed __hashtable__* (primary first)
     */
    private static function multisortCoupledBubble(
        Context $context,
        array $hts,
        Value $num,
        bool $descending
    ): void {
        $primary = $hts[0];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $firstEntry = self::listEntryAt($context, $primary, $zero);
        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($firstEntry, $valueMap['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING, false)
        );
        $sortStrings = BasicBlockHelper::append($context, 'array_multisort_coupled_str');
        $sortLongs = BasicBlockHelper::append($context, 'array_multisort_coupled_long');
        $sorted = BasicBlockHelper::append($context, 'array_multisort_coupled_done');
        $context->builder->branchIf($isString, $sortStrings, $sortLongs);

        $context->builder->positionAtEnd($sortStrings);
        self::multisortCoupledBubbleTyped($context, $hts, $num, Variable::TYPE_STRING, $descending);
        $context->builder->branch($sorted);

        $context->builder->positionAtEnd($sortLongs);
        self::multisortCoupledBubbleTyped($context, $hts, $num, Variable::TYPE_NATIVE_LONG, $descending);
        $context->builder->branch($sorted);

        $context->builder->positionAtEnd($sorted);
    }

    /**
     * @param list<Value> $hts
     */
    private static function multisortCoupledBubbleTyped(
        Context $context,
        array $hts,
        Value $num,
        int $elemType,
        bool $descending
    ): void {
        $primary = $hts[0];
        $sizeT = $context->getTypeFromString('size_t');
        $one = $sizeT->constInt(1, false);
        $zero = $sizeT->constInt(0, false);
        $outerSlot = $context->builder->alloca($sizeT, 1, 'array_multisort_outer');
        $context->builder->store($zero, $outerSlot);
        $outerHead = BasicBlockHelper::append($context, 'array_multisort_outer_head');
        $outerBody = BasicBlockHelper::append($context, 'array_multisort_outer_body');
        $outerDone = BasicBlockHelper::append($context, 'array_multisort_outer_done');
        $context->builder->branch($outerHead);

        $context->builder->positionAtEnd($outerHead);
        $outer = $context->builder->load($outerSlot);
        $outerEnd = $context->builder->sub($num, $one);
        $outerAtEnd = $context->builder->icmp(Builder::INT_SGE, $outer, $outerEnd);
        $context->builder->branchIf($outerAtEnd, $outerDone, $outerBody);

        $context->builder->positionAtEnd($outerBody);
        $innerSlot = $context->builder->alloca($sizeT, 1, 'array_multisort_inner');
        $context->builder->store($zero, $innerSlot);
        $limit = $context->builder->sub($context->builder->sub($num, $outer), $one);
        $innerHead = BasicBlockHelper::append($context, 'array_multisort_inner_head');
        $innerBody = BasicBlockHelper::append($context, 'array_multisort_inner_body');
        $innerDone = BasicBlockHelper::append($context, 'array_multisort_inner_done');
        $context->builder->branch($innerHead);

        $context->builder->positionAtEnd($innerHead);
        $inner = $context->builder->load($innerSlot);
        $innerAtEnd = $context->builder->icmp(Builder::INT_SGE, $inner, $limit);
        $context->builder->branchIf($innerAtEnd, $innerDone, $innerBody);

        $context->builder->positionAtEnd($innerBody);
        $nextInner = $context->builder->addNoSignedWrap($inner, $one);
        $cmp = self::comparePackedAtIndices($context, $primary, $inner, $nextInner, $elemType);
        $i32 = $context->getTypeFromString('int32');
        $zeroCmp = $i32->constInt(0, false);
        $needsSwap = $descending
            ? $context->builder->icmp(Builder::INT_SLT, $cmp, $zeroCmp)
            : $context->builder->icmp(Builder::INT_SGT, $cmp, $zeroCmp);
        $swapBlock = BasicBlockHelper::append($context, 'array_multisort_swap');
        $noSwap = BasicBlockHelper::append($context, 'array_multisort_no_swap');
        $afterSwap = BasicBlockHelper::append($context, 'array_multisort_after_swap');
        $context->builder->branchIf($needsSwap, $swapBlock, $noSwap);

        $context->builder->positionAtEnd($swapBlock);
        foreach ($hts as $ht) {
            self::swapPackedEntriesAt($context, $ht, $inner, $nextInner, $elemType);
        }
        $context->builder->branch($afterSwap);

        $context->builder->positionAtEnd($noSwap);
        $context->builder->branch($afterSwap);

        $context->builder->positionAtEnd($afterSwap);
        $context->builder->store($nextInner, $innerSlot);
        $context->builder->branch($innerHead);

        $context->builder->positionAtEnd($innerDone);
        $context->builder->store($context->builder->addNoSignedWrap($outer, $one), $outerSlot);
        $context->builder->branch($outerHead);

        $context->builder->positionAtEnd($outerDone);
    }

    private static function comparePackedAtIndices(
        Context $context,
        Value $ht,
        Value $idxA,
        Value $idxB,
        int $elemType
    ): Value {
        if (Variable::TYPE_STRING === $elemType) {
            $strA = $context->builder->call(
                $context->lookupFunction('__hashtable__readStringAt'),
                $ht,
                $idxA
            );
            $strB = $context->builder->call(
                $context->lookupFunction('__hashtable__readStringAt'),
                $ht,
                $idxB
            );
            $map = $context->structFieldMap['__string__'];
            $ptrA = $context->builder->load($context->builder->structGep($strA, $map['data']));
            $ptrB = $context->builder->load($context->builder->structGep($strB, $map['data']));

            return $context->builder->call(
                $context->lookupFunction('strcmp'),
                $ptrA,
                $ptrB
            );
        }
        $longA = $context->builder->call(
            $context->lookupFunction('__hashtable__readLongAt'),
            $ht,
            $idxA
        );
        $longB = $context->builder->call(
            $context->lookupFunction('__hashtable__readLongAt'),
            $ht,
            $idxB
        );
        $cmpLt = $context->builder->icmp(Builder::INT_SLT, $longA, $longB);
        $cmpGt = $context->builder->icmp(Builder::INT_SGT, $longA, $longB);
        $i32 = $context->getTypeFromString('int32');
        $neg = $i32->constInt(-1, false);
        $pos = $i32->constInt(1, false);
        $zero = $i32->constInt(0, false);

        return $context->builder->select(
            $cmpLt,
            $neg,
            $context->builder->select($cmpGt, $pos, $zero)
        );
    }

    private static function swapPackedEntriesAt(
        Context $context,
        Value $ht,
        Value $idxA,
        Value $idxB,
        int $elemType
    ): void {
        if (Variable::TYPE_STRING === $elemType) {
            $strA = $context->builder->call(
                $context->lookupFunction('__hashtable__readStringAt'),
                $ht,
                $idxA
            );
            $strB = $context->builder->call(
                $context->lookupFunction('__hashtable__readStringAt'),
                $ht,
                $idxB
            );
            $entryA = self::listEntryAt($context, $ht, $idxA);
            $entryB = self::listEntryAt($context, $ht, $idxB);
            $context->builder->call($context->lookupFunction('__value__writeString'), $entryA, $strB);
            $context->builder->call($context->lookupFunction('__value__writeString'), $entryB, $strA);

            return;
        }
        $longA = $context->builder->call(
            $context->lookupFunction('__hashtable__readLongAt'),
            $ht,
            $idxA
        );
        $longB = $context->builder->call(
            $context->lookupFunction('__hashtable__readLongAt'),
            $ht,
            $idxB
        );
        $entryA = self::listEntryAt($context, $ht, $idxA);
        $entryB = self::listEntryAt($context, $ht, $idxB);
        $context->builder->call($context->lookupFunction('__value__writeLong'), $entryA, $longB);
        $context->builder->call($context->lookupFunction('__value__writeLong'), $entryB, $longA);
    }

    private static function clonePackedHashTable(Context $context, Value $src): Value
    {
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $src
        );
        $dest = HashTableHelper::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__hashtable__grow'),
            $dest,
            $num
        );
        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_clone_idx');
        $context->builder->store($zero, $idxSlot);
        $head = BasicBlockHelper::append($context, 'array_clone_head');
        $body = BasicBlockHelper::append($context, 'array_clone_body');
        $done = BasicBlockHelper::append($context, 'array_clone_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $num);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        self::copyPackedListEntry($context, $src, $idx, $dest, $idx);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        $context->builder->store($num, $context->builder->structGep($dest, $map['numElements']));
        $context->builder->store($num, $context->builder->structGep($dest, $map['nextFreeElement']));

        return $dest;
    }

    /**
     * Split an array into consecutive chunks (array_chunk subset; matches VM HashTable::chunkCopy).
     *
     * @param Value|null $preserveKeys i1 when the third argument is present; null for default false
     */
    public static function buildChunkArray(
        Context $context,
        Variable $array,
        Value $size,
        ?Value $preserveKeys = null
    ): Value {
        if (null === $preserveKeys) {
            return self::buildChunkArrayFast($context, $array, $size);
        }

        $fastBlock = BasicBlockHelper::append($context, 'array_chunk_fast');
        $preserveBlock = BasicBlockHelper::append($context, 'array_chunk_preserve');
        $doneBlock = BasicBlockHelper::append($context, 'array_chunk_branch_done');
        $context->builder->branchIf($preserveKeys, $preserveBlock, $fastBlock);

        $context->builder->positionAtEnd($fastBlock);
        $fastResult = self::buildChunkArrayFast($context, $array, $size);
        $fastEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($preserveBlock);
        $preserveResult = self::buildChunkArrayPreserveKeys($context, $array, $size);
        $preserveEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($fastResult->typeOf());
        $phi->addIncoming($fastResult, $fastEnd);
        $phi->addIncoming($preserveResult, $preserveEnd);

        return $phi;
    }

    private static function buildChunkArrayFast(Context $context, Variable $array, Value $size): Value
    {
        if (self::isNativeArray($array->type)) {
            return self::buildChunkFromNativeArray($context, $array, $size);
        }

        return self::buildChunkFromHashTable(
            $context,
            self::loadHashTable($context, $array),
            $size
        );
    }

    private static function buildChunkArrayPreserveKeys(Context $context, Variable $array, Value $size): Value
    {
        if (self::isNativeArray($array->type)) {
            return self::buildChunkPreserveKeysFromNativeArray($context, $array, $size);
        }

        return self::buildChunkPreserveKeysFromHashTable(
            $context,
            self::loadHashTable($context, $array),
            $size
        );
    }

    private static function appendChunkHashtable(
        Context $context,
        Value $out,
        Value $chunk,
        Value $outIndex
    ): void {
        $chunkVar = new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            $chunk
        );
        HashTableHelper::setAtIndex($context, $out, $outIndex, $chunkVar);
    }

    private static function buildChunkFromNativeArray(
        Context $context,
        Variable $array,
        Value $size
    ): Value {
        $tag = 'acn'.(string) ++self::$copyListEntrySeq;
        $elemType = $array->type & ~Variable::IS_NATIVE_ARRAY;
        $sizeT = $context->getTypeFromString('size_t');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $count = $context->constantFromInteger($array->nextFreeElement, 'size_t');
        $chunkSize = $context->builder->truncOrBitCast($size, $sizeT);

        $out = HashTableHelper::alloc($context);
        $srcIdxSlot = $context->builder->alloca($sizeT, 1, 'array_chunk_native_src_'.$tag);
        $chunkCountSlot = $context->builder->alloca($sizeT, 1, 'array_chunk_native_count_'.$tag);
        $chunkHtSlot = $context->builder->alloca($htPtr, 1, 'array_chunk_native_chunk_'.$tag);
        $outIdxSlot = $context->builder->alloca($sizeT, 1, 'array_chunk_native_out_'.$tag);
        $context->builder->store($zero, $srcIdxSlot);
        $context->builder->store($zero, $chunkCountSlot);
        $context->builder->store($zero, $outIdxSlot);

        $head = BasicBlockHelper::append($context, 'array_chunk_native_head_'.$tag);
        $startChunk = BasicBlockHelper::append($context, 'array_chunk_native_start_'.$tag);
        $copyBlock = BasicBlockHelper::append($context, 'array_chunk_native_copy_'.$tag);
        $flushCheck = BasicBlockHelper::append($context, 'array_chunk_native_flush_chk_'.$tag);
        $flushBlock = BasicBlockHelper::append($context, 'array_chunk_native_flush_'.$tag);
        $advance = BasicBlockHelper::append($context, 'array_chunk_native_advance_'.$tag);
        $finalize = BasicBlockHelper::append($context, 'array_chunk_native_finalize_'.$tag);
        $finalizeFlush = BasicBlockHelper::append($context, 'array_chunk_native_finalize_flush_'.$tag);
        $doneBlock = BasicBlockHelper::append($context, 'array_chunk_native_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $srcIdx = $context->builder->load($srcIdxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $srcIdx, $count);
        $context->builder->branchIf($atEnd, $finalize, $startChunk);

        $context->builder->positionAtEnd($startChunk);
        $chunkCount = $context->builder->load($chunkCountSlot);
        $needsChunk = $context->builder->icmp(Builder::INT_EQ, $chunkCount, $zero);
        $newChunkBlock = BasicBlockHelper::append($context, 'array_chunk_native_new_'.$tag);
        $context->builder->branchIf($needsChunk, $newChunkBlock, $copyBlock);

        $context->builder->positionAtEnd($newChunkBlock);
        $newChunk = HashTableHelper::alloc($context);
        $context->builder->store($newChunk, $chunkHtSlot);
        $context->builder->branch($copyBlock);

        $context->builder->positionAtEnd($copyBlock);
        $srcIdx = $context->builder->load($srcIdxSlot);
        $chunkCount = $context->builder->load($chunkCountSlot);
        $chunkHt = $context->builder->load($chunkHtSlot);
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
        HashTableHelper::setAtIndex($context, $chunkHt, $chunkCount, $elem);
        $context->builder->store(
            $context->builder->addNoSignedWrap($chunkCount, $one),
            $chunkCountSlot
        );
        $context->builder->branch($flushCheck);

        $context->builder->positionAtEnd($flushCheck);
        $chunkCount = $context->builder->load($chunkCountSlot);
        $shouldFlush = $context->builder->icmp(Builder::INT_SGE, $chunkCount, $chunkSize);
        $context->builder->branchIf($shouldFlush, $flushBlock, $advance);

        $context->builder->positionAtEnd($flushBlock);
        $outIdx = $context->builder->load($outIdxSlot);
        self::appendChunkHashtable($context, $out, $context->builder->load($chunkHtSlot), $outIdx);
        $context->builder->store(
            $context->builder->addNoSignedWrap($outIdx, $one),
            $outIdxSlot
        );
        $context->builder->store($zero, $chunkCountSlot);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store(
            $context->builder->addNoSignedWrap($context->builder->load($srcIdxSlot), $one),
            $srcIdxSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($finalize);
        $chunkCount = $context->builder->load($chunkCountSlot);
        $hasPartial = $context->builder->icmp(Builder::INT_SGT, $chunkCount, $zero);
        $context->builder->branchIf($hasPartial, $finalizeFlush, $doneBlock);

        $context->builder->positionAtEnd($finalizeFlush);
        $outIdx = $context->builder->load($outIdxSlot);
        self::appendChunkHashtable($context, $out, $context->builder->load($chunkHtSlot), $outIdx);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $out;
    }

    private static function buildChunkFromHashTable(Context $context, Value $src, Value $size): Value
    {
        $tag = 'ac'.(string) ++self::$copyListEntrySeq;
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $chunkSize = $context->builder->truncOrBitCast($size, $sizeT);
        $nextFree = $context->builder->load(
            $context->builder->structGep($src, $map['nextFreeElement'])
        );

        $out = HashTableHelper::alloc($context);
        $srcIdxSlot = $context->builder->alloca($sizeT, 1, 'array_chunk_src_'.$tag);
        $chunkCountSlot = $context->builder->alloca($sizeT, 1, 'array_chunk_count_'.$tag);
        $chunkHtSlot = $context->builder->alloca($htPtr, 1, 'array_chunk_chunk_'.$tag);
        $outIdxSlot = $context->builder->alloca($sizeT, 1, 'array_chunk_out_'.$tag);
        $context->builder->store($zero, $srcIdxSlot);
        $context->builder->store($zero, $chunkCountSlot);
        $context->builder->store($zero, $outIdxSlot);

        $head = BasicBlockHelper::append($context, 'array_chunk_head_'.$tag);
        $check = BasicBlockHelper::append($context, 'array_chunk_check_'.$tag);
        $skipUnset = BasicBlockHelper::append($context, 'array_chunk_skip_'.$tag);
        $startChunk = BasicBlockHelper::append($context, 'array_chunk_start_'.$tag);
        $copyBlock = BasicBlockHelper::append($context, 'array_chunk_copy_'.$tag);
        $flushCheck = BasicBlockHelper::append($context, 'array_chunk_flush_chk_'.$tag);
        $flushBlock = BasicBlockHelper::append($context, 'array_chunk_flush_'.$tag);
        $advance = BasicBlockHelper::append($context, 'array_chunk_advance_'.$tag);
        $finalize = BasicBlockHelper::append($context, 'array_chunk_finalize_'.$tag);
        $finalizeFlush = BasicBlockHelper::append($context, 'array_chunk_finalize_flush_'.$tag);
        $doneBlock = BasicBlockHelper::append($context, 'array_chunk_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $srcIdx = $context->builder->load($srcIdxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $srcIdx, $nextFree);
        $context->builder->branchIf($atEnd, $finalize, $check);

        $context->builder->positionAtEnd($check);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $src,
            $srcIdx
        );
        $context->builder->branchIf($isSet, $startChunk, $skipUnset);

        $context->builder->positionAtEnd($skipUnset);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($startChunk);
        $chunkCount = $context->builder->load($chunkCountSlot);
        $needsChunk = $context->builder->icmp(Builder::INT_EQ, $chunkCount, $zero);
        $newChunkBlock = BasicBlockHelper::append($context, 'array_chunk_new_'.$tag);
        $context->builder->branchIf($needsChunk, $newChunkBlock, $copyBlock);

        $context->builder->positionAtEnd($newChunkBlock);
        $newChunk = HashTableHelper::alloc($context);
        $context->builder->store($newChunk, $chunkHtSlot);
        $context->builder->branch($copyBlock);

        $context->builder->positionAtEnd($copyBlock);
        $srcIdx = $context->builder->load($srcIdxSlot);
        $chunkCount = $context->builder->load($chunkCountSlot);
        $chunkHt = $context->builder->load($chunkHtSlot);
        self::copyPackedListEntry($context, $src, $srcIdx, $chunkHt, $chunkCount);
        $context->builder->store(
            $context->builder->addNoSignedWrap($chunkCount, $one),
            $chunkCountSlot
        );
        $context->builder->branch($flushCheck);

        $context->builder->positionAtEnd($flushCheck);
        $chunkCount = $context->builder->load($chunkCountSlot);
        $shouldFlush = $context->builder->icmp(Builder::INT_SGE, $chunkCount, $chunkSize);
        $context->builder->branchIf($shouldFlush, $flushBlock, $advance);

        $context->builder->positionAtEnd($flushBlock);
        $outIdx = $context->builder->load($outIdxSlot);
        self::appendChunkHashtable($context, $out, $context->builder->load($chunkHtSlot), $outIdx);
        $context->builder->store(
            $context->builder->addNoSignedWrap($outIdx, $one),
            $outIdxSlot
        );
        $context->builder->store($zero, $chunkCountSlot);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store(
            $context->builder->addNoSignedWrap($context->builder->load($srcIdxSlot), $one),
            $srcIdxSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($finalize);
        $chunkCount = $context->builder->load($chunkCountSlot);
        $hasPartial = $context->builder->icmp(Builder::INT_SGT, $chunkCount, $zero);
        $context->builder->branchIf($hasPartial, $finalizeFlush, $doneBlock);

        $context->builder->positionAtEnd($finalizeFlush);
        $outIdx = $context->builder->load($outIdxSlot);
        self::appendChunkHashtable($context, $out, $context->builder->load($chunkHtSlot), $outIdx);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $out;
    }

    private static function buildChunkPreserveKeysFromNativeArray(
        Context $context,
        Variable $array,
        Value $size
    ): Value {
        $tag = 'acpkn'.(string) ++self::$copyListEntrySeq;
        $elemType = $array->type & ~Variable::IS_NATIVE_ARRAY;
        $sizeT = $context->getTypeFromString('size_t');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $count = $context->constantFromInteger($array->nextFreeElement, 'size_t');
        $chunkSize = $context->builder->truncOrBitCast($size, $sizeT);

        $out = HashTableHelper::alloc($context);
        $srcIdxSlot = $context->builder->alloca($sizeT, 1, 'array_chunk_pk_native_src_'.$tag);
        $chunkCountSlot = $context->builder->alloca($sizeT, 1, 'array_chunk_pk_native_count_'.$tag);
        $chunkHtSlot = $context->builder->alloca($htPtr, 1, 'array_chunk_pk_native_chunk_'.$tag);
        $outIdxSlot = $context->builder->alloca($sizeT, 1, 'array_chunk_pk_native_out_'.$tag);
        $context->builder->store($zero, $srcIdxSlot);
        $context->builder->store($zero, $chunkCountSlot);
        $context->builder->store($zero, $outIdxSlot);

        $head = BasicBlockHelper::append($context, 'array_chunk_pk_native_head_'.$tag);
        $startChunk = BasicBlockHelper::append($context, 'array_chunk_pk_native_start_'.$tag);
        $copyBlock = BasicBlockHelper::append($context, 'array_chunk_pk_native_copy_'.$tag);
        $flushCheck = BasicBlockHelper::append($context, 'array_chunk_pk_native_flush_chk_'.$tag);
        $flushBlock = BasicBlockHelper::append($context, 'array_chunk_pk_native_flush_'.$tag);
        $advance = BasicBlockHelper::append($context, 'array_chunk_pk_native_advance_'.$tag);
        $finalize = BasicBlockHelper::append($context, 'array_chunk_pk_native_finalize_'.$tag);
        $finalizeFlush = BasicBlockHelper::append($context, 'array_chunk_pk_native_finalize_flush_'.$tag);
        $doneBlock = BasicBlockHelper::append($context, 'array_chunk_pk_native_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $srcIdx = $context->builder->load($srcIdxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $srcIdx, $count);
        $context->builder->branchIf($atEnd, $finalize, $startChunk);

        $context->builder->positionAtEnd($startChunk);
        $chunkCount = $context->builder->load($chunkCountSlot);
        $needsChunk = $context->builder->icmp(Builder::INT_EQ, $chunkCount, $zero);
        $newChunkBlock = BasicBlockHelper::append($context, 'array_chunk_pk_native_new_'.$tag);
        $context->builder->branchIf($needsChunk, $newChunkBlock, $copyBlock);

        $context->builder->positionAtEnd($newChunkBlock);
        $newChunk = HashTableHelper::alloc($context);
        $context->builder->store($newChunk, $chunkHtSlot);
        $context->builder->branch($copyBlock);

        $context->builder->positionAtEnd($copyBlock);
        $srcIdx = $context->builder->load($srcIdxSlot);
        $chunkCount = $context->builder->load($chunkCountSlot);
        $chunkHt = $context->builder->load($chunkHtSlot);
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
        HashTableHelper::setAtIndex($context, $chunkHt, $srcIdx, $elem);
        $context->builder->store(
            $context->builder->addNoSignedWrap($chunkCount, $one),
            $chunkCountSlot
        );
        $context->builder->branch($flushCheck);

        $context->builder->positionAtEnd($flushCheck);
        $chunkCount = $context->builder->load($chunkCountSlot);
        $shouldFlush = $context->builder->icmp(Builder::INT_SGE, $chunkCount, $chunkSize);
        $context->builder->branchIf($shouldFlush, $flushBlock, $advance);

        $context->builder->positionAtEnd($flushBlock);
        $outIdx = $context->builder->load($outIdxSlot);
        self::appendChunkHashtable($context, $out, $context->builder->load($chunkHtSlot), $outIdx);
        $context->builder->store(
            $context->builder->addNoSignedWrap($outIdx, $one),
            $outIdxSlot
        );
        $context->builder->store($zero, $chunkCountSlot);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store(
            $context->builder->addNoSignedWrap($context->builder->load($srcIdxSlot), $one),
            $srcIdxSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($finalize);
        $chunkCount = $context->builder->load($chunkCountSlot);
        $hasPartial = $context->builder->icmp(Builder::INT_SGT, $chunkCount, $zero);
        $context->builder->branchIf($hasPartial, $finalizeFlush, $doneBlock);

        $context->builder->positionAtEnd($finalizeFlush);
        $outIdx = $context->builder->load($outIdxSlot);
        self::appendChunkHashtable($context, $out, $context->builder->load($chunkHtSlot), $outIdx);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $out;
    }

    private static function buildChunkPreserveKeysFromHashTable(Context $context, Value $src, Value $size): Value
    {
        $tag = 'acpk'.(string) ++self::$copyListEntrySeq;
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $sizeT = $context->getTypeFromString('size_t');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $chunkSize = $context->builder->truncOrBitCast($size, $sizeT);
        $nextFree = $context->builder->load(
            $context->builder->structGep($src, $map['nextFreeElement'])
        );

        $out = HashTableHelper::alloc($context);
        $chunkCountSlot = $context->builder->alloca($sizeT, 1, 'array_chunk_pk_count_'.$tag);
        $chunkHtSlot = $context->builder->alloca($htPtr, 1, 'array_chunk_pk_chunk_'.$tag);
        $outIdxSlot = $context->builder->alloca($sizeT, 1, 'array_chunk_pk_out_'.$tag);
        $srcIdxSlot = $context->builder->alloca($sizeT, 1, 'array_chunk_pk_src_'.$tag);
        $walkSlot = $context->builder->alloca($nodePtrType, 1, 'array_chunk_pk_walk_'.$tag);
        $context->builder->store($zero, $chunkCountSlot);
        $context->builder->store($zero, $outIdxSlot);
        $context->builder->store($zero, $srcIdxSlot);

        $flushChunkBlock = BasicBlockHelper::append($context, 'array_chunk_pk_flush_'.$tag);
        $flushChunkContinue = BasicBlockHelper::append($context, 'array_chunk_pk_flush_cont_'.$tag);
        $finalizeBlock = BasicBlockHelper::append($context, 'array_chunk_pk_finalize_'.$tag);
        $finalizeFlushBlock = BasicBlockHelper::append($context, 'array_chunk_pk_finalize_flush_'.$tag);
        $doneBlock = BasicBlockHelper::append($context, 'array_chunk_pk_done_'.$tag);

        // Packed int keys (preserve original index).
        $packedHead = BasicBlockHelper::append($context, 'array_chunk_pk_packed_head_'.$tag);
        $packedCheck = BasicBlockHelper::append($context, 'array_chunk_pk_packed_check_'.$tag);
        $packedSkip = BasicBlockHelper::append($context, 'array_chunk_pk_packed_skip_'.$tag);
        $packedStart = BasicBlockHelper::append($context, 'array_chunk_pk_packed_start_'.$tag);
        $packedNew = BasicBlockHelper::append($context, 'array_chunk_pk_packed_new_'.$tag);
        $packedCopy = BasicBlockHelper::append($context, 'array_chunk_pk_packed_copy_'.$tag);
        $packedFlushCheck = BasicBlockHelper::append($context, 'array_chunk_pk_packed_flush_chk_'.$tag);
        $packedAdvance = BasicBlockHelper::append($context, 'array_chunk_pk_packed_advance_'.$tag);
        $packedDone = BasicBlockHelper::append($context, 'array_chunk_pk_packed_done_'.$tag);
        $context->builder->branch($packedHead);

        $context->builder->positionAtEnd($packedHead);
        $srcIdx = $context->builder->load($srcIdxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $srcIdx, $nextFree);
        $context->builder->branchIf($atEnd, $packedDone, $packedCheck);

        $context->builder->positionAtEnd($packedCheck);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $src,
            $srcIdx
        );
        $context->builder->branchIf($isSet, $packedStart, $packedSkip);

        $context->builder->positionAtEnd($packedSkip);
        $context->builder->branch($packedAdvance);

        $context->builder->positionAtEnd($packedStart);
        $chunkCount = $context->builder->load($chunkCountSlot);
        $needsChunk = $context->builder->icmp(Builder::INT_EQ, $chunkCount, $zero);
        $context->builder->branchIf($needsChunk, $packedNew, $packedCopy);

        $context->builder->positionAtEnd($packedNew);
        $newChunk = HashTableHelper::alloc($context);
        $context->builder->store($newChunk, $chunkHtSlot);
        $context->builder->branch($packedCopy);

        $context->builder->positionAtEnd($packedCopy);
        $srcIdx = $context->builder->load($srcIdxSlot);
        $chunkHt = $context->builder->load($chunkHtSlot);
        self::storeValueEntryAtIndex(
            $context,
            $chunkHt,
            $srcIdx,
            self::listEntryAt($context, $src, $srcIdx)
        );
        $context->builder->store(
            $context->builder->addNoSignedWrap($context->builder->load($chunkCountSlot), $one),
            $chunkCountSlot
        );
        $context->builder->branch($packedFlushCheck);

        $context->builder->positionAtEnd($packedFlushCheck);
        $chunkCount = $context->builder->load($chunkCountSlot);
        $shouldFlush = $context->builder->icmp(Builder::INT_SGE, $chunkCount, $chunkSize);
        $context->builder->branchIf($shouldFlush, $flushChunkBlock, $packedAdvance);

        $context->builder->positionAtEnd($flushChunkBlock);
        $outIdx = $context->builder->load($outIdxSlot);
        self::appendChunkHashtable($context, $out, $context->builder->load($chunkHtSlot), $outIdx);
        $context->builder->store(
            $context->builder->addNoSignedWrap($outIdx, $one),
            $outIdxSlot
        );
        $context->builder->store($zero, $chunkCountSlot);
        $context->builder->branch($flushChunkContinue);

        $context->builder->positionAtEnd($flushChunkContinue);
        $context->builder->branch($packedAdvance);

        $context->builder->positionAtEnd($packedAdvance);
        $context->builder->store(
            $context->builder->addNoSignedWrap($context->builder->load($srcIdxSlot), $one),
            $srcIdxSlot
        );
        $context->builder->branch($packedHead);

        // String keys (preserve original key).
        $strInit = BasicBlockHelper::append($context, 'array_chunk_pk_str_init_'.$tag);
        $strHead = BasicBlockHelper::append($context, 'array_chunk_pk_str_head_'.$tag);
        $strBody = BasicBlockHelper::append($context, 'array_chunk_pk_str_body_'.$tag);
        $strStart = BasicBlockHelper::append($context, 'array_chunk_pk_str_start_'.$tag);
        $strNew = BasicBlockHelper::append($context, 'array_chunk_pk_str_new_'.$tag);
        $strCopy = BasicBlockHelper::append($context, 'array_chunk_pk_str_copy_'.$tag);
        $strFlushCheck = BasicBlockHelper::append($context, 'array_chunk_pk_str_flush_chk_'.$tag);
        $strNext = BasicBlockHelper::append($context, 'array_chunk_pk_str_next_'.$tag);
        $context->builder->positionAtEnd($packedDone);
        $context->builder->branch($strInit);

        $context->builder->positionAtEnd($strInit);
        $head = $context->builder->load($context->builder->structGep($src, $map['strKeys']));
        $context->builder->store($head, $walkSlot);
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strHead);
        $node = $context->builder->load($walkSlot);
        $nodeNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($nodeNull, $finalizeBlock, $strBody);

        $context->builder->positionAtEnd($strBody);
        $context->builder->branch($strStart);

        $context->builder->positionAtEnd($strStart);
        $chunkCount = $context->builder->load($chunkCountSlot);
        $needsChunk = $context->builder->icmp(Builder::INT_EQ, $chunkCount, $zero);
        $context->builder->branchIf($needsChunk, $strNew, $strCopy);

        $context->builder->positionAtEnd($strNew);
        $newChunk = HashTableHelper::alloc($context);
        $context->builder->store($newChunk, $chunkHtSlot);
        $context->builder->branch($strCopy);

        $context->builder->positionAtEnd($strCopy);
        $node = $context->builder->load($walkSlot);
        $valEntry = $context->builder->structGep($node, $nodeMap['value']);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        self::storeValueEntryAtStringKey(
            $context,
            $context->builder->load($chunkHtSlot),
            $keyStr,
            $valEntry
        );
        $context->builder->store(
            $context->builder->addNoSignedWrap($context->builder->load($chunkCountSlot), $one),
            $chunkCountSlot
        );
        $context->builder->branch($strFlushCheck);

        $context->builder->positionAtEnd($strFlushCheck);
        $chunkCount = $context->builder->load($chunkCountSlot);
        $shouldFlush = $context->builder->icmp(Builder::INT_SGE, $chunkCount, $chunkSize);
        $strFlushBlock = BasicBlockHelper::append($context, 'array_chunk_pk_str_flush_'.$tag);
        $context->builder->branchIf($shouldFlush, $strFlushBlock, $strNext);

        $context->builder->positionAtEnd($strFlushBlock);
        $outIdx = $context->builder->load($outIdxSlot);
        self::appendChunkHashtable($context, $out, $context->builder->load($chunkHtSlot), $outIdx);
        $context->builder->store(
            $context->builder->addNoSignedWrap($outIdx, $one),
            $outIdxSlot
        );
        $context->builder->store($zero, $chunkCountSlot);
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($strNext);
        $node = $context->builder->load($walkSlot);
        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($nextNode, $walkSlot);
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($finalizeBlock);
        $chunkCount = $context->builder->load($chunkCountSlot);
        $hasPartial = $context->builder->icmp(Builder::INT_SGT, $chunkCount, $zero);
        $context->builder->branchIf($hasPartial, $finalizeFlushBlock, $doneBlock);

        $context->builder->positionAtEnd($finalizeFlushBlock);
        $outIdx = $context->builder->load($outIdxSlot);
        self::appendChunkHashtable($context, $out, $context->builder->load($chunkHtSlot), $outIdx);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $out;
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
        self::copyPackedListEntry($context, $src, $srcIdx, $dest, $destIdx);
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
        self::copyPackedListEntry($context, $src, $srcIdx, $dest, $destIdx);
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

    private static function buildColumnFromHashTable(
        Context $context,
        Value $src,
        Value $columnKeyStr
    ): Value {
        $tag = (string) ++self::$copyListEntrySeq;
        $map = $context->structFieldMap['__hashtable__'];
        $valueMap = $context->structFieldMap['__value__'];
        $sizeT = $context->getTypeFromString('size_t');
        $nextFree = $context->builder->load(
            $context->builder->structGep($src, $map['nextFreeElement'])
        );
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $nextFree, $zero);
        $emptyBlock = BasicBlockHelper::append($context, 'array_column_empty_'.$tag);
        $workBlock = BasicBlockHelper::append($context, 'array_column_work_'.$tag);
        $doneBlock = BasicBlockHelper::append($context, 'array_column_done_'.$tag);
        $context->builder->branchIf($isEmpty, $emptyBlock, $workBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptyHt = HashTableHelper::alloc($context);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($workBlock);
        $dest = HashTableHelper::alloc($context);
        $srcIdxSlot = $context->builder->alloca($sizeT, 1, 'array_column_src');
        $destIdxSlot = $context->builder->alloca($sizeT, 1, 'array_column_dest');
        $context->builder->store($zero, $srcIdxSlot);
        $context->builder->store($zero, $destIdxSlot);

        $head = BasicBlockHelper::append($context, 'array_column_head_'.$tag);
        $check = BasicBlockHelper::append($context, 'array_column_check_'.$tag);
        $copyBlock = BasicBlockHelper::append($context, 'array_column_copy_'.$tag);
        $skip = BasicBlockHelper::append($context, 'array_column_skip_'.$tag);
        $rowHtBlock = BasicBlockHelper::append($context, 'array_column_row_ht_'.$tag);
        $rowNullBlock = BasicBlockHelper::append($context, 'array_column_row_null_'.$tag);
        $rowDone = BasicBlockHelper::append($context, 'array_column_row_done_'.$tag);
        $advance = BasicBlockHelper::append($context, 'array_column_advance_'.$tag);
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
        $rowEntry = self::listEntryAt($context, $src, $srcIdx);
        $typeByte = $context->builder->load(
            $context->builder->structGep($rowEntry, $valueMap['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $isHt = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_HASHTABLE, false)
        );
        $context->builder->branchIf($isHt, $rowHtBlock, $rowNullBlock);

        $context->builder->positionAtEnd($rowHtBlock);
        $rowHt = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $rowEntry
        );
        $cell = HashTableHelper::readStringKeyToValueBox($context, $rowHt, $columnKeyStr);
        HashTableHelper::setAtIndex($context, $dest, $destIdx, $cell);
        $context->builder->branch($rowDone);

        $context->builder->positionAtEnd($rowNullBlock);
        self::appendNullAtIndex($context, $dest, $destIdx);
        $context->builder->branch($rowDone);

        $context->builder->positionAtEnd($rowDone);
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

    private static function buildColumnWithIndexFromHashTable(
        Context $context,
        Value $src,
        Value $columnKeyStr,
        Value $indexKeyStr
    ): Value {
        $tag = (string) ++self::$copyListEntrySeq;
        $map = $context->structFieldMap['__hashtable__'];
        $valueMap = $context->structFieldMap['__value__'];
        $sizeT = $context->getTypeFromString('size_t');
        $nextFree = $context->builder->load(
            $context->builder->structGep($src, $map['nextFreeElement'])
        );
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $nextFree, $zero);
        $emptyBlock = BasicBlockHelper::append($context, 'array_column_idx_empty_'.$tag);
        $workBlock = BasicBlockHelper::append($context, 'array_column_idx_work_'.$tag);
        $doneBlock = BasicBlockHelper::append($context, 'array_column_idx_done_'.$tag);
        $context->builder->branchIf($isEmpty, $emptyBlock, $workBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptyHt = HashTableHelper::alloc($context);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($workBlock);
        $dest = HashTableHelper::alloc($context);
        $srcIdxSlot = $context->builder->alloca($sizeT, 1, 'array_column_idx_src');
        $context->builder->store($zero, $srcIdxSlot);

        $head = BasicBlockHelper::append($context, 'array_column_idx_head_'.$tag);
        $check = BasicBlockHelper::append($context, 'array_column_idx_check_'.$tag);
        $copyBlock = BasicBlockHelper::append($context, 'array_column_idx_copy_'.$tag);
        $skip = BasicBlockHelper::append($context, 'array_column_idx_skip_'.$tag);
        $rowHtBlock = BasicBlockHelper::append($context, 'array_column_idx_row_ht_'.$tag);
        $rowSkip = BasicBlockHelper::append($context, 'array_column_idx_row_skip_'.$tag);
        $indexCheck = BasicBlockHelper::append($context, 'array_column_idx_index_check_'.$tag);
        $columnCheck = BasicBlockHelper::append($context, 'array_column_idx_column_check_'.$tag);
        $storeBlock = BasicBlockHelper::append($context, 'array_column_idx_store_'.$tag);
        $advance = BasicBlockHelper::append($context, 'array_column_idx_advance_'.$tag);
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
        $rowEntry = self::listEntryAt($context, $src, $srcIdx);
        $typeByte = $context->builder->load(
            $context->builder->structGep($rowEntry, $valueMap['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $isHt = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_HASHTABLE, false)
        );
        $context->builder->branchIf($isHt, $rowHtBlock, $rowSkip);

        $context->builder->positionAtEnd($rowHtBlock);
        $rowHt = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $rowEntry
        );
        $indexIsSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
            $rowHt,
            $indexKeyStr
        );
        $context->builder->branchIf($indexIsSet, $indexCheck, $rowSkip);

        $context->builder->positionAtEnd($indexCheck);
        $columnIsSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
            $rowHt,
            $columnKeyStr
        );
        $context->builder->branchIf($columnIsSet, $columnCheck, $rowSkip);

        $context->builder->positionAtEnd($columnCheck);
        $indexEntry = $context->builder->call(
            $context->lookupFunction('__hashtable__readStringKeyValue'),
            $rowHt,
            $indexKeyStr
        );
        $columnEntry = $context->builder->call(
            $context->lookupFunction('__hashtable__readStringKeyValue'),
            $rowHt,
            $columnKeyStr
        );
        $context->builder->branch($storeBlock);

        $context->builder->positionAtEnd($storeBlock);
        self::storeCombinedEntry($context, $dest, $indexEntry, $columnEntry);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($rowSkip);
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

    private static function appendNullAtIndex(Context $context, Value $dest, Value $destIdx): void
    {
        $slot = JitValueBox::alloc($context);
        $destPtr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            $destPtr
        );
        $nullVar = new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $slot
        );
        HashTableHelper::setAtIndex($context, $dest, $destIdx, $nullVar);
    }

    private static function buildFilterFromNativeArray(Context $context, Variable $array): Value
    {
        $elemType = $array->type & ~Variable::IS_NATIVE_ARRAY;
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $count = $context->constantFromInteger($array->nextFreeElement, 'size_t');
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $count, $zero);
        $emptyBlock = BasicBlockHelper::append($context, 'array_filter_native_empty');
        $workBlock = BasicBlockHelper::append($context, 'array_filter_native_work');
        $doneBlock = BasicBlockHelper::append($context, 'array_filter_native_done');
        $context->builder->branchIf($isEmpty, $emptyBlock, $workBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptyHt = HashTableHelper::alloc($context);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($workBlock);
        $dest = HashTableHelper::alloc($context);
        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_filter_native_idx');
        $destIdxSlot = $context->builder->alloca($sizeT, 1, 'array_filter_native_dest');
        $context->builder->store($zero, $idxSlot);
        $context->builder->store($zero, $destIdxSlot);
        $head = BasicBlockHelper::append($context, 'array_filter_native_head');
        $body = BasicBlockHelper::append($context, 'array_filter_native_body');
        $copyBlock = BasicBlockHelper::append($context, 'array_filter_native_copy');
        $skipBlock = BasicBlockHelper::append($context, 'array_filter_native_skip');
        $advance = BasicBlockHelper::append($context, 'array_filter_native_advance');
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
        if (Variable::TYPE_STRING === $elem->type) {
            $truthy = boolval::stringTruthy($context, $context->helper->loadValue($elem));
        } else {
            $truthy = (new boolval())->call($context, $elem);
        }
        $context->builder->branchIf($truthy, $copyBlock, $skipBlock);

        $context->builder->positionAtEnd($copyBlock);
        $destIdx = $context->builder->load($destIdxSlot);
        HashTableHelper::setAtIndex($context, $dest, $destIdx, $elem);
        $context->builder->store(
            $context->builder->addNoSignedWrap($destIdx, $one),
            $destIdxSlot
        );
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($skipBlock);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($emptyHt->typeOf());
        $phi->addIncoming($emptyHt, $emptyBlock);
        $phi->addIncoming($dest, $head);

        return $phi;
    }

    private static function filterCopyListEntry(
        Context $context,
        Value $src,
        Value $srcIndex,
        Value $dest,
        Value $destIndex
    ): void {
        self::copyPackedListEntry($context, $src, $srcIndex, $dest, $destIndex);
    }

    private static function listEntryTruthy(Context $context, Value $entry): Value
    {
        $tag = 'ft'.(string) ++self::$copyListEntrySeq;
        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($entry, $valueMap['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $false = $context->constantFromBool(false);

        $stringBlock = BasicBlockHelper::append($context, 'array_filter_str_'.$tag);
        $longBlock = BasicBlockHelper::append($context, 'array_filter_long_'.$tag);
        $doubleBlock = BasicBlockHelper::append($context, 'array_filter_double_'.$tag);
        $boolBlock = BasicBlockHelper::append($context, 'array_filter_bool_'.$tag);
        $nullBlock = BasicBlockHelper::append($context, 'array_filter_null_'.$tag);
        $doneBlock = BasicBlockHelper::append($context, 'array_filter_truthy_done_'.$tag);

        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING, false)
        );
        $afterString = BasicBlockHelper::append($context, 'array_filter_after_str_'.$tag);
        $context->builder->branchIf($isString, $stringBlock, $afterString);

        $context->builder->positionAtEnd($stringBlock);
        $str = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $entry
        );
        $strTruthy = boolval::stringTruthy($context, $str);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterString);
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );
        $afterLong = BasicBlockHelper::append($context, 'array_filter_after_long_'.$tag);
        $context->builder->branchIf($isLong, $longBlock, $afterLong);

        $context->builder->positionAtEnd($longBlock);
        $longVal = $context->builder->call($context->lookupFunction('__value__readLong'), $entry);
        $longTruthy = $context->builder->icmp(Builder::INT_NE, $longVal, $i64->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterLong);
        $isDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)
        );
        $afterDouble = BasicBlockHelper::append($context, 'array_filter_after_double_'.$tag);
        $context->builder->branchIf($isDouble, $doubleBlock, $afterDouble);

        $context->builder->positionAtEnd($doubleBlock);
        $doubleVal = $context->builder->call($context->lookupFunction('__value__readDouble'), $entry);
        $doubleTruthy = $context->builder->fcmp(
            Builder::REAL_ONE,
            $doubleVal,
            $doubleVal->typeOf()->constReal(0.0)
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterDouble);
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false)
        );
        $afterBool = BasicBlockHelper::append($context, 'array_filter_after_bool_'.$tag);
        $context->builder->branchIf($isBool, $boolBlock, $afterBool);

        $context->builder->positionAtEnd($boolBlock);
        $boolVal = $context->builder->truncOrBitCast(
            $context->builder->call($context->lookupFunction('__value__readLong'), $entry),
            $context->getTypeFromString('int1')
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterBool);
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NULL, false)
        );
        $context->builder->branchIf($isNull, $nullBlock, $doneBlock);

        $context->builder->positionAtEnd($nullBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($false->typeOf());
        $phi->addIncoming($strTruthy, $stringBlock);
        $phi->addIncoming($longTruthy, $longBlock);
        $phi->addIncoming($doubleTruthy, $doubleBlock);
        $phi->addIncoming($boolVal, $boolBlock);
        $phi->addIncoming($false, $nullBlock);
        $phi->addIncoming($false, $afterBool);

        return $phi;
    }

    private static function buildFilterFromHashTable(Context $context, Value $src): Value
    {
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $nextFree = $context->builder->load(
            $context->builder->structGep($src, $map['nextFreeElement'])
        );
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $nextFree, $zero);
        $emptyBlock = BasicBlockHelper::append($context, 'array_filter_empty');
        $workBlock = BasicBlockHelper::append($context, 'array_filter_work');
        $doneBlock = BasicBlockHelper::append($context, 'array_filter_done');
        $context->builder->branchIf($isEmpty, $emptyBlock, $workBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptyHt = HashTableHelper::alloc($context);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($workBlock);
        $dest = HashTableHelper::alloc($context);
        $srcIdxSlot = $context->builder->alloca($sizeT, 1, 'array_filter_src');
        $context->builder->store($zero, $srcIdxSlot);
        $head = BasicBlockHelper::append($context, 'array_filter_head');
        $check = BasicBlockHelper::append($context, 'array_filter_check');
        $truthyBlock = BasicBlockHelper::append($context, 'array_filter_truthy');
        $copyBlock = BasicBlockHelper::append($context, 'array_filter_copy');
        $skipUnset = BasicBlockHelper::append($context, 'array_filter_skip_unset');
        $advance = BasicBlockHelper::append($context, 'array_filter_advance');
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
        $context->builder->branchIf($isSet, $truthyBlock, $skipUnset);

        $context->builder->positionAtEnd($truthyBlock);
        $entry = self::listEntryAt($context, $src, $srcIdx);
        $truthy = self::listEntryTruthy($context, $entry);
        $context->builder->branchIf($truthy, $copyBlock, $skipUnset);

        $context->builder->positionAtEnd($copyBlock);
        self::filterCopyListEntry($context, $src, $srcIdx, $dest, $srcIdx);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($skipUnset);
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
            $i8->constInt(Variable::TYPE_STRING & 0xff, false)
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

    public static function buildKeysArrayFromVariable(Context $context, Variable $array): Value
    {
        $ht = self::isNativeArray($array->type)
            ? self::nativeListToHashTable($context, $array)
            : self::loadHashTable($context, $array);

        return self::buildKeysArray($context, $ht);
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
        $rangeEndBlock = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($emptyHt->typeOf());
        $phi->addIncoming($emptyHt, $emptyBlock);
        $phi->addIncoming($keysHt, $rangeEndBlock);

        return $phi;
    }

    public static function merge(Context $context, Variable ...$arrays): Value
    {
        if (\count($arrays) < 2) {
            throw new \LogicException('array_merge() requires at least two arguments');
        }

        $allNative = true;
        foreach ($arrays as $array) {
            if (!self::isNativeArray($array->type)) {
                $allNative = false;
                break;
            }
        }
        if ($allNative) {
            $result = HashTableHelper::alloc($context);
            foreach ($arrays as $array) {
                self::copyInto($context, $result, self::nativeListToHashTable($context, $array));
            }

            return $result;
        }

        $i1 = $context->getTypeFromString('int1');
        $allReindexableSlot = $context->builder->alloca($i1, 1, 'array_merge_all_reindexable');
        $context->builder->store($context->constantFromBool(true), $allReindexableSlot);

        $hts = [];
        foreach ($arrays as $array) {
            $ht = self::loadHashTable($context, $array);
            $hts[] = $ht;
            $isReindexable = \PHPCompiler\ext\standard\JitArrayIsList::hashTableIsReindexableList($context, $ht);
            $context->builder->store(
                $context->builder->and($context->builder->load($allReindexableSlot), $isReindexable),
                $allReindexableSlot
            );
        }

        $listBb = BasicBlockHelper::append($context, 'array_merge_list');
        $assocBb = BasicBlockHelper::append($context, 'array_merge_assoc');
        $mergeDone = BasicBlockHelper::append($context, 'array_merge_done');
        $context->builder->branchIf($context->builder->load($allReindexableSlot), $listBb, $assocBb);

        $context->builder->positionAtEnd($listBb);
        $listResult = HashTableHelper::alloc($context);
        foreach ($hts as $ht) {
            self::copyReindexableInto($context, $listResult, $ht);
        }
        $listEndBb = $context->builder->getInsertBlock();
        $context->builder->branch($mergeDone);

        $context->builder->positionAtEnd($assocBb);
        $assocResult = HashTableHelper::alloc($context);
        self::overlayHashTable($context, $assocResult, $hts[0]);
        $otherCount = \count($hts);
        for ($i = 1; $i < $otherCount; ++$i) {
            self::mergeStringKeysInto($context, $assocResult, $hts[$i]);
        }
        $assocEndBb = $context->builder->getInsertBlock();
        $context->builder->branch($mergeDone);

        $context->builder->positionAtEnd($mergeDone);
        $phi = $context->builder->phi($listResult->typeOf());
        $phi->addIncoming($listResult, $listEndBb);
        $phi->addIncoming($assocResult, $assocEndBb);

        return $phi;
    }

    /**
     * array_merge_recursive() — deep merge with scalar→array promotion (#3297).
     */
    public static function mergeRecursive(Context $context, Variable ...$arrays): Value
    {
        if (\count($arrays) < 2) {
            throw new \LogicException('array_merge_recursive() requires at least two arguments');
        }

        $allReindexable = true;
        $hts = [];
        foreach ($arrays as $array) {
            $ht = self::loadHashTable($context, $array);
            $hts[] = $ht;
            if (!\PHPCompiler\ext\standard\JitArrayIsList::hashTableIsReindexableList($context, $ht)) {
                $allReindexable = false;
            }
        }
        if ($allReindexable) {
            return self::merge($context, ...$arrays);
        }

        $result = HashTableHelper::alloc($context);
        self::overlayHashTable($context, $result, $hts[0]);
        for ($i = 1, $n = \count($hts); $i < $n; ++$i) {
            \PHPCompiler\ext\standard\JitArrayMergeRecursive::overlay($context, $result, $hts[$i]);
        }

        return $result;
    }

    /**
     * Copy string-key entries from {@param $src} into {@param $dest}, overwriting duplicates (#2287).
     */
    private static function mergeStringKeysInto(Context $context, Value $dest, Value $src): void
    {
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');

        $strInit = BasicBlockHelper::append($context, 'array_merge_str_init');
        $strHead = BasicBlockHelper::append($context, 'array_merge_str_head');
        $context->builder->branch($strInit);

        $context->builder->positionAtEnd($strInit);
        $walkSlot = $context->builder->alloca($nodePtrType, 1, 'array_merge_str_walk');
        $head = $context->builder->load($context->builder->structGep($src, $map['strKeys']));
        $context->builder->store($head, $walkSlot);
        $strBody = BasicBlockHelper::append($context, 'array_merge_str_body');
        $strSet = BasicBlockHelper::append($context, 'array_merge_str_set');
        $strNext = BasicBlockHelper::append($context, 'array_merge_str_next');
        $strDone = BasicBlockHelper::append($context, 'array_merge_str_done');
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strHead);
        $node = $context->builder->load($walkSlot);
        $nodeNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($nodeNull, $strDone, $strBody);

        $context->builder->positionAtEnd($strBody);
        $valEntry = $context->builder->structGep($node, $nodeMap['value']);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $context->builder->branch($strSet);

        $context->builder->positionAtEnd($strSet);
        self::storeValueEntryAtStringKey($context, $dest, $keyStr, $valEntry);
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($strNext);
        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($nextNode, $walkSlot);
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strDone);
    }

    /**
     * array_combine() for packed list arrays (subset of PHP; returns __value__*).
     *
     * @return Value
     * (hashtable on success, boolean false when lengths differ)
     */
    public static function combine(Context $context, Variable $keys, Variable $values): Value
    {
        if (self::isNativeArray($keys->type) && self::isNativeArray($values->type)) {
            return self::combineNativeArrays($context, $keys, $values);
        }

        return self::combineHashTables(
            $context,
            self::loadHashTable($context, $keys),
            self::loadHashTable($context, $values)
        );
    }

    /**
     * array_fill_keys() — values of {@param $keys} become keys; uniform {@param $value} (returns __value__*).
     */
    public static function fillKeys(Context $context, Variable $keys, Variable $value): Value
    {
        $keysHt = self::isNativeArray($keys->type)
            ? HashTableHelper::materializeNativeArrayForCall($context, $keys)
            : self::loadHashTable($context, $keys);

        return self::fillKeysHashTable($context, $keysHt, $value);
    }

    /**
     * array_pad() — pad a packed list hashtable to abs({@param $length}) with {@param $value}.
     */
    public static function pad(Context $context, Variable $array, Value $length, Variable $value): Value
    {
        $src = self::isNativeArray($array->type)
            ? HashTableHelper::materializeNativeArrayForCall($context, $array)
            : self::loadHashTable($context, $array);

        return self::padHashTable($context, $src, $length, $value);
    }

    private static function padHashTable(Context $context, Value $src, Value $length, Variable $value): Value
    {
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $zero64 = $i64->constInt(0, false);
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $negLen = $context->builder->icmp(Builder::INT_SLT, $length, $zero64);
        $negBlock = BasicBlockHelper::append($context, 'array_pad_abs_neg');
        $posBlock = BasicBlockHelper::append($context, 'array_pad_abs_pos');
        $absDone = BasicBlockHelper::append($context, 'array_pad_abs_done');
        $context->builder->branchIf($negLen, $negBlock, $posBlock);
        $context->builder->positionAtEnd($negBlock);
        $negated = $context->builder->sub($zero64, $length);
        $context->builder->branch($absDone);
        $context->builder->positionAtEnd($posBlock);
        $context->builder->branch($absDone);
        $context->builder->positionAtEnd($absDone);
        $absPhi = $context->builder->phi($i64);
        $absPhi->addIncoming($negated, $negBlock);
        $absPhi->addIncoming($length, $posBlock);
        $target = $context->builder->truncOrBitCast($absPhi, $sizeT);

        $count = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $src
        );
        $needsPad = $context->builder->icmp(Builder::INT_SGT, $target, $count);
        $noPadBlock = BasicBlockHelper::append($context, 'array_pad_no_pad');
        $padBlock = BasicBlockHelper::append($context, 'array_pad_pad');
        $doneBlock = BasicBlockHelper::append($context, 'array_pad_done');
        $context->builder->branchIf($needsPad, $padBlock, $noPadBlock);

        $context->builder->positionAtEnd($noPadBlock);
        $copied = self::copyPackedListHashTable($context, $src, $zero);
        $noPadExit = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($padBlock);
        $padCount = $context->builder->sub($target, $count);
        $posLen = $context->builder->icmp(Builder::INT_SGT, $length, $zero64);
        $rightBlock = BasicBlockHelper::append($context, 'array_pad_right');
        $leftBlock = BasicBlockHelper::append($context, 'array_pad_left');
        $padDone = BasicBlockHelper::append($context, 'array_pad_pad_done');
        $context->builder->branchIf($posLen, $rightBlock, $leftBlock);

        $context->builder->positionAtEnd($rightBlock);
        $rightHt = self::copyPackedListHashTable($context, $src, $zero);
        $padIdxSlot = $context->builder->alloca($sizeT, 1, 'array_pad_right_idx');
        $context->builder->store($count, $padIdxSlot);
        $rightHead = BasicBlockHelper::append($context, 'array_pad_right_head');
        $rightBody = BasicBlockHelper::append($context, 'array_pad_right_body');
        $rightAdvance = BasicBlockHelper::append($context, 'array_pad_right_advance');
        $rightDone = BasicBlockHelper::append($context, 'array_pad_right_done');
        $context->builder->branch($rightHead);
        $context->builder->positionAtEnd($rightHead);
        $padIdx = $context->builder->load($padIdxSlot);
        $rightAtEnd = $context->builder->icmp(Builder::INT_SGE, $padIdx, $target);
        $context->builder->branchIf($rightAtEnd, $rightDone, $rightBody);
        $context->builder->positionAtEnd($rightBody);
        self::appendElement($context, $rightHt, $value);
        $context->builder->branch($rightAdvance);
        $context->builder->positionAtEnd($rightAdvance);
        $context->builder->store(
            $context->builder->addNoSignedWrap($padIdx, $one),
            $padIdxSlot
        );
        $context->builder->branch($rightHead);
        $context->builder->positionAtEnd($rightDone);
        $context->builder->branch($padDone);

        $context->builder->positionAtEnd($leftBlock);
        $leftHt = HashTableHelper::alloc($context);
        $leftIdxSlot = $context->builder->alloca($sizeT, 1, 'array_pad_left_pad_idx');
        $context->builder->store($zero, $leftIdxSlot);
        $leftPadHead = BasicBlockHelper::append($context, 'array_pad_left_pad_head');
        $leftPadBody = BasicBlockHelper::append($context, 'array_pad_left_pad_body');
        $leftPadAdvance = BasicBlockHelper::append($context, 'array_pad_left_pad_advance');
        $leftCopyBlock = BasicBlockHelper::append($context, 'array_pad_left_copy');
        $context->builder->branch($leftPadHead);
        $context->builder->positionAtEnd($leftPadHead);
        $leftPadIdx = $context->builder->load($leftIdxSlot);
        $leftPadAtEnd = $context->builder->icmp(Builder::INT_SGE, $leftPadIdx, $padCount);
        $context->builder->branchIf($leftPadAtEnd, $leftCopyBlock, $leftPadBody);
        $context->builder->positionAtEnd($leftPadBody);
        HashTableHelper::setAtIndex($context, $leftHt, $leftPadIdx, $value);
        $context->builder->branch($leftPadAdvance);
        $context->builder->positionAtEnd($leftPadAdvance);
        $context->builder->store(
            $context->builder->addNoSignedWrap($leftPadIdx, $one),
            $leftIdxSlot
        );
        $context->builder->branch($leftPadHead);
        $context->builder->positionAtEnd($leftCopyBlock);
        $leftPadded = self::copyPackedListHashTable($context, $src, $padCount, $leftHt);
        $leftPadExit = $context->builder->getInsertBlock();
        $context->builder->branch($padDone);

        $context->builder->positionAtEnd($padDone);
        $padPhi = $context->builder->phi($rightHt->typeOf());
        $padPhi->addIncoming($rightHt, $rightDone);
        $padPhi->addIncoming($leftPadded, $leftPadExit);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $resultPhi = $context->builder->phi($copied->typeOf());
        $resultPhi->addIncoming($copied, $noPadExit);
        $resultPhi->addIncoming($padPhi, $padDone);

        return $resultPhi;
    }

    /**
     * Copy packed list {@param $src} into a new hashtable starting at {@param $destOffset}.
     */
    private static function copyPackedListHashTable(
        Context $context,
        Value $src,
        Value $destOffset,
        ?Value $destIn = null
    ): Value {
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $nextFree = $context->builder->load(
            $context->builder->structGep($src, $map['nextFreeElement'])
        );
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $nextFree, $zero);
        $emptyBlock = BasicBlockHelper::append($context, 'array_pad_copy_empty');
        $workBlock = BasicBlockHelper::append($context, 'array_pad_copy_work');
        $doneBlock = BasicBlockHelper::append($context, 'array_pad_copy_done');
        $context->builder->branchIf($isEmpty, $emptyBlock, $workBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptyHt = null !== $destIn ? $destIn : HashTableHelper::alloc($context);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($workBlock);
        $dest = null !== $destIn ? $destIn : HashTableHelper::alloc($context);
        $srcIdxSlot = $context->builder->alloca($sizeT, 1, 'array_pad_copy_src');
        $context->builder->store($zero, $srcIdxSlot);
        $head = BasicBlockHelper::append($context, 'array_pad_copy_head');
        $body = BasicBlockHelper::append($context, 'array_pad_copy_body');
        $advance = BasicBlockHelper::append($context, 'array_pad_copy_advance');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $srcIdx = $context->builder->load($srcIdxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $srcIdx, $nextFree);
        $context->builder->branchIf($atEnd, $doneBlock, $body);

        $context->builder->positionAtEnd($body);
        $destIdx = $context->builder->addNoSignedWrap($destOffset, $srcIdx);
        self::copyPackedListEntry($context, $src, $srcIdx, $dest, $destIdx);
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

    private static function fillKeysHashTable(Context $context, Value $keysHt, Variable $value): Value
    {
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $keysNum = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $keysHt
        );
        $dest = HashTableHelper::alloc($context);
        $valEntry = self::valueEntryPtrForFill($context, $value);
        $resultSlot = JitValueBox::alloc($context);
        $resultPtr = JitValueBox::pointer($context, $resultSlot);
        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_fill_keys_idx');
        $context->builder->store($zero, $idxSlot);
        $head = BasicBlockHelper::append($context, 'array_fill_keys_head');
        $body = BasicBlockHelper::append($context, 'array_fill_keys_body');
        $advance = BasicBlockHelper::append($context, 'array_fill_keys_advance');
        $loopDone = BasicBlockHelper::append($context, 'array_fill_keys_loop_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $keysNum);
        $context->builder->branchIf($atEnd, $loopDone, $body);

        $context->builder->positionAtEnd($body);
        $keyEntry = self::listEntryAt($context, $keysHt, $idx);
        $valSlot = JitValueBox::alloc($context);
        JitValueBox::copyFromPointer($context, $valSlot, $valEntry);
        self::storeCombinedEntry(
            $context,
            $dest,
            $keyEntry,
            JitValueBox::pointer($context, $valSlot)
        );
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store(
            $context->builder->addNoSignedWrap($idx, $one),
            $idxSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $resultPtr,
            $dest
        );

        return $resultPtr;
    }

    private static function valueEntryPtrForFill(Context $context, Variable $value): Value
    {
        if (Variable::TYPE_VALUE === $value->type) {
            return JitValueBox::valuePtrFromVariable($context, $value);
        }
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        switch ($value->type) {
            case Variable::TYPE_NATIVE_LONG:
                JitValueBox::writeLong($context, $slot, $context->helper->loadValue($value));
                break;
            case Variable::TYPE_NATIVE_BOOL:
                JitValueBox::writeBool(
                    $context,
                    $slot,
                    $context->builder->truncOrBitCast($context->helper->loadValue($value), $context->getTypeFromString('int1'))
                );
                break;
            case Variable::TYPE_NATIVE_DOUBLE:
                $context->builder->call(
                    $context->lookupFunction('__value__writeDouble'),
                    $ptr,
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
                    'fill_keys_val'
                );
                $context->builder->call(
                    $context->lookupFunction('__value__writeString'),
                    $ptr,
                    $owned
                );
                break;
            default:
                throw new \LogicException(
                    'array_fill_keys() value type not supported for JIT: '
                    .Variable::getStringType($value->type)
                );
        }

        return $ptr;
    }

    private static function combineHashTables(Context $context, Value $keysHt, Value $valsHt): Value
    {
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $keysNum = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $keysHt
        );
        $valsNum = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $valsHt
        );
        $lengthMismatch = $context->builder->icmp(Builder::INT_NE, $keysNum, $valsNum);

        $failSlot = JitValueBox::alloc($context);
        $failPtr = JitValueBox::pointer($context, $failSlot);
        $okSlot = JitValueBox::alloc($context);
        $okPtr = JitValueBox::pointer($context, $okSlot);
        $failBlock = BasicBlockHelper::append($context, 'array_combine_fail');
        $workBlock = BasicBlockHelper::append($context, 'array_combine_work');
        $mergeBlock = BasicBlockHelper::append($context, 'array_combine_merge');
        $context->builder->branchIf($lengthMismatch, $failBlock, $workBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $failSlot, $i1->constInt(0, false));
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($workBlock);
        $dest = HashTableHelper::alloc($context);
        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_combine_idx');
        $context->builder->store($zero, $idxSlot);
        $head = BasicBlockHelper::append($context, 'array_combine_head');
        $body = BasicBlockHelper::append($context, 'array_combine_body');
        $advance = BasicBlockHelper::append($context, 'array_combine_advance');
        $loopDone = BasicBlockHelper::append($context, 'array_combine_loop_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $keysNum);
        $context->builder->branchIf($atEnd, $loopDone, $body);

        $context->builder->positionAtEnd($body);
        $keyEntry = self::listEntryAt($context, $keysHt, $idx);
        $valEntry = self::listEntryAt($context, $valsHt, $idx);
        self::storeCombinedEntry($context, $dest, $keyEntry, $valEntry);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store(
            $context->builder->addNoSignedWrap($idx, $one),
            $idxSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $okPtr,
            $dest
        );
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);
        $phi = $context->builder->phi($failPtr->typeOf());
        $phi->addIncoming($failPtr, $failBlock);
        $phi->addIncoming($okPtr, $loopDone);

        return $phi;
    }

    private static function combineNativeArrays(Context $context, Variable $keys, Variable $values): Value
    {
        if (!self::isNativeArray($keys->type) || !self::isNativeArray($values->type)) {
            throw new \LogicException(
                'array_combine() requires both arguments to be the same array kind in this compiler build'
            );
        }
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $keysCount = $context->constantFromInteger($keys->nextFreeElement, 'size_t');
        $valsCount = $context->constantFromInteger($values->nextFreeElement, 'size_t');
        $lengthMismatch = $context->builder->icmp(Builder::INT_NE, $keysCount, $valsCount);

        $failSlot = JitValueBox::alloc($context);
        $failPtr = JitValueBox::pointer($context, $failSlot);
        $okSlot = JitValueBox::alloc($context);
        $okPtr = JitValueBox::pointer($context, $okSlot);
        $failBlock = BasicBlockHelper::append($context, 'array_combine_native_fail');
        $workBlock = BasicBlockHelper::append($context, 'array_combine_native_work');
        $mergeBlock = BasicBlockHelper::append($context, 'array_combine_native_merge');
        $context->builder->branchIf($lengthMismatch, $failBlock, $workBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $failSlot, $i1->constInt(0, false));
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($workBlock);
        $dest = HashTableHelper::alloc($context);
        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_combine_native_idx');
        $context->builder->store($zero, $idxSlot);
        $head = BasicBlockHelper::append($context, 'array_combine_native_head');
        $body = BasicBlockHelper::append($context, 'array_combine_native_body');
        $advance = BasicBlockHelper::append($context, 'array_combine_native_advance');
        $loopDone = BasicBlockHelper::append($context, 'array_combine_native_loop_done');
        $context->builder->branch($head);

        $keyElemType = $keys->type & ~Variable::IS_NATIVE_ARRAY;
        $valElemType = $values->type & ~Variable::IS_NATIVE_ARRAY;

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $keysCount);
        $context->builder->branchIf($atEnd, $loopDone, $body);

        $context->builder->positionAtEnd($body);
        $keySlot = $context->builder->inBoundsGep($keys->value, $zero, $idx);
        $valSlot = $context->builder->inBoundsGep($values->value, $zero, $idx);
        if (Variable::TYPE_STRING === $keyElemType) {
            $keyVar = new Variable($context, $keyElemType, Variable::KIND_VARIABLE, $keySlot);
            $valVar = self::nativeElementVariable($context, $valElemType, $valSlot);
            HashTableHelper::setAtStringKey(
                $context,
                $dest,
                $context->helper->loadValue($keyVar),
                $valVar
            );
        } elseif (Variable::TYPE_NATIVE_LONG === $keyElemType) {
            $intKey = $context->builder->truncOrBitCast(
                $context->builder->load($keySlot),
                $sizeT
            );
            $valVar = self::nativeElementVariable($context, $valElemType, $valSlot);
            HashTableHelper::setAtIndex($context, $dest, $intKey, $valVar);
        } else {
            throw new \LogicException(
                'array_combine() keys must be integers or strings in this compiler build'
            );
        }
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store(
            $context->builder->addNoSignedWrap($idx, $one),
            $idxSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $okPtr,
            $dest
        );
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);
        $phi = $context->builder->phi($failPtr->typeOf());
        $phi->addIncoming($failPtr, $failBlock);
        $phi->addIncoming($okPtr, $loopDone);

        return $phi;
    }

    private static function nativeElementVariable(Context $context, int $elemType, Value $slot): Variable
    {
        if (Variable::TYPE_STRING === $elemType) {
            return new Variable($context, $elemType, Variable::KIND_VARIABLE, $slot);
        }

        return new Variable(
            $context,
            $elemType,
            Variable::KIND_VALUE,
            $context->builder->load($slot)
        );
    }

    /**
     * array_flip from a packed hashtable slot: old value becomes key, index becomes long value.
     */
    private static function flipStorePackedEntry(
        Context $context,
        Value $dest,
        Value $valEntry,
        Value $index
    ): void {
        $valueMap = $context->structFieldMap['__value__'];
        $valType = $context->builder->load(
            $context->builder->structGep($valEntry, $valueMap['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $keyLong = $context->builder->zExt($index, $i64);

        $stringBlock = BasicBlockHelper::append($context, 'array_flip_packed_val_string');
        $longBlock = BasicBlockHelper::append($context, 'array_flip_packed_val_long');
        $done = BasicBlockHelper::append($context, 'array_flip_packed_val_done');

        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $valType,
            $i8->constInt(Variable::TYPE_STRING, false)
        );
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $valType,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );

        $afterString = BasicBlockHelper::append($context, 'array_flip_packed_after_string');
        $context->builder->branchIf($isString, $stringBlock, $afterString);

        $context->builder->positionAtEnd($stringBlock);
        $newKeyStr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valEntry
        );
        $ownedKey = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $newKeyStr
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $dest,
            $ownedKey,
            $keyLong
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterString);
        $context->builder->branchIf($isLong, $longBlock, $done);

        $context->builder->positionAtEnd($longBlock);
        $newKeyIdx = $context->builder->truncOrBitCast(
            $context->builder->call($context->lookupFunction('__value__readLong'), $valEntry),
            $sizeT
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setLongAt'),
            $dest,
            $newKeyIdx,
            $keyLong
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    /**
     * array_flip: store old value as key and old key as value (int/string subset only).
     */
    private static function flipStoreEntry(
        Context $context,
        Value $dest,
        Value $valEntry,
        Value $keyEntry
    ): void {
        $valueMap = $context->structFieldMap['__value__'];
        $valType = $context->builder->load(
            $context->builder->structGep($valEntry, $valueMap['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $sizeT = $context->getTypeFromString('size_t');

        $stringKeyBlock = BasicBlockHelper::append($context, 'array_flip_newkey_string');
        $longKeyBlock = BasicBlockHelper::append($context, 'array_flip_newkey_long');
        $done = BasicBlockHelper::append($context, 'array_flip_newkey_done');

        $isStringKey = $context->builder->icmp(
            Builder::INT_EQ,
            $valType,
            $i8->constInt(Variable::TYPE_STRING, false)
        );
        $isLongKey = $context->builder->icmp(
            Builder::INT_EQ,
            $valType,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );

        $afterString = BasicBlockHelper::append($context, 'array_flip_after_string_key');
        $context->builder->branchIf($isStringKey, $stringKeyBlock, $afterString);

        $context->builder->positionAtEnd($stringKeyBlock);
        $newKeyStr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valEntry
        );
        $ownedKey = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $newKeyStr
        );
        self::flipStoreValueAtStringKey($context, $dest, $ownedKey, $keyEntry);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterString);
        $context->builder->branchIf($isLongKey, $longKeyBlock, $done);

        $context->builder->positionAtEnd($longKeyBlock);
        $newKeyIdx = $context->builder->truncOrBitCast(
            $context->builder->call($context->lookupFunction('__value__readLong'), $valEntry),
            $sizeT
        );
        self::flipStoreValueAtIndex($context, $dest, $newKeyIdx, $keyEntry);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    private static function flipStoreValueAtIndex(
        Context $context,
        Value $dest,
        Value $index,
        Value $keyEntry
    ): void {
        $valueMap = $context->structFieldMap['__value__'];
        $keyType = $context->builder->load(
            $context->builder->structGep($keyEntry, $valueMap['type'])
        );
        $i8 = $context->getTypeFromString('int8');

        $stringBlock = BasicBlockHelper::append($context, 'array_flip_val_string');
        $longBlock = BasicBlockHelper::append($context, 'array_flip_val_long');
        $done = BasicBlockHelper::append($context, 'array_flip_val_done');

        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $keyType,
            $i8->constInt(Variable::TYPE_STRING, false)
        );
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $keyType,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );

        $afterString = BasicBlockHelper::append($context, 'array_flip_val_after_string');
        $context->builder->branchIf($isString, $stringBlock, $afterString);

        $context->builder->positionAtEnd($stringBlock);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringAt'),
            $dest,
            $index,
            $context->builder->call($context->lookupFunction('__value__readString'), $keyEntry)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterString);
        $context->builder->branchIf($isLong, $longBlock, $done);

        $context->builder->positionAtEnd($longBlock);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setLongAt'),
            $dest,
            $index,
            $context->builder->call($context->lookupFunction('__value__readLong'), $keyEntry)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    private static function flipStoreValueAtStringKey(
        Context $context,
        Value $dest,
        Value $keyStr,
        Value $keyEntry
    ): void {
        $valueMap = $context->structFieldMap['__value__'];
        $keyType = $context->builder->load(
            $context->builder->structGep($keyEntry, $valueMap['type'])
        );
        $i8 = $context->getTypeFromString('int8');

        $stringBlock = BasicBlockHelper::append($context, 'array_flip_sval_string');
        $longBlock = BasicBlockHelper::append($context, 'array_flip_sval_long');
        $done = BasicBlockHelper::append($context, 'array_flip_sval_done');

        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $keyType,
            $i8->constInt(Variable::TYPE_STRING, false)
        );
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $keyType,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );

        $afterString = BasicBlockHelper::append($context, 'array_flip_sval_after_string');
        $context->builder->branchIf($isString, $stringBlock, $afterString);

        $context->builder->positionAtEnd($stringBlock);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyString'),
            $dest,
            $keyStr,
            $context->builder->call($context->lookupFunction('__value__readString'), $keyEntry)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterString);
        $context->builder->branchIf($isLong, $longBlock, $done);

        $context->builder->positionAtEnd($longBlock);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $dest,
            $keyStr,
            $context->builder->call($context->lookupFunction('__value__readLong'), $keyEntry)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    private static function storeCombinedEntry(
        Context $context,
        Value $dest,
        Value $keyEntry,
        Value $valEntry
    ): void {
        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($keyEntry, $valueMap['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $sizeT = $context->getTypeFromString('size_t');

        $stringBlock = BasicBlockHelper::append($context, 'array_combine_key_string');
        $longBlock = BasicBlockHelper::append($context, 'array_combine_key_long');
        $done = BasicBlockHelper::append($context, 'array_combine_key_done');

        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING & 0xff, false)
        );
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );

        $afterString = BasicBlockHelper::append($context, 'array_combine_after_string');
        $context->builder->branchIf($isString, $stringBlock, $afterString);

        $context->builder->positionAtEnd($stringBlock);
        $keyStr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $keyEntry
        );
        self::storeValueEntryAtStringKey($context, $dest, $keyStr, $valEntry);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterString);
        $context->builder->branchIf($isLong, $longBlock, $done);

        $context->builder->positionAtEnd($longBlock);
        $intKey = $context->builder->truncOrBitCast(
            $context->builder->call($context->lookupFunction('__value__readLong'), $keyEntry),
            $sizeT
        );
        self::storeValueEntryAtIndex($context, $dest, $intKey, $valEntry);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    private static function storeValueEntryAtIndex(
        Context $context,
        Value $dest,
        Value $index,
        Value $valEntry
    ): void {
        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valEntry, $valueMap['type'])
        );
        $i8 = $context->getTypeFromString('int8');

        $longBlock = BasicBlockHelper::append($context, 'array_combine_val_long');
        $stringBlock = BasicBlockHelper::append($context, 'array_combine_val_string');
        $doubleBlock = BasicBlockHelper::append($context, 'array_combine_val_double');
        $boolBlock = BasicBlockHelper::append($context, 'array_combine_val_bool');
        $done = BasicBlockHelper::append($context, 'array_combine_val_done');

        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING & 0xff, false)
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

        $afterString = BasicBlockHelper::append($context, 'array_combine_val_after_string');
        $context->builder->branchIf($isString, $stringBlock, $afterString);

        $context->builder->positionAtEnd($stringBlock);
        $str = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valEntry
        );
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $str
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringAt'),
            $dest,
            $index,
            $owned
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterString);
        $afterLong = BasicBlockHelper::append($context, 'array_combine_val_after_long');
        $context->builder->branchIf($isLong, $longBlock, $afterLong);

        $context->builder->positionAtEnd($longBlock);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setLongAt'),
            $dest,
            $index,
            $context->builder->call($context->lookupFunction('__value__readLong'), $valEntry)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterLong);
        $afterBool = BasicBlockHelper::append($context, 'array_combine_val_after_bool');
        $context->builder->branchIf($isBool, $boolBlock, $afterBool);

        $context->builder->positionAtEnd($boolBlock);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setBoolAt'),
            $dest,
            $index,
            $context->builder->truncOrBitCast(
                $context->builder->call($context->lookupFunction('__value__readLong'), $valEntry),
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
            $index,
            $context->builder->call($context->lookupFunction('__value__readDouble'), $valEntry)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    private static function storeValueEntryAtStringKey(
        Context $context,
        Value $dest,
        Value $keyStr,
        Value $valEntry
    ): void {
        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valEntry, $valueMap['type'])
        );
        $i8 = $context->getTypeFromString('int8');

        $longBlock = BasicBlockHelper::append($context, 'array_combine_sval_long');
        $stringBlock = BasicBlockHelper::append($context, 'array_combine_sval_string');
        $boolBlock = BasicBlockHelper::append($context, 'array_combine_sval_bool');
        $done = BasicBlockHelper::append($context, 'array_combine_sval_done');

        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING & 0xff, false)
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

        $afterString = BasicBlockHelper::append($context, 'array_combine_sval_after_string');
        $context->builder->branchIf($isString, $stringBlock, $afterString);

        $context->builder->positionAtEnd($stringBlock);
        $str = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valEntry
        );
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $str
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyString'),
            $dest,
            $keyStr,
            $owned
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterString);
        $afterLong = BasicBlockHelper::append($context, 'array_combine_sval_after_long');
        $context->builder->branchIf($isLong, $longBlock, $afterLong);

        $context->builder->positionAtEnd($longBlock);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $dest,
            $keyStr,
            $context->builder->call($context->lookupFunction('__value__readLong'), $valEntry)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterLong);
        $context->builder->branchIf($isBool, $boolBlock, $done);

        $context->builder->positionAtEnd($boolBlock);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyBool'),
            $dest,
            $keyStr,
            $context->builder->truncOrBitCast(
                $context->builder->call($context->lookupFunction('__value__readLong'), $valEntry),
                $context->getTypeFromString('int1')
            )
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    public static function copyInto(Context $context, Value $dest, Value $src): void
    {
        self::copyReindexableInto($context, $dest, $src);
    }

    /**
     * Append values from a packed list or numeric-string-key list (#3607).
     */
    public static function copyReindexableInto(Context $context, Value $dest, Value $src): void
    {
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $idxSlot = $context->builder->alloca($sizeT, 1, 'merge_idx');
        $context->builder->store($zero, $idxSlot);
        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $src
        );

        $done = BasicBlockHelper::append($context, 'merge_copy_done');
        $head = BasicBlockHelper::append($context, 'merge_copy_head');
        $body = BasicBlockHelper::append($context, 'merge_copy_body');
        $bodyInt = BasicBlockHelper::append($context, 'merge_copy_body_int');
        $bodyStr = BasicBlockHelper::append($context, 'merge_copy_body_str');
        $bodyStore = BasicBlockHelper::append($context, 'merge_copy_body_store');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $num);
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
        $srcEntryStr = $context->builder->call(
            $context->lookupFunction('__hashtable__readStringKeyValue'),
            $src,
            $keyStr
        );
        $context->builder->branch($bodyStore);

        $context->builder->positionAtEnd($bodyStore);
        $srcEntry = $context->builder->phi($srcEntryInt->typeOf());
        $srcEntry->addIncoming($srcEntryInt, $bodyInt);
        $srcEntry->addIncoming($srcEntryStr, $bodyStr);
        $destMap = $context->structFieldMap['__hashtable__'];
        $destNextPtr = $context->builder->structGep($dest, $destMap['nextFreeElement']);
        $destIdx = $context->builder->load($destNextPtr);
        self::copyPackedValueEntry($context, $srcEntry, $dest, $destIdx);
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
        Value $strict,
        string $tagSuffix = ''
    ): Value {
        $tag = '' === $tagSuffix ? '' : '_'.$tagSuffix;
        $ht = self::isNativeArray($haystack->type)
            ? self::nativeListToHashTable($context, $haystack)
            : self::loadHashTable($context, $haystack);
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $idxSlot = $context->builder->alloca($sizeT, 1, 'in_array_idx'.$tag);
        $context->builder->store($zero, $idxSlot);
        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $ht
        );

        $foundSlot = $context->builder->alloca(
            $context->getTypeFromString('int1'),
            1,
            'in_array_found'.$tag
        );
        $context->builder->store($context->getTypeFromString('int1')->constInt(0, false), $foundSlot);

        $done = BasicBlockHelper::append($context, 'in_array_done'.$tag);
        $head = BasicBlockHelper::append($context, 'in_array_head'.$tag);
        $body = BasicBlockHelper::append($context, 'in_array_body'.$tag);
        $foundBlock = BasicBlockHelper::append($context, 'in_array_found_block'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $num);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $entry = self::listEntryAt($context, $ht, $idx);
        $match = self::entryMatchesNeedle($context, $entry, $needle, $strict);
        $continueBlock = BasicBlockHelper::append($context, 'in_array_continue'.$tag);
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

    /**
     * array_search() for packed lists and string-keyed assoc arrays (subset of PHP).
     *
     * @return Value
     * (key as long/string, or boolean false)
     */
    public static function arraySearch(
        Context $context,
        Variable $needle,
        Variable $haystack,
        Value $strict
    ): Value {
        if (self::isNativeArray($haystack->type)) {
            return self::arraySearchNative($context, $needle, $haystack, $strict);
        }

        $ht = self::loadHashTable($context, $haystack);
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');

        $resultSlot = JitValueBox::alloc($context);
        $resultPtr = JitValueBox::pointer($context, $resultSlot);
        JitValueBox::writeBool($context, $resultSlot, $i1->constInt(0, false));

        $done = BasicBlockHelper::append($context, 'array_search_done');

        $nextFree = $context->builder->load($context->builder->structGep($ht, $map['nextFreeElement']));
        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_search_idx');
        $context->builder->store($zero, $idxSlot);
        $packedHead = BasicBlockHelper::append($context, 'array_search_packed_head');
        $packedBody = BasicBlockHelper::append($context, 'array_search_packed_body');
        $packedFound = BasicBlockHelper::append($context, 'array_search_packed_found');
        $packedFoundWrite = BasicBlockHelper::append($context, 'array_search_packed_write');
        $packedNext = BasicBlockHelper::append($context, 'array_search_packed_next');
        $packedDone = BasicBlockHelper::append($context, 'array_search_packed_done');
        $context->builder->branch($packedHead);

        $context->builder->positionAtEnd($packedHead);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $packedDone, $packedBody);

        $context->builder->positionAtEnd($packedBody);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $ht,
            $idx
        );
        $context->builder->branchIf($isSet, $packedFound, $packedNext);

        $context->builder->positionAtEnd($packedFound);
        $entry = self::listEntryAt($context, $ht, $idx);
        $match = self::entryMatchesNeedle($context, $entry, $needle, $strict);
        $context->builder->branchIf($match, $packedFoundWrite, $packedNext);

        $context->builder->positionAtEnd($packedFoundWrite);
        $i64 = $context->getTypeFromString('int64');
        JitValueBox::writeLong(
            $context,
            $resultSlot,
            $context->builder->truncOrBitCast($idx, $i64)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($packedNext);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($packedHead);

        $strInit = BasicBlockHelper::append($context, 'array_search_str_init');
        $strHead = BasicBlockHelper::append($context, 'array_search_str_head');
        $context->builder->positionAtEnd($packedDone);
        $context->builder->branch($strInit);

        $context->builder->positionAtEnd($strInit);
        $ptrSize = $sizeT->constInt(8, false);
        $strCountSlot = $context->builder->alloca($sizeT, 1, 'array_search_str_count');
        $nodesSlot = $context->builder->alloca($nodePtrType->pointerType(0), 1, 'array_search_str_nodes');
        $walkSlot = $context->builder->alloca($nodePtrType, 1, 'array_search_walk');
        $head = $context->builder->load($context->builder->structGep($ht, $map['strKeys']));
        $context->builder->store($zero, $strCountSlot);
        $context->builder->store($head, $walkSlot);
        $countHead = BasicBlockHelper::append($context, 'array_search_str_count_head');
        $countBody = BasicBlockHelper::append($context, 'array_search_str_count_body');
        $countDone = BasicBlockHelper::append($context, 'array_search_str_count_done');
        $context->builder->branch($countHead);
        $context->builder->positionAtEnd($countHead);
        $walkNode = $context->builder->load($walkSlot);
        $walkEnd = $context->builder->icmp(Builder::INT_EQ, $walkNode, $nodePtrType->constNull());
        $context->builder->branchIf($walkEnd, $countDone, $countBody);
        $context->builder->positionAtEnd($countBody);
        $strCount = $context->builder->load($strCountSlot);
        $context->builder->store($context->builder->addNoSignedWrap($strCount, $one), $strCountSlot);
        $nextWalk = $context->builder->load($context->builder->structGep($walkNode, $nodeMap['next']));
        $context->builder->store($nextWalk, $walkSlot);
        $context->builder->branch($countHead);
        $context->builder->positionAtEnd($countDone);
        $numStrKeys = $context->builder->load($strCountSlot);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $numStrKeys, $zero);
        $strEmpty = BasicBlockHelper::append($context, 'array_search_str_empty');
        $strWork = BasicBlockHelper::append($context, 'array_search_str_work');
        $context->builder->branchIf($isEmpty, $strEmpty, $strWork);
        $context->builder->positionAtEnd($strEmpty);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($strWork);
        $bytes = $context->builder->mulNoSignedWrap($numStrKeys, $ptrSize);
        $nodesRaw = $context->builder->call($context->lookupFunction('malloc'), $bytes);
        $nodesArray = $context->builder->pointerCast($nodesRaw, $nodePtrType->pointerType(0));
        $context->builder->store($nodesArray, $nodesSlot);
        $context->builder->store($zero, $strCountSlot);
        $context->builder->store($head, $walkSlot);
        $fillHead = BasicBlockHelper::append($context, 'array_search_str_fill_head');
        $fillBody = BasicBlockHelper::append($context, 'array_search_str_fill_body');
        $fillDone = BasicBlockHelper::append($context, 'array_search_str_fill_done');
        $context->builder->branch($fillHead);
        $context->builder->positionAtEnd($fillHead);
        $fillNode = $context->builder->load($walkSlot);
        $fillEnd = $context->builder->icmp(Builder::INT_EQ, $fillNode, $nodePtrType->constNull());
        $context->builder->branchIf($fillEnd, $fillDone, $fillBody);
        $context->builder->positionAtEnd($fillBody);
        $fillIdx = $context->builder->load($strCountSlot);
        $nodesArray = $context->builder->load($nodesSlot);
        $context->builder->store($fillNode, $context->builder->inBoundsGEP($nodesArray, $fillIdx));
        $context->builder->store($context->builder->addNoSignedWrap($fillIdx, $one), $strCountSlot);
        $nextFill = $context->builder->load($context->builder->structGep($fillNode, $nodeMap['next']));
        $context->builder->store($nextFill, $walkSlot);
        $context->builder->branch($fillHead);
        $context->builder->positionAtEnd($fillDone);
        $strIdxSlot = $context->builder->alloca($sizeT, 1, 'array_search_str_idx');
        $context->builder->store($zero, $strIdxSlot);
        $strBody = BasicBlockHelper::append($context, 'array_search_str_body');
        $strFound = BasicBlockHelper::append($context, 'array_search_str_found');
        $strNext = BasicBlockHelper::append($context, 'array_search_str_next');
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strHead);
        $nodeIdx = $context->builder->load($strIdxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $nodeIdx, $numStrKeys);
        $strDrain = BasicBlockHelper::append($context, 'array_search_str_drain');
        $context->builder->branchIf($atEnd, $strDrain, $strBody);

        $context->builder->positionAtEnd($strDrain);
        $nodesArray = $context->builder->load($nodesSlot);
        $nodesRaw = $context->builder->pointerCast($nodesArray, $context->getTypeFromString('int8*'));
        $context->builder->call($context->lookupFunction('free'), $nodesRaw);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($strBody);
        $nodesArray = $context->builder->load($nodesSlot);
        $node = $context->builder->load($context->builder->inBoundsGEP($nodesArray, $nodeIdx));
        $valEntry = $context->builder->structGep($node, $nodeMap['value']);
        $match = self::entryMatchesNeedle($context, $valEntry, $needle, $strict);
        $context->builder->branchIf($match, $strFound, $strNext);

        $context->builder->positionAtEnd($strFound);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $keyStr);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $resultPtr,
            $owned
        );
        $nodesArray = $context->builder->load($nodesSlot);
        $nodesRaw = $context->builder->pointerCast($nodesArray, $context->getTypeFromString('int8*'));
        $context->builder->call($context->lookupFunction('free'), $nodesRaw);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($strNext);
        $context->builder->store($context->builder->addNoSignedWrap($nodeIdx, $one), $strIdxSlot);
        $context->builder->branch($strHead);

        $retBlock = BasicBlockHelper::append($context, 'array_search_return');
        $context->builder->positionAtEnd($done);
        $context->builder->branch($retBlock);
        $context->builder->positionAtEnd($retBlock);

        return $resultPtr;
    }

    private static function arraySearchNative(
        Context $context,
        Variable $needle,
        Variable $array,
        Value $strict
    ): Value {
        $elemType = $array->type & ~Variable::IS_NATIVE_ARRAY;
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $count = $context->constantFromInteger($array->nextFreeElement, 'size_t');

        $resultSlot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $resultSlot, $i1->constInt(0, false));

        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_search_native_idx');
        $context->builder->store($zero, $idxSlot);
        $head = BasicBlockHelper::append($context, 'array_search_native_head');
        $body = BasicBlockHelper::append($context, 'array_search_native_body');
        $foundWrite = BasicBlockHelper::append($context, 'array_search_native_write');
        $next = BasicBlockHelper::append($context, 'array_search_native_next');
        $done = BasicBlockHelper::append($context, 'array_search_native_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $slot = $context->builder->inBoundsGep($array->value, $zero, $idx);
        $elem = $context->builder->load($slot);
        $cand = new Variable($context, $elemType, Variable::KIND_VALUE, $elem);
        $match = self::valuesEqual($context, $needle, $cand, $strict);
        $context->builder->branchIf($match, $foundWrite, $next);

        $context->builder->positionAtEnd($foundWrite);
        JitValueBox::writeLong(
            $context,
            $resultSlot,
            $context->builder->truncOrBitCast($idx, $i64)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($next);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $retBlock = BasicBlockHelper::append($context, 'array_search_native_return');
        $context->builder->positionAtEnd($done);
        $context->builder->branch($retBlock);
        $context->builder->positionAtEnd($retBlock);

        return JitValueBox::pointer($context, $resultSlot);
    }

    /**
     * Unbox a TYPE_VALUE needle (e.g. $_GET['route']) for comparison with a typed candidate.
     */
    private static function coerceNeedleForCompare(Context $context, Variable $needle, int $targetType): Variable
    {
        if ($needle->type === $targetType) {
            return $needle;
        }
        if (Variable::TYPE_VALUE !== $needle->type) {
            return $needle;
        }
        if (Variable::TYPE_STRING === $targetType) {
            return new Variable(
                $context,
                Variable::TYPE_STRING,
                Variable::KIND_VALUE,
                $context->builder->call($context->lookupFunction('__value__readString'), $needle->value)
            );
        }
        if (Variable::TYPE_NATIVE_LONG === $targetType) {
            return new Variable(
                $context,
                Variable::TYPE_NATIVE_LONG,
                Variable::KIND_VALUE,
                $context->builder->call($context->lookupFunction('__value__readLong'), $needle->value)
            );
        }
        if (Variable::TYPE_NATIVE_BOOL === $targetType) {
            $i1 = $context->getTypeFromString('int1');

            return new Variable(
                $context,
                Variable::TYPE_NATIVE_BOOL,
                Variable::KIND_VALUE,
                $context->builder->truncOrBitCast(
                    $context->builder->call($context->lookupFunction('__value__readLong'), $needle->value),
                    $i1
                )
            );
        }
        if (Variable::TYPE_NATIVE_DOUBLE === $targetType) {
            return new Variable(
                $context,
                Variable::TYPE_NATIVE_DOUBLE,
                Variable::KIND_VALUE,
                $context->builder->call($context->lookupFunction('__value__readDouble'), $needle->value)
            );
        }

        return $needle;
    }

    private static function entryMatchesNeedle(
        Context $context,
        Value $entry,
        Variable $needle,
        Value $strict
    ): Value {
        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($entry, $valueMap['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');
        $false = $i1->constInt(0, false);
        $resultSlot = $context->builder->alloca($i1, 1, 'entry_match');
        $context->builder->store($false, $resultSlot);

        $bbString = BasicBlockHelper::append($context, 'entry_match_string');
        $bbCheckLong = BasicBlockHelper::append($context, 'entry_match_check_long');
        $bbLong = BasicBlockHelper::append($context, 'entry_match_long');
        $bbCheckBool = BasicBlockHelper::append($context, 'entry_match_check_bool');
        $bbBool = BasicBlockHelper::append($context, 'entry_match_bool');
        $bbCheckDouble = BasicBlockHelper::append($context, 'entry_match_check_double');
        $bbDouble = BasicBlockHelper::append($context, 'entry_match_double');
        $bbCheckHashtable = BasicBlockHelper::append($context, 'entry_match_check_hashtable');
        $bbHashtable = BasicBlockHelper::append($context, 'entry_match_hashtable');
        $bbNull = BasicBlockHelper::append($context, 'entry_match_null');
        $bbDone = BasicBlockHelper::append($context, 'entry_match_done');

        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING & 0xff, false)
        );
        $context->builder->branchIf($isString, $bbString, $bbCheckLong);

        $context->builder->positionAtEnd($bbString);
        $strCand = new Variable(
            $context,
            Variable::TYPE_STRING,
            Variable::KIND_VALUE,
            $context->builder->call($context->lookupFunction('__value__readString'), $entry)
        );
        $context->builder->store(
            self::valuesEqual($context, self::coerceNeedleForCompare($context, $needle, Variable::TYPE_STRING), $strCand, $strict),
            $resultSlot
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbCheckLong);
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );
        $context->builder->branchIf($isLong, $bbLong, $bbCheckBool);

        $context->builder->positionAtEnd($bbLong);
        $longCand = new Variable(
            $context,
            Variable::TYPE_NATIVE_LONG,
            Variable::KIND_VALUE,
            $context->builder->call($context->lookupFunction('__value__readLong'), $entry)
        );
        $context->builder->store(
            self::valuesEqual($context, self::coerceNeedleForCompare($context, $needle, Variable::TYPE_NATIVE_LONG), $longCand, $strict),
            $resultSlot
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbCheckBool);
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false)
        );
        $context->builder->branchIf($isBool, $bbBool, $bbCheckDouble);

        $context->builder->positionAtEnd($bbBool);
        $boolCand = new Variable(
            $context,
            Variable::TYPE_NATIVE_BOOL,
            Variable::KIND_VALUE,
            $context->builder->truncOrBitCast(
                $context->builder->call($context->lookupFunction('__value__readLong'), $entry),
                $i1
            )
        );
        $context->builder->store(
            self::valuesEqual($context, self::coerceNeedleForCompare($context, $needle, Variable::TYPE_NATIVE_BOOL), $boolCand, $strict),
            $resultSlot
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbCheckDouble);
        $isDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)
        );
        $context->builder->branchIf($isDouble, $bbDouble, $bbCheckHashtable);

        $context->builder->positionAtEnd($bbDouble);
        $doubleCand = new Variable(
            $context,
            Variable::TYPE_NATIVE_DOUBLE,
            Variable::KIND_VALUE,
            $context->builder->call($context->lookupFunction('__value__readDouble'), $entry)
        );
        $context->builder->store(
            self::valuesEqual($context, self::coerceNeedleForCompare($context, $needle, Variable::TYPE_NATIVE_DOUBLE), $doubleCand, $strict),
            $resultSlot
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbCheckHashtable);
        $isHashtable = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_HASHTABLE, false)
        );
        $context->builder->branchIf($isHashtable, $bbHashtable, $bbNull);

        $context->builder->positionAtEnd($bbHashtable);
        $htCand = new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            $context->builder->call($context->lookupFunction('__value__readHashtable'), $entry)
        );
        $context->builder->store(
            self::valuesEqual($context, $needle, $htCand, $strict),
            $resultSlot
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbNull);
        if (Variable::TYPE_NULL === $needle->type) {
            $isNull = $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(Variable::TYPE_NULL, false)
            );
            $context->builder->store($isNull, $resultSlot);
        }
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);

        return $context->builder->load($resultSlot);
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
            if (Variable::TYPE_STRING === $left->type) {
                return JitValueCompare::looseEqualStringToString(
                    $context,
                    $context->helper->loadValue($left),
                    $context->helper->loadValue($right)
                );
            }

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
        if (Variable::TYPE_STRING === $left->type && Variable::TYPE_NATIVE_LONG === $right->type) {
            return self::looseEqualStringLong($context, $left, $right);
        }
        if (Variable::TYPE_NATIVE_LONG === $left->type && Variable::TYPE_STRING === $right->type) {
            return self::looseEqualStringLong($context, $right, $left);
        }
        if (Variable::TYPE_HASHTABLE === $left->type && Variable::TYPE_NATIVE_BOOL === $right->type) {
            return JitValueCompare::looseEqualArrayToBool($context, $left, $context->helper->loadValue($right));
        }
        if (Variable::TYPE_NATIVE_BOOL === $left->type && Variable::TYPE_HASHTABLE === $right->type) {
            return JitValueCompare::looseEqualArrayToBool($context, $right, $context->helper->loadValue($left));
        }
        if (ArrayBuiltinHelper::isNativeArray($left->type) && Variable::TYPE_NATIVE_BOOL === $right->type) {
            return JitValueCompare::looseEqualArrayToBool($context, $left, $context->helper->loadValue($right));
        }
        if (Variable::TYPE_NATIVE_BOOL === $left->type && ArrayBuiltinHelper::isNativeArray($right->type)) {
            return JitValueCompare::looseEqualArrayToBool($context, $right, $context->helper->loadValue($left));
        }
        if (Variable::TYPE_HASHTABLE === $left->type && Variable::TYPE_NULL === $right->type) {
            return JitValueCompare::looseEqualArrayToNull($context, $left);
        }
        if (Variable::TYPE_NULL === $left->type && Variable::TYPE_HASHTABLE === $right->type) {
            return JitValueCompare::looseEqualArrayToNull($context, $right);
        }
        if (ArrayBuiltinHelper::isNativeArray($left->type) && Variable::TYPE_NULL === $right->type) {
            return JitValueCompare::looseEqualArrayToNull($context, $left);
        }
        if (Variable::TYPE_NULL === $left->type && ArrayBuiltinHelper::isNativeArray($right->type)) {
            return JitValueCompare::looseEqualArrayToNull($context, $right);
        }

        return $context->constantFromBool(false);
    }

    private static function looseEqualStringLong(Context $context, Variable $str, Variable $long): Value
    {
        return JitValueCompare::looseEqualStringToNativeLong(
            $context,
            $context->helper->loadValue($str),
            $context->helper->loadValue($long)
        );
    }

    public static function shufflePacked(Context $context, Variable $array): void
    {
        if (self::isNativeArray($array->type)) {
            throw new \LogicException(
                'shuffle() cannot compile fixed-size literal arrays in JIT/AOT yet; use bin/vm.php or bin/serve.php, or build the list with [] append'
            );
        }
        $ht = self::loadHashTable($context, $array);
        $context->builder->call($context->lookupFunction('__hashtable__shufflePacked'), $ht);
        HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);
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
        HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);
    }

    public static function ksortByKey(Context $context, Variable $array): void
    {
        if (self::isNativeArray($array->type)) {
            throw new \LogicException(
                'ksort() cannot compile fixed-size literal arrays in JIT/AOT yet; use bin/vm.php or bin/serve.php'
            );
        }
        $isList = \PHPCompiler\ext\standard\JitArrayIsList::invoke($context, $array);
        $done = BasicBlockHelper::append($context, 'ksort_done');
        $sort = BasicBlockHelper::append($context, 'ksort_sort');
        $context->builder->branchIf($isList, $done, $sort);

        $context->builder->positionAtEnd($sort);
        self::sortStringKeys($context, $array);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    public static function sortStringKeys(Context $context, Variable $array): void
    {
        $ht = self::loadHashTable($context, $array);
        $context->builder->call($context->lookupFunction('__hashtable__sortStringKeys'), $ht);
        HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);
    }

    public static function krsortByKey(Context $context, Variable $array): void
    {
        if (self::isNativeArray($array->type)) {
            throw new \LogicException(
                'krsort() cannot compile fixed-size literal arrays in JIT/AOT yet; use bin/vm.php or bin/serve.php'
            );
        }
        $isList = \PHPCompiler\ext\standard\JitArrayIsList::invoke($context, $array);
        $done = BasicBlockHelper::append($context, 'krsort_done');
        $sort = BasicBlockHelper::append($context, 'krsort_sort');
        $context->builder->branchIf($isList, $done, $sort);

        $context->builder->positionAtEnd($sort);
        self::sortStringKeysReverse($context, $array);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    public static function sortStringKeysReverse(Context $context, Variable $array): void
    {
        $ht = self::loadHashTable($context, $array);
        $context->builder->call($context->lookupFunction('__hashtable__sortStringKeysReverse'), $ht);
        HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);
    }

    public static function asortByValue(Context $context, Variable $array): void
    {
        if (self::isNativeArray($array->type)) {
            throw new \LogicException(
                'asort() cannot compile fixed-size literal arrays in JIT/AOT yet; use bin/vm.php or bin/serve.php'
            );
        }
        $isList = \PHPCompiler\ext\standard\JitArrayIsList::invoke($context, $array);
        $done = BasicBlockHelper::append($context, 'asort_done');
        $sortList = BasicBlockHelper::append($context, 'asort_sort_list');
        $sortAssoc = BasicBlockHelper::append($context, 'asort_sort_assoc');
        $context->builder->branchIf($isList, $sortList, $sortAssoc);

        $context->builder->positionAtEnd($sortList);
        self::sortPacked($context, $array);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($sortAssoc);
        self::sortStringKeyValues($context, $array);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    public static function natsortByValue(Context $context, Variable $array): void
    {
        if (self::isNativeArray($array->type)) {
            throw new \LogicException(
                'natsort() cannot compile fixed-size literal arrays in JIT/AOT yet; use bin/vm.php or bin/serve.php'
            );
        }
        $isList = \PHPCompiler\ext\standard\JitArrayIsList::invoke($context, $array);
        $done = BasicBlockHelper::append($context, 'natsort_done');
        $sortList = BasicBlockHelper::append($context, 'natsort_sort_list');
        $sortAssoc = BasicBlockHelper::append($context, 'natsort_sort_assoc');
        $context->builder->branchIf($isList, $sortList, $sortAssoc);

        $context->builder->positionAtEnd($sortList);
        self::sortPackedNatural($context, $array);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($sortAssoc);
        self::sortStringKeyValuesNatural($context, $array);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    public static function sortPackedNatural(Context $context, Variable $array): void
    {
        if (self::isNativeArray($array->type)) {
            throw new \LogicException(
                'natsort() cannot compile fixed-size literal arrays in JIT/AOT yet; use bin/vm.php or bin/serve.php'
            );
        }
        $ht = self::loadHashTable($context, $array);
        $context->builder->call($context->lookupFunction('__hashtable__sortPackedNatural'), $ht);
        HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);
    }

    public static function sortStringKeyValuesNatural(Context $context, Variable $array): void
    {
        $ht = self::loadHashTable($context, $array);
        $context->builder->call($context->lookupFunction('__hashtable__sortStringKeyValuesNatural'), $ht);
        HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);
    }

    public static function natcasesortByValue(Context $context, Variable $array): void
    {
        if (self::isNativeArray($array->type)) {
            throw new \LogicException(
                'natcasesort() cannot compile fixed-size literal arrays in JIT/AOT yet; use bin/vm.php or bin/serve.php'
            );
        }
        $isList = \PHPCompiler\ext\standard\JitArrayIsList::invoke($context, $array);
        $done = BasicBlockHelper::append($context, 'natcasesort_done');
        $sortList = BasicBlockHelper::append($context, 'natcasesort_sort_list');
        $sortAssoc = BasicBlockHelper::append($context, 'natcasesort_sort_assoc');
        $context->builder->branchIf($isList, $sortList, $sortAssoc);

        $context->builder->positionAtEnd($sortList);
        self::sortPackedNaturalCase($context, $array);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($sortAssoc);
        self::sortStringKeyValuesNaturalCase($context, $array);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    public static function sortPackedNaturalCase(Context $context, Variable $array): void
    {
        if (self::isNativeArray($array->type)) {
            throw new \LogicException(
                'natcasesort() cannot compile fixed-size literal arrays in JIT/AOT yet; use bin/vm.php or bin/serve.php'
            );
        }
        $ht = self::loadHashTable($context, $array);
        $context->builder->call($context->lookupFunction('__hashtable__sortPackedNaturalCase'), $ht);
        HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);
    }

    public static function sortStringKeyValuesNaturalCase(Context $context, Variable $array): void
    {
        $ht = self::loadHashTable($context, $array);
        $context->builder->call($context->lookupFunction('__hashtable__sortStringKeyValuesNaturalCase'), $ht);
        HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);
    }

    public static function sortStringKeyValues(Context $context, Variable $array): void
    {
        $ht = self::loadHashTable($context, $array);
        $context->builder->call($context->lookupFunction('__hashtable__sortStringKeyValues'), $ht);
        HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);
    }

    public static function sortPackedReverse(Context $context, Variable $array): void
    {
        if (self::isNativeArray($array->type)) {
            throw new \LogicException(
                'arsort() cannot compile fixed-size literal arrays in JIT/AOT yet; use bin/vm.php or bin/serve.php, or build the list with [] append'
            );
        }
        $ht = self::loadHashTable($context, $array);
        $context->builder->call($context->lookupFunction('__hashtable__sortPackedReverse'), $ht);
        HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);
    }

    public static function sortStringKeyValuesReverse(Context $context, Variable $array): void
    {
        $ht = self::loadHashTable($context, $array);
        $context->builder->call($context->lookupFunction('__hashtable__sortStringKeyValuesReverse'), $ht);
        HashTableHelper::storeHashtableInArrayVariable($context, $array, $ht);
    }

    public static function arsortByValue(Context $context, Variable $array): void
    {
        if (self::isNativeArray($array->type)) {
            throw new \LogicException(
                'arsort() cannot compile fixed-size literal arrays in JIT/AOT yet; use bin/vm.php or bin/serve.php'
            );
        }
        $isList = \PHPCompiler\ext\standard\JitArrayIsList::invoke($context, $array);
        $done = BasicBlockHelper::append($context, 'arsort_done');
        $sortList = BasicBlockHelper::append($context, 'arsort_sort_list');
        $sortAssoc = BasicBlockHelper::append($context, 'arsort_sort_assoc');
        $context->builder->branchIf($isList, $sortList, $sortAssoc);

        $context->builder->positionAtEnd($sortList);
        self::sortPackedReverse($context, $array);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($sortAssoc);
        self::sortStringKeyValuesReverse($context, $array);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
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
                return JitStringCompare::identical(
                    $context,
                    $context->helper->loadValue($left),
                    $context->helper->loadValue($right)
                );
            case Variable::TYPE_NULL:
                return $context->constantFromBool(true);
            default:
                return $context->constantFromBool(false);
        }
    }

    /**
     * True when __string__* is fully consumed by base-10 strtol (integer numeric string; #3619).
     */
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

    private static function stringPtrToDouble(Context $context, Value $strPtr): Value
    {
        $structName = $strPtr->typeOf()->getElementType()->getName();
        $map = $context->structFieldMap[$structName];
        $charPtr = $context->builder->structGep($strPtr, $map['value']);
        $endPtr = $context->getTypeFromString('int8**')->constNull();

        return $context->builder->call($context->lookupFunction('strtod'), $charPtr, $endPtr);
    }

    /**
     * Emit LLVM to accumulate a __string__* element into array_sum slots (#3619).
     */
    private static function arraySumAccumulateStringPtr(
        Context $context,
        Value $strPtr,
        Value $sumIntSlot,
        Value $sumFloatSlot,
        Value $useFloatSlot,
        string $tag
    ): void {
        $i64 = $context->getTypeFromString('int64');
        $double = $context->getTypeFromString('double');
        $i1 = $context->getTypeFromString('int1');

        $isIntNumeric = self::stringPtrIsIntegerNumeric($context, $strPtr);
        $intBlock = BasicBlockHelper::append($context, 'array_sum_'.$tag.'_str_int');
        $floatBlock = BasicBlockHelper::append($context, 'array_sum_'.$tag.'_str_float');
        $strDone = BasicBlockHelper::append($context, 'array_sum_'.$tag.'_str_done');
        $context->builder->branchIf($isIntNumeric, $intBlock, $floatBlock);

        $context->builder->positionAtEnd($intBlock);
        $charPtr = $context->builder->structGep(
            $strPtr,
            $context->structFieldMap['__string__']['value']
        );
        $endPtrSlot = $context->builder->alloca(
            $context->getTypeFromString('int8*'),
            1,
            'array_sum_'.$tag.'_strtol_end'
        );
        $context->builder->store($context->getTypeFromString('int8*')->constNull(), $endPtrSlot);
        $longVal = $context->builder->call(
            $context->lookupFunction('strtol'),
            $charPtr,
            $endPtrSlot,
            $context->getTypeFromString('int32')->constInt(10, false)
        );
        $useFloat = $context->builder->load($useFloatSlot);
        $intAsFloat = BasicBlockHelper::append($context, 'array_sum_'.$tag.'_str_int_f');
        $intAsInt = BasicBlockHelper::append($context, 'array_sum_'.$tag.'_str_int_i');
        $intPathDone = BasicBlockHelper::append($context, 'array_sum_'.$tag.'_str_int_done');
        $context->builder->branchIf($useFloat, $intAsFloat, $intAsInt);

        $context->builder->positionAtEnd($intAsInt);
        $sumInt = $context->builder->load($sumIntSlot);
        $context->builder->store(
            $context->builder->addNoSignedWrap($sumInt, $longVal),
            $sumIntSlot
        );
        $context->builder->branch($intPathDone);

        $context->builder->positionAtEnd($intAsFloat);
        $sumFloat = $context->builder->load($sumFloatSlot);
        $context->builder->store(
            $context->builder->fadd($sumFloat, $context->builder->sitofp($longVal, $double)),
            $sumFloatSlot
        );
        $context->builder->branch($intPathDone);

        $context->builder->positionAtEnd($intPathDone);
        $context->builder->branch($strDone);

        $context->builder->positionAtEnd($floatBlock);
        $doubleVal = self::stringPtrToDouble($context, $strPtr);
        $useFloatNow = $context->builder->load($useFloatSlot);
        $promoteBlock = BasicBlockHelper::append($context, 'array_sum_'.$tag.'_str_promote');
        $addFloatBlock = BasicBlockHelper::append($context, 'array_sum_'.$tag.'_str_add_float');
        $floatPathDone = BasicBlockHelper::append($context, 'array_sum_'.$tag.'_str_float_done');
        $context->builder->branchIf($useFloatNow, $addFloatBlock, $promoteBlock);

        $context->builder->positionAtEnd($promoteBlock);
        $sumInt = $context->builder->load($sumIntSlot);
        $context->builder->store(
            $context->builder->fadd($context->builder->sitofp($sumInt, $double), $doubleVal),
            $sumFloatSlot
        );
        $context->builder->store($i1->constInt(1, false), $useFloatSlot);
        $context->builder->branch($floatPathDone);

        $context->builder->positionAtEnd($addFloatBlock);
        $sumFloat = $context->builder->load($sumFloatSlot);
        $context->builder->store($context->builder->fadd($sumFloat, $doubleVal), $sumFloatSlot);
        $context->builder->branch($floatPathDone);

        $context->builder->positionAtEnd($floatPathDone);
        $context->builder->branch($strDone);

        $context->builder->positionAtEnd($strDone);
    }

    /**
     * Emit LLVM to accumulate a string __value__* element into array_sum slots (#3619).
     */
    private static function arraySumAccumulateStringEntry(
        Context $context,
        Value $entry,
        Value $sumIntSlot,
        Value $sumFloatSlot,
        Value $useFloatSlot,
        string $tag
    ): void {
        $strPtr = $context->builder->call($context->lookupFunction('__value__readString'), $entry);
        self::arraySumAccumulateStringPtr(
            $context,
            $strPtr,
            $sumIntSlot,
            $sumFloatSlot,
            $useFloatSlot,
            $tag
        );
    }

    /**
     * Emit LLVM to accumulate a __string__* element into array_product slots (#3619).
     */
    private static function arrayProductAccumulateStringPtr(
        Context $context,
        Value $strPtr,
        Value $prodIntSlot,
        Value $prodFloatSlot,
        Value $useFloatSlot,
        string $tag
    ): void {
        $i64 = $context->getTypeFromString('int64');
        $double = $context->getTypeFromString('double');
        $i1 = $context->getTypeFromString('int1');

        $isIntNumeric = self::stringPtrIsIntegerNumeric($context, $strPtr);
        $intBlock = BasicBlockHelper::append($context, 'array_product_'.$tag.'_str_int');
        $floatBlock = BasicBlockHelper::append($context, 'array_product_'.$tag.'_str_float');
        $strDone = BasicBlockHelper::append($context, 'array_product_'.$tag.'_str_done');
        $context->builder->branchIf($isIntNumeric, $intBlock, $floatBlock);

        $context->builder->positionAtEnd($intBlock);
        $charPtr = $context->builder->structGep(
            $strPtr,
            $context->structFieldMap['__string__']['value']
        );
        $endPtrSlot = $context->builder->alloca(
            $context->getTypeFromString('int8*'),
            1,
            'array_product_'.$tag.'_strtol_end'
        );
        $context->builder->store($context->getTypeFromString('int8*')->constNull(), $endPtrSlot);
        $longVal = $context->builder->call(
            $context->lookupFunction('strtol'),
            $charPtr,
            $endPtrSlot,
            $context->getTypeFromString('int32')->constInt(10, false)
        );
        $useFloat = $context->builder->load($useFloatSlot);
        $intAsFloat = BasicBlockHelper::append($context, 'array_product_'.$tag.'_str_int_f');
        $intAsInt = BasicBlockHelper::append($context, 'array_product_'.$tag.'_str_int_i');
        $intPathDone = BasicBlockHelper::append($context, 'array_product_'.$tag.'_str_int_done');
        $context->builder->branchIf($useFloat, $intAsFloat, $intAsInt);

        $context->builder->positionAtEnd($intAsInt);
        $prodInt = $context->builder->load($prodIntSlot);
        $context->builder->store(
            $context->builder->mulNoSignedWrap($prodInt, $longVal),
            $prodIntSlot
        );
        $context->builder->branch($intPathDone);

        $context->builder->positionAtEnd($intAsFloat);
        $prodFloat = $context->builder->load($prodFloatSlot);
        $context->builder->store(
            $context->builder->fmul($prodFloat, $context->builder->sitofp($longVal, $double)),
            $prodFloatSlot
        );
        $context->builder->branch($intPathDone);

        $context->builder->positionAtEnd($intPathDone);
        $context->builder->branch($strDone);

        $context->builder->positionAtEnd($floatBlock);
        $doubleVal = self::stringPtrToDouble($context, $strPtr);
        $useFloatNow = $context->builder->load($useFloatSlot);
        $promoteBlock = BasicBlockHelper::append($context, 'array_product_'.$tag.'_str_promote');
        $addFloatBlock = BasicBlockHelper::append($context, 'array_product_'.$tag.'_str_add_float');
        $floatPathDone = BasicBlockHelper::append($context, 'array_product_'.$tag.'_str_float_done');
        $context->builder->branchIf($useFloatNow, $addFloatBlock, $promoteBlock);

        $context->builder->positionAtEnd($promoteBlock);
        $prodInt = $context->builder->load($prodIntSlot);
        $context->builder->store(
            $context->builder->fmul($context->builder->sitofp($prodInt, $double), $doubleVal),
            $prodFloatSlot
        );
        $context->builder->store($i1->constInt(1, false), $useFloatSlot);
        $context->builder->branch($floatPathDone);

        $context->builder->positionAtEnd($addFloatBlock);
        $prodFloat = $context->builder->load($prodFloatSlot);
        $context->builder->store($context->builder->fmul($prodFloat, $doubleVal), $prodFloatSlot);
        $context->builder->branch($floatPathDone);

        $context->builder->positionAtEnd($floatPathDone);
        $context->builder->branch($strDone);

        $context->builder->positionAtEnd($strDone);
    }

    /**
     * Emit LLVM to accumulate a string __value__* element into array_product slots (#3619).
     */
    private static function arrayProductAccumulateStringEntry(
        Context $context,
        Value $entry,
        Value $prodIntSlot,
        Value $prodFloatSlot,
        Value $useFloatSlot,
        string $tag
    ): void {
        $strPtr = $context->builder->call($context->lookupFunction('__value__readString'), $entry);
        self::arrayProductAccumulateStringPtr(
            $context,
            $strPtr,
            $prodIntSlot,
            $prodFloatSlot,
            $useFloatSlot,
            $tag
        );
    }

    /**
     * array_sum() for packed lists (integers and floats; subset of PHP).
     */
    public static function arraySum(Context $context, Variable $array): Value
    {
        if (self::isNativeArray($array->type)) {
            return self::arraySumNative($context, $array);
        }

        return self::arraySumHashTable($context, self::loadHashTable($context, $array));
    }

    private static function arraySumNative(Context $context, Variable $array): Value
    {
        $elemType = $array->type & ~Variable::IS_NATIVE_ARRAY;
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $double = $context->getTypeFromString('double');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $count = $context->constantFromInteger($array->nextFreeElement, 'size_t');

        if (Variable::TYPE_NATIVE_DOUBLE === $elemType) {
            $sumSlot = $context->builder->alloca($double, 1, 'array_sum_native_f');
            $context->builder->store($double->constReal(0.0), $sumSlot);
            if (0 === $array->nextFreeElement) {
                return $context->builder->load($sumSlot);
            }
            $idxSlot = $context->builder->alloca($sizeT, 1, 'array_sum_native_f_idx');
            $context->builder->store($zero, $idxSlot);
            $head = BasicBlockHelper::append($context, 'array_sum_native_f_head');
            $body = BasicBlockHelper::append($context, 'array_sum_native_f_body');
            $done = BasicBlockHelper::append($context, 'array_sum_native_f_done');
            $context->builder->branch($head);

            $context->builder->positionAtEnd($head);
            $idx = $context->builder->load($idxSlot);
            $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
            $context->builder->branchIf($atEnd, $done, $body);

            $context->builder->positionAtEnd($body);
            $slot = $context->builder->inBoundsGep($array->value, $zero, $idx);
            $elem = $context->builder->load($slot);
            $sum = $context->builder->load($sumSlot);
            $context->builder->store($context->builder->fadd($sum, $elem), $sumSlot);
            $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
            $context->builder->branch($head);

            $context->builder->positionAtEnd($done);

            return $context->builder->load($sumSlot);
        }

        if (Variable::TYPE_VALUE === $elemType) {
            return self::arraySumNativeValue($context, $array);
        }

        if (Variable::TYPE_STRING === $elemType) {
            return self::arraySumNativeString($context, $array);
        }

        if (Variable::TYPE_NATIVE_LONG !== $elemType) {
            throw new \LogicException(
                'array_sum() only supports integer and float elements in this compiler build'
            );
        }

        $sumSlot = $context->builder->alloca($i64, 1, 'array_sum_native_i');
        $context->builder->store($i64->constInt(0, false), $sumSlot);
        if (0 === $array->nextFreeElement) {
            return $context->builder->load($sumSlot);
        }

        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_sum_native_i_idx');
        $context->builder->store($zero, $idxSlot);
        $head = BasicBlockHelper::append($context, 'array_sum_native_i_head');
        $body = BasicBlockHelper::append($context, 'array_sum_native_i_body');
        $done = BasicBlockHelper::append($context, 'array_sum_native_i_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $slot = $context->builder->inBoundsGep($array->value, $zero, $idx);
        $elem = $context->builder->load($slot);
        $sum = $context->builder->load($sumSlot);
        $context->builder->store($context->builder->addNoSignedWrap($sum, $elem), $sumSlot);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);

        return $context->builder->load($sumSlot);
    }

    private static function arraySumHashTable(Context $context, Value $ht): Value
    {
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $double = $context->getTypeFromString('double');
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);

        $sumIntSlot = $context->builder->alloca($i64, 1, 'array_sum_int');
        $sumFloatSlot = $context->builder->alloca($double, 1, 'array_sum_float');
        $useFloatSlot = $context->builder->alloca($i1, 1, 'array_sum_use_float');
        $context->builder->store($i64->constInt(0, false), $sumIntSlot);
        $context->builder->store($double->constReal(0.0), $sumFloatSlot);
        $context->builder->store($i1->constInt(0, false), $useFloatSlot);

        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $ht
        );

        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_sum_ht_idx');
        $context->builder->store($zero, $idxSlot);
        $head = BasicBlockHelper::append($context, 'array_sum_ht_head');
        $body = BasicBlockHelper::append($context, 'array_sum_ht_body');
        $afterLong = BasicBlockHelper::append($context, 'array_sum_ht_after_long');
        $longBlock = BasicBlockHelper::append($context, 'array_sum_ht_long');
        $doubleBlock = BasicBlockHelper::append($context, 'array_sum_ht_double');
        $afterDouble = BasicBlockHelper::append($context, 'array_sum_ht_after_double');
        $stringBlock = BasicBlockHelper::append($context, 'array_sum_ht_string');
        $continueBlock = BasicBlockHelper::append($context, 'array_sum_ht_continue');
        $doneBlock = BasicBlockHelper::append($context, 'array_sum_ht_done');

        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $num, $zero);
        $context->builder->branchIf($isEmpty, $doneBlock, $head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $num);
        $context->builder->branchIf($atEnd, $doneBlock, $body);

        $context->builder->positionAtEnd($body);
        $entry = self::listEntryAt($context, $ht, $idx);
        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($entry, $valueMap['type'])
        );
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );
        $isDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)
        );
        $context->builder->branchIf($isLong, $longBlock, $afterLong);

        $context->builder->positionAtEnd($afterLong);
        $context->builder->branchIf($isDouble, $doubleBlock, $afterDouble);

        $context->builder->positionAtEnd($afterDouble);
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING & 0xff, false)
        );
        $context->builder->branchIf($isString, $stringBlock, $continueBlock);

        $context->builder->positionAtEnd($stringBlock);
        self::arraySumAccumulateStringEntry(
            $context,
            $entry,
            $sumIntSlot,
            $sumFloatSlot,
            $useFloatSlot,
            'ht'
        );
        $context->builder->branch($continueBlock);

        $context->builder->positionAtEnd($longBlock);
        $longVal = $context->builder->call($context->lookupFunction('__value__readLong'), $entry);
        $useFloat = $context->builder->load($useFloatSlot);
        $floatPath = BasicBlockHelper::append($context, 'array_sum_ht_long_as_float');
        $intPath = BasicBlockHelper::append($context, 'array_sum_ht_long_as_int');
        $longDone = BasicBlockHelper::append($context, 'array_sum_ht_long_done');
        $context->builder->branchIf($useFloat, $floatPath, $intPath);

        $context->builder->positionAtEnd($intPath);
        $sumInt = $context->builder->load($sumIntSlot);
        $context->builder->store(
            $context->builder->addNoSignedWrap($sumInt, $longVal),
            $sumIntSlot
        );
        $context->builder->branch($longDone);

        $context->builder->positionAtEnd($floatPath);
        $sumFloat = $context->builder->load($sumFloatSlot);
        $context->builder->store(
            $context->builder->fadd($sumFloat, $context->builder->siToFp($longVal, $double)),
            $sumFloatSlot
        );
        $context->builder->branch($longDone);

        $context->builder->positionAtEnd($longDone);
        $context->builder->branch($continueBlock);

        $context->builder->positionAtEnd($doubleBlock);
        $doubleVal = $context->builder->call($context->lookupFunction('__value__readDouble'), $entry);
        $useFloatNow = $context->builder->load($useFloatSlot);
        $promoteBlock = BasicBlockHelper::append($context, 'array_sum_ht_promote');
        $addFloatBlock = BasicBlockHelper::append($context, 'array_sum_ht_add_float');
        $doubleDone = BasicBlockHelper::append($context, 'array_sum_ht_double_done');
        $context->builder->branchIf($useFloatNow, $addFloatBlock, $promoteBlock);

        $context->builder->positionAtEnd($promoteBlock);
        $sumInt = $context->builder->load($sumIntSlot);
        $context->builder->store(
            $context->builder->fadd($context->builder->siToFp($sumInt, $double), $doubleVal),
            $sumFloatSlot
        );
        $context->builder->store($i1->constInt(1, false), $useFloatSlot);
        $context->builder->branch($doubleDone);

        $context->builder->positionAtEnd($addFloatBlock);
        $sumFloat = $context->builder->load($sumFloatSlot);
        $context->builder->store($context->builder->fadd($sumFloat, $doubleVal), $sumFloatSlot);
        $context->builder->branch($doubleDone);

        $context->builder->positionAtEnd($doubleDone);
        $context->builder->branch($continueBlock);

        $context->builder->positionAtEnd($continueBlock);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($doneBlock);
        $useFloat = $context->builder->load($useFloatSlot);
        $sumInt = $context->builder->load($sumIntSlot);
        $sumFloat = $context->builder->load($sumFloatSlot);

        return $context->builder->select(
            $useFloat,
            $sumFloat,
            $context->builder->siToFp($sumInt, $double)
        );
    }

    /** Native array of boxed __value__ (e.g. array(1, 2.5)). */
    private static function arraySumNativeValue(Context $context, Variable $array): Value
    {
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $double = $context->getTypeFromString('double');
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $count = $context->constantFromInteger($array->nextFreeElement, 'size_t');
        $valueMap = $context->structFieldMap['__value__'];

        $sumIntSlot = $context->builder->alloca($i64, 1, 'array_sum_nv_int');
        $sumFloatSlot = $context->builder->alloca($double, 1, 'array_sum_nv_float');
        $useFloatSlot = $context->builder->alloca($i1, 1, 'array_sum_nv_use_float');
        $context->builder->store($i64->constInt(0, false), $sumIntSlot);
        $context->builder->store($double->constReal(0.0), $sumFloatSlot);
        $context->builder->store($i1->constInt(0, false), $useFloatSlot);

        if (0 === $array->nextFreeElement) {
            return $context->builder->load($sumIntSlot);
        }

        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_sum_nv_idx');
        $context->builder->store($zero, $idxSlot);
        $head = BasicBlockHelper::append($context, 'array_sum_nv_head');
        $body = BasicBlockHelper::append($context, 'array_sum_nv_body');
        $afterLong = BasicBlockHelper::append($context, 'array_sum_nv_after_long');
        $longBlock = BasicBlockHelper::append($context, 'array_sum_nv_long');
        $doubleBlock = BasicBlockHelper::append($context, 'array_sum_nv_double');
        $afterDouble = BasicBlockHelper::append($context, 'array_sum_nv_after_double');
        $stringBlock = BasicBlockHelper::append($context, 'array_sum_nv_string');
        $continueBlock = BasicBlockHelper::append($context, 'array_sum_nv_continue');
        $doneBlock = BasicBlockHelper::append($context, 'array_sum_nv_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
        $context->builder->branchIf($atEnd, $doneBlock, $body);

        $context->builder->positionAtEnd($body);
        $entry = $context->builder->inBoundsGep($array->value, $zero, $idx);
        $typeByte = $context->builder->load(
            $context->builder->structGep($entry, $valueMap['type'])
        );
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );
        $isDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)
        );
        $context->builder->branchIf($isLong, $longBlock, $afterLong);

        $context->builder->positionAtEnd($afterLong);
        $context->builder->branchIf($isDouble, $doubleBlock, $afterDouble);

        $context->builder->positionAtEnd($afterDouble);
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING & 0xff, false)
        );
        $context->builder->branchIf($isString, $stringBlock, $continueBlock);

        $context->builder->positionAtEnd($stringBlock);
        self::arraySumAccumulateStringEntry(
            $context,
            $entry,
            $sumIntSlot,
            $sumFloatSlot,
            $useFloatSlot,
            'nv'
        );
        $context->builder->branch($continueBlock);

        $context->builder->positionAtEnd($longBlock);
        $longVal = $context->builder->call($context->lookupFunction('__value__readLong'), $entry);
        $useFloat = $context->builder->load($useFloatSlot);
        $floatPath = BasicBlockHelper::append($context, 'array_sum_nv_long_f');
        $intPath = BasicBlockHelper::append($context, 'array_sum_nv_long_i');
        $longDone = BasicBlockHelper::append($context, 'array_sum_nv_long_done');
        $context->builder->branchIf($useFloat, $floatPath, $intPath);

        $context->builder->positionAtEnd($intPath);
        $sumInt = $context->builder->load($sumIntSlot);
        $context->builder->store(
            $context->builder->addNoSignedWrap($sumInt, $longVal),
            $sumIntSlot
        );
        $context->builder->branch($longDone);

        $context->builder->positionAtEnd($floatPath);
        $sumFloat = $context->builder->load($sumFloatSlot);
        $context->builder->store(
            $context->builder->fadd($sumFloat, $context->builder->siToFp($longVal, $double)),
            $sumFloatSlot
        );
        $context->builder->branch($longDone);

        $context->builder->positionAtEnd($longDone);
        $context->builder->branch($continueBlock);

        $context->builder->positionAtEnd($doubleBlock);
        $doubleVal = $context->builder->call($context->lookupFunction('__value__readDouble'), $entry);
        $useFloatNow = $context->builder->load($useFloatSlot);
        $promoteBlock = BasicBlockHelper::append($context, 'array_sum_nv_promote');
        $addFloatBlock = BasicBlockHelper::append($context, 'array_sum_nv_add_float');
        $doubleDone = BasicBlockHelper::append($context, 'array_sum_nv_double_done');
        $context->builder->branchIf($useFloatNow, $addFloatBlock, $promoteBlock);

        $context->builder->positionAtEnd($promoteBlock);
        $sumInt = $context->builder->load($sumIntSlot);
        $context->builder->store(
            $context->builder->fadd($context->builder->siToFp($sumInt, $double), $doubleVal),
            $sumFloatSlot
        );
        $context->builder->store($i1->constInt(1, false), $useFloatSlot);
        $context->builder->branch($doubleDone);

        $context->builder->positionAtEnd($addFloatBlock);
        $sumFloat = $context->builder->load($sumFloatSlot);
        $context->builder->store($context->builder->fadd($sumFloat, $doubleVal), $sumFloatSlot);
        $context->builder->branch($doubleDone);

        $context->builder->positionAtEnd($doubleDone);
        $context->builder->branch($continueBlock);

        $context->builder->positionAtEnd($continueBlock);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($doneBlock);
        $useFloat = $context->builder->load($useFloatSlot);
        $sumInt = $context->builder->load($sumIntSlot);
        $sumFloat = $context->builder->load($sumFloatSlot);

        return $context->builder->select(
            $useFloat,
            $sumFloat,
            $context->builder->siToFp($sumInt, $double)
        );
    }

    /** Native packed __string__* list (compile-time string literals; #3619). */
    private static function arraySumNativeString(Context $context, Variable $array): Value
    {
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $double = $context->getTypeFromString('double');
        $i1 = $context->getTypeFromString('int1');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $count = $context->constantFromInteger($array->nextFreeElement, 'size_t');

        $sumIntSlot = $context->builder->alloca($i64, 1, 'array_sum_ns_int');
        $sumFloatSlot = $context->builder->alloca($double, 1, 'array_sum_ns_float');
        $useFloatSlot = $context->builder->alloca($i1, 1, 'array_sum_ns_use_float');
        $context->builder->store($i64->constInt(0, false), $sumIntSlot);
        $context->builder->store($double->constReal(0.0), $sumFloatSlot);
        $context->builder->store($i1->constInt(0, false), $useFloatSlot);

        if (0 === $array->nextFreeElement) {
            return $context->builder->load($sumIntSlot);
        }

        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_sum_ns_idx');
        $context->builder->store($zero, $idxSlot);
        $head = BasicBlockHelper::append($context, 'array_sum_ns_head');
        $body = BasicBlockHelper::append($context, 'array_sum_ns_body');
        $accumulate = BasicBlockHelper::append($context, 'array_sum_ns_accumulate');
        $continueBlock = BasicBlockHelper::append($context, 'array_sum_ns_continue');
        $doneBlock = BasicBlockHelper::append($context, 'array_sum_ns_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
        $context->builder->branchIf($atEnd, $doneBlock, $body);

        $context->builder->positionAtEnd($body);
        $slot = $context->builder->inBoundsGep($array->value, $zero, $idx);
        $strPtr = $context->builder->load($slot);
        $context->builder->branch($accumulate);

        $context->builder->positionAtEnd($accumulate);
        self::arraySumAccumulateStringPtr(
            $context,
            $strPtr,
            $sumIntSlot,
            $sumFloatSlot,
            $useFloatSlot,
            'ns'
        );
        $context->builder->branch($continueBlock);

        $context->builder->positionAtEnd($continueBlock);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($doneBlock);
        $useFloat = $context->builder->load($useFloatSlot);
        $sumInt = $context->builder->load($sumIntSlot);
        $sumFloat = $context->builder->load($sumFloatSlot);

        return $context->builder->select(
            $useFloat,
            $sumFloat,
            $context->builder->siToFp($sumInt, $double)
        );
    }

    /**
     * array_product() for packed lists (integers and floats; subset of PHP).
     */
    public static function arrayProduct(Context $context, Variable $array): Value
    {
        if (self::isNativeArray($array->type)) {
            return self::arrayProductNative($context, $array);
        }

        return self::arrayProductHashTable($context, self::loadHashTable($context, $array));
    }

    private static function arrayProductNative(Context $context, Variable $array): Value
    {
        $elemType = $array->type & ~Variable::IS_NATIVE_ARRAY;
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $double = $context->getTypeFromString('double');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $count = $context->constantFromInteger($array->nextFreeElement, 'size_t');

        if (Variable::TYPE_NATIVE_DOUBLE === $elemType) {
            $prodSlot = $context->builder->alloca($double, 1, 'array_product_native_f');
            $context->builder->store($double->constReal(1.0), $prodSlot);
            if (0 === $array->nextFreeElement) {
                return $context->builder->load($prodSlot);
            }
            $idxSlot = $context->builder->alloca($sizeT, 1, 'array_product_native_f_idx');
            $context->builder->store($zero, $idxSlot);
            $head = BasicBlockHelper::append($context, 'array_product_native_f_head');
            $body = BasicBlockHelper::append($context, 'array_product_native_f_body');
            $done = BasicBlockHelper::append($context, 'array_product_native_f_done');
            $context->builder->branch($head);

            $context->builder->positionAtEnd($head);
            $idx = $context->builder->load($idxSlot);
            $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
            $context->builder->branchIf($atEnd, $done, $body);

            $context->builder->positionAtEnd($body);
            $slot = $context->builder->inBoundsGep($array->value, $zero, $idx);
            $elem = $context->builder->load($slot);
            $prod = $context->builder->load($prodSlot);
            $context->builder->store($context->builder->fmul($prod, $elem), $prodSlot);
            $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
            $context->builder->branch($head);

            $context->builder->positionAtEnd($done);

            return $context->builder->load($prodSlot);
        }

        if (Variable::TYPE_VALUE === $elemType) {
            return self::arrayProductNativeValue($context, $array);
        }

        if (Variable::TYPE_STRING === $elemType) {
            return self::arrayProductNativeString($context, $array);
        }

        if (Variable::TYPE_NATIVE_LONG !== $elemType) {
            throw new \LogicException(
                'array_product() only supports integer and float elements in this compiler build'
            );
        }

        $prodSlot = $context->builder->alloca($i64, 1, 'array_product_native_i');
        $context->builder->store($i64->constInt(1, false), $prodSlot);
        if (0 === $array->nextFreeElement) {
            return $context->builder->load($prodSlot);
        }

        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_product_native_i_idx');
        $context->builder->store($zero, $idxSlot);
        $head = BasicBlockHelper::append($context, 'array_product_native_i_head');
        $body = BasicBlockHelper::append($context, 'array_product_native_i_body');
        $done = BasicBlockHelper::append($context, 'array_product_native_i_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $slot = $context->builder->inBoundsGep($array->value, $zero, $idx);
        $elem = $context->builder->load($slot);
        $prod = $context->builder->load($prodSlot);
        $context->builder->store($context->builder->mulNoSignedWrap($prod, $elem), $prodSlot);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);

        return $context->builder->load($prodSlot);
    }

    /** Native packed __string__* list (compile-time string literals; #3619). */
    private static function arrayProductNativeString(Context $context, Variable $array): Value
    {
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $double = $context->getTypeFromString('double');
        $i1 = $context->getTypeFromString('int1');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $count = $context->constantFromInteger($array->nextFreeElement, 'size_t');

        $prodIntSlot = $context->builder->alloca($i64, 1, 'array_product_ns_int');
        $prodFloatSlot = $context->builder->alloca($double, 1, 'array_product_ns_float');
        $useFloatSlot = $context->builder->alloca($i1, 1, 'array_product_ns_use_float');
        $context->builder->store($i64->constInt(1, false), $prodIntSlot);
        $context->builder->store($double->constReal(1.0), $prodFloatSlot);
        $context->builder->store($i1->constInt(0, false), $useFloatSlot);

        if (0 === $array->nextFreeElement) {
            return $context->builder->load($prodIntSlot);
        }

        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_product_ns_idx');
        $context->builder->store($zero, $idxSlot);
        $head = BasicBlockHelper::append($context, 'array_product_ns_head');
        $body = BasicBlockHelper::append($context, 'array_product_ns_body');
        $accumulate = BasicBlockHelper::append($context, 'array_product_ns_accumulate');
        $continueBlock = BasicBlockHelper::append($context, 'array_product_ns_continue');
        $doneBlock = BasicBlockHelper::append($context, 'array_product_ns_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
        $context->builder->branchIf($atEnd, $doneBlock, $body);

        $context->builder->positionAtEnd($body);
        $slot = $context->builder->inBoundsGep($array->value, $zero, $idx);
        $strPtr = $context->builder->load($slot);
        $context->builder->branch($accumulate);

        $context->builder->positionAtEnd($accumulate);
        self::arrayProductAccumulateStringPtr(
            $context,
            $strPtr,
            $prodIntSlot,
            $prodFloatSlot,
            $useFloatSlot,
            'ns'
        );
        $context->builder->branch($continueBlock);

        $context->builder->positionAtEnd($continueBlock);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($doneBlock);
        $useFloat = $context->builder->load($useFloatSlot);
        $prodInt = $context->builder->load($prodIntSlot);
        $prodFloat = $context->builder->load($prodFloatSlot);

        return $context->builder->select(
            $useFloat,
            $prodFloat,
            $context->builder->siToFp($prodInt, $double)
        );
    }

    private static function arrayProductHashTable(Context $context, Value $ht): Value
    {
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $double = $context->getTypeFromString('double');
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);

        $prodIntSlot = $context->builder->alloca($i64, 1, 'array_product_int');
        $prodFloatSlot = $context->builder->alloca($double, 1, 'array_product_float');
        $useFloatSlot = $context->builder->alloca($i1, 1, 'array_product_use_float');
        $context->builder->store($i64->constInt(1, false), $prodIntSlot);
        $context->builder->store($double->constReal(1.0), $prodFloatSlot);
        $context->builder->store($i1->constInt(0, false), $useFloatSlot);

        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $ht
        );

        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_product_ht_idx');
        $context->builder->store($zero, $idxSlot);
        $head = BasicBlockHelper::append($context, 'array_product_ht_head');
        $body = BasicBlockHelper::append($context, 'array_product_ht_body');
        $afterLong = BasicBlockHelper::append($context, 'array_product_ht_after_long');
        $longBlock = BasicBlockHelper::append($context, 'array_product_ht_long');
        $doubleBlock = BasicBlockHelper::append($context, 'array_product_ht_double');
        $afterDouble = BasicBlockHelper::append($context, 'array_product_ht_after_double');
        $stringBlock = BasicBlockHelper::append($context, 'array_product_ht_string');
        $continueBlock = BasicBlockHelper::append($context, 'array_product_ht_continue');
        $doneBlock = BasicBlockHelper::append($context, 'array_product_ht_done');

        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $num, $zero);
        $context->builder->branchIf($isEmpty, $doneBlock, $head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $num);
        $context->builder->branchIf($atEnd, $doneBlock, $body);

        $context->builder->positionAtEnd($body);
        $entry = self::listEntryAt($context, $ht, $idx);
        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($entry, $valueMap['type'])
        );
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );
        $isDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)
        );
        $context->builder->branchIf($isLong, $longBlock, $afterLong);

        $context->builder->positionAtEnd($afterLong);
        $context->builder->branchIf($isDouble, $doubleBlock, $afterDouble);

        $context->builder->positionAtEnd($afterDouble);
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING & 0xff, false)
        );
        $context->builder->branchIf($isString, $stringBlock, $continueBlock);

        $context->builder->positionAtEnd($stringBlock);
        self::arrayProductAccumulateStringEntry(
            $context,
            $entry,
            $prodIntSlot,
            $prodFloatSlot,
            $useFloatSlot,
            'ht'
        );
        $context->builder->branch($continueBlock);

        $context->builder->positionAtEnd($longBlock);
        $longVal = $context->builder->call($context->lookupFunction('__value__readLong'), $entry);
        $useFloat = $context->builder->load($useFloatSlot);
        $floatPath = BasicBlockHelper::append($context, 'array_product_ht_long_as_float');
        $intPath = BasicBlockHelper::append($context, 'array_product_ht_long_as_int');
        $longDone = BasicBlockHelper::append($context, 'array_product_ht_long_done');
        $context->builder->branchIf($useFloat, $floatPath, $intPath);

        $context->builder->positionAtEnd($intPath);
        $prodInt = $context->builder->load($prodIntSlot);
        $context->builder->store(
            $context->builder->mulNoSignedWrap($prodInt, $longVal),
            $prodIntSlot
        );
        $context->builder->branch($longDone);

        $context->builder->positionAtEnd($floatPath);
        $prodFloat = $context->builder->load($prodFloatSlot);
        $context->builder->store(
            $context->builder->fmul($prodFloat, $context->builder->siToFp($longVal, $double)),
            $prodFloatSlot
        );
        $context->builder->branch($longDone);

        $context->builder->positionAtEnd($longDone);
        $context->builder->branch($continueBlock);

        $context->builder->positionAtEnd($doubleBlock);
        $doubleVal = $context->builder->call($context->lookupFunction('__value__readDouble'), $entry);
        $useFloatNow = $context->builder->load($useFloatSlot);
        $promoteBlock = BasicBlockHelper::append($context, 'array_product_ht_promote');
        $addFloatBlock = BasicBlockHelper::append($context, 'array_product_ht_add_float');
        $doubleDone = BasicBlockHelper::append($context, 'array_product_ht_double_done');
        $context->builder->branchIf($useFloatNow, $addFloatBlock, $promoteBlock);

        $context->builder->positionAtEnd($promoteBlock);
        $prodInt = $context->builder->load($prodIntSlot);
        $context->builder->store(
            $context->builder->fmul($context->builder->siToFp($prodInt, $double), $doubleVal),
            $prodFloatSlot
        );
        $context->builder->store($i1->constInt(1, false), $useFloatSlot);
        $context->builder->branch($doubleDone);

        $context->builder->positionAtEnd($addFloatBlock);
        $prodFloat = $context->builder->load($prodFloatSlot);
        $context->builder->store($context->builder->fmul($prodFloat, $doubleVal), $prodFloatSlot);
        $context->builder->branch($doubleDone);

        $context->builder->positionAtEnd($doubleDone);
        $context->builder->branch($continueBlock);

        $context->builder->positionAtEnd($continueBlock);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($doneBlock);
        $useFloat = $context->builder->load($useFloatSlot);
        $prodInt = $context->builder->load($prodIntSlot);
        $prodFloat = $context->builder->load($prodFloatSlot);

        return $context->builder->select(
            $useFloat,
            $prodFloat,
            $context->builder->siToFp($prodInt, $double)
        );
    }

    /** Native array of boxed __value__ (e.g. array(1, 2.5)). */
    private static function arrayProductNativeValue(Context $context, Variable $array): Value
    {
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $double = $context->getTypeFromString('double');
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $count = $context->constantFromInteger($array->nextFreeElement, 'size_t');
        $valueMap = $context->structFieldMap['__value__'];

        $prodIntSlot = $context->builder->alloca($i64, 1, 'array_product_nv_int');
        $prodFloatSlot = $context->builder->alloca($double, 1, 'array_product_nv_float');
        $useFloatSlot = $context->builder->alloca($i1, 1, 'array_product_nv_use_float');
        $context->builder->store($i64->constInt(1, false), $prodIntSlot);
        $context->builder->store($double->constReal(1.0), $prodFloatSlot);
        $context->builder->store($i1->constInt(0, false), $useFloatSlot);

        if (0 === $array->nextFreeElement) {
            return $context->builder->load($prodIntSlot);
        }

        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_product_nv_idx');
        $context->builder->store($zero, $idxSlot);
        $head = BasicBlockHelper::append($context, 'array_product_nv_head');
        $body = BasicBlockHelper::append($context, 'array_product_nv_body');
        $afterLong = BasicBlockHelper::append($context, 'array_product_nv_after_long');
        $longBlock = BasicBlockHelper::append($context, 'array_product_nv_long');
        $doubleBlock = BasicBlockHelper::append($context, 'array_product_nv_double');
        $afterDouble = BasicBlockHelper::append($context, 'array_product_nv_after_double');
        $stringBlock = BasicBlockHelper::append($context, 'array_product_nv_string');
        $continueBlock = BasicBlockHelper::append($context, 'array_product_nv_continue');
        $doneBlock = BasicBlockHelper::append($context, 'array_product_nv_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
        $context->builder->branchIf($atEnd, $doneBlock, $body);

        $context->builder->positionAtEnd($body);
        $entry = $context->builder->inBoundsGep($array->value, $zero, $idx);
        $typeByte = $context->builder->load(
            $context->builder->structGep($entry, $valueMap['type'])
        );
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );
        $isDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)
        );
        $context->builder->branchIf($isLong, $longBlock, $afterLong);

        $context->builder->positionAtEnd($afterLong);
        $context->builder->branchIf($isDouble, $doubleBlock, $afterDouble);

        $context->builder->positionAtEnd($afterDouble);
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING & 0xff, false)
        );
        $context->builder->branchIf($isString, $stringBlock, $continueBlock);

        $context->builder->positionAtEnd($stringBlock);
        self::arrayProductAccumulateStringEntry(
            $context,
            $entry,
            $prodIntSlot,
            $prodFloatSlot,
            $useFloatSlot,
            'nv'
        );
        $context->builder->branch($continueBlock);

        $context->builder->positionAtEnd($longBlock);
        $longVal = $context->builder->call($context->lookupFunction('__value__readLong'), $entry);
        $useFloat = $context->builder->load($useFloatSlot);
        $floatPath = BasicBlockHelper::append($context, 'array_product_nv_long_f');
        $intPath = BasicBlockHelper::append($context, 'array_product_nv_long_i');
        $longDone = BasicBlockHelper::append($context, 'array_product_nv_long_done');
        $context->builder->branchIf($useFloat, $floatPath, $intPath);

        $context->builder->positionAtEnd($intPath);
        $prodInt = $context->builder->load($prodIntSlot);
        $context->builder->store(
            $context->builder->mulNoSignedWrap($prodInt, $longVal),
            $prodIntSlot
        );
        $context->builder->branch($longDone);

        $context->builder->positionAtEnd($floatPath);
        $prodFloat = $context->builder->load($prodFloatSlot);
        $context->builder->store(
            $context->builder->fmul($prodFloat, $context->builder->siToFp($longVal, $double)),
            $prodFloatSlot
        );
        $context->builder->branch($longDone);

        $context->builder->positionAtEnd($longDone);
        $context->builder->branch($continueBlock);

        $context->builder->positionAtEnd($doubleBlock);
        $doubleVal = $context->builder->call($context->lookupFunction('__value__readDouble'), $entry);
        $useFloatNow = $context->builder->load($useFloatSlot);
        $promoteBlock = BasicBlockHelper::append($context, 'array_product_nv_promote');
        $addFloatBlock = BasicBlockHelper::append($context, 'array_product_nv_add_float');
        $doubleDone = BasicBlockHelper::append($context, 'array_product_nv_double_done');
        $context->builder->branchIf($useFloatNow, $addFloatBlock, $promoteBlock);

        $context->builder->positionAtEnd($promoteBlock);
        $prodInt = $context->builder->load($prodIntSlot);
        $context->builder->store(
            $context->builder->fmul($context->builder->siToFp($prodInt, $double), $doubleVal),
            $prodFloatSlot
        );
        $context->builder->store($i1->constInt(1, false), $useFloatSlot);
        $context->builder->branch($doubleDone);

        $context->builder->positionAtEnd($addFloatBlock);
        $prodFloat = $context->builder->load($prodFloatSlot);
        $context->builder->store($context->builder->fmul($prodFloat, $doubleVal), $prodFloatSlot);
        $context->builder->branch($doubleDone);

        $context->builder->positionAtEnd($doubleDone);
        $context->builder->branch($continueBlock);

        $context->builder->positionAtEnd($continueBlock);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($doneBlock);
        $useFloat = $context->builder->load($useFloatSlot);
        $prodInt = $context->builder->load($prodIntSlot);
        $prodFloat = $context->builder->load($prodFloatSlot);

        return $context->builder->select(
            $useFloat,
            $prodFloat,
            $context->builder->siToFp($prodInt, $double)
        );
    }

    /**
     * array_unique() for arrays of scalar values (ext/standard/array.c subset).
     *
     * @param int $flags SORT_REGULAR (identical) or SORT_STRING (string cast compare)
     */
    public static function arrayUnique(Context $context, Variable $array, int $flags = 0): Value
    {
        if (self::isNativeArray($array->type)) {
            return self::arrayUniqueHashTable($context, self::nativeListToHashTable($context, $array), $flags);
        }

        return self::arrayUniqueHashTable($context, self::loadHashTable($context, $array), $flags);
    }

    private static function arrayUniqueHashTable(Context $context, Value $src, int $flags): Value
    {
        $dest = HashTableHelper::alloc($context);
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');

        $nextFree = $context->builder->load($context->builder->structGep($src, $map['nextFreeElement']));
        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_unique_packed_idx');
        $context->builder->store($zero, $idxSlot);

        $packedHead = BasicBlockHelper::append($context, 'array_unique_packed_head');
        $packedBody = BasicBlockHelper::append($context, 'array_unique_packed_body');
        $packedKeep = BasicBlockHelper::append($context, 'array_unique_packed_keep');
        $packedSkip = BasicBlockHelper::append($context, 'array_unique_packed_skip');
        $packedAdd = BasicBlockHelper::append($context, 'array_unique_packed_add');
        $packedNext = BasicBlockHelper::append($context, 'array_unique_packed_next');
        $packedDone = BasicBlockHelper::append($context, 'array_unique_packed_done');
        $context->builder->branch($packedHead);

        $context->builder->positionAtEnd($packedHead);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $packedDone, $packedBody);

        $context->builder->positionAtEnd($packedBody);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $src,
            $idx
        );
        $context->builder->branchIf($isSet, $packedKeep, $packedNext);

        $context->builder->positionAtEnd($packedKeep);
        $valEntry = self::listEntryAt($context, $src, $idx);
        $duplicate = self::destContainsPackedEntry($context, $dest, $valEntry, $flags);
        $context->builder->branchIf($duplicate, $packedSkip, $packedAdd);

        $context->builder->positionAtEnd($packedAdd);
        self::appendListEntryScalars($context, $src, $idx, $dest);
        $context->builder->branch($packedNext);

        $context->builder->positionAtEnd($packedSkip);
        $context->builder->branch($packedNext);

        $context->builder->positionAtEnd($packedNext);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($packedHead);

        $strInit = BasicBlockHelper::append($context, 'array_unique_str_init');
        $strHead = BasicBlockHelper::append($context, 'array_unique_str_head');
        $context->builder->positionAtEnd($packedDone);
        $context->builder->branch($strInit);

        $context->builder->positionAtEnd($strInit);
        $walkSlot = $context->builder->alloca($nodePtrType, 1, 'array_unique_walk');
        $head = $context->builder->load($context->builder->structGep($src, $map['strKeys']));
        $context->builder->store($head, $walkSlot);
        $strBody = BasicBlockHelper::append($context, 'array_unique_str_body');
        $strSkip = BasicBlockHelper::append($context, 'array_unique_str_skip');
        $strAdd = BasicBlockHelper::append($context, 'array_unique_str_add');
        $strNext = BasicBlockHelper::append($context, 'array_unique_str_next');
        $strDone = BasicBlockHelper::append($context, 'array_unique_str_done');
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strHead);
        $node = $context->builder->load($walkSlot);
        $nodeNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($nodeNull, $strDone, $strBody);

        $context->builder->positionAtEnd($strBody);
        $valEntry = $context->builder->structGep($node, $nodeMap['value']);
        $duplicate = self::destContainsPackedEntry($context, $dest, $valEntry, $flags);
        $context->builder->branchIf($duplicate, $strSkip, $strAdd);

        $context->builder->positionAtEnd($strAdd);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        self::storeValueEntryAtStringKey($context, $dest, $keyStr, $valEntry);
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($strSkip);
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($strNext);
        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($nextNode, $walkSlot);
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strDone);
        BasicBlockHelper::branchToFreshContinue($context, 'array_unique_continue');

        return $dest;
    }

    /**
     * Duplicate check against values already stored in $dest.
     *
     * @param int $flags SORT_REGULAR (identical) or SORT_STRING (string cast)
     */
    private static function destContainsPackedEntry(Context $context, Value $dest, Value $entry, int $flags): Value
    {
        $sortType = $flags & ~\PHPCompiler\ext\standard\StdlibConstants::SORT_FLAG_CASE;
        if (\PHPCompiler\ext\standard\StdlibConstants::SORT_STRING === $sortType) {
            return self::destContainsPackedEntryString($context, $dest, $entry);
        }

        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($entry, $valueMap['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');
        $strict = $i1->constInt(1, false);
        $falseVal = $i1->constInt(0, false);
        $destVar = new Variable($context, Variable::TYPE_HASHTABLE, Variable::KIND_VALUE, $dest);

        $dupSlot = $context->builder->alloca($i1, 1, 'array_unique_dup');
        $context->builder->store($falseVal, $dupSlot);

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

        $stringBlock = BasicBlockHelper::append($context, 'array_unique_dup_string');
        $longBlock = BasicBlockHelper::append($context, 'array_unique_dup_long');
        $falseBlock = BasicBlockHelper::append($context, 'array_unique_dup_false');
        $mergeBlock = BasicBlockHelper::append($context, 'array_unique_dup_merge');

        $afterString = BasicBlockHelper::append($context, 'array_unique_dup_after_string');
        $context->builder->branchIf($isString, $stringBlock, $afterString);

        $context->builder->positionAtEnd($stringBlock);
        $needle = new Variable(
            $context,
            Variable::TYPE_STRING,
            Variable::KIND_VALUE,
            $context->builder->call($context->lookupFunction('__value__readString'), $entry)
        );
        $context->builder->store(
            self::inArray($context, $needle, $destVar, $strict),
            $dupSlot
        );
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($afterString);
        $context->builder->branchIf($isLong, $longBlock, $falseBlock);

        $context->builder->positionAtEnd($longBlock);
        $needle = new Variable(
            $context,
            Variable::TYPE_NATIVE_LONG,
            Variable::KIND_VALUE,
            $context->builder->call($context->lookupFunction('__value__readLong'), $entry)
        );
        $context->builder->store(
            self::inArray($context, $needle, $destVar, $strict),
            $dupSlot
        );
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($falseBlock);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);

        return $context->builder->load($dupSlot);
    }

    /**
     * SORT_STRING duplicate check: compare string casts (ext/standard string_compare_function).
     */
    private static function destContainsPackedEntryString(Context $context, Value $dest, Value $entry): Value
    {
        $strval = new strval();
        $needleStr = $strval->valueToString($context, $entry);
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_unique_str_dup_idx');
        $context->builder->store($zero, $idxSlot);
        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $dest
        );

        $foundSlot = $context->builder->alloca(
            $context->getTypeFromString('int1'),
            1,
            'array_unique_str_dup_found'
        );
        $context->builder->store($context->getTypeFromString('int1')->constInt(0, false), $foundSlot);

        $done = BasicBlockHelper::append($context, 'array_unique_str_dup_done');
        $head = BasicBlockHelper::append($context, 'array_unique_str_dup_head');
        $body = BasicBlockHelper::append($context, 'array_unique_str_dup_body');
        $foundBlock = BasicBlockHelper::append($context, 'array_unique_str_dup_found_block');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $num);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $candEntry = self::listEntryAt($context, $dest, $idx);
        $candStr = $strval->valueToString($context, $candEntry);
        $match = JitStringCompare::identical($context, $candStr, $needleStr);
        $continueBlock = BasicBlockHelper::append($context, 'array_unique_str_dup_continue');
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

    /**
     * array_diff() for arrays of scalar values (loose compare; subset of PHP; issue #1206).
     */
    public static function arrayDiff(Context $context, Variable $first, Variable ...$others): Value
    {
        if (\count($others) < 1) {
            throw new \LogicException('array_diff() requires at least two arguments');
        }
        $otherHts = [];
        foreach ($others as $other) {
            $otherHts[] = self::isNativeArray($other->type)
                ? self::nativeListToHashTable($context, $other)
                : self::loadHashTable($context, $other);
        }
        $src = self::isNativeArray($first->type)
            ? self::nativeListToHashTable($context, $first)
            : self::loadHashTable($context, $first);

        return self::arrayDiffHashTable($context, $src, $otherHts);
    }

    /**
     * @param list<Value> $otherHts
     */
    private static function arrayDiffHashTable(Context $context, Value $src, array $otherHts): Value
    {
        $dest = HashTableHelper::alloc($context);
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');
        $loose = $context->constantFromBool(false);

        $nextFree = $context->builder->load($context->builder->structGep($src, $map['nextFreeElement']));
        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_diff_packed_idx');
        $context->builder->store($zero, $idxSlot);

        $packedHead = BasicBlockHelper::append($context, 'array_diff_packed_head');
        $packedBody = BasicBlockHelper::append($context, 'array_diff_packed_body');
        $packedKeep = BasicBlockHelper::append($context, 'array_diff_packed_keep');
        $packedSkip = BasicBlockHelper::append($context, 'array_diff_packed_skip');
        $packedAdd = BasicBlockHelper::append($context, 'array_diff_packed_add');
        $packedNext = BasicBlockHelper::append($context, 'array_diff_packed_next');
        $packedDone = BasicBlockHelper::append($context, 'array_diff_packed_done');
        $context->builder->branch($packedHead);

        $context->builder->positionAtEnd($packedHead);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $packedDone, $packedBody);

        $context->builder->positionAtEnd($packedBody);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $src,
            $idx
        );
        $context->builder->branchIf($isSet, $packedKeep, $packedNext);

        $context->builder->positionAtEnd($packedKeep);
        $valEntry = self::listEntryAt($context, $src, $idx);
        $inOthers = self::entryInAnyHaystack($context, $valEntry, $otherHts, $loose);
        $context->builder->branchIf($inOthers, $packedSkip, $packedAdd);

        $context->builder->positionAtEnd($packedAdd);
        self::appendListEntryScalars($context, $src, $idx, $dest);
        $context->builder->branch($packedNext);

        $context->builder->positionAtEnd($packedSkip);
        $context->builder->branch($packedNext);

        $context->builder->positionAtEnd($packedNext);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($packedHead);

        $strInit = BasicBlockHelper::append($context, 'array_diff_str_init');
        $strHead = BasicBlockHelper::append($context, 'array_diff_str_head');
        $context->builder->positionAtEnd($packedDone);
        $context->builder->branch($strInit);

        $context->builder->positionAtEnd($strInit);
        $walkSlot = $context->builder->alloca($nodePtrType, 1, 'array_diff_walk');
        $head = $context->builder->load($context->builder->structGep($src, $map['strKeys']));
        $context->builder->store($head, $walkSlot);
        $strBody = BasicBlockHelper::append($context, 'array_diff_str_body');
        $strSkip = BasicBlockHelper::append($context, 'array_diff_str_skip');
        $strAdd = BasicBlockHelper::append($context, 'array_diff_str_add');
        $strNext = BasicBlockHelper::append($context, 'array_diff_str_next');
        $strDone = BasicBlockHelper::append($context, 'array_diff_str_done');
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strHead);
        $node = $context->builder->load($walkSlot);
        $nodeNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($nodeNull, $strDone, $strBody);

        $context->builder->positionAtEnd($strBody);
        $valEntry = $context->builder->structGep($node, $nodeMap['value']);
        $inOthers = self::entryInAnyHaystack($context, $valEntry, $otherHts, $loose);
        $context->builder->branchIf($inOthers, $strSkip, $strAdd);

        $context->builder->positionAtEnd($strAdd);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        self::storeValueEntryAtStringKey($context, $dest, $keyStr, $valEntry);
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($strSkip);
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($strNext);
        $next = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($next, $walkSlot);
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strDone);

        return $dest;
    }

    /**
     * @param list<Value> $otherHts
     */
    private static function entryInAnyHaystack(
        Context $context,
        Value $entry,
        array $otherHts,
        Value $strict
    ): Value {
        if ([] === $otherHts) {
            return $context->constantFromBool(false);
        }

        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($entry, $valueMap['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');
        $falseVal = $i1->constInt(0, false);
        $foundSlot = $context->builder->alloca($i1, 1, 'array_diff_in_others');
        $context->builder->store($falseVal, $foundSlot);

        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING & 0xff, false)
        );
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );

        $stringBlock = BasicBlockHelper::append($context, 'array_diff_in_others_string');
        $longBlock = BasicBlockHelper::append($context, 'array_diff_in_others_long');
        $falseBlock = BasicBlockHelper::append($context, 'array_diff_in_others_false');
        $mergeBlock = BasicBlockHelper::append($context, 'array_diff_in_others_merge');

        $afterString = BasicBlockHelper::append($context, 'array_diff_in_others_after_string');
        $context->builder->branchIf($isString, $stringBlock, $afterString);

        $context->builder->positionAtEnd($stringBlock);
        $needle = new Variable(
            $context,
            Variable::TYPE_STRING,
            Variable::KIND_VALUE,
            $context->builder->call($context->lookupFunction('__value__readString'), $entry)
        );
        $context->builder->store(
            self::entryInAnyHaystackNeedle($context, $needle, $otherHts, $strict),
            $foundSlot
        );
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($afterString);
        $context->builder->branchIf($isLong, $longBlock, $falseBlock);

        $context->builder->positionAtEnd($longBlock);
        $needle = new Variable(
            $context,
            Variable::TYPE_NATIVE_LONG,
            Variable::KIND_VALUE,
            $context->builder->call($context->lookupFunction('__value__readLong'), $entry)
        );
        $context->builder->store(
            self::entryInAnyHaystackNeedle($context, $needle, $otherHts, $strict),
            $foundSlot
        );
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($falseBlock);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);

        return $context->builder->load($foundSlot);
    }

    /**
     * @param list<Value> $otherHts
     */
    private static function entryInAnyHaystackNeedle(
        Context $context,
        Variable $needle,
        array $otherHts,
        Value $strict
    ): Value {
        $i1 = $context->getTypeFromString('int1');
        $foundSlot = $context->builder->alloca($i1, 1, 'array_diff_in_others_needle');
        $context->builder->store($i1->constInt(0, false), $foundSlot);

        $done = BasicBlockHelper::append($context, 'array_diff_in_others_needle_done');
        $n = \count($otherHts);
        $checkBlocks = [];
        $foundBlocks = [];
        $nextBlocks = [];
        for ($i = 0; $i < $n; ++$i) {
            $checkBlocks[$i] = BasicBlockHelper::append($context, 'array_diff_in_others_needle_ht_'.$i);
            $foundBlocks[$i] = BasicBlockHelper::append($context, 'array_diff_in_others_needle_found_'.$i);
            $nextBlocks[$i] = $i + 1 < $n
                ? BasicBlockHelper::append($context, 'array_diff_in_others_needle_next_'.$i)
                : $done;
        }

        $context->builder->branch($checkBlocks[0]);
        for ($i = 0; $i < $n; ++$i) {
            $haystack = new Variable(
                $context,
                Variable::TYPE_HASHTABLE,
                Variable::KIND_VALUE,
                $otherHts[$i]
            );
            $context->builder->positionAtEnd($checkBlocks[$i]);
            $match = self::inArray($context, $needle, $haystack, $strict, 'diff'.$i);
            $context->builder->branchIf($match, $foundBlocks[$i], $nextBlocks[$i]);
            $context->builder->positionAtEnd($foundBlocks[$i]);
            $context->builder->store($i1->constInt(1, false), $foundSlot);
            $context->builder->branch($done);
            if ($i + 1 < $n) {
                $context->builder->positionAtEnd($nextBlocks[$i]);
                $context->builder->branch($checkBlocks[$i + 1]);
            }
        }

        $context->builder->positionAtEnd($done);

        return $context->builder->load($foundSlot);
    }

    /**
     * array_replace() for arrays with int and string keys (subset of PHP; issue #1208).
     */
    public static function arrayReplace(Context $context, Variable $first, Variable ...$others): Value
    {
        if (\count($others) < 1) {
            throw new \LogicException('array_replace() requires at least two arguments');
        }
        $dest = HashTableHelper::alloc($context);
        foreach ([$first, ...$others] as $array) {
            self::overlayHashTable($context, $dest, self::loadHashTable($context, $array));
        }

        return $dest;
    }

    private static function overlayHashTable(Context $context, Value $dest, Value $src): void
    {
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');

        $nextFree = $context->builder->load($context->builder->structGep($src, $map['nextFreeElement']));
        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_replace_overlay_packed_idx');
        $context->builder->store($zero, $idxSlot);

        $packedHead = BasicBlockHelper::append($context, 'array_replace_overlay_packed_head');
        $packedBody = BasicBlockHelper::append($context, 'array_replace_overlay_packed_body');
        $packedSet = BasicBlockHelper::append($context, 'array_replace_overlay_packed_set');
        $packedNext = BasicBlockHelper::append($context, 'array_replace_overlay_packed_next');
        $packedDone = BasicBlockHelper::append($context, 'array_replace_overlay_packed_done');
        $context->builder->branch($packedHead);

        $context->builder->positionAtEnd($packedHead);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $packedDone, $packedBody);

        $context->builder->positionAtEnd($packedBody);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $src,
            $idx
        );
        $context->builder->branchIf($isSet, $packedSet, $packedNext);

        $context->builder->positionAtEnd($packedSet);
        self::copyPackedListEntry($context, $src, $idx, $dest, $idx);
        $context->builder->branch($packedNext);

        $context->builder->positionAtEnd($packedNext);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($packedHead);

        $strInit = BasicBlockHelper::append($context, 'array_replace_overlay_str_init');
        $strHead = BasicBlockHelper::append($context, 'array_replace_overlay_str_head');
        $context->builder->positionAtEnd($packedDone);
        $context->builder->branch($strInit);

        $context->builder->positionAtEnd($strInit);
        $walkSlot = $context->builder->alloca($nodePtrType, 1, 'array_replace_overlay_walk');
        $head = $context->builder->load($context->builder->structGep($src, $map['strKeys']));
        $context->builder->store($head, $walkSlot);
        $strBody = BasicBlockHelper::append($context, 'array_replace_overlay_str_body');
        $strSet = BasicBlockHelper::append($context, 'array_replace_overlay_str_set');
        $strNext = BasicBlockHelper::append($context, 'array_replace_overlay_str_next');
        $strDone = BasicBlockHelper::append($context, 'array_replace_overlay_str_done');
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strHead);
        $node = $context->builder->load($walkSlot);
        $nodeNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($nodeNull, $strDone, $strBody);

        $context->builder->positionAtEnd($strBody);
        $valEntry = $context->builder->structGep($node, $nodeMap['value']);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $context->builder->branch($strSet);

        $context->builder->positionAtEnd($strSet);
        self::storeValueEntryAtStringKey($context, $dest, $keyStr, $valEntry);
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($strNext);
        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($nextNode, $walkSlot);
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strDone);
    }

    /**
     * array_intersect() for arrays of scalar values (loose compare; issue #1207).
     */
    public static function arrayIntersect(Context $context, Variable $first, Variable ...$others): Value
    {
        if (\count($others) < 1) {
            throw new \LogicException('array_intersect() requires at least two arguments');
        }
        $otherHts = [];
        foreach ($others as $other) {
            $otherHts[] = self::isNativeArray($other->type)
                ? self::nativeListToHashTable($context, $other)
                : self::loadHashTable($context, $other);
        }
        $src = self::isNativeArray($first->type)
            ? self::nativeListToHashTable($context, $first)
            : self::loadHashTable($context, $first);

        return self::arrayIntersectHashTable($context, $src, $otherHts);
    }

    /**
     * @param list<Value> $otherHts
     */
    private static function arrayIntersectHashTable(Context $context, Value $src, array $otherHts): Value
    {
        $dest = HashTableHelper::alloc($context);
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');
        $loose = $context->constantFromBool(false);

        $nextFree = $context->builder->load($context->builder->structGep($src, $map['nextFreeElement']));
        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_intersect_packed_idx');
        $context->builder->store($zero, $idxSlot);

        $packedHead = BasicBlockHelper::append($context, 'array_intersect_packed_head');
        $packedBody = BasicBlockHelper::append($context, 'array_intersect_packed_body');
        $packedKeep = BasicBlockHelper::append($context, 'array_intersect_packed_keep');
        $packedSkip = BasicBlockHelper::append($context, 'array_intersect_packed_skip');
        $packedAdd = BasicBlockHelper::append($context, 'array_intersect_packed_add');
        $packedNext = BasicBlockHelper::append($context, 'array_intersect_packed_next');
        $packedDone = BasicBlockHelper::append($context, 'array_intersect_packed_done');
        $context->builder->branch($packedHead);

        $context->builder->positionAtEnd($packedHead);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $packedDone, $packedBody);

        $context->builder->positionAtEnd($packedBody);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $src,
            $idx
        );
        $context->builder->branchIf($isSet, $packedKeep, $packedNext);

        $context->builder->positionAtEnd($packedKeep);
        $valEntry = self::listEntryAt($context, $src, $idx);
        $inAll = self::entryInAllHaystacks($context, $valEntry, $otherHts, $loose);
        $context->builder->branchIf($inAll, $packedAdd, $packedSkip);

        $context->builder->positionAtEnd($packedAdd);
        self::storeValueEntryAtIndex(
            $context,
            $dest,
            $idx,
            self::listEntryAt($context, $src, $idx)
        );
        $context->builder->branch($packedNext);

        $context->builder->positionAtEnd($packedSkip);
        $context->builder->branch($packedNext);

        $context->builder->positionAtEnd($packedNext);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($packedHead);

        $strInit = BasicBlockHelper::append($context, 'array_intersect_str_init');
        $strHead = BasicBlockHelper::append($context, 'array_intersect_str_head');
        $context->builder->positionAtEnd($packedDone);
        $context->builder->branch($strInit);

        $context->builder->positionAtEnd($strInit);
        $walkSlot = $context->builder->alloca($nodePtrType, 1, 'array_intersect_walk');
        $head = $context->builder->load($context->builder->structGep($src, $map['strKeys']));
        $context->builder->store($head, $walkSlot);
        $strBody = BasicBlockHelper::append($context, 'array_intersect_str_body');
        $strSkip = BasicBlockHelper::append($context, 'array_intersect_str_skip');
        $strAdd = BasicBlockHelper::append($context, 'array_intersect_str_add');
        $strNext = BasicBlockHelper::append($context, 'array_intersect_str_next');
        $strDone = BasicBlockHelper::append($context, 'array_intersect_str_done');
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strHead);
        $node = $context->builder->load($walkSlot);
        $nodeNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($nodeNull, $strDone, $strBody);

        $context->builder->positionAtEnd($strBody);
        $valEntry = $context->builder->structGep($node, $nodeMap['value']);
        $inAll = self::entryInAllHaystacks($context, $valEntry, $otherHts, $loose);
        $context->builder->branchIf($inAll, $strAdd, $strSkip);

        $context->builder->positionAtEnd($strAdd);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        self::storeValueEntryAtStringKey($context, $dest, $keyStr, $valEntry);
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($strSkip);
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($strNext);
        $next = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($next, $walkSlot);
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strDone);

        return $dest;
    }

    /**
     * @param list<Value> $otherHts
     */
    private static function entryInAllHaystacks(
        Context $context,
        Value $entry,
        array $otherHts,
        Value $strict
    ): Value {
        if ([] === $otherHts) {
            return $context->constantFromBool(true);
        }

        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($entry, $valueMap['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');
        $falseVal = $i1->constInt(0, false);
        $foundSlot = $context->builder->alloca($i1, 1, 'array_intersect_in_all');
        $context->builder->store($falseVal, $foundSlot);

        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING & 0xff, false)
        );
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );

        $stringBlock = BasicBlockHelper::append($context, 'array_intersect_in_all_string');
        $longBlock = BasicBlockHelper::append($context, 'array_intersect_in_all_long');
        $falseBlock = BasicBlockHelper::append($context, 'array_intersect_in_all_false');
        $mergeBlock = BasicBlockHelper::append($context, 'array_intersect_in_all_merge');

        $afterString = BasicBlockHelper::append($context, 'array_intersect_in_all_after_string');
        $context->builder->branchIf($isString, $stringBlock, $afterString);

        $context->builder->positionAtEnd($stringBlock);
        $needle = new Variable(
            $context,
            Variable::TYPE_STRING,
            Variable::KIND_VALUE,
            $context->builder->call($context->lookupFunction('__value__readString'), $entry)
        );
        $context->builder->store(
            self::entryInAllHaystacksNeedle($context, $needle, $otherHts, $strict),
            $foundSlot
        );
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($afterString);
        $context->builder->branchIf($isLong, $longBlock, $falseBlock);

        $context->builder->positionAtEnd($longBlock);
        $needle = new Variable(
            $context,
            Variable::TYPE_NATIVE_LONG,
            Variable::KIND_VALUE,
            $context->builder->call($context->lookupFunction('__value__readLong'), $entry)
        );
        $context->builder->store(
            self::entryInAllHaystacksNeedle($context, $needle, $otherHts, $strict),
            $foundSlot
        );
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($falseBlock);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);

        return $context->builder->load($foundSlot);
    }

    /**
     * @param list<Value> $otherHts
     */
    private static function entryInAllHaystacksNeedle(
        Context $context,
        Variable $needle,
        array $otherHts,
        Value $strict
    ): Value {
        $i1 = $context->getTypeFromString('int1');
        $n = \count($otherHts);
        if (0 === $n) {
            return $i1->constInt(1, false);
        }

        $resultSlot = $context->builder->alloca($i1, 1, 'array_intersect_in_all_needle_result');
        $failBlock = BasicBlockHelper::append($context, 'array_intersect_in_all_needle_fail');
        $okBlock = BasicBlockHelper::append($context, 'array_intersect_in_all_needle_ok');
        $mergeBlock = BasicBlockHelper::append($context, 'array_intersect_in_all_needle_merge');
        $checkBlocks = [];
        $nextBlocks = [];
        for ($i = 0; $i < $n; ++$i) {
            $checkBlocks[$i] = BasicBlockHelper::append($context, 'array_intersect_in_all_needle_ht_'.$i);
            $nextBlocks[$i] = $i + 1 < $n
                ? BasicBlockHelper::append($context, 'array_intersect_in_all_needle_next_'.$i)
                : $okBlock;
        }

        $context->builder->branch($checkBlocks[0]);
        for ($i = 0; $i < $n; ++$i) {
            $haystack = new Variable(
                $context,
                Variable::TYPE_HASHTABLE,
                Variable::KIND_VALUE,
                $otherHts[$i]
            );
            $context->builder->positionAtEnd($checkBlocks[$i]);
            $match = self::inArray($context, $needle, $haystack, $strict, 'intersect'.$i);
            $context->builder->branchIf($match, $nextBlocks[$i], $failBlock);
            if ($i + 1 < $n) {
                $context->builder->positionAtEnd($nextBlocks[$i]);
                $context->builder->branch($checkBlocks[$i + 1]);
            }
        }

        $context->builder->positionAtEnd($okBlock);
        $context->builder->store($i1->constInt(1, false), $resultSlot);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($failBlock);
        $context->builder->store($i1->constInt(0, false), $resultSlot);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);

        return $context->builder->load($resultSlot);
    }
}
