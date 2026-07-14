<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\HashTableReadLlvm;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** HashTable::unshiftPrepend() for nested php-in-PHP JIT helpers (#12717, bootstrap-aot-link). */
final class HashTableUnshiftPrepend implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('unshiftPrepend() requires a HashTable receiver');
        }
        $ht = HashTableNestedReceiver::hashtableFromReceiver($context, $args[0]);
        $values = \array_slice($args, 1);
        $k = \count($values);
        if (0 === $k) {
            return self::countResult($context, $ht);
        }
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $n = $context->builder->call($context->lookupFunction('__hashtable__getNumElements'), $ht);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $n, $zero);
        $emptyBb = BasicBlockHelper::append($context, 'ht_unshift_empty');
        $shiftBb = BasicBlockHelper::append($context, 'ht_unshift_shift');
        $doneBb = BasicBlockHelper::append($context, 'ht_unshift_done');
        $context->builder->branchIf($isEmpty, $emptyBb, $shiftBb);

        $context->builder->positionAtEnd($emptyBb);
        foreach ($values as $value) {
            HashTableHelper::addElement(
                $context,
                new Variable($context, Variable::TYPE_HASHTABLE, Variable::KIND_VALUE, $ht),
                $value,
                null
            );
        }
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($shiftBb);
        $need = $context->builder->addNoSignedWrap($n, $context->getTypeFromString('size_t')->constInt($k, false));
        $context->builder->call($context->lookupFunction('__hashtable__grow'), $ht, $need);
        $indexSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($context->builder->subNoSignedWrap($n, $one), $indexSlot);
        $shiftHead = BasicBlockHelper::append($context, 'ht_unshift_shift_head');
        $shiftBody = BasicBlockHelper::append($context, 'ht_unshift_shift_body');
        $shiftDone = BasicBlockHelper::append($context, 'ht_unshift_shift_done');
        $context->builder->branch($shiftHead);
        $context->builder->positionAtEnd($shiftHead);
        $idx = $context->builder->load($indexSlot);
        $past = $context->builder->icmp(Builder::INT_SLT, $idx, $zero);
        $context->builder->branchIf($past, $shiftDone, $shiftBody);
        $context->builder->positionAtEnd($shiftBody);
        $srcIdx = $idx;
        $dstIdx = $context->builder->addNoSignedWrap($idx, $context->getTypeFromString('size_t')->constInt($k, false));
        $srcVal = HashTableReadLlvm::readIndexedToValueBox($context, $ht, $srcIdx);
        HashTableHelper::setAtIndex($context, $ht, $dstIdx, $srcVal);
        $context->builder->store($context->builder->subNoSignedWrap($idx, $one), $indexSlot);
        $context->builder->branch($shiftHead);
        $context->builder->positionAtEnd($shiftDone);
        $writeIdx = $zero;
        foreach ($values as $value) {
            HashTableHelper::setAtIndex($context, $ht, $writeIdx, $value);
            $writeIdx = $context->builder->addNoSignedWrap($writeIdx, $one);
        }
        $map = $context->structFieldMap['__hashtable__'];
        $context->builder->store($need, $context->builder->structGep($ht, $map['nextFreeElement']));
        $context->builder->store($need, $context->builder->structGep($ht, $map['numElements']));
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return self::countResult($context, $ht);
    }

    private static function countResult(Context $context, Value $ht): Value
    {
        $slot = JitValueBox::alloc($context);
        $count = $context->builder->call($context->lookupFunction('__hashtable__getNumElements'), $ht);
        $i64 = $context->getTypeFromString('int64');
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            JitValueBox::pointer($context, $slot),
            JitNestedHelperCoerce::i64ToScalar($context, JitNestedHelperCoerce::scalarToI64($context, $count, $i64), $i64)
        );

        return $slot;
    }
}
