<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\ext\spl\SplHeapBuiltin;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT SplHeap / SplMaxHeap / SplMinHeap — object `__spl_heap` + Iterator (#26784).
 *
 * php-src: ext/spl/spl_heap.c — insert/extract + destructive foreach.
 */
final class SplHeapJitHelper
{
    public const PROP_HEAP = '__spl_heap';

    public const PROP_ITER_POS = '__spl_iter_pos';

    public const PROP_KIND = '__spl_kind';

    public static function compileConstruct(Context $context, JITVariable $receiver, int $kind): Value
    {
        $obj = self::loadObject($context, $receiver);
        $objectType = $context->type->object;
        $class = self::classNameForKind($kind);
        $ht = HashTableHelper::alloc($context);
        $htVar = new JITVariable($context, JITVariable::TYPE_HASHTABLE, JITVariable::KIND_VALUE, $ht);
        $slot = $objectType->propertySlotFor($obj, $class, self::PROP_HEAP);
        $objectType->propertyStore($slot, $htVar, JITVariable::TYPE_HASHTABLE);
        self::storeLongProperty($context, $obj, $class, self::PROP_ITER_POS, -1);
        self::storeLongProperty($context, $obj, $class, self::PROP_KIND, $kind);
        $objectType->markObjectConstructed($obj);

        return self::voidResult($context);
    }

    public static function compileInsert(Context $context, JITVariable $receiver, JITVariable $value): Value
    {
        $obj = self::loadObject($context, $receiver);
        $class = self::classNameFromObject($context, $obj);
        $htVar = $context->type->object->splBackingHashtable(
            new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $obj)
        );
        // Always append via runtime nextFreeElement — heap mutates under foreach (#26784).
        $htVar->nextFreeElementFromRuntime = true;
        HashTableHelper::addElement($context, $htVar, $value, null);
        $ht = $context->helper->loadValue($htVar);
        // Keep index 0 as heap top via packed sort (spaceship sift is fragile under thin AOT).
        // Max → reverse sort; Min → ascending (#26784, ext/spl/spl_heap.c extract order).
        $kind = self::loadKind($context, $obj, $class);
        $i64 = $context->getTypeFromString('int64');
        $kind64 = $context->builder->truncOrBitCast($kind, $i64);
        $isMin = $context->builder->icmp(Builder::INT_SLT, $kind64, $i64->constInt(0, false));
        $minBb = BasicBlockHelper::append($context, 'splheap_insert_minsort');
        $maxBb = BasicBlockHelper::append($context, 'splheap_insert_maxsort');
        $done = BasicBlockHelper::append($context, 'splheap_insert_sortdone');
        $context->builder->branchIf($isMin, $minBb, $maxBb);
        $context->builder->positionAtEnd($minBb);
        $context->builder->call($context->lookupFunction('__hashtable__sortPacked'), $ht);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($maxBb);
        $context->builder->call($context->lookupFunction('__hashtable__sortPackedReverse'), $ht);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);

        return self::voidResult($context);
    }

    public static function compileCount(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $ht = self::heapPtr($context, $obj);
        $map = $context->structFieldMap['__hashtable__'];
        $n = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong(
            $context,
            $slot,
            $context->builder->truncOrBitCast($n, $context->getTypeFromString('int64'))
        );

        return $slot;
    }

    /**
     * SplHeap::extract() — return + remove heap top (#27276, ext/spl/spl_heap.c).
     *
     * Empty heap: Zend throws RuntimeException; thin AOT returns null (same trade-off as
     * SplDllistJitHelper::compilePop — empty throw stays VM-covered).
     */
    public static function compileExtract(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $class = self::classNameFromObject($context, $obj);
        $ht = self::heapPtr($context, $obj);
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $n = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $out = JitValueBox::alloc($context);
        $emptyBb = BasicBlockHelper::append($context, 'splheap_extract_method_empty');
        $bodyBb = BasicBlockHelper::append($context, 'splheap_extract_method_body');
        $doneBb = BasicBlockHelper::append($context, 'splheap_extract_method_done');
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $n, $sizeT->constInt(0, false));
        $context->builder->branchIf($isEmpty, $emptyBb, $bodyBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $out)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($bodyBb);
        $fetched = HashTableHelper::readIndexedToValueBox(
            $context,
            $ht,
            $sizeT->constInt(0, false)
        );
        JitValueBox::copyFromPointer(
            $context,
            $out,
            JitValueBox::valuePtrFromVariable($context, $fetched)
        );
        self::extractTop($context, $obj, $class);
        self::storeLongProperty($context, $obj, $class, self::PROP_ITER_POS, -1);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $out;
    }

    /**
     * SplHeap::top() — peek heap top without removing (#27276).
     * Empty: thin AOT returns null (Zend throws; VM covers throw).
     */
    public static function compileTop(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $ht = self::heapPtr($context, $obj);
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $n = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $out = JitValueBox::alloc($context);
        $emptyBb = BasicBlockHelper::append($context, 'splheap_top_empty');
        $readBb = BasicBlockHelper::append($context, 'splheap_top_read');
        $doneBb = BasicBlockHelper::append($context, 'splheap_top_done');
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $n, $sizeT->constInt(0, false));
        $context->builder->branchIf($isEmpty, $emptyBb, $readBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $out)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($readBb);
        $fetched = HashTableHelper::readIndexedToValueBox(
            $context,
            $ht,
            $sizeT->constInt(0, false)
        );
        JitValueBox::copyFromPointer(
            $context,
            $out,
            JitValueBox::valuePtrFromVariable($context, $fetched)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $out;
    }

    public static function compileRewind(Context $context, JITVariable $receiver): Value
    {
        // php-src spl_heap_it_rewind is a no-op; valid/key derive from count (#31600).
        unset($receiver);

        return self::voidResult($context);
    }

    public static function compileValid(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $ht = self::heapPtr($context, $obj);
        $map = $context->structFieldMap['__hashtable__'];
        $n = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $sizeT = $context->getTypeFromString('size_t');
        // php-src spl_heap_it_valid: heap->count != 0 (#31600).
        $nonEmpty = $context->builder->icmp(Builder::INT_UGT, $n, $sizeT->constInt(0, false));
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $nonEmpty);

        return $slot;
    }

    public static function compileCurrent(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $ht = self::heapPtr($context, $obj);
        $map = $context->structFieldMap['__hashtable__'];
        $n = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $sizeT = $context->getTypeFromString('size_t');
        $out = JitValueBox::alloc($context);
        $emptyBb = BasicBlockHelper::append($context, 'splheap_current_empty');
        $readBb = BasicBlockHelper::append($context, 'splheap_current_read');
        $merge = BasicBlockHelper::append($context, 'splheap_current_merge');
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $n, $sizeT->constInt(0, false));
        $context->builder->branchIf($isEmpty, $emptyBb, $readBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $out)
        );
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($readBb);
        $fetched = HashTableHelper::readIndexedToValueBox(
            $context,
            $ht,
            $sizeT->constInt(0, false)
        );
        JitValueBox::copyFromPointer(
            $context,
            $out,
            JitValueBox::valuePtrFromVariable($context, $fetched)
        );
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);

        return $out;
    }

    public static function compileKey(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $ht = self::heapPtr($context, $obj);
        $map = $context->structFieldMap['__hashtable__'];
        $n = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $n64 = $context->builder->truncOrBitCast($n, $i64);
        // php-src spl_heap_it_get_current_key: count - 1 (#22290 / #31600).
        $pos = $context->builder->sub($n64, $i64->constInt(1, false));
        $empty = $context->builder->icmp(Builder::INT_EQ, $n, $sizeT->constInt(0, false));
        $pos = $context->builder->select($empty, $i64->constInt(-1, true), $pos);
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong($context, $slot, $pos);

        return $slot;
    }

    public static function compileNext(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $class = self::classNameFromObject($context, $obj);
        $ht = self::heapPtr($context, $obj);
        $map = $context->structFieldMap['__hashtable__'];
        $n = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $sizeT = $context->getTypeFromString('size_t');
        $skip = BasicBlockHelper::append($context, 'splheap_next_skip');
        $doExtract = BasicBlockHelper::append($context, 'splheap_next_extract');
        $done = BasicBlockHelper::append($context, 'splheap_next_done');
        $nonEmpty = $context->builder->icmp(Builder::INT_UGT, $n, $sizeT->constInt(0, false));
        $context->builder->branchIf($nonEmpty, $doExtract, $skip);

        $context->builder->positionAtEnd($skip);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($doExtract);
        self::extractTop($context, $obj, $class);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);

        return self::voidResult($context);
    }

    private static function extractTop(Context $context, Value $obj, string $class): void
    {
        $ht = self::heapPtr($context, $obj);
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $n = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $fn = $context->builder->getInsertBlock()->getParent();
        $emptyBb = BasicBlockHelper::append($context, 'splheap_extract_empty');
        $bodyBb = BasicBlockHelper::append($context, 'splheap_extract_body');
        $doneBb = BasicBlockHelper::append($context, 'splheap_extract_done');
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $n, $sizeT->constInt(0, false));
        $context->builder->branchIf($isEmpty, $emptyBb, $bodyBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($bodyBb);
        $lastIdx = $context->builder->sub($n, $sizeT->constInt(1, false));
        $onlyOne = $context->builder->icmp(Builder::INT_EQ, $n, $sizeT->constInt(1, false));
        $moveBb = BasicBlockHelper::append($context, 'splheap_extract_move');
        $shrinkBb = BasicBlockHelper::append($context, 'splheap_extract_shrink');
        $context->builder->branchIf($onlyOne, $shrinkBb, $moveBb);

        $context->builder->positionAtEnd($moveBb);
        // Deep-copy last before mutating HT — readIndexed copies to a stack box; setAtIndex
        // then writes by value into index 0 (#27276 MinHeap / sortPacked hole).
        $last = HashTableHelper::readIndexedToValueBox($context, $ht, $lastIdx);
        HashTableHelper::setAtIndex($context, $ht, $sizeT->constInt(0, false), $last);
        $context->builder->branch($shrinkBb);

        $context->builder->positionAtEnd($shrinkBb);
        $context->builder->call(
            $context->lookupFunction('__hashtable__unsetLongAt'),
            $ht,
            $lastIdx
        );
        // unsetLongAt nulls the slot and decrements numElements but leaves nextFreeElement
        // stale. sortPacked/sortPackedReverse iterate nextFreeElement, so a trailing NULL
        // was sorted into index 0 under ascending (MinHeap) while reverse (MaxHeap) left
        // the real value on top (#27276).
        $nAfter = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $context->builder->store(
            $nAfter,
            $context->builder->structGep($ht, $map['nextFreeElement'])
        );
        $needSort = $context->builder->icmp(Builder::INT_UGT, $nAfter, $sizeT->constInt(1, false));
        $sortBb = BasicBlockHelper::append($context, 'splheap_extract_sort');
        $context->builder->branchIf($needSort, $sortBb, $doneBb);

        $context->builder->positionAtEnd($sortBb);
        $kind = self::loadKind($context, $obj, $class);
        $i64 = $context->getTypeFromString('int64');
        $kind64 = $context->builder->truncOrBitCast($kind, $i64);
        $isMin = $context->builder->icmp(Builder::INT_SLT, $kind64, $i64->constInt(0, false));
        $minBb = BasicBlockHelper::append($context, 'splheap_extract_minsort');
        $maxBb = BasicBlockHelper::append($context, 'splheap_extract_maxsort');
        $context->builder->branchIf($isMin, $minBb, $maxBb);
        $context->builder->positionAtEnd($minBb);
        $context->builder->call($context->lookupFunction('__hashtable__sortPacked'), $ht);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($maxBb);
        $context->builder->call($context->lookupFunction('__hashtable__sortPackedReverse'), $ht);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
    }

    private static function heapPtr(Context $context, Value $obj): Value
    {
        return $context->helper->loadValue(
            $context->type->object->splBackingHashtable(
                new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $obj)
            )
        );
    }

    private static function loadKind(Context $context, Value $obj, string $class): Value
    {
        return self::loadLongProperty($context, $obj, $class, self::PROP_KIND);
    }

    private static function classNameForKind(int $kind): string
    {
        return match ($kind) {
            SplHeapBuiltin::KIND_MAX => 'SplMaxHeap',
            SplHeapBuiltin::KIND_MIN => 'SplMinHeap',
            default => 'SplHeap',
        };
    }

    private static function classNameFromObject(Context $context, Value $obj): string
    {
        // Slot layouts match across SplHeap / SplMaxHeap / SplMinHeap (#26784).
        return 'SplMaxHeap';
    }

    private static function loadObject(Context $context, JITVariable $receiver): Value
    {
        if (JITVariable::TYPE_OBJECT === $receiver->type) {
            return $context->helper->loadValue($receiver);
        }
        if (JITVariable::TYPE_VALUE === $receiver->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $receiver)
            );
        }

        throw new \LogicException('SplHeap method requires an object receiver');
    }

    private static function storeLongProperty(
        Context $context,
        Value $obj,
        string $class,
        string $prop,
        int $value
    ): void {
        $i64 = $context->getTypeFromString('int64');
        self::storeLongPropertyValue($context, $obj, $class, $prop, $i64->constInt($value, true));
    }

    private static function storeLongPropertyValue(
        Context $context,
        Value $obj,
        string $class,
        string $prop,
        Value $value
    ): void {
        $objectType = $context->type->object;
        $slot = $objectType->propertySlotFor($obj, $class, $prop);
        $var = new JITVariable($context, JITVariable::TYPE_NATIVE_LONG, JITVariable::KIND_VALUE, $value);
        $objectType->propertyStore($slot, $var, JITVariable::TYPE_NATIVE_LONG);
    }

    private static function loadLongProperty(
        Context $context,
        Value $obj,
        string $class,
        string $prop
    ): Value {
        $slot = $context->type->object->propertyFetch($obj, $class, $prop);

        return $context->helper->loadValue($slot);
    }

    private static function voidResult(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }
}
