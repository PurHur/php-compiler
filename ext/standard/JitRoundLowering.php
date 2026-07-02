<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MathFpow;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for round() — mirrors {@see VmRound::mathRound()} (php-src math.c).
 */
final class JitRoundLowering
{
    /** @var list<float> */
    private const POWERS_OF_10 = [
        1e0, 1e1, 1e2, 1e3, 1e4, 1e5, 1e6, 1e7, 1e8, 1e9, 1e10, 1e11,
        1e12, 1e13, 1e14, 1e15, 1e16, 1e17, 1e18, 1e19, 1e20, 1e21, 1e22,
    ];

    public static function lower(Context $context, Value $value, Value $precision, Value $mode): Value
    {
        $f64 = $context->getTypeFromString('double');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $zero = $f64->constReal(0.0);
        $one = $f64->constReal(1.0);
        $limit16 = $f64->constReal(1e16);

        $finite = self::isFinite($context, $value);
        $isZero = $context->builder->fcmp(Builder::REAL_OEQ, $value, $zero);
        $early = $context->builder->or(
            $context->builder->not($finite),
            $isZero
        );
        $earlyBlock = BasicBlockHelper::append($context, 'round_early');
        $mainBlock = BasicBlockHelper::append($context, 'round_main');
        $mergeEarly = BasicBlockHelper::append($context, 'round_early_merge');
        $context->builder->branchIf($early, $earlyBlock, $mainBlock);

        $context->builder->positionAtEnd($earlyBlock);
        $context->builder->branch($mergeEarly);

        $context->builder->positionAtEnd($mainBlock);
        $places = self::clampPlaces($context, $precision);
        $absPlaces = self::absI32($context, $places);
        $exponent = self::intPow10($context, $absPlaces);

        $placesPos = $context->builder->icmp(Builder::INT_SGT, $places, $i32->constInt(0, false));
        $scaledPos = $context->builder->select(
            $placesPos,
            $context->builder->fmul($value, $exponent),
            $context->builder->fdiv($value, $exponent)
        );
        $scaledNeg = $context->builder->select(
            $placesPos,
            $context->builder->fdiv($value, $exponent),
            $context->builder->fmul($value, $exponent)
        );

        $geZero = $context->builder->fcmp(Builder::REAL_OGE, $value, $zero);
        $posBlock = BasicBlockHelper::append($context, 'round_pos');
        $negBlock = BasicBlockHelper::append($context, 'round_neg');
        $afterSign = BasicBlockHelper::append($context, 'round_after_sign');
        $context->builder->branchIf($geZero, $posBlock, $negBlock);

        $context->builder->positionAtEnd($posBlock);
        $tmpPos = $context->builder->call($context->lookupFunction('floor'), $scaledPos);
        $tmp2Pos = $context->builder->fadd($tmpPos, $one);
        $posEnd = $context->builder->getInsertBlock();
        $context->builder->branch($afterSign);

        $context->builder->positionAtEnd($negBlock);
        $tmpNeg = $context->builder->call($context->lookupFunction('ceil'), $scaledNeg);
        $tmp2Neg = $context->builder->fsub($tmpNeg, $one);
        $negEnd = $context->builder->getInsertBlock();
        $context->builder->branch($afterSign);

        $context->builder->positionAtEnd($afterSign);
        $tmpPhi = $context->builder->phi($f64, 'round_tmp');
        $tmpPhi->addIncoming($tmpPos, $posEnd);
        $tmpPhi->addIncoming($tmpNeg, $negEnd);
        $tmp2Phi = $context->builder->phi($f64, 'round_tmp2');
        $tmp2Phi->addIncoming($tmp2Pos, $posEnd);
        $tmp2Phi->addIncoming($tmp2Neg, $negEnd);

        $scaledTmp2 = $context->builder->select(
            $placesPos,
            $context->builder->fdiv($tmp2Phi, $exponent),
            $context->builder->fmul($tmp2Phi, $exponent)
        );
        $useTmp2 = $context->builder->fcmp(Builder::REAL_OEQ, $scaledTmp2, $value);
        $tmpValue = $context->builder->select($useTmp2, $tmp2Phi, $tmpPhi);

        $absTmp = $context->builder->call($context->lookupFunction('fabs'), $tmpValue);
        $bigTmp = $context->builder->fcmp(Builder::REAL_OGE, $absTmp, $limit16);
        $bigBlock = BasicBlockHelper::append($context, 'round_big');
        $helperBlock = BasicBlockHelper::append($context, 'round_helper');
        $afterHelper = BasicBlockHelper::append($context, 'round_after_helper');
        $context->builder->branchIf($bigTmp, $bigBlock, $helperBlock);

        $context->builder->positionAtEnd($bigBlock);
        $context->builder->branch($afterHelper);

        $context->builder->positionAtEnd($helperBlock);
        $rounded = self::roundHelper($context, $tmpValue, $value, $exponent, $places, $mode);
        $helperEnd = $context->builder->getInsertBlock();
        $context->builder->branch($afterHelper);

        $context->builder->positionAtEnd($afterHelper);
        $roundedPhi = $context->builder->phi($f64, 'round_rounded');
        $roundedPhi->addIncoming($value, $bigBlock);
        $roundedPhi->addIncoming($rounded, $helperEnd);

        $absPlacesLt23 = $context->builder->icmp(
            Builder::INT_SLT,
            $absPlaces,
            $i32->constInt(23, false)
        );
        $fastBlock = BasicBlockHelper::append($context, 'round_fast');
        $slowBlock = BasicBlockHelper::append($context, 'round_slow');
        $doneBlock = BasicBlockHelper::append($context, 'round_done');
        $context->builder->branchIf($absPlacesLt23, $fastBlock, $slowBlock);

        $context->builder->positionAtEnd($fastBlock);
        $fastResult = $context->builder->select(
            $placesPos,
            $context->builder->fdiv($roundedPhi, $exponent),
            $context->builder->fmul($roundedPhi, $exponent)
        );
        $fastEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($slowBlock);
        $slowResult = self::slowFormatRound($context, $roundedPhi, $places, $value);
        $slowEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $resultPhi = $context->builder->phi($f64, 'round_result');
        $resultPhi->addIncoming($fastResult, $fastEnd);
        $resultPhi->addIncoming($slowResult, $slowEnd);
        $mainEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeEarly);

        $context->builder->positionAtEnd($mergeEarly);
        $outPhi = $context->builder->phi($f64, 'round_out');
        $outPhi->addIncoming($value, $earlyBlock);
        $outPhi->addIncoming($resultPhi, $mainEnd);

        return $outPhi;
    }

    private static function clampPlaces(Context $context, Value $precision): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $places = $context->builder->trunc($precision, $i32);
        $minPlaces = $i64->constInt(\PHP_INT_MIN + 1, true);
        $belowMin = $context->builder->icmp(Builder::INT_SLT, $precision, $minPlaces);

        return $context->builder->select(
            $belowMin,
            $context->builder->trunc($minPlaces, $i32),
            $places
        );
    }

    private static function absI32(Context $context, Value $places): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $neg = $context->builder->icmp(Builder::INT_SLT, $places, $i32->constInt(0, false));

        return $context->builder->select(
            $neg,
            $context->builder->sub($i32->constInt(0, false), $places),
            $places
        );
    }

    private static function intPow10(Context $context, Value $absPlaces): Value
    {
        $f64 = $context->getTypeFromString('double');
        $i32 = $context->getTypeFromString('int32');
        $result = $f64->constReal(self::POWERS_OF_10[22]);
        for ($power = 21; $power >= 0; --$power) {
            $match = $context->builder->icmp(
                Builder::INT_EQ,
                $absPlaces,
                $i32->constInt($power, false)
            );
            $result = $context->builder->select(
                $match,
                $f64->constReal(self::POWERS_OF_10[$power]),
                $result
            );
        }
        $usePow = $context->builder->icmp(
            Builder::INT_SGT,
            $absPlaces,
            $i32->constInt(22, false)
        );
        $powResult = MathFpow::invoke(
            $context,
            $f64->constReal(10.0),
            $context->builder->sitofp($absPlaces, $f64)
        );

        return $context->builder->select($usePow, $powResult, $result);
    }

    private static function copySign(Context $context, Value $magnitude, Value $signSource): Value
    {
        $f64 = $context->getTypeFromString('double');
        $neg = $context->builder->fsub($f64->constReal(0.0), $magnitude);
        $nonNeg = $context->builder->fcmp(Builder::REAL_OGE, $signSource, $f64->constReal(0.0));

        return $context->builder->select($nonNeg, $magnitude, $neg);
    }

    private static function getBasicEdgeCase(
        Context $context,
        Value $integral,
        Value $exponent,
        Value $places
    ): Value {
        $f64 = $context->getTypeFromString('double');
        $i32 = $context->getTypeFromString('int32');
        $half = self::copySign($context, $f64->constReal(0.5), $integral);
        $sum = $context->builder->fadd($integral, $half);
        $placesPos = $context->builder->icmp(Builder::INT_SGT, $places, $i32->constInt(0, false));
        $scaled = $context->builder->select(
            $placesPos,
            $context->builder->fdiv($sum, $exponent),
            $context->builder->fmul($sum, $exponent)
        );

        return $context->builder->call($context->lookupFunction('fabs'), $scaled);
    }

    private static function roundHelper(
        Context $context,
        Value $integral,
        Value $value,
        Value $exponent,
        Value $places,
        Value $mode
    ): Value {
        $f64 = $context->getTypeFromString('double');
        $i64 = $context->getTypeFromString('int64');
        $one = $f64->constReal(1.0);
        $zero = $f64->constReal(0.0);
        $valueAbs = $context->builder->call($context->lookupFunction('fabs'), $value);
        $edgeCase = self::getBasicEdgeCase($context, $integral, $exponent, $places);
        $bump = self::copySign($context, $one, $integral);
        $zeroEdgeCase = self::getZeroEdgeCase($context, $integral, $exponent, $places);
        $valueGtZero = $context->builder->fcmp(Builder::REAL_OGT, $value, $zero);
        $valueLtZero = $context->builder->fcmp(Builder::REAL_OLT, $value, $zero);
        $valueAbsGtZeroEdge = $context->builder->fcmp(Builder::REAL_OGT, $valueAbs, $zeroEdgeCase);

        $upBlock = BasicBlockHelper::append($context, 'round_mode_up');
        $downBlock = BasicBlockHelper::append($context, 'round_mode_down');
        $evenBlock = BasicBlockHelper::append($context, 'round_mode_even');
        $oddBlock = BasicBlockHelper::append($context, 'round_mode_odd');
        $ceilingBlock = BasicBlockHelper::append($context, 'round_mode_ceiling');
        $floorBlock = BasicBlockHelper::append($context, 'round_mode_floor');
        $towardBlock = BasicBlockHelper::append($context, 'round_mode_toward');
        $awayBlock = BasicBlockHelper::append($context, 'round_mode_away');
        $merge = BasicBlockHelper::append($context, 'round_mode_merge');

        $dispatch = BasicBlockHelper::append($context, 'round_mode_dispatch');
        $context->builder->branch($dispatch);
        $context->builder->positionAtEnd($dispatch);

        $isUp = $context->builder->icmp(Builder::INT_EQ, $mode, $i64->constInt(StdlibConstants::PHP_ROUND_HALF_UP, false));
        $isDown = $context->builder->icmp(Builder::INT_EQ, $mode, $i64->constInt(StdlibConstants::PHP_ROUND_HALF_DOWN, false));
        $isEven = $context->builder->icmp(Builder::INT_EQ, $mode, $i64->constInt(StdlibConstants::PHP_ROUND_HALF_EVEN, false));
        $isOdd = $context->builder->icmp(Builder::INT_EQ, $mode, $i64->constInt(StdlibConstants::PHP_ROUND_HALF_ODD, false));
        $isCeiling = $context->builder->icmp(Builder::INT_EQ, $mode, $i64->constInt(StdlibConstants::PHP_ROUND_CEILING, false));
        $isFloor = $context->builder->icmp(Builder::INT_EQ, $mode, $i64->constInt(StdlibConstants::PHP_ROUND_FLOOR, false));
        $isToward = $context->builder->icmp(Builder::INT_EQ, $mode, $i64->constInt(StdlibConstants::PHP_ROUND_TOWARD_ZERO, false));
        $isAway = $context->builder->icmp(Builder::INT_EQ, $mode, $i64->constInt(StdlibConstants::PHP_ROUND_AWAY_FROM_ZERO, false));

        $d1 = BasicBlockHelper::append($context, 'round_dispatch_1');
        $context->builder->branchIf($isUp, $upBlock, $d1);
        $context->builder->positionAtEnd($d1);
        $d2 = BasicBlockHelper::append($context, 'round_dispatch_2');
        $context->builder->branchIf($isDown, $downBlock, $d2);
        $context->builder->positionAtEnd($d2);
        $d3 = BasicBlockHelper::append($context, 'round_dispatch_3');
        $context->builder->branchIf($isEven, $evenBlock, $d3);
        $context->builder->positionAtEnd($d3);
        $d4 = BasicBlockHelper::append($context, 'round_dispatch_4');
        $context->builder->branchIf($isOdd, $oddBlock, $d4);
        $context->builder->positionAtEnd($d4);
        $d5 = BasicBlockHelper::append($context, 'round_dispatch_5');
        $context->builder->branchIf($isCeiling, $ceilingBlock, $d5);
        $context->builder->positionAtEnd($d5);
        $d6 = BasicBlockHelper::append($context, 'round_dispatch_6');
        $context->builder->branchIf($isFloor, $floorBlock, $d6);
        $context->builder->positionAtEnd($d6);
        $d7 = BasicBlockHelper::append($context, 'round_dispatch_7');
        $context->builder->branchIf($isToward, $towardBlock, $d7);
        $context->builder->positionAtEnd($d7);
        $defaultBlock = BasicBlockHelper::append($context, 'round_mode_default');
        $context->builder->branchIf($isAway, $awayBlock, $defaultBlock);
        $context->builder->positionAtEnd($defaultBlock);
        // php-src 8.2: unrecognized mode ints use PHP_ROUND_HALF_UP.
        $context->builder->branch($upBlock);

        $context->builder->positionAtEnd($upBlock);
        $upGe = $context->builder->fcmp(Builder::REAL_OGE, $valueAbs, $edgeCase);
        $upResult = $context->builder->select($upGe, $context->builder->fadd($integral, $bump), $integral);
        $upEnd = $context->builder->getInsertBlock();
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($downBlock);
        $downGt = $context->builder->fcmp(Builder::REAL_OGT, $valueAbs, $edgeCase);
        $downResult = $context->builder->select($downGt, $context->builder->fadd($integral, $bump), $integral);
        $downEnd = $context->builder->getInsertBlock();
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($evenBlock);
        $evenResult = self::roundHalfEvenOdd($context, $integral, $valueAbs, $edgeCase, $bump, false);
        $evenEnd = $context->builder->getInsertBlock();
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($oddBlock);
        $oddResult = self::roundHalfEvenOdd($context, $integral, $valueAbs, $edgeCase, $bump, true);
        $oddEnd = $context->builder->getInsertBlock();
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($ceilingBlock);
        $ceilBump = $context->builder->and($valueGtZero, $valueAbsGtZeroEdge);
        $ceilingResult = $context->builder->select(
            $ceilBump,
            $context->builder->fadd($integral, $one),
            $integral
        );
        $ceilingEnd = $context->builder->getInsertBlock();
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($floorBlock);
        $floorBump = $context->builder->and($valueLtZero, $valueAbsGtZeroEdge);
        $floorResult = $context->builder->select(
            $floorBump,
            $context->builder->fsub($integral, $one),
            $integral
        );
        $floorEnd = $context->builder->getInsertBlock();
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($towardBlock);
        $towardResult = $integral;
        $towardEnd = $context->builder->getInsertBlock();
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($awayBlock);
        $awayResult = $context->builder->select(
            $valueAbsGtZeroEdge,
            $context->builder->fadd($integral, $bump),
            $integral
        );
        $awayEnd = $context->builder->getInsertBlock();
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);
        $phi = $context->builder->phi($f64, 'round_helper_out');
        $phi->addIncoming($upResult, $upEnd);
        $phi->addIncoming($downResult, $downEnd);
        $phi->addIncoming($evenResult, $evenEnd);
        $phi->addIncoming($oddResult, $oddEnd);
        $phi->addIncoming($ceilingResult, $ceilingEnd);
        $phi->addIncoming($floorResult, $floorEnd);
        $phi->addIncoming($towardResult, $towardEnd);
        $phi->addIncoming($awayResult, $awayEnd);

        return $phi;
    }

    private static function getZeroEdgeCase(
        Context $context,
        Value $integral,
        Value $exponent,
        Value $places
    ): Value {
        $f64 = $context->getTypeFromString('double');
        $i32 = $context->getTypeFromString('int32');
        $placesPos = $context->builder->icmp(Builder::INT_SGT, $places, $i32->constInt(0, false));
        $scaled = $context->builder->select(
            $placesPos,
            $context->builder->fdiv($integral, $exponent),
            $context->builder->fmul($integral, $exponent)
        );

        return $context->builder->call($context->lookupFunction('fabs'), $scaled);
    }

    private static function roundHalfEvenOdd(
        Context $context,
        Value $integral,
        Value $valueAbs,
        Value $edgeCase,
        Value $bump,
        bool $oddMode
    ): Value {
        $f64 = $context->getTypeFromString('double');
        $two = $f64->constReal(2.0);
        $gt = $context->builder->fcmp(Builder::REAL_OGT, $valueAbs, $edgeCase);
        $eq = $context->builder->fcmp(Builder::REAL_OEQ, $valueAbs, $edgeCase);
        $even = $context->builder->fcmp(
            Builder::REAL_OEQ,
            $context->builder->call($context->lookupFunction('fmod'), $integral, $two),
            $f64->constReal(0.0)
        );
        $tieBump = $oddMode ? $even : $context->builder->not($even);
        $doTieBump = $context->builder->and($eq, $tieBump);
        $doBump = $context->builder->or($gt, $doTieBump);

        return $context->builder->select($doBump, $context->builder->fadd($integral, $bump), $integral);
    }

    private static function slowFormatRound(
        Context $context,
        Value $tmpValue,
        Value $places,
        Value $fallback
    ): Value {
        $f64 = $context->getTypeFromString('double');
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $buf = $context->builder->alloca($i8, 40, 'round_snprintf_buf');
        $charPtr = $context->getTypeFromString('char*');
        $fmt = $context->builder->pointerCast(
            $context->constantFromString('%15fe%d'),
            $charPtr
        );
        $negPlaces = $context->builder->sub($i32->constInt(0, false), $places);
        $context->builder->call(
            $context->lookupFunction('snprintf'),
            $context->builder->pointerCast($buf, $charPtr),
            $sizeT->constInt(40, false),
            $fmt,
            $tmpValue,
            $negPlaces
        );
        $endPtr = $context->builder->alloca($i8p, 1, 'round_strtod_end');
        $context->builder->store($i8p->constNull(), $endPtr);
        $converted = $context->builder->call(
            $context->lookupFunction('strtod'),
            $context->builder->pointerCast($buf, $charPtr),
            $endPtr
        );
        $bad = $context->builder->not(self::isFinite($context, $converted));

        return $context->builder->select($bad, $fallback, $converted);
    }

    /** glibc exports isnan/isinf but not isfinite (header macro); compose for AOT link. */
    private static function isFinite(Context $context, Value $value): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $zero = $i32->constInt(0, false);
        $nan = $context->builder->call($context->lookupFunction('isnan'), $value);
        $inf = $context->builder->call($context->lookupFunction('isinf'), $value);
        $notNan = $context->builder->icmp(Builder::INT_EQ, $nan, $zero);
        $notInf = $context->builder->icmp(Builder::INT_EQ, $inf, $zero);

        return $context->builder->and($notNan, $notInf);
    }
}
