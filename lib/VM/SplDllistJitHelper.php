<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT SplDoublyLinkedList / SplQueue / SplStack — object `__spl_ht` deque (#26790).
 *
 * php-src: ext/spl/spl_dllist.c — push/pop/shift/unshift; SplQueue enqueue/dequeue; SplStack push/pop.
 */
final class SplDllistJitHelper
{
    public const PROP_HT = '__spl_ht';

    public static function compileConstruct(Context $context, JITVariable $receiver, string $className): Value
    {
        $obj = self::loadObject($context, $receiver);
        $objectType = $context->type->object;
        $ht = HashTableHelper::alloc($context);
        $htVar = new JITVariable($context, JITVariable::TYPE_HASHTABLE, JITVariable::KIND_VALUE, $ht);
        $slot = $objectType->propertySlotFor($obj, $className, self::PROP_HT);
        $objectType->propertyStore($slot, $htVar, JITVariable::TYPE_HASHTABLE);
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
