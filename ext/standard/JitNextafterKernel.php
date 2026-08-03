<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for phpc_nextafter_kernel() — IEEE bitcast leaf, no libc (#27496).
 *
 * Matches {@see VmMath::nextafter} bit walk. Used inside NextafterJitHelper so NestedJIT
 * helper units do not recurse through nextafter() or NestedJIT pack/unpack (wrong 0 under
 * thin AOT). Peer: {@see JitIsNanKernel} / {@see JitIsInfiniteKernel}.
 * php-src: ext/standard/math.c — PHP_FUNCTION(nextafter)
 */
final class JitNextafterKernel
{
    private const MIN_POS_BITS = 1; // 0x0000000000000001
    private const MIN_NEG_BITS = -9223372036854775807; // 0x8000000000000001 as signed i64

    /** @return Value double — nextafter(num, toward) */
    public static function invoke(Context $context, Value $num, Value $toward): Value
    {
        $double = $context->getTypeFromString('double');
        $i64 = $context->getTypeFromString('int64');
        $fn = $context->builder->getInsertBlock()->getParent();

        $retNum = $fn->appendBasicBlock('na_ret_num');
        $checkTowardNan = $fn->appendBasicBlock('na_check_toward_nan');
        $retToward = $fn->appendBasicBlock('na_ret_toward');
        $checkEq = $fn->appendBasicBlock('na_check_eq');
        $retEq = $fn->appendBasicBlock('na_ret_eq');
        $checkZero = $fn->appendBasicBlock('na_check_zero');
        $zeroDir = $fn->appendBasicBlock('na_zero_dir');
        $retZeroPos = $fn->appendBasicBlock('na_ret_zero_pos');
        $retZeroNeg = $fn->appendBasicBlock('na_ret_zero_neg');
        $walk = $fn->appendBasicBlock('na_walk');
        $inc = $fn->appendBasicBlock('na_inc');
        $dec = $fn->appendBasicBlock('na_dec');
        $done = $fn->appendBasicBlock('na_done');

        $numNan = $context->builder->fcmp(Builder::REAL_UNO, $num, $num);
        $context->builder->branchIf($numNan, $retNum, $checkTowardNan);

        $context->builder->positionAtEnd($retNum);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($checkTowardNan);
        $towardNan = $context->builder->fcmp(Builder::REAL_UNO, $toward, $toward);
        $context->builder->branchIf($towardNan, $retToward, $checkEq);

        $context->builder->positionAtEnd($retToward);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($checkEq);
        $eq = $context->builder->fcmp(Builder::REAL_OEQ, $num, $toward);
        $context->builder->branchIf($eq, $retEq, $checkZero);

        $context->builder->positionAtEnd($retEq);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($checkZero);
        $zero = $context->builder->fcmp(Builder::REAL_OEQ, $num, $double->constReal(0.0));
        $context->builder->branchIf($zero, $zeroDir, $walk);

        $context->builder->positionAtEnd($zeroDir);
        $towardPos = $context->builder->fcmp(Builder::REAL_OGT, $toward, $double->constReal(0.0));
        $context->builder->branchIf($towardPos, $retZeroPos, $retZeroNeg);

        $context->builder->positionAtEnd($retZeroPos);
        $minPos = $context->builder->bitCast($i64->constInt(self::MIN_POS_BITS, false), $double);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($retZeroNeg);
        $minNeg = $context->builder->bitCast($i64->constInt(self::MIN_NEG_BITS, true), $double);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($walk);
        $bits = $context->builder->bitCast($num, $i64);
        $numPos = $context->builder->fcmp(Builder::REAL_OGT, $num, $double->constReal(0.0));
        $towardGt = $context->builder->fcmp(Builder::REAL_OGT, $toward, $num);
        // ($num > 0.0) === ($toward > $num) → increment bits (VmMath::nextafter).
        $sameDir = $context->builder->icmp(Builder::INT_EQ, $numPos, $towardGt);
        $context->builder->branchIf($sameDir, $inc, $dec);

        $context->builder->positionAtEnd($inc);
        $bitsInc = $context->builder->add($bits, $i64->constInt(1, false));
        $outInc = $context->builder->bitCast($bitsInc, $double);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($dec);
        $bitsDec = $context->builder->sub($bits, $i64->constInt(1, false));
        $outDec = $context->builder->bitCast($bitsDec, $double);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $phi = $context->builder->phi($double);
        $phi->addIncoming($num, $retNum);
        $phi->addIncoming($toward, $retToward);
        $phi->addIncoming($toward, $retEq);
        $phi->addIncoming($minPos, $retZeroPos);
        $phi->addIncoming($minNeg, $retZeroNeg);
        $phi->addIncoming($outInc, $inc);
        $phi->addIncoming($outDec, $dec);

        return $phi;
    }
}
