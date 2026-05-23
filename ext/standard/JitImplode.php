<?php

declare(strict_types=1);

/**
 * LLVM JIT helper for implode() — glue plus packed __hashtable__ of strings.
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitImplode
{
    public static function implode(Context $context, Value $glue, Value $haystack): Value
    {
        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $haystack
        );
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $zeroSize = $sizeT->constInt(0, false);
        $zeroI64 = $i64->constInt(0, false);
        $oneSize = $sizeT->constInt(1, false);

        $mergeBlock = BasicBlockHelper::append($context, 'implode_merge');
        $emptyBlock = BasicBlockHelper::append($context, 'implode_empty');
        $workBlock = BasicBlockHelper::append($context, 'implode_work');
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $num, $zeroSize);
        $context->builder->branchIf($isEmpty, $emptyBlock, $workBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptyStr = $context->builder->call($context->lookupFunction('__string__alloc'), $zeroI64);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($workBlock);
        $first = HashTableHelper::readStringAt($context, $haystack, $zeroSize);
        $resultSlot = $context->builder->alloca($strPtr, 1, 'implode_acc');
        $acc = $context->builder->call($context->lookupFunction('__string__separate'), $first);
        $context->builder->store($acc, $resultSlot);

        $iSlot = $context->builder->alloca($sizeT, 1, 'implode_i');
        $context->builder->store($oneSize, $iSlot);

        $loopHead = BasicBlockHelper::append($context, 'implode_head');
        $loopBody = BasicBlockHelper::append($context, 'implode_body');
        $loopDone = BasicBlockHelper::append($context, 'implode_loop_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $num);
        $context->builder->branchIf($atEnd, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $acc = $context->builder->load($resultSlot);
        $withGlue = JitStringConcat::concat($context, $acc, $glue);
        $part = HashTableHelper::readStringAt($context, $haystack, $i);
        $acc = JitStringConcat::concat($context, $withGlue, $part);
        $context->builder->store($acc, $resultSlot);
        $context->builder->store(
            $context->builder->addNoSignedWrap($i, $oneSize),
            $iSlot
        );
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $workResult = $context->builder->load($resultSlot);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);
        $result = $context->builder->phi($strPtr);
        $result->addIncoming($emptyStr, $emptyBlock);
        $result->addIncoming($workResult, $loopDone);

        BasicBlockHelper::branchToFreshContinue($context, 'implode_continue');

        return $result;
    }
}
