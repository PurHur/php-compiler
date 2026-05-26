<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM JIT helpers for array_rand() on packed lists (#2321). */
final class JitArrayRand
{
    public static function randPacked(Context $context, JITVariable $array, Value $num): Value
    {
        if (ArrayBuiltinHelper::isNativeArray($array->type)) {
            throw new \LogicException(
                'array_rand() cannot compile fixed-size literal arrays in JIT/AOT yet; use bin/vm.php or build the list with [] append'
            );
        }
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $one = $sizeT->constInt(1, false);
        $zero = $sizeT->constInt(0, false);
        $n = $context->builder->load(
            $context->builder->structGep($ht, $map['nextFreeElement'])
        );
        $resultSlot = JitValueBox::alloc($context);
        $resultPtr = JitValueBox::pointer($context, $resultSlot);

        $emptyBb = BasicBlockHelper::append($context, 'array_rand_empty');
        $workBb = BasicBlockHelper::append($context, 'array_rand_work');
        $doneBb = BasicBlockHelper::append($context, 'array_rand_done');
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $n, $zero);
        $context->builder->branchIf($isEmpty, $emptyBb, $workBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->call($context->lookupFunction('abort'));

        $context->builder->positionAtEnd($workBb);
        $numGtN = $context->builder->icmp(Builder::INT_UGT, $num, $n);
        $numZero = $context->builder->icmp(Builder::INT_EQ, $num, $zero);
        $numBad = $context->builder->or($numGtN, $numZero);
        $badBb = BasicBlockHelper::append($context, 'array_rand_bad_num');
        $pickBb = BasicBlockHelper::append($context, 'array_rand_pick');
        $context->builder->branchIf($numBad, $badBb, $pickBb);

        $context->builder->positionAtEnd($badBb);
        $context->builder->call($context->lookupFunction('abort'));

        $context->builder->positionAtEnd($pickBb);
        $isOne = $context->builder->icmp(Builder::INT_EQ, $num, $one);
        $singleBb = BasicBlockHelper::append($context, 'array_rand_single');
        $multiBb = BasicBlockHelper::append($context, 'array_rand_multi');
        $context->builder->branchIf($isOne, $singleBb, $multiBb);

        $context->builder->positionAtEnd($singleBb);
        $idx = JitRandom::indexBelow($context, $n);
        JitValueBox::writeLong(
            $context,
            $resultSlot,
            $context->builder->truncOrBitCast($idx, $context->getTypeFromString('int64'))
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($multiBb);
        $outHt = $context->builder->call(
            $context->lookupFunction('__hashtable__arrayRandKeys'),
            $ht,
            $num
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $resultPtr,
            $outHt
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $resultPtr;
    }
}
