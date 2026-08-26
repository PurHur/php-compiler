<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\ext\standard\JitIntdiv;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\ReflectionBuiltinHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT SplDoublyLinkedList / SplQueue / SplStack — object `__spl_ht` deque (#26790).
 *
 * php-src: ext/spl/spl_dllist.c — push/pop/shift/unshift/top/bottom; SplQueue enqueue/dequeue; SplStack push/pop/top.
 * Serialize bag: flags + dllist + members (#33966; peer #33625 / #33876).
 */
final class SplDllistJitHelper
{
    public const PROP_HT = '__spl_ht';

    /** Iterator mode flags (IT_MODE_*); peer SplPriorityQueue `__spl_flags` (#33987). */
    public const PROP_FLAGS = '__spl_flags';

    /**
     * Iterator cursor for rewind/valid/current/key/next (#34976).
     * -1 = not started / exhausted (LIFO); matches unset traverse_pointer until rewind.
     */
    public const PROP_ITER_POS = '__spl_iter_pos';

    public const IT_MODE_DELETE = 1;

    public const IT_MODE_LIFO = 2;

    public const IT_MODE_FIX = 4;

    public const IT_MODE_MASK = 3;

    public static function compileConstruct(Context $context, JITVariable $receiver, string $className): Value
    {
        $obj = self::loadObject($context, $receiver);
        $objectType = $context->type->object;
        $ht = HashTableHelper::alloc($context);
        $htVar = new JITVariable($context, JITVariable::TYPE_HASHTABLE, JITVariable::KIND_VALUE, $ht);
        $slot = $objectType->propertySlotFor($obj, $className, self::PROP_HT);
        $objectType->propertyStore($slot, $htVar, JITVariable::TYPE_HASHTABLE);
        // php-src: SplQueue FIX|FIFO; SplStack FIX|LIFO; SplDoublyLinkedList FIFO (#33987).
        $flags = 0;
        $lc = strtolower($className);
        if ('splstack' === $lc) {
            $flags = self::IT_MODE_LIFO | self::IT_MODE_FIX;
        } elseif ('splqueue' === $lc) {
            $flags = self::IT_MODE_FIX;
        }
        self::storeLongProperty($context, $obj, $className, self::PROP_FLAGS, $flags);
        // php-src: traverse_pointer NULL until rewind → valid() false (#34976).
        self::storeLongProperty($context, $obj, $className, self::PROP_ITER_POS, -1);
        $objectType->markObjectConstructed($obj);

        return self::voidResult($context);
    }

    public static function compilePush(Context $context, JITVariable $receiver, JITVariable $value): Value
    {
        $obj = self::loadObject($context, $receiver);
        $htVar = self::htVar($context, $obj);
        $htVar->nextFreeElementFromRuntime = true;
        HashTableHelper::addElement($context, $htVar, $value, null);

        return self::voidResult($context);
    }

    /** SplQueue::enqueue ≡ push. */
    public static function compileEnqueue(Context $context, JITVariable $receiver, JITVariable $value): Value
    {
        return self::compilePush($context, $receiver, $value);
    }

    /**
     * Insert at front of packed `__spl_ht` (php-src spl_ptr_llist_unshift / #27311).
     *
     * Grow with push, then rotate slots right so index 0 holds $value.
     */
    public static function compileUnshift(Context $context, JITVariable $receiver, JITVariable $value): Value
    {
        $obj = self::loadObject($context, $receiver);
        $ht = self::htPtr($context, $obj);
        $htVar = self::htVar($context, $obj);
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $oldN = $context->builder->load($context->builder->structGep($ht, $map['numElements']));

        // Append a slot (becomes temporary last element), then slide [0..oldN) → [1..oldN].
        $htVar->nextFreeElementFromRuntime = true;
        HashTableHelper::addElement($context, $htVar, $value, null);

        $iSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($oldN, $iSlot);
        $condBb = BasicBlockHelper::append($context, 'spldllist_unshift_cond');
        $moveBb = BasicBlockHelper::append($context, 'spldllist_unshift_move');
        $headBb = BasicBlockHelper::append($context, 'spldllist_unshift_head');
        $context->builder->branch($condBb);

        $context->builder->positionAtEnd($condBb);
        $i = $context->builder->load($iSlot);
        $more = $context->builder->icmp(
            Builder::INT_UGT,
            $i,
            $sizeT->constInt(0, false)
        );
        $context->builder->branchIf($more, $moveBb, $headBb);

        $context->builder->positionAtEnd($moveBb);
        $prev = $context->builder->sub($i, $sizeT->constInt(1, false));
        $elem = HashTableHelper::readIndexedToValueBox($context, $ht, $prev);
        HashTableHelper::setAtIndex($context, $ht, $i, $elem);
        $context->builder->store($prev, $iSlot);
        $context->builder->branch($condBb);

        $context->builder->positionAtEnd($headBb);
        HashTableHelper::setAtIndex($context, $ht, $sizeT->constInt(0, false), $value);

        return self::voidResult($context);
    }

    public static function compilePop(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $ht = self::htPtr($context, $obj);
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $n = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $out = JitValueBox::alloc($context);
        $emptyBb = BasicBlockHelper::append($context, 'spldllist_pop_empty');
        $bodyBb = BasicBlockHelper::append($context, 'spldllist_pop_body');
        $doneBb = BasicBlockHelper::append($context, 'spldllist_pop_done');
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $n, $sizeT->constInt(0, false));
        $context->builder->branchIf($isEmpty, $emptyBb, $bodyBb);

        $context->builder->positionAtEnd($emptyBb);
        // Match Zend RuntimeException message only when thrown — thin AOT returns null on empty
        // for the silent-wrong-output fix (#26790); empty throw path is VM-covered.
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $out)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($bodyBb);
        $lastIdx = $context->builder->sub($n, $sizeT->constInt(1, false));
        $fetched = HashTableHelper::readIndexedToValueBox($context, $ht, $lastIdx);
        JitValueBox::copyFromPointer(
            $context,
            $out,
            JitValueBox::valuePtrFromVariable($context, $fetched)
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__unsetLongAt'),
            $ht,
            $lastIdx
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $out;
    }

    /**
     * SplDoublyLinkedList::top / SplStack::top — peek last packed slot without removal (#28704).
     * Empty: thin AOT returns null (Zend throws; VM covers throw).
     */
    public static function compileTop(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $ht = self::htPtr($context, $obj);
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $n = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $out = JitValueBox::alloc($context);
        $emptyBb = BasicBlockHelper::append($context, 'spldllist_top_empty');
        $bodyBb = BasicBlockHelper::append($context, 'spldllist_top_body');
        $doneBb = BasicBlockHelper::append($context, 'spldllist_top_done');
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $n, $sizeT->constInt(0, false));
        $context->builder->branchIf($isEmpty, $emptyBb, $bodyBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $out)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($bodyBb);
        $lastIdx = $context->builder->sub($n, $sizeT->constInt(1, false));
        $fetched = HashTableHelper::readIndexedToValueBox($context, $ht, $lastIdx);
        JitValueBox::copyFromPointer(
            $context,
            $out,
            JitValueBox::valuePtrFromVariable($context, $fetched)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $out;
    }

    /**
     * SplDoublyLinkedList::bottom — peek first packed slot without removal (#28704).
     * Empty: thin AOT returns null (Zend throws; VM covers throw).
     */
    public static function compileBottom(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $ht = self::htPtr($context, $obj);
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $n = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $out = JitValueBox::alloc($context);
        $emptyBb = BasicBlockHelper::append($context, 'spldllist_bottom_empty');
        $bodyBb = BasicBlockHelper::append($context, 'spldllist_bottom_body');
        $doneBb = BasicBlockHelper::append($context, 'spldllist_bottom_done');
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
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $out;
    }

    public static function compileShift(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $ht = self::htPtr($context, $obj);
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $n = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $out = JitValueBox::alloc($context);
        $emptyBb = BasicBlockHelper::append($context, 'spldllist_shift_empty');
        $bodyBb = BasicBlockHelper::append($context, 'spldllist_shift_body');
        $doneBb = BasicBlockHelper::append($context, 'spldllist_shift_done');
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $n, $sizeT->constInt(0, false));
        $context->builder->branchIf($isEmpty, $emptyBb, $bodyBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $out)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($bodyBb);
        $zero = $sizeT->constInt(0, false);
        $fetched = HashTableHelper::readIndexedToValueBox($context, $ht, $zero);
        JitValueBox::copyFromPointer(
            $context,
            $out,
            JitValueBox::valuePtrFromVariable($context, $fetched)
        );

        // Move packed slots [1..n) → [0..n-1); then unset last.
        $iSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $iSlot);
        $condBb = BasicBlockHelper::append($context, 'spldllist_shift_cond');
        $moveBb = BasicBlockHelper::append($context, 'spldllist_shift_move');
        $shrinkBb = BasicBlockHelper::append($context, 'spldllist_shift_shrink');
        $context->builder->branch($condBb);

        $context->builder->positionAtEnd($condBb);
        $i = $context->builder->load($iSlot);
        $limit = $context->builder->sub($n, $sizeT->constInt(1, false));
        $more = $context->builder->icmp(Builder::INT_ULT, $i, $limit);
        $context->builder->branchIf($more, $moveBb, $shrinkBb);

        $context->builder->positionAtEnd($moveBb);
        $next = $context->builder->add($i, $sizeT->constInt(1, false));
        $elem = HashTableHelper::readIndexedToValueBox($context, $ht, $next);
        HashTableHelper::setAtIndex($context, $ht, $i, $elem);
        $context->builder->store($next, $iSlot);
        $context->builder->branch($condBb);

        $context->builder->positionAtEnd($shrinkBb);
        $lastIdx = $context->builder->sub($n, $sizeT->constInt(1, false));
        $context->builder->call(
            $context->lookupFunction('__hashtable__unsetLongAt'),
            $ht,
            $lastIdx
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $out;
    }

    /** SplQueue::dequeue ≡ shift. */
    public static function compileDequeue(Context $context, JITVariable $receiver): Value
    {
        return self::compileShift($context, $receiver);
    }

    /**
     * php-src zim_SplDoublyLinkedList_count — zend_hash_num_elements on storage (#32910).
     */
    public static function compileCount(Context $context, JITVariable $receiver): Value
    {
        $ht = self::htPtr($context, self::loadObject($context, $receiver));
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
     * php-src zim_SplDoublyLinkedList_isEmpty — zend_hash_num_elements == 0 (#33973).
     * Without a thin-AOT proxy, unbound isEmpty lowered to null (#579) so drained queues
     * always looked non-empty in boolean context.
     */
    public static function compileIsEmpty(Context $context, JITVariable $receiver): Value
    {
        $ht = self::htPtr($context, self::loadObject($context, $receiver));
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $n = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $empty = $context->builder->icmp(Builder::INT_EQ, $n, $sizeT->constInt(0, false));
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $empty);

        return $slot;
    }


    /**
     * php-src zim_SplDoublyLinkedList_offsetGet — packed `__spl_ht` index (#33987).
     * Thin AOT without proxy silent-nulled (#579).
     */
    public static function compileOffsetGet(Context $context, JITVariable $receiver, JITVariable $index): Value
    {
        $obj = self::loadObject($context, $receiver);
        $ht = self::htPtr($context, $obj);
        $idx = self::coerceIndex($context, $index, 'SplDoublyLinkedList::offsetGet');
        self::assertIndexInRange($context, $ht, $idx, 'SplDoublyLinkedList::offsetGet');

        return HashTableHelper::readIndexedToValueBox($context, $ht, $idx)->value;
    }

    /**
     * php-src zim_SplDoublyLinkedList_offsetExists — in-range index (#33987).
     */
    public static function compileOffsetExists(Context $context, JITVariable $receiver, JITVariable $index): Value
    {
        $obj = self::loadObject($context, $receiver);
        $ht = self::htPtr($context, $obj);
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $n = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $idx = self::coerceIndex($context, $index, 'SplDoublyLinkedList::offsetExists');
        $inRange = $context->builder->icmp(Builder::INT_ULT, $idx, $n);
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $inRange);

        return $slot;
    }

    /**
     * php-src zim_SplDoublyLinkedList_offsetSet — null index appends (#31731 / #33987).
     */
    public static function compileOffsetSet(
        Context $context,
        JITVariable $receiver,
        JITVariable $index,
        JITVariable $value
    ): Value {
        // null / omitted index → push (php-src write_dimension).
        if (JITVariable::TYPE_NULL === $index->type) {
            return self::compilePush($context, $receiver, $value);
        }
        $obj = self::loadObject($context, $receiver);
        $ht = self::htPtr($context, $obj);
        $idx = self::coerceIndex($context, $index, 'SplDoublyLinkedList::offsetSet');
        self::assertIndexInRange($context, $ht, $idx, 'SplDoublyLinkedList::offsetSet');
        HashTableHelper::setAtIndex($context, $ht, $idx, $value);

        return self::voidResult($context);
    }

    /**
     * php-src zim_SplDoublyLinkedList_offsetUnset — splice packed slot (#33987).
     */
    public static function compileOffsetUnset(Context $context, JITVariable $receiver, JITVariable $index): Value
    {
        $obj = self::loadObject($context, $receiver);
        $ht = self::htPtr($context, $obj);
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $n = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $idx = self::coerceIndex($context, $index, 'SplDoublyLinkedList::offsetUnset');
        self::assertIndexInRange($context, $ht, $idx, 'SplDoublyLinkedList::offsetUnset');

        // Slide [idx+1..n) → [idx..n-1); then unset last (same shape as compileShift).
        $iSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($idx, $iSlot);
        $condBb = BasicBlockHelper::append($context, 'spldllist_unset_cond');
        $moveBb = BasicBlockHelper::append($context, 'spldllist_unset_move');
        $shrinkBb = BasicBlockHelper::append($context, 'spldllist_unset_shrink');
        $doneBb = BasicBlockHelper::append($context, 'spldllist_unset_done');
        $context->builder->branch($condBb);

        $context->builder->positionAtEnd($condBb);
        $i = $context->builder->load($iSlot);
        $limit = $context->builder->sub($n, $sizeT->constInt(1, false));
        $more = $context->builder->icmp(Builder::INT_ULT, $i, $limit);
        $context->builder->branchIf($more, $moveBb, $shrinkBb);

        $context->builder->positionAtEnd($moveBb);
        $next = $context->builder->add($i, $sizeT->constInt(1, false));
        $elem = HashTableHelper::readIndexedToValueBox($context, $ht, $next);
        HashTableHelper::setAtIndex($context, $ht, $i, $elem);
        $context->builder->store($next, $iSlot);
        $context->builder->branch($condBb);

        $context->builder->positionAtEnd($shrinkBb);
        $lastIdx = $context->builder->sub($n, $sizeT->constInt(1, false));
        $context->builder->call(
            $context->lookupFunction('__hashtable__unsetLongAt'),
            $ht,
            $lastIdx
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return self::voidResult($context);
    }

    /**
     * php-src zim_SplDoublyLinkedList_setIteratorMode (#33987).
     * FIX bit freezes LIFO/FIFO for SplQueue/SplStack.
     */
    public static function compileSetIteratorMode(
        Context $context,
        JITVariable $receiver,
        JITVariable $modeArg,
        string $className
    ): Value {
        $obj = self::loadObject($context, $receiver);
        $i64 = $context->getTypeFromString('int64');
        $mode = JitLongArg::lower($context, $modeArg, 'SplDoublyLinkedList::setIteratorMode');
        $current = self::loadLongProperty($context, $obj, $className, self::PROP_FLAGS);
        $fix = $i64->constInt(self::IT_MODE_FIX, false);
        $lifo = $i64->constInt(self::IT_MODE_LIFO, false);
        $mask = $i64->constInt(self::IT_MODE_MASK, false);
        $hasFix = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->and($current, $fix),
            $i64->constInt(0, false)
        );
        $curLifo = $context->builder->and($current, $lifo);
        $newLifo = $context->builder->and($mode, $lifo);
        $lifoChanged = $context->builder->icmp(Builder::INT_NE, $curLifo, $newLifo);
        $forbidden = $context->builder->and($hasFix, $lifoChanged);
        $badBb = BasicBlockHelper::append($context, 'spldllist_mode_fix');
        $okBb = BasicBlockHelper::append($context, 'spldllist_mode_ok');
        $context->builder->branchIf($forbidden, $badBb, $okBb);

        $context->builder->positionAtEnd($badBb);
        $msg = "Iterators' LIFO/FIFO modes for SplStack/SplQueue objects cannot be changed";
        $context->builder->call(
            $context->lookupFunction('__compiler_jit_raise_logic_exception'),
            $context->builder->pointerCast(
                $context->constantFromString($msg),
                $context->getTypeFromString('int8*')
            ),
            $context->constantFromInteger(\strlen($msg), 'size_t')
        );
        $context->builder->branch($okBb);

        $context->builder->positionAtEnd($okBb);
        $stored = $context->builder->or(
            $context->builder->and($mode, $mask),
            $context->builder->and($current, $fix)
        );
        self::storeLongPropertyValue($context, $obj, $className, self::PROP_FLAGS, $stored);
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong($context, $slot, $stored);

        return $slot;
    }

    /**
     * php-src zim_SplDoublyLinkedList_getIteratorMode (#33987).
     */
    public static function compileGetIteratorMode(
        Context $context,
        JITVariable $receiver,
        string $className
    ): Value {
        $obj = self::loadObject($context, $receiver);
        $flags = self::loadLongProperty($context, $obj, $className, self::PROP_FLAGS);
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong($context, $slot, $flags);

        return $slot;
    }

    /** Load `__spl_flags` for foreach LIFO reset (#33987 / #28705). */
    public static function loadFlags(Context $context, Value $obj, string $className): Value
    {
        return self::loadLongProperty($context, $obj, $className, self::PROP_FLAGS);
    }

    /**
     * php-src zim_SplDoublyLinkedList_rewind / spl_dllist_it_helper_rewind (#34976).
     * LIFO → last index; FIFO → 0; empty → -1.
     */
    public static function compileRewind(Context $context, JITVariable $receiver, string $className): Value
    {
        $obj = self::loadObject($context, $receiver);
        $ht = self::htPtr($context, $obj);
        $map = $context->structFieldMap['__hashtable__'];
        $n = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $n64 = $context->builder->truncOrBitCast($n, $i64);
        $empty = $context->builder->icmp(Builder::INT_EQ, $n, $sizeT->constInt(0, false));
        $flags = self::loadLongProperty($context, $obj, $className, self::PROP_FLAGS);
        $lifo = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->and($flags, $i64->constInt(self::IT_MODE_LIFO, false)),
            $i64->constInt(0, false)
        );
        $negOne = $i64->constInt(-1, true);
        $fifoPos = $context->builder->select($empty, $negOne, $i64->constInt(0, false));
        $lifoPos = $context->builder->select(
            $empty,
            $negOne,
            $context->builder->sub($n64, $i64->constInt(1, false))
        );
        $pos = $context->builder->select($lifo, $lifoPos, $fifoPos);
        self::storeLongPropertyValue($context, $obj, $className, self::PROP_ITER_POS, $pos);

        return self::voidResult($context);
    }

    /**
     * php-src zim_SplDoublyLinkedList_valid (#34976).
     */
    public static function compileValid(Context $context, JITVariable $receiver, string $className): Value
    {
        $obj = self::loadObject($context, $receiver);
        $ht = self::htPtr($context, $obj);
        $map = $context->structFieldMap['__hashtable__'];
        $n = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $pos = self::loadLongProperty($context, $obj, $className, self::PROP_ITER_POS);
        $i64 = $context->getTypeFromString('int64');
        $n64 = $context->builder->truncOrBitCast($n, $i64);
        $nonNeg = $context->builder->icmp(Builder::INT_SGE, $pos, $i64->constInt(0, false));
        $inRange = $context->builder->icmp(Builder::INT_SLT, $pos, $n64);
        $ok = $context->builder->and($nonNeg, $inRange);
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $ok);

        return $slot;
    }

    /**
     * php-src zim_SplDoublyLinkedList_current — NULL when not valid (#24326 / #34976).
     */
    public static function compileCurrent(Context $context, JITVariable $receiver, string $className): Value
    {
        $obj = self::loadObject($context, $receiver);
        $ht = self::htPtr($context, $obj);
        $map = $context->structFieldMap['__hashtable__'];
        $n = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $pos = self::loadLongProperty($context, $obj, $className, self::PROP_ITER_POS);
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $n64 = $context->builder->truncOrBitCast($n, $i64);
        $nonNeg = $context->builder->icmp(Builder::INT_SGE, $pos, $i64->constInt(0, false));
        $inRange = $context->builder->icmp(Builder::INT_SLT, $pos, $n64);
        $ok = $context->builder->and($nonNeg, $inRange);
        $out = JitValueBox::alloc($context);
        $badBb = BasicBlockHelper::append($context, 'spldllist_current_bad');
        $okBb = BasicBlockHelper::append($context, 'spldllist_current_ok');
        $doneBb = BasicBlockHelper::append($context, 'spldllist_current_done');
        $context->builder->branchIf($ok, $okBb, $badBb);

        $context->builder->positionAtEnd($badBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $out)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($okBb);
        $idx = $context->builder->truncOrBitCast($pos, $sizeT);
        $fetched = HashTableHelper::readIndexedToValueBox($context, $ht, $idx);
        JitValueBox::copyFromPointer(
            $context,
            $out,
            JitValueBox::valuePtrFromVariable($context, $fetched)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $out;
    }

    /**
     * php-src zim_SplDoublyLinkedList_key (#34976).
     */
    public static function compileKey(Context $context, JITVariable $receiver, string $className): Value
    {
        $obj = self::loadObject($context, $receiver);
        $pos = self::loadLongProperty($context, $obj, $className, self::PROP_ITER_POS);
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong($context, $slot, $pos);

        return $slot;
    }

    /**
     * php-src zim_SplDoublyLinkedList_next / spl_dllist_it_helper_move_forward (#34976).
     * KEEP: ±1 by LIFO; DELETE: pop/shift then re-anchor cursor.
     */
    public static function compileNext(Context $context, JITVariable $receiver, string $className): Value
    {
        $obj = self::loadObject($context, $receiver);
        $ht = self::htPtr($context, $obj);
        $map = $context->structFieldMap['__hashtable__'];
        $n = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $pos = self::loadLongProperty($context, $obj, $className, self::PROP_ITER_POS);
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $n64 = $context->builder->truncOrBitCast($n, $i64);
        $nonNeg = $context->builder->icmp(Builder::INT_SGE, $pos, $i64->constInt(0, false));
        $inRange = $context->builder->icmp(Builder::INT_SLT, $pos, $n64);
        $ok = $context->builder->and($nonNeg, $inRange);

        $skipBb = BasicBlockHelper::append($context, 'spldllist_next_skip');
        $bodyBb = BasicBlockHelper::append($context, 'spldllist_next_body');
        $doneBb = BasicBlockHelper::append($context, 'spldllist_next_done');
        $context->builder->branchIf($ok, $bodyBb, $skipBb);

        $context->builder->positionAtEnd($skipBb);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($bodyBb);
        $flags = self::loadLongProperty($context, $obj, $className, self::PROP_FLAGS);
        $isDelete = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->and($flags, $i64->constInt(self::IT_MODE_DELETE, false)),
            $i64->constInt(0, false)
        );
        $isLifo = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->and($flags, $i64->constInt(self::IT_MODE_LIFO, false)),
            $i64->constInt(0, false)
        );

        $delBb = BasicBlockHelper::append($context, 'spldllist_next_del');
        $keepBb = BasicBlockHelper::append($context, 'spldllist_next_keep');
        $context->builder->branchIf($isDelete, $delBb, $keepBb);

        $context->builder->positionAtEnd($keepBb);
        $inc = $context->builder->add($pos, $i64->constInt(1, false));
        $dec = $context->builder->sub($pos, $i64->constInt(1, false));
        $kept = $context->builder->select($isLifo, $dec, $inc);
        self::storeLongPropertyValue($context, $obj, $className, self::PROP_ITER_POS, $kept);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($delBb);
        $delLifoBb = BasicBlockHelper::append($context, 'spldllist_next_del_lifo');
        $delFifoBb = BasicBlockHelper::append($context, 'spldllist_next_del_fifo');
        $delMergeBb = BasicBlockHelper::append($context, 'spldllist_next_del_merge');
        $context->builder->branchIf($isLifo, $delLifoBb, $delFifoBb);

        $context->builder->positionAtEnd($delLifoBb);
        // Discard popped value — Iterator::next is void (php-src zim_SplDoublyLinkedList_next).
        self::compilePop($context, $receiver);
        $htAfter = self::htPtr($context, $obj);
        $nAfter = $context->builder->load($context->builder->structGep($htAfter, $map['numElements']));
        $nAfter64 = $context->builder->truncOrBitCast($nAfter, $i64);
        $emptyAfter = $context->builder->icmp(Builder::INT_EQ, $nAfter, $sizeT->constInt(0, false));
        $lifoPos = $context->builder->select(
            $emptyAfter,
            $i64->constInt(-1, true),
            $context->builder->sub($nAfter64, $i64->constInt(1, false))
        );
        self::storeLongPropertyValue($context, $obj, $className, self::PROP_ITER_POS, $lifoPos);
        $context->builder->branch($delMergeBb);

        $context->builder->positionAtEnd($delFifoBb);
        self::compileShift($context, $receiver);
        $htAfterF = self::htPtr($context, $obj);
        $nAfterF = $context->builder->load($context->builder->structGep($htAfterF, $map['numElements']));
        $emptyAfterF = $context->builder->icmp(Builder::INT_EQ, $nAfterF, $sizeT->constInt(0, false));
        $fifoPos = $context->builder->select(
            $emptyAfterF,
            $i64->constInt(-1, true),
            $i64->constInt(0, false)
        );
        self::storeLongPropertyValue($context, $obj, $className, self::PROP_ITER_POS, $fifoPos);
        $context->builder->branch($delMergeBb);

        $context->builder->positionAtEnd($delMergeBb);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return self::voidResult($context);
    }

    /**
     * php-src SplDoublyLinkedList serialize — flags + dllist HT + empty members (#33966 / #34592).
     *
     * NestedJIT encodeWire SIGABRTs on non-empty HT (peer #34491 / #34483). Call registered
     * `__compiler_serialize_hashtable` for storage then wrap
     * `O:len:"Class":3:{i:0;i:flags;i:1;<a:N:{…}>i:2;a:0:{}}`.
     * Must not inline SerializeArrayLlvm::encode here — nested object values recurse
     * through encodeBoxedValue → compileSerialize during IR emit (peer ArrayObject).
     *
     * @return Value {@see __string__*} full `O:len:"Class":3:{…}` wire
     */
    public static function compileSerialize(Context $context, JITVariable $receiver): Value
    {
        \PHPCompiler\JIT\Builtin\StringSerialize::ensureLinked($context);
        $obj = self::loadObject($context, $receiver);
        $objVar = new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $obj);
        $classNameStr = ReflectionBuiltinHelper::getClassName($context, $objVar);

        $flags = self::loadFlagsForAnyDllistClass($context, $obj);

        $ht = self::htPtr($context, $obj);
        $i64 = $context->getTypeFromString('int64');
        $serFlags = $i64->constInt(0, false);
        $storageWire = $context->builder->call(
            $context->lookupFunction('__compiler_serialize_hashtable'),
            $ht,
            $serFlags
        );

        $strMap = $context->structFieldMap['__string__'];
        $classLen = $context->builder->load(
            $context->builder->structGep($classNameStr, $strMap['length'])
        );
        $classLenDigits = VmResourceIdString::formatNativeLong(
            $context,
            $context->builder->zExt($classLen, $i64)
        );
        $flagDigits = VmResourceIdString::formatNativeLong($context, $flags);

        $oColon = $context->builder->load($context->constantStringFromString('O:'));
        $colonQuote = $context->builder->load($context->constantStringFromString(':"'));
        $quoteThree = $context->builder->load($context->constantStringFromString('":3:{i:0;i:'));
        $mid = $context->builder->load($context->constantStringFromString(';i:1;'));
        $tail = $context->builder->load($context->constantStringFromString('i:2;a:0:{}}'));

        $acc = \PHPCompiler\ext\standard\JitStringConcat::concat($context, $oColon, $classLenDigits);
        $acc = \PHPCompiler\ext\standard\JitStringConcat::concat($context, $acc, $colonQuote);
        $acc = \PHPCompiler\ext\standard\JitStringConcat::concat($context, $acc, $classNameStr);
        $acc = \PHPCompiler\ext\standard\JitStringConcat::concat($context, $acc, $quoteThree);
        $acc = \PHPCompiler\ext\standard\JitStringConcat::concat($context, $acc, $flagDigits);
        $acc = \PHPCompiler\ext\standard\JitStringConcat::concat($context, $acc, $mid);
        $acc = \PHPCompiler\ext\standard\JitStringConcat::concat($context, $acc, $storageWire);

        return \PHPCompiler\ext\standard\JitStringConcat::concat($context, $acc, $tail);
    }

    /**
     * Load `__spl_flags` for SplDoublyLinkedList / SplQueue / SplStack (#34592).
     *
     * Construct stores the property under the concrete class name; branch on class_id.
     */
    private static function loadFlagsForAnyDllistClass(Context $context, Value $obj): Value
    {
        $objectType = $context->type->object;
        $objMap = $context->structFieldMap['__object__'];
        $classId = $context->builder->load(
            $context->builder->structGep($obj, $objMap['class_id'])
        );
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);

        /** @var list<array{0: string, 1: int}> $candidates */
        $candidates = [];
        foreach (['SplDoublyLinkedList', 'SplQueue', 'SplStack'] as $name) {
            $id = $objectType->classIdByName($name)
                ?? $objectType->classIdForLowerName(strtolower($name));
            if (null !== $id) {
                $candidates[] = [$name, $id];
            }
        }
        if ([] === $candidates) {
            return $zero;
        }

        $flagsSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($zero, $flagsSlot);
        $doneBb = BasicBlockHelper::append($context, 'dllist_ser_flags_done');
        $continue = $context->builder->getInsertBlock();
        foreach ($candidates as [$name, $cid]) {
            $matchBb = BasicBlockHelper::append($context, 'dllist_ser_flags_match');
            $nextBb = BasicBlockHelper::append($context, 'dllist_ser_flags_next');
            $context->builder->positionAtEnd($continue);
            $is = $context->builder->icmp(
                Builder::INT_EQ,
                $classId,
                $i64->constInt($cid, false)
            );
            $context->builder->branchIf($is, $matchBb, $nextBb);
            $context->builder->positionAtEnd($matchBb);
            $context->builder->store(
                self::loadLongProperty($context, $obj, $name, self::PROP_FLAGS),
                $flagsSlot
            );
            $context->builder->branch($doneBb);
            $continue = $nextBb;
        }
        $context->builder->positionAtEnd($continue);
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);

        return $context->builder->load($flagsSlot);
    }

    /** Expose object load for {@see \PHPCompiler\ext\standard\JitSerialize} (#33966). */
    public static function loadObjectPtr(Context $context, JITVariable $receiver): Value
    {
        return self::loadObject($context, $receiver);
    }

    /**
     * php-src SplDoublyLinkedList::__unserialize — restore dllist into `__spl_ht` (#33966).
     *
     * Bag shape matches ArrayObject `i:1;a:` storage (#33636). Do not write firstIntProp
     * into slot 0 (that replaces the HT pointer).
     * Prefer helper-runtime (avoid PHP_COMPILER_HELPER_RUNTIME_O=0) — peer #32925 / #33636.
     */
    public static function compileUnserializeRestore(
        Context $context,
        Value $obj,
        Value $payloadString
    ): void {
        \PHPCompiler\JIT\Builtin\StringUnserialize::ensureLinked($context);
        $internals = [
            new \PHPCompiler\ext\standard\phpc_native_ht_set_string_at(),
            new \PHPCompiler\ext\standard\phpc_native_ht_set_long_at(),
            new \PHPCompiler\ext\standard\phpc_native_ht_set_null_at(),
            new \PHPCompiler\ext\standard\phpc_native_ht_set_double_at(),
            new \PHPCompiler\ext\standard\phpc_native_ht_set_bool_at(),
        ];
        foreach ($internals as $internal) {
            $lc = strtolower($internal->getName());
            $existing = $context->functionProxies[$lc] ?? null;
            if (null === $existing || $existing instanceof \PHPCompiler\JIT\Call\ExternalMethod) {
                $context->functionProxies[$lc] = $internal;
            }
        }
        $ht = self::htPtr($context, $obj);
        $findLogical = 'PHPCompiler\\ext\\standard\\UnserializeSplArrayFindNestedJitHelper::findStorage';
        $fillIntLogical = 'PHPCompiler\\ext\\standard\\UnserializeSplArrayFillIntKeyNestedJitHelper::fillAt';
        $saved = BasicBlockHelper::tryGetInsertBlock($context);
        \PHPCompiler\JIT\JitVmHelperLink::ensureCompiled(
            $context,
            '/ext/standard/UnserializeSplArrayFindNestedJitHelper.php',
            [$findLogical],
            '#33966'
        );
        BasicBlockHelper::restoreInsertBlock($context, $saved);
        $saved = BasicBlockHelper::tryGetInsertBlock($context);
        \PHPCompiler\JIT\JitVmHelperLink::ensureCompiled(
            $context,
            '/ext/standard/UnserializeSplArrayFillIntKeyNestedJitHelper.php',
            [$fillIntLogical],
            '#33966'
        );
        BasicBlockHelper::restoreInsertBlock($context, $saved);

        $findFn = \PHPCompiler\JIT\JitVmHelperLink::lookupCompiled($context, $findLogical, '#33966');
        $fillIntFn = \PHPCompiler\JIT\JitVmHelperLink::lookupCompiled($context, $fillIntLogical, '#33966');
        $i64 = $context->getTypeFromString('int64');
        $payloadOwned = self::nestedJitOwnedString($context, $payloadString);

        $findOffRaw = $context->builder->call(
            $findFn,
            \PHPCompiler\JIT\JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $payloadOwned,
                $findFn->getParam(0)->typeOf()
            )
        );
        $findOff = \PHPCompiler\JIT\JitNestedHelperCoerce::coerceBridgeResult($context, $findOffRaw, $i64);
        $parent = BasicBlockHelper::parentFunction($context);
        $bbFill = $parent->appendBasicBlock('dll_unser_fill');
        $bbDone = $parent->appendBasicBlock('dll_unser_done');
        $found = $context->builder->icmp(
            Builder::INT_SGE,
            $findOff,
            $i64->constInt(0, false)
        );
        $context->builder->branchIf($found, $bbFill, $bbDone);

        $context->builder->positionAtEnd($bbFill);
        $destI64 = \PHPCompiler\JIT\JitNestedHelperCoerce::ptrToI64($context, $ht);
        $context->builder->call(
            $fillIntFn,
            \PHPCompiler\JIT\JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $destI64,
                $fillIntFn->getParam(0)->typeOf()
            ),
            \PHPCompiler\JIT\JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $payloadOwned,
                $fillIntFn->getParam(1)->typeOf()
            ),
            \PHPCompiler\JIT\JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $findOff,
                $fillIntFn->getParam(2)->typeOf()
            )
        );
        $context->builder->branch($bbDone);
        $context->builder->positionAtEnd($bbDone);
    }

    /** Owned `__string__*` copy for NestedJIT PHP string params (#24137 / #33966). */
    private static function nestedJitOwnedString(Context $context, Value $payload): Value
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $separated = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $payload
        );
        $slot = BasicBlockHelper::entryAlloca($context, $strPtr);
        $context->builder->store($separated, $slot);
        $loaded = $context->builder->load($slot);
        $map = $context->structFieldMap['__string__'];
        $i8p = $context->getTypeFromString('int8*');
        $len = $context->builder->call($context->lookupFunction('__string__strlen'), $loaded);
        $src = $context->builder->pointerCast(
            $context->builder->structGep($loaded, $map['value']),
            $i8p
        );
        $copy = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $len,
            $src
        );
        $context->refcount->disableRefcount($copy);

        return $copy;
    }

    private static function htVar(Context $context, Value $obj): JITVariable
    {
        return $context->type->object->splBackingHashtable(
            new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $obj)
        );
    }

    private static function htPtr(Context $context, Value $obj): Value
    {
        return $context->helper->loadValue(self::htVar($context, $obj));
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

        throw new \LogicException('SplDllist method requires an object receiver');
    }


    private static function coerceIndex(Context $context, JITVariable $index, string $fn): Value
    {
        $sizeT = $context->getTypeFromString('size_t');
        if (null !== $index->compileTimeLong) {
            $lit = (int) $index->compileTimeLong;
            if ($lit < 0) {
                return $sizeT->constInt(0x7fffffffffffffff, false);
            }

            return $sizeT->constInt($lit, false);
        }
        $i64 = JitIntdiv::lowerIntBuiltinArgForCaller($context, $index, $fn, 1, 'index');

        return $context->builder->truncOrBitCast($i64, $sizeT);
    }

    private static function assertIndexInRange(Context $context, Value $ht, Value $idx, string $fn): void
    {
        $map = $context->structFieldMap['__hashtable__'];
        $n = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $ok = $context->builder->icmp(Builder::INT_ULT, $idx, $n);
        $badBb = BasicBlockHelper::append($context, 'spldllist_oob');
        $okBb = BasicBlockHelper::append($context, 'spldllist_oob_ok');
        $context->builder->branchIf($ok, $okBb, $badBb);
        $context->builder->positionAtEnd($badBb);
        $msg = $fn.'(): Argument #1 ($index) is out of range';
        $context->builder->call(
            $context->lookupFunction('__compiler_jit_raise_logic_exception'),
            $context->builder->pointerCast(
                $context->constantFromString($msg),
                $context->getTypeFromString('int8*')
            ),
            $context->constantFromInteger(\strlen($msg), 'size_t')
        );
        $context->builder->branch($okBb);
        $context->builder->positionAtEnd($okBb);
    }

    private static function storeLongProperty(
        Context $context,
        Value $obj,
        string $className,
        string $prop,
        int $value
    ): void {
        $i64 = $context->getTypeFromString('int64');
        self::storeLongPropertyValue($context, $obj, $className, $prop, $i64->constInt($value, true));
    }

    private static function storeLongPropertyValue(
        Context $context,
        Value $obj,
        string $className,
        string $prop,
        Value $value
    ): void {
        $slot = $context->type->object->propertySlotFor($obj, $className, $prop);
        $var = new JITVariable(
            $context,
            JITVariable::TYPE_NATIVE_LONG,
            JITVariable::KIND_VALUE,
            $value
        );
        $context->type->object->propertyStore($slot, $var, JITVariable::TYPE_NATIVE_LONG);
    }

    private static function loadLongProperty(
        Context $context,
        Value $obj,
        string $className,
        string $prop
    ): Value {
        $slot = $context->type->object->propertyFetch($obj, $className, $prop);

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
