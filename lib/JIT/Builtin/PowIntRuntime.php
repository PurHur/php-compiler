<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM implementation of __phpc_pow_int (issue #3678, #5202).
 *
 * php-src: Zend/zend_operators.c — pow_function integer fast path.
 */
final class PowIntRuntime
{
    private const LLONG_MAX = \PHP_INT_MAX;
    private const LLONG_MIN = \PHP_INT_MIN;

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__phpc_pow_int');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $valuePtr = $context->getTypeFromString('__value__*');
        $i64 = $context->getTypeFromString('int64');
        $voidTy = $context->getTypeFromString('void');
        $ft = $context->context->functionType($voidTy, false, $valuePtr, $i64, $i64);
        $fn = $context->module->addFunction('__phpc_pow_int', $ft);
        self::implementPowInt($context, $fn);
        self::registerLinkedRuntime($context);
    }

    private static function implementPowInt(Context $context, Value $fn): void
    {
        $entry = $fn->appendBasicBlock('pow_int_entry');
        $context->builder->positionAtEnd($entry);

        $out = $fn->getParam(0);
        $base = $fn->getParam(1);
        $exp = $fn->getParam(2);
        $i64 = $context->getTypeFromString('int64');
        $doubleTy = $context->getTypeFromString('double');
        $zeroI64 = $i64->constInt(0, false);
        $oneI64 = $i64->constInt(1, false);
        $nullOut = $context->builder->icmp(
            Builder::INT_EQ,
            $out,
            $out->typeOf()->constNull()
        );

        $resultSlot = $context->builder->alloca($i64, 1, 'pow_result');
        $baseSlot = $context->builder->alloca($i64, 1, 'pow_b');
        $expSlot = $context->builder->alloca($i64, 1, 'pow_e');

        $nullBb = $fn->appendBasicBlock('pow_int_null_out');
        $checkExpBb = $fn->appendBasicBlock('pow_int_check_exp');
        $negExpBb = $fn->appendBasicBlock('pow_int_neg_exp');
        $zeroExpBb = $fn->appendBasicBlock('pow_int_zero_exp');
        $loopInitBb = $fn->appendBasicBlock('pow_int_loop_init');
        $loopHeadBb = $fn->appendBasicBlock('pow_int_loop_head');
        $loopOddBb = $fn->appendBasicBlock('pow_int_loop_odd');
        $loopOddMulBb = $fn->appendBasicBlock('pow_int_loop_odd_mul');
        $loopShiftBb = $fn->appendBasicBlock('pow_int_loop_shift');
        $loopSquareBb = $fn->appendBasicBlock('pow_int_loop_square');
        $loopSquareOkBb = $fn->appendBasicBlock('pow_int_loop_square_ok');
        $successBb = $fn->appendBasicBlock('pow_int_success');
        $floatBb = $fn->appendBasicBlock('pow_int_float');

        $context->builder->branchIf($nullOut, $nullBb, $checkExpBb);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($checkExpBb);
        $negExp = $context->builder->icmp(Builder::INT_SLT, $exp, $zeroI64);
        $context->builder->branchIf($negExp, $negExpBb, $zeroExpCheckBb = $fn->appendBasicBlock('pow_int_zero_exp_check'));

        $context->builder->positionAtEnd($zeroExpCheckBb);
        $zeroExp = $context->builder->icmp(Builder::INT_EQ, $exp, $zeroI64);
        $context->builder->branchIf($zeroExp, $zeroExpBb, $loopInitBb);

        $context->builder->positionAtEnd($negExpBb);
        self::writeDoublePow($context, $out, $base, $exp, $doubleTy);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($zeroExpBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $out,
            $oneI64
        );
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($loopInitBb);
        $context->builder->store($oneI64, $resultSlot);
        $context->builder->store($base, $baseSlot);
        $context->builder->store($exp, $expSlot);
        $context->builder->branch($loopHeadBb);

        $context->builder->positionAtEnd($loopHeadBb);
        $eVal = $context->builder->load($expSlot);
        $eDone = $context->builder->icmp(Builder::INT_EQ, $eVal, $zeroI64);
        $context->builder->branchIf($eDone, $successBb, $loopOddBb);

        $context->builder->positionAtEnd($loopOddBb);
        $oddBit = $context->builder->and($eVal, $oneI64);
        $isOdd = $context->builder->icmp(Builder::INT_NE, $oddBit, $zeroI64);
        $context->builder->branchIf($isOdd, $loopOddMulBb, $loopShiftBb);

        $context->builder->positionAtEnd($loopOddMulBb);
        $resVal = $context->builder->load($resultSlot);
        $bVal = $context->builder->load($baseSlot);
        $oddOverflow = self::mulOverflows($context, $resVal, $bVal);
        $context->builder->branchIf($oddOverflow, $floatBb, $loopOddApplyBb = $fn->appendBasicBlock('pow_int_loop_odd_apply'));

        $context->builder->positionAtEnd($loopOddApplyBb);
        $context->builder->store(
            $context->builder->mulNoSignedWrap(
                $context->builder->load($resultSlot),
                $context->builder->load($baseSlot)
            ),
            $resultSlot
        );
        $context->builder->branch($loopShiftBb);
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($loopShiftBb);
        $eVal = $context->builder->load($expSlot);
        $context->builder->store(
            $context->builder->lShr($eVal, $oneI64),
            $expSlot
        );
        $eAfter = $context->builder->load($expSlot);
        $needSquare = $context->builder->icmp(Builder::INT_SGT, $eAfter, $zeroI64);
        $context->builder->branchIf($needSquare, $loopSquareBb, $loopHeadBb);

        $context->builder->positionAtEnd($loopSquareBb);
        $bVal = $context->builder->load($baseSlot);
        $sqOverflow = self::mulOverflows($context, $bVal, $bVal);
        $context->builder->branchIf($sqOverflow, $floatBb, $loopSquareOkBb);

        $context->builder->positionAtEnd($loopSquareOkBb);
        $bVal = $context->builder->load($baseSlot);
        $context->builder->store(
            $context->builder->mulNoSignedWrap($bVal, $bVal),
            $baseSlot
        );
        $context->builder->branch($loopHeadBb);
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($successBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $out,
            $context->builder->load($resultSlot)
        );
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($floatBb);
        self::writeDoublePow($context, $out, $base, $exp, $doubleTy);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function ensureLibmPow(Context $context): void
    {
        try {
            $context->lookupFunction('pow');
        } catch (\Throwable $e) {
            $double = $context->getTypeFromString('double');
            $ft = $context->context->functionType($double, false, $double, $double);
            $fn = $context->module->addFunction('pow', $ft);
            $context->registerFunction('pow', $fn);
        }
    }

    private static function writeDoublePow(
        Context $context,
        Value $out,
        Value $base,
        Value $exp,
        $doubleTy
    ): void {
        self::ensureLibmPow($context);
        $baseD = $context->builder->sitofp($base, $doubleTy);
        $expD = $context->builder->sitofp($exp, $doubleTy);
        $powFn = $context->lookupFunction('pow');
        $result = $context->builder->call($powFn, $baseD, $expD);
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $out,
            $result
        );
    }

    /** Mirrors phpc_mul_overflows in phpc_pow.c. */
    private static function mulOverflows(Context $context, Value $a, Value $b): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $zero = $i64->constInt(0, false);
        $falseVal = $i1->constInt(0, false);
        $bZero = $context->builder->icmp(Builder::INT_EQ, $b, $zero);
        $aZero = $context->builder->icmp(Builder::INT_EQ, $a, $zero);
        $noOverflow = $context->builder->or($aZero, $bZero);

        $one = $i64->constInt(1, false);
        $max = $i64->constInt(self::LLONG_MAX, false);
        $min = $i64->constInt(self::LLONG_MIN, false);
        $safeA = $context->builder->select($aZero, $one, $a);
        $safeB = $context->builder->select($bZero, $one, $b);

        $aPos = $context->builder->icmp(Builder::INT_SGT, $a, $zero);
        $aNeg = $context->builder->icmp(Builder::INT_SLT, $a, $zero);
        $bPos = $context->builder->icmp(Builder::INT_SGT, $b, $zero);
        $bNeg = $context->builder->icmp(Builder::INT_SLT, $b, $zero);

        $maxDivB = $context->builder->signedDiv($max, $safeB);
        $posPos = $context->builder->and($aPos, $bPos);
        $posPosOv = $context->builder->and($posPos, $context->builder->icmp(Builder::INT_SGT, $a, $maxDivB));

        $minDivA = $context->builder->signedDiv($min, $safeA);
        $posNeg = $context->builder->and($aPos, $bNeg);
        $posNegOv = $context->builder->and($posNeg, $context->builder->icmp(Builder::INT_SLT, $b, $minDivA));

        $minDivB = $context->builder->signedDiv($min, $safeB);
        $negPos = $context->builder->and($aNeg, $bPos);
        $negPosOv = $context->builder->and($negPos, $context->builder->icmp(Builder::INT_SLT, $a, $minDivB));

        $maxDivA = $context->builder->signedDiv($max, $safeA);
        $aNonZero = $context->builder->icmp(Builder::INT_NE, $a, $zero);
        $negNeg = $context->builder->and($aNeg, $bNeg);
        $negNegOv = $context->builder->and(
            $negNeg,
            $context->builder->and($aNonZero, $context->builder->icmp(Builder::INT_SLT, $b, $maxDivA))
        );

        $any = $context->builder->or($posPosOv, $posNegOv);
        $any = $context->builder->or($any, $negPosOv);
        $any = $context->builder->or($any, $negNegOv);

        return $context->builder->select($noOverflow, $falseVal, $any);
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction('__phpc_pow_int');
        if (null === $fn) {
            throw new \LogicException('__phpc_pow_int missing after PowIntRuntime LLVM implement');
        }
        $context->registerFunction('__phpc_pow_int', $fn);
    }
}
