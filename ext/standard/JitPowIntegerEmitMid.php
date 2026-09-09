<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitLongArithOverflow;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\OpCode;
use PHPLLVM\Value;

/**
 * Mid compile-time exponent integer {@code pow}/{@code **} chained-smul
 * emit (thirtieth … fortyninth) (#36387 / #36386).
 *
 * Extracted from {@see JitPowIntegerEmit} so gen-0 spine gets another TU
 * instead of one ~25k-line hub after High (70–89) split.
 *
 * No new C ABI. php-src: Zend/zend_operators.c {@code pow_function} /
 * {@code zend_pow} / {@code mul_function}; ext/standard/math.c
 * {@code PHP_FUNCTION(pow)}.
 */
final class JitPowIntegerEmitMid
{
    /**
     * @return bool true when {@code $expFold} was a mid exponent and emit ran
     */
    public static function tryEmitIntegerPowViaMathFpow(
        Context $context,
        Value $slotPtr,
        JITVariable $base,
        JITVariable $exp,
        ?string $expFold
    ): bool {
        if (null === $expFold) {
            return false;
        }
        static $mid = [
            'thirtieth' => true,
            'thirtyfirst' => true,
            'thirtysecond' => true,
            'thirtythird' => true,
            'thirtyfourth' => true,
            'thirtyfifth' => true,
            'thirtysixth' => true,
            'thirtyseventh' => true,
            'thirtyeighth' => true,
            'thirtyninth' => true,
            'fortieth' => true,
            'fortyfirst' => true,
            'fortysecond' => true,
            'fortythird' => true,
            'fortyfourth' => true,
            'fortyfifth' => true,
            'fortysixth' => true,
            'fortyseventh' => true,
            'fortyeighth' => true,
            'fortyninth' => true,
        ];
        if (!isset($mid[$expFold])) {
            return false;
        }

        if ('thirtieth' === $expFold) {
            // n^30 = fifteenth*fifteenth: sq=n*n, cu=sq*n, sixth=cu*cu,
            // seventh=sixth*n, fourteenth=seventh*seventh,
            // fifteenth=fourteenth*n, then fifteenth*fifteenth.
            // Overflow arms finish in float (sqF^14*nF^2, ((cuF^2)*nF)^4*nF^2,
            // (sixthF*nF)^4*nF^2, seventhF^4*nF^2, fourteenthF^2*nF^2,
            // fifteenthF^2).
            $baseL = JitLongArg::lower($context, $base, 'pow() base');
            $i64 = $context->getTypeFromString('int64');
            $f64 = $context->getTypeFromString('double');
            $n = $context->builder->intCast($baseL, $i64);
            $sqVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $n,
                $n
            );
            $ov1 = JitLongArithOverflow::loadOverflowFlagI1($context, $sqVar->longArithOverflowFlag);
            if (null === $ov1 || null === $sqVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **30 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_thirtieth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_thirtieth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_thirtieth_done');
            $context->builder->branchIf($ov1, $ov1Block, $ok1Block);

            $context->builder->positionAtEnd($ov1Block);
            $sqF = $context->builder->load($sqVar->longArithOverflowDoubleSlot);
            $nF = $context->builder->siToFp($n, $f64);
            $sq2F = $context->builder->fmul($sqF, $sqF);
            $sq4F = $context->builder->fmul($sq2F, $sq2F);
            $sq6F = $context->builder->fmul($sq4F, $sq2F);
            $fourteenthF = $context->builder->fmul($sq6F, $sqF);
            $fifteenthF = $context->builder->fmul($fourteenthF, $nF);
            $thirtiethF = $context->builder->fmul($fifteenthF, $fifteenthF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtiethF
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok1Block);
            $cuVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sqLong,
                $n
            );
            $ov2 = JitLongArithOverflow::loadOverflowFlagI1($context, $cuVar->longArithOverflowFlag);
            if (null === $ov2 || null === $cuVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **30 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_thirtieth_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_thirtieth_cu_ok');
            $context->builder->branchIf($ov2, $ov2Block, $ok2Block);

            $context->builder->positionAtEnd($ov2Block);
            $cuF = $context->builder->load($cuVar->longArithOverflowDoubleSlot);
            $nF2 = $context->builder->siToFp($n, $f64);
            $cu2F = $context->builder->fmul($cuF, $cuF);
            $seventhF2 = $context->builder->fmul($cu2F, $nF2);
            $fourteenthF2 = $context->builder->fmul($seventhF2, $seventhF2);
            $fifteenthF2 = $context->builder->fmul($fourteenthF2, $nF2);
            $thirtiethF2 = $context->builder->fmul($fifteenthF2, $fifteenthF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtiethF2
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok2Block);
            $sixthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $cuLong,
                $cuLong
            );
            $ov3 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixthVar->longArithOverflowFlag);
            if (null === $ov3 || null === $sixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **30 expected smul overflow metadata (sixth)');
            }
            $sixthLong = JITVariable::KIND_VARIABLE === $sixthVar->kind
                ? $context->builder->load($sixthVar->value)
                : $sixthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_thirtieth_sixth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_thirtieth_sixth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $sixthF = $context->builder->load($sixthVar->longArithOverflowDoubleSlot);
            $nF3 = $context->builder->siToFp($n, $f64);
            $seventhF3 = $context->builder->fmul($sixthF, $nF3);
            $fourteenthF3 = $context->builder->fmul($seventhF3, $seventhF3);
            $fifteenthF3 = $context->builder->fmul($fourteenthF3, $nF3);
            $thirtiethF3 = $context->builder->fmul($fifteenthF3, $fifteenthF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtiethF3
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok3Block);
            $seventhVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixthLong,
                $n
            );
            $ov4 = JitLongArithOverflow::loadOverflowFlagI1($context, $seventhVar->longArithOverflowFlag);
            if (null === $ov4 || null === $seventhVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **30 expected smul overflow metadata (seventh)');
            }
            $seventhLong = JITVariable::KIND_VARIABLE === $seventhVar->kind
                ? $context->builder->load($seventhVar->value)
                : $seventhVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_thirtieth_seventh_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_thirtieth_seventh_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $seventhF4 = $context->builder->load($seventhVar->longArithOverflowDoubleSlot);
            $nF4 = $context->builder->siToFp($n, $f64);
            $fourteenthF4 = $context->builder->fmul($seventhF4, $seventhF4);
            $fifteenthF4 = $context->builder->fmul($fourteenthF4, $nF4);
            $thirtiethF4 = $context->builder->fmul($fifteenthF4, $fifteenthF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtiethF4
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok4Block);
            $fourteenthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $seventhLong,
                $seventhLong
            );
            $ov5 = JitLongArithOverflow::loadOverflowFlagI1($context, $fourteenthVar->longArithOverflowFlag);
            if (null === $ov5 || null === $fourteenthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **30 expected smul overflow metadata (fourteenth)');
            }
            $fourteenthLong = JITVariable::KIND_VARIABLE === $fourteenthVar->kind
                ? $context->builder->load($fourteenthVar->value)
                : $fourteenthVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_thirtieth_fourteenth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_thirtieth_fourteenth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $fourteenthF5 = $context->builder->load($fourteenthVar->longArithOverflowDoubleSlot);
            $nF5 = $context->builder->siToFp($n, $f64);
            $fifteenthF5 = $context->builder->fmul($fourteenthF5, $nF5);
            $thirtiethF5 = $context->builder->fmul($fifteenthF5, $fifteenthF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtiethF5
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok5Block);
            $fifteenthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fourteenthLong,
                $n
            );
            $ov6 = JitLongArithOverflow::loadOverflowFlagI1($context, $fifteenthVar->longArithOverflowFlag);
            if (null === $ov6 || null === $fifteenthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **30 expected smul overflow metadata (fifteenth)');
            }
            $fifteenthLong = JITVariable::KIND_VARIABLE === $fifteenthVar->kind
                ? $context->builder->load($fifteenthVar->value)
                : $fifteenthVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_thirtieth_fifteenth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_thirtieth_fifteenth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $fifteenthF6 = $context->builder->load($fifteenthVar->longArithOverflowDoubleSlot);
            $thirtiethF6 = $context->builder->fmul($fifteenthF6, $fifteenthF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtiethF6
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok6Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $fifteenthLong,
                $fifteenthLong,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return true;
        }

        if ('thirtyfirst' === $expFold) {
            // n^31 = thirtieth*n: sq=n*n, cu=sq*n, sixth=cu*cu,
            // seventh=sixth*n, fourteenth=seventh*seventh,
            // fifteenth=fourteenth*n, thirtieth=fifteenth*fifteenth,
            // then thirtieth*n.
            // Overflow arms finish in float (sqF^15*nF, ((cuF^2)*nF)^4*nF^2*nF,
            // (sixthF*nF)^4*nF^2*nF, seventhF^4*nF^2*nF, fourteenthF^2*nF^2*nF,
            // fifteenthF^2*nF, thirtiethF*nF).
            $baseL = JitLongArg::lower($context, $base, 'pow() base');
            $i64 = $context->getTypeFromString('int64');
            $f64 = $context->getTypeFromString('double');
            $n = $context->builder->intCast($baseL, $i64);
            $sqVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $n,
                $n
            );
            $ov1 = JitLongArithOverflow::loadOverflowFlagI1($context, $sqVar->longArithOverflowFlag);
            if (null === $ov1 || null === $sqVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **31 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_thirtyfirst_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_thirtyfirst_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_thirtyfirst_done');
            $context->builder->branchIf($ov1, $ov1Block, $ok1Block);

            $context->builder->positionAtEnd($ov1Block);
            $sqF = $context->builder->load($sqVar->longArithOverflowDoubleSlot);
            $nF = $context->builder->siToFp($n, $f64);
            $sq2F = $context->builder->fmul($sqF, $sqF);
            $sq4F = $context->builder->fmul($sq2F, $sq2F);
            $sq6F = $context->builder->fmul($sq4F, $sq2F);
            $fourteenthF = $context->builder->fmul($sq6F, $sqF);
            $fifteenthF = $context->builder->fmul($fourteenthF, $nF);
            $thirtiethF = $context->builder->fmul($fifteenthF, $fifteenthF);
            $thirtyfirstF = $context->builder->fmul($thirtiethF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtyfirstF
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok1Block);
            $cuVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sqLong,
                $n
            );
            $ov2 = JitLongArithOverflow::loadOverflowFlagI1($context, $cuVar->longArithOverflowFlag);
            if (null === $ov2 || null === $cuVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **31 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_thirtyfirst_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_thirtyfirst_cu_ok');
            $context->builder->branchIf($ov2, $ov2Block, $ok2Block);

            $context->builder->positionAtEnd($ov2Block);
            $cuF = $context->builder->load($cuVar->longArithOverflowDoubleSlot);
            $nF2 = $context->builder->siToFp($n, $f64);
            $cu2F = $context->builder->fmul($cuF, $cuF);
            $seventhF2 = $context->builder->fmul($cu2F, $nF2);
            $fourteenthF2 = $context->builder->fmul($seventhF2, $seventhF2);
            $fifteenthF2 = $context->builder->fmul($fourteenthF2, $nF2);
            $thirtiethF2 = $context->builder->fmul($fifteenthF2, $fifteenthF2);
            $thirtyfirstF2 = $context->builder->fmul($thirtiethF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtyfirstF2
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok2Block);
            $sixthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $cuLong,
                $cuLong
            );
            $ov3 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixthVar->longArithOverflowFlag);
            if (null === $ov3 || null === $sixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **31 expected smul overflow metadata (sixth)');
            }
            $sixthLong = JITVariable::KIND_VARIABLE === $sixthVar->kind
                ? $context->builder->load($sixthVar->value)
                : $sixthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_thirtyfirst_sixth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_thirtyfirst_sixth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $sixthF = $context->builder->load($sixthVar->longArithOverflowDoubleSlot);
            $nF3 = $context->builder->siToFp($n, $f64);
            $seventhF3 = $context->builder->fmul($sixthF, $nF3);
            $fourteenthF3 = $context->builder->fmul($seventhF3, $seventhF3);
            $fifteenthF3 = $context->builder->fmul($fourteenthF3, $nF3);
            $thirtiethF3 = $context->builder->fmul($fifteenthF3, $fifteenthF3);
            $thirtyfirstF3 = $context->builder->fmul($thirtiethF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtyfirstF3
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok3Block);
            $seventhVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixthLong,
                $n
            );
            $ov4 = JitLongArithOverflow::loadOverflowFlagI1($context, $seventhVar->longArithOverflowFlag);
            if (null === $ov4 || null === $seventhVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **31 expected smul overflow metadata (seventh)');
            }
            $seventhLong = JITVariable::KIND_VARIABLE === $seventhVar->kind
                ? $context->builder->load($seventhVar->value)
                : $seventhVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_thirtyfirst_seventh_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_thirtyfirst_seventh_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $seventhF4 = $context->builder->load($seventhVar->longArithOverflowDoubleSlot);
            $nF4 = $context->builder->siToFp($n, $f64);
            $fourteenthF4 = $context->builder->fmul($seventhF4, $seventhF4);
            $fifteenthF4 = $context->builder->fmul($fourteenthF4, $nF4);
            $thirtiethF4 = $context->builder->fmul($fifteenthF4, $fifteenthF4);
            $thirtyfirstF4 = $context->builder->fmul($thirtiethF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtyfirstF4
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok4Block);
            $fourteenthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $seventhLong,
                $seventhLong
            );
            $ov5 = JitLongArithOverflow::loadOverflowFlagI1($context, $fourteenthVar->longArithOverflowFlag);
            if (null === $ov5 || null === $fourteenthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **31 expected smul overflow metadata (fourteenth)');
            }
            $fourteenthLong = JITVariable::KIND_VARIABLE === $fourteenthVar->kind
                ? $context->builder->load($fourteenthVar->value)
                : $fourteenthVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_thirtyfirst_fourteenth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_thirtyfirst_fourteenth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $fourteenthF5 = $context->builder->load($fourteenthVar->longArithOverflowDoubleSlot);
            $nF5 = $context->builder->siToFp($n, $f64);
            $fifteenthF5 = $context->builder->fmul($fourteenthF5, $nF5);
            $thirtiethF5 = $context->builder->fmul($fifteenthF5, $fifteenthF5);
            $thirtyfirstF5 = $context->builder->fmul($thirtiethF5, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtyfirstF5
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok5Block);
            $fifteenthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fourteenthLong,
                $n
            );
            $ov6 = JitLongArithOverflow::loadOverflowFlagI1($context, $fifteenthVar->longArithOverflowFlag);
            if (null === $ov6 || null === $fifteenthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **31 expected smul overflow metadata (fifteenth)');
            }
            $fifteenthLong = JITVariable::KIND_VARIABLE === $fifteenthVar->kind
                ? $context->builder->load($fifteenthVar->value)
                : $fifteenthVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_thirtyfirst_fifteenth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_thirtyfirst_fifteenth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $fifteenthF6 = $context->builder->load($fifteenthVar->longArithOverflowDoubleSlot);
            $nF6 = $context->builder->siToFp($n, $f64);
            $thirtiethF6 = $context->builder->fmul($fifteenthF6, $fifteenthF6);
            $thirtyfirstF6 = $context->builder->fmul($thirtiethF6, $nF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtyfirstF6
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok6Block);
            $thirtiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fifteenthLong,
                $fifteenthLong
            );
            $ov7 = JitLongArithOverflow::loadOverflowFlagI1($context, $thirtiethVar->longArithOverflowFlag);
            if (null === $ov7 || null === $thirtiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **31 expected smul overflow metadata (thirtieth)');
            }
            $thirtiethLong = JITVariable::KIND_VARIABLE === $thirtiethVar->kind
                ? $context->builder->load($thirtiethVar->value)
                : $thirtiethVar->value;
            $ov7Block = BasicBlockHelper::append($context, 'pow_thirtyfirst_thirtieth_ov');
            $ok7Block = BasicBlockHelper::append($context, 'pow_thirtyfirst_thirtieth_ok');
            $context->builder->branchIf($ov7, $ov7Block, $ok7Block);

            $context->builder->positionAtEnd($ov7Block);
            $thirtiethF7 = $context->builder->load($thirtiethVar->longArithOverflowDoubleSlot);
            $nF7 = $context->builder->siToFp($n, $f64);
            $thirtyfirstF7 = $context->builder->fmul($thirtiethF7, $nF7);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtyfirstF7
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok7Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $thirtiethLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return true;
        }

        if ('thirtysecond' === $expFold) {
            // n^32 = sixteenth*sixteenth: sq=n*n, fourth=sq*sq, eighth=fourth*fourth,
            // sixteenth=eighth*eighth, then sixteenth*sixteenth.
            // Overflow arms finish in float (sqF^16, fourthF^8, eighthF^4,
            // sixteenthF^2).
            $baseL = JitLongArg::lower($context, $base, 'pow() base');
            $i64 = $context->getTypeFromString('int64');
            $n = $context->builder->intCast($baseL, $i64);
            $sqVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $n,
                $n
            );
            $ov1 = JitLongArithOverflow::loadOverflowFlagI1($context, $sqVar->longArithOverflowFlag);
            if (null === $ov1 || null === $sqVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **32 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_thirtysecond_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_thirtysecond_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_thirtysecond_done');
            $context->builder->branchIf($ov1, $ov1Block, $ok1Block);

            $context->builder->positionAtEnd($ov1Block);
            $sqF = $context->builder->load($sqVar->longArithOverflowDoubleSlot);
            $sq2F = $context->builder->fmul($sqF, $sqF);
            $sq4F = $context->builder->fmul($sq2F, $sq2F);
            $sq8F = $context->builder->fmul($sq4F, $sq4F);
            $thirtysecondF = $context->builder->fmul($sq8F, $sq8F);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtysecondF
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok1Block);
            $fourthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sqLong,
                $sqLong
            );
            $ov2 = JitLongArithOverflow::loadOverflowFlagI1($context, $fourthVar->longArithOverflowFlag);
            if (null === $ov2 || null === $fourthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **32 expected smul overflow metadata (fourth)');
            }
            $fourthLong = JITVariable::KIND_VARIABLE === $fourthVar->kind
                ? $context->builder->load($fourthVar->value)
                : $fourthVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_thirtysecond_fourth_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_thirtysecond_fourth_ok');
            $context->builder->branchIf($ov2, $ov2Block, $ok2Block);

            $context->builder->positionAtEnd($ov2Block);
            $fourthF = $context->builder->load($fourthVar->longArithOverflowDoubleSlot);
            $fourth2F = $context->builder->fmul($fourthF, $fourthF);
            $fourth4F = $context->builder->fmul($fourth2F, $fourth2F);
            $thirtysecondF2 = $context->builder->fmul($fourth4F, $fourth4F);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtysecondF2
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok2Block);
            $eighthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fourthLong,
                $fourthLong
            );
            $ov3 = JitLongArithOverflow::loadOverflowFlagI1($context, $eighthVar->longArithOverflowFlag);
            if (null === $ov3 || null === $eighthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **32 expected smul overflow metadata (eighth)');
            }
            $eighthLong = JITVariable::KIND_VARIABLE === $eighthVar->kind
                ? $context->builder->load($eighthVar->value)
                : $eighthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_thirtysecond_eighth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_thirtysecond_eighth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $eighthF = $context->builder->load($eighthVar->longArithOverflowDoubleSlot);
            $eighth2F = $context->builder->fmul($eighthF, $eighthF);
            $thirtysecondF3 = $context->builder->fmul($eighth2F, $eighth2F);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtysecondF3
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok3Block);
            $sixteenthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $eighthLong,
                $eighthLong
            );
            $ov4 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixteenthVar->longArithOverflowFlag);
            if (null === $ov4 || null === $sixteenthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **32 expected smul overflow metadata (sixteenth)');
            }
            $sixteenthLong = JITVariable::KIND_VARIABLE === $sixteenthVar->kind
                ? $context->builder->load($sixteenthVar->value)
                : $sixteenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_thirtysecond_sixteenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_thirtysecond_sixteenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $sixteenthF4 = $context->builder->load($sixteenthVar->longArithOverflowDoubleSlot);
            $thirtysecondF4 = $context->builder->fmul($sixteenthF4, $sixteenthF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtysecondF4
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok4Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $sixteenthLong,
                $sixteenthLong,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return true;
        }

        if ('thirtythird' === $expFold) {
            // n^33 = thirtysecond*n: sq=n*n, fourth=sq*sq, eighth=fourth*fourth,
            // sixteenth=eighth*eighth, thirtysecond=sixteenth*sixteenth,
            // then thirtysecond*n.
            // Overflow arms finish in float (sqF^16*nF, fourthF^8*nF,
            // eighthF^4*nF, sixteenthF^2*nF, thirtysecondF*nF).
            $baseL = JitLongArg::lower($context, $base, 'pow() base');
            $i64 = $context->getTypeFromString('int64');
            $f64 = $context->getTypeFromString('double');
            $n = $context->builder->intCast($baseL, $i64);
            $sqVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $n,
                $n
            );
            $ov1 = JitLongArithOverflow::loadOverflowFlagI1($context, $sqVar->longArithOverflowFlag);
            if (null === $ov1 || null === $sqVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **33 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_thirtythird_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_thirtythird_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_thirtythird_done');
            $context->builder->branchIf($ov1, $ov1Block, $ok1Block);

            $context->builder->positionAtEnd($ov1Block);
            $sqF = $context->builder->load($sqVar->longArithOverflowDoubleSlot);
            $nF = $context->builder->siToFp($n, $f64);
            $sq2F = $context->builder->fmul($sqF, $sqF);
            $sq4F = $context->builder->fmul($sq2F, $sq2F);
            $sq8F = $context->builder->fmul($sq4F, $sq4F);
            $thirtysecondF = $context->builder->fmul($sq8F, $sq8F);
            $thirtythirdF = $context->builder->fmul($thirtysecondF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtythirdF
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok1Block);
            $fourthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sqLong,
                $sqLong
            );
            $ov2 = JitLongArithOverflow::loadOverflowFlagI1($context, $fourthVar->longArithOverflowFlag);
            if (null === $ov2 || null === $fourthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **33 expected smul overflow metadata (fourth)');
            }
            $fourthLong = JITVariable::KIND_VARIABLE === $fourthVar->kind
                ? $context->builder->load($fourthVar->value)
                : $fourthVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_thirtythird_fourth_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_thirtythird_fourth_ok');
            $context->builder->branchIf($ov2, $ov2Block, $ok2Block);

            $context->builder->positionAtEnd($ov2Block);
            $fourthF = $context->builder->load($fourthVar->longArithOverflowDoubleSlot);
            $nF2 = $context->builder->siToFp($n, $f64);
            $fourth2F = $context->builder->fmul($fourthF, $fourthF);
            $fourth4F = $context->builder->fmul($fourth2F, $fourth2F);
            $thirtysecondF2 = $context->builder->fmul($fourth4F, $fourth4F);
            $thirtythirdF2 = $context->builder->fmul($thirtysecondF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtythirdF2
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok2Block);
            $eighthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fourthLong,
                $fourthLong
            );
            $ov3 = JitLongArithOverflow::loadOverflowFlagI1($context, $eighthVar->longArithOverflowFlag);
            if (null === $ov3 || null === $eighthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **33 expected smul overflow metadata (eighth)');
            }
            $eighthLong = JITVariable::KIND_VARIABLE === $eighthVar->kind
                ? $context->builder->load($eighthVar->value)
                : $eighthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_thirtythird_eighth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_thirtythird_eighth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $eighthF = $context->builder->load($eighthVar->longArithOverflowDoubleSlot);
            $nF3 = $context->builder->siToFp($n, $f64);
            $eighth2F = $context->builder->fmul($eighthF, $eighthF);
            $thirtysecondF3 = $context->builder->fmul($eighth2F, $eighth2F);
            $thirtythirdF3 = $context->builder->fmul($thirtysecondF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtythirdF3
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok3Block);
            $sixteenthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $eighthLong,
                $eighthLong
            );
            $ov4 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixteenthVar->longArithOverflowFlag);
            if (null === $ov4 || null === $sixteenthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **33 expected smul overflow metadata (sixteenth)');
            }
            $sixteenthLong = JITVariable::KIND_VARIABLE === $sixteenthVar->kind
                ? $context->builder->load($sixteenthVar->value)
                : $sixteenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_thirtythird_sixteenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_thirtythird_sixteenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $sixteenthF4 = $context->builder->load($sixteenthVar->longArithOverflowDoubleSlot);
            $nF4 = $context->builder->siToFp($n, $f64);
            $thirtysecondF4 = $context->builder->fmul($sixteenthF4, $sixteenthF4);
            $thirtythirdF4 = $context->builder->fmul($thirtysecondF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtythirdF4
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok4Block);
            $thirtysecondVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixteenthLong,
                $sixteenthLong
            );
            $ov5 = JitLongArithOverflow::loadOverflowFlagI1($context, $thirtysecondVar->longArithOverflowFlag);
            if (null === $ov5 || null === $thirtysecondVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **33 expected smul overflow metadata (thirtysecond)');
            }
            $thirtysecondLong = JITVariable::KIND_VARIABLE === $thirtysecondVar->kind
                ? $context->builder->load($thirtysecondVar->value)
                : $thirtysecondVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_thirtythird_thirtysecond_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_thirtythird_thirtysecond_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $thirtysecondF5 = $context->builder->load($thirtysecondVar->longArithOverflowDoubleSlot);
            $nF5 = $context->builder->siToFp($n, $f64);
            $thirtythirdF5 = $context->builder->fmul($thirtysecondF5, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtythirdF5
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok5Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $thirtysecondLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return true;
        }

        if ('thirtyfourth' === $expFold) {
            // n^34 = seventeenth*seventeenth: sq=n*n, fourth=sq*sq,
            // eighth=fourth*fourth, sixteenth=eighth*eighth,
            // seventeenth=sixteenth*n, then seventeenth*seventeenth.
            // Overflow arms finish in float (sqF^17, fourthF^8*nF^2,
            // eighthF^4*nF^2, sixteenthF^2*nF^2, seventeenthF^2).
            $baseL = JitLongArg::lower($context, $base, 'pow() base');
            $i64 = $context->getTypeFromString('int64');
            $f64 = $context->getTypeFromString('double');
            $n = $context->builder->intCast($baseL, $i64);
            $sqVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $n,
                $n
            );
            $ov1 = JitLongArithOverflow::loadOverflowFlagI1($context, $sqVar->longArithOverflowFlag);
            if (null === $ov1 || null === $sqVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **34 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_thirtyfourth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_thirtyfourth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_thirtyfourth_done');
            $context->builder->branchIf($ov1, $ov1Block, $ok1Block);

            $context->builder->positionAtEnd($ov1Block);
            $sqF = $context->builder->load($sqVar->longArithOverflowDoubleSlot);
            $sq2F = $context->builder->fmul($sqF, $sqF);
            $sq4F = $context->builder->fmul($sq2F, $sq2F);
            $sq8F = $context->builder->fmul($sq4F, $sq4F);
            $sq16F = $context->builder->fmul($sq8F, $sq8F);
            $thirtyfourthF = $context->builder->fmul($sq16F, $sqF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtyfourthF
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok1Block);
            $fourthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sqLong,
                $sqLong
            );
            $ov2 = JitLongArithOverflow::loadOverflowFlagI1($context, $fourthVar->longArithOverflowFlag);
            if (null === $ov2 || null === $fourthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **34 expected smul overflow metadata (fourth)');
            }
            $fourthLong = JITVariable::KIND_VARIABLE === $fourthVar->kind
                ? $context->builder->load($fourthVar->value)
                : $fourthVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_thirtyfourth_fourth_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_thirtyfourth_fourth_ok');
            $context->builder->branchIf($ov2, $ov2Block, $ok2Block);

            $context->builder->positionAtEnd($ov2Block);
            $fourthF = $context->builder->load($fourthVar->longArithOverflowDoubleSlot);
            $nF2 = $context->builder->siToFp($n, $f64);
            $n2F2 = $context->builder->fmul($nF2, $nF2);
            $fourth2F = $context->builder->fmul($fourthF, $fourthF);
            $fourth4F = $context->builder->fmul($fourth2F, $fourth2F);
            $thirtysecondF2 = $context->builder->fmul($fourth4F, $fourth4F);
            $thirtyfourthF2 = $context->builder->fmul($thirtysecondF2, $n2F2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtyfourthF2
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok2Block);
            $eighthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fourthLong,
                $fourthLong
            );
            $ov3 = JitLongArithOverflow::loadOverflowFlagI1($context, $eighthVar->longArithOverflowFlag);
            if (null === $ov3 || null === $eighthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **34 expected smul overflow metadata (eighth)');
            }
            $eighthLong = JITVariable::KIND_VARIABLE === $eighthVar->kind
                ? $context->builder->load($eighthVar->value)
                : $eighthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_thirtyfourth_eighth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_thirtyfourth_eighth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $eighthF = $context->builder->load($eighthVar->longArithOverflowDoubleSlot);
            $nF3 = $context->builder->siToFp($n, $f64);
            $n2F3 = $context->builder->fmul($nF3, $nF3);
            $eighth2F = $context->builder->fmul($eighthF, $eighthF);
            $thirtysecondF3 = $context->builder->fmul($eighth2F, $eighth2F);
            $thirtyfourthF3 = $context->builder->fmul($thirtysecondF3, $n2F3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtyfourthF3
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok3Block);
            $sixteenthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $eighthLong,
                $eighthLong
            );
            $ov4 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixteenthVar->longArithOverflowFlag);
            if (null === $ov4 || null === $sixteenthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **34 expected smul overflow metadata (sixteenth)');
            }
            $sixteenthLong = JITVariable::KIND_VARIABLE === $sixteenthVar->kind
                ? $context->builder->load($sixteenthVar->value)
                : $sixteenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_thirtyfourth_sixteenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_thirtyfourth_sixteenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $sixteenthF4 = $context->builder->load($sixteenthVar->longArithOverflowDoubleSlot);
            $nF4 = $context->builder->siToFp($n, $f64);
            $n2F4 = $context->builder->fmul($nF4, $nF4);
            $thirtysecondF4 = $context->builder->fmul($sixteenthF4, $sixteenthF4);
            $thirtyfourthF4 = $context->builder->fmul($thirtysecondF4, $n2F4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtyfourthF4
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok4Block);
            $seventeenthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixteenthLong,
                $n
            );
            $ov5 = JitLongArithOverflow::loadOverflowFlagI1($context, $seventeenthVar->longArithOverflowFlag);
            if (null === $ov5 || null === $seventeenthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **34 expected smul overflow metadata (seventeenth)');
            }
            $seventeenthLong = JITVariable::KIND_VARIABLE === $seventeenthVar->kind
                ? $context->builder->load($seventeenthVar->value)
                : $seventeenthVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_thirtyfourth_seventeenth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_thirtyfourth_seventeenth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $seventeenthF5 = $context->builder->load($seventeenthVar->longArithOverflowDoubleSlot);
            $thirtyfourthF5 = $context->builder->fmul($seventeenthF5, $seventeenthF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtyfourthF5
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok5Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $seventeenthLong,
                $seventeenthLong,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return true;
        }

        if ('thirtyfifth' === $expFold) {
            // n^35 = thirtyfourth*n: sq=n*n, fourth=sq*sq, eighth=fourth*fourth,
            // sixteenth=eighth*eighth, seventeenth=sixteenth*n,
            // thirtyfourth=seventeenth*seventeenth, then thirtyfourth*n.
            // Overflow arms finish in float (sqF^17*nF, fourthF^8*nF^3,
            // eighthF^4*nF^3, sixteenthF^2*nF^3, seventeenthF^2*nF,
            // thirtyfourthF*nF).
            $baseL = JitLongArg::lower($context, $base, 'pow() base');
            $i64 = $context->getTypeFromString('int64');
            $f64 = $context->getTypeFromString('double');
            $n = $context->builder->intCast($baseL, $i64);
            $sqVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $n,
                $n
            );
            $ov1 = JitLongArithOverflow::loadOverflowFlagI1($context, $sqVar->longArithOverflowFlag);
            if (null === $ov1 || null === $sqVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **35 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_thirtyfifth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_thirtyfifth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_thirtyfifth_done');
            $context->builder->branchIf($ov1, $ov1Block, $ok1Block);

            $context->builder->positionAtEnd($ov1Block);
            $sqF = $context->builder->load($sqVar->longArithOverflowDoubleSlot);
            $nF = $context->builder->siToFp($n, $f64);
            $sq2F = $context->builder->fmul($sqF, $sqF);
            $sq4F = $context->builder->fmul($sq2F, $sq2F);
            $sq8F = $context->builder->fmul($sq4F, $sq4F);
            $sq16F = $context->builder->fmul($sq8F, $sq8F);
            $thirtyfourthF = $context->builder->fmul($sq16F, $sqF);
            $thirtyfifthF = $context->builder->fmul($thirtyfourthF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtyfifthF
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok1Block);
            $fourthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sqLong,
                $sqLong
            );
            $ov2 = JitLongArithOverflow::loadOverflowFlagI1($context, $fourthVar->longArithOverflowFlag);
            if (null === $ov2 || null === $fourthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **35 expected smul overflow metadata (fourth)');
            }
            $fourthLong = JITVariable::KIND_VARIABLE === $fourthVar->kind
                ? $context->builder->load($fourthVar->value)
                : $fourthVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_thirtyfifth_fourth_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_thirtyfifth_fourth_ok');
            $context->builder->branchIf($ov2, $ov2Block, $ok2Block);

            $context->builder->positionAtEnd($ov2Block);
            $fourthF = $context->builder->load($fourthVar->longArithOverflowDoubleSlot);
            $nF2 = $context->builder->siToFp($n, $f64);
            $n2F2 = $context->builder->fmul($nF2, $nF2);
            $fourth2F = $context->builder->fmul($fourthF, $fourthF);
            $fourth4F = $context->builder->fmul($fourth2F, $fourth2F);
            $thirtysecondF2 = $context->builder->fmul($fourth4F, $fourth4F);
            $thirtyfourthF2 = $context->builder->fmul($thirtysecondF2, $n2F2);
            $thirtyfifthF2 = $context->builder->fmul($thirtyfourthF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtyfifthF2
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok2Block);
            $eighthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fourthLong,
                $fourthLong
            );
            $ov3 = JitLongArithOverflow::loadOverflowFlagI1($context, $eighthVar->longArithOverflowFlag);
            if (null === $ov3 || null === $eighthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **35 expected smul overflow metadata (eighth)');
            }
            $eighthLong = JITVariable::KIND_VARIABLE === $eighthVar->kind
                ? $context->builder->load($eighthVar->value)
                : $eighthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_thirtyfifth_eighth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_thirtyfifth_eighth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $eighthF = $context->builder->load($eighthVar->longArithOverflowDoubleSlot);
            $nF3 = $context->builder->siToFp($n, $f64);
            $n2F3 = $context->builder->fmul($nF3, $nF3);
            $eighth2F = $context->builder->fmul($eighthF, $eighthF);
            $thirtysecondF3 = $context->builder->fmul($eighth2F, $eighth2F);
            $thirtyfourthF3 = $context->builder->fmul($thirtysecondF3, $n2F3);
            $thirtyfifthF3 = $context->builder->fmul($thirtyfourthF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtyfifthF3
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok3Block);
            $sixteenthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $eighthLong,
                $eighthLong
            );
            $ov4 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixteenthVar->longArithOverflowFlag);
            if (null === $ov4 || null === $sixteenthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **35 expected smul overflow metadata (sixteenth)');
            }
            $sixteenthLong = JITVariable::KIND_VARIABLE === $sixteenthVar->kind
                ? $context->builder->load($sixteenthVar->value)
                : $sixteenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_thirtyfifth_sixteenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_thirtyfifth_sixteenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $sixteenthF4 = $context->builder->load($sixteenthVar->longArithOverflowDoubleSlot);
            $nF4 = $context->builder->siToFp($n, $f64);
            $n2F4 = $context->builder->fmul($nF4, $nF4);
            $thirtysecondF4 = $context->builder->fmul($sixteenthF4, $sixteenthF4);
            $thirtyfourthF4 = $context->builder->fmul($thirtysecondF4, $n2F4);
            $thirtyfifthF4 = $context->builder->fmul($thirtyfourthF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtyfifthF4
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok4Block);
            $seventeenthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixteenthLong,
                $n
            );
            $ov5 = JitLongArithOverflow::loadOverflowFlagI1($context, $seventeenthVar->longArithOverflowFlag);
            if (null === $ov5 || null === $seventeenthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **35 expected smul overflow metadata (seventeenth)');
            }
            $seventeenthLong = JITVariable::KIND_VARIABLE === $seventeenthVar->kind
                ? $context->builder->load($seventeenthVar->value)
                : $seventeenthVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_thirtyfifth_seventeenth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_thirtyfifth_seventeenth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $seventeenthF5 = $context->builder->load($seventeenthVar->longArithOverflowDoubleSlot);
            $nF5 = $context->builder->siToFp($n, $f64);
            $thirtyfourthF5 = $context->builder->fmul($seventeenthF5, $seventeenthF5);
            $thirtyfifthF5 = $context->builder->fmul($thirtyfourthF5, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtyfifthF5
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok5Block);
            $thirtyfourthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $seventeenthLong,
                $seventeenthLong
            );
            $ov6 = JitLongArithOverflow::loadOverflowFlagI1($context, $thirtyfourthVar->longArithOverflowFlag);
            if (null === $ov6 || null === $thirtyfourthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **35 expected smul overflow metadata (thirtyfourth)');
            }
            $thirtyfourthLong = JITVariable::KIND_VARIABLE === $thirtyfourthVar->kind
                ? $context->builder->load($thirtyfourthVar->value)
                : $thirtyfourthVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_thirtyfifth_thirtyfourth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_thirtyfifth_thirtyfourth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $thirtyfourthF6 = $context->builder->load($thirtyfourthVar->longArithOverflowDoubleSlot);
            $nF6 = $context->builder->siToFp($n, $f64);
            $thirtyfifthF6 = $context->builder->fmul($thirtyfourthF6, $nF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtyfifthF6
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok6Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $thirtyfourthLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return true;
        }

        if ('thirtysixth' === $expFold) {
            // n^36 = eighteenth*eighteenth: sq=n*n, cu=sq*n, sixth=cu*cu,
            // ninth=sixth*cu, eighteenth=ninth*ninth, then
            // eighteenth*eighteenth. Overflow arms finish in float
            // (sqF^18, ((cuF^3)^2)^2, ((sixthF*cuF)^2)^2, ninthF^4,
            // eighteenthF^2).
            $baseL = JitLongArg::lower($context, $base, 'pow() base');
            $i64 = $context->getTypeFromString('int64');
            $f64 = $context->getTypeFromString('double');
            $n = $context->builder->intCast($baseL, $i64);
            $sqVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $n,
                $n
            );
            $ov1 = JitLongArithOverflow::loadOverflowFlagI1($context, $sqVar->longArithOverflowFlag);
            if (null === $ov1 || null === $sqVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **36 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_thirtysixth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_thirtysixth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_thirtysixth_done');
            $context->builder->branchIf($ov1, $ov1Block, $ok1Block);

            $context->builder->positionAtEnd($ov1Block);
            $sqF = $context->builder->load($sqVar->longArithOverflowDoubleSlot);
            $sq2F = $context->builder->fmul($sqF, $sqF);
            $sq4F = $context->builder->fmul($sq2F, $sq2F);
            $sq8F = $context->builder->fmul($sq4F, $sq4F);
            $eighteenthF = $context->builder->fmul($sq8F, $sqF);
            $thirtysixthF = $context->builder->fmul($eighteenthF, $eighteenthF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtysixthF
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok1Block);
            $cuVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sqLong,
                $n
            );
            $ov2 = JitLongArithOverflow::loadOverflowFlagI1($context, $cuVar->longArithOverflowFlag);
            if (null === $ov2 || null === $cuVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **36 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_thirtysixth_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_thirtysixth_cu_ok');
            $context->builder->branchIf($ov2, $ov2Block, $ok2Block);

            $context->builder->positionAtEnd($ov2Block);
            $cuF = $context->builder->load($cuVar->longArithOverflowDoubleSlot);
            $cu2F = $context->builder->fmul($cuF, $cuF);
            $ninthF2 = $context->builder->fmul($cu2F, $cuF);
            $eighteenthF2 = $context->builder->fmul($ninthF2, $ninthF2);
            $thirtysixthF2 = $context->builder->fmul($eighteenthF2, $eighteenthF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtysixthF2
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok2Block);
            $sixthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $cuLong,
                $cuLong
            );
            $ov3 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixthVar->longArithOverflowFlag);
            if (null === $ov3 || null === $sixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **36 expected smul overflow metadata (sixth)');
            }
            $sixthLong = JITVariable::KIND_VARIABLE === $sixthVar->kind
                ? $context->builder->load($sixthVar->value)
                : $sixthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_thirtysixth_sixth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_thirtysixth_sixth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $sixthF = $context->builder->load($sixthVar->longArithOverflowDoubleSlot);
            $cuF3 = $context->builder->siToFp($cuLong, $f64);
            $ninthF3 = $context->builder->fmul($sixthF, $cuF3);
            $eighteenthF3 = $context->builder->fmul($ninthF3, $ninthF3);
            $thirtysixthF3 = $context->builder->fmul($eighteenthF3, $eighteenthF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtysixthF3
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok3Block);
            $ninthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixthLong,
                $cuLong
            );
            $ov4 = JitLongArithOverflow::loadOverflowFlagI1($context, $ninthVar->longArithOverflowFlag);
            if (null === $ov4 || null === $ninthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **36 expected smul overflow metadata (ninth)');
            }
            $ninthLong = JITVariable::KIND_VARIABLE === $ninthVar->kind
                ? $context->builder->load($ninthVar->value)
                : $ninthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_thirtysixth_ninth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_thirtysixth_ninth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $ninthF4 = $context->builder->load($ninthVar->longArithOverflowDoubleSlot);
            $eighteenthF4 = $context->builder->fmul($ninthF4, $ninthF4);
            $thirtysixthF4 = $context->builder->fmul($eighteenthF4, $eighteenthF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtysixthF4
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok4Block);
            $eighteenthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $ninthLong,
                $ninthLong
            );
            $ov5 = JitLongArithOverflow::loadOverflowFlagI1($context, $eighteenthVar->longArithOverflowFlag);
            if (null === $ov5 || null === $eighteenthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **36 expected smul overflow metadata (eighteenth)');
            }
            $eighteenthLong = JITVariable::KIND_VARIABLE === $eighteenthVar->kind
                ? $context->builder->load($eighteenthVar->value)
                : $eighteenthVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_thirtysixth_eighteenth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_thirtysixth_eighteenth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $eighteenthF5 = $context->builder->load($eighteenthVar->longArithOverflowDoubleSlot);
            $thirtysixthF5 = $context->builder->fmul($eighteenthF5, $eighteenthF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtysixthF5
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok5Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $eighteenthLong,
                $eighteenthLong,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return true;
        }

        if ('thirtyseventh' === $expFold) {
            // n^37 = thirtysixth*n: sq=n*n, cu=sq*n, sixth=cu*cu,
            // ninth=sixth*cu, eighteenth=ninth*ninth,
            // thirtysixth=eighteenth*eighteenth, then thirtysixth*n.
            // Overflow arms finish in float (sqF^18*nF, ((cuF^3)^2)^2*nF,
            // ((sixthF*cuF)^2)^2*nF, ninthF^4*nF, eighteenthF^2*nF,
            // thirtysixthF*nF).
            $baseL = JitLongArg::lower($context, $base, 'pow() base');
            $i64 = $context->getTypeFromString('int64');
            $f64 = $context->getTypeFromString('double');
            $n = $context->builder->intCast($baseL, $i64);
            $sqVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $n,
                $n
            );
            $ov1 = JitLongArithOverflow::loadOverflowFlagI1($context, $sqVar->longArithOverflowFlag);
            if (null === $ov1 || null === $sqVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **37 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_thirtyseventh_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_thirtyseventh_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_thirtyseventh_done');
            $context->builder->branchIf($ov1, $ov1Block, $ok1Block);

            $context->builder->positionAtEnd($ov1Block);
            $sqF = $context->builder->load($sqVar->longArithOverflowDoubleSlot);
            $nF = $context->builder->siToFp($n, $f64);
            $sq2F = $context->builder->fmul($sqF, $sqF);
            $sq4F = $context->builder->fmul($sq2F, $sq2F);
            $sq8F = $context->builder->fmul($sq4F, $sq4F);
            $eighteenthF = $context->builder->fmul($sq8F, $sqF);
            $thirtysixthF = $context->builder->fmul($eighteenthF, $eighteenthF);
            $thirtyseventhF = $context->builder->fmul($thirtysixthF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtyseventhF
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok1Block);
            $cuVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sqLong,
                $n
            );
            $ov2 = JitLongArithOverflow::loadOverflowFlagI1($context, $cuVar->longArithOverflowFlag);
            if (null === $ov2 || null === $cuVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **37 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_thirtyseventh_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_thirtyseventh_cu_ok');
            $context->builder->branchIf($ov2, $ov2Block, $ok2Block);

            $context->builder->positionAtEnd($ov2Block);
            $cuF = $context->builder->load($cuVar->longArithOverflowDoubleSlot);
            $nF2 = $context->builder->siToFp($n, $f64);
            $cu2F = $context->builder->fmul($cuF, $cuF);
            $ninthF2 = $context->builder->fmul($cu2F, $cuF);
            $eighteenthF2 = $context->builder->fmul($ninthF2, $ninthF2);
            $thirtysixthF2 = $context->builder->fmul($eighteenthF2, $eighteenthF2);
            $thirtyseventhF2 = $context->builder->fmul($thirtysixthF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtyseventhF2
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok2Block);
            $sixthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $cuLong,
                $cuLong
            );
            $ov3 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixthVar->longArithOverflowFlag);
            if (null === $ov3 || null === $sixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **37 expected smul overflow metadata (sixth)');
            }
            $sixthLong = JITVariable::KIND_VARIABLE === $sixthVar->kind
                ? $context->builder->load($sixthVar->value)
                : $sixthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_thirtyseventh_sixth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_thirtyseventh_sixth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $sixthF = $context->builder->load($sixthVar->longArithOverflowDoubleSlot);
            $cuF3 = $context->builder->siToFp($cuLong, $f64);
            $nF3 = $context->builder->siToFp($n, $f64);
            $ninthF3 = $context->builder->fmul($sixthF, $cuF3);
            $eighteenthF3 = $context->builder->fmul($ninthF3, $ninthF3);
            $thirtysixthF3 = $context->builder->fmul($eighteenthF3, $eighteenthF3);
            $thirtyseventhF3 = $context->builder->fmul($thirtysixthF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtyseventhF3
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok3Block);
            $ninthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixthLong,
                $cuLong
            );
            $ov4 = JitLongArithOverflow::loadOverflowFlagI1($context, $ninthVar->longArithOverflowFlag);
            if (null === $ov4 || null === $ninthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **37 expected smul overflow metadata (ninth)');
            }
            $ninthLong = JITVariable::KIND_VARIABLE === $ninthVar->kind
                ? $context->builder->load($ninthVar->value)
                : $ninthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_thirtyseventh_ninth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_thirtyseventh_ninth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $ninthF4 = $context->builder->load($ninthVar->longArithOverflowDoubleSlot);
            $nF4 = $context->builder->siToFp($n, $f64);
            $eighteenthF4 = $context->builder->fmul($ninthF4, $ninthF4);
            $thirtysixthF4 = $context->builder->fmul($eighteenthF4, $eighteenthF4);
            $thirtyseventhF4 = $context->builder->fmul($thirtysixthF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtyseventhF4
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok4Block);
            $eighteenthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $ninthLong,
                $ninthLong
            );
            $ov5 = JitLongArithOverflow::loadOverflowFlagI1($context, $eighteenthVar->longArithOverflowFlag);
            if (null === $ov5 || null === $eighteenthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **37 expected smul overflow metadata (eighteenth)');
            }
            $eighteenthLong = JITVariable::KIND_VARIABLE === $eighteenthVar->kind
                ? $context->builder->load($eighteenthVar->value)
                : $eighteenthVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_thirtyseventh_eighteenth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_thirtyseventh_eighteenth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $eighteenthF5 = $context->builder->load($eighteenthVar->longArithOverflowDoubleSlot);
            $nF5 = $context->builder->siToFp($n, $f64);
            $thirtysixthF5 = $context->builder->fmul($eighteenthF5, $eighteenthF5);
            $thirtyseventhF5 = $context->builder->fmul($thirtysixthF5, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtyseventhF5
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok5Block);
            $thirtysixthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $eighteenthLong,
                $eighteenthLong
            );
            $ov6 = JitLongArithOverflow::loadOverflowFlagI1($context, $thirtysixthVar->longArithOverflowFlag);
            if (null === $ov6 || null === $thirtysixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **37 expected smul overflow metadata (thirtysixth)');
            }
            $thirtysixthLong = JITVariable::KIND_VARIABLE === $thirtysixthVar->kind
                ? $context->builder->load($thirtysixthVar->value)
                : $thirtysixthVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_thirtyseventh_thirtysixth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_thirtyseventh_thirtysixth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $thirtysixthF6 = $context->builder->load($thirtysixthVar->longArithOverflowDoubleSlot);
            $nF6 = $context->builder->siToFp($n, $f64);
            $thirtyseventhF6 = $context->builder->fmul($thirtysixthF6, $nF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtyseventhF6
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok6Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $thirtysixthLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return true;
        }
        if ('thirtyeighth' === $expFold) {
            // n^38 = thirtyseventh*n: sq=n*n, cu=sq*n, sixth=cu*cu,
            // ninth=sixth*cu, eighteenth=ninth*ninth,
            // thirtysixth=eighteenth*eighteenth, thirtyseventh=thirtysixth*n,
            // then thirtyseventh*n. Overflow arms finish in float
            // (sqF^18*nF^2, ((cuF^3)^2)^2*nF^2, ((sixthF*cuF)^2)^2*nF^2,
            // ninthF^4*nF^2, eighteenthF^2*nF^2, thirtysixthF*nF^2,
            // thirtyseventhF*nF).
            $baseL = JitLongArg::lower($context, $base, 'pow() base');
            $i64 = $context->getTypeFromString('int64');
            $f64 = $context->getTypeFromString('double');
            $n = $context->builder->intCast($baseL, $i64);
            $sqVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $n,
                $n
            );
            $ov1 = JitLongArithOverflow::loadOverflowFlagI1($context, $sqVar->longArithOverflowFlag);
            if (null === $ov1 || null === $sqVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **38 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_thirtyeighth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_thirtyeighth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_thirtyeighth_done');
            $context->builder->branchIf($ov1, $ov1Block, $ok1Block);

            $context->builder->positionAtEnd($ov1Block);
            $sqF = $context->builder->load($sqVar->longArithOverflowDoubleSlot);
            $nF = $context->builder->siToFp($n, $f64);
            $sq2F = $context->builder->fmul($sqF, $sqF);
            $sq4F = $context->builder->fmul($sq2F, $sq2F);
            $sq8F = $context->builder->fmul($sq4F, $sq4F);
            $eighteenthF = $context->builder->fmul($sq8F, $sqF);
            $thirtysixthF = $context->builder->fmul($eighteenthF, $eighteenthF);
            $thirtyseventhF = $context->builder->fmul($thirtysixthF, $nF);
            $thirtyeighthF = $context->builder->fmul($thirtyseventhF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtyeighthF
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok1Block);
            $cuVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sqLong,
                $n
            );
            $ov2 = JitLongArithOverflow::loadOverflowFlagI1($context, $cuVar->longArithOverflowFlag);
            if (null === $ov2 || null === $cuVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **38 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_thirtyeighth_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_thirtyeighth_cu_ok');
            $context->builder->branchIf($ov2, $ov2Block, $ok2Block);

            $context->builder->positionAtEnd($ov2Block);
            $cuF = $context->builder->load($cuVar->longArithOverflowDoubleSlot);
            $nF2 = $context->builder->siToFp($n, $f64);
            $cu2F = $context->builder->fmul($cuF, $cuF);
            $ninthF2 = $context->builder->fmul($cu2F, $cuF);
            $eighteenthF2 = $context->builder->fmul($ninthF2, $ninthF2);
            $thirtysixthF2 = $context->builder->fmul($eighteenthF2, $eighteenthF2);
            $thirtyseventhF2 = $context->builder->fmul($thirtysixthF2, $nF2);
            $thirtyeighthF2 = $context->builder->fmul($thirtyseventhF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtyeighthF2
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok2Block);
            $sixthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $cuLong,
                $cuLong
            );
            $ov3 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixthVar->longArithOverflowFlag);
            if (null === $ov3 || null === $sixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **38 expected smul overflow metadata (sixth)');
            }
            $sixthLong = JITVariable::KIND_VARIABLE === $sixthVar->kind
                ? $context->builder->load($sixthVar->value)
                : $sixthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_thirtyeighth_sixth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_thirtyeighth_sixth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $sixthF = $context->builder->load($sixthVar->longArithOverflowDoubleSlot);
            $cuF3 = $context->builder->siToFp($cuLong, $f64);
            $nF3 = $context->builder->siToFp($n, $f64);
            $ninthF3 = $context->builder->fmul($sixthF, $cuF3);
            $eighteenthF3 = $context->builder->fmul($ninthF3, $ninthF3);
            $thirtysixthF3 = $context->builder->fmul($eighteenthF3, $eighteenthF3);
            $thirtyseventhF3 = $context->builder->fmul($thirtysixthF3, $nF3);
            $thirtyeighthF3 = $context->builder->fmul($thirtyseventhF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtyeighthF3
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok3Block);
            $ninthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixthLong,
                $cuLong
            );
            $ov4 = JitLongArithOverflow::loadOverflowFlagI1($context, $ninthVar->longArithOverflowFlag);
            if (null === $ov4 || null === $ninthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **38 expected smul overflow metadata (ninth)');
            }
            $ninthLong = JITVariable::KIND_VARIABLE === $ninthVar->kind
                ? $context->builder->load($ninthVar->value)
                : $ninthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_thirtyeighth_ninth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_thirtyeighth_ninth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $ninthF4 = $context->builder->load($ninthVar->longArithOverflowDoubleSlot);
            $nF4 = $context->builder->siToFp($n, $f64);
            $eighteenthF4 = $context->builder->fmul($ninthF4, $ninthF4);
            $thirtysixthF4 = $context->builder->fmul($eighteenthF4, $eighteenthF4);
            $thirtyseventhF4 = $context->builder->fmul($thirtysixthF4, $nF4);
            $thirtyeighthF4 = $context->builder->fmul($thirtyseventhF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtyeighthF4
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok4Block);
            $eighteenthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $ninthLong,
                $ninthLong
            );
            $ov5 = JitLongArithOverflow::loadOverflowFlagI1($context, $eighteenthVar->longArithOverflowFlag);
            if (null === $ov5 || null === $eighteenthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **38 expected smul overflow metadata (eighteenth)');
            }
            $eighteenthLong = JITVariable::KIND_VARIABLE === $eighteenthVar->kind
                ? $context->builder->load($eighteenthVar->value)
                : $eighteenthVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_thirtyeighth_eighteenth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_thirtyeighth_eighteenth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $eighteenthF5 = $context->builder->load($eighteenthVar->longArithOverflowDoubleSlot);
            $nF5 = $context->builder->siToFp($n, $f64);
            $thirtysixthF5 = $context->builder->fmul($eighteenthF5, $eighteenthF5);
            $thirtyseventhF5 = $context->builder->fmul($thirtysixthF5, $nF5);
            $thirtyeighthF5 = $context->builder->fmul($thirtyseventhF5, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtyeighthF5
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok5Block);
            $thirtysixthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $eighteenthLong,
                $eighteenthLong
            );
            $ov6 = JitLongArithOverflow::loadOverflowFlagI1($context, $thirtysixthVar->longArithOverflowFlag);
            if (null === $ov6 || null === $thirtysixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **38 expected smul overflow metadata (thirtysixth)');
            }
            $thirtysixthLong = JITVariable::KIND_VARIABLE === $thirtysixthVar->kind
                ? $context->builder->load($thirtysixthVar->value)
                : $thirtysixthVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_thirtyeighth_thirtysixth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_thirtyeighth_thirtysixth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $thirtysixthF6 = $context->builder->load($thirtysixthVar->longArithOverflowDoubleSlot);
            $nF6 = $context->builder->siToFp($n, $f64);
            $thirtyseventhF6 = $context->builder->fmul($thirtysixthF6, $nF6);
            $thirtyeighthF6 = $context->builder->fmul($thirtyseventhF6, $nF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtyeighthF6
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok6Block);
            $thirtyseventhVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $thirtysixthLong,
                $n
            );
            $ov7 = JitLongArithOverflow::loadOverflowFlagI1($context, $thirtyseventhVar->longArithOverflowFlag);
            if (null === $ov7 || null === $thirtyseventhVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **38 expected smul overflow metadata (thirtyseventh)');
            }
            $thirtyseventhLong = JITVariable::KIND_VARIABLE === $thirtyseventhVar->kind
                ? $context->builder->load($thirtyseventhVar->value)
                : $thirtyseventhVar->value;
            $ov7Block = BasicBlockHelper::append($context, 'pow_thirtyeighth_thirtyseventh_ov');
            $ok7Block = BasicBlockHelper::append($context, 'pow_thirtyeighth_thirtyseventh_ok');
            $context->builder->branchIf($ov7, $ov7Block, $ok7Block);

            $context->builder->positionAtEnd($ov7Block);
            $thirtyseventhF7 = $context->builder->load($thirtyseventhVar->longArithOverflowDoubleSlot);
            $nF7 = $context->builder->siToFp($n, $f64);
            $thirtyeighthF7 = $context->builder->fmul($thirtyseventhF7, $nF7);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtyeighthF7
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok7Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $thirtyseventhLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return true;
        }

        if ('thirtyninth' === $expFold) {
            // n^39 = thirtyeighth*n: sq=n*n, cu=sq*n, sixth=cu*cu,
            // ninth=sixth*cu, eighteenth=ninth*ninth,
            // thirtysixth=eighteenth*eighteenth, thirtyseventh=thirtysixth*n,
            // thirtyeighth=thirtyseventh*n, then thirtyeighth*n.
            // Overflow arms finish in float
            // (sqF^18*nF^3, ((cuF^3)^2)^2*nF^3, ((sixthF*cuF)^2)^2*nF^3,
            // ninthF^4*nF^3, eighteenthF^2*nF^3, thirtysixthF*nF^3,
            // thirtyseventhF*nF^2, thirtyeighthF*nF).
            $baseL = JitLongArg::lower($context, $base, 'pow() base');
            $i64 = $context->getTypeFromString('int64');
            $f64 = $context->getTypeFromString('double');
            $n = $context->builder->intCast($baseL, $i64);
            $sqVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $n,
                $n
            );
            $ov1 = JitLongArithOverflow::loadOverflowFlagI1($context, $sqVar->longArithOverflowFlag);
            if (null === $ov1 || null === $sqVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **39 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_thirtyninth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_thirtyninth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_thirtyninth_done');
            $context->builder->branchIf($ov1, $ov1Block, $ok1Block);

            $context->builder->positionAtEnd($ov1Block);
            $sqF = $context->builder->load($sqVar->longArithOverflowDoubleSlot);
            $nF = $context->builder->siToFp($n, $f64);
            $sq2F = $context->builder->fmul($sqF, $sqF);
            $sq4F = $context->builder->fmul($sq2F, $sq2F);
            $sq8F = $context->builder->fmul($sq4F, $sq4F);
            $eighteenthF = $context->builder->fmul($sq8F, $sqF);
            $thirtysixthF = $context->builder->fmul($eighteenthF, $eighteenthF);
            $thirtyseventhF = $context->builder->fmul($thirtysixthF, $nF);
            $thirtyeighthF = $context->builder->fmul($thirtyseventhF, $nF);
            $thirtyninthF = $context->builder->fmul($thirtyeighthF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtyninthF
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok1Block);
            $cuVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sqLong,
                $n
            );
            $ov2 = JitLongArithOverflow::loadOverflowFlagI1($context, $cuVar->longArithOverflowFlag);
            if (null === $ov2 || null === $cuVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **39 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_thirtyninth_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_thirtyninth_cu_ok');
            $context->builder->branchIf($ov2, $ov2Block, $ok2Block);

            $context->builder->positionAtEnd($ov2Block);
            $cuF = $context->builder->load($cuVar->longArithOverflowDoubleSlot);
            $nF2 = $context->builder->siToFp($n, $f64);
            $cu2F = $context->builder->fmul($cuF, $cuF);
            $ninthF2 = $context->builder->fmul($cu2F, $cuF);
            $eighteenthF2 = $context->builder->fmul($ninthF2, $ninthF2);
            $thirtysixthF2 = $context->builder->fmul($eighteenthF2, $eighteenthF2);
            $thirtyseventhF2 = $context->builder->fmul($thirtysixthF2, $nF2);
            $thirtyeighthF2 = $context->builder->fmul($thirtyseventhF2, $nF2);
            $thirtyninthF2 = $context->builder->fmul($thirtyeighthF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtyninthF2
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok2Block);
            $sixthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $cuLong,
                $cuLong
            );
            $ov3 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixthVar->longArithOverflowFlag);
            if (null === $ov3 || null === $sixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **39 expected smul overflow metadata (sixth)');
            }
            $sixthLong = JITVariable::KIND_VARIABLE === $sixthVar->kind
                ? $context->builder->load($sixthVar->value)
                : $sixthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_thirtyninth_sixth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_thirtyninth_sixth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $sixthF = $context->builder->load($sixthVar->longArithOverflowDoubleSlot);
            $cuF3 = $context->builder->siToFp($cuLong, $f64);
            $nF3 = $context->builder->siToFp($n, $f64);
            $ninthF3 = $context->builder->fmul($sixthF, $cuF3);
            $eighteenthF3 = $context->builder->fmul($ninthF3, $ninthF3);
            $thirtysixthF3 = $context->builder->fmul($eighteenthF3, $eighteenthF3);
            $thirtyseventhF3 = $context->builder->fmul($thirtysixthF3, $nF3);
            $thirtyeighthF3 = $context->builder->fmul($thirtyseventhF3, $nF3);
            $thirtyninthF3 = $context->builder->fmul($thirtyeighthF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtyninthF3
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok3Block);
            $ninthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixthLong,
                $cuLong
            );
            $ov4 = JitLongArithOverflow::loadOverflowFlagI1($context, $ninthVar->longArithOverflowFlag);
            if (null === $ov4 || null === $ninthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **39 expected smul overflow metadata (ninth)');
            }
            $ninthLong = JITVariable::KIND_VARIABLE === $ninthVar->kind
                ? $context->builder->load($ninthVar->value)
                : $ninthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_thirtyninth_ninth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_thirtyninth_ninth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $ninthF4 = $context->builder->load($ninthVar->longArithOverflowDoubleSlot);
            $nF4 = $context->builder->siToFp($n, $f64);
            $eighteenthF4 = $context->builder->fmul($ninthF4, $ninthF4);
            $thirtysixthF4 = $context->builder->fmul($eighteenthF4, $eighteenthF4);
            $thirtyseventhF4 = $context->builder->fmul($thirtysixthF4, $nF4);
            $thirtyeighthF4 = $context->builder->fmul($thirtyseventhF4, $nF4);
            $thirtyninthF4 = $context->builder->fmul($thirtyeighthF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtyninthF4
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok4Block);
            $eighteenthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $ninthLong,
                $ninthLong
            );
            $ov5 = JitLongArithOverflow::loadOverflowFlagI1($context, $eighteenthVar->longArithOverflowFlag);
            if (null === $ov5 || null === $eighteenthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **39 expected smul overflow metadata (eighteenth)');
            }
            $eighteenthLong = JITVariable::KIND_VARIABLE === $eighteenthVar->kind
                ? $context->builder->load($eighteenthVar->value)
                : $eighteenthVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_thirtyninth_eighteenth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_thirtyninth_eighteenth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $eighteenthF5 = $context->builder->load($eighteenthVar->longArithOverflowDoubleSlot);
            $nF5 = $context->builder->siToFp($n, $f64);
            $thirtysixthF5 = $context->builder->fmul($eighteenthF5, $eighteenthF5);
            $thirtyseventhF5 = $context->builder->fmul($thirtysixthF5, $nF5);
            $thirtyeighthF5 = $context->builder->fmul($thirtyseventhF5, $nF5);
            $thirtyninthF5 = $context->builder->fmul($thirtyeighthF5, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtyninthF5
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok5Block);
            $thirtysixthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $eighteenthLong,
                $eighteenthLong
            );
            $ov6 = JitLongArithOverflow::loadOverflowFlagI1($context, $thirtysixthVar->longArithOverflowFlag);
            if (null === $ov6 || null === $thirtysixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **39 expected smul overflow metadata (thirtysixth)');
            }
            $thirtysixthLong = JITVariable::KIND_VARIABLE === $thirtysixthVar->kind
                ? $context->builder->load($thirtysixthVar->value)
                : $thirtysixthVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_thirtyninth_thirtysixth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_thirtyninth_thirtysixth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $thirtysixthF6 = $context->builder->load($thirtysixthVar->longArithOverflowDoubleSlot);
            $nF6 = $context->builder->siToFp($n, $f64);
            $thirtyseventhF6 = $context->builder->fmul($thirtysixthF6, $nF6);
            $thirtyeighthF6 = $context->builder->fmul($thirtyseventhF6, $nF6);
            $thirtyninthF6 = $context->builder->fmul($thirtyeighthF6, $nF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtyninthF6
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok6Block);
            $thirtyseventhVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $thirtysixthLong,
                $n
            );
            $ov7 = JitLongArithOverflow::loadOverflowFlagI1($context, $thirtyseventhVar->longArithOverflowFlag);
            if (null === $ov7 || null === $thirtyseventhVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **39 expected smul overflow metadata (thirtyseventh)');
            }
            $thirtyseventhLong = JITVariable::KIND_VARIABLE === $thirtyseventhVar->kind
                ? $context->builder->load($thirtyseventhVar->value)
                : $thirtyseventhVar->value;
            $ov7Block = BasicBlockHelper::append($context, 'pow_thirtyninth_thirtyseventh_ov');
            $ok7Block = BasicBlockHelper::append($context, 'pow_thirtyninth_thirtyseventh_ok');
            $context->builder->branchIf($ov7, $ov7Block, $ok7Block);

            $context->builder->positionAtEnd($ov7Block);
            $thirtyseventhF7 = $context->builder->load($thirtyseventhVar->longArithOverflowDoubleSlot);
            $nF7 = $context->builder->siToFp($n, $f64);
            $thirtyeighthF7 = $context->builder->fmul($thirtyseventhF7, $nF7);
            $thirtyninthF7 = $context->builder->fmul($thirtyeighthF7, $nF7);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtyninthF7
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok7Block);
            $thirtyeighthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $thirtyseventhLong,
                $n
            );
            $ov8 = JitLongArithOverflow::loadOverflowFlagI1($context, $thirtyeighthVar->longArithOverflowFlag);
            if (null === $ov8 || null === $thirtyeighthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **39 expected smul overflow metadata (thirtyeighth)');
            }
            $thirtyeighthLong = JITVariable::KIND_VARIABLE === $thirtyeighthVar->kind
                ? $context->builder->load($thirtyeighthVar->value)
                : $thirtyeighthVar->value;
            $ov8Block = BasicBlockHelper::append($context, 'pow_thirtyninth_thirtyeighth_ov');
            $ok8Block = BasicBlockHelper::append($context, 'pow_thirtyninth_thirtyeighth_ok');
            $context->builder->branchIf($ov8, $ov8Block, $ok8Block);

            $context->builder->positionAtEnd($ov8Block);
            $thirtyeighthF8 = $context->builder->load($thirtyeighthVar->longArithOverflowDoubleSlot);
            $nF8 = $context->builder->siToFp($n, $f64);
            $thirtyninthF8 = $context->builder->fmul($thirtyeighthF8, $nF8);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $thirtyninthF8
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok8Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $thirtyeighthLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return true;
        }

        if ('fortieth' === $expFold) {
            // n^40 = twentieth*twentieth: sq=n*n, cu=sq*n, fifth=cu*sq,
            // tenth=fifth*fifth, twentieth=tenth*tenth, then
            // twentieth*twentieth. Overflow arms finish in float
            // (sqF^20, ((cuF*sqF)^2)^4, (fifthF^2)^4, tenthF^4,
            // twentiethF^2).
            $baseL = JitLongArg::lower($context, $base, 'pow() base');
            $i64 = $context->getTypeFromString('int64');
            $f64 = $context->getTypeFromString('double');
            $n = $context->builder->intCast($baseL, $i64);
            $sqVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $n,
                $n
            );
            $ov1 = JitLongArithOverflow::loadOverflowFlagI1($context, $sqVar->longArithOverflowFlag);
            if (null === $ov1 || null === $sqVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **40 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_fortieth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_fortieth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_fortieth_done');
            $context->builder->branchIf($ov1, $ov1Block, $ok1Block);

            $context->builder->positionAtEnd($ov1Block);
            $sqF = $context->builder->load($sqVar->longArithOverflowDoubleSlot);
            $sq2F = $context->builder->fmul($sqF, $sqF);
            $sq4F = $context->builder->fmul($sq2F, $sq2F);
            $sq8F = $context->builder->fmul($sq4F, $sq4F);
            $twentiethF = $context->builder->fmul($sq8F, $sq2F);
            $fortiethF = $context->builder->fmul($twentiethF, $twentiethF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortiethF
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok1Block);
            $cuVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sqLong,
                $n
            );
            $ov2 = JitLongArithOverflow::loadOverflowFlagI1($context, $cuVar->longArithOverflowFlag);
            if (null === $ov2 || null === $cuVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **40 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_fortieth_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_fortieth_cu_ok');
            $context->builder->branchIf($ov2, $ov2Block, $ok2Block);

            $context->builder->positionAtEnd($ov2Block);
            $cuF = $context->builder->load($cuVar->longArithOverflowDoubleSlot);
            $sqF2 = $context->builder->siToFp($sqLong, $f64);
            $fifthF = $context->builder->fmul($cuF, $sqF2);
            $tenthF2 = $context->builder->fmul($fifthF, $fifthF);
            $twentiethF2 = $context->builder->fmul($tenthF2, $tenthF2);
            $fortiethF2 = $context->builder->fmul($twentiethF2, $twentiethF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortiethF2
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok2Block);
            $fifthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $cuLong,
                $sqLong
            );
            $ov3 = JitLongArithOverflow::loadOverflowFlagI1($context, $fifthVar->longArithOverflowFlag);
            if (null === $ov3 || null === $fifthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **40 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_fortieth_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_fortieth_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $twentiethF3 = $context->builder->fmul($tenthF3, $tenthF3);
            $fortiethF3 = $context->builder->fmul($twentiethF3, $twentiethF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortiethF3
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok3Block);
            $tenthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fifthLong,
                $fifthLong
            );
            $ov4 = JitLongArithOverflow::loadOverflowFlagI1($context, $tenthVar->longArithOverflowFlag);
            if (null === $ov4 || null === $tenthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **40 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_fortieth_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_fortieth_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF4 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $twentiethF4 = $context->builder->fmul($tenthF4, $tenthF4);
            $fortiethF4 = $context->builder->fmul($twentiethF4, $twentiethF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortiethF4
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok4Block);
            $twentiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $tenthLong,
                $tenthLong
            );
            $ov5 = JitLongArithOverflow::loadOverflowFlagI1($context, $twentiethVar->longArithOverflowFlag);
            if (null === $ov5 || null === $twentiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **40 expected smul overflow metadata (twentieth)');
            }
            $twentiethLong = JITVariable::KIND_VARIABLE === $twentiethVar->kind
                ? $context->builder->load($twentiethVar->value)
                : $twentiethVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_fortieth_twentieth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_fortieth_twentieth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $twentiethF5 = $context->builder->load($twentiethVar->longArithOverflowDoubleSlot);
            $fortiethF5 = $context->builder->fmul($twentiethF5, $twentiethF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortiethF5
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok5Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $twentiethLong,
                $twentiethLong,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return true;
        }

        if ('fortyfirst' === $expFold) {
            // n^41 = fortieth*n: sq=n*n, cu=sq*n, fifth=cu*sq,
            // tenth=fifth*fifth, twentieth=tenth*tenth,
            // fortieth=twentieth*twentieth, then fortieth*n.
            // Overflow arms finish in float (sqF^20*nF,
            // ((cuF*sqF)^2)^4*nF, (fifthF^2)^4*nF, tenthF^4*nF,
            // twentiethF^2*nF, fortiethF*nF).
            $baseL = JitLongArg::lower($context, $base, 'pow() base');
            $i64 = $context->getTypeFromString('int64');
            $f64 = $context->getTypeFromString('double');
            $n = $context->builder->intCast($baseL, $i64);
            $sqVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $n,
                $n
            );
            $ov1 = JitLongArithOverflow::loadOverflowFlagI1($context, $sqVar->longArithOverflowFlag);
            if (null === $ov1 || null === $sqVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **41 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_fortyfirst_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_fortyfirst_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_fortyfirst_done');
            $context->builder->branchIf($ov1, $ov1Block, $ok1Block);

            $context->builder->positionAtEnd($ov1Block);
            $sqF = $context->builder->load($sqVar->longArithOverflowDoubleSlot);
            $sq2F = $context->builder->fmul($sqF, $sqF);
            $sq4F = $context->builder->fmul($sq2F, $sq2F);
            $sq8F = $context->builder->fmul($sq4F, $sq4F);
            $twentiethF = $context->builder->fmul($sq8F, $sq2F);
            $fortiethF = $context->builder->fmul($twentiethF, $twentiethF);
            $nF = $context->builder->siToFp($n, $f64);
            $fortyfirstF = $context->builder->fmul($fortiethF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortyfirstF
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok1Block);
            $cuVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sqLong,
                $n
            );
            $ov2 = JitLongArithOverflow::loadOverflowFlagI1($context, $cuVar->longArithOverflowFlag);
            if (null === $ov2 || null === $cuVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **41 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_fortyfirst_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_fortyfirst_cu_ok');
            $context->builder->branchIf($ov2, $ov2Block, $ok2Block);

            $context->builder->positionAtEnd($ov2Block);
            $cuF = $context->builder->load($cuVar->longArithOverflowDoubleSlot);
            $sqF2 = $context->builder->siToFp($sqLong, $f64);
            $fifthF = $context->builder->fmul($cuF, $sqF2);
            $tenthF2 = $context->builder->fmul($fifthF, $fifthF);
            $twentiethF2 = $context->builder->fmul($tenthF2, $tenthF2);
            $fortiethF2 = $context->builder->fmul($twentiethF2, $twentiethF2);
            $nF2 = $context->builder->siToFp($n, $f64);
            $fortyfirstF2 = $context->builder->fmul($fortiethF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortyfirstF2
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok2Block);
            $fifthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $cuLong,
                $sqLong
            );
            $ov3 = JitLongArithOverflow::loadOverflowFlagI1($context, $fifthVar->longArithOverflowFlag);
            if (null === $ov3 || null === $fifthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **41 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_fortyfirst_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_fortyfirst_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $twentiethF3 = $context->builder->fmul($tenthF3, $tenthF3);
            $fortiethF3 = $context->builder->fmul($twentiethF3, $twentiethF3);
            $nF3 = $context->builder->siToFp($n, $f64);
            $fortyfirstF3 = $context->builder->fmul($fortiethF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortyfirstF3
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok3Block);
            $tenthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fifthLong,
                $fifthLong
            );
            $ov4 = JitLongArithOverflow::loadOverflowFlagI1($context, $tenthVar->longArithOverflowFlag);
            if (null === $ov4 || null === $tenthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **41 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_fortyfirst_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_fortyfirst_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF4 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $twentiethF4 = $context->builder->fmul($tenthF4, $tenthF4);
            $fortiethF4 = $context->builder->fmul($twentiethF4, $twentiethF4);
            $nF4 = $context->builder->siToFp($n, $f64);
            $fortyfirstF4 = $context->builder->fmul($fortiethF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortyfirstF4
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok4Block);
            $twentiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $tenthLong,
                $tenthLong
            );
            $ov5 = JitLongArithOverflow::loadOverflowFlagI1($context, $twentiethVar->longArithOverflowFlag);
            if (null === $ov5 || null === $twentiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **41 expected smul overflow metadata (twentieth)');
            }
            $twentiethLong = JITVariable::KIND_VARIABLE === $twentiethVar->kind
                ? $context->builder->load($twentiethVar->value)
                : $twentiethVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_fortyfirst_twentieth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_fortyfirst_twentieth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $twentiethF5 = $context->builder->load($twentiethVar->longArithOverflowDoubleSlot);
            $fortiethF5 = $context->builder->fmul($twentiethF5, $twentiethF5);
            $nF5 = $context->builder->siToFp($n, $f64);
            $fortyfirstF5 = $context->builder->fmul($fortiethF5, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortyfirstF5
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok5Block);
            $fortiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $twentiethLong,
                $twentiethLong
            );
            $ov6 = JitLongArithOverflow::loadOverflowFlagI1($context, $fortiethVar->longArithOverflowFlag);
            if (null === $ov6 || null === $fortiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **41 expected smul overflow metadata (fortieth)');
            }
            $fortiethLong = JITVariable::KIND_VARIABLE === $fortiethVar->kind
                ? $context->builder->load($fortiethVar->value)
                : $fortiethVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_fortyfirst_fortieth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_fortyfirst_fortieth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $fortiethF6 = $context->builder->load($fortiethVar->longArithOverflowDoubleSlot);
            $nF6 = $context->builder->siToFp($n, $f64);
            $fortyfirstF6 = $context->builder->fmul($fortiethF6, $nF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortyfirstF6
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok6Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $fortiethLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return true;
        }
        if ('fortysecond' === $expFold) {
            // n^42 = fortieth*sq: sq=n*n, cu=sq*n, fifth=cu*sq,
            // tenth=fifth*fifth, twentieth=tenth*tenth,
            // fortieth=twentieth*twentieth, then fortieth*sq.
            // Overflow arms finish in float (sqF^21,
            // ((cuF*sqF)^2)^4*sqF, (fifthF^2)^4*sqF, tenthF^4*sqF,
            // twentiethF^2*sqF, fortiethF*sqF).
            $baseL = JitLongArg::lower($context, $base, 'pow() base');
            $i64 = $context->getTypeFromString('int64');
            $f64 = $context->getTypeFromString('double');
            $n = $context->builder->intCast($baseL, $i64);
            $sqVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $n,
                $n
            );
            $ov1 = JitLongArithOverflow::loadOverflowFlagI1($context, $sqVar->longArithOverflowFlag);
            if (null === $ov1 || null === $sqVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **42 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_fortysecond_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_fortysecond_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_fortysecond_done');
            $context->builder->branchIf($ov1, $ov1Block, $ok1Block);

            $context->builder->positionAtEnd($ov1Block);
            $sqF = $context->builder->load($sqVar->longArithOverflowDoubleSlot);
            $sq2F = $context->builder->fmul($sqF, $sqF);
            $sq4F = $context->builder->fmul($sq2F, $sq2F);
            $sq8F = $context->builder->fmul($sq4F, $sq4F);
            $twentiethF = $context->builder->fmul($sq8F, $sq2F);
            $fortiethF = $context->builder->fmul($twentiethF, $twentiethF);
            $fortysecondF = $context->builder->fmul($fortiethF, $sqF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortysecondF
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok1Block);
            $cuVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sqLong,
                $n
            );
            $ov2 = JitLongArithOverflow::loadOverflowFlagI1($context, $cuVar->longArithOverflowFlag);
            if (null === $ov2 || null === $cuVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **42 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_fortysecond_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_fortysecond_cu_ok');
            $context->builder->branchIf($ov2, $ov2Block, $ok2Block);

            $context->builder->positionAtEnd($ov2Block);
            $cuF = $context->builder->load($cuVar->longArithOverflowDoubleSlot);
            $sqF2 = $context->builder->siToFp($sqLong, $f64);
            $fifthF = $context->builder->fmul($cuF, $sqF2);
            $tenthF2 = $context->builder->fmul($fifthF, $fifthF);
            $twentiethF2 = $context->builder->fmul($tenthF2, $tenthF2);
            $fortiethF2 = $context->builder->fmul($twentiethF2, $twentiethF2);
            $fortysecondF2 = $context->builder->fmul($fortiethF2, $sqF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortysecondF2
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok2Block);
            $fifthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $cuLong,
                $sqLong
            );
            $ov3 = JitLongArithOverflow::loadOverflowFlagI1($context, $fifthVar->longArithOverflowFlag);
            if (null === $ov3 || null === $fifthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **42 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_fortysecond_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_fortysecond_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $twentiethF3 = $context->builder->fmul($tenthF3, $tenthF3);
            $fortiethF3 = $context->builder->fmul($twentiethF3, $twentiethF3);
            $sqF3 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF3 = $context->builder->fmul($fortiethF3, $sqF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortysecondF3
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok3Block);
            $tenthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fifthLong,
                $fifthLong
            );
            $ov4 = JitLongArithOverflow::loadOverflowFlagI1($context, $tenthVar->longArithOverflowFlag);
            if (null === $ov4 || null === $tenthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **42 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_fortysecond_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_fortysecond_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF4 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $twentiethF4 = $context->builder->fmul($tenthF4, $tenthF4);
            $fortiethF4 = $context->builder->fmul($twentiethF4, $twentiethF4);
            $sqF4 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF4 = $context->builder->fmul($fortiethF4, $sqF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortysecondF4
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok4Block);
            $twentiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $tenthLong,
                $tenthLong
            );
            $ov5 = JitLongArithOverflow::loadOverflowFlagI1($context, $twentiethVar->longArithOverflowFlag);
            if (null === $ov5 || null === $twentiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **42 expected smul overflow metadata (twentieth)');
            }
            $twentiethLong = JITVariable::KIND_VARIABLE === $twentiethVar->kind
                ? $context->builder->load($twentiethVar->value)
                : $twentiethVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_fortysecond_twentieth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_fortysecond_twentieth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $twentiethF5 = $context->builder->load($twentiethVar->longArithOverflowDoubleSlot);
            $fortiethF5 = $context->builder->fmul($twentiethF5, $twentiethF5);
            $sqF5 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF5 = $context->builder->fmul($fortiethF5, $sqF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortysecondF5
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok5Block);
            $fortiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $twentiethLong,
                $twentiethLong
            );
            $ov6 = JitLongArithOverflow::loadOverflowFlagI1($context, $fortiethVar->longArithOverflowFlag);
            if (null === $ov6 || null === $fortiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **42 expected smul overflow metadata (fortieth)');
            }
            $fortiethLong = JITVariable::KIND_VARIABLE === $fortiethVar->kind
                ? $context->builder->load($fortiethVar->value)
                : $fortiethVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_fortysecond_fortieth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_fortysecond_fortieth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $fortiethF6 = $context->builder->load($fortiethVar->longArithOverflowDoubleSlot);
            $sqF6 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF6 = $context->builder->fmul($fortiethF6, $sqF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortysecondF6
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok6Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $fortiethLong,
                $sqLong,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return true;
        }

        if ('fortythird' === $expFold) {
            // n^43 = fortysecond*n: sq=n*n, cu=sq*n, fifth=cu*sq,
            // tenth=fifth*fifth, twentieth=tenth*tenth,
            // fortieth=twentieth*twentieth, fortysecond=fortieth*sq, then fortysecond*n.
            // Overflow arms finish in float (sqF^21*nF,
            // ((cuF*sqF)^2)^4*sqF*nF, (fifthF^2)^4*sqF*nF, tenthF^4*sqF*nF,
            // twentiethF^2*sqF*nF, fortiethF*sqF*nF, fortysecondF*nF).
            $baseL = JitLongArg::lower($context, $base, 'pow() base');
            $i64 = $context->getTypeFromString('int64');
            $f64 = $context->getTypeFromString('double');
            $n = $context->builder->intCast($baseL, $i64);
            $sqVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $n,
                $n
            );
            $ov1 = JitLongArithOverflow::loadOverflowFlagI1($context, $sqVar->longArithOverflowFlag);
            if (null === $ov1 || null === $sqVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **43 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_fortythird_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_fortythird_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_fortythird_done');
            $context->builder->branchIf($ov1, $ov1Block, $ok1Block);

            $context->builder->positionAtEnd($ov1Block);
            $sqF = $context->builder->load($sqVar->longArithOverflowDoubleSlot);
            $sq2F = $context->builder->fmul($sqF, $sqF);
            $sq4F = $context->builder->fmul($sq2F, $sq2F);
            $sq8F = $context->builder->fmul($sq4F, $sq4F);
            $twentiethF = $context->builder->fmul($sq8F, $sq2F);
            $fortiethF = $context->builder->fmul($twentiethF, $twentiethF);
            $fortysecondF = $context->builder->fmul($fortiethF, $sqF);
            $nF = $context->builder->siToFp($n, $f64);
            $fortythirdF = $context->builder->fmul($fortysecondF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortythirdF
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok1Block);
            $cuVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sqLong,
                $n
            );
            $ov2 = JitLongArithOverflow::loadOverflowFlagI1($context, $cuVar->longArithOverflowFlag);
            if (null === $ov2 || null === $cuVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **43 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_fortythird_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_fortythird_cu_ok');
            $context->builder->branchIf($ov2, $ov2Block, $ok2Block);

            $context->builder->positionAtEnd($ov2Block);
            $cuF = $context->builder->load($cuVar->longArithOverflowDoubleSlot);
            $sqF2 = $context->builder->siToFp($sqLong, $f64);
            $fifthF = $context->builder->fmul($cuF, $sqF2);
            $tenthF2 = $context->builder->fmul($fifthF, $fifthF);
            $twentiethF2 = $context->builder->fmul($tenthF2, $tenthF2);
            $fortiethF2 = $context->builder->fmul($twentiethF2, $twentiethF2);
            $fortysecondF2 = $context->builder->fmul($fortiethF2, $sqF2);
            $nF2 = $context->builder->siToFp($n, $f64);
            $fortythirdF2 = $context->builder->fmul($fortysecondF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortythirdF2
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok2Block);
            $fifthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $cuLong,
                $sqLong
            );
            $ov3 = JitLongArithOverflow::loadOverflowFlagI1($context, $fifthVar->longArithOverflowFlag);
            if (null === $ov3 || null === $fifthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **43 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_fortythird_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_fortythird_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $twentiethF3 = $context->builder->fmul($tenthF3, $tenthF3);
            $fortiethF3 = $context->builder->fmul($twentiethF3, $twentiethF3);
            $sqF3 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF3 = $context->builder->fmul($fortiethF3, $sqF3);
            $nF3 = $context->builder->siToFp($n, $f64);
            $fortythirdF3 = $context->builder->fmul($fortysecondF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortythirdF3
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok3Block);
            $tenthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fifthLong,
                $fifthLong
            );
            $ov4 = JitLongArithOverflow::loadOverflowFlagI1($context, $tenthVar->longArithOverflowFlag);
            if (null === $ov4 || null === $tenthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **43 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_fortythird_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_fortythird_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF4 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $twentiethF4 = $context->builder->fmul($tenthF4, $tenthF4);
            $fortiethF4 = $context->builder->fmul($twentiethF4, $twentiethF4);
            $sqF4 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF4 = $context->builder->fmul($fortiethF4, $sqF4);
            $nF4 = $context->builder->siToFp($n, $f64);
            $fortythirdF4 = $context->builder->fmul($fortysecondF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortythirdF4
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok4Block);
            $twentiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $tenthLong,
                $tenthLong
            );
            $ov5 = JitLongArithOverflow::loadOverflowFlagI1($context, $twentiethVar->longArithOverflowFlag);
            if (null === $ov5 || null === $twentiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **43 expected smul overflow metadata (twentieth)');
            }
            $twentiethLong = JITVariable::KIND_VARIABLE === $twentiethVar->kind
                ? $context->builder->load($twentiethVar->value)
                : $twentiethVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_fortythird_twentieth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_fortythird_twentieth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $twentiethF5 = $context->builder->load($twentiethVar->longArithOverflowDoubleSlot);
            $fortiethF5 = $context->builder->fmul($twentiethF5, $twentiethF5);
            $sqF5 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF5 = $context->builder->fmul($fortiethF5, $sqF5);
            $nF5 = $context->builder->siToFp($n, $f64);
            $fortythirdF5 = $context->builder->fmul($fortysecondF5, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortythirdF5
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok5Block);
            $fortiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $twentiethLong,
                $twentiethLong
            );
            $ov6 = JitLongArithOverflow::loadOverflowFlagI1($context, $fortiethVar->longArithOverflowFlag);
            if (null === $ov6 || null === $fortiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **43 expected smul overflow metadata (fortieth)');
            }
            $fortiethLong = JITVariable::KIND_VARIABLE === $fortiethVar->kind
                ? $context->builder->load($fortiethVar->value)
                : $fortiethVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_fortythird_fortieth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_fortythird_fortieth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $fortiethF6 = $context->builder->load($fortiethVar->longArithOverflowDoubleSlot);
            $sqF6 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF6 = $context->builder->fmul($fortiethF6, $sqF6);
            $nF6 = $context->builder->siToFp($n, $f64);
            $fortythirdF6 = $context->builder->fmul($fortysecondF6, $nF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortythirdF6
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok6Block);
            $fortysecondVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fortiethLong,
                $sqLong
            );
            $ov7 = JitLongArithOverflow::loadOverflowFlagI1($context, $fortysecondVar->longArithOverflowFlag);
            if (null === $ov7 || null === $fortysecondVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **43 expected smul overflow metadata (fortysecond)');
            }
            $fortysecondLong = JITVariable::KIND_VARIABLE === $fortysecondVar->kind
                ? $context->builder->load($fortysecondVar->value)
                : $fortysecondVar->value;
            $ov7Block = BasicBlockHelper::append($context, 'pow_fortythird_fortysecond_ov');
            $ok7Block = BasicBlockHelper::append($context, 'pow_fortythird_fortysecond_ok');
            $context->builder->branchIf($ov7, $ov7Block, $ok7Block);

            $context->builder->positionAtEnd($ov7Block);
            $fortysecondF7 = $context->builder->load($fortysecondVar->longArithOverflowDoubleSlot);
            $nF7 = $context->builder->siToFp($n, $f64);
            $fortythirdF7 = $context->builder->fmul($fortysecondF7, $nF7);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortythirdF7
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok7Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $fortysecondLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return true;
        }
        if ('fortyfourth' === $expFold) {
            // n^44 = fortysecond*sq: sq=n*n, cu=sq*n, fifth=cu*sq,
            // tenth=fifth*fifth, twentieth=tenth*tenth,
            // fortieth=twentieth*twentieth, fortysecond=fortieth*sq, then fortysecond*sq.
            // Overflow arms finish in float (sqF^22,
            // ((cuF*sqF)^2)^4*sqF*sqF, (fifthF^2)^4*sqF*sqF, tenthF^4*sqF*sqF,
            // twentiethF^2*sqF*sqF, fortiethF*sqF*sqF, fortysecondF*sqF).
            $baseL = JitLongArg::lower($context, $base, 'pow() base');
            $i64 = $context->getTypeFromString('int64');
            $f64 = $context->getTypeFromString('double');
            $n = $context->builder->intCast($baseL, $i64);
            $sqVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $n,
                $n
            );
            $ov1 = JitLongArithOverflow::loadOverflowFlagI1($context, $sqVar->longArithOverflowFlag);
            if (null === $ov1 || null === $sqVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **44 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_fortyfourth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_fortyfourth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_fortyfourth_done');
            $context->builder->branchIf($ov1, $ov1Block, $ok1Block);

            $context->builder->positionAtEnd($ov1Block);
            $sqF = $context->builder->load($sqVar->longArithOverflowDoubleSlot);
            $sq2F = $context->builder->fmul($sqF, $sqF);
            $sq4F = $context->builder->fmul($sq2F, $sq2F);
            $sq8F = $context->builder->fmul($sq4F, $sq4F);
            $twentiethF = $context->builder->fmul($sq8F, $sq2F);
            $fortiethF = $context->builder->fmul($twentiethF, $twentiethF);
            $fortysecondF = $context->builder->fmul($fortiethF, $sqF);
            $fortyfourthF = $context->builder->fmul($fortysecondF, $sqF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortyfourthF
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok1Block);
            $cuVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sqLong,
                $n
            );
            $ov2 = JitLongArithOverflow::loadOverflowFlagI1($context, $cuVar->longArithOverflowFlag);
            if (null === $ov2 || null === $cuVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **44 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_fortyfourth_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_fortyfourth_cu_ok');
            $context->builder->branchIf($ov2, $ov2Block, $ok2Block);

            $context->builder->positionAtEnd($ov2Block);
            $cuF = $context->builder->load($cuVar->longArithOverflowDoubleSlot);
            $sqF2 = $context->builder->siToFp($sqLong, $f64);
            $fifthF = $context->builder->fmul($cuF, $sqF2);
            $tenthF2 = $context->builder->fmul($fifthF, $fifthF);
            $twentiethF2 = $context->builder->fmul($tenthF2, $tenthF2);
            $fortiethF2 = $context->builder->fmul($twentiethF2, $twentiethF2);
            $fortysecondF2 = $context->builder->fmul($fortiethF2, $sqF2);
            $fortyfourthF2 = $context->builder->fmul($fortysecondF2, $sqF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortyfourthF2
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok2Block);
            $fifthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $cuLong,
                $sqLong
            );
            $ov3 = JitLongArithOverflow::loadOverflowFlagI1($context, $fifthVar->longArithOverflowFlag);
            if (null === $ov3 || null === $fifthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **44 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_fortyfourth_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_fortyfourth_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $twentiethF3 = $context->builder->fmul($tenthF3, $tenthF3);
            $fortiethF3 = $context->builder->fmul($twentiethF3, $twentiethF3);
            $sqF3 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF3 = $context->builder->fmul($fortiethF3, $sqF3);
            $fortyfourthF3 = $context->builder->fmul($fortysecondF3, $sqF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortyfourthF3
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok3Block);
            $tenthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fifthLong,
                $fifthLong
            );
            $ov4 = JitLongArithOverflow::loadOverflowFlagI1($context, $tenthVar->longArithOverflowFlag);
            if (null === $ov4 || null === $tenthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **44 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_fortyfourth_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_fortyfourth_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF4 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $twentiethF4 = $context->builder->fmul($tenthF4, $tenthF4);
            $fortiethF4 = $context->builder->fmul($twentiethF4, $twentiethF4);
            $sqF4 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF4 = $context->builder->fmul($fortiethF4, $sqF4);
            $fortyfourthF4 = $context->builder->fmul($fortysecondF4, $sqF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortyfourthF4
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok4Block);
            $twentiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $tenthLong,
                $tenthLong
            );
            $ov5 = JitLongArithOverflow::loadOverflowFlagI1($context, $twentiethVar->longArithOverflowFlag);
            if (null === $ov5 || null === $twentiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **44 expected smul overflow metadata (twentieth)');
            }
            $twentiethLong = JITVariable::KIND_VARIABLE === $twentiethVar->kind
                ? $context->builder->load($twentiethVar->value)
                : $twentiethVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_fortyfourth_twentieth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_fortyfourth_twentieth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $twentiethF5 = $context->builder->load($twentiethVar->longArithOverflowDoubleSlot);
            $fortiethF5 = $context->builder->fmul($twentiethF5, $twentiethF5);
            $sqF5 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF5 = $context->builder->fmul($fortiethF5, $sqF5);
            $fortyfourthF5 = $context->builder->fmul($fortysecondF5, $sqF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortyfourthF5
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok5Block);
            $fortiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $twentiethLong,
                $twentiethLong
            );
            $ov6 = JitLongArithOverflow::loadOverflowFlagI1($context, $fortiethVar->longArithOverflowFlag);
            if (null === $ov6 || null === $fortiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **44 expected smul overflow metadata (fortieth)');
            }
            $fortiethLong = JITVariable::KIND_VARIABLE === $fortiethVar->kind
                ? $context->builder->load($fortiethVar->value)
                : $fortiethVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_fortyfourth_fortieth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_fortyfourth_fortieth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $fortiethF6 = $context->builder->load($fortiethVar->longArithOverflowDoubleSlot);
            $sqF6 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF6 = $context->builder->fmul($fortiethF6, $sqF6);
            $fortyfourthF6 = $context->builder->fmul($fortysecondF6, $sqF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortyfourthF6
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok6Block);
            $fortysecondVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fortiethLong,
                $sqLong
            );
            $ov7 = JitLongArithOverflow::loadOverflowFlagI1($context, $fortysecondVar->longArithOverflowFlag);
            if (null === $ov7 || null === $fortysecondVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **44 expected smul overflow metadata (fortysecond)');
            }
            $fortysecondLong = JITVariable::KIND_VARIABLE === $fortysecondVar->kind
                ? $context->builder->load($fortysecondVar->value)
                : $fortysecondVar->value;
            $ov7Block = BasicBlockHelper::append($context, 'pow_fortyfourth_fortysecond_ov');
            $ok7Block = BasicBlockHelper::append($context, 'pow_fortyfourth_fortysecond_ok');
            $context->builder->branchIf($ov7, $ov7Block, $ok7Block);

            $context->builder->positionAtEnd($ov7Block);
            $fortysecondF7 = $context->builder->load($fortysecondVar->longArithOverflowDoubleSlot);
            $sqF7 = $context->builder->siToFp($sqLong, $f64);
            $fortyfourthF7 = $context->builder->fmul($fortysecondF7, $sqF7);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortyfourthF7
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok7Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $fortysecondLong,
                $sqLong,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return true;
        }

        if ('fortyfifth' === $expFold) {
            // n^45 = fortyfourth*n: sq=n*n, cu=sq*n, fifth=cu*sq,
            // tenth=fifth*fifth, twentieth=tenth*tenth,
            // fortieth=twentieth*twentieth, fortysecond=fortieth*sq, fortyfourth=fortysecond*sq,
            // then fortyfourth*n.
            // Overflow arms finish in float (sqF^22*nF,
            // ((cuF*sqF)^2)^4*sqF*sqF*nF, (fifthF^2)^4*sqF*sqF*nF, tenthF^4*sqF*sqF*nF,
            // twentiethF^2*sqF*sqF*nF, fortiethF*sqF*sqF*nF, fortysecondF*sqF*nF,
            // fortyfourthF*nF).
            $baseL = JitLongArg::lower($context, $base, 'pow() base');
            $i64 = $context->getTypeFromString('int64');
            $f64 = $context->getTypeFromString('double');
            $n = $context->builder->intCast($baseL, $i64);
            $sqVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $n,
                $n
            );
            $ov1 = JitLongArithOverflow::loadOverflowFlagI1($context, $sqVar->longArithOverflowFlag);
            if (null === $ov1 || null === $sqVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **45 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_fortyfifth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_fortyfifth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_fortyfifth_done');
            $context->builder->branchIf($ov1, $ov1Block, $ok1Block);

            $context->builder->positionAtEnd($ov1Block);
            $sqF = $context->builder->load($sqVar->longArithOverflowDoubleSlot);
            $sq2F = $context->builder->fmul($sqF, $sqF);
            $sq4F = $context->builder->fmul($sq2F, $sq2F);
            $sq8F = $context->builder->fmul($sq4F, $sq4F);
            $twentiethF = $context->builder->fmul($sq8F, $sq2F);
            $fortiethF = $context->builder->fmul($twentiethF, $twentiethF);
            $fortysecondF = $context->builder->fmul($fortiethF, $sqF);
            $fortyfourthF = $context->builder->fmul($fortysecondF, $sqF);
            $nF = $context->builder->siToFp($n, $f64);
            $fortyfifthF = $context->builder->fmul($fortyfourthF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortyfifthF
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok1Block);
            $cuVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sqLong,
                $n
            );
            $ov2 = JitLongArithOverflow::loadOverflowFlagI1($context, $cuVar->longArithOverflowFlag);
            if (null === $ov2 || null === $cuVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **45 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_fortyfifth_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_fortyfifth_cu_ok');
            $context->builder->branchIf($ov2, $ov2Block, $ok2Block);

            $context->builder->positionAtEnd($ov2Block);
            $cuF = $context->builder->load($cuVar->longArithOverflowDoubleSlot);
            $sqF2 = $context->builder->siToFp($sqLong, $f64);
            $fifthF = $context->builder->fmul($cuF, $sqF2);
            $tenthF2 = $context->builder->fmul($fifthF, $fifthF);
            $twentiethF2 = $context->builder->fmul($tenthF2, $tenthF2);
            $fortiethF2 = $context->builder->fmul($twentiethF2, $twentiethF2);
            $fortysecondF2 = $context->builder->fmul($fortiethF2, $sqF2);
            $fortyfourthF2 = $context->builder->fmul($fortysecondF2, $sqF2);
            $nF2 = $context->builder->siToFp($n, $f64);
            $fortyfifthF2 = $context->builder->fmul($fortyfourthF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortyfifthF2
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok2Block);
            $fifthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $cuLong,
                $sqLong
            );
            $ov3 = JitLongArithOverflow::loadOverflowFlagI1($context, $fifthVar->longArithOverflowFlag);
            if (null === $ov3 || null === $fifthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **45 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_fortyfifth_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_fortyfifth_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $twentiethF3 = $context->builder->fmul($tenthF3, $tenthF3);
            $fortiethF3 = $context->builder->fmul($twentiethF3, $twentiethF3);
            $sqF3 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF3 = $context->builder->fmul($fortiethF3, $sqF3);
            $fortyfourthF3 = $context->builder->fmul($fortysecondF3, $sqF3);
            $nF3 = $context->builder->siToFp($n, $f64);
            $fortyfifthF3 = $context->builder->fmul($fortyfourthF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortyfifthF3
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok3Block);
            $tenthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fifthLong,
                $fifthLong
            );
            $ov4 = JitLongArithOverflow::loadOverflowFlagI1($context, $tenthVar->longArithOverflowFlag);
            if (null === $ov4 || null === $tenthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **45 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_fortyfifth_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_fortyfifth_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF4 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $twentiethF4 = $context->builder->fmul($tenthF4, $tenthF4);
            $fortiethF4 = $context->builder->fmul($twentiethF4, $twentiethF4);
            $sqF4 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF4 = $context->builder->fmul($fortiethF4, $sqF4);
            $fortyfourthF4 = $context->builder->fmul($fortysecondF4, $sqF4);
            $nF4 = $context->builder->siToFp($n, $f64);
            $fortyfifthF4 = $context->builder->fmul($fortyfourthF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortyfifthF4
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok4Block);
            $twentiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $tenthLong,
                $tenthLong
            );
            $ov5 = JitLongArithOverflow::loadOverflowFlagI1($context, $twentiethVar->longArithOverflowFlag);
            if (null === $ov5 || null === $twentiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **45 expected smul overflow metadata (twentieth)');
            }
            $twentiethLong = JITVariable::KIND_VARIABLE === $twentiethVar->kind
                ? $context->builder->load($twentiethVar->value)
                : $twentiethVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_fortyfifth_twentieth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_fortyfifth_twentieth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $twentiethF5 = $context->builder->load($twentiethVar->longArithOverflowDoubleSlot);
            $fortiethF5 = $context->builder->fmul($twentiethF5, $twentiethF5);
            $sqF5 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF5 = $context->builder->fmul($fortiethF5, $sqF5);
            $fortyfourthF5 = $context->builder->fmul($fortysecondF5, $sqF5);
            $nF5 = $context->builder->siToFp($n, $f64);
            $fortyfifthF5 = $context->builder->fmul($fortyfourthF5, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortyfifthF5
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok5Block);
            $fortiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $twentiethLong,
                $twentiethLong
            );
            $ov6 = JitLongArithOverflow::loadOverflowFlagI1($context, $fortiethVar->longArithOverflowFlag);
            if (null === $ov6 || null === $fortiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **45 expected smul overflow metadata (fortieth)');
            }
            $fortiethLong = JITVariable::KIND_VARIABLE === $fortiethVar->kind
                ? $context->builder->load($fortiethVar->value)
                : $fortiethVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_fortyfifth_fortieth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_fortyfifth_fortieth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $fortiethF6 = $context->builder->load($fortiethVar->longArithOverflowDoubleSlot);
            $sqF6 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF6 = $context->builder->fmul($fortiethF6, $sqF6);
            $fortyfourthF6 = $context->builder->fmul($fortysecondF6, $sqF6);
            $nF6 = $context->builder->siToFp($n, $f64);
            $fortyfifthF6 = $context->builder->fmul($fortyfourthF6, $nF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortyfifthF6
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok6Block);
            $fortysecondVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fortiethLong,
                $sqLong
            );
            $ov7 = JitLongArithOverflow::loadOverflowFlagI1($context, $fortysecondVar->longArithOverflowFlag);
            if (null === $ov7 || null === $fortysecondVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **45 expected smul overflow metadata (fortysecond)');
            }
            $fortysecondLong = JITVariable::KIND_VARIABLE === $fortysecondVar->kind
                ? $context->builder->load($fortysecondVar->value)
                : $fortysecondVar->value;
            $ov7Block = BasicBlockHelper::append($context, 'pow_fortyfifth_fortysecond_ov');
            $ok7Block = BasicBlockHelper::append($context, 'pow_fortyfifth_fortysecond_ok');
            $context->builder->branchIf($ov7, $ov7Block, $ok7Block);

            $context->builder->positionAtEnd($ov7Block);
            $fortysecondF7 = $context->builder->load($fortysecondVar->longArithOverflowDoubleSlot);
            $sqF7 = $context->builder->siToFp($sqLong, $f64);
            $fortyfourthF7 = $context->builder->fmul($fortysecondF7, $sqF7);
            $nF7 = $context->builder->siToFp($n, $f64);
            $fortyfifthF7 = $context->builder->fmul($fortyfourthF7, $nF7);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortyfifthF7
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok7Block);
            $fortyfourthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fortysecondLong,
                $sqLong
            );
            $ov8 = JitLongArithOverflow::loadOverflowFlagI1($context, $fortyfourthVar->longArithOverflowFlag);
            if (null === $ov8 || null === $fortyfourthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **45 expected smul overflow metadata (fortyfourth)');
            }
            $fortyfourthLong = JITVariable::KIND_VARIABLE === $fortyfourthVar->kind
                ? $context->builder->load($fortyfourthVar->value)
                : $fortyfourthVar->value;
            $ov8Block = BasicBlockHelper::append($context, 'pow_fortyfifth_fortyfourth_ov');
            $ok8Block = BasicBlockHelper::append($context, 'pow_fortyfifth_fortyfourth_ok');
            $context->builder->branchIf($ov8, $ov8Block, $ok8Block);

            $context->builder->positionAtEnd($ov8Block);
            $fortyfourthF8 = $context->builder->load($fortyfourthVar->longArithOverflowDoubleSlot);
            $nF8 = $context->builder->siToFp($n, $f64);
            $fortyfifthF8 = $context->builder->fmul($fortyfourthF8, $nF8);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortyfifthF8
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok8Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $fortyfourthLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return true;
        }

        if ('fortysixth' === $expFold) {
            // n^46 = fortyfourth*sq: sq=n*n, cu=sq*n, fifth=cu*sq,
            // tenth=fifth*fifth, twentieth=tenth*tenth,
            // fortieth=twentieth*twentieth, fortysecond=fortieth*sq, fortyfourth=fortysecond*sq,
            // then fortyfourth*sq.
            // Overflow arms finish in float (sqF^23,
            // ((cuF*sqF)^2)^4*sqF*sqF*sqF, (fifthF^2)^4*sqF*sqF*sqF, tenthF^4*sqF*sqF*sqF,
            // twentiethF^2*sqF*sqF*sqF, fortiethF*sqF*sqF*sqF, fortysecondF*sqF*sqF,
            // fortyfourthF*sqF).
            $baseL = JitLongArg::lower($context, $base, 'pow() base');
            $i64 = $context->getTypeFromString('int64');
            $f64 = $context->getTypeFromString('double');
            $n = $context->builder->intCast($baseL, $i64);
            $sqVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $n,
                $n
            );
            $ov1 = JitLongArithOverflow::loadOverflowFlagI1($context, $sqVar->longArithOverflowFlag);
            if (null === $ov1 || null === $sqVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **46 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_fortysixth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_fortysixth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_fortysixth_done');
            $context->builder->branchIf($ov1, $ov1Block, $ok1Block);

            $context->builder->positionAtEnd($ov1Block);
            $sqF = $context->builder->load($sqVar->longArithOverflowDoubleSlot);
            $sq2F = $context->builder->fmul($sqF, $sqF);
            $sq4F = $context->builder->fmul($sq2F, $sq2F);
            $sq8F = $context->builder->fmul($sq4F, $sq4F);
            $twentiethF = $context->builder->fmul($sq8F, $sq2F);
            $fortiethF = $context->builder->fmul($twentiethF, $twentiethF);
            $fortysecondF = $context->builder->fmul($fortiethF, $sqF);
            $fortyfourthF = $context->builder->fmul($fortysecondF, $sqF);
            $fortysixthF = $context->builder->fmul($fortyfourthF, $sqF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortysixthF
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok1Block);
            $cuVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sqLong,
                $n
            );
            $ov2 = JitLongArithOverflow::loadOverflowFlagI1($context, $cuVar->longArithOverflowFlag);
            if (null === $ov2 || null === $cuVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **46 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_fortysixth_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_fortysixth_cu_ok');
            $context->builder->branchIf($ov2, $ov2Block, $ok2Block);

            $context->builder->positionAtEnd($ov2Block);
            $cuF = $context->builder->load($cuVar->longArithOverflowDoubleSlot);
            $sqF2 = $context->builder->siToFp($sqLong, $f64);
            $fifthF = $context->builder->fmul($cuF, $sqF2);
            $tenthF2 = $context->builder->fmul($fifthF, $fifthF);
            $twentiethF2 = $context->builder->fmul($tenthF2, $tenthF2);
            $fortiethF2 = $context->builder->fmul($twentiethF2, $twentiethF2);
            $fortysecondF2 = $context->builder->fmul($fortiethF2, $sqF2);
            $fortyfourthF2 = $context->builder->fmul($fortysecondF2, $sqF2);
            $fortysixthF2 = $context->builder->fmul($fortyfourthF2, $sqF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortysixthF2
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok2Block);
            $fifthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $cuLong,
                $sqLong
            );
            $ov3 = JitLongArithOverflow::loadOverflowFlagI1($context, $fifthVar->longArithOverflowFlag);
            if (null === $ov3 || null === $fifthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **46 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_fortysixth_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_fortysixth_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $twentiethF3 = $context->builder->fmul($tenthF3, $tenthF3);
            $fortiethF3 = $context->builder->fmul($twentiethF3, $twentiethF3);
            $sqF3 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF3 = $context->builder->fmul($fortiethF3, $sqF3);
            $fortyfourthF3 = $context->builder->fmul($fortysecondF3, $sqF3);
            $fortysixthF3 = $context->builder->fmul($fortyfourthF3, $sqF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortysixthF3
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok3Block);
            $tenthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fifthLong,
                $fifthLong
            );
            $ov4 = JitLongArithOverflow::loadOverflowFlagI1($context, $tenthVar->longArithOverflowFlag);
            if (null === $ov4 || null === $tenthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **46 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_fortysixth_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_fortysixth_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF4 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $twentiethF4 = $context->builder->fmul($tenthF4, $tenthF4);
            $fortiethF4 = $context->builder->fmul($twentiethF4, $twentiethF4);
            $sqF4 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF4 = $context->builder->fmul($fortiethF4, $sqF4);
            $fortyfourthF4 = $context->builder->fmul($fortysecondF4, $sqF4);
            $fortysixthF4 = $context->builder->fmul($fortyfourthF4, $sqF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortysixthF4
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok4Block);
            $twentiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $tenthLong,
                $tenthLong
            );
            $ov5 = JitLongArithOverflow::loadOverflowFlagI1($context, $twentiethVar->longArithOverflowFlag);
            if (null === $ov5 || null === $twentiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **46 expected smul overflow metadata (twentieth)');
            }
            $twentiethLong = JITVariable::KIND_VARIABLE === $twentiethVar->kind
                ? $context->builder->load($twentiethVar->value)
                : $twentiethVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_fortysixth_twentieth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_fortysixth_twentieth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $twentiethF5 = $context->builder->load($twentiethVar->longArithOverflowDoubleSlot);
            $fortiethF5 = $context->builder->fmul($twentiethF5, $twentiethF5);
            $sqF5 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF5 = $context->builder->fmul($fortiethF5, $sqF5);
            $fortyfourthF5 = $context->builder->fmul($fortysecondF5, $sqF5);
            $fortysixthF5 = $context->builder->fmul($fortyfourthF5, $sqF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortysixthF5
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok5Block);
            $fortiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $twentiethLong,
                $twentiethLong
            );
            $ov6 = JitLongArithOverflow::loadOverflowFlagI1($context, $fortiethVar->longArithOverflowFlag);
            if (null === $ov6 || null === $fortiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **46 expected smul overflow metadata (fortieth)');
            }
            $fortiethLong = JITVariable::KIND_VARIABLE === $fortiethVar->kind
                ? $context->builder->load($fortiethVar->value)
                : $fortiethVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_fortysixth_fortieth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_fortysixth_fortieth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $fortiethF6 = $context->builder->load($fortiethVar->longArithOverflowDoubleSlot);
            $sqF6 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF6 = $context->builder->fmul($fortiethF6, $sqF6);
            $fortyfourthF6 = $context->builder->fmul($fortysecondF6, $sqF6);
            $fortysixthF6 = $context->builder->fmul($fortyfourthF6, $sqF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortysixthF6
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok6Block);
            $fortysecondVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fortiethLong,
                $sqLong
            );
            $ov7 = JitLongArithOverflow::loadOverflowFlagI1($context, $fortysecondVar->longArithOverflowFlag);
            if (null === $ov7 || null === $fortysecondVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **46 expected smul overflow metadata (fortysecond)');
            }
            $fortysecondLong = JITVariable::KIND_VARIABLE === $fortysecondVar->kind
                ? $context->builder->load($fortysecondVar->value)
                : $fortysecondVar->value;
            $ov7Block = BasicBlockHelper::append($context, 'pow_fortysixth_fortysecond_ov');
            $ok7Block = BasicBlockHelper::append($context, 'pow_fortysixth_fortysecond_ok');
            $context->builder->branchIf($ov7, $ov7Block, $ok7Block);

            $context->builder->positionAtEnd($ov7Block);
            $fortysecondF7 = $context->builder->load($fortysecondVar->longArithOverflowDoubleSlot);
            $sqF7 = $context->builder->siToFp($sqLong, $f64);
            $fortyfourthF7 = $context->builder->fmul($fortysecondF7, $sqF7);
            $fortysixthF7 = $context->builder->fmul($fortyfourthF7, $sqF7);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortysixthF7
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok7Block);
            $fortyfourthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fortysecondLong,
                $sqLong
            );
            $ov8 = JitLongArithOverflow::loadOverflowFlagI1($context, $fortyfourthVar->longArithOverflowFlag);
            if (null === $ov8 || null === $fortyfourthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **46 expected smul overflow metadata (fortyfourth)');
            }
            $fortyfourthLong = JITVariable::KIND_VARIABLE === $fortyfourthVar->kind
                ? $context->builder->load($fortyfourthVar->value)
                : $fortyfourthVar->value;
            $ov8Block = BasicBlockHelper::append($context, 'pow_fortysixth_fortyfourth_ov');
            $ok8Block = BasicBlockHelper::append($context, 'pow_fortysixth_fortyfourth_ok');
            $context->builder->branchIf($ov8, $ov8Block, $ok8Block);

            $context->builder->positionAtEnd($ov8Block);
            $fortyfourthF8 = $context->builder->load($fortyfourthVar->longArithOverflowDoubleSlot);
            $sqF8 = $context->builder->siToFp($sqLong, $f64);
            $fortysixthF8 = $context->builder->fmul($fortyfourthF8, $sqF8);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortysixthF8
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok8Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $fortyfourthLong,
                $sqLong,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return true;
        }
        if ('fortyseventh' === $expFold) {
            // n^47 = fortysixth*n: sq=n*n, cu=sq*n, fifth=cu*sq,
            // tenth=fifth*fifth, twentieth=tenth*tenth,
            // fortieth=twentieth*twentieth, fortysecond=fortieth*sq, fortyfourth=fortysecond*sq,
            // fortysixth=fortyfourth*sq, then fortysixth*n.
            // Overflow arms finish in float (sqF^23*nF,
            // ((cuF*sqF)^2)^4*sqF*sqF*sqF*nF, (fifthF^2)^4*sqF*sqF*sqF*nF, tenthF^4*sqF*sqF*sqF*nF,
            // twentiethF^2*sqF*sqF*sqF*nF, fortiethF*sqF*sqF*sqF*nF, fortysecondF*sqF*sqF*nF,
            // fortyfourthF*sqF*nF, fortysixthF*nF).
            $baseL = JitLongArg::lower($context, $base, 'pow() base');
            $i64 = $context->getTypeFromString('int64');
            $f64 = $context->getTypeFromString('double');
            $n = $context->builder->intCast($baseL, $i64);
            $sqVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $n,
                $n
            );
            $ov1 = JitLongArithOverflow::loadOverflowFlagI1($context, $sqVar->longArithOverflowFlag);
            if (null === $ov1 || null === $sqVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **47 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_fortyseventh_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_fortyseventh_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_fortyseventh_done');
            $context->builder->branchIf($ov1, $ov1Block, $ok1Block);

            $context->builder->positionAtEnd($ov1Block);
            $sqF = $context->builder->load($sqVar->longArithOverflowDoubleSlot);
            $sq2F = $context->builder->fmul($sqF, $sqF);
            $sq4F = $context->builder->fmul($sq2F, $sq2F);
            $sq8F = $context->builder->fmul($sq4F, $sq4F);
            $twentiethF = $context->builder->fmul($sq8F, $sq2F);
            $fortiethF = $context->builder->fmul($twentiethF, $twentiethF);
            $fortysecondF = $context->builder->fmul($fortiethF, $sqF);
            $fortyfourthF = $context->builder->fmul($fortysecondF, $sqF);
            $fortyseventhFSq = $context->builder->fmul($fortyfourthF, $sqF);
            $nF = $context->builder->siToFp($n, $f64);
            $fortyseventhF = $context->builder->fmul($fortyseventhFSq, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortyseventhF
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok1Block);
            $cuVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sqLong,
                $n
            );
            $ov2 = JitLongArithOverflow::loadOverflowFlagI1($context, $cuVar->longArithOverflowFlag);
            if (null === $ov2 || null === $cuVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **47 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_fortyseventh_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_fortyseventh_cu_ok');
            $context->builder->branchIf($ov2, $ov2Block, $ok2Block);

            $context->builder->positionAtEnd($ov2Block);
            $cuF = $context->builder->load($cuVar->longArithOverflowDoubleSlot);
            $sqF2 = $context->builder->siToFp($sqLong, $f64);
            $fifthF = $context->builder->fmul($cuF, $sqF2);
            $tenthF2 = $context->builder->fmul($fifthF, $fifthF);
            $twentiethF2 = $context->builder->fmul($tenthF2, $tenthF2);
            $fortiethF2 = $context->builder->fmul($twentiethF2, $twentiethF2);
            $fortysecondF2 = $context->builder->fmul($fortiethF2, $sqF2);
            $fortyfourthF2 = $context->builder->fmul($fortysecondF2, $sqF2);
            $fortyseventhF2Sq = $context->builder->fmul($fortyfourthF2, $sqF2);
            $nF2 = $context->builder->siToFp($n, $f64);
            $fortyseventhF2 = $context->builder->fmul($fortyseventhF2Sq, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortyseventhF2
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok2Block);
            $fifthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $cuLong,
                $sqLong
            );
            $ov3 = JitLongArithOverflow::loadOverflowFlagI1($context, $fifthVar->longArithOverflowFlag);
            if (null === $ov3 || null === $fifthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **47 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_fortyseventh_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_fortyseventh_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $twentiethF3 = $context->builder->fmul($tenthF3, $tenthF3);
            $fortiethF3 = $context->builder->fmul($twentiethF3, $twentiethF3);
            $sqF3 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF3 = $context->builder->fmul($fortiethF3, $sqF3);
            $fortyfourthF3 = $context->builder->fmul($fortysecondF3, $sqF3);
            $fortyseventhF3Sq = $context->builder->fmul($fortyfourthF3, $sqF3);
            $nF3 = $context->builder->siToFp($n, $f64);
            $fortyseventhF3 = $context->builder->fmul($fortyseventhF3Sq, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortyseventhF3
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok3Block);
            $tenthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fifthLong,
                $fifthLong
            );
            $ov4 = JitLongArithOverflow::loadOverflowFlagI1($context, $tenthVar->longArithOverflowFlag);
            if (null === $ov4 || null === $tenthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **47 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_fortyseventh_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_fortyseventh_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF4 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $twentiethF4 = $context->builder->fmul($tenthF4, $tenthF4);
            $fortiethF4 = $context->builder->fmul($twentiethF4, $twentiethF4);
            $sqF4 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF4 = $context->builder->fmul($fortiethF4, $sqF4);
            $fortyfourthF4 = $context->builder->fmul($fortysecondF4, $sqF4);
            $fortyseventhF4Sq = $context->builder->fmul($fortyfourthF4, $sqF4);
            $nF4 = $context->builder->siToFp($n, $f64);
            $fortyseventhF4 = $context->builder->fmul($fortyseventhF4Sq, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortyseventhF4
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok4Block);
            $twentiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $tenthLong,
                $tenthLong
            );
            $ov5 = JitLongArithOverflow::loadOverflowFlagI1($context, $twentiethVar->longArithOverflowFlag);
            if (null === $ov5 || null === $twentiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **47 expected smul overflow metadata (twentieth)');
            }
            $twentiethLong = JITVariable::KIND_VARIABLE === $twentiethVar->kind
                ? $context->builder->load($twentiethVar->value)
                : $twentiethVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_fortyseventh_twentieth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_fortyseventh_twentieth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $twentiethF5 = $context->builder->load($twentiethVar->longArithOverflowDoubleSlot);
            $fortiethF5 = $context->builder->fmul($twentiethF5, $twentiethF5);
            $sqF5 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF5 = $context->builder->fmul($fortiethF5, $sqF5);
            $fortyfourthF5 = $context->builder->fmul($fortysecondF5, $sqF5);
            $fortyseventhF5Sq = $context->builder->fmul($fortyfourthF5, $sqF5);
            $nF5 = $context->builder->siToFp($n, $f64);
            $fortyseventhF5 = $context->builder->fmul($fortyseventhF5Sq, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortyseventhF5
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok5Block);
            $fortiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $twentiethLong,
                $twentiethLong
            );
            $ov6 = JitLongArithOverflow::loadOverflowFlagI1($context, $fortiethVar->longArithOverflowFlag);
            if (null === $ov6 || null === $fortiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **47 expected smul overflow metadata (fortieth)');
            }
            $fortiethLong = JITVariable::KIND_VARIABLE === $fortiethVar->kind
                ? $context->builder->load($fortiethVar->value)
                : $fortiethVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_fortyseventh_fortieth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_fortyseventh_fortieth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $fortiethF6 = $context->builder->load($fortiethVar->longArithOverflowDoubleSlot);
            $sqF6 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF6 = $context->builder->fmul($fortiethF6, $sqF6);
            $fortyfourthF6 = $context->builder->fmul($fortysecondF6, $sqF6);
            $fortyseventhF6Sq = $context->builder->fmul($fortyfourthF6, $sqF6);
            $nF6 = $context->builder->siToFp($n, $f64);
            $fortyseventhF6 = $context->builder->fmul($fortyseventhF6Sq, $nF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortyseventhF6
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok6Block);
            $fortysecondVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fortiethLong,
                $sqLong
            );
            $ov7 = JitLongArithOverflow::loadOverflowFlagI1($context, $fortysecondVar->longArithOverflowFlag);
            if (null === $ov7 || null === $fortysecondVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **47 expected smul overflow metadata (fortysecond)');
            }
            $fortysecondLong = JITVariable::KIND_VARIABLE === $fortysecondVar->kind
                ? $context->builder->load($fortysecondVar->value)
                : $fortysecondVar->value;
            $ov7Block = BasicBlockHelper::append($context, 'pow_fortyseventh_fortysecond_ov');
            $ok7Block = BasicBlockHelper::append($context, 'pow_fortyseventh_fortysecond_ok');
            $context->builder->branchIf($ov7, $ov7Block, $ok7Block);

            $context->builder->positionAtEnd($ov7Block);
            $fortysecondF7 = $context->builder->load($fortysecondVar->longArithOverflowDoubleSlot);
            $sqF7 = $context->builder->siToFp($sqLong, $f64);
            $fortyfourthF7 = $context->builder->fmul($fortysecondF7, $sqF7);
            $fortyseventhF7Sq = $context->builder->fmul($fortyfourthF7, $sqF7);
            $nF7 = $context->builder->siToFp($n, $f64);
            $fortyseventhF7 = $context->builder->fmul($fortyseventhF7Sq, $nF7);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortyseventhF7
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok7Block);
            $fortyfourthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fortysecondLong,
                $sqLong
            );
            $ov8 = JitLongArithOverflow::loadOverflowFlagI1($context, $fortyfourthVar->longArithOverflowFlag);
            if (null === $ov8 || null === $fortyfourthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **47 expected smul overflow metadata (fortyfourth)');
            }
            $fortyfourthLong = JITVariable::KIND_VARIABLE === $fortyfourthVar->kind
                ? $context->builder->load($fortyfourthVar->value)
                : $fortyfourthVar->value;
            $ov8Block = BasicBlockHelper::append($context, 'pow_fortyseventh_fortyfourth_ov');
            $ok8Block = BasicBlockHelper::append($context, 'pow_fortyseventh_fortyfourth_ok');
            $context->builder->branchIf($ov8, $ov8Block, $ok8Block);

            $context->builder->positionAtEnd($ov8Block);
            $fortyfourthF8 = $context->builder->load($fortyfourthVar->longArithOverflowDoubleSlot);
            $sqF8 = $context->builder->siToFp($sqLong, $f64);
            $fortyseventhF8Sq = $context->builder->fmul($fortyfourthF8, $sqF8);
            $nF8 = $context->builder->siToFp($n, $f64);
            $fortyseventhF8 = $context->builder->fmul($fortyseventhF8Sq, $nF8);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortyseventhF8
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok8Block);
            $fortysixthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fortyfourthLong,
                $sqLong
            );
            $ov9 = JitLongArithOverflow::loadOverflowFlagI1($context, $fortysixthVar->longArithOverflowFlag);
            if (null === $ov9 || null === $fortysixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **47 expected smul overflow metadata (fortysixth)');
            }
            $fortysixthLong = JITVariable::KIND_VARIABLE === $fortysixthVar->kind
                ? $context->builder->load($fortysixthVar->value)
                : $fortysixthVar->value;
            $ov9Block = BasicBlockHelper::append($context, 'pow_fortyseventh_fortysixth_ov');
            $ok9Block = BasicBlockHelper::append($context, 'pow_fortyseventh_fortysixth_ok');
            $context->builder->branchIf($ov9, $ov9Block, $ok9Block);

            $context->builder->positionAtEnd($ov9Block);
            $fortysixthF9 = $context->builder->load($fortysixthVar->longArithOverflowDoubleSlot);
            $nF9 = $context->builder->siToFp($n, $f64);
            $fortyseventhF9 = $context->builder->fmul($fortysixthF9, $nF9);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortyseventhF9
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok9Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $fortysixthLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return true;
        }
        if ('fortyeighth' === $expFold) {
            // n^48 = fortysixth*sq: sq=n*n, cu=sq*n, fifth=cu*sq,
            // tenth=fifth*fifth, twentieth=tenth*tenth,
            // fortieth=twentieth*twentieth, fortysecond=fortieth*sq, fortyfourth=fortysecond*sq,
            // fortysixth=fortyfourth*sq, then fortysixth*sq.
            // Overflow arms finish in float (sqF^24,
            // ((cuF*sqF)^2)^4*sqF*sqF*sqF*sqF, (fifthF^2)^4*sqF*sqF*sqF*sqF, tenthF^4*sqF*sqF*sqF*sqF,
            // twentiethF^2*sqF*sqF*sqF*sqF, fortiethF*sqF*sqF*sqF*sqF, fortysecondF*sqF*sqF*sqF,
            // fortyfourthF*sqF*sqF, fortysixthF*sqF).
            $baseL = JitLongArg::lower($context, $base, 'pow() base');
            $i64 = $context->getTypeFromString('int64');
            $f64 = $context->getTypeFromString('double');
            $n = $context->builder->intCast($baseL, $i64);
            $sqVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $n,
                $n
            );
            $ov1 = JitLongArithOverflow::loadOverflowFlagI1($context, $sqVar->longArithOverflowFlag);
            if (null === $ov1 || null === $sqVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **48 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_fortyeighth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_fortyeighth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_fortyeighth_done');
            $context->builder->branchIf($ov1, $ov1Block, $ok1Block);

            $context->builder->positionAtEnd($ov1Block);
            $sqF = $context->builder->load($sqVar->longArithOverflowDoubleSlot);
            $sq2F = $context->builder->fmul($sqF, $sqF);
            $sq4F = $context->builder->fmul($sq2F, $sq2F);
            $sq8F = $context->builder->fmul($sq4F, $sq4F);
            $twentiethF = $context->builder->fmul($sq8F, $sq2F);
            $fortiethF = $context->builder->fmul($twentiethF, $twentiethF);
            $fortysecondF = $context->builder->fmul($fortiethF, $sqF);
            $fortyfourthF = $context->builder->fmul($fortysecondF, $sqF);
            $fortyeighthFSq = $context->builder->fmul($fortyfourthF, $sqF);
            $fortyeighthF = $context->builder->fmul($fortyeighthFSq, $sqF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortyeighthF
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok1Block);
            $cuVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sqLong,
                $n
            );
            $ov2 = JitLongArithOverflow::loadOverflowFlagI1($context, $cuVar->longArithOverflowFlag);
            if (null === $ov2 || null === $cuVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **48 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_fortyeighth_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_fortyeighth_cu_ok');
            $context->builder->branchIf($ov2, $ov2Block, $ok2Block);

            $context->builder->positionAtEnd($ov2Block);
            $cuF = $context->builder->load($cuVar->longArithOverflowDoubleSlot);
            $sqF2 = $context->builder->siToFp($sqLong, $f64);
            $fifthF = $context->builder->fmul($cuF, $sqF2);
            $tenthF2 = $context->builder->fmul($fifthF, $fifthF);
            $twentiethF2 = $context->builder->fmul($tenthF2, $tenthF2);
            $fortiethF2 = $context->builder->fmul($twentiethF2, $twentiethF2);
            $fortysecondF2 = $context->builder->fmul($fortiethF2, $sqF2);
            $fortyfourthF2 = $context->builder->fmul($fortysecondF2, $sqF2);
            $fortyeighthF2Sq = $context->builder->fmul($fortyfourthF2, $sqF2);
            $fortyeighthF2 = $context->builder->fmul($fortyeighthF2Sq, $sqF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortyeighthF2
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok2Block);
            $fifthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $cuLong,
                $sqLong
            );
            $ov3 = JitLongArithOverflow::loadOverflowFlagI1($context, $fifthVar->longArithOverflowFlag);
            if (null === $ov3 || null === $fifthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **48 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_fortyeighth_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_fortyeighth_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $twentiethF3 = $context->builder->fmul($tenthF3, $tenthF3);
            $fortiethF3 = $context->builder->fmul($twentiethF3, $twentiethF3);
            $sqF3 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF3 = $context->builder->fmul($fortiethF3, $sqF3);
            $fortyfourthF3 = $context->builder->fmul($fortysecondF3, $sqF3);
            $fortyeighthF3Sq = $context->builder->fmul($fortyfourthF3, $sqF3);
            $fortyeighthF3 = $context->builder->fmul($fortyeighthF3Sq, $sqF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortyeighthF3
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok3Block);
            $tenthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fifthLong,
                $fifthLong
            );
            $ov4 = JitLongArithOverflow::loadOverflowFlagI1($context, $tenthVar->longArithOverflowFlag);
            if (null === $ov4 || null === $tenthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **48 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_fortyeighth_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_fortyeighth_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF4 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $twentiethF4 = $context->builder->fmul($tenthF4, $tenthF4);
            $fortiethF4 = $context->builder->fmul($twentiethF4, $twentiethF4);
            $sqF4 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF4 = $context->builder->fmul($fortiethF4, $sqF4);
            $fortyfourthF4 = $context->builder->fmul($fortysecondF4, $sqF4);
            $fortyeighthF4Sq = $context->builder->fmul($fortyfourthF4, $sqF4);
            $fortyeighthF4 = $context->builder->fmul($fortyeighthF4Sq, $sqF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortyeighthF4
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok4Block);
            $twentiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $tenthLong,
                $tenthLong
            );
            $ov5 = JitLongArithOverflow::loadOverflowFlagI1($context, $twentiethVar->longArithOverflowFlag);
            if (null === $ov5 || null === $twentiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **48 expected smul overflow metadata (twentieth)');
            }
            $twentiethLong = JITVariable::KIND_VARIABLE === $twentiethVar->kind
                ? $context->builder->load($twentiethVar->value)
                : $twentiethVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_fortyeighth_twentieth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_fortyeighth_twentieth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $twentiethF5 = $context->builder->load($twentiethVar->longArithOverflowDoubleSlot);
            $fortiethF5 = $context->builder->fmul($twentiethF5, $twentiethF5);
            $sqF5 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF5 = $context->builder->fmul($fortiethF5, $sqF5);
            $fortyfourthF5 = $context->builder->fmul($fortysecondF5, $sqF5);
            $fortyeighthF5Sq = $context->builder->fmul($fortyfourthF5, $sqF5);
            $fortyeighthF5 = $context->builder->fmul($fortyeighthF5Sq, $sqF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortyeighthF5
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok5Block);
            $fortiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $twentiethLong,
                $twentiethLong
            );
            $ov6 = JitLongArithOverflow::loadOverflowFlagI1($context, $fortiethVar->longArithOverflowFlag);
            if (null === $ov6 || null === $fortiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **48 expected smul overflow metadata (fortieth)');
            }
            $fortiethLong = JITVariable::KIND_VARIABLE === $fortiethVar->kind
                ? $context->builder->load($fortiethVar->value)
                : $fortiethVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_fortyeighth_fortieth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_fortyeighth_fortieth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $fortiethF6 = $context->builder->load($fortiethVar->longArithOverflowDoubleSlot);
            $sqF6 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF6 = $context->builder->fmul($fortiethF6, $sqF6);
            $fortyfourthF6 = $context->builder->fmul($fortysecondF6, $sqF6);
            $fortyeighthF6Sq = $context->builder->fmul($fortyfourthF6, $sqF6);
            $fortyeighthF6 = $context->builder->fmul($fortyeighthF6Sq, $sqF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortyeighthF6
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok6Block);
            $fortysecondVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fortiethLong,
                $sqLong
            );
            $ov7 = JitLongArithOverflow::loadOverflowFlagI1($context, $fortysecondVar->longArithOverflowFlag);
            if (null === $ov7 || null === $fortysecondVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **48 expected smul overflow metadata (fortysecond)');
            }
            $fortysecondLong = JITVariable::KIND_VARIABLE === $fortysecondVar->kind
                ? $context->builder->load($fortysecondVar->value)
                : $fortysecondVar->value;
            $ov7Block = BasicBlockHelper::append($context, 'pow_fortyeighth_fortysecond_ov');
            $ok7Block = BasicBlockHelper::append($context, 'pow_fortyeighth_fortysecond_ok');
            $context->builder->branchIf($ov7, $ov7Block, $ok7Block);

            $context->builder->positionAtEnd($ov7Block);
            $fortysecondF7 = $context->builder->load($fortysecondVar->longArithOverflowDoubleSlot);
            $sqF7 = $context->builder->siToFp($sqLong, $f64);
            $fortyfourthF7 = $context->builder->fmul($fortysecondF7, $sqF7);
            $fortyeighthF7Sq = $context->builder->fmul($fortyfourthF7, $sqF7);
            $fortyeighthF7 = $context->builder->fmul($fortyeighthF7Sq, $sqF7);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortyeighthF7
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok7Block);
            $fortyfourthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fortysecondLong,
                $sqLong
            );
            $ov8 = JitLongArithOverflow::loadOverflowFlagI1($context, $fortyfourthVar->longArithOverflowFlag);
            if (null === $ov8 || null === $fortyfourthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **48 expected smul overflow metadata (fortyfourth)');
            }
            $fortyfourthLong = JITVariable::KIND_VARIABLE === $fortyfourthVar->kind
                ? $context->builder->load($fortyfourthVar->value)
                : $fortyfourthVar->value;
            $ov8Block = BasicBlockHelper::append($context, 'pow_fortyeighth_fortyfourth_ov');
            $ok8Block = BasicBlockHelper::append($context, 'pow_fortyeighth_fortyfourth_ok');
            $context->builder->branchIf($ov8, $ov8Block, $ok8Block);

            $context->builder->positionAtEnd($ov8Block);
            $fortyfourthF8 = $context->builder->load($fortyfourthVar->longArithOverflowDoubleSlot);
            $sqF8 = $context->builder->siToFp($sqLong, $f64);
            $fortyeighthF8Sq = $context->builder->fmul($fortyfourthF8, $sqF8);
            $fortyeighthF8 = $context->builder->fmul($fortyeighthF8Sq, $sqF8);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortyeighthF8
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok8Block);
            $fortysixthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fortyfourthLong,
                $sqLong
            );
            $ov9 = JitLongArithOverflow::loadOverflowFlagI1($context, $fortysixthVar->longArithOverflowFlag);
            if (null === $ov9 || null === $fortysixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **48 expected smul overflow metadata (fortysixth)');
            }
            $fortysixthLong = JITVariable::KIND_VARIABLE === $fortysixthVar->kind
                ? $context->builder->load($fortysixthVar->value)
                : $fortysixthVar->value;
            $ov9Block = BasicBlockHelper::append($context, 'pow_fortyeighth_fortysixth_ov');
            $ok9Block = BasicBlockHelper::append($context, 'pow_fortyeighth_fortysixth_ok');
            $context->builder->branchIf($ov9, $ov9Block, $ok9Block);

            $context->builder->positionAtEnd($ov9Block);
            $fortysixthF9 = $context->builder->load($fortysixthVar->longArithOverflowDoubleSlot);
            $sqF9 = $context->builder->siToFp($sqLong, $f64);
            $fortyeighthF9 = $context->builder->fmul($fortysixthF9, $sqF9);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortyeighthF9
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok9Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $fortysixthLong,
                $sqLong,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return true;
        }

        if ('fortyninth' === $expFold) {
            // n^49 = fortyeighth*n = fortysixth*sq*n: sq=n*n, cu=sq*n, fifth=cu*sq,
            // tenth=fifth*fifth, twentieth=tenth*tenth,
            // fortieth=twentieth*twentieth, fortysecond=fortieth*sq, fortyfourth=fortysecond*sq,
            // fortysixth=fortyfourth*sq, then fortysixth*sq*n.
            // Overflow arms finish in float (sqF^24*nF,
            // ((cuF*sqF)^2)^4*sqF*sqF*sqF*sqF*nF, (fifthF^2)^4*sqF*sqF*sqF*sqF*nF, tenthF^4*sqF*sqF*sqF*sqF*nF,
            // twentiethF^2*sqF*sqF*sqF*sqF*nF, fortiethF*sqF*sqF*sqF*sqF*nF, fortysecondF*sqF*sqF*sqF*nF,
            // fortyfourthF*sqF*sqF*nF, fortysixthF*sqF*nF).
            $baseL = JitLongArg::lower($context, $base, 'pow() base');
            $i64 = $context->getTypeFromString('int64');
            $f64 = $context->getTypeFromString('double');
            $n = $context->builder->intCast($baseL, $i64);
            $sqVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $n,
                $n
            );
            $ov1 = JitLongArithOverflow::loadOverflowFlagI1($context, $sqVar->longArithOverflowFlag);
            if (null === $ov1 || null === $sqVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **49 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_fortyninth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_fortyninth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_fortyninth_done');
            $context->builder->branchIf($ov1, $ov1Block, $ok1Block);

            $context->builder->positionAtEnd($ov1Block);
            $sqF = $context->builder->load($sqVar->longArithOverflowDoubleSlot);
            $sq2F = $context->builder->fmul($sqF, $sqF);
            $sq4F = $context->builder->fmul($sq2F, $sq2F);
            $sq8F = $context->builder->fmul($sq4F, $sq4F);
            $twentiethF = $context->builder->fmul($sq8F, $sq2F);
            $fortiethF = $context->builder->fmul($twentiethF, $twentiethF);
            $fortysecondF = $context->builder->fmul($fortiethF, $sqF);
            $fortyfourthF = $context->builder->fmul($fortysecondF, $sqF);
            $fortyninthFSq = $context->builder->fmul($fortyfourthF, $sqF);
            $fortyninthFEighth = $context->builder->fmul($fortyninthFSq, $sqF);
            $nF = $context->builder->siToFp($n, $f64);
            $fortyninthF = $context->builder->fmul($fortyninthFEighth, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortyninthF
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok1Block);
            $cuVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sqLong,
                $n
            );
            $ov2 = JitLongArithOverflow::loadOverflowFlagI1($context, $cuVar->longArithOverflowFlag);
            if (null === $ov2 || null === $cuVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **49 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_fortyninth_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_fortyninth_cu_ok');
            $context->builder->branchIf($ov2, $ov2Block, $ok2Block);

            $context->builder->positionAtEnd($ov2Block);
            $cuF = $context->builder->load($cuVar->longArithOverflowDoubleSlot);
            $sqF2 = $context->builder->siToFp($sqLong, $f64);
            $fifthF = $context->builder->fmul($cuF, $sqF2);
            $tenthF2 = $context->builder->fmul($fifthF, $fifthF);
            $twentiethF2 = $context->builder->fmul($tenthF2, $tenthF2);
            $fortiethF2 = $context->builder->fmul($twentiethF2, $twentiethF2);
            $fortysecondF2 = $context->builder->fmul($fortiethF2, $sqF2);
            $fortyfourthF2 = $context->builder->fmul($fortysecondF2, $sqF2);
            $fortyninthF2Sq = $context->builder->fmul($fortyfourthF2, $sqF2);
            $fortyninthF2Eighth = $context->builder->fmul($fortyninthF2Sq, $sqF2);
            $nF2 = $context->builder->siToFp($n, $f64);
            $fortyninthF2 = $context->builder->fmul($fortyninthF2Eighth, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortyninthF2
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok2Block);
            $fifthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $cuLong,
                $sqLong
            );
            $ov3 = JitLongArithOverflow::loadOverflowFlagI1($context, $fifthVar->longArithOverflowFlag);
            if (null === $ov3 || null === $fifthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **49 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_fortyninth_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_fortyninth_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $twentiethF3 = $context->builder->fmul($tenthF3, $tenthF3);
            $fortiethF3 = $context->builder->fmul($twentiethF3, $twentiethF3);
            $sqF3 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF3 = $context->builder->fmul($fortiethF3, $sqF3);
            $fortyfourthF3 = $context->builder->fmul($fortysecondF3, $sqF3);
            $fortyninthF3Sq = $context->builder->fmul($fortyfourthF3, $sqF3);
            $fortyninthF3Eighth = $context->builder->fmul($fortyninthF3Sq, $sqF3);
            $nF3 = $context->builder->siToFp($n, $f64);
            $fortyninthF3 = $context->builder->fmul($fortyninthF3Eighth, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortyninthF3
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok3Block);
            $tenthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fifthLong,
                $fifthLong
            );
            $ov4 = JitLongArithOverflow::loadOverflowFlagI1($context, $tenthVar->longArithOverflowFlag);
            if (null === $ov4 || null === $tenthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **49 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_fortyninth_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_fortyninth_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF4 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $twentiethF4 = $context->builder->fmul($tenthF4, $tenthF4);
            $fortiethF4 = $context->builder->fmul($twentiethF4, $twentiethF4);
            $sqF4 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF4 = $context->builder->fmul($fortiethF4, $sqF4);
            $fortyfourthF4 = $context->builder->fmul($fortysecondF4, $sqF4);
            $fortyninthF4Sq = $context->builder->fmul($fortyfourthF4, $sqF4);
            $fortyninthF4Eighth = $context->builder->fmul($fortyninthF4Sq, $sqF4);
            $nF4 = $context->builder->siToFp($n, $f64);
            $fortyninthF4 = $context->builder->fmul($fortyninthF4Eighth, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortyninthF4
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok4Block);
            $twentiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $tenthLong,
                $tenthLong
            );
            $ov5 = JitLongArithOverflow::loadOverflowFlagI1($context, $twentiethVar->longArithOverflowFlag);
            if (null === $ov5 || null === $twentiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **49 expected smul overflow metadata (twentieth)');
            }
            $twentiethLong = JITVariable::KIND_VARIABLE === $twentiethVar->kind
                ? $context->builder->load($twentiethVar->value)
                : $twentiethVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_fortyninth_twentieth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_fortyninth_twentieth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $twentiethF5 = $context->builder->load($twentiethVar->longArithOverflowDoubleSlot);
            $fortiethF5 = $context->builder->fmul($twentiethF5, $twentiethF5);
            $sqF5 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF5 = $context->builder->fmul($fortiethF5, $sqF5);
            $fortyfourthF5 = $context->builder->fmul($fortysecondF5, $sqF5);
            $fortyninthF5Sq = $context->builder->fmul($fortyfourthF5, $sqF5);
            $fortyninthF5Eighth = $context->builder->fmul($fortyninthF5Sq, $sqF5);
            $nF5 = $context->builder->siToFp($n, $f64);
            $fortyninthF5 = $context->builder->fmul($fortyninthF5Eighth, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortyninthF5
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok5Block);
            $fortiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $twentiethLong,
                $twentiethLong
            );
            $ov6 = JitLongArithOverflow::loadOverflowFlagI1($context, $fortiethVar->longArithOverflowFlag);
            if (null === $ov6 || null === $fortiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **49 expected smul overflow metadata (fortieth)');
            }
            $fortiethLong = JITVariable::KIND_VARIABLE === $fortiethVar->kind
                ? $context->builder->load($fortiethVar->value)
                : $fortiethVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_fortyninth_fortieth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_fortyninth_fortieth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $fortiethF6 = $context->builder->load($fortiethVar->longArithOverflowDoubleSlot);
            $sqF6 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF6 = $context->builder->fmul($fortiethF6, $sqF6);
            $fortyfourthF6 = $context->builder->fmul($fortysecondF6, $sqF6);
            $fortyninthF6Sq = $context->builder->fmul($fortyfourthF6, $sqF6);
            $fortyninthF6Eighth = $context->builder->fmul($fortyninthF6Sq, $sqF6);
            $nF6 = $context->builder->siToFp($n, $f64);
            $fortyninthF6 = $context->builder->fmul($fortyninthF6Eighth, $nF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortyninthF6
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok6Block);
            $fortysecondVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fortiethLong,
                $sqLong
            );
            $ov7 = JitLongArithOverflow::loadOverflowFlagI1($context, $fortysecondVar->longArithOverflowFlag);
            if (null === $ov7 || null === $fortysecondVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **49 expected smul overflow metadata (fortysecond)');
            }
            $fortysecondLong = JITVariable::KIND_VARIABLE === $fortysecondVar->kind
                ? $context->builder->load($fortysecondVar->value)
                : $fortysecondVar->value;
            $ov7Block = BasicBlockHelper::append($context, 'pow_fortyninth_fortysecond_ov');
            $ok7Block = BasicBlockHelper::append($context, 'pow_fortyninth_fortysecond_ok');
            $context->builder->branchIf($ov7, $ov7Block, $ok7Block);

            $context->builder->positionAtEnd($ov7Block);
            $fortysecondF7 = $context->builder->load($fortysecondVar->longArithOverflowDoubleSlot);
            $sqF7 = $context->builder->siToFp($sqLong, $f64);
            $fortyfourthF7 = $context->builder->fmul($fortysecondF7, $sqF7);
            $fortyninthF7Sq = $context->builder->fmul($fortyfourthF7, $sqF7);
            $fortyninthF7Eighth = $context->builder->fmul($fortyninthF7Sq, $sqF7);
            $nF7 = $context->builder->siToFp($n, $f64);
            $fortyninthF7 = $context->builder->fmul($fortyninthF7Eighth, $nF7);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortyninthF7
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok7Block);
            $fortyfourthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fortysecondLong,
                $sqLong
            );
            $ov8 = JitLongArithOverflow::loadOverflowFlagI1($context, $fortyfourthVar->longArithOverflowFlag);
            if (null === $ov8 || null === $fortyfourthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **49 expected smul overflow metadata (fortyfourth)');
            }
            $fortyfourthLong = JITVariable::KIND_VARIABLE === $fortyfourthVar->kind
                ? $context->builder->load($fortyfourthVar->value)
                : $fortyfourthVar->value;
            $ov8Block = BasicBlockHelper::append($context, 'pow_fortyninth_fortyfourth_ov');
            $ok8Block = BasicBlockHelper::append($context, 'pow_fortyninth_fortyfourth_ok');
            $context->builder->branchIf($ov8, $ov8Block, $ok8Block);

            $context->builder->positionAtEnd($ov8Block);
            $fortyfourthF8 = $context->builder->load($fortyfourthVar->longArithOverflowDoubleSlot);
            $sqF8 = $context->builder->siToFp($sqLong, $f64);
            $fortyninthF8Sq = $context->builder->fmul($fortyfourthF8, $sqF8);
            $fortyninthF8Eighth = $context->builder->fmul($fortyninthF8Sq, $sqF8);
            $nF8 = $context->builder->siToFp($n, $f64);
            $fortyninthF8 = $context->builder->fmul($fortyninthF8Eighth, $nF8);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortyninthF8
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok8Block);
            $fortysixthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fortyfourthLong,
                $sqLong
            );
            $ov9 = JitLongArithOverflow::loadOverflowFlagI1($context, $fortysixthVar->longArithOverflowFlag);
            if (null === $ov9 || null === $fortysixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **49 expected smul overflow metadata (fortysixth)');
            }
            $fortysixthLong = JITVariable::KIND_VARIABLE === $fortysixthVar->kind
                ? $context->builder->load($fortysixthVar->value)
                : $fortysixthVar->value;
            $ov9Block = BasicBlockHelper::append($context, 'pow_fortyninth_fortysixth_ov');
            $ok9Block = BasicBlockHelper::append($context, 'pow_fortyninth_fortysixth_ok');
            $context->builder->branchIf($ov9, $ov9Block, $ok9Block);

            $context->builder->positionAtEnd($ov9Block);
            $fortysixthF9 = $context->builder->load($fortysixthVar->longArithOverflowDoubleSlot);
            $sqF9 = $context->builder->siToFp($sqLong, $f64);
            $fortyeighthF9 = $context->builder->fmul($fortysixthF9, $sqF9);
            $nF9 = $context->builder->siToFp($n, $f64);
            $fortyninthF9 = $context->builder->fmul($fortyeighthF9, $nF9);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortyninthF9
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok9Block);
            $fortyeighthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fortysixthLong,
                $sqLong
            );
            $ov10 = JitLongArithOverflow::loadOverflowFlagI1($context, $fortyeighthVar->longArithOverflowFlag);
            if (null === $ov10 || null === $fortyeighthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **49 expected smul overflow metadata (fortyeighth)');
            }
            $fortyeighthLong = JITVariable::KIND_VARIABLE === $fortyeighthVar->kind
                ? $context->builder->load($fortyeighthVar->value)
                : $fortyeighthVar->value;
            $ov10Block = BasicBlockHelper::append($context, 'pow_fortyninth_fortyeighth_ov');
            $ok10Block = BasicBlockHelper::append($context, 'pow_fortyninth_fortyeighth_ok');
            $context->builder->branchIf($ov10, $ov10Block, $ok10Block);

            $context->builder->positionAtEnd($ov10Block);
            $fortyeighthF10 = $context->builder->load($fortyeighthVar->longArithOverflowDoubleSlot);
            $nF10 = $context->builder->siToFp($n, $f64);
            $fortyninthF10 = $context->builder->fmul($fortyeighthF10, $nF10);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fortyninthF10
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok10Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $fortyeighthLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return true;
        }



        throw new \LogicException('JitPowIntegerEmitMid: unhandled expFold '.$expFold);
    }
}
