<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT lowering for levenshtein() — mirrors ext/standard/VmString::levenshtein().
 *
 * php-src: ext/standard/levenshtein.c — PHP_FUNCTION(levenshtein)
 */
final class JitLevenshtein
{
    private static int $blockSerial = 0;

    public static function invoke(
        Context $context,
        Value $s1,
        Value $s2,
        Value $ins,
        Value $rep,
        Value $del
    ): Value {
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i64p = $context->getTypeFromString('int64*');
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $eight = $i64->constInt(8, false);

        $ins = self::clampCost($context, $ins, $one);
        $rep = self::clampCost($context, $rep, $one);
        $del = self::clampCost($context, $del, $one);

        $len1 = $context->builder->load($context->builder->structGep($s1, $map['length']));
        $len2 = $context->builder->load($context->builder->structGep($s2, $map['length']));
        $data1 = $context->builder->structGep($s1, $map['value']);
        $data2 = $context->builder->structGep($s2, $map['value']);

        $id = (string) (++self::$blockSerial);
        $empty1Bb = BasicBlockHelper::append($context, 'lev_empty1_'.$id);
        $check2Bb = BasicBlockHelper::append($context, 'lev_check2_'.$id);
        $empty2Bb = BasicBlockHelper::append($context, 'lev_empty2_'.$id);
        $dpBb = BasicBlockHelper::append($context, 'lev_dp_'.$id);
        $doneBb = BasicBlockHelper::append($context, 'lev_done_'.$id);

        $resultSlot = $context->builder->alloca($i64, 1, 'lev_result_'.$id);

        $len1Zero = $context->builder->icmp(Builder::INT_EQ, $len1, $zero);
        $context->builder->branchIf($len1Zero, $empty1Bb, $check2Bb);

        $context->builder->positionAtEnd($empty1Bb);
        $context->builder->store($context->builder->mul($len2, $ins), $resultSlot);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($check2Bb);
        $len2Zero = $context->builder->icmp(Builder::INT_EQ, $len2, $zero);
        $context->builder->branchIf($len2Zero, $empty2Bb, $dpBb);

        $context->builder->positionAtEnd($empty2Bb);
        $context->builder->store($context->builder->mul($len1, $del), $resultSlot);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($dpBb);
        $rowLen = $context->builder->addNoSignedWrap($len2, $one);
        $rowBytes = $context->builder->mul($rowLen, $eight);
        $malloc = $context->lookupFunction('malloc');
        $free = $context->lookupFunction('free');
        $prevRaw = $context->builder->call($malloc, $context->builder->truncOrBitCast($rowBytes, $sizeT));
        $curRaw = $context->builder->call($malloc, $context->builder->truncOrBitCast($rowBytes, $sizeT));
        $prevSlot = $context->builder->alloca($i64p, 1, 'lev_prev_'.$id);
        $curSlot = $context->builder->alloca($i64p, 1, 'lev_cur_'.$id);
        $context->builder->store(
            $context->builder->pointerCast($prevRaw, $i64p),
            $prevSlot
        );
        $context->builder->store(
            $context->builder->pointerCast($curRaw, $i64p),
            $curSlot
        );

        $jSlot = $context->builder->alloca($i64, 1, 'lev_init_j_'.$id);
        $context->builder->store($zero, $jSlot);
        $initHead = BasicBlockHelper::append($context, 'lev_init_head_'.$id);
        $initBody = BasicBlockHelper::append($context, 'lev_init_body_'.$id);
        $initDone = BasicBlockHelper::append($context, 'lev_init_done_'.$id);
        $context->builder->branch($initHead);
        $context->builder->positionAtEnd($initHead);
        $j = $context->builder->load($jSlot);
        $initPast = $context->builder->icmp(Builder::INT_SGT, $j, $len2);
        $context->builder->branchIf($initPast, $initDone, $initBody);
        $context->builder->positionAtEnd($initBody);
        $prevPtr = $context->builder->load($prevSlot);
        $context->builder->store(
            $context->builder->mul($j, $ins),
            $context->builder->inBoundsGEP($prevPtr, $j)
        );
        $context->builder->store($context->builder->addNoSignedWrap($j, $one), $jSlot);
        $context->builder->branch($initHead);
        $context->builder->positionAtEnd($initDone);

        $iSlot = $context->builder->alloca($i64, 1, 'lev_i_'.$id);
        $context->builder->store($one, $iSlot);
        $outerHead = BasicBlockHelper::append($context, 'lev_outer_head_'.$id);
        $outerBody = BasicBlockHelper::append($context, 'lev_outer_body_'.$id);
        $outerDone = BasicBlockHelper::append($context, 'lev_outer_done_'.$id);
        $context->builder->branch($outerHead);
        $context->builder->positionAtEnd($outerHead);
        $i = $context->builder->load($iSlot);
        $outerPast = $context->builder->icmp(Builder::INT_SGT, $i, $len1);
        $context->builder->branchIf($outerPast, $outerDone, $outerBody);

        $context->builder->positionAtEnd($outerBody);
        $prevPtr = $context->builder->load($prevSlot);
        $curPtr = $context->builder->load($curSlot);
        $context->builder->store(
            $context->builder->mul($i, $del),
            $context->builder->inBoundsGEP($curPtr, $zero)
        );
        $jSlot2 = $context->builder->alloca($i64, 1, 'lev_inner_j_'.$id);
        $context->builder->store($one, $jSlot2);
        $innerHead = BasicBlockHelper::append($context, 'lev_inner_head_'.$id);
        $innerBody = BasicBlockHelper::append($context, 'lev_inner_body_'.$id);
        $innerDone = BasicBlockHelper::append($context, 'lev_inner_done_'.$id);
        $context->builder->branch($innerHead);
        $context->builder->positionAtEnd($innerHead);
        $j2 = $context->builder->load($jSlot2);
        $innerPast = $context->builder->icmp(Builder::INT_SGT, $j2, $len2);
        $context->builder->branchIf($innerPast, $innerDone, $innerBody);

        $context->builder->positionAtEnd($innerBody);
        $prevPtr = $context->builder->load($prevSlot);
        $curPtr = $context->builder->load($curSlot);
        $iIdx = $context->builder->addNoSignedWrap($i, $i64->constInt(-1, true));
        $jIdx = $context->builder->addNoSignedWrap($j2, $i64->constInt(-1, true));
        $jPrev = $context->builder->addNoSignedWrap($j2, $i64->constInt(-1, true));
        $ch1 = $context->builder->load($context->builder->inBoundsGEP($data1, $iIdx));
        $ch2 = $context->builder->load($context->builder->inBoundsGEP($data2, $jIdx));
        $same = $context->builder->icmp(Builder::INT_EQ, $ch1, $ch2);
        $subst = $context->builder->select($same, $zero, $rep);
        $delVal = $context->builder->addNoSignedWrap(
            $context->builder->load($context->builder->inBoundsGEP($curPtr, $jPrev)),
            $ins
        );
        $insVal = $context->builder->addNoSignedWrap(
            $context->builder->load($context->builder->inBoundsGEP($prevPtr, $j2)),
            $del
        );
        $repVal = $context->builder->addNoSignedWrap(
            $context->builder->load($context->builder->inBoundsGEP($prevPtr, $jPrev)),
            $subst
        );
        $best = self::min3($context, $delVal, $insVal, $repVal);
        $context->builder->store($best, $context->builder->inBoundsGEP($curPtr, $j2));
        $context->builder->store($context->builder->addNoSignedWrap($j2, $one), $jSlot2);
        $context->builder->branch($innerHead);

        $context->builder->positionAtEnd($innerDone);
        $swapTmp = $context->builder->load($prevSlot);
        $context->builder->store($context->builder->load($curSlot), $prevSlot);
        $context->builder->store($swapTmp, $curSlot);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($outerHead);

        $context->builder->positionAtEnd($outerDone);
        $prevPtr = $context->builder->load($prevSlot);
        $context->builder->store(
            $context->builder->load($context->builder->inBoundsGEP($prevPtr, $len2)),
            $resultSlot
        );
        $context->builder->call($free, $prevRaw);
        $context->builder->call($free, $curRaw);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $context->builder->load($resultSlot);
    }

    private static function clampCost(Context $context, Value $cost, Value $one): Value
    {
        $ltOne = $context->builder->icmp(Builder::INT_SLT, $cost, $one);

        return $context->builder->select($ltOne, $one, $cost);
    }

    private static function min3(Context $context, Value $a, Value $b, Value $c): Value
    {
        $ab = $context->builder->select(
            $context->builder->icmp(Builder::INT_SLT, $a, $b),
            $a,
            $b
        );

        return $context->builder->select(
            $context->builder->icmp(Builder::INT_SLT, $ab, $c),
            $ab,
            $c
        );
    }
}
