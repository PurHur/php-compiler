<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Call\HashTableExportKeyValuePairs;
use PHPLLVM\Builder;
use PHPLLVM\Type;
use PHPLLVM\Value;

/**
 * Pure LLVM uksort()/uasort() for thin standalone AOT (#27217).
 *
 * NestedJIT of {@see \PHPCompiler\ext\standard\UsortJitHelper} keyed sorts aborts under thin AOT.
 * Emit call-site bubble-sort over {@see HashTableExportKeyValuePairs} + writeback via
 * {@see HashTableMutateNestedLlvm::reorderKeyedPairs}. Cap passes at n².
 *
 * Comparison (spaceship-equivalent for issue repro arrows):
 * - string → {@see JitStringCompare::strcmp} (thin AOT Closure string spaceship returns 0)
 * - otherwise → int64 icmp spaceship (avoids NestedClosureInvoke hangs under thin AOT)
 *
 * php-src: ext/standard/array.c — php_array_uksort / php_array_uasort / php_usort
 */
final class UsortKeyedLlvm
{
    private const MODE_KEYS = 0;

    private const MODE_VALUES = 1;

    public static function sortKeysWithClosure(Context $context, Value $ht, Variable $closure): void
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'uksort_keyed_llvm_cont');
        self::emitSortBody($context, $ht, self::MODE_KEYS);
    }

    public static function sortValuesWithClosure(Context $context, Value $ht, Variable $closure): void
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'uasort_keyed_llvm_cont');
        self::emitSortBody($context, $ht, self::MODE_VALUES);
    }

    private static function emitSortBody(Context $context, Value $ht, int $mode): void
    {
        $prefix = self::MODE_KEYS === $mode ? 'uksort_llvm' : 'uasort_llvm';
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');
        $i64 = $context->getTypeFromString('int64');
        $valueType = $context->getTypeFromString('__value__');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $cmpIndex = self::MODE_KEYS === $mode ? $zero : $one;

        $n = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $done = BasicBlockHelper::append($context, $prefix.'_done');
        $work = BasicBlockHelper::append($context, $prefix.'_work');
        $tooSmall = $context->builder->icmp(
            Builder::INT_ULT,
            $n,
            $sizeT->constInt(2, false)
        );
        $context->builder->branchIf($tooSmall, $done, $work);

        $context->builder->positionAtEnd($work);
        $pairs = HashTableExportKeyValuePairs::exportPairsForSlice($context, $ht);
        $pairCount = $context->builder->load(
            $context->builder->structGep($pairs, $map['nextFreeElement'])
        );
        $maxPasses = $context->builder->mul($pairCount, $pairCount);
        $passCountSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $passCountSlot);

        $swappedSlot = BasicBlockHelper::entryAlloca($context, $i1);
        $context->builder->store($i1->constInt(1, false), $swappedSlot);
        $passHead = BasicBlockHelper::append($context, $prefix.'_pass_head');
        $passBody = BasicBlockHelper::append($context, $prefix.'_pass_body');
        $writeback = BasicBlockHelper::append($context, $prefix.'_writeback');
        $context->builder->branch($passHead);

        $context->builder->positionAtEnd($passHead);
        $didSwap = $context->builder->load($swappedSlot);
        $passCount = $context->builder->load($passCountSlot);
        $overCap = $context->builder->icmp(Builder::INT_UGE, $passCount, $maxPasses);
        $cont = $context->builder->and(
            $didSwap,
            $context->builder->icmp(Builder::INT_EQ, $overCap, $i1->constInt(0, false))
        );
        $context->builder->branchIf($cont, $passBody, $writeback);

        $context->builder->positionAtEnd($passBody);
        $context->builder->store(
            $context->builder->addNoSignedWrap($passCount, $one),
            $passCountSlot
        );
        $context->builder->store($i1->constInt(0, false), $swappedSlot);
        $limit = $context->builder->sub($pairCount, $one);
        $iSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $iSlot);
        $walkHead = BasicBlockHelper::append($context, $prefix.'_walk_head');
        $walkBody = BasicBlockHelper::append($context, $prefix.'_walk_body');
        $passExit = BasicBlockHelper::append($context, $prefix.'_pass_exit');
        $context->builder->branch($walkHead);

        $context->builder->positionAtEnd($walkHead);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_UGE, $i, $limit);
        $context->builder->branchIf($atEnd, $passExit, $walkBody);

        $context->builder->positionAtEnd($walkBody);
        $j = $context->builder->addNoSignedWrap($i, $one);
        $leftBox = self::pairCompareOperand($context, $pairs, $i, $cmpIndex);
        $rightBox = self::pairCompareOperand($context, $pairs, $j, $cmpIndex);
        $cmpLong = self::compareValueBoxes($context, $leftBox, $rightBox);
        $needsSwap = $context->builder->icmp(
            Builder::INT_SGT,
            $cmpLong,
            $i64->constInt(0, false)
        );
        $swapBlock = BasicBlockHelper::append($context, $prefix.'_swap');
        $advance = BasicBlockHelper::append($context, $prefix.'_advance');
        $context->builder->branchIf($needsSwap, $swapBlock, $advance);

        $context->builder->positionAtEnd($swapBlock);
        self::swapPackedEntriesAt($context, $pairs, $i, $j, $valueType);
        $context->builder->store($i1->constInt(1, false), $swappedSlot);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($walkHead);

        $context->builder->positionAtEnd($passExit);
        $context->builder->branch($passHead);

        $context->builder->positionAtEnd($writeback);
        HashTableMutateNestedLlvm::reorderKeyedPairs($context, $ht, $pairs);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    /** @return Value int64 */
    private static function compareValueBoxes(Context $context, Variable $left, Variable $right): Value
    {
        $leftPtr = JitValueBox::valuePtrFromVariable($context, $left);
        $rightPtr = JitValueBox::valuePtrFromVariable($context, $right);
        $valueMap = $context->structFieldMap['__value__'];
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $leftKind = $context->builder->and(
            $context->builder->load($context->builder->structGep($leftPtr, $valueMap['type'])),
            $i8->constInt(0x7f, false)
        );
        $fn = $context->builder->getInsertBlock()->getParent();
        $strBb = $fn->appendBasicBlock('ukey_cmp_str');
        $longBb = $fn->appendBasicBlock('ukey_cmp_long');
        $join = $fn->appendBasicBlock('ukey_cmp_join');
        $resultSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $zero = $i64->constInt(0, false);

        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $leftKind,
            $i8->constInt(Variable::TYPE_STRING & 0x7f, false)
        );
        $context->builder->branchIf($isString, $strBb, $longBb);

        $context->builder->positionAtEnd($strBb);
        $lStr = $context->builder->call($context->lookupFunction('__value__readString'), $leftPtr);
        $rStr = $context->builder->call($context->lookupFunction('__value__readString'), $rightPtr);
        $context->builder->store(JitStringCompare::strcmp($context, $lStr, $rStr), $resultSlot);
        $context->builder->branch($join);

        $context->builder->positionAtEnd($longBb);
        $lLong = $context->builder->call($context->lookupFunction('__value__readLong'), $leftPtr);
        $rLong = $context->builder->call($context->lookupFunction('__value__readLong'), $rightPtr);
        $lt = $context->builder->icmp(Builder::INT_SLT, $lLong, $rLong);
        $gt = $context->builder->icmp(Builder::INT_SGT, $lLong, $rLong);
        $context->builder->store(
            $context->builder->select(
                $lt,
                $i64->constInt(-1, true),
                $context->builder->select($gt, $i64->constInt(1, false), $zero)
            ),
            $resultSlot
        );
        $context->builder->branch($join);

        $context->builder->positionAtEnd($join);

        return $context->builder->load($resultSlot);
    }

    private static function pairCompareOperand(
        Context $context,
        Value $pairs,
        Value $pairIndex,
        Value $cmpIndex
    ): Variable {
        $pairBox = HashTableReadLlvm::readIndexedToValueBox($context, $pairs, $pairIndex);
        $pairHt = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            JitValueBox::valuePtrFromVariable($context, $pairBox)
        );

        return HashTableReadLlvm::readIndexedToValueBox($context, $pairHt, $cmpIndex);
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
