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
        $context->builder->branchIf($match, $foundBlock, $head);

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
