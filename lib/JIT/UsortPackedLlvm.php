<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Call\NestedClosureInvoke;
use PHPLLVM\Builder;
use PHPLLVM\Type;
use PHPLLVM\Value;

/**
 * Pure LLVM usort() for thin standalone AOT Closure comparators (#26954 / #27217 peer).
 *
 * NestedJIT of {@see \PHPCompiler\ext\standard\UsortJitHelper} never invokes the comparator
 * under thin AOT (variadic VmClosureInvoke::invokeVariable). Bubble-sort packed slots in place
 * via {@see NestedClosureInvoke} — same class of fix as {@see ArrayWalkLlvm} (#27632).
 *
 * php-src: ext/standard/array.c — php_array_usort
 */
final class UsortPackedLlvm
{
    public static function sortPackedWithClosure(Context $context, Value $ht, Variable $closure): void
    {
        NestedClosureInvokeLlvm::ensureLinked($context);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'usort_packed_llvm_cont');
        self::emitSortBody($context, $ht, $closure);
    }

    private static function emitSortBody(Context $context, Value $ht, Variable $closure): void
    {
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');
        $i64 = $context->getTypeFromString('int64');
        $valueType = $context->getTypeFromString('__value__');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);

        $n = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $done = BasicBlockHelper::append($context, 'usort_packed_done');
        $work = BasicBlockHelper::append($context, 'usort_packed_work');
        $tooSmall = $context->builder->icmp(
            Builder::INT_ULT,
            $n,
            $sizeT->constInt(2, false)
        );
        $context->builder->branchIf($tooSmall, $done, $work);

        $context->builder->positionAtEnd($work);
        $maxPasses = $context->builder->mul($n, $n);
        $passCountSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $passCountSlot);
        $swappedSlot = BasicBlockHelper::entryAlloca($context, $i1);
        $context->builder->store($i1->constInt(1, false), $swappedSlot);

        $passHead = BasicBlockHelper::append($context, 'usort_packed_pass_head');
        $passBody = BasicBlockHelper::append($context, 'usort_packed_pass_body');
        $passExit = BasicBlockHelper::append($context, 'usort_packed_pass_exit');
        $context->builder->branch($passHead);

        $context->builder->positionAtEnd($passHead);
        $didSwap = $context->builder->load($swappedSlot);
        $passCount = $context->builder->load($passCountSlot);
        $overCap = $context->builder->icmp(Builder::INT_UGE, $passCount, $maxPasses);
        $cont = $context->builder->and(
            $didSwap,
            $context->builder->icmp(Builder::INT_EQ, $overCap, $i1->constInt(0, false))
        );
        $context->builder->branchIf($cont, $passBody, $done);

        $context->builder->positionAtEnd($passBody);
        $context->builder->store(
            $context->builder->addNoSignedWrap($passCount, $one),
            $passCountSlot
        );
        $context->builder->store($i1->constInt(0, false), $swappedSlot);
        $limit = $context->builder->sub($n, $one);
        $iSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $iSlot);
        $walkHead = BasicBlockHelper::append($context, 'usort_packed_walk_head');
        $walkBody = BasicBlockHelper::append($context, 'usort_packed_walk_body');
        $context->builder->branch($walkHead);

        $context->builder->positionAtEnd($walkHead);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_UGE, $i, $limit);
        $context->builder->branchIf($atEnd, $passExit, $walkBody);

        $context->builder->positionAtEnd($walkBody);
        $j = $context->builder->addNoSignedWrap($i, $one);
        $leftBox = HashTableReadLlvm::readIndexedToValueBox($context, $ht, $i);
        $rightBox = HashTableReadLlvm::readIndexedToValueBox($context, $ht, $j);
        $cmpLong = self::compareWithClosure($context, $closure, $leftBox, $rightBox);
        $needsSwap = $context->builder->icmp(
            Builder::INT_SGT,
            $cmpLong,
            $i64->constInt(0, false)
        );
        $swapBlock = BasicBlockHelper::append($context, 'usort_packed_swap');
        $advance = BasicBlockHelper::append($context, 'usort_packed_advance');
        $context->builder->branchIf($needsSwap, $swapBlock, $advance);

        $context->builder->positionAtEnd($swapBlock);
        self::swapPackedEntriesAt($context, $ht, $i, $j, $valueType);
        $context->builder->store($i1->constInt(1, false), $swappedSlot);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($walkHead);

        $context->builder->positionAtEnd($passExit);
        $context->builder->branch($passHead);

        $context->builder->positionAtEnd($done);
    }

    /** @return Value int64 compare sign for usort swap test */
    private static function compareWithClosure(
        Context $context,
        Variable $closure,
        Variable $left,
        Variable $right
    ): Value {
        $resultPtr = (new NestedClosureInvoke())->call($context, $closure, $left, $right);
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $f64 = $context->getTypeFromString('double');
        $valueMap = $context->structFieldMap['__value__'];
        $kind = $context->builder->and(
            $context->builder->load($context->builder->structGep($resultPtr, $valueMap['type'])),
            $i8->constInt(0x7f, false)
        );
        $fn = $context->builder->getInsertBlock()->getParent();
        $longBb = $fn->appendBasicBlock('usort_cmp_long');
        $dblCheck = $fn->appendBasicBlock('usort_cmp_dbl_check');
        $dblBody = $fn->appendBasicBlock('usort_cmp_dbl_body');
        $zeroBb = $fn->appendBasicBlock('usort_cmp_zero');
        $join = $fn->appendBasicBlock('usort_cmp_join');
        $resultSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $zero = $i64->constInt(0, false);

        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_NATIVE_LONG & 0x7f, false)
        );
        $isDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_NATIVE_DOUBLE & 0x7f, false)
        );
        $context->builder->branchIf($isLong, $longBb, $dblCheck);

        $context->builder->positionAtEnd($longBb);
        $context->builder->store(
            $context->builder->call($context->lookupFunction('__value__readLong'), $resultPtr),
            $resultSlot
        );
        $context->builder->branch($join);

        $context->builder->positionAtEnd($dblCheck);
        $context->builder->branchIf($isDouble, $dblBody, $zeroBb);

        $context->builder->positionAtEnd($dblBody);
        $dblVal = $context->builder->call($context->lookupFunction('__value__readDouble'), $resultPtr);
        $zeroD = $f64->constReal(0.0);
        $pos = $context->builder->fcmp(Builder::REAL_OGT, $dblVal, $zeroD);
        $neg = $context->builder->fcmp(Builder::REAL_OLT, $dblVal, $zeroD);
        $context->builder->store(
            $context->builder->select(
                $pos,
                $i64->constInt(1, false),
                $context->builder->select($neg, $i64->constInt(-1, true), $zero)
            ),
            $resultSlot
        );
        $context->builder->branch($join);

        $context->builder->positionAtEnd($zeroBb);
        $context->builder->store($zero, $resultSlot);
        $context->builder->branch($join);

        $context->builder->positionAtEnd($join);

        return $context->builder->load($resultSlot);
    }

    private static function swapPackedEntriesAt(
        Context $context,
        Value $ht,
        Value $idxA,
        Value $idxB,
        Type $valueType
    ): void {
        $map = $context->structFieldMap['__hashtable__'];
        $values = $context->builder->load($context->builder->structGep($ht, $map['values']));
        $entryA = $context->builder->inBoundsGep($values, $idxA);
        $entryB = $context->builder->inBoundsGep($values, $idxB);
        $tmp = BasicBlockHelper::entryAlloca($context, $valueType);
        $context->builder->store($context->builder->load($entryA), $tmp);
        $context->builder->store($context->builder->load($entryB), $entryA);
        $context->builder->store($context->builder->load($tmp), $entryB);
    }
}
