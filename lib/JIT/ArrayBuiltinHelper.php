<?php

declare(strict_types=1);

/**
 * LLVM helpers for stdlib array builtins (packed __hashtable__).
 */

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\boolval;
use PHPCompiler\ext\standard\floatval;
use PHPCompiler\ext\standard\intval;
use PHPCompiler\ext\standard\strval;
use PHPCompiler\ext\standard\string_ltrim;
use PHPCompiler\ext\standard\string_rtrim;
use PHPCompiler\ext\standard\string_trim;
use PHPCompiler\ext\standard\strtolower;
use PHPCompiler\ext\standard\strtoupper;
use PHPCompiler\ext\standard\VmInternalCall;
use PHPCompiler\ext\types\strlen;
use PHPCompiler\Func\Internal;
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
            throw new \LogicException(
                'array_map() callback must be a compile-time string builtin name in this compiler build'
            );
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
        static $seq = 0;
        $tag = 'mn'.(string) ++$seq;
        $srcEntry = self::listEntryAt($context, $src, $srcIndex);
        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($srcEntry, $valueMap['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $stringBlock = BasicBlockHelper::append($context, 'array_map_null_copy_str_'.$tag);
        $longBlock = BasicBlockHelper::append($context, 'array_map_null_copy_long_'.$tag);
        $done = BasicBlockHelper::append($context, 'array_map_null_copy_done_'.$tag);

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
        $str = $context->builder->call(
            $context->lookupFunction('__hashtable__readStringAt'),
            $src,
            $srcIndex
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringAt'),
            $dest,
            $destIndex,
            $str
        );
    }

    private static function listEntryTruthy(Context $context, Value $entry): Value
    {
        static $seq = 0;
        $tag = 'ft'.(string) ++$seq;
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
        $result = HashTableHelper::alloc($context);
        foreach ($arrays as $array) {
            $ht = self::isNativeArray($array->type)
                ? self::nativeListToHashTable($context, $array)
                : self::loadHashTable($context, $array);
            self::copyInto($context, $result, $ht);
        }

        return $result;
    }

    /**
     * array_combine() for packed list arrays (subset of PHP; returns __value__*).
     *
     * @return Value __value__* (hashtable on success, boolean false when lengths differ)
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
        $ht = self::isNativeArray($haystack->type)
            ? self::nativeListToHashTable($context, $haystack)
            : self::loadHashTable($context, $haystack);
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
        $entry = self::listEntryAt($context, $ht, $idx);
        $match = self::entryMatchesNeedle($context, $entry, $needle, $strict);
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

    /**
     * array_search() for packed lists and string-keyed assoc arrays (subset of PHP).
     *
     * @return Value __value__* (key as long/string, or boolean false)
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
        $strRemainSlot = $context->builder->alloca($sizeT, 1, 'array_search_str_remain');
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
        $context->builder->store($numStrKeys, $strRemainSlot);
        $strBody = BasicBlockHelper::append($context, 'array_search_str_body');
        $strFound = BasicBlockHelper::append($context, 'array_search_str_found');
        $strNext = BasicBlockHelper::append($context, 'array_search_str_next');
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strHead);
        $remain = $context->builder->load($strRemainSlot);
        $remainZero = $context->builder->icmp(Builder::INT_EQ, $remain, $zero);
        $strDrain = BasicBlockHelper::append($context, 'array_search_str_drain');
        $context->builder->branchIf($remainZero, $strDrain, $strBody);

        $context->builder->positionAtEnd($strDrain);
        $nodesArray = $context->builder->load($nodesSlot);
        $nodesRaw = $context->builder->pointerCast($nodesArray, $context->getTypeFromString('int8*'));
        $context->builder->call($context->lookupFunction('free'), $nodesRaw);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($strBody);
        $nodeIdx = $context->builder->subNoSignedWrap($remain, $one);
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
        $context->builder->store($nodeIdx, $strRemainSlot);
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
        $context->builder->branchIf($isDouble, $bbDouble, $bbNull);

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

        return $context->constantFromBool(false);
    }

    private static function looseEqualStringLong(Context $context, Variable $str, Variable $long): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $numBuf = $context->builder->alloca($context->getTypeFromString('int8'), $i64->constInt(32, false), 'loose_strlong_buf');
        $num = $context->helper->loadValue($long);
        $bufC = $context->builder->pointerCast($numBuf, $i8p);
        $fmt = $context->builder->pointerCast($context->constantFromString('%lld'), $i8p);
        $context->builder->call($context->lookupFunction('sprintf'), $bufC, $fmt, $num);
        $len = $context->builder->call($context->lookupFunction('strlen'), $bufC);
        $lenI64 = $len->typeOf() === $i64
            ? $len
            : $context->builder->zExt($len, $i64);
        $numStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $lenI64,
            $bufC
        );

        return JitStringCompare::identical(
            $context,
            $context->helper->loadValue($str),
            $numStr
        );
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
        $context->builder->branchIf($isDouble, $doubleBlock, $continueBlock);

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
        $context->builder->branchIf($isDouble, $doubleBlock, $continueBlock);

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
        $context->builder->branchIf($isDouble, $doubleBlock, $continueBlock);

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
        $context->builder->branchIf($isDouble, $doubleBlock, $continueBlock);

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
     * array_unique() for arrays of scalar values (strict identity; subset of PHP).
     */
    public static function arrayUnique(Context $context, Variable $array): Value
    {
        if (self::isNativeArray($array->type)) {
            return self::arrayUniqueHashTable($context, self::nativeListToHashTable($context, $array));
        }

        return self::arrayUniqueHashTable($context, self::loadHashTable($context, $array));
    }

    private static function arrayUniqueHashTable(Context $context, Value $src): Value
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
        $duplicate = self::destContainsPackedEntry($context, $dest, $valEntry);
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
        $duplicate = self::destContainsPackedEntry($context, $dest, $valEntry);
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
     * Strict duplicate check against a packed hashtable (reuses in_array lowering).
     */
    private static function destContainsPackedEntry(Context $context, Value $dest, Value $entry): Value
    {
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
