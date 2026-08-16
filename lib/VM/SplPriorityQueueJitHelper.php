<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\ext\spl\SplPriorityQueueBuiltin;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MultisortRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT SplPriorityQueue — parallel `__spl_data` / `__spl_prio` + Iterator (#27277, #28708).
 *
 * php-src: ext/spl/spl_heap.c — SplPriorityQueue insert/extract + destructive foreach
 * (default EXTR_DATA). Priorities stay sorted descending via {@see MultisortRuntime::multisortPacked}.
 */
final class SplPriorityQueueJitHelper
{
    public const PROP_DATA = '__spl_data';

    public const PROP_PRIO = '__spl_prio';

    public const PROP_FLAGS = '__spl_flags';

    public const PROP_ITER_POS = '__spl_iter_pos';

    private const CLASS_NAME = 'SplPriorityQueue';

    public static function compileConstruct(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $objectType = $context->type->object;
        $data = HashTableHelper::alloc($context);
        $prio = HashTableHelper::alloc($context);
        $dataVar = new JITVariable($context, JITVariable::TYPE_HASHTABLE, JITVariable::KIND_VALUE, $data);
        $prioVar = new JITVariable($context, JITVariable::TYPE_HASHTABLE, JITVariable::KIND_VALUE, $prio);
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, self::CLASS_NAME, self::PROP_DATA),
            $dataVar,
            JITVariable::TYPE_HASHTABLE
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, self::CLASS_NAME, self::PROP_PRIO),
            $prioVar,
            JITVariable::TYPE_HASHTABLE
        );
        self::storeLongProperty($context, $obj, self::PROP_FLAGS, SplPriorityQueueBuiltin::EXTR_DATA);
        self::storeLongProperty($context, $obj, self::PROP_ITER_POS, -1);
        $objectType->markObjectConstructed($obj);

        return self::voidResult($context);
    }

    public static function compileInsert(
        Context $context,
        JITVariable $receiver,
        JITVariable $data,
        JITVariable $priority
    ): Value {
        $obj = self::loadObject($context, $receiver);
        $dataHt = self::dataHtVar($context, $obj);
        $prioHt = self::prioHtVar($context, $obj);
        $dataHt->nextFreeElementFromRuntime = true;
        $prioHt->nextFreeElementFromRuntime = true;
        HashTableHelper::addElement($context, $dataHt, $data, null);
        HashTableHelper::addElement($context, $prioHt, $priority, null);
        // Highest priority first (php-src max-heap extract order).
        MultisortRuntime::multisortPacked($context, [$prioHt, $dataHt], true);

        return self::voidResult($context);
    }

    public static function compileExtract(Context $context, JITVariable $receiver): Value
    {
        return self::compilePopTop($context, $receiver, true);
    }

    public static function compileTop(Context $context, JITVariable $receiver): Value
    {
        return self::compilePopTop($context, $receiver, false);
    }

    public static function compileCount(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $ht = self::dataPtr($context, $obj);
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

    /** php-src spl_heap_it_rewind is a no-op; valid/key derive from count (#31601). */
    public static function compileRewind(Context $context, JITVariable $receiver): Value
    {
        unset($receiver);

        return self::voidResult($context);
    }

    public static function compileValid(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $ht = self::dataPtr($context, $obj);
        $map = $context->structFieldMap['__hashtable__'];
        $n = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $sizeT = $context->getTypeFromString('size_t');
        // php-src spl_heap_it_valid: heap->count != 0 (#31601).
        $nonEmpty = $context->builder->icmp(Builder::INT_UGT, $n, $sizeT->constInt(0, false));
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $nonEmpty);

        return $slot;
    }

    /** Default EXTR_DATA — yield data[0] (flags other than EXTR_DATA stay VM-covered). */
    public static function compileCurrent(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $ht = self::dataPtr($context, $obj);
        $map = $context->structFieldMap['__hashtable__'];
        $n = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $sizeT = $context->getTypeFromString('size_t');
        $out = JitValueBox::alloc($context);
        $emptyBb = BasicBlockHelper::append($context, 'splpq_current_empty');
        $readBb = BasicBlockHelper::append($context, 'splpq_current_read');
        $merge = BasicBlockHelper::append($context, 'splpq_current_merge');
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
        $ht = self::dataPtr($context, $obj);
        $map = $context->structFieldMap['__hashtable__'];
        $n = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $n64 = $context->builder->truncOrBitCast($n, $i64);
        // php-src spl_heap_it_get_current_key: count - 1 (#22290 / #31601).
        $pos = $context->builder->sub($n64, $i64->constInt(1, false));
        $empty = $context->builder->icmp(Builder::INT_EQ, $n, $sizeT->constInt(0, false));
        $pos = $context->builder->select($empty, $i64->constInt(-1, true), $pos);
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong($context, $slot, $pos);

        return $slot;
    }

    /** Destructive foreach — extract top when non-empty (#28708 / #31601). */
    public static function compileNext(Context $context, JITVariable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $ht = self::dataPtr($context, $obj);
        $map = $context->structFieldMap['__hashtable__'];
        $n = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $sizeT = $context->getTypeFromString('size_t');
        $skip = BasicBlockHelper::append($context, 'splpq_next_skip');
        $doExtract = BasicBlockHelper::append($context, 'splpq_next_extract');
        $done = BasicBlockHelper::append($context, 'splpq_next_done');
        $nonEmpty = $context->builder->icmp(Builder::INT_UGT, $n, $sizeT->constInt(0, false));
        $context->builder->branchIf($nonEmpty, $doExtract, $skip);

        $context->builder->positionAtEnd($skip);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($doExtract);
        self::extractTop($context, $obj);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);

        return self::voidResult($context);
    }

    /**
     * @param bool $remove when true, pop top (extract); else peek (top)
     */
    private static function compilePopTop(Context $context, JITVariable $receiver, bool $remove): Value
    {
        $obj = self::loadObject($context, $receiver);
        $dataHt = self::dataPtr($context, $obj);
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $n = $context->builder->load($context->builder->structGep($dataHt, $map['numElements']));
        $out = JitValueBox::alloc($context);
        $emptyBb = BasicBlockHelper::append($context, 'splpq_'.($remove ? 'extract' : 'top').'_empty');
        $bodyBb = BasicBlockHelper::append($context, 'splpq_'.($remove ? 'extract' : 'top').'_body');
        $doneBb = BasicBlockHelper::append($context, 'splpq_'.($remove ? 'extract' : 'top').'_done');
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $n, $sizeT->constInt(0, false));
        $context->builder->branchIf($isEmpty, $emptyBb, $bodyBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $out)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($bodyBb);
        // Default EXTR_DATA — return data[0] (flags other than EXTR_DATA stay VM-covered).
        $fetched = HashTableHelper::readIndexedToValueBox(
            $context,
            $dataHt,
            $sizeT->constInt(0, false)
        );
        JitValueBox::copyFromPointer(
            $context,
            $out,
            JitValueBox::valuePtrFromVariable($context, $fetched)
        );
        if ($remove) {
            self::extractTop($context, $obj);
            self::storeLongProperty($context, $obj, self::PROP_ITER_POS, -1);
        }
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $out;
    }

    private static function extractTop(Context $context, Value $obj): void
    {
        $dataHt = self::dataPtr($context, $obj);
        $prioHt = self::prioPtr($context, $obj);
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $n = $context->builder->load($context->builder->structGep($dataHt, $map['numElements']));
        $emptyBb = BasicBlockHelper::append($context, 'splpq_pop_empty');
        $bodyBb = BasicBlockHelper::append($context, 'splpq_pop_body');
        $doneBb = BasicBlockHelper::append($context, 'splpq_pop_done');
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $n, $sizeT->constInt(0, false));
        $context->builder->branchIf($isEmpty, $emptyBb, $bodyBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($bodyBb);
        $lastIdx = $context->builder->sub($n, $sizeT->constInt(1, false));
        $onlyOne = $context->builder->icmp(Builder::INT_EQ, $n, $sizeT->constInt(1, false));
        $moveBb = BasicBlockHelper::append($context, 'splpq_pop_move');
        $shrinkBb = BasicBlockHelper::append($context, 'splpq_pop_shrink');
        $context->builder->branchIf($onlyOne, $shrinkBb, $moveBb);

        $context->builder->positionAtEnd($moveBb);
        $lastData = HashTableHelper::readIndexedToValueBox($context, $dataHt, $lastIdx);
        $lastPrio = HashTableHelper::readIndexedToValueBox($context, $prioHt, $lastIdx);
        HashTableHelper::setAtIndex($context, $dataHt, $sizeT->constInt(0, false), $lastData);
        HashTableHelper::setAtIndex($context, $prioHt, $sizeT->constInt(0, false), $lastPrio);
        $context->builder->branch($shrinkBb);

        $context->builder->positionAtEnd($shrinkBb);
        $context->builder->call($context->lookupFunction('__hashtable__unsetLongAt'), $dataHt, $lastIdx);
        $context->builder->call($context->lookupFunction('__hashtable__unsetLongAt'), $prioHt, $lastIdx);
        // Keep nextFreeElement in sync for sortPacked/multisort (#27276 / #27277).
        $nAfter = $context->builder->load($context->builder->structGep($dataHt, $map['numElements']));
        $context->builder->store($nAfter, $context->builder->structGep($dataHt, $map['nextFreeElement']));
        $context->builder->store($nAfter, $context->builder->structGep($prioHt, $map['nextFreeElement']));
        $needSort = $context->builder->icmp(Builder::INT_UGT, $nAfter, $sizeT->constInt(1, false));
        $sortBb = BasicBlockHelper::append($context, 'splpq_pop_sort');
        $context->builder->branchIf($needSort, $sortBb, $doneBb);

        $context->builder->positionAtEnd($sortBb);
        $dataVar = self::dataHtVar($context, $obj);
        $prioVar = self::prioHtVar($context, $obj);
        MultisortRuntime::multisortPacked($context, [$prioVar, $dataVar], true);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
    }

    private static function dataHtVar(Context $context, Value $obj): JITVariable
    {
        $slot = $context->type->object->propertyFetch($obj, self::CLASS_NAME, self::PROP_DATA);

        return new JITVariable(
            $context,
            JITVariable::TYPE_HASHTABLE,
            JITVariable::KIND_VALUE,
            $context->helper->loadValue($slot)
        );
    }

    private static function prioHtVar(Context $context, Value $obj): JITVariable
    {
        $slot = $context->type->object->propertyFetch($obj, self::CLASS_NAME, self::PROP_PRIO);

        return new JITVariable(
            $context,
            JITVariable::TYPE_HASHTABLE,
            JITVariable::KIND_VALUE,
            $context->helper->loadValue($slot)
        );
    }

    private static function dataPtr(Context $context, Value $obj): Value
    {
        return $context->helper->loadValue(self::dataHtVar($context, $obj));
    }

    private static function prioPtr(Context $context, Value $obj): Value
    {
        return $context->helper->loadValue(self::prioHtVar($context, $obj));
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

        throw new \LogicException('SplPriorityQueue method requires an object receiver');
    }

    private static function storeLongProperty(Context $context, Value $obj, string $prop, int $value): void
    {
        $i64 = $context->getTypeFromString('int64');
        self::storeLongPropertyValue($context, $obj, $prop, $i64->constInt($value, true));
    }

    private static function storeLongPropertyValue(
        Context $context,
        Value $obj,
        string $prop,
        Value $value
    ): void {
        $slot = $context->type->object->propertySlotFor($obj, self::CLASS_NAME, $prop);
        $var = new JITVariable(
            $context,
            JITVariable::TYPE_NATIVE_LONG,
            JITVariable::KIND_VALUE,
            $value
        );
        $context->type->object->propertyStore($slot, $var, JITVariable::TYPE_NATIVE_LONG);
    }

    private static function loadLongProperty(Context $context, Value $obj, string $prop): Value
    {
        $slot = $context->type->object->propertyFetch($obj, self::CLASS_NAME, $prop);

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
