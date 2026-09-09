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
 * Integer {@code pow}/{@code **} chained-smul emit for compile-time
 * exponents 50–69 (#36387 / #36386). Extracted from {@see JitPowIntegerEmit}
 * so gen-0 spine gets a TU between {@see JitPowIntegerEmitMid} (30–49) and
 * {@see JitPowIntegerEmitHigh} (70+).
 *
 * No new C ABI. php-src: Zend/zend_operators.c {@code pow_function} /
 * {@code zend_pow} / {@code mul_function}; ext/standard/math.c
 * {@code PHP_FUNCTION(pow)}.
 */
final class JitPowIntegerEmitExponents50to69
{
    public static function tryEmit(
        Context $context,
        Value $slotPtr,
        JITVariable $base,
        JITVariable $exp,
        string $expFold
    ): bool {
        if ('fiftieth' === $expFold) {
            // n^50 = fortyeighth*sq = fortysixth*sq*sq: sq=n*n, cu=sq*n, fifth=cu*sq,
            // tenth=fifth*fifth, twentieth=tenth*tenth,
            // fortieth=twentieth*twentieth, fortysecond=fortieth*sq, fortyfourth=fortysecond*sq,
            // fortysixth=fortyfourth*sq, then fortysixth*sq*sq.
            // Overflow arms finish in float (sqF^25,
            // ((cuF*sqF)^2)^4*sqF*sqF*sqF*sqF*sqF, (fifthF^2)^4*sqF*sqF*sqF*sqF*sqF, tenthF^4*sqF*sqF*sqF*sqF*sqF,
            // twentiethF^2*sqF*sqF*sqF*sqF*sqF, fortiethF*sqF*sqF*sqF*sqF*sqF, fortysecondF*sqF*sqF*sqF*sqF,
            // fortyfourthF*sqF*sqF*sqF, fortysixthF*sqF*sqF).
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
                throw new \LogicException('pow() **50 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_fiftieth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_fiftieth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_fiftieth_done');
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
            $fiftiethFSq = $context->builder->fmul($fortyfourthF, $sqF);
            $fiftiethFEighth = $context->builder->fmul($fiftiethFSq, $sqF);
            $fiftiethF = $context->builder->fmul($fiftiethFEighth, $sqF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftiethF
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
                throw new \LogicException('pow() **50 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_fiftieth_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_fiftieth_cu_ok');
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
            $fiftiethF2Sq = $context->builder->fmul($fortyfourthF2, $sqF2);
            $fiftiethF2Eighth = $context->builder->fmul($fiftiethF2Sq, $sqF2);
            $fiftiethF2 = $context->builder->fmul($fiftiethF2Eighth, $sqF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftiethF2
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
                throw new \LogicException('pow() **50 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_fiftieth_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_fiftieth_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $twentiethF3 = $context->builder->fmul($tenthF3, $tenthF3);
            $fortiethF3 = $context->builder->fmul($twentiethF3, $twentiethF3);
            $sqF3 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF3 = $context->builder->fmul($fortiethF3, $sqF3);
            $fortyfourthF3 = $context->builder->fmul($fortysecondF3, $sqF3);
            $fiftiethF3Sq = $context->builder->fmul($fortyfourthF3, $sqF3);
            $fiftiethF3Eighth = $context->builder->fmul($fiftiethF3Sq, $sqF3);
            $fiftiethF3 = $context->builder->fmul($fiftiethF3Eighth, $sqF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftiethF3
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
                throw new \LogicException('pow() **50 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_fiftieth_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_fiftieth_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF4 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $twentiethF4 = $context->builder->fmul($tenthF4, $tenthF4);
            $fortiethF4 = $context->builder->fmul($twentiethF4, $twentiethF4);
            $sqF4 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF4 = $context->builder->fmul($fortiethF4, $sqF4);
            $fortyfourthF4 = $context->builder->fmul($fortysecondF4, $sqF4);
            $fiftiethF4Sq = $context->builder->fmul($fortyfourthF4, $sqF4);
            $fiftiethF4Eighth = $context->builder->fmul($fiftiethF4Sq, $sqF4);
            $fiftiethF4 = $context->builder->fmul($fiftiethF4Eighth, $sqF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftiethF4
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
                throw new \LogicException('pow() **50 expected smul overflow metadata (twentieth)');
            }
            $twentiethLong = JITVariable::KIND_VARIABLE === $twentiethVar->kind
                ? $context->builder->load($twentiethVar->value)
                : $twentiethVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_fiftieth_twentieth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_fiftieth_twentieth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $twentiethF5 = $context->builder->load($twentiethVar->longArithOverflowDoubleSlot);
            $fortiethF5 = $context->builder->fmul($twentiethF5, $twentiethF5);
            $sqF5 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF5 = $context->builder->fmul($fortiethF5, $sqF5);
            $fortyfourthF5 = $context->builder->fmul($fortysecondF5, $sqF5);
            $fiftiethF5Sq = $context->builder->fmul($fortyfourthF5, $sqF5);
            $fiftiethF5Eighth = $context->builder->fmul($fiftiethF5Sq, $sqF5);
            $fiftiethF5 = $context->builder->fmul($fiftiethF5Eighth, $sqF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftiethF5
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
                throw new \LogicException('pow() **50 expected smul overflow metadata (fortieth)');
            }
            $fortiethLong = JITVariable::KIND_VARIABLE === $fortiethVar->kind
                ? $context->builder->load($fortiethVar->value)
                : $fortiethVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_fiftieth_fortieth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_fiftieth_fortieth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $fortiethF6 = $context->builder->load($fortiethVar->longArithOverflowDoubleSlot);
            $sqF6 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF6 = $context->builder->fmul($fortiethF6, $sqF6);
            $fortyfourthF6 = $context->builder->fmul($fortysecondF6, $sqF6);
            $fiftiethF6Sq = $context->builder->fmul($fortyfourthF6, $sqF6);
            $fiftiethF6Eighth = $context->builder->fmul($fiftiethF6Sq, $sqF6);
            $fiftiethF6 = $context->builder->fmul($fiftiethF6Eighth, $sqF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftiethF6
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
                throw new \LogicException('pow() **50 expected smul overflow metadata (fortysecond)');
            }
            $fortysecondLong = JITVariable::KIND_VARIABLE === $fortysecondVar->kind
                ? $context->builder->load($fortysecondVar->value)
                : $fortysecondVar->value;
            $ov7Block = BasicBlockHelper::append($context, 'pow_fiftieth_fortysecond_ov');
            $ok7Block = BasicBlockHelper::append($context, 'pow_fiftieth_fortysecond_ok');
            $context->builder->branchIf($ov7, $ov7Block, $ok7Block);

            $context->builder->positionAtEnd($ov7Block);
            $fortysecondF7 = $context->builder->load($fortysecondVar->longArithOverflowDoubleSlot);
            $sqF7 = $context->builder->siToFp($sqLong, $f64);
            $fortyfourthF7 = $context->builder->fmul($fortysecondF7, $sqF7);
            $fiftiethF7Sq = $context->builder->fmul($fortyfourthF7, $sqF7);
            $fiftiethF7Eighth = $context->builder->fmul($fiftiethF7Sq, $sqF7);
            $fiftiethF7 = $context->builder->fmul($fiftiethF7Eighth, $sqF7);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftiethF7
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
                throw new \LogicException('pow() **50 expected smul overflow metadata (fortyfourth)');
            }
            $fortyfourthLong = JITVariable::KIND_VARIABLE === $fortyfourthVar->kind
                ? $context->builder->load($fortyfourthVar->value)
                : $fortyfourthVar->value;
            $ov8Block = BasicBlockHelper::append($context, 'pow_fiftieth_fortyfourth_ov');
            $ok8Block = BasicBlockHelper::append($context, 'pow_fiftieth_fortyfourth_ok');
            $context->builder->branchIf($ov8, $ov8Block, $ok8Block);

            $context->builder->positionAtEnd($ov8Block);
            $fortyfourthF8 = $context->builder->load($fortyfourthVar->longArithOverflowDoubleSlot);
            $sqF8 = $context->builder->siToFp($sqLong, $f64);
            $fiftiethF8Sq = $context->builder->fmul($fortyfourthF8, $sqF8);
            $fiftiethF8Eighth = $context->builder->fmul($fiftiethF8Sq, $sqF8);
            $fiftiethF8 = $context->builder->fmul($fiftiethF8Eighth, $sqF8);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftiethF8
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
                throw new \LogicException('pow() **50 expected smul overflow metadata (fortysixth)');
            }
            $fortysixthLong = JITVariable::KIND_VARIABLE === $fortysixthVar->kind
                ? $context->builder->load($fortysixthVar->value)
                : $fortysixthVar->value;
            $ov9Block = BasicBlockHelper::append($context, 'pow_fiftieth_fortysixth_ov');
            $ok9Block = BasicBlockHelper::append($context, 'pow_fiftieth_fortysixth_ok');
            $context->builder->branchIf($ov9, $ov9Block, $ok9Block);

            $context->builder->positionAtEnd($ov9Block);
            $fortysixthF9 = $context->builder->load($fortysixthVar->longArithOverflowDoubleSlot);
            $sqF9 = $context->builder->siToFp($sqLong, $f64);
            $fortyeighthF9 = $context->builder->fmul($fortysixthF9, $sqF9);
            $fiftiethF9 = $context->builder->fmul($fortyeighthF9, $sqF9);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftiethF9
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
                throw new \LogicException('pow() **50 expected smul overflow metadata (fortyeighth)');
            }
            $fortyeighthLong = JITVariable::KIND_VARIABLE === $fortyeighthVar->kind
                ? $context->builder->load($fortyeighthVar->value)
                : $fortyeighthVar->value;
            $ov10Block = BasicBlockHelper::append($context, 'pow_fiftieth_fortyeighth_ov');
            $ok10Block = BasicBlockHelper::append($context, 'pow_fiftieth_fortyeighth_ok');
            $context->builder->branchIf($ov10, $ov10Block, $ok10Block);

            $context->builder->positionAtEnd($ov10Block);
            $fortyeighthF10 = $context->builder->load($fortyeighthVar->longArithOverflowDoubleSlot);
            $sqF10 = $context->builder->siToFp($sqLong, $f64);
            $fiftiethF10 = $context->builder->fmul($fortyeighthF10, $sqF10);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftiethF10
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok10Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $fortyeighthLong,
                $sqLong,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return true;
        }

        if ('fiftyfirst' === $expFold) {
            // n^51 = fiftieth*n = fortyeighth*sq*n: sq=n*n, cu=sq*n, fifth=cu*sq,
            // tenth=fifth*fifth, twentieth=tenth*tenth,
            // fortieth=twentieth*twentieth, fortysecond=fortieth*sq, fortyfourth=fortysecond*sq,
            // fortysixth=fortyfourth*sq, then fortysixth*sq*sq*n.
            // Overflow arms finish in float (sqF^25*nF,
            // ((cuF*sqF)^2)^4*sqF*sqF*sqF*sqF*sqF*nF, (fifthF^2)^4*sqF*sqF*sqF*sqF*sqF*nF, tenthF^4*sqF*sqF*sqF*sqF*sqF*nF,
            // twentiethF^2*sqF*sqF*sqF*sqF*sqF*nF, fortiethF*sqF*sqF*sqF*sqF*sqF*nF, fortysecondF*sqF*sqF*sqF*sqF*nF,
            // fortyfourthF*sqF*sqF*sqF*nF, fortysixthF*sqF*sqF*nF).
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
                throw new \LogicException('pow() **51 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_fiftyfirst_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_fiftyfirst_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_fiftyfirst_done');
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
            $fiftyfirstFSq = $context->builder->fmul($fortyfourthF, $sqF);
            $fiftyfirstFEighth = $context->builder->fmul($fiftyfirstFSq, $sqF);
            $fiftyfirstF = $context->builder->fmul($fiftyfirstFEighth, $sqF);
            $nF = $context->builder->siToFp($n, $f64);
            $fiftyfirstF = $context->builder->fmul($fiftyfirstF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyfirstF
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
                throw new \LogicException('pow() **51 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_fiftyfirst_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_fiftyfirst_cu_ok');
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
            $fiftyfirstF2Sq = $context->builder->fmul($fortyfourthF2, $sqF2);
            $fiftyfirstF2Eighth = $context->builder->fmul($fiftyfirstF2Sq, $sqF2);
            $fiftyfirstF2 = $context->builder->fmul($fiftyfirstF2Eighth, $sqF2);
            $nF2 = $context->builder->siToFp($n, $f64);
            $fiftyfirstF2 = $context->builder->fmul($fiftyfirstF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyfirstF2
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
                throw new \LogicException('pow() **51 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_fiftyfirst_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_fiftyfirst_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $twentiethF3 = $context->builder->fmul($tenthF3, $tenthF3);
            $fortiethF3 = $context->builder->fmul($twentiethF3, $twentiethF3);
            $sqF3 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF3 = $context->builder->fmul($fortiethF3, $sqF3);
            $fortyfourthF3 = $context->builder->fmul($fortysecondF3, $sqF3);
            $fiftyfirstF3Sq = $context->builder->fmul($fortyfourthF3, $sqF3);
            $fiftyfirstF3Eighth = $context->builder->fmul($fiftyfirstF3Sq, $sqF3);
            $fiftyfirstF3 = $context->builder->fmul($fiftyfirstF3Eighth, $sqF3);
            $nF3 = $context->builder->siToFp($n, $f64);
            $fiftyfirstF3 = $context->builder->fmul($fiftyfirstF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyfirstF3
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
                throw new \LogicException('pow() **51 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_fiftyfirst_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_fiftyfirst_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF4 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $twentiethF4 = $context->builder->fmul($tenthF4, $tenthF4);
            $fortiethF4 = $context->builder->fmul($twentiethF4, $twentiethF4);
            $sqF4 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF4 = $context->builder->fmul($fortiethF4, $sqF4);
            $fortyfourthF4 = $context->builder->fmul($fortysecondF4, $sqF4);
            $fiftyfirstF4Sq = $context->builder->fmul($fortyfourthF4, $sqF4);
            $fiftyfirstF4Eighth = $context->builder->fmul($fiftyfirstF4Sq, $sqF4);
            $fiftyfirstF4 = $context->builder->fmul($fiftyfirstF4Eighth, $sqF4);
            $nF4 = $context->builder->siToFp($n, $f64);
            $fiftyfirstF4 = $context->builder->fmul($fiftyfirstF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyfirstF4
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
                throw new \LogicException('pow() **51 expected smul overflow metadata (twentieth)');
            }
            $twentiethLong = JITVariable::KIND_VARIABLE === $twentiethVar->kind
                ? $context->builder->load($twentiethVar->value)
                : $twentiethVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_fiftyfirst_twentieth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_fiftyfirst_twentieth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $twentiethF5 = $context->builder->load($twentiethVar->longArithOverflowDoubleSlot);
            $fortiethF5 = $context->builder->fmul($twentiethF5, $twentiethF5);
            $sqF5 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF5 = $context->builder->fmul($fortiethF5, $sqF5);
            $fortyfourthF5 = $context->builder->fmul($fortysecondF5, $sqF5);
            $fiftyfirstF5Sq = $context->builder->fmul($fortyfourthF5, $sqF5);
            $fiftyfirstF5Eighth = $context->builder->fmul($fiftyfirstF5Sq, $sqF5);
            $fiftyfirstF5 = $context->builder->fmul($fiftyfirstF5Eighth, $sqF5);
            $nF5 = $context->builder->siToFp($n, $f64);
            $fiftyfirstF5 = $context->builder->fmul($fiftyfirstF5, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyfirstF5
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
                throw new \LogicException('pow() **51 expected smul overflow metadata (fortieth)');
            }
            $fortiethLong = JITVariable::KIND_VARIABLE === $fortiethVar->kind
                ? $context->builder->load($fortiethVar->value)
                : $fortiethVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_fiftyfirst_fortieth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_fiftyfirst_fortieth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $fortiethF6 = $context->builder->load($fortiethVar->longArithOverflowDoubleSlot);
            $sqF6 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF6 = $context->builder->fmul($fortiethF6, $sqF6);
            $fortyfourthF6 = $context->builder->fmul($fortysecondF6, $sqF6);
            $fiftyfirstF6Sq = $context->builder->fmul($fortyfourthF6, $sqF6);
            $fiftyfirstF6Eighth = $context->builder->fmul($fiftyfirstF6Sq, $sqF6);
            $fiftyfirstF6 = $context->builder->fmul($fiftyfirstF6Eighth, $sqF6);
            $nF6 = $context->builder->siToFp($n, $f64);
            $fiftyfirstF6 = $context->builder->fmul($fiftyfirstF6, $nF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyfirstF6
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
                throw new \LogicException('pow() **51 expected smul overflow metadata (fortysecond)');
            }
            $fortysecondLong = JITVariable::KIND_VARIABLE === $fortysecondVar->kind
                ? $context->builder->load($fortysecondVar->value)
                : $fortysecondVar->value;
            $ov7Block = BasicBlockHelper::append($context, 'pow_fiftyfirst_fortysecond_ov');
            $ok7Block = BasicBlockHelper::append($context, 'pow_fiftyfirst_fortysecond_ok');
            $context->builder->branchIf($ov7, $ov7Block, $ok7Block);

            $context->builder->positionAtEnd($ov7Block);
            $fortysecondF7 = $context->builder->load($fortysecondVar->longArithOverflowDoubleSlot);
            $sqF7 = $context->builder->siToFp($sqLong, $f64);
            $fortyfourthF7 = $context->builder->fmul($fortysecondF7, $sqF7);
            $fiftyfirstF7Sq = $context->builder->fmul($fortyfourthF7, $sqF7);
            $fiftyfirstF7Eighth = $context->builder->fmul($fiftyfirstF7Sq, $sqF7);
            $fiftyfirstF7 = $context->builder->fmul($fiftyfirstF7Eighth, $sqF7);
            $nF7 = $context->builder->siToFp($n, $f64);
            $fiftyfirstF7 = $context->builder->fmul($fiftyfirstF7, $nF7);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyfirstF7
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
                throw new \LogicException('pow() **51 expected smul overflow metadata (fortyfourth)');
            }
            $fortyfourthLong = JITVariable::KIND_VARIABLE === $fortyfourthVar->kind
                ? $context->builder->load($fortyfourthVar->value)
                : $fortyfourthVar->value;
            $ov8Block = BasicBlockHelper::append($context, 'pow_fiftyfirst_fortyfourth_ov');
            $ok8Block = BasicBlockHelper::append($context, 'pow_fiftyfirst_fortyfourth_ok');
            $context->builder->branchIf($ov8, $ov8Block, $ok8Block);

            $context->builder->positionAtEnd($ov8Block);
            $fortyfourthF8 = $context->builder->load($fortyfourthVar->longArithOverflowDoubleSlot);
            $sqF8 = $context->builder->siToFp($sqLong, $f64);
            $fiftyfirstF8Sq = $context->builder->fmul($fortyfourthF8, $sqF8);
            $fiftyfirstF8Eighth = $context->builder->fmul($fiftyfirstF8Sq, $sqF8);
            $fiftyfirstF8 = $context->builder->fmul($fiftyfirstF8Eighth, $sqF8);
            $nF8 = $context->builder->siToFp($n, $f64);
            $fiftyfirstF8 = $context->builder->fmul($fiftyfirstF8, $nF8);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyfirstF8
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
                throw new \LogicException('pow() **51 expected smul overflow metadata (fortysixth)');
            }
            $fortysixthLong = JITVariable::KIND_VARIABLE === $fortysixthVar->kind
                ? $context->builder->load($fortysixthVar->value)
                : $fortysixthVar->value;
            $ov9Block = BasicBlockHelper::append($context, 'pow_fiftyfirst_fortysixth_ov');
            $ok9Block = BasicBlockHelper::append($context, 'pow_fiftyfirst_fortysixth_ok');
            $context->builder->branchIf($ov9, $ov9Block, $ok9Block);

            $context->builder->positionAtEnd($ov9Block);
            $fortysixthF9 = $context->builder->load($fortysixthVar->longArithOverflowDoubleSlot);
            $sqF9 = $context->builder->siToFp($sqLong, $f64);
            $fortyeighthF9 = $context->builder->fmul($fortysixthF9, $sqF9);
            $fiftyfirstF9 = $context->builder->fmul($fortyeighthF9, $sqF9);
            $nF9 = $context->builder->siToFp($n, $f64);
            $fiftyfirstF9 = $context->builder->fmul($fiftyfirstF9, $nF9);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyfirstF9
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
                throw new \LogicException('pow() **51 expected smul overflow metadata (fortyeighth)');
            }
            $fortyeighthLong = JITVariable::KIND_VARIABLE === $fortyeighthVar->kind
                ? $context->builder->load($fortyeighthVar->value)
                : $fortyeighthVar->value;
            $ov10Block = BasicBlockHelper::append($context, 'pow_fiftyfirst_fortyeighth_ov');
            $ok10Block = BasicBlockHelper::append($context, 'pow_fiftyfirst_fortyeighth_ok');
            $context->builder->branchIf($ov10, $ov10Block, $ok10Block);

            $context->builder->positionAtEnd($ov10Block);
            $fortyeighthF10 = $context->builder->load($fortyeighthVar->longArithOverflowDoubleSlot);
            $sqF10 = $context->builder->siToFp($sqLong, $f64);
            $fiftyfirstF10 = $context->builder->fmul($fortyeighthF10, $sqF10);
            $nF10 = $context->builder->siToFp($n, $f64);
            $fiftyfirstF10 = $context->builder->fmul($fiftyfirstF10, $nF10);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyfirstF10
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok10Block);
            $fiftiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fortyeighthLong,
                $sqLong
            );
            $ov11 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftiethVar->longArithOverflowFlag);
            if (null === $ov11 || null === $fiftiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **51 expected smul overflow metadata (fiftieth)');
            }
            $fiftiethLong = JITVariable::KIND_VARIABLE === $fiftiethVar->kind
                ? $context->builder->load($fiftiethVar->value)
                : $fiftiethVar->value;
            $ov11Block = BasicBlockHelper::append($context, 'pow_fiftyfirst_fiftieth_ov');
            $ok11Block = BasicBlockHelper::append($context, 'pow_fiftyfirst_fiftieth_ok');
            $context->builder->branchIf($ov11, $ov11Block, $ok11Block);

            $context->builder->positionAtEnd($ov11Block);
            $fiftiethF11 = $context->builder->load($fiftiethVar->longArithOverflowDoubleSlot);
            $nF11 = $context->builder->siToFp($n, $f64);
            $fiftyfirstF11 = $context->builder->fmul($fiftiethF11, $nF11);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyfirstF11
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok11Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $fiftiethLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return true;
        }

        if ('fiftysecond' === $expFold) {
            // n^52 = fiftyfirst*n = fortyeighth*sq*n*n: sq=n*n, cu=sq*n, fifth=cu*sq,
            // tenth=fifth*fifth, twentieth=tenth*tenth,
            // fortieth=twentieth*twentieth, fortysecond=fortieth*sq, fortyfourth=fortysecond*sq,
            // fortysixth=fortyfourth*sq, then fortysixth*sq*sq*n*n.
            // Overflow arms finish in float (sqF^25*nF*nF,
            // ((cuF*sqF)^2)^4*sqF*sqF*sqF*sqF*sqF*nF*nF, (fifthF^2)^4*sqF*sqF*sqF*sqF*sqF*nF*nF, tenthF^4*sqF*sqF*sqF*sqF*sqF*nF*nF,
            // twentiethF^2*sqF*sqF*sqF*sqF*sqF*nF*nF, fortiethF*sqF*sqF*sqF*sqF*sqF*nF*nF, fortysecondF*sqF*sqF*sqF*sqF*nF*nF,
            // fortyfourthF*sqF*sqF*sqF*nF*nF, fortysixthF*sqF*sqF*nF*nF).
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
                throw new \LogicException('pow() **52 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_fiftysecond_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_fiftysecond_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_fiftysecond_done');
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
            $fiftysecondFSq = $context->builder->fmul($fortyfourthF, $sqF);
            $fiftysecondFEighth = $context->builder->fmul($fiftysecondFSq, $sqF);
            $fiftysecondF = $context->builder->fmul($fiftysecondFEighth, $sqF);
            $nF = $context->builder->siToFp($n, $f64);
            $fiftysecondF = $context->builder->fmul($fiftysecondF, $nF);
            $fiftysecondF = $context->builder->fmul($fiftysecondF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftysecondF
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
                throw new \LogicException('pow() **52 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_fiftysecond_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_fiftysecond_cu_ok');
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
            $fiftysecondF2Sq = $context->builder->fmul($fortyfourthF2, $sqF2);
            $fiftysecondF2Eighth = $context->builder->fmul($fiftysecondF2Sq, $sqF2);
            $fiftysecondF2 = $context->builder->fmul($fiftysecondF2Eighth, $sqF2);
            $nF2 = $context->builder->siToFp($n, $f64);
            $fiftysecondF2 = $context->builder->fmul($fiftysecondF2, $nF2);
            $fiftysecondF2 = $context->builder->fmul($fiftysecondF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftysecondF2
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
                throw new \LogicException('pow() **52 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_fiftysecond_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_fiftysecond_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $twentiethF3 = $context->builder->fmul($tenthF3, $tenthF3);
            $fortiethF3 = $context->builder->fmul($twentiethF3, $twentiethF3);
            $sqF3 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF3 = $context->builder->fmul($fortiethF3, $sqF3);
            $fortyfourthF3 = $context->builder->fmul($fortysecondF3, $sqF3);
            $fiftysecondF3Sq = $context->builder->fmul($fortyfourthF3, $sqF3);
            $fiftysecondF3Eighth = $context->builder->fmul($fiftysecondF3Sq, $sqF3);
            $fiftysecondF3 = $context->builder->fmul($fiftysecondF3Eighth, $sqF3);
            $nF3 = $context->builder->siToFp($n, $f64);
            $fiftysecondF3 = $context->builder->fmul($fiftysecondF3, $nF3);
            $fiftysecondF3 = $context->builder->fmul($fiftysecondF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftysecondF3
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
                throw new \LogicException('pow() **52 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_fiftysecond_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_fiftysecond_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF4 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $twentiethF4 = $context->builder->fmul($tenthF4, $tenthF4);
            $fortiethF4 = $context->builder->fmul($twentiethF4, $twentiethF4);
            $sqF4 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF4 = $context->builder->fmul($fortiethF4, $sqF4);
            $fortyfourthF4 = $context->builder->fmul($fortysecondF4, $sqF4);
            $fiftysecondF4Sq = $context->builder->fmul($fortyfourthF4, $sqF4);
            $fiftysecondF4Eighth = $context->builder->fmul($fiftysecondF4Sq, $sqF4);
            $fiftysecondF4 = $context->builder->fmul($fiftysecondF4Eighth, $sqF4);
            $nF4 = $context->builder->siToFp($n, $f64);
            $fiftysecondF4 = $context->builder->fmul($fiftysecondF4, $nF4);
            $fiftysecondF4 = $context->builder->fmul($fiftysecondF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftysecondF4
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
                throw new \LogicException('pow() **52 expected smul overflow metadata (twentieth)');
            }
            $twentiethLong = JITVariable::KIND_VARIABLE === $twentiethVar->kind
                ? $context->builder->load($twentiethVar->value)
                : $twentiethVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_fiftysecond_twentieth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_fiftysecond_twentieth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $twentiethF5 = $context->builder->load($twentiethVar->longArithOverflowDoubleSlot);
            $fortiethF5 = $context->builder->fmul($twentiethF5, $twentiethF5);
            $sqF5 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF5 = $context->builder->fmul($fortiethF5, $sqF5);
            $fortyfourthF5 = $context->builder->fmul($fortysecondF5, $sqF5);
            $fiftysecondF5Sq = $context->builder->fmul($fortyfourthF5, $sqF5);
            $fiftysecondF5Eighth = $context->builder->fmul($fiftysecondF5Sq, $sqF5);
            $fiftysecondF5 = $context->builder->fmul($fiftysecondF5Eighth, $sqF5);
            $nF5 = $context->builder->siToFp($n, $f64);
            $fiftysecondF5 = $context->builder->fmul($fiftysecondF5, $nF5);
            $fiftysecondF5 = $context->builder->fmul($fiftysecondF5, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftysecondF5
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
                throw new \LogicException('pow() **52 expected smul overflow metadata (fortieth)');
            }
            $fortiethLong = JITVariable::KIND_VARIABLE === $fortiethVar->kind
                ? $context->builder->load($fortiethVar->value)
                : $fortiethVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_fiftysecond_fortieth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_fiftysecond_fortieth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $fortiethF6 = $context->builder->load($fortiethVar->longArithOverflowDoubleSlot);
            $sqF6 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF6 = $context->builder->fmul($fortiethF6, $sqF6);
            $fortyfourthF6 = $context->builder->fmul($fortysecondF6, $sqF6);
            $fiftysecondF6Sq = $context->builder->fmul($fortyfourthF6, $sqF6);
            $fiftysecondF6Eighth = $context->builder->fmul($fiftysecondF6Sq, $sqF6);
            $fiftysecondF6 = $context->builder->fmul($fiftysecondF6Eighth, $sqF6);
            $nF6 = $context->builder->siToFp($n, $f64);
            $fiftysecondF6 = $context->builder->fmul($fiftysecondF6, $nF6);
            $fiftysecondF6 = $context->builder->fmul($fiftysecondF6, $nF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftysecondF6
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
                throw new \LogicException('pow() **52 expected smul overflow metadata (fortysecond)');
            }
            $fortysecondLong = JITVariable::KIND_VARIABLE === $fortysecondVar->kind
                ? $context->builder->load($fortysecondVar->value)
                : $fortysecondVar->value;
            $ov7Block = BasicBlockHelper::append($context, 'pow_fiftysecond_fortysecond_ov');
            $ok7Block = BasicBlockHelper::append($context, 'pow_fiftysecond_fortysecond_ok');
            $context->builder->branchIf($ov7, $ov7Block, $ok7Block);

            $context->builder->positionAtEnd($ov7Block);
            $fortysecondF7 = $context->builder->load($fortysecondVar->longArithOverflowDoubleSlot);
            $sqF7 = $context->builder->siToFp($sqLong, $f64);
            $fortyfourthF7 = $context->builder->fmul($fortysecondF7, $sqF7);
            $fiftysecondF7Sq = $context->builder->fmul($fortyfourthF7, $sqF7);
            $fiftysecondF7Eighth = $context->builder->fmul($fiftysecondF7Sq, $sqF7);
            $fiftysecondF7 = $context->builder->fmul($fiftysecondF7Eighth, $sqF7);
            $nF7 = $context->builder->siToFp($n, $f64);
            $fiftysecondF7 = $context->builder->fmul($fiftysecondF7, $nF7);
            $fiftysecondF7 = $context->builder->fmul($fiftysecondF7, $nF7);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftysecondF7
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
                throw new \LogicException('pow() **52 expected smul overflow metadata (fortyfourth)');
            }
            $fortyfourthLong = JITVariable::KIND_VARIABLE === $fortyfourthVar->kind
                ? $context->builder->load($fortyfourthVar->value)
                : $fortyfourthVar->value;
            $ov8Block = BasicBlockHelper::append($context, 'pow_fiftysecond_fortyfourth_ov');
            $ok8Block = BasicBlockHelper::append($context, 'pow_fiftysecond_fortyfourth_ok');
            $context->builder->branchIf($ov8, $ov8Block, $ok8Block);

            $context->builder->positionAtEnd($ov8Block);
            $fortyfourthF8 = $context->builder->load($fortyfourthVar->longArithOverflowDoubleSlot);
            $sqF8 = $context->builder->siToFp($sqLong, $f64);
            $fiftysecondF8Sq = $context->builder->fmul($fortyfourthF8, $sqF8);
            $fiftysecondF8Eighth = $context->builder->fmul($fiftysecondF8Sq, $sqF8);
            $fiftysecondF8 = $context->builder->fmul($fiftysecondF8Eighth, $sqF8);
            $nF8 = $context->builder->siToFp($n, $f64);
            $fiftysecondF8 = $context->builder->fmul($fiftysecondF8, $nF8);
            $fiftysecondF8 = $context->builder->fmul($fiftysecondF8, $nF8);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftysecondF8
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
                throw new \LogicException('pow() **52 expected smul overflow metadata (fortysixth)');
            }
            $fortysixthLong = JITVariable::KIND_VARIABLE === $fortysixthVar->kind
                ? $context->builder->load($fortysixthVar->value)
                : $fortysixthVar->value;
            $ov9Block = BasicBlockHelper::append($context, 'pow_fiftysecond_fortysixth_ov');
            $ok9Block = BasicBlockHelper::append($context, 'pow_fiftysecond_fortysixth_ok');
            $context->builder->branchIf($ov9, $ov9Block, $ok9Block);

            $context->builder->positionAtEnd($ov9Block);
            $fortysixthF9 = $context->builder->load($fortysixthVar->longArithOverflowDoubleSlot);
            $sqF9 = $context->builder->siToFp($sqLong, $f64);
            $fortyeighthF9 = $context->builder->fmul($fortysixthF9, $sqF9);
            $fiftysecondF9 = $context->builder->fmul($fortyeighthF9, $sqF9);
            $nF9 = $context->builder->siToFp($n, $f64);
            $fiftysecondF9 = $context->builder->fmul($fiftysecondF9, $nF9);
            $fiftysecondF9 = $context->builder->fmul($fiftysecondF9, $nF9);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftysecondF9
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
                throw new \LogicException('pow() **52 expected smul overflow metadata (fortyeighth)');
            }
            $fortyeighthLong = JITVariable::KIND_VARIABLE === $fortyeighthVar->kind
                ? $context->builder->load($fortyeighthVar->value)
                : $fortyeighthVar->value;
            $ov10Block = BasicBlockHelper::append($context, 'pow_fiftysecond_fortyeighth_ov');
            $ok10Block = BasicBlockHelper::append($context, 'pow_fiftysecond_fortyeighth_ok');
            $context->builder->branchIf($ov10, $ov10Block, $ok10Block);

            $context->builder->positionAtEnd($ov10Block);
            $fortyeighthF10 = $context->builder->load($fortyeighthVar->longArithOverflowDoubleSlot);
            $sqF10 = $context->builder->siToFp($sqLong, $f64);
            $fiftysecondF10 = $context->builder->fmul($fortyeighthF10, $sqF10);
            $nF10 = $context->builder->siToFp($n, $f64);
            $fiftysecondF10 = $context->builder->fmul($fiftysecondF10, $nF10);
            $fiftysecondF10 = $context->builder->fmul($fiftysecondF10, $nF10);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftysecondF10
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok10Block);
            $fiftiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fortyeighthLong,
                $sqLong
            );
            $ov11 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftiethVar->longArithOverflowFlag);
            if (null === $ov11 || null === $fiftiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **52 expected smul overflow metadata (fiftieth)');
            }
            $fiftiethLong = JITVariable::KIND_VARIABLE === $fiftiethVar->kind
                ? $context->builder->load($fiftiethVar->value)
                : $fiftiethVar->value;
            $ov11Block = BasicBlockHelper::append($context, 'pow_fiftysecond_fiftieth_ov');
            $ok11Block = BasicBlockHelper::append($context, 'pow_fiftysecond_fiftieth_ok');
            $context->builder->branchIf($ov11, $ov11Block, $ok11Block);

            $context->builder->positionAtEnd($ov11Block);
            $fiftiethF11 = $context->builder->load($fiftiethVar->longArithOverflowDoubleSlot);
            $nF11 = $context->builder->siToFp($n, $f64);
            $fiftysecondF11 = $context->builder->fmul($fiftiethF11, $nF11);
            $fiftysecondF11 = $context->builder->fmul($fiftysecondF11, $nF11);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftysecondF11
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok11Block);
            $fiftyfirstVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftiethLong,
                $n
            );
            $ov12 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyfirstVar->longArithOverflowFlag);
            if (null === $ov12 || null === $fiftyfirstVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **52 expected smul overflow metadata (fiftyfirst)');
            }
            $fiftyfirstLong = JITVariable::KIND_VARIABLE === $fiftyfirstVar->kind
                ? $context->builder->load($fiftyfirstVar->value)
                : $fiftyfirstVar->value;
            $ov12Block = BasicBlockHelper::append($context, 'pow_fiftysecond_fiftyfirst_ov');
            $ok12Block = BasicBlockHelper::append($context, 'pow_fiftysecond_fiftyfirst_ok');
            $context->builder->branchIf($ov12, $ov12Block, $ok12Block);

            $context->builder->positionAtEnd($ov12Block);
            $fiftyfirstF12 = $context->builder->load($fiftyfirstVar->longArithOverflowDoubleSlot);
            $nF12 = $context->builder->siToFp($n, $f64);
            $fiftysecondF12 = $context->builder->fmul($fiftyfirstF12, $nF12);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftysecondF12
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok12Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $fiftyfirstLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return true;
        }

        if ('fiftythird' === $expFold) {
            // n^53 = fiftysecond*n = fortyeighth*sq*n*n*n: sq=n*n, cu=sq*n, fifth=cu*sq,
            // tenth=fifth*fifth, twentieth=tenth*tenth,
            // fortieth=twentieth*twentieth, fortysecond=fortieth*sq, fortyfourth=fortysecond*sq,
            // fortysixth=fortyfourth*sq, then fortysixth*sq*sq*n*n*n.
            // Overflow arms finish in float (sqF^25*nF*nF*nF,
            // ((cuF*sqF)^2)^4*sqF*sqF*sqF*sqF*sqF*nF*nF*nF, (fifthF^2)^4*sqF*sqF*sqF*sqF*sqF*nF*nF*nF, tenthF^4*sqF*sqF*sqF*sqF*sqF*nF*nF*nF,
            // twentiethF^2*sqF*sqF*sqF*sqF*sqF*nF*nF*nF, fortiethF*sqF*sqF*sqF*sqF*sqF*nF*nF*nF, fortysecondF*sqF*sqF*sqF*sqF*nF*nF*nF,
            // fortyfourthF*sqF*sqF*sqF*nF*nF*nF, fortysixthF*sqF*sqF*nF*nF*nF).
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
                throw new \LogicException('pow() **53 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_fiftythird_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_fiftythird_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_fiftythird_done');
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
            $fiftythirdFSq = $context->builder->fmul($fortyfourthF, $sqF);
            $fiftythirdFEighth = $context->builder->fmul($fiftythirdFSq, $sqF);
            $fiftythirdF = $context->builder->fmul($fiftythirdFEighth, $sqF);
            $nF = $context->builder->siToFp($n, $f64);
            $fiftythirdF = $context->builder->fmul($fiftythirdF, $nF);
            $fiftythirdF = $context->builder->fmul($fiftythirdF, $nF);
            $fiftythirdF = $context->builder->fmul($fiftythirdF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftythirdF
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
                throw new \LogicException('pow() **53 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_fiftythird_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_fiftythird_cu_ok');
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
            $fiftythirdF2Sq = $context->builder->fmul($fortyfourthF2, $sqF2);
            $fiftythirdF2Eighth = $context->builder->fmul($fiftythirdF2Sq, $sqF2);
            $fiftythirdF2 = $context->builder->fmul($fiftythirdF2Eighth, $sqF2);
            $nF2 = $context->builder->siToFp($n, $f64);
            $fiftythirdF2 = $context->builder->fmul($fiftythirdF2, $nF2);
            $fiftythirdF2 = $context->builder->fmul($fiftythirdF2, $nF2);
            $fiftythirdF2 = $context->builder->fmul($fiftythirdF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftythirdF2
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
                throw new \LogicException('pow() **53 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_fiftythird_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_fiftythird_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $twentiethF3 = $context->builder->fmul($tenthF3, $tenthF3);
            $fortiethF3 = $context->builder->fmul($twentiethF3, $twentiethF3);
            $sqF3 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF3 = $context->builder->fmul($fortiethF3, $sqF3);
            $fortyfourthF3 = $context->builder->fmul($fortysecondF3, $sqF3);
            $fiftythirdF3Sq = $context->builder->fmul($fortyfourthF3, $sqF3);
            $fiftythirdF3Eighth = $context->builder->fmul($fiftythirdF3Sq, $sqF3);
            $fiftythirdF3 = $context->builder->fmul($fiftythirdF3Eighth, $sqF3);
            $nF3 = $context->builder->siToFp($n, $f64);
            $fiftythirdF3 = $context->builder->fmul($fiftythirdF3, $nF3);
            $fiftythirdF3 = $context->builder->fmul($fiftythirdF3, $nF3);
            $fiftythirdF3 = $context->builder->fmul($fiftythirdF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftythirdF3
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
                throw new \LogicException('pow() **53 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_fiftythird_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_fiftythird_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF4 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $twentiethF4 = $context->builder->fmul($tenthF4, $tenthF4);
            $fortiethF4 = $context->builder->fmul($twentiethF4, $twentiethF4);
            $sqF4 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF4 = $context->builder->fmul($fortiethF4, $sqF4);
            $fortyfourthF4 = $context->builder->fmul($fortysecondF4, $sqF4);
            $fiftythirdF4Sq = $context->builder->fmul($fortyfourthF4, $sqF4);
            $fiftythirdF4Eighth = $context->builder->fmul($fiftythirdF4Sq, $sqF4);
            $fiftythirdF4 = $context->builder->fmul($fiftythirdF4Eighth, $sqF4);
            $nF4 = $context->builder->siToFp($n, $f64);
            $fiftythirdF4 = $context->builder->fmul($fiftythirdF4, $nF4);
            $fiftythirdF4 = $context->builder->fmul($fiftythirdF4, $nF4);
            $fiftythirdF4 = $context->builder->fmul($fiftythirdF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftythirdF4
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
                throw new \LogicException('pow() **53 expected smul overflow metadata (twentieth)');
            }
            $twentiethLong = JITVariable::KIND_VARIABLE === $twentiethVar->kind
                ? $context->builder->load($twentiethVar->value)
                : $twentiethVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_fiftythird_twentieth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_fiftythird_twentieth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $twentiethF5 = $context->builder->load($twentiethVar->longArithOverflowDoubleSlot);
            $fortiethF5 = $context->builder->fmul($twentiethF5, $twentiethF5);
            $sqF5 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF5 = $context->builder->fmul($fortiethF5, $sqF5);
            $fortyfourthF5 = $context->builder->fmul($fortysecondF5, $sqF5);
            $fiftythirdF5Sq = $context->builder->fmul($fortyfourthF5, $sqF5);
            $fiftythirdF5Eighth = $context->builder->fmul($fiftythirdF5Sq, $sqF5);
            $fiftythirdF5 = $context->builder->fmul($fiftythirdF5Eighth, $sqF5);
            $nF5 = $context->builder->siToFp($n, $f64);
            $fiftythirdF5 = $context->builder->fmul($fiftythirdF5, $nF5);
            $fiftythirdF5 = $context->builder->fmul($fiftythirdF5, $nF5);
            $fiftythirdF5 = $context->builder->fmul($fiftythirdF5, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftythirdF5
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
                throw new \LogicException('pow() **53 expected smul overflow metadata (fortieth)');
            }
            $fortiethLong = JITVariable::KIND_VARIABLE === $fortiethVar->kind
                ? $context->builder->load($fortiethVar->value)
                : $fortiethVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_fiftythird_fortieth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_fiftythird_fortieth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $fortiethF6 = $context->builder->load($fortiethVar->longArithOverflowDoubleSlot);
            $sqF6 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF6 = $context->builder->fmul($fortiethF6, $sqF6);
            $fortyfourthF6 = $context->builder->fmul($fortysecondF6, $sqF6);
            $fiftythirdF6Sq = $context->builder->fmul($fortyfourthF6, $sqF6);
            $fiftythirdF6Eighth = $context->builder->fmul($fiftythirdF6Sq, $sqF6);
            $fiftythirdF6 = $context->builder->fmul($fiftythirdF6Eighth, $sqF6);
            $nF6 = $context->builder->siToFp($n, $f64);
            $fiftythirdF6 = $context->builder->fmul($fiftythirdF6, $nF6);
            $fiftythirdF6 = $context->builder->fmul($fiftythirdF6, $nF6);
            $fiftythirdF6 = $context->builder->fmul($fiftythirdF6, $nF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftythirdF6
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
                throw new \LogicException('pow() **53 expected smul overflow metadata (fortysecond)');
            }
            $fortysecondLong = JITVariable::KIND_VARIABLE === $fortysecondVar->kind
                ? $context->builder->load($fortysecondVar->value)
                : $fortysecondVar->value;
            $ov7Block = BasicBlockHelper::append($context, 'pow_fiftythird_fortysecond_ov');
            $ok7Block = BasicBlockHelper::append($context, 'pow_fiftythird_fortysecond_ok');
            $context->builder->branchIf($ov7, $ov7Block, $ok7Block);

            $context->builder->positionAtEnd($ov7Block);
            $fortysecondF7 = $context->builder->load($fortysecondVar->longArithOverflowDoubleSlot);
            $sqF7 = $context->builder->siToFp($sqLong, $f64);
            $fortyfourthF7 = $context->builder->fmul($fortysecondF7, $sqF7);
            $fiftythirdF7Sq = $context->builder->fmul($fortyfourthF7, $sqF7);
            $fiftythirdF7Eighth = $context->builder->fmul($fiftythirdF7Sq, $sqF7);
            $fiftythirdF7 = $context->builder->fmul($fiftythirdF7Eighth, $sqF7);
            $nF7 = $context->builder->siToFp($n, $f64);
            $fiftythirdF7 = $context->builder->fmul($fiftythirdF7, $nF7);
            $fiftythirdF7 = $context->builder->fmul($fiftythirdF7, $nF7);
            $fiftythirdF7 = $context->builder->fmul($fiftythirdF7, $nF7);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftythirdF7
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
                throw new \LogicException('pow() **53 expected smul overflow metadata (fortyfourth)');
            }
            $fortyfourthLong = JITVariable::KIND_VARIABLE === $fortyfourthVar->kind
                ? $context->builder->load($fortyfourthVar->value)
                : $fortyfourthVar->value;
            $ov8Block = BasicBlockHelper::append($context, 'pow_fiftythird_fortyfourth_ov');
            $ok8Block = BasicBlockHelper::append($context, 'pow_fiftythird_fortyfourth_ok');
            $context->builder->branchIf($ov8, $ov8Block, $ok8Block);

            $context->builder->positionAtEnd($ov8Block);
            $fortyfourthF8 = $context->builder->load($fortyfourthVar->longArithOverflowDoubleSlot);
            $sqF8 = $context->builder->siToFp($sqLong, $f64);
            $fiftythirdF8Sq = $context->builder->fmul($fortyfourthF8, $sqF8);
            $fiftythirdF8Eighth = $context->builder->fmul($fiftythirdF8Sq, $sqF8);
            $fiftythirdF8 = $context->builder->fmul($fiftythirdF8Eighth, $sqF8);
            $nF8 = $context->builder->siToFp($n, $f64);
            $fiftythirdF8 = $context->builder->fmul($fiftythirdF8, $nF8);
            $fiftythirdF8 = $context->builder->fmul($fiftythirdF8, $nF8);
            $fiftythirdF8 = $context->builder->fmul($fiftythirdF8, $nF8);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftythirdF8
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
                throw new \LogicException('pow() **53 expected smul overflow metadata (fortysixth)');
            }
            $fortysixthLong = JITVariable::KIND_VARIABLE === $fortysixthVar->kind
                ? $context->builder->load($fortysixthVar->value)
                : $fortysixthVar->value;
            $ov9Block = BasicBlockHelper::append($context, 'pow_fiftythird_fortysixth_ov');
            $ok9Block = BasicBlockHelper::append($context, 'pow_fiftythird_fortysixth_ok');
            $context->builder->branchIf($ov9, $ov9Block, $ok9Block);

            $context->builder->positionAtEnd($ov9Block);
            $fortysixthF9 = $context->builder->load($fortysixthVar->longArithOverflowDoubleSlot);
            $sqF9 = $context->builder->siToFp($sqLong, $f64);
            $fortyeighthF9 = $context->builder->fmul($fortysixthF9, $sqF9);
            $fiftythirdF9 = $context->builder->fmul($fortyeighthF9, $sqF9);
            $nF9 = $context->builder->siToFp($n, $f64);
            $fiftythirdF9 = $context->builder->fmul($fiftythirdF9, $nF9);
            $fiftythirdF9 = $context->builder->fmul($fiftythirdF9, $nF9);
            $fiftythirdF9 = $context->builder->fmul($fiftythirdF9, $nF9);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftythirdF9
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
                throw new \LogicException('pow() **53 expected smul overflow metadata (fortyeighth)');
            }
            $fortyeighthLong = JITVariable::KIND_VARIABLE === $fortyeighthVar->kind
                ? $context->builder->load($fortyeighthVar->value)
                : $fortyeighthVar->value;
            $ov10Block = BasicBlockHelper::append($context, 'pow_fiftythird_fortyeighth_ov');
            $ok10Block = BasicBlockHelper::append($context, 'pow_fiftythird_fortyeighth_ok');
            $context->builder->branchIf($ov10, $ov10Block, $ok10Block);

            $context->builder->positionAtEnd($ov10Block);
            $fortyeighthF10 = $context->builder->load($fortyeighthVar->longArithOverflowDoubleSlot);
            $sqF10 = $context->builder->siToFp($sqLong, $f64);
            $fiftythirdF10 = $context->builder->fmul($fortyeighthF10, $sqF10);
            $nF10 = $context->builder->siToFp($n, $f64);
            $fiftythirdF10 = $context->builder->fmul($fiftythirdF10, $nF10);
            $fiftythirdF10 = $context->builder->fmul($fiftythirdF10, $nF10);
            $fiftythirdF10 = $context->builder->fmul($fiftythirdF10, $nF10);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftythirdF10
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok10Block);
            $fiftiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fortyeighthLong,
                $sqLong
            );
            $ov11 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftiethVar->longArithOverflowFlag);
            if (null === $ov11 || null === $fiftiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **53 expected smul overflow metadata (fiftieth)');
            }
            $fiftiethLong = JITVariable::KIND_VARIABLE === $fiftiethVar->kind
                ? $context->builder->load($fiftiethVar->value)
                : $fiftiethVar->value;
            $ov11Block = BasicBlockHelper::append($context, 'pow_fiftythird_fiftieth_ov');
            $ok11Block = BasicBlockHelper::append($context, 'pow_fiftythird_fiftieth_ok');
            $context->builder->branchIf($ov11, $ov11Block, $ok11Block);

            $context->builder->positionAtEnd($ov11Block);
            $fiftiethF11 = $context->builder->load($fiftiethVar->longArithOverflowDoubleSlot);
            $nF11 = $context->builder->siToFp($n, $f64);
            $fiftythirdF11 = $context->builder->fmul($fiftiethF11, $nF11);
            $fiftythirdF11 = $context->builder->fmul($fiftythirdF11, $nF11);
            $fiftythirdF11 = $context->builder->fmul($fiftythirdF11, $nF11);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftythirdF11
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok11Block);
            $fiftyfirstVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftiethLong,
                $n
            );
            $ov12 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyfirstVar->longArithOverflowFlag);
            if (null === $ov12 || null === $fiftyfirstVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **53 expected smul overflow metadata (fiftyfirst)');
            }
            $fiftyfirstLong = JITVariable::KIND_VARIABLE === $fiftyfirstVar->kind
                ? $context->builder->load($fiftyfirstVar->value)
                : $fiftyfirstVar->value;
            $ov12Block = BasicBlockHelper::append($context, 'pow_fiftythird_fiftyfirst_ov');
            $ok12Block = BasicBlockHelper::append($context, 'pow_fiftythird_fiftyfirst_ok');
            $context->builder->branchIf($ov12, $ov12Block, $ok12Block);

            $context->builder->positionAtEnd($ov12Block);
            $fiftyfirstF12 = $context->builder->load($fiftyfirstVar->longArithOverflowDoubleSlot);
            $nF12 = $context->builder->siToFp($n, $f64);
            $fiftythirdF12 = $context->builder->fmul($fiftyfirstF12, $nF12);
            $fiftythirdF12 = $context->builder->fmul($fiftythirdF12, $nF12);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftythirdF12
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok12Block);
            $fiftysecondVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyfirstLong,
                $n
            );
            $ov13 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftysecondVar->longArithOverflowFlag);
            if (null === $ov13 || null === $fiftysecondVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **53 expected smul overflow metadata (fiftysecond)');
            }
            $fiftysecondLong = JITVariable::KIND_VARIABLE === $fiftysecondVar->kind
                ? $context->builder->load($fiftysecondVar->value)
                : $fiftysecondVar->value;
            $ov13Block = BasicBlockHelper::append($context, 'pow_fiftythird_fiftysecond_ov');
            $ok13Block = BasicBlockHelper::append($context, 'pow_fiftythird_fiftysecond_ok');
            $context->builder->branchIf($ov13, $ov13Block, $ok13Block);

            $context->builder->positionAtEnd($ov13Block);
            $fiftysecondF13 = $context->builder->load($fiftysecondVar->longArithOverflowDoubleSlot);
            $nF13 = $context->builder->siToFp($n, $f64);
            $fiftythirdF13 = $context->builder->fmul($fiftysecondF13, $nF13);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftythirdF13
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok13Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $fiftysecondLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return true;
        }

        if ('fiftyfourth' === $expFold) {
            // n^54 = fiftythird*n = fortyeighth*sq*n*n*n*n: sq=n*n, cu=sq*n, fifth=cu*sq,
            // tenth=fifth*fifth, twentieth=tenth*tenth,
            // fortieth=twentieth*twentieth, fortysecond=fortieth*sq, fortyfourth=fortysecond*sq,
            // fortysixth=fortyfourth*sq, then fortysixth*sq*sq*n*n*n*n.
            // Overflow arms finish in float (sqF^25*nF*nF*nF*nF,
            // ((cuF*sqF)^2)^4*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF, (fifthF^2)^4*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF, tenthF^4*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF,
            // twentiethF^2*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF, fortiethF*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF, fortysecondF*sqF*sqF*sqF*sqF*nF*nF*nF*nF,
            // fortyfourthF*sqF*sqF*sqF*nF*nF*nF*nF, fortysixthF*sqF*sqF*nF*nF*nF*nF).
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
                throw new \LogicException('pow() **54 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_fiftyfourth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_fiftyfourth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_fiftyfourth_done');
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
            $fiftyfourthFSq = $context->builder->fmul($fortyfourthF, $sqF);
            $fiftyfourthFEighth = $context->builder->fmul($fiftyfourthFSq, $sqF);
            $fiftyfourthF = $context->builder->fmul($fiftyfourthFEighth, $sqF);
            $nF = $context->builder->siToFp($n, $f64);
            $fiftyfourthF = $context->builder->fmul($fiftyfourthF, $nF);
            $fiftyfourthF = $context->builder->fmul($fiftyfourthF, $nF);
            $fiftyfourthF = $context->builder->fmul($fiftyfourthF, $nF);
            $fiftyfourthF = $context->builder->fmul($fiftyfourthF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyfourthF
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
                throw new \LogicException('pow() **54 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_fiftyfourth_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_fiftyfourth_cu_ok');
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
            $fiftyfourthF2Sq = $context->builder->fmul($fortyfourthF2, $sqF2);
            $fiftyfourthF2Eighth = $context->builder->fmul($fiftyfourthF2Sq, $sqF2);
            $fiftyfourthF2 = $context->builder->fmul($fiftyfourthF2Eighth, $sqF2);
            $nF2 = $context->builder->siToFp($n, $f64);
            $fiftyfourthF2 = $context->builder->fmul($fiftyfourthF2, $nF2);
            $fiftyfourthF2 = $context->builder->fmul($fiftyfourthF2, $nF2);
            $fiftyfourthF2 = $context->builder->fmul($fiftyfourthF2, $nF2);
            $fiftyfourthF2 = $context->builder->fmul($fiftyfourthF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyfourthF2
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
                throw new \LogicException('pow() **54 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_fiftyfourth_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_fiftyfourth_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $twentiethF3 = $context->builder->fmul($tenthF3, $tenthF3);
            $fortiethF3 = $context->builder->fmul($twentiethF3, $twentiethF3);
            $sqF3 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF3 = $context->builder->fmul($fortiethF3, $sqF3);
            $fortyfourthF3 = $context->builder->fmul($fortysecondF3, $sqF3);
            $fiftyfourthF3Sq = $context->builder->fmul($fortyfourthF3, $sqF3);
            $fiftyfourthF3Eighth = $context->builder->fmul($fiftyfourthF3Sq, $sqF3);
            $fiftyfourthF3 = $context->builder->fmul($fiftyfourthF3Eighth, $sqF3);
            $nF3 = $context->builder->siToFp($n, $f64);
            $fiftyfourthF3 = $context->builder->fmul($fiftyfourthF3, $nF3);
            $fiftyfourthF3 = $context->builder->fmul($fiftyfourthF3, $nF3);
            $fiftyfourthF3 = $context->builder->fmul($fiftyfourthF3, $nF3);
            $fiftyfourthF3 = $context->builder->fmul($fiftyfourthF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyfourthF3
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
                throw new \LogicException('pow() **54 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_fiftyfourth_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_fiftyfourth_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF4 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $twentiethF4 = $context->builder->fmul($tenthF4, $tenthF4);
            $fortiethF4 = $context->builder->fmul($twentiethF4, $twentiethF4);
            $sqF4 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF4 = $context->builder->fmul($fortiethF4, $sqF4);
            $fortyfourthF4 = $context->builder->fmul($fortysecondF4, $sqF4);
            $fiftyfourthF4Sq = $context->builder->fmul($fortyfourthF4, $sqF4);
            $fiftyfourthF4Eighth = $context->builder->fmul($fiftyfourthF4Sq, $sqF4);
            $fiftyfourthF4 = $context->builder->fmul($fiftyfourthF4Eighth, $sqF4);
            $nF4 = $context->builder->siToFp($n, $f64);
            $fiftyfourthF4 = $context->builder->fmul($fiftyfourthF4, $nF4);
            $fiftyfourthF4 = $context->builder->fmul($fiftyfourthF4, $nF4);
            $fiftyfourthF4 = $context->builder->fmul($fiftyfourthF4, $nF4);
            $fiftyfourthF4 = $context->builder->fmul($fiftyfourthF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyfourthF4
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
                throw new \LogicException('pow() **54 expected smul overflow metadata (twentieth)');
            }
            $twentiethLong = JITVariable::KIND_VARIABLE === $twentiethVar->kind
                ? $context->builder->load($twentiethVar->value)
                : $twentiethVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_fiftyfourth_twentieth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_fiftyfourth_twentieth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $twentiethF5 = $context->builder->load($twentiethVar->longArithOverflowDoubleSlot);
            $fortiethF5 = $context->builder->fmul($twentiethF5, $twentiethF5);
            $sqF5 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF5 = $context->builder->fmul($fortiethF5, $sqF5);
            $fortyfourthF5 = $context->builder->fmul($fortysecondF5, $sqF5);
            $fiftyfourthF5Sq = $context->builder->fmul($fortyfourthF5, $sqF5);
            $fiftyfourthF5Eighth = $context->builder->fmul($fiftyfourthF5Sq, $sqF5);
            $fiftyfourthF5 = $context->builder->fmul($fiftyfourthF5Eighth, $sqF5);
            $nF5 = $context->builder->siToFp($n, $f64);
            $fiftyfourthF5 = $context->builder->fmul($fiftyfourthF5, $nF5);
            $fiftyfourthF5 = $context->builder->fmul($fiftyfourthF5, $nF5);
            $fiftyfourthF5 = $context->builder->fmul($fiftyfourthF5, $nF5);
            $fiftyfourthF5 = $context->builder->fmul($fiftyfourthF5, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyfourthF5
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
                throw new \LogicException('pow() **54 expected smul overflow metadata (fortieth)');
            }
            $fortiethLong = JITVariable::KIND_VARIABLE === $fortiethVar->kind
                ? $context->builder->load($fortiethVar->value)
                : $fortiethVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_fiftyfourth_fortieth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_fiftyfourth_fortieth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $fortiethF6 = $context->builder->load($fortiethVar->longArithOverflowDoubleSlot);
            $sqF6 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF6 = $context->builder->fmul($fortiethF6, $sqF6);
            $fortyfourthF6 = $context->builder->fmul($fortysecondF6, $sqF6);
            $fiftyfourthF6Sq = $context->builder->fmul($fortyfourthF6, $sqF6);
            $fiftyfourthF6Eighth = $context->builder->fmul($fiftyfourthF6Sq, $sqF6);
            $fiftyfourthF6 = $context->builder->fmul($fiftyfourthF6Eighth, $sqF6);
            $nF6 = $context->builder->siToFp($n, $f64);
            $fiftyfourthF6 = $context->builder->fmul($fiftyfourthF6, $nF6);
            $fiftyfourthF6 = $context->builder->fmul($fiftyfourthF6, $nF6);
            $fiftyfourthF6 = $context->builder->fmul($fiftyfourthF6, $nF6);
            $fiftyfourthF6 = $context->builder->fmul($fiftyfourthF6, $nF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyfourthF6
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
                throw new \LogicException('pow() **54 expected smul overflow metadata (fortysecond)');
            }
            $fortysecondLong = JITVariable::KIND_VARIABLE === $fortysecondVar->kind
                ? $context->builder->load($fortysecondVar->value)
                : $fortysecondVar->value;
            $ov7Block = BasicBlockHelper::append($context, 'pow_fiftyfourth_fortysecond_ov');
            $ok7Block = BasicBlockHelper::append($context, 'pow_fiftyfourth_fortysecond_ok');
            $context->builder->branchIf($ov7, $ov7Block, $ok7Block);

            $context->builder->positionAtEnd($ov7Block);
            $fortysecondF7 = $context->builder->load($fortysecondVar->longArithOverflowDoubleSlot);
            $sqF7 = $context->builder->siToFp($sqLong, $f64);
            $fortyfourthF7 = $context->builder->fmul($fortysecondF7, $sqF7);
            $fiftyfourthF7Sq = $context->builder->fmul($fortyfourthF7, $sqF7);
            $fiftyfourthF7Eighth = $context->builder->fmul($fiftyfourthF7Sq, $sqF7);
            $fiftyfourthF7 = $context->builder->fmul($fiftyfourthF7Eighth, $sqF7);
            $nF7 = $context->builder->siToFp($n, $f64);
            $fiftyfourthF7 = $context->builder->fmul($fiftyfourthF7, $nF7);
            $fiftyfourthF7 = $context->builder->fmul($fiftyfourthF7, $nF7);
            $fiftyfourthF7 = $context->builder->fmul($fiftyfourthF7, $nF7);
            $fiftyfourthF7 = $context->builder->fmul($fiftyfourthF7, $nF7);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyfourthF7
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
                throw new \LogicException('pow() **54 expected smul overflow metadata (fortyfourth)');
            }
            $fortyfourthLong = JITVariable::KIND_VARIABLE === $fortyfourthVar->kind
                ? $context->builder->load($fortyfourthVar->value)
                : $fortyfourthVar->value;
            $ov8Block = BasicBlockHelper::append($context, 'pow_fiftyfourth_fortyfourth_ov');
            $ok8Block = BasicBlockHelper::append($context, 'pow_fiftyfourth_fortyfourth_ok');
            $context->builder->branchIf($ov8, $ov8Block, $ok8Block);

            $context->builder->positionAtEnd($ov8Block);
            $fortyfourthF8 = $context->builder->load($fortyfourthVar->longArithOverflowDoubleSlot);
            $sqF8 = $context->builder->siToFp($sqLong, $f64);
            $fiftyfourthF8Sq = $context->builder->fmul($fortyfourthF8, $sqF8);
            $fiftyfourthF8Eighth = $context->builder->fmul($fiftyfourthF8Sq, $sqF8);
            $fiftyfourthF8 = $context->builder->fmul($fiftyfourthF8Eighth, $sqF8);
            $nF8 = $context->builder->siToFp($n, $f64);
            $fiftyfourthF8 = $context->builder->fmul($fiftyfourthF8, $nF8);
            $fiftyfourthF8 = $context->builder->fmul($fiftyfourthF8, $nF8);
            $fiftyfourthF8 = $context->builder->fmul($fiftyfourthF8, $nF8);
            $fiftyfourthF8 = $context->builder->fmul($fiftyfourthF8, $nF8);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyfourthF8
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
                throw new \LogicException('pow() **54 expected smul overflow metadata (fortysixth)');
            }
            $fortysixthLong = JITVariable::KIND_VARIABLE === $fortysixthVar->kind
                ? $context->builder->load($fortysixthVar->value)
                : $fortysixthVar->value;
            $ov9Block = BasicBlockHelper::append($context, 'pow_fiftyfourth_fortysixth_ov');
            $ok9Block = BasicBlockHelper::append($context, 'pow_fiftyfourth_fortysixth_ok');
            $context->builder->branchIf($ov9, $ov9Block, $ok9Block);

            $context->builder->positionAtEnd($ov9Block);
            $fortysixthF9 = $context->builder->load($fortysixthVar->longArithOverflowDoubleSlot);
            $sqF9 = $context->builder->siToFp($sqLong, $f64);
            $fortyeighthF9 = $context->builder->fmul($fortysixthF9, $sqF9);
            $fiftyfourthF9 = $context->builder->fmul($fortyeighthF9, $sqF9);
            $nF9 = $context->builder->siToFp($n, $f64);
            $fiftyfourthF9 = $context->builder->fmul($fiftyfourthF9, $nF9);
            $fiftyfourthF9 = $context->builder->fmul($fiftyfourthF9, $nF9);
            $fiftyfourthF9 = $context->builder->fmul($fiftyfourthF9, $nF9);
            $fiftyfourthF9 = $context->builder->fmul($fiftyfourthF9, $nF9);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyfourthF9
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
                throw new \LogicException('pow() **54 expected smul overflow metadata (fortyeighth)');
            }
            $fortyeighthLong = JITVariable::KIND_VARIABLE === $fortyeighthVar->kind
                ? $context->builder->load($fortyeighthVar->value)
                : $fortyeighthVar->value;
            $ov10Block = BasicBlockHelper::append($context, 'pow_fiftyfourth_fortyeighth_ov');
            $ok10Block = BasicBlockHelper::append($context, 'pow_fiftyfourth_fortyeighth_ok');
            $context->builder->branchIf($ov10, $ov10Block, $ok10Block);

            $context->builder->positionAtEnd($ov10Block);
            $fortyeighthF10 = $context->builder->load($fortyeighthVar->longArithOverflowDoubleSlot);
            $sqF10 = $context->builder->siToFp($sqLong, $f64);
            $fiftyfourthF10 = $context->builder->fmul($fortyeighthF10, $sqF10);
            $nF10 = $context->builder->siToFp($n, $f64);
            $fiftyfourthF10 = $context->builder->fmul($fiftyfourthF10, $nF10);
            $fiftyfourthF10 = $context->builder->fmul($fiftyfourthF10, $nF10);
            $fiftyfourthF10 = $context->builder->fmul($fiftyfourthF10, $nF10);
            $fiftyfourthF10 = $context->builder->fmul($fiftyfourthF10, $nF10);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyfourthF10
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok10Block);
            $fiftiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fortyeighthLong,
                $sqLong
            );
            $ov11 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftiethVar->longArithOverflowFlag);
            if (null === $ov11 || null === $fiftiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **54 expected smul overflow metadata (fiftieth)');
            }
            $fiftiethLong = JITVariable::KIND_VARIABLE === $fiftiethVar->kind
                ? $context->builder->load($fiftiethVar->value)
                : $fiftiethVar->value;
            $ov11Block = BasicBlockHelper::append($context, 'pow_fiftyfourth_fiftieth_ov');
            $ok11Block = BasicBlockHelper::append($context, 'pow_fiftyfourth_fiftieth_ok');
            $context->builder->branchIf($ov11, $ov11Block, $ok11Block);

            $context->builder->positionAtEnd($ov11Block);
            $fiftiethF11 = $context->builder->load($fiftiethVar->longArithOverflowDoubleSlot);
            $nF11 = $context->builder->siToFp($n, $f64);
            $fiftyfourthF11 = $context->builder->fmul($fiftiethF11, $nF11);
            $fiftyfourthF11 = $context->builder->fmul($fiftyfourthF11, $nF11);
            $fiftyfourthF11 = $context->builder->fmul($fiftyfourthF11, $nF11);
            $fiftyfourthF11 = $context->builder->fmul($fiftyfourthF11, $nF11);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyfourthF11
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok11Block);
            $fiftyfirstVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftiethLong,
                $n
            );
            $ov12 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyfirstVar->longArithOverflowFlag);
            if (null === $ov12 || null === $fiftyfirstVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **54 expected smul overflow metadata (fiftyfirst)');
            }
            $fiftyfirstLong = JITVariable::KIND_VARIABLE === $fiftyfirstVar->kind
                ? $context->builder->load($fiftyfirstVar->value)
                : $fiftyfirstVar->value;
            $ov12Block = BasicBlockHelper::append($context, 'pow_fiftyfourth_fiftyfirst_ov');
            $ok12Block = BasicBlockHelper::append($context, 'pow_fiftyfourth_fiftyfirst_ok');
            $context->builder->branchIf($ov12, $ov12Block, $ok12Block);

            $context->builder->positionAtEnd($ov12Block);
            $fiftyfirstF12 = $context->builder->load($fiftyfirstVar->longArithOverflowDoubleSlot);
            $nF12 = $context->builder->siToFp($n, $f64);
            $fiftyfourthF12 = $context->builder->fmul($fiftyfirstF12, $nF12);
            $fiftyfourthF12 = $context->builder->fmul($fiftyfourthF12, $nF12);
            $fiftyfourthF12 = $context->builder->fmul($fiftyfourthF12, $nF12);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyfourthF12
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok12Block);
            $fiftysecondVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyfirstLong,
                $n
            );
            $ov13 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftysecondVar->longArithOverflowFlag);
            if (null === $ov13 || null === $fiftysecondVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **54 expected smul overflow metadata (fiftysecond)');
            }
            $fiftysecondLong = JITVariable::KIND_VARIABLE === $fiftysecondVar->kind
                ? $context->builder->load($fiftysecondVar->value)
                : $fiftysecondVar->value;
            $ov13Block = BasicBlockHelper::append($context, 'pow_fiftyfourth_fiftysecond_ov');
            $ok13Block = BasicBlockHelper::append($context, 'pow_fiftyfourth_fiftysecond_ok');
            $context->builder->branchIf($ov13, $ov13Block, $ok13Block);

            $context->builder->positionAtEnd($ov13Block);
            $fiftysecondF13 = $context->builder->load($fiftysecondVar->longArithOverflowDoubleSlot);
            $nF13 = $context->builder->siToFp($n, $f64);
            $fiftyfourthF13 = $context->builder->fmul($fiftysecondF13, $nF13);
            $fiftyfourthF13 = $context->builder->fmul($fiftyfourthF13, $nF13);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyfourthF13
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok13Block);
            $fiftythirdVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftysecondLong,
                $n
            );
            $ov14 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftythirdVar->longArithOverflowFlag);
            if (null === $ov14 || null === $fiftythirdVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **54 expected smul overflow metadata (fiftythird)');
            }
            $fiftythirdLong = JITVariable::KIND_VARIABLE === $fiftythirdVar->kind
                ? $context->builder->load($fiftythirdVar->value)
                : $fiftythirdVar->value;
            $ov14Block = BasicBlockHelper::append($context, 'pow_fiftyfourth_fiftythird_ov');
            $ok14Block = BasicBlockHelper::append($context, 'pow_fiftyfourth_fiftythird_ok');
            $context->builder->branchIf($ov14, $ov14Block, $ok14Block);

            $context->builder->positionAtEnd($ov14Block);
            $fiftythirdF14 = $context->builder->load($fiftythirdVar->longArithOverflowDoubleSlot);
            $nF14 = $context->builder->siToFp($n, $f64);
            $fiftyfourthF14 = $context->builder->fmul($fiftythirdF14, $nF14);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyfourthF14
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok14Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $fiftythirdLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return true;
        }


        if ('fiftyfifth' === $expFold) {
            // n^55 = fiftyfourth*n = fortyeighth*sq*n*n*n*n*n: sq=n*n, cu=sq*n, fifth=cu*sq,
            // tenth=fifth*fifth, twentieth=tenth*tenth,
            // fortieth=twentieth*twentieth, fortysecond=fortieth*sq, fortyfourth=fortysecond*sq,
            // fortysixth=fortyfourth*sq, then fortysixth*sq*sq*n*n*n*n*n.
            // Overflow arms finish in float (sqF^25*nF*nF*nF*nF*nF,
            // ((cuF*sqF)^2)^4*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF, (fifthF^2)^4*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF, tenthF^4*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF,
            // twentiethF^2*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF, fortiethF*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF, fortysecondF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF,
            // fortyfourthF*sqF*sqF*sqF*nF*nF*nF*nF*nF, fortysixthF*sqF*sqF*nF*nF*nF*nF*nF).
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
                throw new \LogicException('pow() **55 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_fiftyfifth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_fiftyfifth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_fiftyfifth_done');
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
            $fiftyfifthFSq = $context->builder->fmul($fortyfourthF, $sqF);
            $fiftyfifthFEighth = $context->builder->fmul($fiftyfifthFSq, $sqF);
            $fiftyfifthF = $context->builder->fmul($fiftyfifthFEighth, $sqF);
            $nF = $context->builder->siToFp($n, $f64);
            $fiftyfifthF = $context->builder->fmul($fiftyfifthF, $nF);
            $fiftyfifthF = $context->builder->fmul($fiftyfifthF, $nF);
            $fiftyfifthF = $context->builder->fmul($fiftyfifthF, $nF);
            $fiftyfifthF = $context->builder->fmul($fiftyfifthF, $nF);
            $fiftyfifthF = $context->builder->fmul($fiftyfifthF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyfifthF
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
                throw new \LogicException('pow() **55 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_fiftyfifth_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_fiftyfifth_cu_ok');
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
            $fiftyfifthF2Sq = $context->builder->fmul($fortyfourthF2, $sqF2);
            $fiftyfifthF2Eighth = $context->builder->fmul($fiftyfifthF2Sq, $sqF2);
            $fiftyfifthF2 = $context->builder->fmul($fiftyfifthF2Eighth, $sqF2);
            $nF2 = $context->builder->siToFp($n, $f64);
            $fiftyfifthF2 = $context->builder->fmul($fiftyfifthF2, $nF2);
            $fiftyfifthF2 = $context->builder->fmul($fiftyfifthF2, $nF2);
            $fiftyfifthF2 = $context->builder->fmul($fiftyfifthF2, $nF2);
            $fiftyfifthF2 = $context->builder->fmul($fiftyfifthF2, $nF2);
            $fiftyfifthF2 = $context->builder->fmul($fiftyfifthF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyfifthF2
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
                throw new \LogicException('pow() **55 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_fiftyfifth_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_fiftyfifth_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $twentiethF3 = $context->builder->fmul($tenthF3, $tenthF3);
            $fortiethF3 = $context->builder->fmul($twentiethF3, $twentiethF3);
            $sqF3 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF3 = $context->builder->fmul($fortiethF3, $sqF3);
            $fortyfourthF3 = $context->builder->fmul($fortysecondF3, $sqF3);
            $fiftyfifthF3Sq = $context->builder->fmul($fortyfourthF3, $sqF3);
            $fiftyfifthF3Eighth = $context->builder->fmul($fiftyfifthF3Sq, $sqF3);
            $fiftyfifthF3 = $context->builder->fmul($fiftyfifthF3Eighth, $sqF3);
            $nF3 = $context->builder->siToFp($n, $f64);
            $fiftyfifthF3 = $context->builder->fmul($fiftyfifthF3, $nF3);
            $fiftyfifthF3 = $context->builder->fmul($fiftyfifthF3, $nF3);
            $fiftyfifthF3 = $context->builder->fmul($fiftyfifthF3, $nF3);
            $fiftyfifthF3 = $context->builder->fmul($fiftyfifthF3, $nF3);
            $fiftyfifthF3 = $context->builder->fmul($fiftyfifthF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyfifthF3
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
                throw new \LogicException('pow() **55 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_fiftyfifth_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_fiftyfifth_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF4 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $twentiethF4 = $context->builder->fmul($tenthF4, $tenthF4);
            $fortiethF4 = $context->builder->fmul($twentiethF4, $twentiethF4);
            $sqF4 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF4 = $context->builder->fmul($fortiethF4, $sqF4);
            $fortyfourthF4 = $context->builder->fmul($fortysecondF4, $sqF4);
            $fiftyfifthF4Sq = $context->builder->fmul($fortyfourthF4, $sqF4);
            $fiftyfifthF4Eighth = $context->builder->fmul($fiftyfifthF4Sq, $sqF4);
            $fiftyfifthF4 = $context->builder->fmul($fiftyfifthF4Eighth, $sqF4);
            $nF4 = $context->builder->siToFp($n, $f64);
            $fiftyfifthF4 = $context->builder->fmul($fiftyfifthF4, $nF4);
            $fiftyfifthF4 = $context->builder->fmul($fiftyfifthF4, $nF4);
            $fiftyfifthF4 = $context->builder->fmul($fiftyfifthF4, $nF4);
            $fiftyfifthF4 = $context->builder->fmul($fiftyfifthF4, $nF4);
            $fiftyfifthF4 = $context->builder->fmul($fiftyfifthF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyfifthF4
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
                throw new \LogicException('pow() **55 expected smul overflow metadata (twentieth)');
            }
            $twentiethLong = JITVariable::KIND_VARIABLE === $twentiethVar->kind
                ? $context->builder->load($twentiethVar->value)
                : $twentiethVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_fiftyfifth_twentieth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_fiftyfifth_twentieth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $twentiethF5 = $context->builder->load($twentiethVar->longArithOverflowDoubleSlot);
            $fortiethF5 = $context->builder->fmul($twentiethF5, $twentiethF5);
            $sqF5 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF5 = $context->builder->fmul($fortiethF5, $sqF5);
            $fortyfourthF5 = $context->builder->fmul($fortysecondF5, $sqF5);
            $fiftyfifthF5Sq = $context->builder->fmul($fortyfourthF5, $sqF5);
            $fiftyfifthF5Eighth = $context->builder->fmul($fiftyfifthF5Sq, $sqF5);
            $fiftyfifthF5 = $context->builder->fmul($fiftyfifthF5Eighth, $sqF5);
            $nF5 = $context->builder->siToFp($n, $f64);
            $fiftyfifthF5 = $context->builder->fmul($fiftyfifthF5, $nF5);
            $fiftyfifthF5 = $context->builder->fmul($fiftyfifthF5, $nF5);
            $fiftyfifthF5 = $context->builder->fmul($fiftyfifthF5, $nF5);
            $fiftyfifthF5 = $context->builder->fmul($fiftyfifthF5, $nF5);
            $fiftyfifthF5 = $context->builder->fmul($fiftyfifthF5, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyfifthF5
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
                throw new \LogicException('pow() **55 expected smul overflow metadata (fortieth)');
            }
            $fortiethLong = JITVariable::KIND_VARIABLE === $fortiethVar->kind
                ? $context->builder->load($fortiethVar->value)
                : $fortiethVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_fiftyfifth_fortieth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_fiftyfifth_fortieth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $fortiethF6 = $context->builder->load($fortiethVar->longArithOverflowDoubleSlot);
            $sqF6 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF6 = $context->builder->fmul($fortiethF6, $sqF6);
            $fortyfourthF6 = $context->builder->fmul($fortysecondF6, $sqF6);
            $fiftyfifthF6Sq = $context->builder->fmul($fortyfourthF6, $sqF6);
            $fiftyfifthF6Eighth = $context->builder->fmul($fiftyfifthF6Sq, $sqF6);
            $fiftyfifthF6 = $context->builder->fmul($fiftyfifthF6Eighth, $sqF6);
            $nF6 = $context->builder->siToFp($n, $f64);
            $fiftyfifthF6 = $context->builder->fmul($fiftyfifthF6, $nF6);
            $fiftyfifthF6 = $context->builder->fmul($fiftyfifthF6, $nF6);
            $fiftyfifthF6 = $context->builder->fmul($fiftyfifthF6, $nF6);
            $fiftyfifthF6 = $context->builder->fmul($fiftyfifthF6, $nF6);
            $fiftyfifthF6 = $context->builder->fmul($fiftyfifthF6, $nF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyfifthF6
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
                throw new \LogicException('pow() **55 expected smul overflow metadata (fortysecond)');
            }
            $fortysecondLong = JITVariable::KIND_VARIABLE === $fortysecondVar->kind
                ? $context->builder->load($fortysecondVar->value)
                : $fortysecondVar->value;
            $ov7Block = BasicBlockHelper::append($context, 'pow_fiftyfifth_fortysecond_ov');
            $ok7Block = BasicBlockHelper::append($context, 'pow_fiftyfifth_fortysecond_ok');
            $context->builder->branchIf($ov7, $ov7Block, $ok7Block);

            $context->builder->positionAtEnd($ov7Block);
            $fortysecondF7 = $context->builder->load($fortysecondVar->longArithOverflowDoubleSlot);
            $sqF7 = $context->builder->siToFp($sqLong, $f64);
            $fortyfourthF7 = $context->builder->fmul($fortysecondF7, $sqF7);
            $fiftyfifthF7Sq = $context->builder->fmul($fortyfourthF7, $sqF7);
            $fiftyfifthF7Eighth = $context->builder->fmul($fiftyfifthF7Sq, $sqF7);
            $fiftyfifthF7 = $context->builder->fmul($fiftyfifthF7Eighth, $sqF7);
            $nF7 = $context->builder->siToFp($n, $f64);
            $fiftyfifthF7 = $context->builder->fmul($fiftyfifthF7, $nF7);
            $fiftyfifthF7 = $context->builder->fmul($fiftyfifthF7, $nF7);
            $fiftyfifthF7 = $context->builder->fmul($fiftyfifthF7, $nF7);
            $fiftyfifthF7 = $context->builder->fmul($fiftyfifthF7, $nF7);
            $fiftyfifthF7 = $context->builder->fmul($fiftyfifthF7, $nF7);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyfifthF7
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
                throw new \LogicException('pow() **55 expected smul overflow metadata (fortyfourth)');
            }
            $fortyfourthLong = JITVariable::KIND_VARIABLE === $fortyfourthVar->kind
                ? $context->builder->load($fortyfourthVar->value)
                : $fortyfourthVar->value;
            $ov8Block = BasicBlockHelper::append($context, 'pow_fiftyfifth_fortyfourth_ov');
            $ok8Block = BasicBlockHelper::append($context, 'pow_fiftyfifth_fortyfourth_ok');
            $context->builder->branchIf($ov8, $ov8Block, $ok8Block);

            $context->builder->positionAtEnd($ov8Block);
            $fortyfourthF8 = $context->builder->load($fortyfourthVar->longArithOverflowDoubleSlot);
            $sqF8 = $context->builder->siToFp($sqLong, $f64);
            $fiftyfifthF8Sq = $context->builder->fmul($fortyfourthF8, $sqF8);
            $fiftyfifthF8Eighth = $context->builder->fmul($fiftyfifthF8Sq, $sqF8);
            $fiftyfifthF8 = $context->builder->fmul($fiftyfifthF8Eighth, $sqF8);
            $nF8 = $context->builder->siToFp($n, $f64);
            $fiftyfifthF8 = $context->builder->fmul($fiftyfifthF8, $nF8);
            $fiftyfifthF8 = $context->builder->fmul($fiftyfifthF8, $nF8);
            $fiftyfifthF8 = $context->builder->fmul($fiftyfifthF8, $nF8);
            $fiftyfifthF8 = $context->builder->fmul($fiftyfifthF8, $nF8);
            $fiftyfifthF8 = $context->builder->fmul($fiftyfifthF8, $nF8);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyfifthF8
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
                throw new \LogicException('pow() **55 expected smul overflow metadata (fortysixth)');
            }
            $fortysixthLong = JITVariable::KIND_VARIABLE === $fortysixthVar->kind
                ? $context->builder->load($fortysixthVar->value)
                : $fortysixthVar->value;
            $ov9Block = BasicBlockHelper::append($context, 'pow_fiftyfifth_fortysixth_ov');
            $ok9Block = BasicBlockHelper::append($context, 'pow_fiftyfifth_fortysixth_ok');
            $context->builder->branchIf($ov9, $ov9Block, $ok9Block);

            $context->builder->positionAtEnd($ov9Block);
            $fortysixthF9 = $context->builder->load($fortysixthVar->longArithOverflowDoubleSlot);
            $sqF9 = $context->builder->siToFp($sqLong, $f64);
            $fortyeighthF9 = $context->builder->fmul($fortysixthF9, $sqF9);
            $fiftyfifthF9 = $context->builder->fmul($fortyeighthF9, $sqF9);
            $nF9 = $context->builder->siToFp($n, $f64);
            $fiftyfifthF9 = $context->builder->fmul($fiftyfifthF9, $nF9);
            $fiftyfifthF9 = $context->builder->fmul($fiftyfifthF9, $nF9);
            $fiftyfifthF9 = $context->builder->fmul($fiftyfifthF9, $nF9);
            $fiftyfifthF9 = $context->builder->fmul($fiftyfifthF9, $nF9);
            $fiftyfifthF9 = $context->builder->fmul($fiftyfifthF9, $nF9);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyfifthF9
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
                throw new \LogicException('pow() **55 expected smul overflow metadata (fortyeighth)');
            }
            $fortyeighthLong = JITVariable::KIND_VARIABLE === $fortyeighthVar->kind
                ? $context->builder->load($fortyeighthVar->value)
                : $fortyeighthVar->value;
            $ov10Block = BasicBlockHelper::append($context, 'pow_fiftyfifth_fortyeighth_ov');
            $ok10Block = BasicBlockHelper::append($context, 'pow_fiftyfifth_fortyeighth_ok');
            $context->builder->branchIf($ov10, $ov10Block, $ok10Block);

            $context->builder->positionAtEnd($ov10Block);
            $fortyeighthF10 = $context->builder->load($fortyeighthVar->longArithOverflowDoubleSlot);
            $sqF10 = $context->builder->siToFp($sqLong, $f64);
            $fiftyfifthF10 = $context->builder->fmul($fortyeighthF10, $sqF10);
            $nF10 = $context->builder->siToFp($n, $f64);
            $fiftyfifthF10 = $context->builder->fmul($fiftyfifthF10, $nF10);
            $fiftyfifthF10 = $context->builder->fmul($fiftyfifthF10, $nF10);
            $fiftyfifthF10 = $context->builder->fmul($fiftyfifthF10, $nF10);
            $fiftyfifthF10 = $context->builder->fmul($fiftyfifthF10, $nF10);
            $fiftyfifthF10 = $context->builder->fmul($fiftyfifthF10, $nF10);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyfifthF10
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok10Block);
            $fiftiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fortyeighthLong,
                $sqLong
            );
            $ov11 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftiethVar->longArithOverflowFlag);
            if (null === $ov11 || null === $fiftiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **55 expected smul overflow metadata (fiftieth)');
            }
            $fiftiethLong = JITVariable::KIND_VARIABLE === $fiftiethVar->kind
                ? $context->builder->load($fiftiethVar->value)
                : $fiftiethVar->value;
            $ov11Block = BasicBlockHelper::append($context, 'pow_fiftyfifth_fiftieth_ov');
            $ok11Block = BasicBlockHelper::append($context, 'pow_fiftyfifth_fiftieth_ok');
            $context->builder->branchIf($ov11, $ov11Block, $ok11Block);

            $context->builder->positionAtEnd($ov11Block);
            $fiftiethF11 = $context->builder->load($fiftiethVar->longArithOverflowDoubleSlot);
            $nF11 = $context->builder->siToFp($n, $f64);
            $fiftyfifthF11 = $context->builder->fmul($fiftiethF11, $nF11);
            $fiftyfifthF11 = $context->builder->fmul($fiftyfifthF11, $nF11);
            $fiftyfifthF11 = $context->builder->fmul($fiftyfifthF11, $nF11);
            $fiftyfifthF11 = $context->builder->fmul($fiftyfifthF11, $nF11);
            $fiftyfifthF11 = $context->builder->fmul($fiftyfifthF11, $nF11);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyfifthF11
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok11Block);
            $fiftyfirstVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftiethLong,
                $n
            );
            $ov12 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyfirstVar->longArithOverflowFlag);
            if (null === $ov12 || null === $fiftyfirstVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **55 expected smul overflow metadata (fiftyfirst)');
            }
            $fiftyfirstLong = JITVariable::KIND_VARIABLE === $fiftyfirstVar->kind
                ? $context->builder->load($fiftyfirstVar->value)
                : $fiftyfirstVar->value;
            $ov12Block = BasicBlockHelper::append($context, 'pow_fiftyfifth_fiftyfirst_ov');
            $ok12Block = BasicBlockHelper::append($context, 'pow_fiftyfifth_fiftyfirst_ok');
            $context->builder->branchIf($ov12, $ov12Block, $ok12Block);

            $context->builder->positionAtEnd($ov12Block);
            $fiftyfirstF12 = $context->builder->load($fiftyfirstVar->longArithOverflowDoubleSlot);
            $nF12 = $context->builder->siToFp($n, $f64);
            $fiftyfifthF12 = $context->builder->fmul($fiftyfirstF12, $nF12);
            $fiftyfifthF12 = $context->builder->fmul($fiftyfifthF12, $nF12);
            $fiftyfifthF12 = $context->builder->fmul($fiftyfifthF12, $nF12);
            $fiftyfifthF12 = $context->builder->fmul($fiftyfifthF12, $nF12);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyfifthF12
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok12Block);
            $fiftysecondVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyfirstLong,
                $n
            );
            $ov13 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftysecondVar->longArithOverflowFlag);
            if (null === $ov13 || null === $fiftysecondVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **55 expected smul overflow metadata (fiftysecond)');
            }
            $fiftysecondLong = JITVariable::KIND_VARIABLE === $fiftysecondVar->kind
                ? $context->builder->load($fiftysecondVar->value)
                : $fiftysecondVar->value;
            $ov13Block = BasicBlockHelper::append($context, 'pow_fiftyfifth_fiftysecond_ov');
            $ok13Block = BasicBlockHelper::append($context, 'pow_fiftyfifth_fiftysecond_ok');
            $context->builder->branchIf($ov13, $ov13Block, $ok13Block);

            $context->builder->positionAtEnd($ov13Block);
            $fiftysecondF13 = $context->builder->load($fiftysecondVar->longArithOverflowDoubleSlot);
            $nF13 = $context->builder->siToFp($n, $f64);
            $fiftyfifthF13 = $context->builder->fmul($fiftysecondF13, $nF13);
            $fiftyfifthF13 = $context->builder->fmul($fiftyfifthF13, $nF13);
            $fiftyfifthF13 = $context->builder->fmul($fiftyfifthF13, $nF13);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyfifthF13
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok13Block);
            $fiftythirdVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftysecondLong,
                $n
            );
            $ov14 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftythirdVar->longArithOverflowFlag);
            if (null === $ov14 || null === $fiftythirdVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **55 expected smul overflow metadata (fiftythird)');
            }
            $fiftythirdLong = JITVariable::KIND_VARIABLE === $fiftythirdVar->kind
                ? $context->builder->load($fiftythirdVar->value)
                : $fiftythirdVar->value;
            $ov14Block = BasicBlockHelper::append($context, 'pow_fiftyfifth_fiftythird_ov');
            $ok14Block = BasicBlockHelper::append($context, 'pow_fiftyfifth_fiftythird_ok');
            $context->builder->branchIf($ov14, $ov14Block, $ok14Block);

            $context->builder->positionAtEnd($ov14Block);
            $fiftythirdF14 = $context->builder->load($fiftythirdVar->longArithOverflowDoubleSlot);
            $nF14 = $context->builder->siToFp($n, $f64);
            $fiftyfifthF14 = $context->builder->fmul($fiftythirdF14, $nF14);
            $fiftyfifthF14 = $context->builder->fmul($fiftyfifthF14, $nF14);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyfifthF14
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok14Block);
            $fiftyfourthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftythirdLong,
                $n
            );
            $ov15 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyfourthVar->longArithOverflowFlag);
            if (null === $ov15 || null === $fiftyfourthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **55 expected smul overflow metadata (fiftyfourth)');
            }
            $fiftyfourthLong = JITVariable::KIND_VARIABLE === $fiftyfourthVar->kind
                ? $context->builder->load($fiftyfourthVar->value)
                : $fiftyfourthVar->value;
            $ov15Block = BasicBlockHelper::append($context, 'pow_fiftyfifth_fiftyfourth_ov');
            $ok15Block = BasicBlockHelper::append($context, 'pow_fiftyfifth_fiftyfourth_ok');
            $context->builder->branchIf($ov15, $ov15Block, $ok15Block);

            $context->builder->positionAtEnd($ov15Block);
            $fiftyfourthF15 = $context->builder->load($fiftyfourthVar->longArithOverflowDoubleSlot);
            $nF15 = $context->builder->siToFp($n, $f64);
            $fiftyfifthF15 = $context->builder->fmul($fiftyfourthF15, $nF15);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyfifthF15
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok15Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $fiftyfourthLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return true;
        }

        if ('fiftysixth' === $expFold) {
            // n^56 = fiftyfifth*n = fortyeighth*sq*n*n*n*n*n*n: sq=n*n, cu=sq*n, fifth=cu*sq,
            // tenth=fifth*fifth, twentieth=tenth*tenth,
            // fortieth=twentieth*twentieth, fortysecond=fortieth*sq, fortyfourth=fortysecond*sq,
            // fortysixth=fortyfourth*sq, then fortysixth*sq*sq*n*n*n*n*n*n.
            // Overflow arms finish in float (sqF^25*nF*nF*nF*nF*nF*nF,
            // ((cuF*sqF)^2)^4*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF, (fifthF^2)^4*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF, tenthF^4*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF,
            // twentiethF^2*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF, fortiethF*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF, fortysecondF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF,
            // fortyfourthF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF, fortysixthF*sqF*sqF*nF*nF*nF*nF*nF*nF).
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
                throw new \LogicException('pow() **56 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_fiftysixth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_fiftysixth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_fiftysixth_done');
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
            $fiftysixthFSq = $context->builder->fmul($fortyfourthF, $sqF);
            $fiftysixthFEighth = $context->builder->fmul($fiftysixthFSq, $sqF);
            $fiftysixthF = $context->builder->fmul($fiftysixthFEighth, $sqF);
            $nF = $context->builder->siToFp($n, $f64);
            $fiftysixthF = $context->builder->fmul($fiftysixthF, $nF);
            $fiftysixthF = $context->builder->fmul($fiftysixthF, $nF);
            $fiftysixthF = $context->builder->fmul($fiftysixthF, $nF);
            $fiftysixthF = $context->builder->fmul($fiftysixthF, $nF);
            $fiftysixthF = $context->builder->fmul($fiftysixthF, $nF);
            $fiftysixthF = $context->builder->fmul($fiftysixthF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftysixthF
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
                throw new \LogicException('pow() **56 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_fiftysixth_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_fiftysixth_cu_ok');
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
            $fiftysixthF2Sq = $context->builder->fmul($fortyfourthF2, $sqF2);
            $fiftysixthF2Eighth = $context->builder->fmul($fiftysixthF2Sq, $sqF2);
            $fiftysixthF2 = $context->builder->fmul($fiftysixthF2Eighth, $sqF2);
            $nF2 = $context->builder->siToFp($n, $f64);
            $fiftysixthF2 = $context->builder->fmul($fiftysixthF2, $nF2);
            $fiftysixthF2 = $context->builder->fmul($fiftysixthF2, $nF2);
            $fiftysixthF2 = $context->builder->fmul($fiftysixthF2, $nF2);
            $fiftysixthF2 = $context->builder->fmul($fiftysixthF2, $nF2);
            $fiftysixthF2 = $context->builder->fmul($fiftysixthF2, $nF2);
            $fiftysixthF2 = $context->builder->fmul($fiftysixthF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftysixthF2
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
                throw new \LogicException('pow() **56 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_fiftysixth_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_fiftysixth_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $twentiethF3 = $context->builder->fmul($tenthF3, $tenthF3);
            $fortiethF3 = $context->builder->fmul($twentiethF3, $twentiethF3);
            $sqF3 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF3 = $context->builder->fmul($fortiethF3, $sqF3);
            $fortyfourthF3 = $context->builder->fmul($fortysecondF3, $sqF3);
            $fiftysixthF3Sq = $context->builder->fmul($fortyfourthF3, $sqF3);
            $fiftysixthF3Eighth = $context->builder->fmul($fiftysixthF3Sq, $sqF3);
            $fiftysixthF3 = $context->builder->fmul($fiftysixthF3Eighth, $sqF3);
            $nF3 = $context->builder->siToFp($n, $f64);
            $fiftysixthF3 = $context->builder->fmul($fiftysixthF3, $nF3);
            $fiftysixthF3 = $context->builder->fmul($fiftysixthF3, $nF3);
            $fiftysixthF3 = $context->builder->fmul($fiftysixthF3, $nF3);
            $fiftysixthF3 = $context->builder->fmul($fiftysixthF3, $nF3);
            $fiftysixthF3 = $context->builder->fmul($fiftysixthF3, $nF3);
            $fiftysixthF3 = $context->builder->fmul($fiftysixthF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftysixthF3
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
                throw new \LogicException('pow() **56 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_fiftysixth_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_fiftysixth_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF4 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $twentiethF4 = $context->builder->fmul($tenthF4, $tenthF4);
            $fortiethF4 = $context->builder->fmul($twentiethF4, $twentiethF4);
            $sqF4 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF4 = $context->builder->fmul($fortiethF4, $sqF4);
            $fortyfourthF4 = $context->builder->fmul($fortysecondF4, $sqF4);
            $fiftysixthF4Sq = $context->builder->fmul($fortyfourthF4, $sqF4);
            $fiftysixthF4Eighth = $context->builder->fmul($fiftysixthF4Sq, $sqF4);
            $fiftysixthF4 = $context->builder->fmul($fiftysixthF4Eighth, $sqF4);
            $nF4 = $context->builder->siToFp($n, $f64);
            $fiftysixthF4 = $context->builder->fmul($fiftysixthF4, $nF4);
            $fiftysixthF4 = $context->builder->fmul($fiftysixthF4, $nF4);
            $fiftysixthF4 = $context->builder->fmul($fiftysixthF4, $nF4);
            $fiftysixthF4 = $context->builder->fmul($fiftysixthF4, $nF4);
            $fiftysixthF4 = $context->builder->fmul($fiftysixthF4, $nF4);
            $fiftysixthF4 = $context->builder->fmul($fiftysixthF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftysixthF4
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
                throw new \LogicException('pow() **56 expected smul overflow metadata (twentieth)');
            }
            $twentiethLong = JITVariable::KIND_VARIABLE === $twentiethVar->kind
                ? $context->builder->load($twentiethVar->value)
                : $twentiethVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_fiftysixth_twentieth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_fiftysixth_twentieth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $twentiethF5 = $context->builder->load($twentiethVar->longArithOverflowDoubleSlot);
            $fortiethF5 = $context->builder->fmul($twentiethF5, $twentiethF5);
            $sqF5 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF5 = $context->builder->fmul($fortiethF5, $sqF5);
            $fortyfourthF5 = $context->builder->fmul($fortysecondF5, $sqF5);
            $fiftysixthF5Sq = $context->builder->fmul($fortyfourthF5, $sqF5);
            $fiftysixthF5Eighth = $context->builder->fmul($fiftysixthF5Sq, $sqF5);
            $fiftysixthF5 = $context->builder->fmul($fiftysixthF5Eighth, $sqF5);
            $nF5 = $context->builder->siToFp($n, $f64);
            $fiftysixthF5 = $context->builder->fmul($fiftysixthF5, $nF5);
            $fiftysixthF5 = $context->builder->fmul($fiftysixthF5, $nF5);
            $fiftysixthF5 = $context->builder->fmul($fiftysixthF5, $nF5);
            $fiftysixthF5 = $context->builder->fmul($fiftysixthF5, $nF5);
            $fiftysixthF5 = $context->builder->fmul($fiftysixthF5, $nF5);
            $fiftysixthF5 = $context->builder->fmul($fiftysixthF5, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftysixthF5
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
                throw new \LogicException('pow() **56 expected smul overflow metadata (fortieth)');
            }
            $fortiethLong = JITVariable::KIND_VARIABLE === $fortiethVar->kind
                ? $context->builder->load($fortiethVar->value)
                : $fortiethVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_fiftysixth_fortieth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_fiftysixth_fortieth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $fortiethF6 = $context->builder->load($fortiethVar->longArithOverflowDoubleSlot);
            $sqF6 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF6 = $context->builder->fmul($fortiethF6, $sqF6);
            $fortyfourthF6 = $context->builder->fmul($fortysecondF6, $sqF6);
            $fiftysixthF6Sq = $context->builder->fmul($fortyfourthF6, $sqF6);
            $fiftysixthF6Eighth = $context->builder->fmul($fiftysixthF6Sq, $sqF6);
            $fiftysixthF6 = $context->builder->fmul($fiftysixthF6Eighth, $sqF6);
            $nF6 = $context->builder->siToFp($n, $f64);
            $fiftysixthF6 = $context->builder->fmul($fiftysixthF6, $nF6);
            $fiftysixthF6 = $context->builder->fmul($fiftysixthF6, $nF6);
            $fiftysixthF6 = $context->builder->fmul($fiftysixthF6, $nF6);
            $fiftysixthF6 = $context->builder->fmul($fiftysixthF6, $nF6);
            $fiftysixthF6 = $context->builder->fmul($fiftysixthF6, $nF6);
            $fiftysixthF6 = $context->builder->fmul($fiftysixthF6, $nF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftysixthF6
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
                throw new \LogicException('pow() **56 expected smul overflow metadata (fortysecond)');
            }
            $fortysecondLong = JITVariable::KIND_VARIABLE === $fortysecondVar->kind
                ? $context->builder->load($fortysecondVar->value)
                : $fortysecondVar->value;
            $ov7Block = BasicBlockHelper::append($context, 'pow_fiftysixth_fortysecond_ov');
            $ok7Block = BasicBlockHelper::append($context, 'pow_fiftysixth_fortysecond_ok');
            $context->builder->branchIf($ov7, $ov7Block, $ok7Block);

            $context->builder->positionAtEnd($ov7Block);
            $fortysecondF7 = $context->builder->load($fortysecondVar->longArithOverflowDoubleSlot);
            $sqF7 = $context->builder->siToFp($sqLong, $f64);
            $fortyfourthF7 = $context->builder->fmul($fortysecondF7, $sqF7);
            $fiftysixthF7Sq = $context->builder->fmul($fortyfourthF7, $sqF7);
            $fiftysixthF7Eighth = $context->builder->fmul($fiftysixthF7Sq, $sqF7);
            $fiftysixthF7 = $context->builder->fmul($fiftysixthF7Eighth, $sqF7);
            $nF7 = $context->builder->siToFp($n, $f64);
            $fiftysixthF7 = $context->builder->fmul($fiftysixthF7, $nF7);
            $fiftysixthF7 = $context->builder->fmul($fiftysixthF7, $nF7);
            $fiftysixthF7 = $context->builder->fmul($fiftysixthF7, $nF7);
            $fiftysixthF7 = $context->builder->fmul($fiftysixthF7, $nF7);
            $fiftysixthF7 = $context->builder->fmul($fiftysixthF7, $nF7);
            $fiftysixthF7 = $context->builder->fmul($fiftysixthF7, $nF7);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftysixthF7
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
                throw new \LogicException('pow() **56 expected smul overflow metadata (fortyfourth)');
            }
            $fortyfourthLong = JITVariable::KIND_VARIABLE === $fortyfourthVar->kind
                ? $context->builder->load($fortyfourthVar->value)
                : $fortyfourthVar->value;
            $ov8Block = BasicBlockHelper::append($context, 'pow_fiftysixth_fortyfourth_ov');
            $ok8Block = BasicBlockHelper::append($context, 'pow_fiftysixth_fortyfourth_ok');
            $context->builder->branchIf($ov8, $ov8Block, $ok8Block);

            $context->builder->positionAtEnd($ov8Block);
            $fortyfourthF8 = $context->builder->load($fortyfourthVar->longArithOverflowDoubleSlot);
            $sqF8 = $context->builder->siToFp($sqLong, $f64);
            $fiftysixthF8Sq = $context->builder->fmul($fortyfourthF8, $sqF8);
            $fiftysixthF8Eighth = $context->builder->fmul($fiftysixthF8Sq, $sqF8);
            $fiftysixthF8 = $context->builder->fmul($fiftysixthF8Eighth, $sqF8);
            $nF8 = $context->builder->siToFp($n, $f64);
            $fiftysixthF8 = $context->builder->fmul($fiftysixthF8, $nF8);
            $fiftysixthF8 = $context->builder->fmul($fiftysixthF8, $nF8);
            $fiftysixthF8 = $context->builder->fmul($fiftysixthF8, $nF8);
            $fiftysixthF8 = $context->builder->fmul($fiftysixthF8, $nF8);
            $fiftysixthF8 = $context->builder->fmul($fiftysixthF8, $nF8);
            $fiftysixthF8 = $context->builder->fmul($fiftysixthF8, $nF8);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftysixthF8
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
                throw new \LogicException('pow() **56 expected smul overflow metadata (fortysixth)');
            }
            $fortysixthLong = JITVariable::KIND_VARIABLE === $fortysixthVar->kind
                ? $context->builder->load($fortysixthVar->value)
                : $fortysixthVar->value;
            $ov9Block = BasicBlockHelper::append($context, 'pow_fiftysixth_fortysixth_ov');
            $ok9Block = BasicBlockHelper::append($context, 'pow_fiftysixth_fortysixth_ok');
            $context->builder->branchIf($ov9, $ov9Block, $ok9Block);

            $context->builder->positionAtEnd($ov9Block);
            $fortysixthF9 = $context->builder->load($fortysixthVar->longArithOverflowDoubleSlot);
            $sqF9 = $context->builder->siToFp($sqLong, $f64);
            $fortyeighthF9 = $context->builder->fmul($fortysixthF9, $sqF9);
            $fiftysixthF9 = $context->builder->fmul($fortyeighthF9, $sqF9);
            $nF9 = $context->builder->siToFp($n, $f64);
            $fiftysixthF9 = $context->builder->fmul($fiftysixthF9, $nF9);
            $fiftysixthF9 = $context->builder->fmul($fiftysixthF9, $nF9);
            $fiftysixthF9 = $context->builder->fmul($fiftysixthF9, $nF9);
            $fiftysixthF9 = $context->builder->fmul($fiftysixthF9, $nF9);
            $fiftysixthF9 = $context->builder->fmul($fiftysixthF9, $nF9);
            $fiftysixthF9 = $context->builder->fmul($fiftysixthF9, $nF9);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftysixthF9
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
                throw new \LogicException('pow() **56 expected smul overflow metadata (fortyeighth)');
            }
            $fortyeighthLong = JITVariable::KIND_VARIABLE === $fortyeighthVar->kind
                ? $context->builder->load($fortyeighthVar->value)
                : $fortyeighthVar->value;
            $ov10Block = BasicBlockHelper::append($context, 'pow_fiftysixth_fortyeighth_ov');
            $ok10Block = BasicBlockHelper::append($context, 'pow_fiftysixth_fortyeighth_ok');
            $context->builder->branchIf($ov10, $ov10Block, $ok10Block);

            $context->builder->positionAtEnd($ov10Block);
            $fortyeighthF10 = $context->builder->load($fortyeighthVar->longArithOverflowDoubleSlot);
            $sqF10 = $context->builder->siToFp($sqLong, $f64);
            $fiftysixthF10 = $context->builder->fmul($fortyeighthF10, $sqF10);
            $nF10 = $context->builder->siToFp($n, $f64);
            $fiftysixthF10 = $context->builder->fmul($fiftysixthF10, $nF10);
            $fiftysixthF10 = $context->builder->fmul($fiftysixthF10, $nF10);
            $fiftysixthF10 = $context->builder->fmul($fiftysixthF10, $nF10);
            $fiftysixthF10 = $context->builder->fmul($fiftysixthF10, $nF10);
            $fiftysixthF10 = $context->builder->fmul($fiftysixthF10, $nF10);
            $fiftysixthF10 = $context->builder->fmul($fiftysixthF10, $nF10);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftysixthF10
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok10Block);
            $fiftiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fortyeighthLong,
                $sqLong
            );
            $ov11 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftiethVar->longArithOverflowFlag);
            if (null === $ov11 || null === $fiftiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **56 expected smul overflow metadata (fiftieth)');
            }
            $fiftiethLong = JITVariable::KIND_VARIABLE === $fiftiethVar->kind
                ? $context->builder->load($fiftiethVar->value)
                : $fiftiethVar->value;
            $ov11Block = BasicBlockHelper::append($context, 'pow_fiftysixth_fiftieth_ov');
            $ok11Block = BasicBlockHelper::append($context, 'pow_fiftysixth_fiftieth_ok');
            $context->builder->branchIf($ov11, $ov11Block, $ok11Block);

            $context->builder->positionAtEnd($ov11Block);
            $fiftiethF11 = $context->builder->load($fiftiethVar->longArithOverflowDoubleSlot);
            $nF11 = $context->builder->siToFp($n, $f64);
            $fiftysixthF11 = $context->builder->fmul($fiftiethF11, $nF11);
            $fiftysixthF11 = $context->builder->fmul($fiftysixthF11, $nF11);
            $fiftysixthF11 = $context->builder->fmul($fiftysixthF11, $nF11);
            $fiftysixthF11 = $context->builder->fmul($fiftysixthF11, $nF11);
            $fiftysixthF11 = $context->builder->fmul($fiftysixthF11, $nF11);
            $fiftysixthF11 = $context->builder->fmul($fiftysixthF11, $nF11);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftysixthF11
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok11Block);
            $fiftyfirstVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftiethLong,
                $n
            );
            $ov12 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyfirstVar->longArithOverflowFlag);
            if (null === $ov12 || null === $fiftyfirstVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **56 expected smul overflow metadata (fiftyfirst)');
            }
            $fiftyfirstLong = JITVariable::KIND_VARIABLE === $fiftyfirstVar->kind
                ? $context->builder->load($fiftyfirstVar->value)
                : $fiftyfirstVar->value;
            $ov12Block = BasicBlockHelper::append($context, 'pow_fiftysixth_fiftyfirst_ov');
            $ok12Block = BasicBlockHelper::append($context, 'pow_fiftysixth_fiftyfirst_ok');
            $context->builder->branchIf($ov12, $ov12Block, $ok12Block);

            $context->builder->positionAtEnd($ov12Block);
            $fiftyfirstF12 = $context->builder->load($fiftyfirstVar->longArithOverflowDoubleSlot);
            $nF12 = $context->builder->siToFp($n, $f64);
            $fiftysixthF12 = $context->builder->fmul($fiftyfirstF12, $nF12);
            $fiftysixthF12 = $context->builder->fmul($fiftysixthF12, $nF12);
            $fiftysixthF12 = $context->builder->fmul($fiftysixthF12, $nF12);
            $fiftysixthF12 = $context->builder->fmul($fiftysixthF12, $nF12);
            $fiftysixthF12 = $context->builder->fmul($fiftysixthF12, $nF12);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftysixthF12
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok12Block);
            $fiftysecondVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyfirstLong,
                $n
            );
            $ov13 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftysecondVar->longArithOverflowFlag);
            if (null === $ov13 || null === $fiftysecondVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **56 expected smul overflow metadata (fiftysecond)');
            }
            $fiftysecondLong = JITVariable::KIND_VARIABLE === $fiftysecondVar->kind
                ? $context->builder->load($fiftysecondVar->value)
                : $fiftysecondVar->value;
            $ov13Block = BasicBlockHelper::append($context, 'pow_fiftysixth_fiftysecond_ov');
            $ok13Block = BasicBlockHelper::append($context, 'pow_fiftysixth_fiftysecond_ok');
            $context->builder->branchIf($ov13, $ov13Block, $ok13Block);

            $context->builder->positionAtEnd($ov13Block);
            $fiftysecondF13 = $context->builder->load($fiftysecondVar->longArithOverflowDoubleSlot);
            $nF13 = $context->builder->siToFp($n, $f64);
            $fiftysixthF13 = $context->builder->fmul($fiftysecondF13, $nF13);
            $fiftysixthF13 = $context->builder->fmul($fiftysixthF13, $nF13);
            $fiftysixthF13 = $context->builder->fmul($fiftysixthF13, $nF13);
            $fiftysixthF13 = $context->builder->fmul($fiftysixthF13, $nF13);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftysixthF13
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok13Block);
            $fiftythirdVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftysecondLong,
                $n
            );
            $ov14 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftythirdVar->longArithOverflowFlag);
            if (null === $ov14 || null === $fiftythirdVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **56 expected smul overflow metadata (fiftythird)');
            }
            $fiftythirdLong = JITVariable::KIND_VARIABLE === $fiftythirdVar->kind
                ? $context->builder->load($fiftythirdVar->value)
                : $fiftythirdVar->value;
            $ov14Block = BasicBlockHelper::append($context, 'pow_fiftysixth_fiftythird_ov');
            $ok14Block = BasicBlockHelper::append($context, 'pow_fiftysixth_fiftythird_ok');
            $context->builder->branchIf($ov14, $ov14Block, $ok14Block);

            $context->builder->positionAtEnd($ov14Block);
            $fiftythirdF14 = $context->builder->load($fiftythirdVar->longArithOverflowDoubleSlot);
            $nF14 = $context->builder->siToFp($n, $f64);
            $fiftysixthF14 = $context->builder->fmul($fiftythirdF14, $nF14);
            $fiftysixthF14 = $context->builder->fmul($fiftysixthF14, $nF14);
            $fiftysixthF14 = $context->builder->fmul($fiftysixthF14, $nF14);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftysixthF14
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok14Block);
            $fiftyfourthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftythirdLong,
                $n
            );
            $ov15 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyfourthVar->longArithOverflowFlag);
            if (null === $ov15 || null === $fiftyfourthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **56 expected smul overflow metadata (fiftyfourth)');
            }
            $fiftyfourthLong = JITVariable::KIND_VARIABLE === $fiftyfourthVar->kind
                ? $context->builder->load($fiftyfourthVar->value)
                : $fiftyfourthVar->value;
            $ov15Block = BasicBlockHelper::append($context, 'pow_fiftysixth_fiftyfourth_ov');
            $ok15Block = BasicBlockHelper::append($context, 'pow_fiftysixth_fiftyfourth_ok');
            $context->builder->branchIf($ov15, $ov15Block, $ok15Block);

            $context->builder->positionAtEnd($ov15Block);
            $fiftyfourthF15 = $context->builder->load($fiftyfourthVar->longArithOverflowDoubleSlot);
            $nF15 = $context->builder->siToFp($n, $f64);
            $fiftysixthF15 = $context->builder->fmul($fiftyfourthF15, $nF15);
            $fiftysixthF15 = $context->builder->fmul($fiftysixthF15, $nF15);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftysixthF15
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok15Block);
            $fiftyfifthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyfourthLong,
                $n
            );
            $ov16 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyfifthVar->longArithOverflowFlag);
            if (null === $ov16 || null === $fiftyfifthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **56 expected smul overflow metadata (fiftyfifth)');
            }
            $fiftyfifthLong = JITVariable::KIND_VARIABLE === $fiftyfifthVar->kind
                ? $context->builder->load($fiftyfifthVar->value)
                : $fiftyfifthVar->value;
            $ov16Block = BasicBlockHelper::append($context, 'pow_fiftysixth_fiftyfifth_ov');
            $ok16Block = BasicBlockHelper::append($context, 'pow_fiftysixth_fiftyfifth_ok');
            $context->builder->branchIf($ov16, $ov16Block, $ok16Block);

            $context->builder->positionAtEnd($ov16Block);
            $fiftyfifthF16 = $context->builder->load($fiftyfifthVar->longArithOverflowDoubleSlot);
            $nF16 = $context->builder->siToFp($n, $f64);
            $fiftysixthF16 = $context->builder->fmul($fiftyfifthF16, $nF16);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftysixthF16
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok16Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $fiftyfifthLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return true;
        }
        if ('fiftyseventh' === $expFold) {
            // n^57 = fiftysixth*n = fortyeighth*sq*n*n*n*n*n*n*n: sq=n*n, cu=sq*n, fifth=cu*sq,
            // tenth=fifth*fifth, twentieth=tenth*tenth,
            // fortieth=twentieth*twentieth, fortysecond=fortieth*sq, fortyfourth=fortysecond*sq,
            // fortysixth=fortyfourth*sq, then fortysixth*sq*sq*n*n*n*n*n*n*n.
            // Overflow arms finish in float (sqF^25*nF*nF*nF*nF*nF*nF*nF,
            // ((cuF*sqF)^2)^4*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF, (fifthF^2)^4*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF, tenthF^4*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF,
            // twentiethF^2*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF, fortiethF*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF, fortysecondF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF,
            // fortyfourthF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF, fortysixthF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF).
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
                throw new \LogicException('pow() **57 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_fiftyseventh_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_fiftyseventh_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_fiftyseventh_done');
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
            $fiftyseventhFSq = $context->builder->fmul($fortyfourthF, $sqF);
            $fiftyseventhFEighth = $context->builder->fmul($fiftyseventhFSq, $sqF);
            $fiftyseventhF = $context->builder->fmul($fiftyseventhFEighth, $sqF);
            $nF = $context->builder->siToFp($n, $f64);
            $fiftyseventhF = $context->builder->fmul($fiftyseventhF, $nF);
            $fiftyseventhF = $context->builder->fmul($fiftyseventhF, $nF);
            $fiftyseventhF = $context->builder->fmul($fiftyseventhF, $nF);
            $fiftyseventhF = $context->builder->fmul($fiftyseventhF, $nF);
            $fiftyseventhF = $context->builder->fmul($fiftyseventhF, $nF);
            $fiftyseventhF = $context->builder->fmul($fiftyseventhF, $nF);
            $fiftyseventhF = $context->builder->fmul($fiftyseventhF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyseventhF
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
                throw new \LogicException('pow() **57 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_fiftyseventh_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_fiftyseventh_cu_ok');
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
            $fiftyseventhF2Sq = $context->builder->fmul($fortyfourthF2, $sqF2);
            $fiftyseventhF2Eighth = $context->builder->fmul($fiftyseventhF2Sq, $sqF2);
            $fiftyseventhF2 = $context->builder->fmul($fiftyseventhF2Eighth, $sqF2);
            $nF2 = $context->builder->siToFp($n, $f64);
            $fiftyseventhF2 = $context->builder->fmul($fiftyseventhF2, $nF2);
            $fiftyseventhF2 = $context->builder->fmul($fiftyseventhF2, $nF2);
            $fiftyseventhF2 = $context->builder->fmul($fiftyseventhF2, $nF2);
            $fiftyseventhF2 = $context->builder->fmul($fiftyseventhF2, $nF2);
            $fiftyseventhF2 = $context->builder->fmul($fiftyseventhF2, $nF2);
            $fiftyseventhF2 = $context->builder->fmul($fiftyseventhF2, $nF2);
            $fiftyseventhF2 = $context->builder->fmul($fiftyseventhF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyseventhF2
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
                throw new \LogicException('pow() **57 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_fiftyseventh_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_fiftyseventh_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $twentiethF3 = $context->builder->fmul($tenthF3, $tenthF3);
            $fortiethF3 = $context->builder->fmul($twentiethF3, $twentiethF3);
            $sqF3 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF3 = $context->builder->fmul($fortiethF3, $sqF3);
            $fortyfourthF3 = $context->builder->fmul($fortysecondF3, $sqF3);
            $fiftyseventhF3Sq = $context->builder->fmul($fortyfourthF3, $sqF3);
            $fiftyseventhF3Eighth = $context->builder->fmul($fiftyseventhF3Sq, $sqF3);
            $fiftyseventhF3 = $context->builder->fmul($fiftyseventhF3Eighth, $sqF3);
            $nF3 = $context->builder->siToFp($n, $f64);
            $fiftyseventhF3 = $context->builder->fmul($fiftyseventhF3, $nF3);
            $fiftyseventhF3 = $context->builder->fmul($fiftyseventhF3, $nF3);
            $fiftyseventhF3 = $context->builder->fmul($fiftyseventhF3, $nF3);
            $fiftyseventhF3 = $context->builder->fmul($fiftyseventhF3, $nF3);
            $fiftyseventhF3 = $context->builder->fmul($fiftyseventhF3, $nF3);
            $fiftyseventhF3 = $context->builder->fmul($fiftyseventhF3, $nF3);
            $fiftyseventhF3 = $context->builder->fmul($fiftyseventhF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyseventhF3
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
                throw new \LogicException('pow() **57 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_fiftyseventh_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_fiftyseventh_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF4 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $twentiethF4 = $context->builder->fmul($tenthF4, $tenthF4);
            $fortiethF4 = $context->builder->fmul($twentiethF4, $twentiethF4);
            $sqF4 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF4 = $context->builder->fmul($fortiethF4, $sqF4);
            $fortyfourthF4 = $context->builder->fmul($fortysecondF4, $sqF4);
            $fiftyseventhF4Sq = $context->builder->fmul($fortyfourthF4, $sqF4);
            $fiftyseventhF4Eighth = $context->builder->fmul($fiftyseventhF4Sq, $sqF4);
            $fiftyseventhF4 = $context->builder->fmul($fiftyseventhF4Eighth, $sqF4);
            $nF4 = $context->builder->siToFp($n, $f64);
            $fiftyseventhF4 = $context->builder->fmul($fiftyseventhF4, $nF4);
            $fiftyseventhF4 = $context->builder->fmul($fiftyseventhF4, $nF4);
            $fiftyseventhF4 = $context->builder->fmul($fiftyseventhF4, $nF4);
            $fiftyseventhF4 = $context->builder->fmul($fiftyseventhF4, $nF4);
            $fiftyseventhF4 = $context->builder->fmul($fiftyseventhF4, $nF4);
            $fiftyseventhF4 = $context->builder->fmul($fiftyseventhF4, $nF4);
            $fiftyseventhF4 = $context->builder->fmul($fiftyseventhF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyseventhF4
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
                throw new \LogicException('pow() **57 expected smul overflow metadata (twentieth)');
            }
            $twentiethLong = JITVariable::KIND_VARIABLE === $twentiethVar->kind
                ? $context->builder->load($twentiethVar->value)
                : $twentiethVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_fiftyseventh_twentieth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_fiftyseventh_twentieth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $twentiethF5 = $context->builder->load($twentiethVar->longArithOverflowDoubleSlot);
            $fortiethF5 = $context->builder->fmul($twentiethF5, $twentiethF5);
            $sqF5 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF5 = $context->builder->fmul($fortiethF5, $sqF5);
            $fortyfourthF5 = $context->builder->fmul($fortysecondF5, $sqF5);
            $fiftyseventhF5Sq = $context->builder->fmul($fortyfourthF5, $sqF5);
            $fiftyseventhF5Eighth = $context->builder->fmul($fiftyseventhF5Sq, $sqF5);
            $fiftyseventhF5 = $context->builder->fmul($fiftyseventhF5Eighth, $sqF5);
            $nF5 = $context->builder->siToFp($n, $f64);
            $fiftyseventhF5 = $context->builder->fmul($fiftyseventhF5, $nF5);
            $fiftyseventhF5 = $context->builder->fmul($fiftyseventhF5, $nF5);
            $fiftyseventhF5 = $context->builder->fmul($fiftyseventhF5, $nF5);
            $fiftyseventhF5 = $context->builder->fmul($fiftyseventhF5, $nF5);
            $fiftyseventhF5 = $context->builder->fmul($fiftyseventhF5, $nF5);
            $fiftyseventhF5 = $context->builder->fmul($fiftyseventhF5, $nF5);
            $fiftyseventhF5 = $context->builder->fmul($fiftyseventhF5, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyseventhF5
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
                throw new \LogicException('pow() **57 expected smul overflow metadata (fortieth)');
            }
            $fortiethLong = JITVariable::KIND_VARIABLE === $fortiethVar->kind
                ? $context->builder->load($fortiethVar->value)
                : $fortiethVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_fiftyseventh_fortieth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_fiftyseventh_fortieth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $fortiethF6 = $context->builder->load($fortiethVar->longArithOverflowDoubleSlot);
            $sqF6 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF6 = $context->builder->fmul($fortiethF6, $sqF6);
            $fortyfourthF6 = $context->builder->fmul($fortysecondF6, $sqF6);
            $fiftyseventhF6Sq = $context->builder->fmul($fortyfourthF6, $sqF6);
            $fiftyseventhF6Eighth = $context->builder->fmul($fiftyseventhF6Sq, $sqF6);
            $fiftyseventhF6 = $context->builder->fmul($fiftyseventhF6Eighth, $sqF6);
            $nF6 = $context->builder->siToFp($n, $f64);
            $fiftyseventhF6 = $context->builder->fmul($fiftyseventhF6, $nF6);
            $fiftyseventhF6 = $context->builder->fmul($fiftyseventhF6, $nF6);
            $fiftyseventhF6 = $context->builder->fmul($fiftyseventhF6, $nF6);
            $fiftyseventhF6 = $context->builder->fmul($fiftyseventhF6, $nF6);
            $fiftyseventhF6 = $context->builder->fmul($fiftyseventhF6, $nF6);
            $fiftyseventhF6 = $context->builder->fmul($fiftyseventhF6, $nF6);
            $fiftyseventhF6 = $context->builder->fmul($fiftyseventhF6, $nF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyseventhF6
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
                throw new \LogicException('pow() **57 expected smul overflow metadata (fortysecond)');
            }
            $fortysecondLong = JITVariable::KIND_VARIABLE === $fortysecondVar->kind
                ? $context->builder->load($fortysecondVar->value)
                : $fortysecondVar->value;
            $ov7Block = BasicBlockHelper::append($context, 'pow_fiftyseventh_fortysecond_ov');
            $ok7Block = BasicBlockHelper::append($context, 'pow_fiftyseventh_fortysecond_ok');
            $context->builder->branchIf($ov7, $ov7Block, $ok7Block);

            $context->builder->positionAtEnd($ov7Block);
            $fortysecondF7 = $context->builder->load($fortysecondVar->longArithOverflowDoubleSlot);
            $sqF7 = $context->builder->siToFp($sqLong, $f64);
            $fortyfourthF7 = $context->builder->fmul($fortysecondF7, $sqF7);
            $fiftyseventhF7Sq = $context->builder->fmul($fortyfourthF7, $sqF7);
            $fiftyseventhF7Eighth = $context->builder->fmul($fiftyseventhF7Sq, $sqF7);
            $fiftyseventhF7 = $context->builder->fmul($fiftyseventhF7Eighth, $sqF7);
            $nF7 = $context->builder->siToFp($n, $f64);
            $fiftyseventhF7 = $context->builder->fmul($fiftyseventhF7, $nF7);
            $fiftyseventhF7 = $context->builder->fmul($fiftyseventhF7, $nF7);
            $fiftyseventhF7 = $context->builder->fmul($fiftyseventhF7, $nF7);
            $fiftyseventhF7 = $context->builder->fmul($fiftyseventhF7, $nF7);
            $fiftyseventhF7 = $context->builder->fmul($fiftyseventhF7, $nF7);
            $fiftyseventhF7 = $context->builder->fmul($fiftyseventhF7, $nF7);
            $fiftyseventhF7 = $context->builder->fmul($fiftyseventhF7, $nF7);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyseventhF7
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
                throw new \LogicException('pow() **57 expected smul overflow metadata (fortyfourth)');
            }
            $fortyfourthLong = JITVariable::KIND_VARIABLE === $fortyfourthVar->kind
                ? $context->builder->load($fortyfourthVar->value)
                : $fortyfourthVar->value;
            $ov8Block = BasicBlockHelper::append($context, 'pow_fiftyseventh_fortyfourth_ov');
            $ok8Block = BasicBlockHelper::append($context, 'pow_fiftyseventh_fortyfourth_ok');
            $context->builder->branchIf($ov8, $ov8Block, $ok8Block);

            $context->builder->positionAtEnd($ov8Block);
            $fortyfourthF8 = $context->builder->load($fortyfourthVar->longArithOverflowDoubleSlot);
            $sqF8 = $context->builder->siToFp($sqLong, $f64);
            $fiftyseventhF8Sq = $context->builder->fmul($fortyfourthF8, $sqF8);
            $fiftyseventhF8Eighth = $context->builder->fmul($fiftyseventhF8Sq, $sqF8);
            $fiftyseventhF8 = $context->builder->fmul($fiftyseventhF8Eighth, $sqF8);
            $nF8 = $context->builder->siToFp($n, $f64);
            $fiftyseventhF8 = $context->builder->fmul($fiftyseventhF8, $nF8);
            $fiftyseventhF8 = $context->builder->fmul($fiftyseventhF8, $nF8);
            $fiftyseventhF8 = $context->builder->fmul($fiftyseventhF8, $nF8);
            $fiftyseventhF8 = $context->builder->fmul($fiftyseventhF8, $nF8);
            $fiftyseventhF8 = $context->builder->fmul($fiftyseventhF8, $nF8);
            $fiftyseventhF8 = $context->builder->fmul($fiftyseventhF8, $nF8);
            $fiftyseventhF8 = $context->builder->fmul($fiftyseventhF8, $nF8);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyseventhF8
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
                throw new \LogicException('pow() **57 expected smul overflow metadata (fortysixth)');
            }
            $fortysixthLong = JITVariable::KIND_VARIABLE === $fortysixthVar->kind
                ? $context->builder->load($fortysixthVar->value)
                : $fortysixthVar->value;
            $ov9Block = BasicBlockHelper::append($context, 'pow_fiftyseventh_fortysixth_ov');
            $ok9Block = BasicBlockHelper::append($context, 'pow_fiftyseventh_fortysixth_ok');
            $context->builder->branchIf($ov9, $ov9Block, $ok9Block);

            $context->builder->positionAtEnd($ov9Block);
            $fortysixthF9 = $context->builder->load($fortysixthVar->longArithOverflowDoubleSlot);
            $sqF9 = $context->builder->siToFp($sqLong, $f64);
            $fortyeighthF9 = $context->builder->fmul($fortysixthF9, $sqF9);
            $fiftyseventhF9 = $context->builder->fmul($fortyeighthF9, $sqF9);
            $nF9 = $context->builder->siToFp($n, $f64);
            $fiftyseventhF9 = $context->builder->fmul($fiftyseventhF9, $nF9);
            $fiftyseventhF9 = $context->builder->fmul($fiftyseventhF9, $nF9);
            $fiftyseventhF9 = $context->builder->fmul($fiftyseventhF9, $nF9);
            $fiftyseventhF9 = $context->builder->fmul($fiftyseventhF9, $nF9);
            $fiftyseventhF9 = $context->builder->fmul($fiftyseventhF9, $nF9);
            $fiftyseventhF9 = $context->builder->fmul($fiftyseventhF9, $nF9);
            $fiftyseventhF9 = $context->builder->fmul($fiftyseventhF9, $nF9);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyseventhF9
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
                throw new \LogicException('pow() **57 expected smul overflow metadata (fortyeighth)');
            }
            $fortyeighthLong = JITVariable::KIND_VARIABLE === $fortyeighthVar->kind
                ? $context->builder->load($fortyeighthVar->value)
                : $fortyeighthVar->value;
            $ov10Block = BasicBlockHelper::append($context, 'pow_fiftyseventh_fortyeighth_ov');
            $ok10Block = BasicBlockHelper::append($context, 'pow_fiftyseventh_fortyeighth_ok');
            $context->builder->branchIf($ov10, $ov10Block, $ok10Block);

            $context->builder->positionAtEnd($ov10Block);
            $fortyeighthF10 = $context->builder->load($fortyeighthVar->longArithOverflowDoubleSlot);
            $sqF10 = $context->builder->siToFp($sqLong, $f64);
            $fiftyseventhF10 = $context->builder->fmul($fortyeighthF10, $sqF10);
            $nF10 = $context->builder->siToFp($n, $f64);
            $fiftyseventhF10 = $context->builder->fmul($fiftyseventhF10, $nF10);
            $fiftyseventhF10 = $context->builder->fmul($fiftyseventhF10, $nF10);
            $fiftyseventhF10 = $context->builder->fmul($fiftyseventhF10, $nF10);
            $fiftyseventhF10 = $context->builder->fmul($fiftyseventhF10, $nF10);
            $fiftyseventhF10 = $context->builder->fmul($fiftyseventhF10, $nF10);
            $fiftyseventhF10 = $context->builder->fmul($fiftyseventhF10, $nF10);
            $fiftyseventhF10 = $context->builder->fmul($fiftyseventhF10, $nF10);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyseventhF10
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok10Block);
            $fiftiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fortyeighthLong,
                $sqLong
            );
            $ov11 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftiethVar->longArithOverflowFlag);
            if (null === $ov11 || null === $fiftiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **57 expected smul overflow metadata (fiftieth)');
            }
            $fiftiethLong = JITVariable::KIND_VARIABLE === $fiftiethVar->kind
                ? $context->builder->load($fiftiethVar->value)
                : $fiftiethVar->value;
            $ov11Block = BasicBlockHelper::append($context, 'pow_fiftyseventh_fiftieth_ov');
            $ok11Block = BasicBlockHelper::append($context, 'pow_fiftyseventh_fiftieth_ok');
            $context->builder->branchIf($ov11, $ov11Block, $ok11Block);

            $context->builder->positionAtEnd($ov11Block);
            $fiftiethF11 = $context->builder->load($fiftiethVar->longArithOverflowDoubleSlot);
            $nF11 = $context->builder->siToFp($n, $f64);
            $fiftyseventhF11 = $context->builder->fmul($fiftiethF11, $nF11);
            $fiftyseventhF11 = $context->builder->fmul($fiftyseventhF11, $nF11);
            $fiftyseventhF11 = $context->builder->fmul($fiftyseventhF11, $nF11);
            $fiftyseventhF11 = $context->builder->fmul($fiftyseventhF11, $nF11);
            $fiftyseventhF11 = $context->builder->fmul($fiftyseventhF11, $nF11);
            $fiftyseventhF11 = $context->builder->fmul($fiftyseventhF11, $nF11);
            $fiftyseventhF11 = $context->builder->fmul($fiftyseventhF11, $nF11);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyseventhF11
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok11Block);
            $fiftyfirstVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftiethLong,
                $n
            );
            $ov12 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyfirstVar->longArithOverflowFlag);
            if (null === $ov12 || null === $fiftyfirstVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **57 expected smul overflow metadata (fiftyfirst)');
            }
            $fiftyfirstLong = JITVariable::KIND_VARIABLE === $fiftyfirstVar->kind
                ? $context->builder->load($fiftyfirstVar->value)
                : $fiftyfirstVar->value;
            $ov12Block = BasicBlockHelper::append($context, 'pow_fiftyseventh_fiftyfirst_ov');
            $ok12Block = BasicBlockHelper::append($context, 'pow_fiftyseventh_fiftyfirst_ok');
            $context->builder->branchIf($ov12, $ov12Block, $ok12Block);

            $context->builder->positionAtEnd($ov12Block);
            $fiftyfirstF12 = $context->builder->load($fiftyfirstVar->longArithOverflowDoubleSlot);
            $nF12 = $context->builder->siToFp($n, $f64);
            $fiftyseventhF12 = $context->builder->fmul($fiftyfirstF12, $nF12);
            $fiftyseventhF12 = $context->builder->fmul($fiftyseventhF12, $nF12);
            $fiftyseventhF12 = $context->builder->fmul($fiftyseventhF12, $nF12);
            $fiftyseventhF12 = $context->builder->fmul($fiftyseventhF12, $nF12);
            $fiftyseventhF12 = $context->builder->fmul($fiftyseventhF12, $nF12);
            $fiftyseventhF12 = $context->builder->fmul($fiftyseventhF12, $nF12);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyseventhF12
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok12Block);
            $fiftysecondVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyfirstLong,
                $n
            );
            $ov13 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftysecondVar->longArithOverflowFlag);
            if (null === $ov13 || null === $fiftysecondVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **57 expected smul overflow metadata (fiftysecond)');
            }
            $fiftysecondLong = JITVariable::KIND_VARIABLE === $fiftysecondVar->kind
                ? $context->builder->load($fiftysecondVar->value)
                : $fiftysecondVar->value;
            $ov13Block = BasicBlockHelper::append($context, 'pow_fiftyseventh_fiftysecond_ov');
            $ok13Block = BasicBlockHelper::append($context, 'pow_fiftyseventh_fiftysecond_ok');
            $context->builder->branchIf($ov13, $ov13Block, $ok13Block);

            $context->builder->positionAtEnd($ov13Block);
            $fiftysecondF13 = $context->builder->load($fiftysecondVar->longArithOverflowDoubleSlot);
            $nF13 = $context->builder->siToFp($n, $f64);
            $fiftyseventhF13 = $context->builder->fmul($fiftysecondF13, $nF13);
            $fiftyseventhF13 = $context->builder->fmul($fiftyseventhF13, $nF13);
            $fiftyseventhF13 = $context->builder->fmul($fiftyseventhF13, $nF13);
            $fiftyseventhF13 = $context->builder->fmul($fiftyseventhF13, $nF13);
            $fiftyseventhF13 = $context->builder->fmul($fiftyseventhF13, $nF13);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyseventhF13
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok13Block);
            $fiftythirdVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftysecondLong,
                $n
            );
            $ov14 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftythirdVar->longArithOverflowFlag);
            if (null === $ov14 || null === $fiftythirdVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **57 expected smul overflow metadata (fiftythird)');
            }
            $fiftythirdLong = JITVariable::KIND_VARIABLE === $fiftythirdVar->kind
                ? $context->builder->load($fiftythirdVar->value)
                : $fiftythirdVar->value;
            $ov14Block = BasicBlockHelper::append($context, 'pow_fiftyseventh_fiftythird_ov');
            $ok14Block = BasicBlockHelper::append($context, 'pow_fiftyseventh_fiftythird_ok');
            $context->builder->branchIf($ov14, $ov14Block, $ok14Block);

            $context->builder->positionAtEnd($ov14Block);
            $fiftythirdF14 = $context->builder->load($fiftythirdVar->longArithOverflowDoubleSlot);
            $nF14 = $context->builder->siToFp($n, $f64);
            $fiftyseventhF14 = $context->builder->fmul($fiftythirdF14, $nF14);
            $fiftyseventhF14 = $context->builder->fmul($fiftyseventhF14, $nF14);
            $fiftyseventhF14 = $context->builder->fmul($fiftyseventhF14, $nF14);
            $fiftyseventhF14 = $context->builder->fmul($fiftyseventhF14, $nF14);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyseventhF14
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok14Block);
            $fiftyfourthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftythirdLong,
                $n
            );
            $ov15 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyfourthVar->longArithOverflowFlag);
            if (null === $ov15 || null === $fiftyfourthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **57 expected smul overflow metadata (fiftyfourth)');
            }
            $fiftyfourthLong = JITVariable::KIND_VARIABLE === $fiftyfourthVar->kind
                ? $context->builder->load($fiftyfourthVar->value)
                : $fiftyfourthVar->value;
            $ov15Block = BasicBlockHelper::append($context, 'pow_fiftyseventh_fiftyfourth_ov');
            $ok15Block = BasicBlockHelper::append($context, 'pow_fiftyseventh_fiftyfourth_ok');
            $context->builder->branchIf($ov15, $ov15Block, $ok15Block);

            $context->builder->positionAtEnd($ov15Block);
            $fiftyfourthF15 = $context->builder->load($fiftyfourthVar->longArithOverflowDoubleSlot);
            $nF15 = $context->builder->siToFp($n, $f64);
            $fiftyseventhF15 = $context->builder->fmul($fiftyfourthF15, $nF15);
            $fiftyseventhF15 = $context->builder->fmul($fiftyseventhF15, $nF15);
            $fiftyseventhF15 = $context->builder->fmul($fiftyseventhF15, $nF15);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyseventhF15
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok15Block);
            $fiftyfifthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyfourthLong,
                $n
            );
            $ov16 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyfifthVar->longArithOverflowFlag);
            if (null === $ov16 || null === $fiftyfifthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **57 expected smul overflow metadata (fiftyfifth)');
            }
            $fiftyfifthLong = JITVariable::KIND_VARIABLE === $fiftyfifthVar->kind
                ? $context->builder->load($fiftyfifthVar->value)
                : $fiftyfifthVar->value;
            $ov16Block = BasicBlockHelper::append($context, 'pow_fiftyseventh_fiftyfifth_ov');
            $ok16Block = BasicBlockHelper::append($context, 'pow_fiftyseventh_fiftyfifth_ok');
            $context->builder->branchIf($ov16, $ov16Block, $ok16Block);

            $context->builder->positionAtEnd($ov16Block);
            $fiftyfifthF16 = $context->builder->load($fiftyfifthVar->longArithOverflowDoubleSlot);
            $nF16 = $context->builder->siToFp($n, $f64);
            $fiftyseventhF16 = $context->builder->fmul($fiftyfifthF16, $nF16);
            $fiftyseventhF16 = $context->builder->fmul($fiftyseventhF16, $nF16);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyseventhF16
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok16Block);
            $fiftysixthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyfifthLong,
                $n
            );
            $ov17 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftysixthVar->longArithOverflowFlag);
            if (null === $ov17 || null === $fiftysixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **57 expected smul overflow metadata (fiftysixth)');
            }
            $fiftysixthLong = JITVariable::KIND_VARIABLE === $fiftysixthVar->kind
                ? $context->builder->load($fiftysixthVar->value)
                : $fiftysixthVar->value;
            $ov17Block = BasicBlockHelper::append($context, 'pow_fiftyseventh_fiftysixth_ov');
            $ok17Block = BasicBlockHelper::append($context, 'pow_fiftyseventh_fiftysixth_ok');
            $context->builder->branchIf($ov17, $ov17Block, $ok17Block);

            $context->builder->positionAtEnd($ov17Block);
            $fiftysixthF17 = $context->builder->load($fiftysixthVar->longArithOverflowDoubleSlot);
            $nF17 = $context->builder->siToFp($n, $f64);
            $fiftyseventhF17 = $context->builder->fmul($fiftysixthF17, $nF17);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyseventhF17
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok17Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $fiftysixthLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return true;
        }
        if ('fiftyeighth' === $expFold) {
            // n^58 = fiftyseventh*n = fortyeighth*sq*n*n*n*n*n*n*n*n: sq=n*n, cu=sq*n, fifth=cu*sq,
            // tenth=fifth*fifth, twentieth=tenth*tenth,
            // fortieth=twentieth*twentieth, fortysecond=fortieth*sq, fortyfourth=fortysecond*sq,
            // fortysixth=fortyfourth*sq, then fortysixth*sq*sq*n*n*n*n*n*n*n*n.
            // Overflow arms finish in float (sqF^25*nF*nF*nF*nF*nF*nF*nF*nF,
            // ((cuF*sqF)^2)^4*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF, (fifthF^2)^4*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF, tenthF^4*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF,
            // twentiethF^2*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF, fortiethF*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF, fortysecondF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF,
            // fortyfourthF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF, fortysixthF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF).
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
                throw new \LogicException('pow() **58 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_fiftyeighth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_fiftyeighth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_fiftyeighth_done');
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
            $fiftyeighthFSq = $context->builder->fmul($fortyfourthF, $sqF);
            $fiftyeighthFEighth = $context->builder->fmul($fiftyeighthFSq, $sqF);
            $fiftyeighthF = $context->builder->fmul($fiftyeighthFEighth, $sqF);
            $nF = $context->builder->siToFp($n, $f64);
            $fiftyeighthF = $context->builder->fmul($fiftyeighthF, $nF);
            $fiftyeighthF = $context->builder->fmul($fiftyeighthF, $nF);
            $fiftyeighthF = $context->builder->fmul($fiftyeighthF, $nF);
            $fiftyeighthF = $context->builder->fmul($fiftyeighthF, $nF);
            $fiftyeighthF = $context->builder->fmul($fiftyeighthF, $nF);
            $fiftyeighthF = $context->builder->fmul($fiftyeighthF, $nF);
            $fiftyeighthF = $context->builder->fmul($fiftyeighthF, $nF);
            $fiftyeighthF = $context->builder->fmul($fiftyeighthF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyeighthF
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
                throw new \LogicException('pow() **58 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_fiftyeighth_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_fiftyeighth_cu_ok');
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
            $fiftyeighthF2Sq = $context->builder->fmul($fortyfourthF2, $sqF2);
            $fiftyeighthF2Eighth = $context->builder->fmul($fiftyeighthF2Sq, $sqF2);
            $fiftyeighthF2 = $context->builder->fmul($fiftyeighthF2Eighth, $sqF2);
            $nF2 = $context->builder->siToFp($n, $f64);
            $fiftyeighthF2 = $context->builder->fmul($fiftyeighthF2, $nF2);
            $fiftyeighthF2 = $context->builder->fmul($fiftyeighthF2, $nF2);
            $fiftyeighthF2 = $context->builder->fmul($fiftyeighthF2, $nF2);
            $fiftyeighthF2 = $context->builder->fmul($fiftyeighthF2, $nF2);
            $fiftyeighthF2 = $context->builder->fmul($fiftyeighthF2, $nF2);
            $fiftyeighthF2 = $context->builder->fmul($fiftyeighthF2, $nF2);
            $fiftyeighthF2 = $context->builder->fmul($fiftyeighthF2, $nF2);
            $fiftyeighthF2 = $context->builder->fmul($fiftyeighthF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyeighthF2
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
                throw new \LogicException('pow() **58 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_fiftyeighth_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_fiftyeighth_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $twentiethF3 = $context->builder->fmul($tenthF3, $tenthF3);
            $fortiethF3 = $context->builder->fmul($twentiethF3, $twentiethF3);
            $sqF3 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF3 = $context->builder->fmul($fortiethF3, $sqF3);
            $fortyfourthF3 = $context->builder->fmul($fortysecondF3, $sqF3);
            $fiftyeighthF3Sq = $context->builder->fmul($fortyfourthF3, $sqF3);
            $fiftyeighthF3Eighth = $context->builder->fmul($fiftyeighthF3Sq, $sqF3);
            $fiftyeighthF3 = $context->builder->fmul($fiftyeighthF3Eighth, $sqF3);
            $nF3 = $context->builder->siToFp($n, $f64);
            $fiftyeighthF3 = $context->builder->fmul($fiftyeighthF3, $nF3);
            $fiftyeighthF3 = $context->builder->fmul($fiftyeighthF3, $nF3);
            $fiftyeighthF3 = $context->builder->fmul($fiftyeighthF3, $nF3);
            $fiftyeighthF3 = $context->builder->fmul($fiftyeighthF3, $nF3);
            $fiftyeighthF3 = $context->builder->fmul($fiftyeighthF3, $nF3);
            $fiftyeighthF3 = $context->builder->fmul($fiftyeighthF3, $nF3);
            $fiftyeighthF3 = $context->builder->fmul($fiftyeighthF3, $nF3);
            $fiftyeighthF3 = $context->builder->fmul($fiftyeighthF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyeighthF3
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
                throw new \LogicException('pow() **58 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_fiftyeighth_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_fiftyeighth_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF4 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $twentiethF4 = $context->builder->fmul($tenthF4, $tenthF4);
            $fortiethF4 = $context->builder->fmul($twentiethF4, $twentiethF4);
            $sqF4 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF4 = $context->builder->fmul($fortiethF4, $sqF4);
            $fortyfourthF4 = $context->builder->fmul($fortysecondF4, $sqF4);
            $fiftyeighthF4Sq = $context->builder->fmul($fortyfourthF4, $sqF4);
            $fiftyeighthF4Eighth = $context->builder->fmul($fiftyeighthF4Sq, $sqF4);
            $fiftyeighthF4 = $context->builder->fmul($fiftyeighthF4Eighth, $sqF4);
            $nF4 = $context->builder->siToFp($n, $f64);
            $fiftyeighthF4 = $context->builder->fmul($fiftyeighthF4, $nF4);
            $fiftyeighthF4 = $context->builder->fmul($fiftyeighthF4, $nF4);
            $fiftyeighthF4 = $context->builder->fmul($fiftyeighthF4, $nF4);
            $fiftyeighthF4 = $context->builder->fmul($fiftyeighthF4, $nF4);
            $fiftyeighthF4 = $context->builder->fmul($fiftyeighthF4, $nF4);
            $fiftyeighthF4 = $context->builder->fmul($fiftyeighthF4, $nF4);
            $fiftyeighthF4 = $context->builder->fmul($fiftyeighthF4, $nF4);
            $fiftyeighthF4 = $context->builder->fmul($fiftyeighthF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyeighthF4
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
                throw new \LogicException('pow() **58 expected smul overflow metadata (twentieth)');
            }
            $twentiethLong = JITVariable::KIND_VARIABLE === $twentiethVar->kind
                ? $context->builder->load($twentiethVar->value)
                : $twentiethVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_fiftyeighth_twentieth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_fiftyeighth_twentieth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $twentiethF5 = $context->builder->load($twentiethVar->longArithOverflowDoubleSlot);
            $fortiethF5 = $context->builder->fmul($twentiethF5, $twentiethF5);
            $sqF5 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF5 = $context->builder->fmul($fortiethF5, $sqF5);
            $fortyfourthF5 = $context->builder->fmul($fortysecondF5, $sqF5);
            $fiftyeighthF5Sq = $context->builder->fmul($fortyfourthF5, $sqF5);
            $fiftyeighthF5Eighth = $context->builder->fmul($fiftyeighthF5Sq, $sqF5);
            $fiftyeighthF5 = $context->builder->fmul($fiftyeighthF5Eighth, $sqF5);
            $nF5 = $context->builder->siToFp($n, $f64);
            $fiftyeighthF5 = $context->builder->fmul($fiftyeighthF5, $nF5);
            $fiftyeighthF5 = $context->builder->fmul($fiftyeighthF5, $nF5);
            $fiftyeighthF5 = $context->builder->fmul($fiftyeighthF5, $nF5);
            $fiftyeighthF5 = $context->builder->fmul($fiftyeighthF5, $nF5);
            $fiftyeighthF5 = $context->builder->fmul($fiftyeighthF5, $nF5);
            $fiftyeighthF5 = $context->builder->fmul($fiftyeighthF5, $nF5);
            $fiftyeighthF5 = $context->builder->fmul($fiftyeighthF5, $nF5);
            $fiftyeighthF5 = $context->builder->fmul($fiftyeighthF5, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyeighthF5
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
                throw new \LogicException('pow() **58 expected smul overflow metadata (fortieth)');
            }
            $fortiethLong = JITVariable::KIND_VARIABLE === $fortiethVar->kind
                ? $context->builder->load($fortiethVar->value)
                : $fortiethVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_fiftyeighth_fortieth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_fiftyeighth_fortieth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $fortiethF6 = $context->builder->load($fortiethVar->longArithOverflowDoubleSlot);
            $sqF6 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF6 = $context->builder->fmul($fortiethF6, $sqF6);
            $fortyfourthF6 = $context->builder->fmul($fortysecondF6, $sqF6);
            $fiftyeighthF6Sq = $context->builder->fmul($fortyfourthF6, $sqF6);
            $fiftyeighthF6Eighth = $context->builder->fmul($fiftyeighthF6Sq, $sqF6);
            $fiftyeighthF6 = $context->builder->fmul($fiftyeighthF6Eighth, $sqF6);
            $nF6 = $context->builder->siToFp($n, $f64);
            $fiftyeighthF6 = $context->builder->fmul($fiftyeighthF6, $nF6);
            $fiftyeighthF6 = $context->builder->fmul($fiftyeighthF6, $nF6);
            $fiftyeighthF6 = $context->builder->fmul($fiftyeighthF6, $nF6);
            $fiftyeighthF6 = $context->builder->fmul($fiftyeighthF6, $nF6);
            $fiftyeighthF6 = $context->builder->fmul($fiftyeighthF6, $nF6);
            $fiftyeighthF6 = $context->builder->fmul($fiftyeighthF6, $nF6);
            $fiftyeighthF6 = $context->builder->fmul($fiftyeighthF6, $nF6);
            $fiftyeighthF6 = $context->builder->fmul($fiftyeighthF6, $nF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyeighthF6
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
                throw new \LogicException('pow() **58 expected smul overflow metadata (fortysecond)');
            }
            $fortysecondLong = JITVariable::KIND_VARIABLE === $fortysecondVar->kind
                ? $context->builder->load($fortysecondVar->value)
                : $fortysecondVar->value;
            $ov7Block = BasicBlockHelper::append($context, 'pow_fiftyeighth_fortysecond_ov');
            $ok7Block = BasicBlockHelper::append($context, 'pow_fiftyeighth_fortysecond_ok');
            $context->builder->branchIf($ov7, $ov7Block, $ok7Block);

            $context->builder->positionAtEnd($ov7Block);
            $fortysecondF7 = $context->builder->load($fortysecondVar->longArithOverflowDoubleSlot);
            $sqF7 = $context->builder->siToFp($sqLong, $f64);
            $fortyfourthF7 = $context->builder->fmul($fortysecondF7, $sqF7);
            $fiftyeighthF7Sq = $context->builder->fmul($fortyfourthF7, $sqF7);
            $fiftyeighthF7Eighth = $context->builder->fmul($fiftyeighthF7Sq, $sqF7);
            $fiftyeighthF7 = $context->builder->fmul($fiftyeighthF7Eighth, $sqF7);
            $nF7 = $context->builder->siToFp($n, $f64);
            $fiftyeighthF7 = $context->builder->fmul($fiftyeighthF7, $nF7);
            $fiftyeighthF7 = $context->builder->fmul($fiftyeighthF7, $nF7);
            $fiftyeighthF7 = $context->builder->fmul($fiftyeighthF7, $nF7);
            $fiftyeighthF7 = $context->builder->fmul($fiftyeighthF7, $nF7);
            $fiftyeighthF7 = $context->builder->fmul($fiftyeighthF7, $nF7);
            $fiftyeighthF7 = $context->builder->fmul($fiftyeighthF7, $nF7);
            $fiftyeighthF7 = $context->builder->fmul($fiftyeighthF7, $nF7);
            $fiftyeighthF7 = $context->builder->fmul($fiftyeighthF7, $nF7);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyeighthF7
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
                throw new \LogicException('pow() **58 expected smul overflow metadata (fortyfourth)');
            }
            $fortyfourthLong = JITVariable::KIND_VARIABLE === $fortyfourthVar->kind
                ? $context->builder->load($fortyfourthVar->value)
                : $fortyfourthVar->value;
            $ov8Block = BasicBlockHelper::append($context, 'pow_fiftyeighth_fortyfourth_ov');
            $ok8Block = BasicBlockHelper::append($context, 'pow_fiftyeighth_fortyfourth_ok');
            $context->builder->branchIf($ov8, $ov8Block, $ok8Block);

            $context->builder->positionAtEnd($ov8Block);
            $fortyfourthF8 = $context->builder->load($fortyfourthVar->longArithOverflowDoubleSlot);
            $sqF8 = $context->builder->siToFp($sqLong, $f64);
            $fiftyeighthF8Sq = $context->builder->fmul($fortyfourthF8, $sqF8);
            $fiftyeighthF8Eighth = $context->builder->fmul($fiftyeighthF8Sq, $sqF8);
            $fiftyeighthF8 = $context->builder->fmul($fiftyeighthF8Eighth, $sqF8);
            $nF8 = $context->builder->siToFp($n, $f64);
            $fiftyeighthF8 = $context->builder->fmul($fiftyeighthF8, $nF8);
            $fiftyeighthF8 = $context->builder->fmul($fiftyeighthF8, $nF8);
            $fiftyeighthF8 = $context->builder->fmul($fiftyeighthF8, $nF8);
            $fiftyeighthF8 = $context->builder->fmul($fiftyeighthF8, $nF8);
            $fiftyeighthF8 = $context->builder->fmul($fiftyeighthF8, $nF8);
            $fiftyeighthF8 = $context->builder->fmul($fiftyeighthF8, $nF8);
            $fiftyeighthF8 = $context->builder->fmul($fiftyeighthF8, $nF8);
            $fiftyeighthF8 = $context->builder->fmul($fiftyeighthF8, $nF8);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyeighthF8
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
                throw new \LogicException('pow() **58 expected smul overflow metadata (fortysixth)');
            }
            $fortysixthLong = JITVariable::KIND_VARIABLE === $fortysixthVar->kind
                ? $context->builder->load($fortysixthVar->value)
                : $fortysixthVar->value;
            $ov9Block = BasicBlockHelper::append($context, 'pow_fiftyeighth_fortysixth_ov');
            $ok9Block = BasicBlockHelper::append($context, 'pow_fiftyeighth_fortysixth_ok');
            $context->builder->branchIf($ov9, $ov9Block, $ok9Block);

            $context->builder->positionAtEnd($ov9Block);
            $fortysixthF9 = $context->builder->load($fortysixthVar->longArithOverflowDoubleSlot);
            $sqF9 = $context->builder->siToFp($sqLong, $f64);
            $fortyeighthF9 = $context->builder->fmul($fortysixthF9, $sqF9);
            $fiftyeighthF9 = $context->builder->fmul($fortyeighthF9, $sqF9);
            $nF9 = $context->builder->siToFp($n, $f64);
            $fiftyeighthF9 = $context->builder->fmul($fiftyeighthF9, $nF9);
            $fiftyeighthF9 = $context->builder->fmul($fiftyeighthF9, $nF9);
            $fiftyeighthF9 = $context->builder->fmul($fiftyeighthF9, $nF9);
            $fiftyeighthF9 = $context->builder->fmul($fiftyeighthF9, $nF9);
            $fiftyeighthF9 = $context->builder->fmul($fiftyeighthF9, $nF9);
            $fiftyeighthF9 = $context->builder->fmul($fiftyeighthF9, $nF9);
            $fiftyeighthF9 = $context->builder->fmul($fiftyeighthF9, $nF9);
            $fiftyeighthF9 = $context->builder->fmul($fiftyeighthF9, $nF9);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyeighthF9
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
                throw new \LogicException('pow() **58 expected smul overflow metadata (fortyeighth)');
            }
            $fortyeighthLong = JITVariable::KIND_VARIABLE === $fortyeighthVar->kind
                ? $context->builder->load($fortyeighthVar->value)
                : $fortyeighthVar->value;
            $ov10Block = BasicBlockHelper::append($context, 'pow_fiftyeighth_fortyeighth_ov');
            $ok10Block = BasicBlockHelper::append($context, 'pow_fiftyeighth_fortyeighth_ok');
            $context->builder->branchIf($ov10, $ov10Block, $ok10Block);

            $context->builder->positionAtEnd($ov10Block);
            $fortyeighthF10 = $context->builder->load($fortyeighthVar->longArithOverflowDoubleSlot);
            $sqF10 = $context->builder->siToFp($sqLong, $f64);
            $fiftyeighthF10 = $context->builder->fmul($fortyeighthF10, $sqF10);
            $nF10 = $context->builder->siToFp($n, $f64);
            $fiftyeighthF10 = $context->builder->fmul($fiftyeighthF10, $nF10);
            $fiftyeighthF10 = $context->builder->fmul($fiftyeighthF10, $nF10);
            $fiftyeighthF10 = $context->builder->fmul($fiftyeighthF10, $nF10);
            $fiftyeighthF10 = $context->builder->fmul($fiftyeighthF10, $nF10);
            $fiftyeighthF10 = $context->builder->fmul($fiftyeighthF10, $nF10);
            $fiftyeighthF10 = $context->builder->fmul($fiftyeighthF10, $nF10);
            $fiftyeighthF10 = $context->builder->fmul($fiftyeighthF10, $nF10);
            $fiftyeighthF10 = $context->builder->fmul($fiftyeighthF10, $nF10);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyeighthF10
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok10Block);
            $fiftiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fortyeighthLong,
                $sqLong
            );
            $ov11 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftiethVar->longArithOverflowFlag);
            if (null === $ov11 || null === $fiftiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **58 expected smul overflow metadata (fiftieth)');
            }
            $fiftiethLong = JITVariable::KIND_VARIABLE === $fiftiethVar->kind
                ? $context->builder->load($fiftiethVar->value)
                : $fiftiethVar->value;
            $ov11Block = BasicBlockHelper::append($context, 'pow_fiftyeighth_fiftieth_ov');
            $ok11Block = BasicBlockHelper::append($context, 'pow_fiftyeighth_fiftieth_ok');
            $context->builder->branchIf($ov11, $ov11Block, $ok11Block);

            $context->builder->positionAtEnd($ov11Block);
            $fiftiethF11 = $context->builder->load($fiftiethVar->longArithOverflowDoubleSlot);
            $nF11 = $context->builder->siToFp($n, $f64);
            $fiftyeighthF11 = $context->builder->fmul($fiftiethF11, $nF11);
            $fiftyeighthF11 = $context->builder->fmul($fiftyeighthF11, $nF11);
            $fiftyeighthF11 = $context->builder->fmul($fiftyeighthF11, $nF11);
            $fiftyeighthF11 = $context->builder->fmul($fiftyeighthF11, $nF11);
            $fiftyeighthF11 = $context->builder->fmul($fiftyeighthF11, $nF11);
            $fiftyeighthF11 = $context->builder->fmul($fiftyeighthF11, $nF11);
            $fiftyeighthF11 = $context->builder->fmul($fiftyeighthF11, $nF11);
            $fiftyeighthF11 = $context->builder->fmul($fiftyeighthF11, $nF11);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyeighthF11
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok11Block);
            $fiftyfirstVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftiethLong,
                $n
            );
            $ov12 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyfirstVar->longArithOverflowFlag);
            if (null === $ov12 || null === $fiftyfirstVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **58 expected smul overflow metadata (fiftyfirst)');
            }
            $fiftyfirstLong = JITVariable::KIND_VARIABLE === $fiftyfirstVar->kind
                ? $context->builder->load($fiftyfirstVar->value)
                : $fiftyfirstVar->value;
            $ov12Block = BasicBlockHelper::append($context, 'pow_fiftyeighth_fiftyfirst_ov');
            $ok12Block = BasicBlockHelper::append($context, 'pow_fiftyeighth_fiftyfirst_ok');
            $context->builder->branchIf($ov12, $ov12Block, $ok12Block);

            $context->builder->positionAtEnd($ov12Block);
            $fiftyfirstF12 = $context->builder->load($fiftyfirstVar->longArithOverflowDoubleSlot);
            $nF12 = $context->builder->siToFp($n, $f64);
            $fiftyeighthF12 = $context->builder->fmul($fiftyfirstF12, $nF12);
            $fiftyeighthF12 = $context->builder->fmul($fiftyeighthF12, $nF12);
            $fiftyeighthF12 = $context->builder->fmul($fiftyeighthF12, $nF12);
            $fiftyeighthF12 = $context->builder->fmul($fiftyeighthF12, $nF12);
            $fiftyeighthF12 = $context->builder->fmul($fiftyeighthF12, $nF12);
            $fiftyeighthF12 = $context->builder->fmul($fiftyeighthF12, $nF12);
            $fiftyeighthF12 = $context->builder->fmul($fiftyeighthF12, $nF12);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyeighthF12
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok12Block);
            $fiftysecondVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyfirstLong,
                $n
            );
            $ov13 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftysecondVar->longArithOverflowFlag);
            if (null === $ov13 || null === $fiftysecondVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **58 expected smul overflow metadata (fiftysecond)');
            }
            $fiftysecondLong = JITVariable::KIND_VARIABLE === $fiftysecondVar->kind
                ? $context->builder->load($fiftysecondVar->value)
                : $fiftysecondVar->value;
            $ov13Block = BasicBlockHelper::append($context, 'pow_fiftyeighth_fiftysecond_ov');
            $ok13Block = BasicBlockHelper::append($context, 'pow_fiftyeighth_fiftysecond_ok');
            $context->builder->branchIf($ov13, $ov13Block, $ok13Block);

            $context->builder->positionAtEnd($ov13Block);
            $fiftysecondF13 = $context->builder->load($fiftysecondVar->longArithOverflowDoubleSlot);
            $nF13 = $context->builder->siToFp($n, $f64);
            $fiftyeighthF13 = $context->builder->fmul($fiftysecondF13, $nF13);
            $fiftyeighthF13 = $context->builder->fmul($fiftyeighthF13, $nF13);
            $fiftyeighthF13 = $context->builder->fmul($fiftyeighthF13, $nF13);
            $fiftyeighthF13 = $context->builder->fmul($fiftyeighthF13, $nF13);
            $fiftyeighthF13 = $context->builder->fmul($fiftyeighthF13, $nF13);
            $fiftyeighthF13 = $context->builder->fmul($fiftyeighthF13, $nF13);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyeighthF13
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok13Block);
            $fiftythirdVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftysecondLong,
                $n
            );
            $ov14 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftythirdVar->longArithOverflowFlag);
            if (null === $ov14 || null === $fiftythirdVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **58 expected smul overflow metadata (fiftythird)');
            }
            $fiftythirdLong = JITVariable::KIND_VARIABLE === $fiftythirdVar->kind
                ? $context->builder->load($fiftythirdVar->value)
                : $fiftythirdVar->value;
            $ov14Block = BasicBlockHelper::append($context, 'pow_fiftyeighth_fiftythird_ov');
            $ok14Block = BasicBlockHelper::append($context, 'pow_fiftyeighth_fiftythird_ok');
            $context->builder->branchIf($ov14, $ov14Block, $ok14Block);

            $context->builder->positionAtEnd($ov14Block);
            $fiftythirdF14 = $context->builder->load($fiftythirdVar->longArithOverflowDoubleSlot);
            $nF14 = $context->builder->siToFp($n, $f64);
            $fiftyeighthF14 = $context->builder->fmul($fiftythirdF14, $nF14);
            $fiftyeighthF14 = $context->builder->fmul($fiftyeighthF14, $nF14);
            $fiftyeighthF14 = $context->builder->fmul($fiftyeighthF14, $nF14);
            $fiftyeighthF14 = $context->builder->fmul($fiftyeighthF14, $nF14);
            $fiftyeighthF14 = $context->builder->fmul($fiftyeighthF14, $nF14);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyeighthF14
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok14Block);
            $fiftyfourthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftythirdLong,
                $n
            );
            $ov15 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyfourthVar->longArithOverflowFlag);
            if (null === $ov15 || null === $fiftyfourthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **58 expected smul overflow metadata (fiftyfourth)');
            }
            $fiftyfourthLong = JITVariable::KIND_VARIABLE === $fiftyfourthVar->kind
                ? $context->builder->load($fiftyfourthVar->value)
                : $fiftyfourthVar->value;
            $ov15Block = BasicBlockHelper::append($context, 'pow_fiftyeighth_fiftyfourth_ov');
            $ok15Block = BasicBlockHelper::append($context, 'pow_fiftyeighth_fiftyfourth_ok');
            $context->builder->branchIf($ov15, $ov15Block, $ok15Block);

            $context->builder->positionAtEnd($ov15Block);
            $fiftyfourthF15 = $context->builder->load($fiftyfourthVar->longArithOverflowDoubleSlot);
            $nF15 = $context->builder->siToFp($n, $f64);
            $fiftyeighthF15 = $context->builder->fmul($fiftyfourthF15, $nF15);
            $fiftyeighthF15 = $context->builder->fmul($fiftyeighthF15, $nF15);
            $fiftyeighthF15 = $context->builder->fmul($fiftyeighthF15, $nF15);
            $fiftyeighthF15 = $context->builder->fmul($fiftyeighthF15, $nF15);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyeighthF15
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok15Block);
            $fiftyfifthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyfourthLong,
                $n
            );
            $ov16 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyfifthVar->longArithOverflowFlag);
            if (null === $ov16 || null === $fiftyfifthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **58 expected smul overflow metadata (fiftyfifth)');
            }
            $fiftyfifthLong = JITVariable::KIND_VARIABLE === $fiftyfifthVar->kind
                ? $context->builder->load($fiftyfifthVar->value)
                : $fiftyfifthVar->value;
            $ov16Block = BasicBlockHelper::append($context, 'pow_fiftyeighth_fiftyfifth_ov');
            $ok16Block = BasicBlockHelper::append($context, 'pow_fiftyeighth_fiftyfifth_ok');
            $context->builder->branchIf($ov16, $ov16Block, $ok16Block);

            $context->builder->positionAtEnd($ov16Block);
            $fiftyfifthF16 = $context->builder->load($fiftyfifthVar->longArithOverflowDoubleSlot);
            $nF16 = $context->builder->siToFp($n, $f64);
            $fiftyeighthF16 = $context->builder->fmul($fiftyfifthF16, $nF16);
            $fiftyeighthF16 = $context->builder->fmul($fiftyeighthF16, $nF16);
            $fiftyeighthF16 = $context->builder->fmul($fiftyeighthF16, $nF16);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyeighthF16
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok16Block);
            $fiftysixthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyfifthLong,
                $n
            );
            $ov17 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftysixthVar->longArithOverflowFlag);
            if (null === $ov17 || null === $fiftysixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **58 expected smul overflow metadata (fiftysixth)');
            }
            $fiftysixthLong = JITVariable::KIND_VARIABLE === $fiftysixthVar->kind
                ? $context->builder->load($fiftysixthVar->value)
                : $fiftysixthVar->value;
            $ov17Block = BasicBlockHelper::append($context, 'pow_fiftyeighth_fiftysixth_ov');
            $ok17Block = BasicBlockHelper::append($context, 'pow_fiftyeighth_fiftysixth_ok');
            $context->builder->branchIf($ov17, $ov17Block, $ok17Block);

            $context->builder->positionAtEnd($ov17Block);
            $fiftysixthF17 = $context->builder->load($fiftysixthVar->longArithOverflowDoubleSlot);
            $nF17 = $context->builder->siToFp($n, $f64);
            $fiftyeighthF17 = $context->builder->fmul($fiftysixthF17, $nF17);
            $fiftyeighthF17 = $context->builder->fmul($fiftyeighthF17, $nF17);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyeighthF17
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok17Block);
            $fiftyseventhVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftysixthLong,
                $n
            );
            $ov18 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyseventhVar->longArithOverflowFlag);
            if (null === $ov18 || null === $fiftyseventhVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **58 expected smul overflow metadata (fiftyseventh)');
            }
            $fiftyseventhLong = JITVariable::KIND_VARIABLE === $fiftyseventhVar->kind
                ? $context->builder->load($fiftyseventhVar->value)
                : $fiftyseventhVar->value;
            $ov18Block = BasicBlockHelper::append($context, 'pow_fiftyeighth_fiftyseventh_ov');
            $ok18Block = BasicBlockHelper::append($context, 'pow_fiftyeighth_fiftyseventh_ok');
            $context->builder->branchIf($ov18, $ov18Block, $ok18Block);

            $context->builder->positionAtEnd($ov18Block);
            $fiftyseventhF18 = $context->builder->load($fiftyseventhVar->longArithOverflowDoubleSlot);
            $nF18 = $context->builder->siToFp($n, $f64);
            $fiftyeighthF18 = $context->builder->fmul($fiftyseventhF18, $nF18);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyeighthF18
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok18Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $fiftyseventhLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return true;
        }
        if ('fiftyninth' === $expFold) {
            // n^59 = fiftyeighth*n = fortyeighth*sq*n*n*n*n*n*n*n*n*n: sq=n*n, cu=sq*n, fifth=cu*sq,
            // tenth=fifth*fifth, twentieth=tenth*tenth,
            // fortieth=twentieth*twentieth, fortysecond=fortieth*sq, fortyfourth=fortysecond*sq,
            // fortysixth=fortyfourth*sq, then fortysixth*sq*sq*n*n*n*n*n*n*n*n*n.
            // Overflow arms finish in float (sqF^25*nF*nF*nF*nF*nF*nF*nF*nF*nF,
            // ((cuF*sqF)^2)^4*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF, (fifthF^2)^4*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF, tenthF^4*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF,
            // twentiethF^2*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF, fortiethF*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF, fortysecondF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF,
            // fortyfourthF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF, fortysixthF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF).
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
                throw new \LogicException('pow() **59 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_fiftyninth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_fiftyninth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_fiftyninth_done');
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
            $fiftyninthFSq = $context->builder->fmul($fortyfourthF, $sqF);
            $fiftyninthFEighth = $context->builder->fmul($fiftyninthFSq, $sqF);
            $fiftyninthF = $context->builder->fmul($fiftyninthFEighth, $sqF);
            $nF = $context->builder->siToFp($n, $f64);
            $fiftyninthF = $context->builder->fmul($fiftyninthF, $nF);
            $fiftyninthF = $context->builder->fmul($fiftyninthF, $nF);
            $fiftyninthF = $context->builder->fmul($fiftyninthF, $nF);
            $fiftyninthF = $context->builder->fmul($fiftyninthF, $nF);
            $fiftyninthF = $context->builder->fmul($fiftyninthF, $nF);
            $fiftyninthF = $context->builder->fmul($fiftyninthF, $nF);
            $fiftyninthF = $context->builder->fmul($fiftyninthF, $nF);
            $fiftyninthF = $context->builder->fmul($fiftyninthF, $nF);
            $fiftyninthF = $context->builder->fmul($fiftyninthF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyninthF
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
                throw new \LogicException('pow() **59 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_fiftyninth_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_fiftyninth_cu_ok');
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
            $fiftyninthF2Sq = $context->builder->fmul($fortyfourthF2, $sqF2);
            $fiftyninthF2Eighth = $context->builder->fmul($fiftyninthF2Sq, $sqF2);
            $fiftyninthF2 = $context->builder->fmul($fiftyninthF2Eighth, $sqF2);
            $nF2 = $context->builder->siToFp($n, $f64);
            $fiftyninthF2 = $context->builder->fmul($fiftyninthF2, $nF2);
            $fiftyninthF2 = $context->builder->fmul($fiftyninthF2, $nF2);
            $fiftyninthF2 = $context->builder->fmul($fiftyninthF2, $nF2);
            $fiftyninthF2 = $context->builder->fmul($fiftyninthF2, $nF2);
            $fiftyninthF2 = $context->builder->fmul($fiftyninthF2, $nF2);
            $fiftyninthF2 = $context->builder->fmul($fiftyninthF2, $nF2);
            $fiftyninthF2 = $context->builder->fmul($fiftyninthF2, $nF2);
            $fiftyninthF2 = $context->builder->fmul($fiftyninthF2, $nF2);
            $fiftyninthF2 = $context->builder->fmul($fiftyninthF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyninthF2
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
                throw new \LogicException('pow() **59 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_fiftyninth_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_fiftyninth_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $twentiethF3 = $context->builder->fmul($tenthF3, $tenthF3);
            $fortiethF3 = $context->builder->fmul($twentiethF3, $twentiethF3);
            $sqF3 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF3 = $context->builder->fmul($fortiethF3, $sqF3);
            $fortyfourthF3 = $context->builder->fmul($fortysecondF3, $sqF3);
            $fiftyninthF3Sq = $context->builder->fmul($fortyfourthF3, $sqF3);
            $fiftyninthF3Eighth = $context->builder->fmul($fiftyninthF3Sq, $sqF3);
            $fiftyninthF3 = $context->builder->fmul($fiftyninthF3Eighth, $sqF3);
            $nF3 = $context->builder->siToFp($n, $f64);
            $fiftyninthF3 = $context->builder->fmul($fiftyninthF3, $nF3);
            $fiftyninthF3 = $context->builder->fmul($fiftyninthF3, $nF3);
            $fiftyninthF3 = $context->builder->fmul($fiftyninthF3, $nF3);
            $fiftyninthF3 = $context->builder->fmul($fiftyninthF3, $nF3);
            $fiftyninthF3 = $context->builder->fmul($fiftyninthF3, $nF3);
            $fiftyninthF3 = $context->builder->fmul($fiftyninthF3, $nF3);
            $fiftyninthF3 = $context->builder->fmul($fiftyninthF3, $nF3);
            $fiftyninthF3 = $context->builder->fmul($fiftyninthF3, $nF3);
            $fiftyninthF3 = $context->builder->fmul($fiftyninthF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyninthF3
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
                throw new \LogicException('pow() **59 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_fiftyninth_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_fiftyninth_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF4 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $twentiethF4 = $context->builder->fmul($tenthF4, $tenthF4);
            $fortiethF4 = $context->builder->fmul($twentiethF4, $twentiethF4);
            $sqF4 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF4 = $context->builder->fmul($fortiethF4, $sqF4);
            $fortyfourthF4 = $context->builder->fmul($fortysecondF4, $sqF4);
            $fiftyninthF4Sq = $context->builder->fmul($fortyfourthF4, $sqF4);
            $fiftyninthF4Eighth = $context->builder->fmul($fiftyninthF4Sq, $sqF4);
            $fiftyninthF4 = $context->builder->fmul($fiftyninthF4Eighth, $sqF4);
            $nF4 = $context->builder->siToFp($n, $f64);
            $fiftyninthF4 = $context->builder->fmul($fiftyninthF4, $nF4);
            $fiftyninthF4 = $context->builder->fmul($fiftyninthF4, $nF4);
            $fiftyninthF4 = $context->builder->fmul($fiftyninthF4, $nF4);
            $fiftyninthF4 = $context->builder->fmul($fiftyninthF4, $nF4);
            $fiftyninthF4 = $context->builder->fmul($fiftyninthF4, $nF4);
            $fiftyninthF4 = $context->builder->fmul($fiftyninthF4, $nF4);
            $fiftyninthF4 = $context->builder->fmul($fiftyninthF4, $nF4);
            $fiftyninthF4 = $context->builder->fmul($fiftyninthF4, $nF4);
            $fiftyninthF4 = $context->builder->fmul($fiftyninthF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyninthF4
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
                throw new \LogicException('pow() **59 expected smul overflow metadata (twentieth)');
            }
            $twentiethLong = JITVariable::KIND_VARIABLE === $twentiethVar->kind
                ? $context->builder->load($twentiethVar->value)
                : $twentiethVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_fiftyninth_twentieth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_fiftyninth_twentieth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $twentiethF5 = $context->builder->load($twentiethVar->longArithOverflowDoubleSlot);
            $fortiethF5 = $context->builder->fmul($twentiethF5, $twentiethF5);
            $sqF5 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF5 = $context->builder->fmul($fortiethF5, $sqF5);
            $fortyfourthF5 = $context->builder->fmul($fortysecondF5, $sqF5);
            $fiftyninthF5Sq = $context->builder->fmul($fortyfourthF5, $sqF5);
            $fiftyninthF5Eighth = $context->builder->fmul($fiftyninthF5Sq, $sqF5);
            $fiftyninthF5 = $context->builder->fmul($fiftyninthF5Eighth, $sqF5);
            $nF5 = $context->builder->siToFp($n, $f64);
            $fiftyninthF5 = $context->builder->fmul($fiftyninthF5, $nF5);
            $fiftyninthF5 = $context->builder->fmul($fiftyninthF5, $nF5);
            $fiftyninthF5 = $context->builder->fmul($fiftyninthF5, $nF5);
            $fiftyninthF5 = $context->builder->fmul($fiftyninthF5, $nF5);
            $fiftyninthF5 = $context->builder->fmul($fiftyninthF5, $nF5);
            $fiftyninthF5 = $context->builder->fmul($fiftyninthF5, $nF5);
            $fiftyninthF5 = $context->builder->fmul($fiftyninthF5, $nF5);
            $fiftyninthF5 = $context->builder->fmul($fiftyninthF5, $nF5);
            $fiftyninthF5 = $context->builder->fmul($fiftyninthF5, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyninthF5
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
                throw new \LogicException('pow() **59 expected smul overflow metadata (fortieth)');
            }
            $fortiethLong = JITVariable::KIND_VARIABLE === $fortiethVar->kind
                ? $context->builder->load($fortiethVar->value)
                : $fortiethVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_fiftyninth_fortieth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_fiftyninth_fortieth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $fortiethF6 = $context->builder->load($fortiethVar->longArithOverflowDoubleSlot);
            $sqF6 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF6 = $context->builder->fmul($fortiethF6, $sqF6);
            $fortyfourthF6 = $context->builder->fmul($fortysecondF6, $sqF6);
            $fiftyninthF6Sq = $context->builder->fmul($fortyfourthF6, $sqF6);
            $fiftyninthF6Eighth = $context->builder->fmul($fiftyninthF6Sq, $sqF6);
            $fiftyninthF6 = $context->builder->fmul($fiftyninthF6Eighth, $sqF6);
            $nF6 = $context->builder->siToFp($n, $f64);
            $fiftyninthF6 = $context->builder->fmul($fiftyninthF6, $nF6);
            $fiftyninthF6 = $context->builder->fmul($fiftyninthF6, $nF6);
            $fiftyninthF6 = $context->builder->fmul($fiftyninthF6, $nF6);
            $fiftyninthF6 = $context->builder->fmul($fiftyninthF6, $nF6);
            $fiftyninthF6 = $context->builder->fmul($fiftyninthF6, $nF6);
            $fiftyninthF6 = $context->builder->fmul($fiftyninthF6, $nF6);
            $fiftyninthF6 = $context->builder->fmul($fiftyninthF6, $nF6);
            $fiftyninthF6 = $context->builder->fmul($fiftyninthF6, $nF6);
            $fiftyninthF6 = $context->builder->fmul($fiftyninthF6, $nF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyninthF6
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
                throw new \LogicException('pow() **59 expected smul overflow metadata (fortysecond)');
            }
            $fortysecondLong = JITVariable::KIND_VARIABLE === $fortysecondVar->kind
                ? $context->builder->load($fortysecondVar->value)
                : $fortysecondVar->value;
            $ov7Block = BasicBlockHelper::append($context, 'pow_fiftyninth_fortysecond_ov');
            $ok7Block = BasicBlockHelper::append($context, 'pow_fiftyninth_fortysecond_ok');
            $context->builder->branchIf($ov7, $ov7Block, $ok7Block);

            $context->builder->positionAtEnd($ov7Block);
            $fortysecondF7 = $context->builder->load($fortysecondVar->longArithOverflowDoubleSlot);
            $sqF7 = $context->builder->siToFp($sqLong, $f64);
            $fortyfourthF7 = $context->builder->fmul($fortysecondF7, $sqF7);
            $fiftyninthF7Sq = $context->builder->fmul($fortyfourthF7, $sqF7);
            $fiftyninthF7Eighth = $context->builder->fmul($fiftyninthF7Sq, $sqF7);
            $fiftyninthF7 = $context->builder->fmul($fiftyninthF7Eighth, $sqF7);
            $nF7 = $context->builder->siToFp($n, $f64);
            $fiftyninthF7 = $context->builder->fmul($fiftyninthF7, $nF7);
            $fiftyninthF7 = $context->builder->fmul($fiftyninthF7, $nF7);
            $fiftyninthF7 = $context->builder->fmul($fiftyninthF7, $nF7);
            $fiftyninthF7 = $context->builder->fmul($fiftyninthF7, $nF7);
            $fiftyninthF7 = $context->builder->fmul($fiftyninthF7, $nF7);
            $fiftyninthF7 = $context->builder->fmul($fiftyninthF7, $nF7);
            $fiftyninthF7 = $context->builder->fmul($fiftyninthF7, $nF7);
            $fiftyninthF7 = $context->builder->fmul($fiftyninthF7, $nF7);
            $fiftyninthF7 = $context->builder->fmul($fiftyninthF7, $nF7);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyninthF7
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
                throw new \LogicException('pow() **59 expected smul overflow metadata (fortyfourth)');
            }
            $fortyfourthLong = JITVariable::KIND_VARIABLE === $fortyfourthVar->kind
                ? $context->builder->load($fortyfourthVar->value)
                : $fortyfourthVar->value;
            $ov8Block = BasicBlockHelper::append($context, 'pow_fiftyninth_fortyfourth_ov');
            $ok8Block = BasicBlockHelper::append($context, 'pow_fiftyninth_fortyfourth_ok');
            $context->builder->branchIf($ov8, $ov8Block, $ok8Block);

            $context->builder->positionAtEnd($ov8Block);
            $fortyfourthF8 = $context->builder->load($fortyfourthVar->longArithOverflowDoubleSlot);
            $sqF8 = $context->builder->siToFp($sqLong, $f64);
            $fiftyninthF8Sq = $context->builder->fmul($fortyfourthF8, $sqF8);
            $fiftyninthF8Eighth = $context->builder->fmul($fiftyninthF8Sq, $sqF8);
            $fiftyninthF8 = $context->builder->fmul($fiftyninthF8Eighth, $sqF8);
            $nF8 = $context->builder->siToFp($n, $f64);
            $fiftyninthF8 = $context->builder->fmul($fiftyninthF8, $nF8);
            $fiftyninthF8 = $context->builder->fmul($fiftyninthF8, $nF8);
            $fiftyninthF8 = $context->builder->fmul($fiftyninthF8, $nF8);
            $fiftyninthF8 = $context->builder->fmul($fiftyninthF8, $nF8);
            $fiftyninthF8 = $context->builder->fmul($fiftyninthF8, $nF8);
            $fiftyninthF8 = $context->builder->fmul($fiftyninthF8, $nF8);
            $fiftyninthF8 = $context->builder->fmul($fiftyninthF8, $nF8);
            $fiftyninthF8 = $context->builder->fmul($fiftyninthF8, $nF8);
            $fiftyninthF8 = $context->builder->fmul($fiftyninthF8, $nF8);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyninthF8
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
                throw new \LogicException('pow() **59 expected smul overflow metadata (fortysixth)');
            }
            $fortysixthLong = JITVariable::KIND_VARIABLE === $fortysixthVar->kind
                ? $context->builder->load($fortysixthVar->value)
                : $fortysixthVar->value;
            $ov9Block = BasicBlockHelper::append($context, 'pow_fiftyninth_fortysixth_ov');
            $ok9Block = BasicBlockHelper::append($context, 'pow_fiftyninth_fortysixth_ok');
            $context->builder->branchIf($ov9, $ov9Block, $ok9Block);

            $context->builder->positionAtEnd($ov9Block);
            $fortysixthF9 = $context->builder->load($fortysixthVar->longArithOverflowDoubleSlot);
            $sqF9 = $context->builder->siToFp($sqLong, $f64);
            $fortyeighthF9 = $context->builder->fmul($fortysixthF9, $sqF9);
            $fiftyninthF9 = $context->builder->fmul($fortyeighthF9, $sqF9);
            $nF9 = $context->builder->siToFp($n, $f64);
            $fiftyninthF9 = $context->builder->fmul($fiftyninthF9, $nF9);
            $fiftyninthF9 = $context->builder->fmul($fiftyninthF9, $nF9);
            $fiftyninthF9 = $context->builder->fmul($fiftyninthF9, $nF9);
            $fiftyninthF9 = $context->builder->fmul($fiftyninthF9, $nF9);
            $fiftyninthF9 = $context->builder->fmul($fiftyninthF9, $nF9);
            $fiftyninthF9 = $context->builder->fmul($fiftyninthF9, $nF9);
            $fiftyninthF9 = $context->builder->fmul($fiftyninthF9, $nF9);
            $fiftyninthF9 = $context->builder->fmul($fiftyninthF9, $nF9);
            $fiftyninthF9 = $context->builder->fmul($fiftyninthF9, $nF9);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyninthF9
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
                throw new \LogicException('pow() **59 expected smul overflow metadata (fortyeighth)');
            }
            $fortyeighthLong = JITVariable::KIND_VARIABLE === $fortyeighthVar->kind
                ? $context->builder->load($fortyeighthVar->value)
                : $fortyeighthVar->value;
            $ov10Block = BasicBlockHelper::append($context, 'pow_fiftyninth_fortyeighth_ov');
            $ok10Block = BasicBlockHelper::append($context, 'pow_fiftyninth_fortyeighth_ok');
            $context->builder->branchIf($ov10, $ov10Block, $ok10Block);

            $context->builder->positionAtEnd($ov10Block);
            $fortyeighthF10 = $context->builder->load($fortyeighthVar->longArithOverflowDoubleSlot);
            $sqF10 = $context->builder->siToFp($sqLong, $f64);
            $fiftyninthF10 = $context->builder->fmul($fortyeighthF10, $sqF10);
            $nF10 = $context->builder->siToFp($n, $f64);
            $fiftyninthF10 = $context->builder->fmul($fiftyninthF10, $nF10);
            $fiftyninthF10 = $context->builder->fmul($fiftyninthF10, $nF10);
            $fiftyninthF10 = $context->builder->fmul($fiftyninthF10, $nF10);
            $fiftyninthF10 = $context->builder->fmul($fiftyninthF10, $nF10);
            $fiftyninthF10 = $context->builder->fmul($fiftyninthF10, $nF10);
            $fiftyninthF10 = $context->builder->fmul($fiftyninthF10, $nF10);
            $fiftyninthF10 = $context->builder->fmul($fiftyninthF10, $nF10);
            $fiftyninthF10 = $context->builder->fmul($fiftyninthF10, $nF10);
            $fiftyninthF10 = $context->builder->fmul($fiftyninthF10, $nF10);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyninthF10
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok10Block);
            $fiftiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fortyeighthLong,
                $sqLong
            );
            $ov11 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftiethVar->longArithOverflowFlag);
            if (null === $ov11 || null === $fiftiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **59 expected smul overflow metadata (fiftieth)');
            }
            $fiftiethLong = JITVariable::KIND_VARIABLE === $fiftiethVar->kind
                ? $context->builder->load($fiftiethVar->value)
                : $fiftiethVar->value;
            $ov11Block = BasicBlockHelper::append($context, 'pow_fiftyninth_fiftieth_ov');
            $ok11Block = BasicBlockHelper::append($context, 'pow_fiftyninth_fiftieth_ok');
            $context->builder->branchIf($ov11, $ov11Block, $ok11Block);

            $context->builder->positionAtEnd($ov11Block);
            $fiftiethF11 = $context->builder->load($fiftiethVar->longArithOverflowDoubleSlot);
            $nF11 = $context->builder->siToFp($n, $f64);
            $fiftyninthF11 = $context->builder->fmul($fiftiethF11, $nF11);
            $fiftyninthF11 = $context->builder->fmul($fiftyninthF11, $nF11);
            $fiftyninthF11 = $context->builder->fmul($fiftyninthF11, $nF11);
            $fiftyninthF11 = $context->builder->fmul($fiftyninthF11, $nF11);
            $fiftyninthF11 = $context->builder->fmul($fiftyninthF11, $nF11);
            $fiftyninthF11 = $context->builder->fmul($fiftyninthF11, $nF11);
            $fiftyninthF11 = $context->builder->fmul($fiftyninthF11, $nF11);
            $fiftyninthF11 = $context->builder->fmul($fiftyninthF11, $nF11);
            $fiftyninthF11 = $context->builder->fmul($fiftyninthF11, $nF11);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyninthF11
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok11Block);
            $fiftyfirstVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftiethLong,
                $n
            );
            $ov12 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyfirstVar->longArithOverflowFlag);
            if (null === $ov12 || null === $fiftyfirstVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **59 expected smul overflow metadata (fiftyfirst)');
            }
            $fiftyfirstLong = JITVariable::KIND_VARIABLE === $fiftyfirstVar->kind
                ? $context->builder->load($fiftyfirstVar->value)
                : $fiftyfirstVar->value;
            $ov12Block = BasicBlockHelper::append($context, 'pow_fiftyninth_fiftyfirst_ov');
            $ok12Block = BasicBlockHelper::append($context, 'pow_fiftyninth_fiftyfirst_ok');
            $context->builder->branchIf($ov12, $ov12Block, $ok12Block);

            $context->builder->positionAtEnd($ov12Block);
            $fiftyfirstF12 = $context->builder->load($fiftyfirstVar->longArithOverflowDoubleSlot);
            $nF12 = $context->builder->siToFp($n, $f64);
            $fiftyninthF12 = $context->builder->fmul($fiftyfirstF12, $nF12);
            $fiftyninthF12 = $context->builder->fmul($fiftyninthF12, $nF12);
            $fiftyninthF12 = $context->builder->fmul($fiftyninthF12, $nF12);
            $fiftyninthF12 = $context->builder->fmul($fiftyninthF12, $nF12);
            $fiftyninthF12 = $context->builder->fmul($fiftyninthF12, $nF12);
            $fiftyninthF12 = $context->builder->fmul($fiftyninthF12, $nF12);
            $fiftyninthF12 = $context->builder->fmul($fiftyninthF12, $nF12);
            $fiftyninthF12 = $context->builder->fmul($fiftyninthF12, $nF12);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyninthF12
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok12Block);
            $fiftysecondVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyfirstLong,
                $n
            );
            $ov13 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftysecondVar->longArithOverflowFlag);
            if (null === $ov13 || null === $fiftysecondVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **59 expected smul overflow metadata (fiftysecond)');
            }
            $fiftysecondLong = JITVariable::KIND_VARIABLE === $fiftysecondVar->kind
                ? $context->builder->load($fiftysecondVar->value)
                : $fiftysecondVar->value;
            $ov13Block = BasicBlockHelper::append($context, 'pow_fiftyninth_fiftysecond_ov');
            $ok13Block = BasicBlockHelper::append($context, 'pow_fiftyninth_fiftysecond_ok');
            $context->builder->branchIf($ov13, $ov13Block, $ok13Block);

            $context->builder->positionAtEnd($ov13Block);
            $fiftysecondF13 = $context->builder->load($fiftysecondVar->longArithOverflowDoubleSlot);
            $nF13 = $context->builder->siToFp($n, $f64);
            $fiftyninthF13 = $context->builder->fmul($fiftysecondF13, $nF13);
            $fiftyninthF13 = $context->builder->fmul($fiftyninthF13, $nF13);
            $fiftyninthF13 = $context->builder->fmul($fiftyninthF13, $nF13);
            $fiftyninthF13 = $context->builder->fmul($fiftyninthF13, $nF13);
            $fiftyninthF13 = $context->builder->fmul($fiftyninthF13, $nF13);
            $fiftyninthF13 = $context->builder->fmul($fiftyninthF13, $nF13);
            $fiftyninthF13 = $context->builder->fmul($fiftyninthF13, $nF13);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyninthF13
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok13Block);
            $fiftythirdVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftysecondLong,
                $n
            );
            $ov14 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftythirdVar->longArithOverflowFlag);
            if (null === $ov14 || null === $fiftythirdVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **59 expected smul overflow metadata (fiftythird)');
            }
            $fiftythirdLong = JITVariable::KIND_VARIABLE === $fiftythirdVar->kind
                ? $context->builder->load($fiftythirdVar->value)
                : $fiftythirdVar->value;
            $ov14Block = BasicBlockHelper::append($context, 'pow_fiftyninth_fiftythird_ov');
            $ok14Block = BasicBlockHelper::append($context, 'pow_fiftyninth_fiftythird_ok');
            $context->builder->branchIf($ov14, $ov14Block, $ok14Block);

            $context->builder->positionAtEnd($ov14Block);
            $fiftythirdF14 = $context->builder->load($fiftythirdVar->longArithOverflowDoubleSlot);
            $nF14 = $context->builder->siToFp($n, $f64);
            $fiftyninthF14 = $context->builder->fmul($fiftythirdF14, $nF14);
            $fiftyninthF14 = $context->builder->fmul($fiftyninthF14, $nF14);
            $fiftyninthF14 = $context->builder->fmul($fiftyninthF14, $nF14);
            $fiftyninthF14 = $context->builder->fmul($fiftyninthF14, $nF14);
            $fiftyninthF14 = $context->builder->fmul($fiftyninthF14, $nF14);
            $fiftyninthF14 = $context->builder->fmul($fiftyninthF14, $nF14);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyninthF14
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok14Block);
            $fiftyfourthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftythirdLong,
                $n
            );
            $ov15 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyfourthVar->longArithOverflowFlag);
            if (null === $ov15 || null === $fiftyfourthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **59 expected smul overflow metadata (fiftyfourth)');
            }
            $fiftyfourthLong = JITVariable::KIND_VARIABLE === $fiftyfourthVar->kind
                ? $context->builder->load($fiftyfourthVar->value)
                : $fiftyfourthVar->value;
            $ov15Block = BasicBlockHelper::append($context, 'pow_fiftyninth_fiftyfourth_ov');
            $ok15Block = BasicBlockHelper::append($context, 'pow_fiftyninth_fiftyfourth_ok');
            $context->builder->branchIf($ov15, $ov15Block, $ok15Block);

            $context->builder->positionAtEnd($ov15Block);
            $fiftyfourthF15 = $context->builder->load($fiftyfourthVar->longArithOverflowDoubleSlot);
            $nF15 = $context->builder->siToFp($n, $f64);
            $fiftyninthF15 = $context->builder->fmul($fiftyfourthF15, $nF15);
            $fiftyninthF15 = $context->builder->fmul($fiftyninthF15, $nF15);
            $fiftyninthF15 = $context->builder->fmul($fiftyninthF15, $nF15);
            $fiftyninthF15 = $context->builder->fmul($fiftyninthF15, $nF15);
            $fiftyninthF15 = $context->builder->fmul($fiftyninthF15, $nF15);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyninthF15
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok15Block);
            $fiftyfifthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyfourthLong,
                $n
            );
            $ov16 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyfifthVar->longArithOverflowFlag);
            if (null === $ov16 || null === $fiftyfifthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **59 expected smul overflow metadata (fiftyfifth)');
            }
            $fiftyfifthLong = JITVariable::KIND_VARIABLE === $fiftyfifthVar->kind
                ? $context->builder->load($fiftyfifthVar->value)
                : $fiftyfifthVar->value;
            $ov16Block = BasicBlockHelper::append($context, 'pow_fiftyninth_fiftyfifth_ov');
            $ok16Block = BasicBlockHelper::append($context, 'pow_fiftyninth_fiftyfifth_ok');
            $context->builder->branchIf($ov16, $ov16Block, $ok16Block);

            $context->builder->positionAtEnd($ov16Block);
            $fiftyfifthF16 = $context->builder->load($fiftyfifthVar->longArithOverflowDoubleSlot);
            $nF16 = $context->builder->siToFp($n, $f64);
            $fiftyninthF16 = $context->builder->fmul($fiftyfifthF16, $nF16);
            $fiftyninthF16 = $context->builder->fmul($fiftyninthF16, $nF16);
            $fiftyninthF16 = $context->builder->fmul($fiftyninthF16, $nF16);
            $fiftyninthF16 = $context->builder->fmul($fiftyninthF16, $nF16);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyninthF16
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok16Block);
            $fiftysixthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyfifthLong,
                $n
            );
            $ov17 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftysixthVar->longArithOverflowFlag);
            if (null === $ov17 || null === $fiftysixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **59 expected smul overflow metadata (fiftysixth)');
            }
            $fiftysixthLong = JITVariable::KIND_VARIABLE === $fiftysixthVar->kind
                ? $context->builder->load($fiftysixthVar->value)
                : $fiftysixthVar->value;
            $ov17Block = BasicBlockHelper::append($context, 'pow_fiftyninth_fiftysixth_ov');
            $ok17Block = BasicBlockHelper::append($context, 'pow_fiftyninth_fiftysixth_ok');
            $context->builder->branchIf($ov17, $ov17Block, $ok17Block);

            $context->builder->positionAtEnd($ov17Block);
            $fiftysixthF17 = $context->builder->load($fiftysixthVar->longArithOverflowDoubleSlot);
            $nF17 = $context->builder->siToFp($n, $f64);
            $fiftyninthF17 = $context->builder->fmul($fiftysixthF17, $nF17);
            $fiftyninthF17 = $context->builder->fmul($fiftyninthF17, $nF17);
            $fiftyninthF17 = $context->builder->fmul($fiftyninthF17, $nF17);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyninthF17
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok17Block);
            $fiftyseventhVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftysixthLong,
                $n
            );
            $ov18 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyseventhVar->longArithOverflowFlag);
            if (null === $ov18 || null === $fiftyseventhVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **59 expected smul overflow metadata (fiftyseventh)');
            }
            $fiftyseventhLong = JITVariable::KIND_VARIABLE === $fiftyseventhVar->kind
                ? $context->builder->load($fiftyseventhVar->value)
                : $fiftyseventhVar->value;
            $ov18Block = BasicBlockHelper::append($context, 'pow_fiftyninth_fiftyseventh_ov');
            $ok18Block = BasicBlockHelper::append($context, 'pow_fiftyninth_fiftyseventh_ok');
            $context->builder->branchIf($ov18, $ov18Block, $ok18Block);

            $context->builder->positionAtEnd($ov18Block);
            $fiftyseventhF18 = $context->builder->load($fiftyseventhVar->longArithOverflowDoubleSlot);
            $nF18 = $context->builder->siToFp($n, $f64);
            $fiftyninthF18 = $context->builder->fmul($fiftyseventhF18, $nF18);
            $fiftyninthF18 = $context->builder->fmul($fiftyninthF18, $nF18);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyninthF18
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok18Block);
            $fiftyeighthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyseventhLong,
                $n
            );
            $ov19 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyeighthVar->longArithOverflowFlag);
            if (null === $ov19 || null === $fiftyeighthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **59 expected smul overflow metadata (fiftyeighth)');
            }
            $fiftyeighthLong = JITVariable::KIND_VARIABLE === $fiftyeighthVar->kind
                ? $context->builder->load($fiftyeighthVar->value)
                : $fiftyeighthVar->value;
            $ov19Block = BasicBlockHelper::append($context, 'pow_fiftyninth_fiftyeighth_ov');
            $ok19Block = BasicBlockHelper::append($context, 'pow_fiftyninth_fiftyeighth_ok');
            $context->builder->branchIf($ov19, $ov19Block, $ok19Block);

            $context->builder->positionAtEnd($ov19Block);
            $fiftyeighthF19 = $context->builder->load($fiftyeighthVar->longArithOverflowDoubleSlot);
            $nF19 = $context->builder->siToFp($n, $f64);
            $fiftyninthF19 = $context->builder->fmul($fiftyeighthF19, $nF19);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $fiftyninthF19
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok19Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $fiftyeighthLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return true;
        }
        if ('sixtieth' === $expFold) {
            // n^60 = fiftyninth*n = fortyeighth*sq*n*n*n*n*n*n*n*n*n*n: sq=n*n, cu=sq*n, fifth=cu*sq,
            // tenth=fifth*fifth, twentieth=tenth*tenth,
            // fortieth=twentieth*twentieth, fortysecond=fortieth*sq, fortyfourth=fortysecond*sq,
            // fortysixth=fortyfourth*sq, then fortysixth*sq*sq*n*n*n*n*n*n*n*n*n*n.
            // Overflow arms finish in float (sqF^25*nF*nF*nF*nF*nF*nF*nF*nF*nF*nF,
            // ((cuF*sqF)^2)^4*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF*nF, (fifthF^2)^4*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF, tenthF^4*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF,
            // twentiethF^2*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF*nF, fortiethF*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF, fortysecondF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF,
            // fortyfourthF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF*nF, fortysixthF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF).
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
                throw new \LogicException('pow() **60 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_sixtieth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_sixtieth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_sixtieth_done');
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
            $sixtiethFSq = $context->builder->fmul($fortyfourthF, $sqF);
            $sixtiethFEighth = $context->builder->fmul($sixtiethFSq, $sqF);
            $sixtiethF = $context->builder->fmul($sixtiethFEighth, $sqF);
            $nF = $context->builder->siToFp($n, $f64);
            $sixtiethF = $context->builder->fmul($sixtiethF, $nF);
            $sixtiethF = $context->builder->fmul($sixtiethF, $nF);
            $sixtiethF = $context->builder->fmul($sixtiethF, $nF);
            $sixtiethF = $context->builder->fmul($sixtiethF, $nF);
            $sixtiethF = $context->builder->fmul($sixtiethF, $nF);
            $sixtiethF = $context->builder->fmul($sixtiethF, $nF);
            $sixtiethF = $context->builder->fmul($sixtiethF, $nF);
            $sixtiethF = $context->builder->fmul($sixtiethF, $nF);
            $sixtiethF = $context->builder->fmul($sixtiethF, $nF);
            $sixtiethF = $context->builder->fmul($sixtiethF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtiethF
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
                throw new \LogicException('pow() **60 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_sixtieth_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_sixtieth_cu_ok');
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
            $sixtiethF2Sq = $context->builder->fmul($fortyfourthF2, $sqF2);
            $sixtiethF2Eighth = $context->builder->fmul($sixtiethF2Sq, $sqF2);
            $sixtiethF2 = $context->builder->fmul($sixtiethF2Eighth, $sqF2);
            $nF2 = $context->builder->siToFp($n, $f64);
            $sixtiethF2 = $context->builder->fmul($sixtiethF2, $nF2);
            $sixtiethF2 = $context->builder->fmul($sixtiethF2, $nF2);
            $sixtiethF2 = $context->builder->fmul($sixtiethF2, $nF2);
            $sixtiethF2 = $context->builder->fmul($sixtiethF2, $nF2);
            $sixtiethF2 = $context->builder->fmul($sixtiethF2, $nF2);
            $sixtiethF2 = $context->builder->fmul($sixtiethF2, $nF2);
            $sixtiethF2 = $context->builder->fmul($sixtiethF2, $nF2);
            $sixtiethF2 = $context->builder->fmul($sixtiethF2, $nF2);
            $sixtiethF2 = $context->builder->fmul($sixtiethF2, $nF2);
            $sixtiethF2 = $context->builder->fmul($sixtiethF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtiethF2
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
                throw new \LogicException('pow() **60 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_sixtieth_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_sixtieth_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $twentiethF3 = $context->builder->fmul($tenthF3, $tenthF3);
            $fortiethF3 = $context->builder->fmul($twentiethF3, $twentiethF3);
            $sqF3 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF3 = $context->builder->fmul($fortiethF3, $sqF3);
            $fortyfourthF3 = $context->builder->fmul($fortysecondF3, $sqF3);
            $sixtiethF3Sq = $context->builder->fmul($fortyfourthF3, $sqF3);
            $sixtiethF3Eighth = $context->builder->fmul($sixtiethF3Sq, $sqF3);
            $sixtiethF3 = $context->builder->fmul($sixtiethF3Eighth, $sqF3);
            $nF3 = $context->builder->siToFp($n, $f64);
            $sixtiethF3 = $context->builder->fmul($sixtiethF3, $nF3);
            $sixtiethF3 = $context->builder->fmul($sixtiethF3, $nF3);
            $sixtiethF3 = $context->builder->fmul($sixtiethF3, $nF3);
            $sixtiethF3 = $context->builder->fmul($sixtiethF3, $nF3);
            $sixtiethF3 = $context->builder->fmul($sixtiethF3, $nF3);
            $sixtiethF3 = $context->builder->fmul($sixtiethF3, $nF3);
            $sixtiethF3 = $context->builder->fmul($sixtiethF3, $nF3);
            $sixtiethF3 = $context->builder->fmul($sixtiethF3, $nF3);
            $sixtiethF3 = $context->builder->fmul($sixtiethF3, $nF3);
            $sixtiethF3 = $context->builder->fmul($sixtiethF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtiethF3
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
                throw new \LogicException('pow() **60 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_sixtieth_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_sixtieth_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF4 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $twentiethF4 = $context->builder->fmul($tenthF4, $tenthF4);
            $fortiethF4 = $context->builder->fmul($twentiethF4, $twentiethF4);
            $sqF4 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF4 = $context->builder->fmul($fortiethF4, $sqF4);
            $fortyfourthF4 = $context->builder->fmul($fortysecondF4, $sqF4);
            $sixtiethF4Sq = $context->builder->fmul($fortyfourthF4, $sqF4);
            $sixtiethF4Eighth = $context->builder->fmul($sixtiethF4Sq, $sqF4);
            $sixtiethF4 = $context->builder->fmul($sixtiethF4Eighth, $sqF4);
            $nF4 = $context->builder->siToFp($n, $f64);
            $sixtiethF4 = $context->builder->fmul($sixtiethF4, $nF4);
            $sixtiethF4 = $context->builder->fmul($sixtiethF4, $nF4);
            $sixtiethF4 = $context->builder->fmul($sixtiethF4, $nF4);
            $sixtiethF4 = $context->builder->fmul($sixtiethF4, $nF4);
            $sixtiethF4 = $context->builder->fmul($sixtiethF4, $nF4);
            $sixtiethF4 = $context->builder->fmul($sixtiethF4, $nF4);
            $sixtiethF4 = $context->builder->fmul($sixtiethF4, $nF4);
            $sixtiethF4 = $context->builder->fmul($sixtiethF4, $nF4);
            $sixtiethF4 = $context->builder->fmul($sixtiethF4, $nF4);
            $sixtiethF4 = $context->builder->fmul($sixtiethF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtiethF4
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
                throw new \LogicException('pow() **60 expected smul overflow metadata (twentieth)');
            }
            $twentiethLong = JITVariable::KIND_VARIABLE === $twentiethVar->kind
                ? $context->builder->load($twentiethVar->value)
                : $twentiethVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_sixtieth_twentieth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_sixtieth_twentieth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $twentiethF5 = $context->builder->load($twentiethVar->longArithOverflowDoubleSlot);
            $fortiethF5 = $context->builder->fmul($twentiethF5, $twentiethF5);
            $sqF5 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF5 = $context->builder->fmul($fortiethF5, $sqF5);
            $fortyfourthF5 = $context->builder->fmul($fortysecondF5, $sqF5);
            $sixtiethF5Sq = $context->builder->fmul($fortyfourthF5, $sqF5);
            $sixtiethF5Eighth = $context->builder->fmul($sixtiethF5Sq, $sqF5);
            $sixtiethF5 = $context->builder->fmul($sixtiethF5Eighth, $sqF5);
            $nF5 = $context->builder->siToFp($n, $f64);
            $sixtiethF5 = $context->builder->fmul($sixtiethF5, $nF5);
            $sixtiethF5 = $context->builder->fmul($sixtiethF5, $nF5);
            $sixtiethF5 = $context->builder->fmul($sixtiethF5, $nF5);
            $sixtiethF5 = $context->builder->fmul($sixtiethF5, $nF5);
            $sixtiethF5 = $context->builder->fmul($sixtiethF5, $nF5);
            $sixtiethF5 = $context->builder->fmul($sixtiethF5, $nF5);
            $sixtiethF5 = $context->builder->fmul($sixtiethF5, $nF5);
            $sixtiethF5 = $context->builder->fmul($sixtiethF5, $nF5);
            $sixtiethF5 = $context->builder->fmul($sixtiethF5, $nF5);
            $sixtiethF5 = $context->builder->fmul($sixtiethF5, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtiethF5
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
                throw new \LogicException('pow() **60 expected smul overflow metadata (fortieth)');
            }
            $fortiethLong = JITVariable::KIND_VARIABLE === $fortiethVar->kind
                ? $context->builder->load($fortiethVar->value)
                : $fortiethVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_sixtieth_fortieth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_sixtieth_fortieth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $fortiethF6 = $context->builder->load($fortiethVar->longArithOverflowDoubleSlot);
            $sqF6 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF6 = $context->builder->fmul($fortiethF6, $sqF6);
            $fortyfourthF6 = $context->builder->fmul($fortysecondF6, $sqF6);
            $sixtiethF6Sq = $context->builder->fmul($fortyfourthF6, $sqF6);
            $sixtiethF6Eighth = $context->builder->fmul($sixtiethF6Sq, $sqF6);
            $sixtiethF6 = $context->builder->fmul($sixtiethF6Eighth, $sqF6);
            $nF6 = $context->builder->siToFp($n, $f64);
            $sixtiethF6 = $context->builder->fmul($sixtiethF6, $nF6);
            $sixtiethF6 = $context->builder->fmul($sixtiethF6, $nF6);
            $sixtiethF6 = $context->builder->fmul($sixtiethF6, $nF6);
            $sixtiethF6 = $context->builder->fmul($sixtiethF6, $nF6);
            $sixtiethF6 = $context->builder->fmul($sixtiethF6, $nF6);
            $sixtiethF6 = $context->builder->fmul($sixtiethF6, $nF6);
            $sixtiethF6 = $context->builder->fmul($sixtiethF6, $nF6);
            $sixtiethF6 = $context->builder->fmul($sixtiethF6, $nF6);
            $sixtiethF6 = $context->builder->fmul($sixtiethF6, $nF6);
            $sixtiethF6 = $context->builder->fmul($sixtiethF6, $nF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtiethF6
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
                throw new \LogicException('pow() **60 expected smul overflow metadata (fortysecond)');
            }
            $fortysecondLong = JITVariable::KIND_VARIABLE === $fortysecondVar->kind
                ? $context->builder->load($fortysecondVar->value)
                : $fortysecondVar->value;
            $ov7Block = BasicBlockHelper::append($context, 'pow_sixtieth_fortysecond_ov');
            $ok7Block = BasicBlockHelper::append($context, 'pow_sixtieth_fortysecond_ok');
            $context->builder->branchIf($ov7, $ov7Block, $ok7Block);

            $context->builder->positionAtEnd($ov7Block);
            $fortysecondF7 = $context->builder->load($fortysecondVar->longArithOverflowDoubleSlot);
            $sqF7 = $context->builder->siToFp($sqLong, $f64);
            $fortyfourthF7 = $context->builder->fmul($fortysecondF7, $sqF7);
            $sixtiethF7Sq = $context->builder->fmul($fortyfourthF7, $sqF7);
            $sixtiethF7Eighth = $context->builder->fmul($sixtiethF7Sq, $sqF7);
            $sixtiethF7 = $context->builder->fmul($sixtiethF7Eighth, $sqF7);
            $nF7 = $context->builder->siToFp($n, $f64);
            $sixtiethF7 = $context->builder->fmul($sixtiethF7, $nF7);
            $sixtiethF7 = $context->builder->fmul($sixtiethF7, $nF7);
            $sixtiethF7 = $context->builder->fmul($sixtiethF7, $nF7);
            $sixtiethF7 = $context->builder->fmul($sixtiethF7, $nF7);
            $sixtiethF7 = $context->builder->fmul($sixtiethF7, $nF7);
            $sixtiethF7 = $context->builder->fmul($sixtiethF7, $nF7);
            $sixtiethF7 = $context->builder->fmul($sixtiethF7, $nF7);
            $sixtiethF7 = $context->builder->fmul($sixtiethF7, $nF7);
            $sixtiethF7 = $context->builder->fmul($sixtiethF7, $nF7);
            $sixtiethF7 = $context->builder->fmul($sixtiethF7, $nF7);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtiethF7
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
                throw new \LogicException('pow() **60 expected smul overflow metadata (fortyfourth)');
            }
            $fortyfourthLong = JITVariable::KIND_VARIABLE === $fortyfourthVar->kind
                ? $context->builder->load($fortyfourthVar->value)
                : $fortyfourthVar->value;
            $ov8Block = BasicBlockHelper::append($context, 'pow_sixtieth_fortyfourth_ov');
            $ok8Block = BasicBlockHelper::append($context, 'pow_sixtieth_fortyfourth_ok');
            $context->builder->branchIf($ov8, $ov8Block, $ok8Block);

            $context->builder->positionAtEnd($ov8Block);
            $fortyfourthF8 = $context->builder->load($fortyfourthVar->longArithOverflowDoubleSlot);
            $sqF8 = $context->builder->siToFp($sqLong, $f64);
            $sixtiethF8Sq = $context->builder->fmul($fortyfourthF8, $sqF8);
            $sixtiethF8Eighth = $context->builder->fmul($sixtiethF8Sq, $sqF8);
            $sixtiethF8 = $context->builder->fmul($sixtiethF8Eighth, $sqF8);
            $nF8 = $context->builder->siToFp($n, $f64);
            $sixtiethF8 = $context->builder->fmul($sixtiethF8, $nF8);
            $sixtiethF8 = $context->builder->fmul($sixtiethF8, $nF8);
            $sixtiethF8 = $context->builder->fmul($sixtiethF8, $nF8);
            $sixtiethF8 = $context->builder->fmul($sixtiethF8, $nF8);
            $sixtiethF8 = $context->builder->fmul($sixtiethF8, $nF8);
            $sixtiethF8 = $context->builder->fmul($sixtiethF8, $nF8);
            $sixtiethF8 = $context->builder->fmul($sixtiethF8, $nF8);
            $sixtiethF8 = $context->builder->fmul($sixtiethF8, $nF8);
            $sixtiethF8 = $context->builder->fmul($sixtiethF8, $nF8);
            $sixtiethF8 = $context->builder->fmul($sixtiethF8, $nF8);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtiethF8
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
                throw new \LogicException('pow() **60 expected smul overflow metadata (fortysixth)');
            }
            $fortysixthLong = JITVariable::KIND_VARIABLE === $fortysixthVar->kind
                ? $context->builder->load($fortysixthVar->value)
                : $fortysixthVar->value;
            $ov9Block = BasicBlockHelper::append($context, 'pow_sixtieth_fortysixth_ov');
            $ok9Block = BasicBlockHelper::append($context, 'pow_sixtieth_fortysixth_ok');
            $context->builder->branchIf($ov9, $ov9Block, $ok9Block);

            $context->builder->positionAtEnd($ov9Block);
            $fortysixthF9 = $context->builder->load($fortysixthVar->longArithOverflowDoubleSlot);
            $sqF9 = $context->builder->siToFp($sqLong, $f64);
            $fortyeighthF9 = $context->builder->fmul($fortysixthF9, $sqF9);
            $sixtiethF9 = $context->builder->fmul($fortyeighthF9, $sqF9);
            $nF9 = $context->builder->siToFp($n, $f64);
            $sixtiethF9 = $context->builder->fmul($sixtiethF9, $nF9);
            $sixtiethF9 = $context->builder->fmul($sixtiethF9, $nF9);
            $sixtiethF9 = $context->builder->fmul($sixtiethF9, $nF9);
            $sixtiethF9 = $context->builder->fmul($sixtiethF9, $nF9);
            $sixtiethF9 = $context->builder->fmul($sixtiethF9, $nF9);
            $sixtiethF9 = $context->builder->fmul($sixtiethF9, $nF9);
            $sixtiethF9 = $context->builder->fmul($sixtiethF9, $nF9);
            $sixtiethF9 = $context->builder->fmul($sixtiethF9, $nF9);
            $sixtiethF9 = $context->builder->fmul($sixtiethF9, $nF9);
            $sixtiethF9 = $context->builder->fmul($sixtiethF9, $nF9);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtiethF9
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
                throw new \LogicException('pow() **60 expected smul overflow metadata (fortyeighth)');
            }
            $fortyeighthLong = JITVariable::KIND_VARIABLE === $fortyeighthVar->kind
                ? $context->builder->load($fortyeighthVar->value)
                : $fortyeighthVar->value;
            $ov10Block = BasicBlockHelper::append($context, 'pow_sixtieth_fortyeighth_ov');
            $ok10Block = BasicBlockHelper::append($context, 'pow_sixtieth_fortyeighth_ok');
            $context->builder->branchIf($ov10, $ov10Block, $ok10Block);

            $context->builder->positionAtEnd($ov10Block);
            $fortyeighthF10 = $context->builder->load($fortyeighthVar->longArithOverflowDoubleSlot);
            $sqF10 = $context->builder->siToFp($sqLong, $f64);
            $sixtiethF10 = $context->builder->fmul($fortyeighthF10, $sqF10);
            $nF10 = $context->builder->siToFp($n, $f64);
            $sixtiethF10 = $context->builder->fmul($sixtiethF10, $nF10);
            $sixtiethF10 = $context->builder->fmul($sixtiethF10, $nF10);
            $sixtiethF10 = $context->builder->fmul($sixtiethF10, $nF10);
            $sixtiethF10 = $context->builder->fmul($sixtiethF10, $nF10);
            $sixtiethF10 = $context->builder->fmul($sixtiethF10, $nF10);
            $sixtiethF10 = $context->builder->fmul($sixtiethF10, $nF10);
            $sixtiethF10 = $context->builder->fmul($sixtiethF10, $nF10);
            $sixtiethF10 = $context->builder->fmul($sixtiethF10, $nF10);
            $sixtiethF10 = $context->builder->fmul($sixtiethF10, $nF10);
            $sixtiethF10 = $context->builder->fmul($sixtiethF10, $nF10);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtiethF10
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok10Block);
            $fiftiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fortyeighthLong,
                $sqLong
            );
            $ov11 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftiethVar->longArithOverflowFlag);
            if (null === $ov11 || null === $fiftiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **60 expected smul overflow metadata (fiftieth)');
            }
            $fiftiethLong = JITVariable::KIND_VARIABLE === $fiftiethVar->kind
                ? $context->builder->load($fiftiethVar->value)
                : $fiftiethVar->value;
            $ov11Block = BasicBlockHelper::append($context, 'pow_sixtieth_fiftieth_ov');
            $ok11Block = BasicBlockHelper::append($context, 'pow_sixtieth_fiftieth_ok');
            $context->builder->branchIf($ov11, $ov11Block, $ok11Block);

            $context->builder->positionAtEnd($ov11Block);
            $fiftiethF11 = $context->builder->load($fiftiethVar->longArithOverflowDoubleSlot);
            $nF11 = $context->builder->siToFp($n, $f64);
            $sixtiethF11 = $context->builder->fmul($fiftiethF11, $nF11);
            $sixtiethF11 = $context->builder->fmul($sixtiethF11, $nF11);
            $sixtiethF11 = $context->builder->fmul($sixtiethF11, $nF11);
            $sixtiethF11 = $context->builder->fmul($sixtiethF11, $nF11);
            $sixtiethF11 = $context->builder->fmul($sixtiethF11, $nF11);
            $sixtiethF11 = $context->builder->fmul($sixtiethF11, $nF11);
            $sixtiethF11 = $context->builder->fmul($sixtiethF11, $nF11);
            $sixtiethF11 = $context->builder->fmul($sixtiethF11, $nF11);
            $sixtiethF11 = $context->builder->fmul($sixtiethF11, $nF11);
            $sixtiethF11 = $context->builder->fmul($sixtiethF11, $nF11);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtiethF11
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok11Block);
            $fiftyfirstVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftiethLong,
                $n
            );
            $ov12 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyfirstVar->longArithOverflowFlag);
            if (null === $ov12 || null === $fiftyfirstVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **60 expected smul overflow metadata (fiftyfirst)');
            }
            $fiftyfirstLong = JITVariable::KIND_VARIABLE === $fiftyfirstVar->kind
                ? $context->builder->load($fiftyfirstVar->value)
                : $fiftyfirstVar->value;
            $ov12Block = BasicBlockHelper::append($context, 'pow_sixtieth_fiftyfirst_ov');
            $ok12Block = BasicBlockHelper::append($context, 'pow_sixtieth_fiftyfirst_ok');
            $context->builder->branchIf($ov12, $ov12Block, $ok12Block);

            $context->builder->positionAtEnd($ov12Block);
            $fiftyfirstF12 = $context->builder->load($fiftyfirstVar->longArithOverflowDoubleSlot);
            $nF12 = $context->builder->siToFp($n, $f64);
            $sixtiethF12 = $context->builder->fmul($fiftyfirstF12, $nF12);
            $sixtiethF12 = $context->builder->fmul($sixtiethF12, $nF12);
            $sixtiethF12 = $context->builder->fmul($sixtiethF12, $nF12);
            $sixtiethF12 = $context->builder->fmul($sixtiethF12, $nF12);
            $sixtiethF12 = $context->builder->fmul($sixtiethF12, $nF12);
            $sixtiethF12 = $context->builder->fmul($sixtiethF12, $nF12);
            $sixtiethF12 = $context->builder->fmul($sixtiethF12, $nF12);
            $sixtiethF12 = $context->builder->fmul($sixtiethF12, $nF12);
            $sixtiethF12 = $context->builder->fmul($sixtiethF12, $nF12);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtiethF12
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok12Block);
            $fiftysecondVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyfirstLong,
                $n
            );
            $ov13 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftysecondVar->longArithOverflowFlag);
            if (null === $ov13 || null === $fiftysecondVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **60 expected smul overflow metadata (fiftysecond)');
            }
            $fiftysecondLong = JITVariable::KIND_VARIABLE === $fiftysecondVar->kind
                ? $context->builder->load($fiftysecondVar->value)
                : $fiftysecondVar->value;
            $ov13Block = BasicBlockHelper::append($context, 'pow_sixtieth_fiftysecond_ov');
            $ok13Block = BasicBlockHelper::append($context, 'pow_sixtieth_fiftysecond_ok');
            $context->builder->branchIf($ov13, $ov13Block, $ok13Block);

            $context->builder->positionAtEnd($ov13Block);
            $fiftysecondF13 = $context->builder->load($fiftysecondVar->longArithOverflowDoubleSlot);
            $nF13 = $context->builder->siToFp($n, $f64);
            $sixtiethF13 = $context->builder->fmul($fiftysecondF13, $nF13);
            $sixtiethF13 = $context->builder->fmul($sixtiethF13, $nF13);
            $sixtiethF13 = $context->builder->fmul($sixtiethF13, $nF13);
            $sixtiethF13 = $context->builder->fmul($sixtiethF13, $nF13);
            $sixtiethF13 = $context->builder->fmul($sixtiethF13, $nF13);
            $sixtiethF13 = $context->builder->fmul($sixtiethF13, $nF13);
            $sixtiethF13 = $context->builder->fmul($sixtiethF13, $nF13);
            $sixtiethF13 = $context->builder->fmul($sixtiethF13, $nF13);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtiethF13
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok13Block);
            $fiftythirdVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftysecondLong,
                $n
            );
            $ov14 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftythirdVar->longArithOverflowFlag);
            if (null === $ov14 || null === $fiftythirdVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **60 expected smul overflow metadata (fiftythird)');
            }
            $fiftythirdLong = JITVariable::KIND_VARIABLE === $fiftythirdVar->kind
                ? $context->builder->load($fiftythirdVar->value)
                : $fiftythirdVar->value;
            $ov14Block = BasicBlockHelper::append($context, 'pow_sixtieth_fiftythird_ov');
            $ok14Block = BasicBlockHelper::append($context, 'pow_sixtieth_fiftythird_ok');
            $context->builder->branchIf($ov14, $ov14Block, $ok14Block);

            $context->builder->positionAtEnd($ov14Block);
            $fiftythirdF14 = $context->builder->load($fiftythirdVar->longArithOverflowDoubleSlot);
            $nF14 = $context->builder->siToFp($n, $f64);
            $sixtiethF14 = $context->builder->fmul($fiftythirdF14, $nF14);
            $sixtiethF14 = $context->builder->fmul($sixtiethF14, $nF14);
            $sixtiethF14 = $context->builder->fmul($sixtiethF14, $nF14);
            $sixtiethF14 = $context->builder->fmul($sixtiethF14, $nF14);
            $sixtiethF14 = $context->builder->fmul($sixtiethF14, $nF14);
            $sixtiethF14 = $context->builder->fmul($sixtiethF14, $nF14);
            $sixtiethF14 = $context->builder->fmul($sixtiethF14, $nF14);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtiethF14
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok14Block);
            $fiftyfourthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftythirdLong,
                $n
            );
            $ov15 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyfourthVar->longArithOverflowFlag);
            if (null === $ov15 || null === $fiftyfourthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **60 expected smul overflow metadata (fiftyfourth)');
            }
            $fiftyfourthLong = JITVariable::KIND_VARIABLE === $fiftyfourthVar->kind
                ? $context->builder->load($fiftyfourthVar->value)
                : $fiftyfourthVar->value;
            $ov15Block = BasicBlockHelper::append($context, 'pow_sixtieth_fiftyfourth_ov');
            $ok15Block = BasicBlockHelper::append($context, 'pow_sixtieth_fiftyfourth_ok');
            $context->builder->branchIf($ov15, $ov15Block, $ok15Block);

            $context->builder->positionAtEnd($ov15Block);
            $fiftyfourthF15 = $context->builder->load($fiftyfourthVar->longArithOverflowDoubleSlot);
            $nF15 = $context->builder->siToFp($n, $f64);
            $sixtiethF15 = $context->builder->fmul($fiftyfourthF15, $nF15);
            $sixtiethF15 = $context->builder->fmul($sixtiethF15, $nF15);
            $sixtiethF15 = $context->builder->fmul($sixtiethF15, $nF15);
            $sixtiethF15 = $context->builder->fmul($sixtiethF15, $nF15);
            $sixtiethF15 = $context->builder->fmul($sixtiethF15, $nF15);
            $sixtiethF15 = $context->builder->fmul($sixtiethF15, $nF15);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtiethF15
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok15Block);
            $fiftyfifthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyfourthLong,
                $n
            );
            $ov16 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyfifthVar->longArithOverflowFlag);
            if (null === $ov16 || null === $fiftyfifthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **60 expected smul overflow metadata (fiftyfifth)');
            }
            $fiftyfifthLong = JITVariable::KIND_VARIABLE === $fiftyfifthVar->kind
                ? $context->builder->load($fiftyfifthVar->value)
                : $fiftyfifthVar->value;
            $ov16Block = BasicBlockHelper::append($context, 'pow_sixtieth_fiftyfifth_ov');
            $ok16Block = BasicBlockHelper::append($context, 'pow_sixtieth_fiftyfifth_ok');
            $context->builder->branchIf($ov16, $ov16Block, $ok16Block);

            $context->builder->positionAtEnd($ov16Block);
            $fiftyfifthF16 = $context->builder->load($fiftyfifthVar->longArithOverflowDoubleSlot);
            $nF16 = $context->builder->siToFp($n, $f64);
            $sixtiethF16 = $context->builder->fmul($fiftyfifthF16, $nF16);
            $sixtiethF16 = $context->builder->fmul($sixtiethF16, $nF16);
            $sixtiethF16 = $context->builder->fmul($sixtiethF16, $nF16);
            $sixtiethF16 = $context->builder->fmul($sixtiethF16, $nF16);
            $sixtiethF16 = $context->builder->fmul($sixtiethF16, $nF16);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtiethF16
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok16Block);
            $fiftysixthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyfifthLong,
                $n
            );
            $ov17 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftysixthVar->longArithOverflowFlag);
            if (null === $ov17 || null === $fiftysixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **60 expected smul overflow metadata (fiftysixth)');
            }
            $fiftysixthLong = JITVariable::KIND_VARIABLE === $fiftysixthVar->kind
                ? $context->builder->load($fiftysixthVar->value)
                : $fiftysixthVar->value;
            $ov17Block = BasicBlockHelper::append($context, 'pow_sixtieth_fiftysixth_ov');
            $ok17Block = BasicBlockHelper::append($context, 'pow_sixtieth_fiftysixth_ok');
            $context->builder->branchIf($ov17, $ov17Block, $ok17Block);

            $context->builder->positionAtEnd($ov17Block);
            $fiftysixthF17 = $context->builder->load($fiftysixthVar->longArithOverflowDoubleSlot);
            $nF17 = $context->builder->siToFp($n, $f64);
            $sixtiethF17 = $context->builder->fmul($fiftysixthF17, $nF17);
            $sixtiethF17 = $context->builder->fmul($sixtiethF17, $nF17);
            $sixtiethF17 = $context->builder->fmul($sixtiethF17, $nF17);
            $sixtiethF17 = $context->builder->fmul($sixtiethF17, $nF17);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtiethF17
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok17Block);
            $fiftyseventhVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftysixthLong,
                $n
            );
            $ov18 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyseventhVar->longArithOverflowFlag);
            if (null === $ov18 || null === $fiftyseventhVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **60 expected smul overflow metadata (fiftyseventh)');
            }
            $fiftyseventhLong = JITVariable::KIND_VARIABLE === $fiftyseventhVar->kind
                ? $context->builder->load($fiftyseventhVar->value)
                : $fiftyseventhVar->value;
            $ov18Block = BasicBlockHelper::append($context, 'pow_sixtieth_fiftyseventh_ov');
            $ok18Block = BasicBlockHelper::append($context, 'pow_sixtieth_fiftyseventh_ok');
            $context->builder->branchIf($ov18, $ov18Block, $ok18Block);

            $context->builder->positionAtEnd($ov18Block);
            $fiftyseventhF18 = $context->builder->load($fiftyseventhVar->longArithOverflowDoubleSlot);
            $nF18 = $context->builder->siToFp($n, $f64);
            $sixtiethF18 = $context->builder->fmul($fiftyseventhF18, $nF18);
            $sixtiethF18 = $context->builder->fmul($sixtiethF18, $nF18);
            $sixtiethF18 = $context->builder->fmul($sixtiethF18, $nF18);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtiethF18
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok18Block);
            $fiftyeighthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyseventhLong,
                $n
            );
            $ov19 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyeighthVar->longArithOverflowFlag);
            if (null === $ov19 || null === $fiftyeighthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **60 expected smul overflow metadata (fiftyeighth)');
            }
            $fiftyeighthLong = JITVariable::KIND_VARIABLE === $fiftyeighthVar->kind
                ? $context->builder->load($fiftyeighthVar->value)
                : $fiftyeighthVar->value;
            $ov19Block = BasicBlockHelper::append($context, 'pow_sixtieth_fiftyeighth_ov');
            $ok19Block = BasicBlockHelper::append($context, 'pow_sixtieth_fiftyeighth_ok');
            $context->builder->branchIf($ov19, $ov19Block, $ok19Block);

            $context->builder->positionAtEnd($ov19Block);
            $fiftyeighthF19 = $context->builder->load($fiftyeighthVar->longArithOverflowDoubleSlot);
            $nF19 = $context->builder->siToFp($n, $f64);
            $sixtiethF19 = $context->builder->fmul($fiftyeighthF19, $nF19);
            $sixtiethF19 = $context->builder->fmul($sixtiethF19, $nF19);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtiethF19
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok19Block);
            $fiftyninthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyeighthLong,
                $n
            );
            $ov20 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyninthVar->longArithOverflowFlag);
            if (null === $ov20 || null === $fiftyninthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **60 expected smul overflow metadata (fiftyninth)');
            }
            $fiftyninthLong = JITVariable::KIND_VARIABLE === $fiftyninthVar->kind
                ? $context->builder->load($fiftyninthVar->value)
                : $fiftyninthVar->value;
            $ov20Block = BasicBlockHelper::append($context, 'pow_sixtieth_fiftyninth_ov');
            $ok20Block = BasicBlockHelper::append($context, 'pow_sixtieth_fiftyninth_ok');
            $context->builder->branchIf($ov20, $ov20Block, $ok20Block);

            $context->builder->positionAtEnd($ov20Block);
            $fiftyninthF20 = $context->builder->load($fiftyninthVar->longArithOverflowDoubleSlot);
            $nF20 = $context->builder->siToFp($n, $f64);
            $sixtiethF20 = $context->builder->fmul($fiftyninthF20, $nF20);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtiethF20
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok20Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $fiftyninthLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return true;
        }

        if ('sixtyfirst' === $expFold) {
            // n^61 = sixtieth*n = fortyeighth*sq*n*n*n*n*n*n*n*n*n*n*n: sq=n*n, cu=sq*n, fifth=cu*sq,
            // tenth=fifth*fifth, twentieth=tenth*tenth,
            // fortieth=twentieth*twentieth, fortysecond=fortieth*sq, fortyfourth=fortysecond*sq,
            // fortysixth=fortyfourth*sq, then fortysixth*sq*sq*n*n*n*n*n*n*n*n*n*n*n.
            // Overflow arms finish in float (sqF^25*nF*nF*nF*nF*nF*nF*nF*nF*nF*nF,
            // ((cuF*sqF)^2)^4*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF*nF, (fifthF^2)^4*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF, tenthF^4*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF,
            // twentiethF^2*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF*nF, fortiethF*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF, fortysecondF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF,
            // fortyfourthF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF*nF, fortysixthF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF).
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
                throw new \LogicException('pow() **61 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_sixtyfirst_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_sixtyfirst_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_sixtyfirst_done');
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
            $sixtyfirstFSq = $context->builder->fmul($fortyfourthF, $sqF);
            $sixtyfirstFEighth = $context->builder->fmul($sixtyfirstFSq, $sqF);
            $sixtyfirstF = $context->builder->fmul($sixtyfirstFEighth, $sqF);
            $nF = $context->builder->siToFp($n, $f64);
            $sixtyfirstF = $context->builder->fmul($sixtyfirstF, $nF);
            $sixtyfirstF = $context->builder->fmul($sixtyfirstF, $nF);
            $sixtyfirstF = $context->builder->fmul($sixtyfirstF, $nF);
            $sixtyfirstF = $context->builder->fmul($sixtyfirstF, $nF);
            $sixtyfirstF = $context->builder->fmul($sixtyfirstF, $nF);
            $sixtyfirstF = $context->builder->fmul($sixtyfirstF, $nF);
            $sixtyfirstF = $context->builder->fmul($sixtyfirstF, $nF);
            $sixtyfirstF = $context->builder->fmul($sixtyfirstF, $nF);
            $sixtyfirstF = $context->builder->fmul($sixtyfirstF, $nF);
            $sixtyfirstF = $context->builder->fmul($sixtyfirstF, $nF);
                $sixtyfirstF = $context->builder->fmul($sixtyfirstF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfirstF
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
                throw new \LogicException('pow() **61 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_sixtyfirst_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_sixtyfirst_cu_ok');
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
            $sixtyfirstF2Sq = $context->builder->fmul($fortyfourthF2, $sqF2);
            $sixtyfirstF2Eighth = $context->builder->fmul($sixtyfirstF2Sq, $sqF2);
            $sixtyfirstF2 = $context->builder->fmul($sixtyfirstF2Eighth, $sqF2);
            $nF2 = $context->builder->siToFp($n, $f64);
            $sixtyfirstF2 = $context->builder->fmul($sixtyfirstF2, $nF2);
            $sixtyfirstF2 = $context->builder->fmul($sixtyfirstF2, $nF2);
            $sixtyfirstF2 = $context->builder->fmul($sixtyfirstF2, $nF2);
            $sixtyfirstF2 = $context->builder->fmul($sixtyfirstF2, $nF2);
            $sixtyfirstF2 = $context->builder->fmul($sixtyfirstF2, $nF2);
            $sixtyfirstF2 = $context->builder->fmul($sixtyfirstF2, $nF2);
            $sixtyfirstF2 = $context->builder->fmul($sixtyfirstF2, $nF2);
            $sixtyfirstF2 = $context->builder->fmul($sixtyfirstF2, $nF2);
            $sixtyfirstF2 = $context->builder->fmul($sixtyfirstF2, $nF2);
            $sixtyfirstF2 = $context->builder->fmul($sixtyfirstF2, $nF2);
                $sixtyfirstF2 = $context->builder->fmul($sixtyfirstF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfirstF2
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
                throw new \LogicException('pow() **61 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_sixtyfirst_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_sixtyfirst_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $twentiethF3 = $context->builder->fmul($tenthF3, $tenthF3);
            $fortiethF3 = $context->builder->fmul($twentiethF3, $twentiethF3);
            $sqF3 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF3 = $context->builder->fmul($fortiethF3, $sqF3);
            $fortyfourthF3 = $context->builder->fmul($fortysecondF3, $sqF3);
            $sixtyfirstF3Sq = $context->builder->fmul($fortyfourthF3, $sqF3);
            $sixtyfirstF3Eighth = $context->builder->fmul($sixtyfirstF3Sq, $sqF3);
            $sixtyfirstF3 = $context->builder->fmul($sixtyfirstF3Eighth, $sqF3);
            $nF3 = $context->builder->siToFp($n, $f64);
            $sixtyfirstF3 = $context->builder->fmul($sixtyfirstF3, $nF3);
            $sixtyfirstF3 = $context->builder->fmul($sixtyfirstF3, $nF3);
            $sixtyfirstF3 = $context->builder->fmul($sixtyfirstF3, $nF3);
            $sixtyfirstF3 = $context->builder->fmul($sixtyfirstF3, $nF3);
            $sixtyfirstF3 = $context->builder->fmul($sixtyfirstF3, $nF3);
            $sixtyfirstF3 = $context->builder->fmul($sixtyfirstF3, $nF3);
            $sixtyfirstF3 = $context->builder->fmul($sixtyfirstF3, $nF3);
            $sixtyfirstF3 = $context->builder->fmul($sixtyfirstF3, $nF3);
            $sixtyfirstF3 = $context->builder->fmul($sixtyfirstF3, $nF3);
            $sixtyfirstF3 = $context->builder->fmul($sixtyfirstF3, $nF3);
                $sixtyfirstF3 = $context->builder->fmul($sixtyfirstF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfirstF3
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
                throw new \LogicException('pow() **61 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_sixtyfirst_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_sixtyfirst_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF4 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $twentiethF4 = $context->builder->fmul($tenthF4, $tenthF4);
            $fortiethF4 = $context->builder->fmul($twentiethF4, $twentiethF4);
            $sqF4 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF4 = $context->builder->fmul($fortiethF4, $sqF4);
            $fortyfourthF4 = $context->builder->fmul($fortysecondF4, $sqF4);
            $sixtyfirstF4Sq = $context->builder->fmul($fortyfourthF4, $sqF4);
            $sixtyfirstF4Eighth = $context->builder->fmul($sixtyfirstF4Sq, $sqF4);
            $sixtyfirstF4 = $context->builder->fmul($sixtyfirstF4Eighth, $sqF4);
            $nF4 = $context->builder->siToFp($n, $f64);
            $sixtyfirstF4 = $context->builder->fmul($sixtyfirstF4, $nF4);
            $sixtyfirstF4 = $context->builder->fmul($sixtyfirstF4, $nF4);
            $sixtyfirstF4 = $context->builder->fmul($sixtyfirstF4, $nF4);
            $sixtyfirstF4 = $context->builder->fmul($sixtyfirstF4, $nF4);
            $sixtyfirstF4 = $context->builder->fmul($sixtyfirstF4, $nF4);
            $sixtyfirstF4 = $context->builder->fmul($sixtyfirstF4, $nF4);
            $sixtyfirstF4 = $context->builder->fmul($sixtyfirstF4, $nF4);
            $sixtyfirstF4 = $context->builder->fmul($sixtyfirstF4, $nF4);
            $sixtyfirstF4 = $context->builder->fmul($sixtyfirstF4, $nF4);
            $sixtyfirstF4 = $context->builder->fmul($sixtyfirstF4, $nF4);
                $sixtyfirstF4 = $context->builder->fmul($sixtyfirstF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfirstF4
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
                throw new \LogicException('pow() **61 expected smul overflow metadata (twentieth)');
            }
            $twentiethLong = JITVariable::KIND_VARIABLE === $twentiethVar->kind
                ? $context->builder->load($twentiethVar->value)
                : $twentiethVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_sixtyfirst_twentieth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_sixtyfirst_twentieth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $twentiethF5 = $context->builder->load($twentiethVar->longArithOverflowDoubleSlot);
            $fortiethF5 = $context->builder->fmul($twentiethF5, $twentiethF5);
            $sqF5 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF5 = $context->builder->fmul($fortiethF5, $sqF5);
            $fortyfourthF5 = $context->builder->fmul($fortysecondF5, $sqF5);
            $sixtyfirstF5Sq = $context->builder->fmul($fortyfourthF5, $sqF5);
            $sixtyfirstF5Eighth = $context->builder->fmul($sixtyfirstF5Sq, $sqF5);
            $sixtyfirstF5 = $context->builder->fmul($sixtyfirstF5Eighth, $sqF5);
            $nF5 = $context->builder->siToFp($n, $f64);
            $sixtyfirstF5 = $context->builder->fmul($sixtyfirstF5, $nF5);
            $sixtyfirstF5 = $context->builder->fmul($sixtyfirstF5, $nF5);
            $sixtyfirstF5 = $context->builder->fmul($sixtyfirstF5, $nF5);
            $sixtyfirstF5 = $context->builder->fmul($sixtyfirstF5, $nF5);
            $sixtyfirstF5 = $context->builder->fmul($sixtyfirstF5, $nF5);
            $sixtyfirstF5 = $context->builder->fmul($sixtyfirstF5, $nF5);
            $sixtyfirstF5 = $context->builder->fmul($sixtyfirstF5, $nF5);
            $sixtyfirstF5 = $context->builder->fmul($sixtyfirstF5, $nF5);
            $sixtyfirstF5 = $context->builder->fmul($sixtyfirstF5, $nF5);
            $sixtyfirstF5 = $context->builder->fmul($sixtyfirstF5, $nF5);
                $sixtyfirstF5 = $context->builder->fmul($sixtyfirstF5, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfirstF5
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
                throw new \LogicException('pow() **61 expected smul overflow metadata (fortieth)');
            }
            $fortiethLong = JITVariable::KIND_VARIABLE === $fortiethVar->kind
                ? $context->builder->load($fortiethVar->value)
                : $fortiethVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_sixtyfirst_fortieth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_sixtyfirst_fortieth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $fortiethF6 = $context->builder->load($fortiethVar->longArithOverflowDoubleSlot);
            $sqF6 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF6 = $context->builder->fmul($fortiethF6, $sqF6);
            $fortyfourthF6 = $context->builder->fmul($fortysecondF6, $sqF6);
            $sixtyfirstF6Sq = $context->builder->fmul($fortyfourthF6, $sqF6);
            $sixtyfirstF6Eighth = $context->builder->fmul($sixtyfirstF6Sq, $sqF6);
            $sixtyfirstF6 = $context->builder->fmul($sixtyfirstF6Eighth, $sqF6);
            $nF6 = $context->builder->siToFp($n, $f64);
            $sixtyfirstF6 = $context->builder->fmul($sixtyfirstF6, $nF6);
            $sixtyfirstF6 = $context->builder->fmul($sixtyfirstF6, $nF6);
            $sixtyfirstF6 = $context->builder->fmul($sixtyfirstF6, $nF6);
            $sixtyfirstF6 = $context->builder->fmul($sixtyfirstF6, $nF6);
            $sixtyfirstF6 = $context->builder->fmul($sixtyfirstF6, $nF6);
            $sixtyfirstF6 = $context->builder->fmul($sixtyfirstF6, $nF6);
            $sixtyfirstF6 = $context->builder->fmul($sixtyfirstF6, $nF6);
            $sixtyfirstF6 = $context->builder->fmul($sixtyfirstF6, $nF6);
            $sixtyfirstF6 = $context->builder->fmul($sixtyfirstF6, $nF6);
            $sixtyfirstF6 = $context->builder->fmul($sixtyfirstF6, $nF6);
                $sixtyfirstF6 = $context->builder->fmul($sixtyfirstF6, $nF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfirstF6
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
                throw new \LogicException('pow() **61 expected smul overflow metadata (fortysecond)');
            }
            $fortysecondLong = JITVariable::KIND_VARIABLE === $fortysecondVar->kind
                ? $context->builder->load($fortysecondVar->value)
                : $fortysecondVar->value;
            $ov7Block = BasicBlockHelper::append($context, 'pow_sixtyfirst_fortysecond_ov');
            $ok7Block = BasicBlockHelper::append($context, 'pow_sixtyfirst_fortysecond_ok');
            $context->builder->branchIf($ov7, $ov7Block, $ok7Block);

            $context->builder->positionAtEnd($ov7Block);
            $fortysecondF7 = $context->builder->load($fortysecondVar->longArithOverflowDoubleSlot);
            $sqF7 = $context->builder->siToFp($sqLong, $f64);
            $fortyfourthF7 = $context->builder->fmul($fortysecondF7, $sqF7);
            $sixtyfirstF7Sq = $context->builder->fmul($fortyfourthF7, $sqF7);
            $sixtyfirstF7Eighth = $context->builder->fmul($sixtyfirstF7Sq, $sqF7);
            $sixtyfirstF7 = $context->builder->fmul($sixtyfirstF7Eighth, $sqF7);
            $nF7 = $context->builder->siToFp($n, $f64);
            $sixtyfirstF7 = $context->builder->fmul($sixtyfirstF7, $nF7);
            $sixtyfirstF7 = $context->builder->fmul($sixtyfirstF7, $nF7);
            $sixtyfirstF7 = $context->builder->fmul($sixtyfirstF7, $nF7);
            $sixtyfirstF7 = $context->builder->fmul($sixtyfirstF7, $nF7);
            $sixtyfirstF7 = $context->builder->fmul($sixtyfirstF7, $nF7);
            $sixtyfirstF7 = $context->builder->fmul($sixtyfirstF7, $nF7);
            $sixtyfirstF7 = $context->builder->fmul($sixtyfirstF7, $nF7);
            $sixtyfirstF7 = $context->builder->fmul($sixtyfirstF7, $nF7);
            $sixtyfirstF7 = $context->builder->fmul($sixtyfirstF7, $nF7);
            $sixtyfirstF7 = $context->builder->fmul($sixtyfirstF7, $nF7);
                $sixtyfirstF7 = $context->builder->fmul($sixtyfirstF7, $nF7);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfirstF7
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
                throw new \LogicException('pow() **61 expected smul overflow metadata (fortyfourth)');
            }
            $fortyfourthLong = JITVariable::KIND_VARIABLE === $fortyfourthVar->kind
                ? $context->builder->load($fortyfourthVar->value)
                : $fortyfourthVar->value;
            $ov8Block = BasicBlockHelper::append($context, 'pow_sixtyfirst_fortyfourth_ov');
            $ok8Block = BasicBlockHelper::append($context, 'pow_sixtyfirst_fortyfourth_ok');
            $context->builder->branchIf($ov8, $ov8Block, $ok8Block);

            $context->builder->positionAtEnd($ov8Block);
            $fortyfourthF8 = $context->builder->load($fortyfourthVar->longArithOverflowDoubleSlot);
            $sqF8 = $context->builder->siToFp($sqLong, $f64);
            $sixtyfirstF8Sq = $context->builder->fmul($fortyfourthF8, $sqF8);
            $sixtyfirstF8Eighth = $context->builder->fmul($sixtyfirstF8Sq, $sqF8);
            $sixtyfirstF8 = $context->builder->fmul($sixtyfirstF8Eighth, $sqF8);
            $nF8 = $context->builder->siToFp($n, $f64);
            $sixtyfirstF8 = $context->builder->fmul($sixtyfirstF8, $nF8);
            $sixtyfirstF8 = $context->builder->fmul($sixtyfirstF8, $nF8);
            $sixtyfirstF8 = $context->builder->fmul($sixtyfirstF8, $nF8);
            $sixtyfirstF8 = $context->builder->fmul($sixtyfirstF8, $nF8);
            $sixtyfirstF8 = $context->builder->fmul($sixtyfirstF8, $nF8);
            $sixtyfirstF8 = $context->builder->fmul($sixtyfirstF8, $nF8);
            $sixtyfirstF8 = $context->builder->fmul($sixtyfirstF8, $nF8);
            $sixtyfirstF8 = $context->builder->fmul($sixtyfirstF8, $nF8);
            $sixtyfirstF8 = $context->builder->fmul($sixtyfirstF8, $nF8);
            $sixtyfirstF8 = $context->builder->fmul($sixtyfirstF8, $nF8);
                $sixtyfirstF8 = $context->builder->fmul($sixtyfirstF8, $nF8);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfirstF8
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
                throw new \LogicException('pow() **61 expected smul overflow metadata (fortysixth)');
            }
            $fortysixthLong = JITVariable::KIND_VARIABLE === $fortysixthVar->kind
                ? $context->builder->load($fortysixthVar->value)
                : $fortysixthVar->value;
            $ov9Block = BasicBlockHelper::append($context, 'pow_sixtyfirst_fortysixth_ov');
            $ok9Block = BasicBlockHelper::append($context, 'pow_sixtyfirst_fortysixth_ok');
            $context->builder->branchIf($ov9, $ov9Block, $ok9Block);

            $context->builder->positionAtEnd($ov9Block);
            $fortysixthF9 = $context->builder->load($fortysixthVar->longArithOverflowDoubleSlot);
            $sqF9 = $context->builder->siToFp($sqLong, $f64);
            $fortyeighthF9 = $context->builder->fmul($fortysixthF9, $sqF9);
            $sixtyfirstF9 = $context->builder->fmul($fortyeighthF9, $sqF9);
            $nF9 = $context->builder->siToFp($n, $f64);
            $sixtyfirstF9 = $context->builder->fmul($sixtyfirstF9, $nF9);
            $sixtyfirstF9 = $context->builder->fmul($sixtyfirstF9, $nF9);
            $sixtyfirstF9 = $context->builder->fmul($sixtyfirstF9, $nF9);
            $sixtyfirstF9 = $context->builder->fmul($sixtyfirstF9, $nF9);
            $sixtyfirstF9 = $context->builder->fmul($sixtyfirstF9, $nF9);
            $sixtyfirstF9 = $context->builder->fmul($sixtyfirstF9, $nF9);
            $sixtyfirstF9 = $context->builder->fmul($sixtyfirstF9, $nF9);
            $sixtyfirstF9 = $context->builder->fmul($sixtyfirstF9, $nF9);
            $sixtyfirstF9 = $context->builder->fmul($sixtyfirstF9, $nF9);
            $sixtyfirstF9 = $context->builder->fmul($sixtyfirstF9, $nF9);
                $sixtyfirstF9 = $context->builder->fmul($sixtyfirstF9, $nF9);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfirstF9
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
                throw new \LogicException('pow() **61 expected smul overflow metadata (fortyeighth)');
            }
            $fortyeighthLong = JITVariable::KIND_VARIABLE === $fortyeighthVar->kind
                ? $context->builder->load($fortyeighthVar->value)
                : $fortyeighthVar->value;
            $ov10Block = BasicBlockHelper::append($context, 'pow_sixtyfirst_fortyeighth_ov');
            $ok10Block = BasicBlockHelper::append($context, 'pow_sixtyfirst_fortyeighth_ok');
            $context->builder->branchIf($ov10, $ov10Block, $ok10Block);

            $context->builder->positionAtEnd($ov10Block);
            $fortyeighthF10 = $context->builder->load($fortyeighthVar->longArithOverflowDoubleSlot);
            $sqF10 = $context->builder->siToFp($sqLong, $f64);
            $sixtyfirstF10 = $context->builder->fmul($fortyeighthF10, $sqF10);
            $nF10 = $context->builder->siToFp($n, $f64);
            $sixtyfirstF10 = $context->builder->fmul($sixtyfirstF10, $nF10);
            $sixtyfirstF10 = $context->builder->fmul($sixtyfirstF10, $nF10);
            $sixtyfirstF10 = $context->builder->fmul($sixtyfirstF10, $nF10);
            $sixtyfirstF10 = $context->builder->fmul($sixtyfirstF10, $nF10);
            $sixtyfirstF10 = $context->builder->fmul($sixtyfirstF10, $nF10);
            $sixtyfirstF10 = $context->builder->fmul($sixtyfirstF10, $nF10);
            $sixtyfirstF10 = $context->builder->fmul($sixtyfirstF10, $nF10);
            $sixtyfirstF10 = $context->builder->fmul($sixtyfirstF10, $nF10);
            $sixtyfirstF10 = $context->builder->fmul($sixtyfirstF10, $nF10);
            $sixtyfirstF10 = $context->builder->fmul($sixtyfirstF10, $nF10);
                $sixtyfirstF10 = $context->builder->fmul($sixtyfirstF10, $nF10);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfirstF10
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok10Block);
            $fiftiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fortyeighthLong,
                $sqLong
            );
            $ov11 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftiethVar->longArithOverflowFlag);
            if (null === $ov11 || null === $fiftiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **61 expected smul overflow metadata (fiftieth)');
            }
            $fiftiethLong = JITVariable::KIND_VARIABLE === $fiftiethVar->kind
                ? $context->builder->load($fiftiethVar->value)
                : $fiftiethVar->value;
            $ov11Block = BasicBlockHelper::append($context, 'pow_sixtyfirst_fiftieth_ov');
            $ok11Block = BasicBlockHelper::append($context, 'pow_sixtyfirst_fiftieth_ok');
            $context->builder->branchIf($ov11, $ov11Block, $ok11Block);

            $context->builder->positionAtEnd($ov11Block);
            $fiftiethF11 = $context->builder->load($fiftiethVar->longArithOverflowDoubleSlot);
            $nF11 = $context->builder->siToFp($n, $f64);
            $sixtyfirstF11 = $context->builder->fmul($fiftiethF11, $nF11);
            $sixtyfirstF11 = $context->builder->fmul($sixtyfirstF11, $nF11);
            $sixtyfirstF11 = $context->builder->fmul($sixtyfirstF11, $nF11);
            $sixtyfirstF11 = $context->builder->fmul($sixtyfirstF11, $nF11);
            $sixtyfirstF11 = $context->builder->fmul($sixtyfirstF11, $nF11);
            $sixtyfirstF11 = $context->builder->fmul($sixtyfirstF11, $nF11);
            $sixtyfirstF11 = $context->builder->fmul($sixtyfirstF11, $nF11);
            $sixtyfirstF11 = $context->builder->fmul($sixtyfirstF11, $nF11);
            $sixtyfirstF11 = $context->builder->fmul($sixtyfirstF11, $nF11);
            $sixtyfirstF11 = $context->builder->fmul($sixtyfirstF11, $nF11);
                $sixtyfirstF11 = $context->builder->fmul($sixtyfirstF11, $nF11);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfirstF11
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok11Block);
            $fiftyfirstVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftiethLong,
                $n
            );
            $ov12 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyfirstVar->longArithOverflowFlag);
            if (null === $ov12 || null === $fiftyfirstVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **61 expected smul overflow metadata (fiftyfirst)');
            }
            $fiftyfirstLong = JITVariable::KIND_VARIABLE === $fiftyfirstVar->kind
                ? $context->builder->load($fiftyfirstVar->value)
                : $fiftyfirstVar->value;
            $ov12Block = BasicBlockHelper::append($context, 'pow_sixtyfirst_fiftyfirst_ov');
            $ok12Block = BasicBlockHelper::append($context, 'pow_sixtyfirst_fiftyfirst_ok');
            $context->builder->branchIf($ov12, $ov12Block, $ok12Block);

            $context->builder->positionAtEnd($ov12Block);
            $fiftyfirstF12 = $context->builder->load($fiftyfirstVar->longArithOverflowDoubleSlot);
            $nF12 = $context->builder->siToFp($n, $f64);
            $sixtyfirstF12 = $context->builder->fmul($fiftyfirstF12, $nF12);
            $sixtyfirstF12 = $context->builder->fmul($sixtyfirstF12, $nF12);
            $sixtyfirstF12 = $context->builder->fmul($sixtyfirstF12, $nF12);
            $sixtyfirstF12 = $context->builder->fmul($sixtyfirstF12, $nF12);
            $sixtyfirstF12 = $context->builder->fmul($sixtyfirstF12, $nF12);
            $sixtyfirstF12 = $context->builder->fmul($sixtyfirstF12, $nF12);
            $sixtyfirstF12 = $context->builder->fmul($sixtyfirstF12, $nF12);
            $sixtyfirstF12 = $context->builder->fmul($sixtyfirstF12, $nF12);
            $sixtyfirstF12 = $context->builder->fmul($sixtyfirstF12, $nF12);
                $sixtyfirstF12 = $context->builder->fmul($sixtyfirstF12, $nF12);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfirstF12
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok12Block);
            $fiftysecondVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyfirstLong,
                $n
            );
            $ov13 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftysecondVar->longArithOverflowFlag);
            if (null === $ov13 || null === $fiftysecondVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **61 expected smul overflow metadata (fiftysecond)');
            }
            $fiftysecondLong = JITVariable::KIND_VARIABLE === $fiftysecondVar->kind
                ? $context->builder->load($fiftysecondVar->value)
                : $fiftysecondVar->value;
            $ov13Block = BasicBlockHelper::append($context, 'pow_sixtyfirst_fiftysecond_ov');
            $ok13Block = BasicBlockHelper::append($context, 'pow_sixtyfirst_fiftysecond_ok');
            $context->builder->branchIf($ov13, $ov13Block, $ok13Block);

            $context->builder->positionAtEnd($ov13Block);
            $fiftysecondF13 = $context->builder->load($fiftysecondVar->longArithOverflowDoubleSlot);
            $nF13 = $context->builder->siToFp($n, $f64);
            $sixtyfirstF13 = $context->builder->fmul($fiftysecondF13, $nF13);
            $sixtyfirstF13 = $context->builder->fmul($sixtyfirstF13, $nF13);
            $sixtyfirstF13 = $context->builder->fmul($sixtyfirstF13, $nF13);
            $sixtyfirstF13 = $context->builder->fmul($sixtyfirstF13, $nF13);
            $sixtyfirstF13 = $context->builder->fmul($sixtyfirstF13, $nF13);
            $sixtyfirstF13 = $context->builder->fmul($sixtyfirstF13, $nF13);
            $sixtyfirstF13 = $context->builder->fmul($sixtyfirstF13, $nF13);
            $sixtyfirstF13 = $context->builder->fmul($sixtyfirstF13, $nF13);
                $sixtyfirstF13 = $context->builder->fmul($sixtyfirstF13, $nF13);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfirstF13
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok13Block);
            $fiftythirdVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftysecondLong,
                $n
            );
            $ov14 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftythirdVar->longArithOverflowFlag);
            if (null === $ov14 || null === $fiftythirdVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **61 expected smul overflow metadata (fiftythird)');
            }
            $fiftythirdLong = JITVariable::KIND_VARIABLE === $fiftythirdVar->kind
                ? $context->builder->load($fiftythirdVar->value)
                : $fiftythirdVar->value;
            $ov14Block = BasicBlockHelper::append($context, 'pow_sixtyfirst_fiftythird_ov');
            $ok14Block = BasicBlockHelper::append($context, 'pow_sixtyfirst_fiftythird_ok');
            $context->builder->branchIf($ov14, $ov14Block, $ok14Block);

            $context->builder->positionAtEnd($ov14Block);
            $fiftythirdF14 = $context->builder->load($fiftythirdVar->longArithOverflowDoubleSlot);
            $nF14 = $context->builder->siToFp($n, $f64);
            $sixtyfirstF14 = $context->builder->fmul($fiftythirdF14, $nF14);
            $sixtyfirstF14 = $context->builder->fmul($sixtyfirstF14, $nF14);
            $sixtyfirstF14 = $context->builder->fmul($sixtyfirstF14, $nF14);
            $sixtyfirstF14 = $context->builder->fmul($sixtyfirstF14, $nF14);
            $sixtyfirstF14 = $context->builder->fmul($sixtyfirstF14, $nF14);
            $sixtyfirstF14 = $context->builder->fmul($sixtyfirstF14, $nF14);
            $sixtyfirstF14 = $context->builder->fmul($sixtyfirstF14, $nF14);
                $sixtyfirstF14 = $context->builder->fmul($sixtyfirstF14, $nF14);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfirstF14
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok14Block);
            $fiftyfourthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftythirdLong,
                $n
            );
            $ov15 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyfourthVar->longArithOverflowFlag);
            if (null === $ov15 || null === $fiftyfourthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **61 expected smul overflow metadata (fiftyfourth)');
            }
            $fiftyfourthLong = JITVariable::KIND_VARIABLE === $fiftyfourthVar->kind
                ? $context->builder->load($fiftyfourthVar->value)
                : $fiftyfourthVar->value;
            $ov15Block = BasicBlockHelper::append($context, 'pow_sixtyfirst_fiftyfourth_ov');
            $ok15Block = BasicBlockHelper::append($context, 'pow_sixtyfirst_fiftyfourth_ok');
            $context->builder->branchIf($ov15, $ov15Block, $ok15Block);

            $context->builder->positionAtEnd($ov15Block);
            $fiftyfourthF15 = $context->builder->load($fiftyfourthVar->longArithOverflowDoubleSlot);
            $nF15 = $context->builder->siToFp($n, $f64);
            $sixtyfirstF15 = $context->builder->fmul($fiftyfourthF15, $nF15);
            $sixtyfirstF15 = $context->builder->fmul($sixtyfirstF15, $nF15);
            $sixtyfirstF15 = $context->builder->fmul($sixtyfirstF15, $nF15);
            $sixtyfirstF15 = $context->builder->fmul($sixtyfirstF15, $nF15);
            $sixtyfirstF15 = $context->builder->fmul($sixtyfirstF15, $nF15);
            $sixtyfirstF15 = $context->builder->fmul($sixtyfirstF15, $nF15);
                $sixtyfirstF15 = $context->builder->fmul($sixtyfirstF15, $nF15);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfirstF15
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok15Block);
            $fiftyfifthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyfourthLong,
                $n
            );
            $ov16 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyfifthVar->longArithOverflowFlag);
            if (null === $ov16 || null === $fiftyfifthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **61 expected smul overflow metadata (fiftyfifth)');
            }
            $fiftyfifthLong = JITVariable::KIND_VARIABLE === $fiftyfifthVar->kind
                ? $context->builder->load($fiftyfifthVar->value)
                : $fiftyfifthVar->value;
            $ov16Block = BasicBlockHelper::append($context, 'pow_sixtyfirst_fiftyfifth_ov');
            $ok16Block = BasicBlockHelper::append($context, 'pow_sixtyfirst_fiftyfifth_ok');
            $context->builder->branchIf($ov16, $ov16Block, $ok16Block);

            $context->builder->positionAtEnd($ov16Block);
            $fiftyfifthF16 = $context->builder->load($fiftyfifthVar->longArithOverflowDoubleSlot);
            $nF16 = $context->builder->siToFp($n, $f64);
            $sixtyfirstF16 = $context->builder->fmul($fiftyfifthF16, $nF16);
            $sixtyfirstF16 = $context->builder->fmul($sixtyfirstF16, $nF16);
            $sixtyfirstF16 = $context->builder->fmul($sixtyfirstF16, $nF16);
            $sixtyfirstF16 = $context->builder->fmul($sixtyfirstF16, $nF16);
            $sixtyfirstF16 = $context->builder->fmul($sixtyfirstF16, $nF16);
                $sixtyfirstF16 = $context->builder->fmul($sixtyfirstF16, $nF16);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfirstF16
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok16Block);
            $fiftysixthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyfifthLong,
                $n
            );
            $ov17 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftysixthVar->longArithOverflowFlag);
            if (null === $ov17 || null === $fiftysixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **61 expected smul overflow metadata (fiftysixth)');
            }
            $fiftysixthLong = JITVariable::KIND_VARIABLE === $fiftysixthVar->kind
                ? $context->builder->load($fiftysixthVar->value)
                : $fiftysixthVar->value;
            $ov17Block = BasicBlockHelper::append($context, 'pow_sixtyfirst_fiftysixth_ov');
            $ok17Block = BasicBlockHelper::append($context, 'pow_sixtyfirst_fiftysixth_ok');
            $context->builder->branchIf($ov17, $ov17Block, $ok17Block);

            $context->builder->positionAtEnd($ov17Block);
            $fiftysixthF17 = $context->builder->load($fiftysixthVar->longArithOverflowDoubleSlot);
            $nF17 = $context->builder->siToFp($n, $f64);
            $sixtyfirstF17 = $context->builder->fmul($fiftysixthF17, $nF17);
            $sixtyfirstF17 = $context->builder->fmul($sixtyfirstF17, $nF17);
            $sixtyfirstF17 = $context->builder->fmul($sixtyfirstF17, $nF17);
            $sixtyfirstF17 = $context->builder->fmul($sixtyfirstF17, $nF17);
                $sixtyfirstF17 = $context->builder->fmul($sixtyfirstF17, $nF17);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfirstF17
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok17Block);
            $fiftyseventhVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftysixthLong,
                $n
            );
            $ov18 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyseventhVar->longArithOverflowFlag);
            if (null === $ov18 || null === $fiftyseventhVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **61 expected smul overflow metadata (fiftyseventh)');
            }
            $fiftyseventhLong = JITVariable::KIND_VARIABLE === $fiftyseventhVar->kind
                ? $context->builder->load($fiftyseventhVar->value)
                : $fiftyseventhVar->value;
            $ov18Block = BasicBlockHelper::append($context, 'pow_sixtyfirst_fiftyseventh_ov');
            $ok18Block = BasicBlockHelper::append($context, 'pow_sixtyfirst_fiftyseventh_ok');
            $context->builder->branchIf($ov18, $ov18Block, $ok18Block);

            $context->builder->positionAtEnd($ov18Block);
            $fiftyseventhF18 = $context->builder->load($fiftyseventhVar->longArithOverflowDoubleSlot);
            $nF18 = $context->builder->siToFp($n, $f64);
            $sixtyfirstF18 = $context->builder->fmul($fiftyseventhF18, $nF18);
            $sixtyfirstF18 = $context->builder->fmul($sixtyfirstF18, $nF18);
            $sixtyfirstF18 = $context->builder->fmul($sixtyfirstF18, $nF18);
                $sixtyfirstF18 = $context->builder->fmul($sixtyfirstF18, $nF18);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfirstF18
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok18Block);
            $fiftyeighthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyseventhLong,
                $n
            );
            $ov19 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyeighthVar->longArithOverflowFlag);
            if (null === $ov19 || null === $fiftyeighthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **61 expected smul overflow metadata (fiftyeighth)');
            }
            $fiftyeighthLong = JITVariable::KIND_VARIABLE === $fiftyeighthVar->kind
                ? $context->builder->load($fiftyeighthVar->value)
                : $fiftyeighthVar->value;
            $ov19Block = BasicBlockHelper::append($context, 'pow_sixtyfirst_fiftyeighth_ov');
            $ok19Block = BasicBlockHelper::append($context, 'pow_sixtyfirst_fiftyeighth_ok');
            $context->builder->branchIf($ov19, $ov19Block, $ok19Block);

            $context->builder->positionAtEnd($ov19Block);
            $fiftyeighthF19 = $context->builder->load($fiftyeighthVar->longArithOverflowDoubleSlot);
            $nF19 = $context->builder->siToFp($n, $f64);
            $sixtyfirstF19 = $context->builder->fmul($fiftyeighthF19, $nF19);
            $sixtyfirstF19 = $context->builder->fmul($sixtyfirstF19, $nF19);
                $sixtyfirstF19 = $context->builder->fmul($sixtyfirstF19, $nF19);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfirstF19
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok19Block);
            $fiftyninthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyeighthLong,
                $n
            );
            $ov20 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyninthVar->longArithOverflowFlag);
            if (null === $ov20 || null === $fiftyninthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **61 expected smul overflow metadata (fiftyninth)');
            }
            $fiftyninthLong = JITVariable::KIND_VARIABLE === $fiftyninthVar->kind
                ? $context->builder->load($fiftyninthVar->value)
                : $fiftyninthVar->value;
            $ov20Block = BasicBlockHelper::append($context, 'pow_sixtyfirst_fiftyninth_ov');
            $ok20Block = BasicBlockHelper::append($context, 'pow_sixtyfirst_fiftyninth_ok');
            $context->builder->branchIf($ov20, $ov20Block, $ok20Block);

            $context->builder->positionAtEnd($ov20Block);
            $fiftyninthF20 = $context->builder->load($fiftyninthVar->longArithOverflowDoubleSlot);
            $nF20 = $context->builder->siToFp($n, $f64);
            $sixtyfirstF20 = $context->builder->fmul($fiftyninthF20, $nF20);
                $sixtyfirstF20 = $context->builder->fmul($sixtyfirstF20, $nF20);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfirstF20
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok20Block);
            $sixtiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyninthLong,
                $n
            );
            $ov21 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixtiethVar->longArithOverflowFlag);
            if (null === $ov21 || null === $sixtiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **61 expected smul overflow metadata (sixtieth)');
            }
            $sixtiethLong = JITVariable::KIND_VARIABLE === $sixtiethVar->kind
                ? $context->builder->load($sixtiethVar->value)
                : $sixtiethVar->value;
            $ov21Block = BasicBlockHelper::append($context, 'pow_sixtyfirst_sixtieth_ov');
            $ok21Block = BasicBlockHelper::append($context, 'pow_sixtyfirst_sixtieth_ok');
            $context->builder->branchIf($ov21, $ov21Block, $ok21Block);

            $context->builder->positionAtEnd($ov21Block);
            $sixtiethF21 = $context->builder->load($sixtiethVar->longArithOverflowDoubleSlot);
            $nF21 = $context->builder->siToFp($n, $f64);
            $sixtyfirstF21 = $context->builder->fmul($sixtiethF21, $nF21);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfirstF21
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok21Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $sixtiethLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return true;
        }

        if ('sixtysecond' === $expFold) {
            // n^62 = sixtyfirst*n = fortyeighth*sq*n*n*n*n*n*n*n*n*n*n*n*n: sq=n*n, cu=sq*n, fifth=cu*sq,
            // tenth=fifth*fifth, twentieth=tenth*tenth,
            // fortieth=twentieth*twentieth, fortysecond=fortieth*sq, fortyfourth=fortysecond*sq,
            // fortysixth=fortyfourth*sq, then fortysixth*sq*sq*n*n*n*n*n*n*n*n*n*n*n*n.
            // Overflow arms finish in float (sqF^25*nF*nF*nF*nF*nF*nF*nF*nF*nF*nF,
            // ((cuF*sqF)^2)^4*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF*nF, (fifthF^2)^4*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF, tenthF^4*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF,
            // twentiethF^2*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF*nF, fortiethF*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF, fortysecondF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF,
            // fortyfourthF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF*nF, fortysixthF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF).
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
                throw new \LogicException('pow() **62 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_sixtysecond_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_sixtysecond_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_sixtysecond_done');
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
            $sixtysecondFSq = $context->builder->fmul($fortyfourthF, $sqF);
            $sixtysecondFEighth = $context->builder->fmul($sixtysecondFSq, $sqF);
            $sixtysecondF = $context->builder->fmul($sixtysecondFEighth, $sqF);
            $nF = $context->builder->siToFp($n, $f64);
            $sixtysecondF = $context->builder->fmul($sixtysecondF, $nF);
            $sixtysecondF = $context->builder->fmul($sixtysecondF, $nF);
            $sixtysecondF = $context->builder->fmul($sixtysecondF, $nF);
            $sixtysecondF = $context->builder->fmul($sixtysecondF, $nF);
            $sixtysecondF = $context->builder->fmul($sixtysecondF, $nF);
            $sixtysecondF = $context->builder->fmul($sixtysecondF, $nF);
            $sixtysecondF = $context->builder->fmul($sixtysecondF, $nF);
            $sixtysecondF = $context->builder->fmul($sixtysecondF, $nF);
            $sixtysecondF = $context->builder->fmul($sixtysecondF, $nF);
            $sixtysecondF = $context->builder->fmul($sixtysecondF, $nF);
                $sixtysecondF = $context->builder->fmul($sixtysecondF, $nF);
                    $sixtysecondF = $context->builder->fmul($sixtysecondF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtysecondF
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
                throw new \LogicException('pow() **62 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_sixtysecond_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_sixtysecond_cu_ok');
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
            $sixtysecondF2Sq = $context->builder->fmul($fortyfourthF2, $sqF2);
            $sixtysecondF2Eighth = $context->builder->fmul($sixtysecondF2Sq, $sqF2);
            $sixtysecondF2 = $context->builder->fmul($sixtysecondF2Eighth, $sqF2);
            $nF2 = $context->builder->siToFp($n, $f64);
            $sixtysecondF2 = $context->builder->fmul($sixtysecondF2, $nF2);
            $sixtysecondF2 = $context->builder->fmul($sixtysecondF2, $nF2);
            $sixtysecondF2 = $context->builder->fmul($sixtysecondF2, $nF2);
            $sixtysecondF2 = $context->builder->fmul($sixtysecondF2, $nF2);
            $sixtysecondF2 = $context->builder->fmul($sixtysecondF2, $nF2);
            $sixtysecondF2 = $context->builder->fmul($sixtysecondF2, $nF2);
            $sixtysecondF2 = $context->builder->fmul($sixtysecondF2, $nF2);
            $sixtysecondF2 = $context->builder->fmul($sixtysecondF2, $nF2);
            $sixtysecondF2 = $context->builder->fmul($sixtysecondF2, $nF2);
            $sixtysecondF2 = $context->builder->fmul($sixtysecondF2, $nF2);
                $sixtysecondF2 = $context->builder->fmul($sixtysecondF2, $nF2);
                    $sixtysecondF2 = $context->builder->fmul($sixtysecondF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtysecondF2
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
                throw new \LogicException('pow() **62 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_sixtysecond_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_sixtysecond_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $twentiethF3 = $context->builder->fmul($tenthF3, $tenthF3);
            $fortiethF3 = $context->builder->fmul($twentiethF3, $twentiethF3);
            $sqF3 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF3 = $context->builder->fmul($fortiethF3, $sqF3);
            $fortyfourthF3 = $context->builder->fmul($fortysecondF3, $sqF3);
            $sixtysecondF3Sq = $context->builder->fmul($fortyfourthF3, $sqF3);
            $sixtysecondF3Eighth = $context->builder->fmul($sixtysecondF3Sq, $sqF3);
            $sixtysecondF3 = $context->builder->fmul($sixtysecondF3Eighth, $sqF3);
            $nF3 = $context->builder->siToFp($n, $f64);
            $sixtysecondF3 = $context->builder->fmul($sixtysecondF3, $nF3);
            $sixtysecondF3 = $context->builder->fmul($sixtysecondF3, $nF3);
            $sixtysecondF3 = $context->builder->fmul($sixtysecondF3, $nF3);
            $sixtysecondF3 = $context->builder->fmul($sixtysecondF3, $nF3);
            $sixtysecondF3 = $context->builder->fmul($sixtysecondF3, $nF3);
            $sixtysecondF3 = $context->builder->fmul($sixtysecondF3, $nF3);
            $sixtysecondF3 = $context->builder->fmul($sixtysecondF3, $nF3);
            $sixtysecondF3 = $context->builder->fmul($sixtysecondF3, $nF3);
            $sixtysecondF3 = $context->builder->fmul($sixtysecondF3, $nF3);
            $sixtysecondF3 = $context->builder->fmul($sixtysecondF3, $nF3);
                $sixtysecondF3 = $context->builder->fmul($sixtysecondF3, $nF3);
                    $sixtysecondF3 = $context->builder->fmul($sixtysecondF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtysecondF3
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
                throw new \LogicException('pow() **62 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_sixtysecond_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_sixtysecond_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF4 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $twentiethF4 = $context->builder->fmul($tenthF4, $tenthF4);
            $fortiethF4 = $context->builder->fmul($twentiethF4, $twentiethF4);
            $sqF4 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF4 = $context->builder->fmul($fortiethF4, $sqF4);
            $fortyfourthF4 = $context->builder->fmul($fortysecondF4, $sqF4);
            $sixtysecondF4Sq = $context->builder->fmul($fortyfourthF4, $sqF4);
            $sixtysecondF4Eighth = $context->builder->fmul($sixtysecondF4Sq, $sqF4);
            $sixtysecondF4 = $context->builder->fmul($sixtysecondF4Eighth, $sqF4);
            $nF4 = $context->builder->siToFp($n, $f64);
            $sixtysecondF4 = $context->builder->fmul($sixtysecondF4, $nF4);
            $sixtysecondF4 = $context->builder->fmul($sixtysecondF4, $nF4);
            $sixtysecondF4 = $context->builder->fmul($sixtysecondF4, $nF4);
            $sixtysecondF4 = $context->builder->fmul($sixtysecondF4, $nF4);
            $sixtysecondF4 = $context->builder->fmul($sixtysecondF4, $nF4);
            $sixtysecondF4 = $context->builder->fmul($sixtysecondF4, $nF4);
            $sixtysecondF4 = $context->builder->fmul($sixtysecondF4, $nF4);
            $sixtysecondF4 = $context->builder->fmul($sixtysecondF4, $nF4);
            $sixtysecondF4 = $context->builder->fmul($sixtysecondF4, $nF4);
            $sixtysecondF4 = $context->builder->fmul($sixtysecondF4, $nF4);
                $sixtysecondF4 = $context->builder->fmul($sixtysecondF4, $nF4);
                    $sixtysecondF4 = $context->builder->fmul($sixtysecondF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtysecondF4
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
                throw new \LogicException('pow() **62 expected smul overflow metadata (twentieth)');
            }
            $twentiethLong = JITVariable::KIND_VARIABLE === $twentiethVar->kind
                ? $context->builder->load($twentiethVar->value)
                : $twentiethVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_sixtysecond_twentieth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_sixtysecond_twentieth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $twentiethF5 = $context->builder->load($twentiethVar->longArithOverflowDoubleSlot);
            $fortiethF5 = $context->builder->fmul($twentiethF5, $twentiethF5);
            $sqF5 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF5 = $context->builder->fmul($fortiethF5, $sqF5);
            $fortyfourthF5 = $context->builder->fmul($fortysecondF5, $sqF5);
            $sixtysecondF5Sq = $context->builder->fmul($fortyfourthF5, $sqF5);
            $sixtysecondF5Eighth = $context->builder->fmul($sixtysecondF5Sq, $sqF5);
            $sixtysecondF5 = $context->builder->fmul($sixtysecondF5Eighth, $sqF5);
            $nF5 = $context->builder->siToFp($n, $f64);
            $sixtysecondF5 = $context->builder->fmul($sixtysecondF5, $nF5);
            $sixtysecondF5 = $context->builder->fmul($sixtysecondF5, $nF5);
            $sixtysecondF5 = $context->builder->fmul($sixtysecondF5, $nF5);
            $sixtysecondF5 = $context->builder->fmul($sixtysecondF5, $nF5);
            $sixtysecondF5 = $context->builder->fmul($sixtysecondF5, $nF5);
            $sixtysecondF5 = $context->builder->fmul($sixtysecondF5, $nF5);
            $sixtysecondF5 = $context->builder->fmul($sixtysecondF5, $nF5);
            $sixtysecondF5 = $context->builder->fmul($sixtysecondF5, $nF5);
            $sixtysecondF5 = $context->builder->fmul($sixtysecondF5, $nF5);
            $sixtysecondF5 = $context->builder->fmul($sixtysecondF5, $nF5);
                $sixtysecondF5 = $context->builder->fmul($sixtysecondF5, $nF5);
                    $sixtysecondF5 = $context->builder->fmul($sixtysecondF5, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtysecondF5
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
                throw new \LogicException('pow() **62 expected smul overflow metadata (fortieth)');
            }
            $fortiethLong = JITVariable::KIND_VARIABLE === $fortiethVar->kind
                ? $context->builder->load($fortiethVar->value)
                : $fortiethVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_sixtysecond_fortieth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_sixtysecond_fortieth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $fortiethF6 = $context->builder->load($fortiethVar->longArithOverflowDoubleSlot);
            $sqF6 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF6 = $context->builder->fmul($fortiethF6, $sqF6);
            $fortyfourthF6 = $context->builder->fmul($fortysecondF6, $sqF6);
            $sixtysecondF6Sq = $context->builder->fmul($fortyfourthF6, $sqF6);
            $sixtysecondF6Eighth = $context->builder->fmul($sixtysecondF6Sq, $sqF6);
            $sixtysecondF6 = $context->builder->fmul($sixtysecondF6Eighth, $sqF6);
            $nF6 = $context->builder->siToFp($n, $f64);
            $sixtysecondF6 = $context->builder->fmul($sixtysecondF6, $nF6);
            $sixtysecondF6 = $context->builder->fmul($sixtysecondF6, $nF6);
            $sixtysecondF6 = $context->builder->fmul($sixtysecondF6, $nF6);
            $sixtysecondF6 = $context->builder->fmul($sixtysecondF6, $nF6);
            $sixtysecondF6 = $context->builder->fmul($sixtysecondF6, $nF6);
            $sixtysecondF6 = $context->builder->fmul($sixtysecondF6, $nF6);
            $sixtysecondF6 = $context->builder->fmul($sixtysecondF6, $nF6);
            $sixtysecondF6 = $context->builder->fmul($sixtysecondF6, $nF6);
            $sixtysecondF6 = $context->builder->fmul($sixtysecondF6, $nF6);
            $sixtysecondF6 = $context->builder->fmul($sixtysecondF6, $nF6);
                $sixtysecondF6 = $context->builder->fmul($sixtysecondF6, $nF6);
                    $sixtysecondF6 = $context->builder->fmul($sixtysecondF6, $nF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtysecondF6
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
                throw new \LogicException('pow() **62 expected smul overflow metadata (fortysecond)');
            }
            $fortysecondLong = JITVariable::KIND_VARIABLE === $fortysecondVar->kind
                ? $context->builder->load($fortysecondVar->value)
                : $fortysecondVar->value;
            $ov7Block = BasicBlockHelper::append($context, 'pow_sixtysecond_fortysecond_ov');
            $ok7Block = BasicBlockHelper::append($context, 'pow_sixtysecond_fortysecond_ok');
            $context->builder->branchIf($ov7, $ov7Block, $ok7Block);

            $context->builder->positionAtEnd($ov7Block);
            $fortysecondF7 = $context->builder->load($fortysecondVar->longArithOverflowDoubleSlot);
            $sqF7 = $context->builder->siToFp($sqLong, $f64);
            $fortyfourthF7 = $context->builder->fmul($fortysecondF7, $sqF7);
            $sixtysecondF7Sq = $context->builder->fmul($fortyfourthF7, $sqF7);
            $sixtysecondF7Eighth = $context->builder->fmul($sixtysecondF7Sq, $sqF7);
            $sixtysecondF7 = $context->builder->fmul($sixtysecondF7Eighth, $sqF7);
            $nF7 = $context->builder->siToFp($n, $f64);
            $sixtysecondF7 = $context->builder->fmul($sixtysecondF7, $nF7);
            $sixtysecondF7 = $context->builder->fmul($sixtysecondF7, $nF7);
            $sixtysecondF7 = $context->builder->fmul($sixtysecondF7, $nF7);
            $sixtysecondF7 = $context->builder->fmul($sixtysecondF7, $nF7);
            $sixtysecondF7 = $context->builder->fmul($sixtysecondF7, $nF7);
            $sixtysecondF7 = $context->builder->fmul($sixtysecondF7, $nF7);
            $sixtysecondF7 = $context->builder->fmul($sixtysecondF7, $nF7);
            $sixtysecondF7 = $context->builder->fmul($sixtysecondF7, $nF7);
            $sixtysecondF7 = $context->builder->fmul($sixtysecondF7, $nF7);
            $sixtysecondF7 = $context->builder->fmul($sixtysecondF7, $nF7);
                $sixtysecondF7 = $context->builder->fmul($sixtysecondF7, $nF7);
                    $sixtysecondF7 = $context->builder->fmul($sixtysecondF7, $nF7);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtysecondF7
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
                throw new \LogicException('pow() **62 expected smul overflow metadata (fortyfourth)');
            }
            $fortyfourthLong = JITVariable::KIND_VARIABLE === $fortyfourthVar->kind
                ? $context->builder->load($fortyfourthVar->value)
                : $fortyfourthVar->value;
            $ov8Block = BasicBlockHelper::append($context, 'pow_sixtysecond_fortyfourth_ov');
            $ok8Block = BasicBlockHelper::append($context, 'pow_sixtysecond_fortyfourth_ok');
            $context->builder->branchIf($ov8, $ov8Block, $ok8Block);

            $context->builder->positionAtEnd($ov8Block);
            $fortyfourthF8 = $context->builder->load($fortyfourthVar->longArithOverflowDoubleSlot);
            $sqF8 = $context->builder->siToFp($sqLong, $f64);
            $sixtysecondF8Sq = $context->builder->fmul($fortyfourthF8, $sqF8);
            $sixtysecondF8Eighth = $context->builder->fmul($sixtysecondF8Sq, $sqF8);
            $sixtysecondF8 = $context->builder->fmul($sixtysecondF8Eighth, $sqF8);
            $nF8 = $context->builder->siToFp($n, $f64);
            $sixtysecondF8 = $context->builder->fmul($sixtysecondF8, $nF8);
            $sixtysecondF8 = $context->builder->fmul($sixtysecondF8, $nF8);
            $sixtysecondF8 = $context->builder->fmul($sixtysecondF8, $nF8);
            $sixtysecondF8 = $context->builder->fmul($sixtysecondF8, $nF8);
            $sixtysecondF8 = $context->builder->fmul($sixtysecondF8, $nF8);
            $sixtysecondF8 = $context->builder->fmul($sixtysecondF8, $nF8);
            $sixtysecondF8 = $context->builder->fmul($sixtysecondF8, $nF8);
            $sixtysecondF8 = $context->builder->fmul($sixtysecondF8, $nF8);
            $sixtysecondF8 = $context->builder->fmul($sixtysecondF8, $nF8);
            $sixtysecondF8 = $context->builder->fmul($sixtysecondF8, $nF8);
                $sixtysecondF8 = $context->builder->fmul($sixtysecondF8, $nF8);
                    $sixtysecondF8 = $context->builder->fmul($sixtysecondF8, $nF8);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtysecondF8
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
                throw new \LogicException('pow() **62 expected smul overflow metadata (fortysixth)');
            }
            $fortysixthLong = JITVariable::KIND_VARIABLE === $fortysixthVar->kind
                ? $context->builder->load($fortysixthVar->value)
                : $fortysixthVar->value;
            $ov9Block = BasicBlockHelper::append($context, 'pow_sixtysecond_fortysixth_ov');
            $ok9Block = BasicBlockHelper::append($context, 'pow_sixtysecond_fortysixth_ok');
            $context->builder->branchIf($ov9, $ov9Block, $ok9Block);

            $context->builder->positionAtEnd($ov9Block);
            $fortysixthF9 = $context->builder->load($fortysixthVar->longArithOverflowDoubleSlot);
            $sqF9 = $context->builder->siToFp($sqLong, $f64);
            $fortyeighthF9 = $context->builder->fmul($fortysixthF9, $sqF9);
            $sixtysecondF9 = $context->builder->fmul($fortyeighthF9, $sqF9);
            $nF9 = $context->builder->siToFp($n, $f64);
            $sixtysecondF9 = $context->builder->fmul($sixtysecondF9, $nF9);
            $sixtysecondF9 = $context->builder->fmul($sixtysecondF9, $nF9);
            $sixtysecondF9 = $context->builder->fmul($sixtysecondF9, $nF9);
            $sixtysecondF9 = $context->builder->fmul($sixtysecondF9, $nF9);
            $sixtysecondF9 = $context->builder->fmul($sixtysecondF9, $nF9);
            $sixtysecondF9 = $context->builder->fmul($sixtysecondF9, $nF9);
            $sixtysecondF9 = $context->builder->fmul($sixtysecondF9, $nF9);
            $sixtysecondF9 = $context->builder->fmul($sixtysecondF9, $nF9);
            $sixtysecondF9 = $context->builder->fmul($sixtysecondF9, $nF9);
            $sixtysecondF9 = $context->builder->fmul($sixtysecondF9, $nF9);
                $sixtysecondF9 = $context->builder->fmul($sixtysecondF9, $nF9);
                    $sixtysecondF9 = $context->builder->fmul($sixtysecondF9, $nF9);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtysecondF9
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
                throw new \LogicException('pow() **62 expected smul overflow metadata (fortyeighth)');
            }
            $fortyeighthLong = JITVariable::KIND_VARIABLE === $fortyeighthVar->kind
                ? $context->builder->load($fortyeighthVar->value)
                : $fortyeighthVar->value;
            $ov10Block = BasicBlockHelper::append($context, 'pow_sixtysecond_fortyeighth_ov');
            $ok10Block = BasicBlockHelper::append($context, 'pow_sixtysecond_fortyeighth_ok');
            $context->builder->branchIf($ov10, $ov10Block, $ok10Block);

            $context->builder->positionAtEnd($ov10Block);
            $fortyeighthF10 = $context->builder->load($fortyeighthVar->longArithOverflowDoubleSlot);
            $sqF10 = $context->builder->siToFp($sqLong, $f64);
            $sixtysecondF10 = $context->builder->fmul($fortyeighthF10, $sqF10);
            $nF10 = $context->builder->siToFp($n, $f64);
            $sixtysecondF10 = $context->builder->fmul($sixtysecondF10, $nF10);
            $sixtysecondF10 = $context->builder->fmul($sixtysecondF10, $nF10);
            $sixtysecondF10 = $context->builder->fmul($sixtysecondF10, $nF10);
            $sixtysecondF10 = $context->builder->fmul($sixtysecondF10, $nF10);
            $sixtysecondF10 = $context->builder->fmul($sixtysecondF10, $nF10);
            $sixtysecondF10 = $context->builder->fmul($sixtysecondF10, $nF10);
            $sixtysecondF10 = $context->builder->fmul($sixtysecondF10, $nF10);
            $sixtysecondF10 = $context->builder->fmul($sixtysecondF10, $nF10);
            $sixtysecondF10 = $context->builder->fmul($sixtysecondF10, $nF10);
            $sixtysecondF10 = $context->builder->fmul($sixtysecondF10, $nF10);
                $sixtysecondF10 = $context->builder->fmul($sixtysecondF10, $nF10);
                    $sixtysecondF10 = $context->builder->fmul($sixtysecondF10, $nF10);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtysecondF10
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok10Block);
            $fiftiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fortyeighthLong,
                $sqLong
            );
            $ov11 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftiethVar->longArithOverflowFlag);
            if (null === $ov11 || null === $fiftiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **62 expected smul overflow metadata (fiftieth)');
            }
            $fiftiethLong = JITVariable::KIND_VARIABLE === $fiftiethVar->kind
                ? $context->builder->load($fiftiethVar->value)
                : $fiftiethVar->value;
            $ov11Block = BasicBlockHelper::append($context, 'pow_sixtysecond_fiftieth_ov');
            $ok11Block = BasicBlockHelper::append($context, 'pow_sixtysecond_fiftieth_ok');
            $context->builder->branchIf($ov11, $ov11Block, $ok11Block);

            $context->builder->positionAtEnd($ov11Block);
            $fiftiethF11 = $context->builder->load($fiftiethVar->longArithOverflowDoubleSlot);
            $nF11 = $context->builder->siToFp($n, $f64);
            $sixtysecondF11 = $context->builder->fmul($fiftiethF11, $nF11);
            $sixtysecondF11 = $context->builder->fmul($sixtysecondF11, $nF11);
            $sixtysecondF11 = $context->builder->fmul($sixtysecondF11, $nF11);
            $sixtysecondF11 = $context->builder->fmul($sixtysecondF11, $nF11);
            $sixtysecondF11 = $context->builder->fmul($sixtysecondF11, $nF11);
            $sixtysecondF11 = $context->builder->fmul($sixtysecondF11, $nF11);
            $sixtysecondF11 = $context->builder->fmul($sixtysecondF11, $nF11);
            $sixtysecondF11 = $context->builder->fmul($sixtysecondF11, $nF11);
            $sixtysecondF11 = $context->builder->fmul($sixtysecondF11, $nF11);
            $sixtysecondF11 = $context->builder->fmul($sixtysecondF11, $nF11);
                $sixtysecondF11 = $context->builder->fmul($sixtysecondF11, $nF11);
                    $sixtysecondF11 = $context->builder->fmul($sixtysecondF11, $nF11);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtysecondF11
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok11Block);
            $fiftyfirstVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftiethLong,
                $n
            );
            $ov12 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyfirstVar->longArithOverflowFlag);
            if (null === $ov12 || null === $fiftyfirstVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **62 expected smul overflow metadata (fiftyfirst)');
            }
            $fiftyfirstLong = JITVariable::KIND_VARIABLE === $fiftyfirstVar->kind
                ? $context->builder->load($fiftyfirstVar->value)
                : $fiftyfirstVar->value;
            $ov12Block = BasicBlockHelper::append($context, 'pow_sixtysecond_fiftyfirst_ov');
            $ok12Block = BasicBlockHelper::append($context, 'pow_sixtysecond_fiftyfirst_ok');
            $context->builder->branchIf($ov12, $ov12Block, $ok12Block);

            $context->builder->positionAtEnd($ov12Block);
            $fiftyfirstF12 = $context->builder->load($fiftyfirstVar->longArithOverflowDoubleSlot);
            $nF12 = $context->builder->siToFp($n, $f64);
            $sixtysecondF12 = $context->builder->fmul($fiftyfirstF12, $nF12);
            $sixtysecondF12 = $context->builder->fmul($sixtysecondF12, $nF12);
            $sixtysecondF12 = $context->builder->fmul($sixtysecondF12, $nF12);
            $sixtysecondF12 = $context->builder->fmul($sixtysecondF12, $nF12);
            $sixtysecondF12 = $context->builder->fmul($sixtysecondF12, $nF12);
            $sixtysecondF12 = $context->builder->fmul($sixtysecondF12, $nF12);
            $sixtysecondF12 = $context->builder->fmul($sixtysecondF12, $nF12);
            $sixtysecondF12 = $context->builder->fmul($sixtysecondF12, $nF12);
            $sixtysecondF12 = $context->builder->fmul($sixtysecondF12, $nF12);
                $sixtysecondF12 = $context->builder->fmul($sixtysecondF12, $nF12);
                    $sixtysecondF12 = $context->builder->fmul($sixtysecondF12, $nF12);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtysecondF12
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok12Block);
            $fiftysecondVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyfirstLong,
                $n
            );
            $ov13 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftysecondVar->longArithOverflowFlag);
            if (null === $ov13 || null === $fiftysecondVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **62 expected smul overflow metadata (fiftysecond)');
            }
            $fiftysecondLong = JITVariable::KIND_VARIABLE === $fiftysecondVar->kind
                ? $context->builder->load($fiftysecondVar->value)
                : $fiftysecondVar->value;
            $ov13Block = BasicBlockHelper::append($context, 'pow_sixtysecond_fiftysecond_ov');
            $ok13Block = BasicBlockHelper::append($context, 'pow_sixtysecond_fiftysecond_ok');
            $context->builder->branchIf($ov13, $ov13Block, $ok13Block);

            $context->builder->positionAtEnd($ov13Block);
            $fiftysecondF13 = $context->builder->load($fiftysecondVar->longArithOverflowDoubleSlot);
            $nF13 = $context->builder->siToFp($n, $f64);
            $sixtysecondF13 = $context->builder->fmul($fiftysecondF13, $nF13);
            $sixtysecondF13 = $context->builder->fmul($sixtysecondF13, $nF13);
            $sixtysecondF13 = $context->builder->fmul($sixtysecondF13, $nF13);
            $sixtysecondF13 = $context->builder->fmul($sixtysecondF13, $nF13);
            $sixtysecondF13 = $context->builder->fmul($sixtysecondF13, $nF13);
            $sixtysecondF13 = $context->builder->fmul($sixtysecondF13, $nF13);
            $sixtysecondF13 = $context->builder->fmul($sixtysecondF13, $nF13);
            $sixtysecondF13 = $context->builder->fmul($sixtysecondF13, $nF13);
                $sixtysecondF13 = $context->builder->fmul($sixtysecondF13, $nF13);
                    $sixtysecondF13 = $context->builder->fmul($sixtysecondF13, $nF13);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtysecondF13
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok13Block);
            $fiftythirdVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftysecondLong,
                $n
            );
            $ov14 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftythirdVar->longArithOverflowFlag);
            if (null === $ov14 || null === $fiftythirdVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **62 expected smul overflow metadata (fiftythird)');
            }
            $fiftythirdLong = JITVariable::KIND_VARIABLE === $fiftythirdVar->kind
                ? $context->builder->load($fiftythirdVar->value)
                : $fiftythirdVar->value;
            $ov14Block = BasicBlockHelper::append($context, 'pow_sixtysecond_fiftythird_ov');
            $ok14Block = BasicBlockHelper::append($context, 'pow_sixtysecond_fiftythird_ok');
            $context->builder->branchIf($ov14, $ov14Block, $ok14Block);

            $context->builder->positionAtEnd($ov14Block);
            $fiftythirdF14 = $context->builder->load($fiftythirdVar->longArithOverflowDoubleSlot);
            $nF14 = $context->builder->siToFp($n, $f64);
            $sixtysecondF14 = $context->builder->fmul($fiftythirdF14, $nF14);
            $sixtysecondF14 = $context->builder->fmul($sixtysecondF14, $nF14);
            $sixtysecondF14 = $context->builder->fmul($sixtysecondF14, $nF14);
            $sixtysecondF14 = $context->builder->fmul($sixtysecondF14, $nF14);
            $sixtysecondF14 = $context->builder->fmul($sixtysecondF14, $nF14);
            $sixtysecondF14 = $context->builder->fmul($sixtysecondF14, $nF14);
            $sixtysecondF14 = $context->builder->fmul($sixtysecondF14, $nF14);
                $sixtysecondF14 = $context->builder->fmul($sixtysecondF14, $nF14);
                    $sixtysecondF14 = $context->builder->fmul($sixtysecondF14, $nF14);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtysecondF14
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok14Block);
            $fiftyfourthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftythirdLong,
                $n
            );
            $ov15 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyfourthVar->longArithOverflowFlag);
            if (null === $ov15 || null === $fiftyfourthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **62 expected smul overflow metadata (fiftyfourth)');
            }
            $fiftyfourthLong = JITVariable::KIND_VARIABLE === $fiftyfourthVar->kind
                ? $context->builder->load($fiftyfourthVar->value)
                : $fiftyfourthVar->value;
            $ov15Block = BasicBlockHelper::append($context, 'pow_sixtysecond_fiftyfourth_ov');
            $ok15Block = BasicBlockHelper::append($context, 'pow_sixtysecond_fiftyfourth_ok');
            $context->builder->branchIf($ov15, $ov15Block, $ok15Block);

            $context->builder->positionAtEnd($ov15Block);
            $fiftyfourthF15 = $context->builder->load($fiftyfourthVar->longArithOverflowDoubleSlot);
            $nF15 = $context->builder->siToFp($n, $f64);
            $sixtysecondF15 = $context->builder->fmul($fiftyfourthF15, $nF15);
            $sixtysecondF15 = $context->builder->fmul($sixtysecondF15, $nF15);
            $sixtysecondF15 = $context->builder->fmul($sixtysecondF15, $nF15);
            $sixtysecondF15 = $context->builder->fmul($sixtysecondF15, $nF15);
            $sixtysecondF15 = $context->builder->fmul($sixtysecondF15, $nF15);
            $sixtysecondF15 = $context->builder->fmul($sixtysecondF15, $nF15);
                $sixtysecondF15 = $context->builder->fmul($sixtysecondF15, $nF15);
                    $sixtysecondF15 = $context->builder->fmul($sixtysecondF15, $nF15);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtysecondF15
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok15Block);
            $fiftyfifthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyfourthLong,
                $n
            );
            $ov16 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyfifthVar->longArithOverflowFlag);
            if (null === $ov16 || null === $fiftyfifthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **62 expected smul overflow metadata (fiftyfifth)');
            }
            $fiftyfifthLong = JITVariable::KIND_VARIABLE === $fiftyfifthVar->kind
                ? $context->builder->load($fiftyfifthVar->value)
                : $fiftyfifthVar->value;
            $ov16Block = BasicBlockHelper::append($context, 'pow_sixtysecond_fiftyfifth_ov');
            $ok16Block = BasicBlockHelper::append($context, 'pow_sixtysecond_fiftyfifth_ok');
            $context->builder->branchIf($ov16, $ov16Block, $ok16Block);

            $context->builder->positionAtEnd($ov16Block);
            $fiftyfifthF16 = $context->builder->load($fiftyfifthVar->longArithOverflowDoubleSlot);
            $nF16 = $context->builder->siToFp($n, $f64);
            $sixtysecondF16 = $context->builder->fmul($fiftyfifthF16, $nF16);
            $sixtysecondF16 = $context->builder->fmul($sixtysecondF16, $nF16);
            $sixtysecondF16 = $context->builder->fmul($sixtysecondF16, $nF16);
            $sixtysecondF16 = $context->builder->fmul($sixtysecondF16, $nF16);
            $sixtysecondF16 = $context->builder->fmul($sixtysecondF16, $nF16);
                $sixtysecondF16 = $context->builder->fmul($sixtysecondF16, $nF16);
                    $sixtysecondF16 = $context->builder->fmul($sixtysecondF16, $nF16);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtysecondF16
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok16Block);
            $fiftysixthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyfifthLong,
                $n
            );
            $ov17 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftysixthVar->longArithOverflowFlag);
            if (null === $ov17 || null === $fiftysixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **62 expected smul overflow metadata (fiftysixth)');
            }
            $fiftysixthLong = JITVariable::KIND_VARIABLE === $fiftysixthVar->kind
                ? $context->builder->load($fiftysixthVar->value)
                : $fiftysixthVar->value;
            $ov17Block = BasicBlockHelper::append($context, 'pow_sixtysecond_fiftysixth_ov');
            $ok17Block = BasicBlockHelper::append($context, 'pow_sixtysecond_fiftysixth_ok');
            $context->builder->branchIf($ov17, $ov17Block, $ok17Block);

            $context->builder->positionAtEnd($ov17Block);
            $fiftysixthF17 = $context->builder->load($fiftysixthVar->longArithOverflowDoubleSlot);
            $nF17 = $context->builder->siToFp($n, $f64);
            $sixtysecondF17 = $context->builder->fmul($fiftysixthF17, $nF17);
            $sixtysecondF17 = $context->builder->fmul($sixtysecondF17, $nF17);
            $sixtysecondF17 = $context->builder->fmul($sixtysecondF17, $nF17);
            $sixtysecondF17 = $context->builder->fmul($sixtysecondF17, $nF17);
                $sixtysecondF17 = $context->builder->fmul($sixtysecondF17, $nF17);
                    $sixtysecondF17 = $context->builder->fmul($sixtysecondF17, $nF17);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtysecondF17
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok17Block);
            $fiftyseventhVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftysixthLong,
                $n
            );
            $ov18 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyseventhVar->longArithOverflowFlag);
            if (null === $ov18 || null === $fiftyseventhVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **62 expected smul overflow metadata (fiftyseventh)');
            }
            $fiftyseventhLong = JITVariable::KIND_VARIABLE === $fiftyseventhVar->kind
                ? $context->builder->load($fiftyseventhVar->value)
                : $fiftyseventhVar->value;
            $ov18Block = BasicBlockHelper::append($context, 'pow_sixtysecond_fiftyseventh_ov');
            $ok18Block = BasicBlockHelper::append($context, 'pow_sixtysecond_fiftyseventh_ok');
            $context->builder->branchIf($ov18, $ov18Block, $ok18Block);

            $context->builder->positionAtEnd($ov18Block);
            $fiftyseventhF18 = $context->builder->load($fiftyseventhVar->longArithOverflowDoubleSlot);
            $nF18 = $context->builder->siToFp($n, $f64);
            $sixtysecondF18 = $context->builder->fmul($fiftyseventhF18, $nF18);
            $sixtysecondF18 = $context->builder->fmul($sixtysecondF18, $nF18);
            $sixtysecondF18 = $context->builder->fmul($sixtysecondF18, $nF18);
                $sixtysecondF18 = $context->builder->fmul($sixtysecondF18, $nF18);
                    $sixtysecondF18 = $context->builder->fmul($sixtysecondF18, $nF18);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtysecondF18
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok18Block);
            $fiftyeighthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyseventhLong,
                $n
            );
            $ov19 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyeighthVar->longArithOverflowFlag);
            if (null === $ov19 || null === $fiftyeighthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **62 expected smul overflow metadata (fiftyeighth)');
            }
            $fiftyeighthLong = JITVariable::KIND_VARIABLE === $fiftyeighthVar->kind
                ? $context->builder->load($fiftyeighthVar->value)
                : $fiftyeighthVar->value;
            $ov19Block = BasicBlockHelper::append($context, 'pow_sixtysecond_fiftyeighth_ov');
            $ok19Block = BasicBlockHelper::append($context, 'pow_sixtysecond_fiftyeighth_ok');
            $context->builder->branchIf($ov19, $ov19Block, $ok19Block);

            $context->builder->positionAtEnd($ov19Block);
            $fiftyeighthF19 = $context->builder->load($fiftyeighthVar->longArithOverflowDoubleSlot);
            $nF19 = $context->builder->siToFp($n, $f64);
            $sixtysecondF19 = $context->builder->fmul($fiftyeighthF19, $nF19);
            $sixtysecondF19 = $context->builder->fmul($sixtysecondF19, $nF19);
                $sixtysecondF19 = $context->builder->fmul($sixtysecondF19, $nF19);
                    $sixtysecondF19 = $context->builder->fmul($sixtysecondF19, $nF19);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtysecondF19
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok19Block);
            $fiftyninthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyeighthLong,
                $n
            );
            $ov20 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyninthVar->longArithOverflowFlag);
            if (null === $ov20 || null === $fiftyninthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **62 expected smul overflow metadata (fiftyninth)');
            }
            $fiftyninthLong = JITVariable::KIND_VARIABLE === $fiftyninthVar->kind
                ? $context->builder->load($fiftyninthVar->value)
                : $fiftyninthVar->value;
            $ov20Block = BasicBlockHelper::append($context, 'pow_sixtysecond_fiftyninth_ov');
            $ok20Block = BasicBlockHelper::append($context, 'pow_sixtysecond_fiftyninth_ok');
            $context->builder->branchIf($ov20, $ov20Block, $ok20Block);

            $context->builder->positionAtEnd($ov20Block);
            $fiftyninthF20 = $context->builder->load($fiftyninthVar->longArithOverflowDoubleSlot);
            $nF20 = $context->builder->siToFp($n, $f64);
            $sixtysecondF20 = $context->builder->fmul($fiftyninthF20, $nF20);
                $sixtysecondF20 = $context->builder->fmul($sixtysecondF20, $nF20);
                    $sixtysecondF20 = $context->builder->fmul($sixtysecondF20, $nF20);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtysecondF20
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok20Block);
            $sixtiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyninthLong,
                $n
            );
            $ov21 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixtiethVar->longArithOverflowFlag);
            if (null === $ov21 || null === $sixtiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **62 expected smul overflow metadata (sixtieth)');
            }
            $sixtiethLong = JITVariable::KIND_VARIABLE === $sixtiethVar->kind
                ? $context->builder->load($sixtiethVar->value)
                : $sixtiethVar->value;
            $ov21Block = BasicBlockHelper::append($context, 'pow_sixtysecond_sixtieth_ov');
            $ok21Block = BasicBlockHelper::append($context, 'pow_sixtysecond_sixtieth_ok');
            $context->builder->branchIf($ov21, $ov21Block, $ok21Block);

            $context->builder->positionAtEnd($ov21Block);
            $sixtiethF21 = $context->builder->load($sixtiethVar->longArithOverflowDoubleSlot);
            $nF21 = $context->builder->siToFp($n, $f64);
            $sixtysecondF21 = $context->builder->fmul($sixtiethF21, $nF21);
                $sixtysecondF21 = $context->builder->fmul($sixtysecondF21, $nF21);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtysecondF21
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok21Block);
            $sixtyfirstVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixtiethLong,
                $n
            );
            $ov22 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixtyfirstVar->longArithOverflowFlag);
            if (null === $ov22 || null === $sixtyfirstVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **62 expected smul overflow metadata (sixtyfirst)');
            }
            $sixtyfirstLong = JITVariable::KIND_VARIABLE === $sixtyfirstVar->kind
                ? $context->builder->load($sixtyfirstVar->value)
                : $sixtyfirstVar->value;
            $ov22Block = BasicBlockHelper::append($context, 'pow_sixtysecond_sixtyfirst_ov');
            $ok22Block = BasicBlockHelper::append($context, 'pow_sixtysecond_sixtyfirst_ok');
            $context->builder->branchIf($ov22, $ov22Block, $ok22Block);

            $context->builder->positionAtEnd($ov22Block);
            $sixtyfirstF22 = $context->builder->load($sixtyfirstVar->longArithOverflowDoubleSlot);
            $nF22 = $context->builder->siToFp($n, $f64);
            $sixtysecondF22 = $context->builder->fmul($sixtyfirstF22, $nF22);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtysecondF22
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok22Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $sixtyfirstLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);


            $context->builder->positionAtEnd($doneBlock);

            return true;
        }
        if ('sixtythird' === $expFold) {
            // n^63 = sixtysecond*n = fortyeighth*sq*n*n*n*n*n*n*n*n*n*n*n*n*n: sq=n*n, cu=sq*n, fifth=cu*sq,
            // tenth=fifth*fifth, twentieth=tenth*tenth,
            // fortieth=twentieth*twentieth, fortysecond=fortieth*sq, fortyfourth=fortysecond*sq,
            // fortysixth=fortyfourth*sq, then fortysixth*sq*sq*n*n*n*n*n*n*n*n*n*n*n*n*n.
            // Overflow arms finish in float (sqF^25*nF*nF*nF*nF*nF*nF*nF*nF*nF*nF,
            // ((cuF*sqF)^2)^4*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF*nF, (fifthF^2)^4*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF, tenthF^4*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF,
            // twentiethF^2*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF*nF, fortiethF*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF, fortysecondF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF,
            // fortyfourthF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF*nF, fortysixthF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF).
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
                throw new \LogicException('pow() **63 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_sixtythird_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_sixtythird_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_sixtythird_done');
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
            $sixtythirdFSq = $context->builder->fmul($fortyfourthF, $sqF);
            $sixtythirdFEighth = $context->builder->fmul($sixtythirdFSq, $sqF);
            $sixtythirdF = $context->builder->fmul($sixtythirdFEighth, $sqF);
            $nF = $context->builder->siToFp($n, $f64);
            $sixtythirdF = $context->builder->fmul($sixtythirdF, $nF);
            $sixtythirdF = $context->builder->fmul($sixtythirdF, $nF);
            $sixtythirdF = $context->builder->fmul($sixtythirdF, $nF);
            $sixtythirdF = $context->builder->fmul($sixtythirdF, $nF);
            $sixtythirdF = $context->builder->fmul($sixtythirdF, $nF);
            $sixtythirdF = $context->builder->fmul($sixtythirdF, $nF);
            $sixtythirdF = $context->builder->fmul($sixtythirdF, $nF);
            $sixtythirdF = $context->builder->fmul($sixtythirdF, $nF);
            $sixtythirdF = $context->builder->fmul($sixtythirdF, $nF);
            $sixtythirdF = $context->builder->fmul($sixtythirdF, $nF);
                $sixtythirdF = $context->builder->fmul($sixtythirdF, $nF);
                    $sixtythirdF = $context->builder->fmul($sixtythirdF, $nF);
                        $sixtythirdF = $context->builder->fmul($sixtythirdF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtythirdF
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
                throw new \LogicException('pow() **63 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_sixtythird_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_sixtythird_cu_ok');
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
            $sixtythirdF2Sq = $context->builder->fmul($fortyfourthF2, $sqF2);
            $sixtythirdF2Eighth = $context->builder->fmul($sixtythirdF2Sq, $sqF2);
            $sixtythirdF2 = $context->builder->fmul($sixtythirdF2Eighth, $sqF2);
            $nF2 = $context->builder->siToFp($n, $f64);
            $sixtythirdF2 = $context->builder->fmul($sixtythirdF2, $nF2);
            $sixtythirdF2 = $context->builder->fmul($sixtythirdF2, $nF2);
            $sixtythirdF2 = $context->builder->fmul($sixtythirdF2, $nF2);
            $sixtythirdF2 = $context->builder->fmul($sixtythirdF2, $nF2);
            $sixtythirdF2 = $context->builder->fmul($sixtythirdF2, $nF2);
            $sixtythirdF2 = $context->builder->fmul($sixtythirdF2, $nF2);
            $sixtythirdF2 = $context->builder->fmul($sixtythirdF2, $nF2);
            $sixtythirdF2 = $context->builder->fmul($sixtythirdF2, $nF2);
            $sixtythirdF2 = $context->builder->fmul($sixtythirdF2, $nF2);
            $sixtythirdF2 = $context->builder->fmul($sixtythirdF2, $nF2);
                $sixtythirdF2 = $context->builder->fmul($sixtythirdF2, $nF2);
                    $sixtythirdF2 = $context->builder->fmul($sixtythirdF2, $nF2);
                        $sixtythirdF2 = $context->builder->fmul($sixtythirdF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtythirdF2
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
                throw new \LogicException('pow() **63 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_sixtythird_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_sixtythird_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $twentiethF3 = $context->builder->fmul($tenthF3, $tenthF3);
            $fortiethF3 = $context->builder->fmul($twentiethF3, $twentiethF3);
            $sqF3 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF3 = $context->builder->fmul($fortiethF3, $sqF3);
            $fortyfourthF3 = $context->builder->fmul($fortysecondF3, $sqF3);
            $sixtythirdF3Sq = $context->builder->fmul($fortyfourthF3, $sqF3);
            $sixtythirdF3Eighth = $context->builder->fmul($sixtythirdF3Sq, $sqF3);
            $sixtythirdF3 = $context->builder->fmul($sixtythirdF3Eighth, $sqF3);
            $nF3 = $context->builder->siToFp($n, $f64);
            $sixtythirdF3 = $context->builder->fmul($sixtythirdF3, $nF3);
            $sixtythirdF3 = $context->builder->fmul($sixtythirdF3, $nF3);
            $sixtythirdF3 = $context->builder->fmul($sixtythirdF3, $nF3);
            $sixtythirdF3 = $context->builder->fmul($sixtythirdF3, $nF3);
            $sixtythirdF3 = $context->builder->fmul($sixtythirdF3, $nF3);
            $sixtythirdF3 = $context->builder->fmul($sixtythirdF3, $nF3);
            $sixtythirdF3 = $context->builder->fmul($sixtythirdF3, $nF3);
            $sixtythirdF3 = $context->builder->fmul($sixtythirdF3, $nF3);
            $sixtythirdF3 = $context->builder->fmul($sixtythirdF3, $nF3);
            $sixtythirdF3 = $context->builder->fmul($sixtythirdF3, $nF3);
                $sixtythirdF3 = $context->builder->fmul($sixtythirdF3, $nF3);
                    $sixtythirdF3 = $context->builder->fmul($sixtythirdF3, $nF3);
                        $sixtythirdF3 = $context->builder->fmul($sixtythirdF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtythirdF3
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
                throw new \LogicException('pow() **63 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_sixtythird_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_sixtythird_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF4 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $twentiethF4 = $context->builder->fmul($tenthF4, $tenthF4);
            $fortiethF4 = $context->builder->fmul($twentiethF4, $twentiethF4);
            $sqF4 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF4 = $context->builder->fmul($fortiethF4, $sqF4);
            $fortyfourthF4 = $context->builder->fmul($fortysecondF4, $sqF4);
            $sixtythirdF4Sq = $context->builder->fmul($fortyfourthF4, $sqF4);
            $sixtythirdF4Eighth = $context->builder->fmul($sixtythirdF4Sq, $sqF4);
            $sixtythirdF4 = $context->builder->fmul($sixtythirdF4Eighth, $sqF4);
            $nF4 = $context->builder->siToFp($n, $f64);
            $sixtythirdF4 = $context->builder->fmul($sixtythirdF4, $nF4);
            $sixtythirdF4 = $context->builder->fmul($sixtythirdF4, $nF4);
            $sixtythirdF4 = $context->builder->fmul($sixtythirdF4, $nF4);
            $sixtythirdF4 = $context->builder->fmul($sixtythirdF4, $nF4);
            $sixtythirdF4 = $context->builder->fmul($sixtythirdF4, $nF4);
            $sixtythirdF4 = $context->builder->fmul($sixtythirdF4, $nF4);
            $sixtythirdF4 = $context->builder->fmul($sixtythirdF4, $nF4);
            $sixtythirdF4 = $context->builder->fmul($sixtythirdF4, $nF4);
            $sixtythirdF4 = $context->builder->fmul($sixtythirdF4, $nF4);
            $sixtythirdF4 = $context->builder->fmul($sixtythirdF4, $nF4);
                $sixtythirdF4 = $context->builder->fmul($sixtythirdF4, $nF4);
                    $sixtythirdF4 = $context->builder->fmul($sixtythirdF4, $nF4);
                        $sixtythirdF4 = $context->builder->fmul($sixtythirdF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtythirdF4
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
                throw new \LogicException('pow() **63 expected smul overflow metadata (twentieth)');
            }
            $twentiethLong = JITVariable::KIND_VARIABLE === $twentiethVar->kind
                ? $context->builder->load($twentiethVar->value)
                : $twentiethVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_sixtythird_twentieth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_sixtythird_twentieth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $twentiethF5 = $context->builder->load($twentiethVar->longArithOverflowDoubleSlot);
            $fortiethF5 = $context->builder->fmul($twentiethF5, $twentiethF5);
            $sqF5 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF5 = $context->builder->fmul($fortiethF5, $sqF5);
            $fortyfourthF5 = $context->builder->fmul($fortysecondF5, $sqF5);
            $sixtythirdF5Sq = $context->builder->fmul($fortyfourthF5, $sqF5);
            $sixtythirdF5Eighth = $context->builder->fmul($sixtythirdF5Sq, $sqF5);
            $sixtythirdF5 = $context->builder->fmul($sixtythirdF5Eighth, $sqF5);
            $nF5 = $context->builder->siToFp($n, $f64);
            $sixtythirdF5 = $context->builder->fmul($sixtythirdF5, $nF5);
            $sixtythirdF5 = $context->builder->fmul($sixtythirdF5, $nF5);
            $sixtythirdF5 = $context->builder->fmul($sixtythirdF5, $nF5);
            $sixtythirdF5 = $context->builder->fmul($sixtythirdF5, $nF5);
            $sixtythirdF5 = $context->builder->fmul($sixtythirdF5, $nF5);
            $sixtythirdF5 = $context->builder->fmul($sixtythirdF5, $nF5);
            $sixtythirdF5 = $context->builder->fmul($sixtythirdF5, $nF5);
            $sixtythirdF5 = $context->builder->fmul($sixtythirdF5, $nF5);
            $sixtythirdF5 = $context->builder->fmul($sixtythirdF5, $nF5);
            $sixtythirdF5 = $context->builder->fmul($sixtythirdF5, $nF5);
                $sixtythirdF5 = $context->builder->fmul($sixtythirdF5, $nF5);
                    $sixtythirdF5 = $context->builder->fmul($sixtythirdF5, $nF5);
                        $sixtythirdF5 = $context->builder->fmul($sixtythirdF5, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtythirdF5
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
                throw new \LogicException('pow() **63 expected smul overflow metadata (fortieth)');
            }
            $fortiethLong = JITVariable::KIND_VARIABLE === $fortiethVar->kind
                ? $context->builder->load($fortiethVar->value)
                : $fortiethVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_sixtythird_fortieth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_sixtythird_fortieth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $fortiethF6 = $context->builder->load($fortiethVar->longArithOverflowDoubleSlot);
            $sqF6 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF6 = $context->builder->fmul($fortiethF6, $sqF6);
            $fortyfourthF6 = $context->builder->fmul($fortysecondF6, $sqF6);
            $sixtythirdF6Sq = $context->builder->fmul($fortyfourthF6, $sqF6);
            $sixtythirdF6Eighth = $context->builder->fmul($sixtythirdF6Sq, $sqF6);
            $sixtythirdF6 = $context->builder->fmul($sixtythirdF6Eighth, $sqF6);
            $nF6 = $context->builder->siToFp($n, $f64);
            $sixtythirdF6 = $context->builder->fmul($sixtythirdF6, $nF6);
            $sixtythirdF6 = $context->builder->fmul($sixtythirdF6, $nF6);
            $sixtythirdF6 = $context->builder->fmul($sixtythirdF6, $nF6);
            $sixtythirdF6 = $context->builder->fmul($sixtythirdF6, $nF6);
            $sixtythirdF6 = $context->builder->fmul($sixtythirdF6, $nF6);
            $sixtythirdF6 = $context->builder->fmul($sixtythirdF6, $nF6);
            $sixtythirdF6 = $context->builder->fmul($sixtythirdF6, $nF6);
            $sixtythirdF6 = $context->builder->fmul($sixtythirdF6, $nF6);
            $sixtythirdF6 = $context->builder->fmul($sixtythirdF6, $nF6);
            $sixtythirdF6 = $context->builder->fmul($sixtythirdF6, $nF6);
                $sixtythirdF6 = $context->builder->fmul($sixtythirdF6, $nF6);
                    $sixtythirdF6 = $context->builder->fmul($sixtythirdF6, $nF6);
                        $sixtythirdF6 = $context->builder->fmul($sixtythirdF6, $nF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtythirdF6
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
                throw new \LogicException('pow() **63 expected smul overflow metadata (fortysecond)');
            }
            $fortysecondLong = JITVariable::KIND_VARIABLE === $fortysecondVar->kind
                ? $context->builder->load($fortysecondVar->value)
                : $fortysecondVar->value;
            $ov7Block = BasicBlockHelper::append($context, 'pow_sixtythird_fortysecond_ov');
            $ok7Block = BasicBlockHelper::append($context, 'pow_sixtythird_fortysecond_ok');
            $context->builder->branchIf($ov7, $ov7Block, $ok7Block);

            $context->builder->positionAtEnd($ov7Block);
            $fortysecondF7 = $context->builder->load($fortysecondVar->longArithOverflowDoubleSlot);
            $sqF7 = $context->builder->siToFp($sqLong, $f64);
            $fortyfourthF7 = $context->builder->fmul($fortysecondF7, $sqF7);
            $sixtythirdF7Sq = $context->builder->fmul($fortyfourthF7, $sqF7);
            $sixtythirdF7Eighth = $context->builder->fmul($sixtythirdF7Sq, $sqF7);
            $sixtythirdF7 = $context->builder->fmul($sixtythirdF7Eighth, $sqF7);
            $nF7 = $context->builder->siToFp($n, $f64);
            $sixtythirdF7 = $context->builder->fmul($sixtythirdF7, $nF7);
            $sixtythirdF7 = $context->builder->fmul($sixtythirdF7, $nF7);
            $sixtythirdF7 = $context->builder->fmul($sixtythirdF7, $nF7);
            $sixtythirdF7 = $context->builder->fmul($sixtythirdF7, $nF7);
            $sixtythirdF7 = $context->builder->fmul($sixtythirdF7, $nF7);
            $sixtythirdF7 = $context->builder->fmul($sixtythirdF7, $nF7);
            $sixtythirdF7 = $context->builder->fmul($sixtythirdF7, $nF7);
            $sixtythirdF7 = $context->builder->fmul($sixtythirdF7, $nF7);
            $sixtythirdF7 = $context->builder->fmul($sixtythirdF7, $nF7);
            $sixtythirdF7 = $context->builder->fmul($sixtythirdF7, $nF7);
                $sixtythirdF7 = $context->builder->fmul($sixtythirdF7, $nF7);
                    $sixtythirdF7 = $context->builder->fmul($sixtythirdF7, $nF7);
                        $sixtythirdF7 = $context->builder->fmul($sixtythirdF7, $nF7);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtythirdF7
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
                throw new \LogicException('pow() **63 expected smul overflow metadata (fortyfourth)');
            }
            $fortyfourthLong = JITVariable::KIND_VARIABLE === $fortyfourthVar->kind
                ? $context->builder->load($fortyfourthVar->value)
                : $fortyfourthVar->value;
            $ov8Block = BasicBlockHelper::append($context, 'pow_sixtythird_fortyfourth_ov');
            $ok8Block = BasicBlockHelper::append($context, 'pow_sixtythird_fortyfourth_ok');
            $context->builder->branchIf($ov8, $ov8Block, $ok8Block);

            $context->builder->positionAtEnd($ov8Block);
            $fortyfourthF8 = $context->builder->load($fortyfourthVar->longArithOverflowDoubleSlot);
            $sqF8 = $context->builder->siToFp($sqLong, $f64);
            $sixtythirdF8Sq = $context->builder->fmul($fortyfourthF8, $sqF8);
            $sixtythirdF8Eighth = $context->builder->fmul($sixtythirdF8Sq, $sqF8);
            $sixtythirdF8 = $context->builder->fmul($sixtythirdF8Eighth, $sqF8);
            $nF8 = $context->builder->siToFp($n, $f64);
            $sixtythirdF8 = $context->builder->fmul($sixtythirdF8, $nF8);
            $sixtythirdF8 = $context->builder->fmul($sixtythirdF8, $nF8);
            $sixtythirdF8 = $context->builder->fmul($sixtythirdF8, $nF8);
            $sixtythirdF8 = $context->builder->fmul($sixtythirdF8, $nF8);
            $sixtythirdF8 = $context->builder->fmul($sixtythirdF8, $nF8);
            $sixtythirdF8 = $context->builder->fmul($sixtythirdF8, $nF8);
            $sixtythirdF8 = $context->builder->fmul($sixtythirdF8, $nF8);
            $sixtythirdF8 = $context->builder->fmul($sixtythirdF8, $nF8);
            $sixtythirdF8 = $context->builder->fmul($sixtythirdF8, $nF8);
            $sixtythirdF8 = $context->builder->fmul($sixtythirdF8, $nF8);
                $sixtythirdF8 = $context->builder->fmul($sixtythirdF8, $nF8);
                    $sixtythirdF8 = $context->builder->fmul($sixtythirdF8, $nF8);
                        $sixtythirdF8 = $context->builder->fmul($sixtythirdF8, $nF8);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtythirdF8
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
                throw new \LogicException('pow() **63 expected smul overflow metadata (fortysixth)');
            }
            $fortysixthLong = JITVariable::KIND_VARIABLE === $fortysixthVar->kind
                ? $context->builder->load($fortysixthVar->value)
                : $fortysixthVar->value;
            $ov9Block = BasicBlockHelper::append($context, 'pow_sixtythird_fortysixth_ov');
            $ok9Block = BasicBlockHelper::append($context, 'pow_sixtythird_fortysixth_ok');
            $context->builder->branchIf($ov9, $ov9Block, $ok9Block);

            $context->builder->positionAtEnd($ov9Block);
            $fortysixthF9 = $context->builder->load($fortysixthVar->longArithOverflowDoubleSlot);
            $sqF9 = $context->builder->siToFp($sqLong, $f64);
            $fortyeighthF9 = $context->builder->fmul($fortysixthF9, $sqF9);
            $sixtythirdF9 = $context->builder->fmul($fortyeighthF9, $sqF9);
            $nF9 = $context->builder->siToFp($n, $f64);
            $sixtythirdF9 = $context->builder->fmul($sixtythirdF9, $nF9);
            $sixtythirdF9 = $context->builder->fmul($sixtythirdF9, $nF9);
            $sixtythirdF9 = $context->builder->fmul($sixtythirdF9, $nF9);
            $sixtythirdF9 = $context->builder->fmul($sixtythirdF9, $nF9);
            $sixtythirdF9 = $context->builder->fmul($sixtythirdF9, $nF9);
            $sixtythirdF9 = $context->builder->fmul($sixtythirdF9, $nF9);
            $sixtythirdF9 = $context->builder->fmul($sixtythirdF9, $nF9);
            $sixtythirdF9 = $context->builder->fmul($sixtythirdF9, $nF9);
            $sixtythirdF9 = $context->builder->fmul($sixtythirdF9, $nF9);
            $sixtythirdF9 = $context->builder->fmul($sixtythirdF9, $nF9);
                $sixtythirdF9 = $context->builder->fmul($sixtythirdF9, $nF9);
                    $sixtythirdF9 = $context->builder->fmul($sixtythirdF9, $nF9);
                        $sixtythirdF9 = $context->builder->fmul($sixtythirdF9, $nF9);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtythirdF9
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
                throw new \LogicException('pow() **63 expected smul overflow metadata (fortyeighth)');
            }
            $fortyeighthLong = JITVariable::KIND_VARIABLE === $fortyeighthVar->kind
                ? $context->builder->load($fortyeighthVar->value)
                : $fortyeighthVar->value;
            $ov10Block = BasicBlockHelper::append($context, 'pow_sixtythird_fortyeighth_ov');
            $ok10Block = BasicBlockHelper::append($context, 'pow_sixtythird_fortyeighth_ok');
            $context->builder->branchIf($ov10, $ov10Block, $ok10Block);

            $context->builder->positionAtEnd($ov10Block);
            $fortyeighthF10 = $context->builder->load($fortyeighthVar->longArithOverflowDoubleSlot);
            $sqF10 = $context->builder->siToFp($sqLong, $f64);
            $sixtythirdF10 = $context->builder->fmul($fortyeighthF10, $sqF10);
            $nF10 = $context->builder->siToFp($n, $f64);
            $sixtythirdF10 = $context->builder->fmul($sixtythirdF10, $nF10);
            $sixtythirdF10 = $context->builder->fmul($sixtythirdF10, $nF10);
            $sixtythirdF10 = $context->builder->fmul($sixtythirdF10, $nF10);
            $sixtythirdF10 = $context->builder->fmul($sixtythirdF10, $nF10);
            $sixtythirdF10 = $context->builder->fmul($sixtythirdF10, $nF10);
            $sixtythirdF10 = $context->builder->fmul($sixtythirdF10, $nF10);
            $sixtythirdF10 = $context->builder->fmul($sixtythirdF10, $nF10);
            $sixtythirdF10 = $context->builder->fmul($sixtythirdF10, $nF10);
            $sixtythirdF10 = $context->builder->fmul($sixtythirdF10, $nF10);
            $sixtythirdF10 = $context->builder->fmul($sixtythirdF10, $nF10);
                $sixtythirdF10 = $context->builder->fmul($sixtythirdF10, $nF10);
                    $sixtythirdF10 = $context->builder->fmul($sixtythirdF10, $nF10);
                        $sixtythirdF10 = $context->builder->fmul($sixtythirdF10, $nF10);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtythirdF10
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok10Block);
            $fiftiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fortyeighthLong,
                $sqLong
            );
            $ov11 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftiethVar->longArithOverflowFlag);
            if (null === $ov11 || null === $fiftiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **63 expected smul overflow metadata (fiftieth)');
            }
            $fiftiethLong = JITVariable::KIND_VARIABLE === $fiftiethVar->kind
                ? $context->builder->load($fiftiethVar->value)
                : $fiftiethVar->value;
            $ov11Block = BasicBlockHelper::append($context, 'pow_sixtythird_fiftieth_ov');
            $ok11Block = BasicBlockHelper::append($context, 'pow_sixtythird_fiftieth_ok');
            $context->builder->branchIf($ov11, $ov11Block, $ok11Block);

            $context->builder->positionAtEnd($ov11Block);
            $fiftiethF11 = $context->builder->load($fiftiethVar->longArithOverflowDoubleSlot);
            $nF11 = $context->builder->siToFp($n, $f64);
            $sixtythirdF11 = $context->builder->fmul($fiftiethF11, $nF11);
            $sixtythirdF11 = $context->builder->fmul($sixtythirdF11, $nF11);
            $sixtythirdF11 = $context->builder->fmul($sixtythirdF11, $nF11);
            $sixtythirdF11 = $context->builder->fmul($sixtythirdF11, $nF11);
            $sixtythirdF11 = $context->builder->fmul($sixtythirdF11, $nF11);
            $sixtythirdF11 = $context->builder->fmul($sixtythirdF11, $nF11);
            $sixtythirdF11 = $context->builder->fmul($sixtythirdF11, $nF11);
            $sixtythirdF11 = $context->builder->fmul($sixtythirdF11, $nF11);
            $sixtythirdF11 = $context->builder->fmul($sixtythirdF11, $nF11);
            $sixtythirdF11 = $context->builder->fmul($sixtythirdF11, $nF11);
                $sixtythirdF11 = $context->builder->fmul($sixtythirdF11, $nF11);
                    $sixtythirdF11 = $context->builder->fmul($sixtythirdF11, $nF11);
                        $sixtythirdF11 = $context->builder->fmul($sixtythirdF11, $nF11);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtythirdF11
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok11Block);
            $fiftyfirstVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftiethLong,
                $n
            );
            $ov12 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyfirstVar->longArithOverflowFlag);
            if (null === $ov12 || null === $fiftyfirstVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **63 expected smul overflow metadata (fiftyfirst)');
            }
            $fiftyfirstLong = JITVariable::KIND_VARIABLE === $fiftyfirstVar->kind
                ? $context->builder->load($fiftyfirstVar->value)
                : $fiftyfirstVar->value;
            $ov12Block = BasicBlockHelper::append($context, 'pow_sixtythird_fiftyfirst_ov');
            $ok12Block = BasicBlockHelper::append($context, 'pow_sixtythird_fiftyfirst_ok');
            $context->builder->branchIf($ov12, $ov12Block, $ok12Block);

            $context->builder->positionAtEnd($ov12Block);
            $fiftyfirstF12 = $context->builder->load($fiftyfirstVar->longArithOverflowDoubleSlot);
            $nF12 = $context->builder->siToFp($n, $f64);
            $sixtythirdF12 = $context->builder->fmul($fiftyfirstF12, $nF12);
            $sixtythirdF12 = $context->builder->fmul($sixtythirdF12, $nF12);
            $sixtythirdF12 = $context->builder->fmul($sixtythirdF12, $nF12);
            $sixtythirdF12 = $context->builder->fmul($sixtythirdF12, $nF12);
            $sixtythirdF12 = $context->builder->fmul($sixtythirdF12, $nF12);
            $sixtythirdF12 = $context->builder->fmul($sixtythirdF12, $nF12);
            $sixtythirdF12 = $context->builder->fmul($sixtythirdF12, $nF12);
            $sixtythirdF12 = $context->builder->fmul($sixtythirdF12, $nF12);
            $sixtythirdF12 = $context->builder->fmul($sixtythirdF12, $nF12);
                $sixtythirdF12 = $context->builder->fmul($sixtythirdF12, $nF12);
                    $sixtythirdF12 = $context->builder->fmul($sixtythirdF12, $nF12);
                        $sixtythirdF12 = $context->builder->fmul($sixtythirdF12, $nF12);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtythirdF12
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok12Block);
            $fiftysecondVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyfirstLong,
                $n
            );
            $ov13 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftysecondVar->longArithOverflowFlag);
            if (null === $ov13 || null === $fiftysecondVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **63 expected smul overflow metadata (fiftysecond)');
            }
            $fiftysecondLong = JITVariable::KIND_VARIABLE === $fiftysecondVar->kind
                ? $context->builder->load($fiftysecondVar->value)
                : $fiftysecondVar->value;
            $ov13Block = BasicBlockHelper::append($context, 'pow_sixtythird_fiftysecond_ov');
            $ok13Block = BasicBlockHelper::append($context, 'pow_sixtythird_fiftysecond_ok');
            $context->builder->branchIf($ov13, $ov13Block, $ok13Block);

            $context->builder->positionAtEnd($ov13Block);
            $fiftysecondF13 = $context->builder->load($fiftysecondVar->longArithOverflowDoubleSlot);
            $nF13 = $context->builder->siToFp($n, $f64);
            $sixtythirdF13 = $context->builder->fmul($fiftysecondF13, $nF13);
            $sixtythirdF13 = $context->builder->fmul($sixtythirdF13, $nF13);
            $sixtythirdF13 = $context->builder->fmul($sixtythirdF13, $nF13);
            $sixtythirdF13 = $context->builder->fmul($sixtythirdF13, $nF13);
            $sixtythirdF13 = $context->builder->fmul($sixtythirdF13, $nF13);
            $sixtythirdF13 = $context->builder->fmul($sixtythirdF13, $nF13);
            $sixtythirdF13 = $context->builder->fmul($sixtythirdF13, $nF13);
            $sixtythirdF13 = $context->builder->fmul($sixtythirdF13, $nF13);
                $sixtythirdF13 = $context->builder->fmul($sixtythirdF13, $nF13);
                    $sixtythirdF13 = $context->builder->fmul($sixtythirdF13, $nF13);
                        $sixtythirdF13 = $context->builder->fmul($sixtythirdF13, $nF13);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtythirdF13
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok13Block);
            $fiftythirdVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftysecondLong,
                $n
            );
            $ov14 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftythirdVar->longArithOverflowFlag);
            if (null === $ov14 || null === $fiftythirdVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **63 expected smul overflow metadata (fiftythird)');
            }
            $fiftythirdLong = JITVariable::KIND_VARIABLE === $fiftythirdVar->kind
                ? $context->builder->load($fiftythirdVar->value)
                : $fiftythirdVar->value;
            $ov14Block = BasicBlockHelper::append($context, 'pow_sixtythird_fiftythird_ov');
            $ok14Block = BasicBlockHelper::append($context, 'pow_sixtythird_fiftythird_ok');
            $context->builder->branchIf($ov14, $ov14Block, $ok14Block);

            $context->builder->positionAtEnd($ov14Block);
            $fiftythirdF14 = $context->builder->load($fiftythirdVar->longArithOverflowDoubleSlot);
            $nF14 = $context->builder->siToFp($n, $f64);
            $sixtythirdF14 = $context->builder->fmul($fiftythirdF14, $nF14);
            $sixtythirdF14 = $context->builder->fmul($sixtythirdF14, $nF14);
            $sixtythirdF14 = $context->builder->fmul($sixtythirdF14, $nF14);
            $sixtythirdF14 = $context->builder->fmul($sixtythirdF14, $nF14);
            $sixtythirdF14 = $context->builder->fmul($sixtythirdF14, $nF14);
            $sixtythirdF14 = $context->builder->fmul($sixtythirdF14, $nF14);
            $sixtythirdF14 = $context->builder->fmul($sixtythirdF14, $nF14);
                $sixtythirdF14 = $context->builder->fmul($sixtythirdF14, $nF14);
                    $sixtythirdF14 = $context->builder->fmul($sixtythirdF14, $nF14);
                        $sixtythirdF14 = $context->builder->fmul($sixtythirdF14, $nF14);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtythirdF14
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok14Block);
            $fiftyfourthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftythirdLong,
                $n
            );
            $ov15 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyfourthVar->longArithOverflowFlag);
            if (null === $ov15 || null === $fiftyfourthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **63 expected smul overflow metadata (fiftyfourth)');
            }
            $fiftyfourthLong = JITVariable::KIND_VARIABLE === $fiftyfourthVar->kind
                ? $context->builder->load($fiftyfourthVar->value)
                : $fiftyfourthVar->value;
            $ov15Block = BasicBlockHelper::append($context, 'pow_sixtythird_fiftyfourth_ov');
            $ok15Block = BasicBlockHelper::append($context, 'pow_sixtythird_fiftyfourth_ok');
            $context->builder->branchIf($ov15, $ov15Block, $ok15Block);

            $context->builder->positionAtEnd($ov15Block);
            $fiftyfourthF15 = $context->builder->load($fiftyfourthVar->longArithOverflowDoubleSlot);
            $nF15 = $context->builder->siToFp($n, $f64);
            $sixtythirdF15 = $context->builder->fmul($fiftyfourthF15, $nF15);
            $sixtythirdF15 = $context->builder->fmul($sixtythirdF15, $nF15);
            $sixtythirdF15 = $context->builder->fmul($sixtythirdF15, $nF15);
            $sixtythirdF15 = $context->builder->fmul($sixtythirdF15, $nF15);
            $sixtythirdF15 = $context->builder->fmul($sixtythirdF15, $nF15);
            $sixtythirdF15 = $context->builder->fmul($sixtythirdF15, $nF15);
                $sixtythirdF15 = $context->builder->fmul($sixtythirdF15, $nF15);
                    $sixtythirdF15 = $context->builder->fmul($sixtythirdF15, $nF15);
                        $sixtythirdF15 = $context->builder->fmul($sixtythirdF15, $nF15);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtythirdF15
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok15Block);
            $fiftyfifthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyfourthLong,
                $n
            );
            $ov16 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyfifthVar->longArithOverflowFlag);
            if (null === $ov16 || null === $fiftyfifthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **63 expected smul overflow metadata (fiftyfifth)');
            }
            $fiftyfifthLong = JITVariable::KIND_VARIABLE === $fiftyfifthVar->kind
                ? $context->builder->load($fiftyfifthVar->value)
                : $fiftyfifthVar->value;
            $ov16Block = BasicBlockHelper::append($context, 'pow_sixtythird_fiftyfifth_ov');
            $ok16Block = BasicBlockHelper::append($context, 'pow_sixtythird_fiftyfifth_ok');
            $context->builder->branchIf($ov16, $ov16Block, $ok16Block);

            $context->builder->positionAtEnd($ov16Block);
            $fiftyfifthF16 = $context->builder->load($fiftyfifthVar->longArithOverflowDoubleSlot);
            $nF16 = $context->builder->siToFp($n, $f64);
            $sixtythirdF16 = $context->builder->fmul($fiftyfifthF16, $nF16);
            $sixtythirdF16 = $context->builder->fmul($sixtythirdF16, $nF16);
            $sixtythirdF16 = $context->builder->fmul($sixtythirdF16, $nF16);
            $sixtythirdF16 = $context->builder->fmul($sixtythirdF16, $nF16);
            $sixtythirdF16 = $context->builder->fmul($sixtythirdF16, $nF16);
                $sixtythirdF16 = $context->builder->fmul($sixtythirdF16, $nF16);
                    $sixtythirdF16 = $context->builder->fmul($sixtythirdF16, $nF16);
                        $sixtythirdF16 = $context->builder->fmul($sixtythirdF16, $nF16);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtythirdF16
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok16Block);
            $fiftysixthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyfifthLong,
                $n
            );
            $ov17 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftysixthVar->longArithOverflowFlag);
            if (null === $ov17 || null === $fiftysixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **63 expected smul overflow metadata (fiftysixth)');
            }
            $fiftysixthLong = JITVariable::KIND_VARIABLE === $fiftysixthVar->kind
                ? $context->builder->load($fiftysixthVar->value)
                : $fiftysixthVar->value;
            $ov17Block = BasicBlockHelper::append($context, 'pow_sixtythird_fiftysixth_ov');
            $ok17Block = BasicBlockHelper::append($context, 'pow_sixtythird_fiftysixth_ok');
            $context->builder->branchIf($ov17, $ov17Block, $ok17Block);

            $context->builder->positionAtEnd($ov17Block);
            $fiftysixthF17 = $context->builder->load($fiftysixthVar->longArithOverflowDoubleSlot);
            $nF17 = $context->builder->siToFp($n, $f64);
            $sixtythirdF17 = $context->builder->fmul($fiftysixthF17, $nF17);
            $sixtythirdF17 = $context->builder->fmul($sixtythirdF17, $nF17);
            $sixtythirdF17 = $context->builder->fmul($sixtythirdF17, $nF17);
            $sixtythirdF17 = $context->builder->fmul($sixtythirdF17, $nF17);
                $sixtythirdF17 = $context->builder->fmul($sixtythirdF17, $nF17);
                    $sixtythirdF17 = $context->builder->fmul($sixtythirdF17, $nF17);
                        $sixtythirdF17 = $context->builder->fmul($sixtythirdF17, $nF17);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtythirdF17
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok17Block);
            $fiftyseventhVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftysixthLong,
                $n
            );
            $ov18 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyseventhVar->longArithOverflowFlag);
            if (null === $ov18 || null === $fiftyseventhVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **63 expected smul overflow metadata (fiftyseventh)');
            }
            $fiftyseventhLong = JITVariable::KIND_VARIABLE === $fiftyseventhVar->kind
                ? $context->builder->load($fiftyseventhVar->value)
                : $fiftyseventhVar->value;
            $ov18Block = BasicBlockHelper::append($context, 'pow_sixtythird_fiftyseventh_ov');
            $ok18Block = BasicBlockHelper::append($context, 'pow_sixtythird_fiftyseventh_ok');
            $context->builder->branchIf($ov18, $ov18Block, $ok18Block);

            $context->builder->positionAtEnd($ov18Block);
            $fiftyseventhF18 = $context->builder->load($fiftyseventhVar->longArithOverflowDoubleSlot);
            $nF18 = $context->builder->siToFp($n, $f64);
            $sixtythirdF18 = $context->builder->fmul($fiftyseventhF18, $nF18);
            $sixtythirdF18 = $context->builder->fmul($sixtythirdF18, $nF18);
            $sixtythirdF18 = $context->builder->fmul($sixtythirdF18, $nF18);
                $sixtythirdF18 = $context->builder->fmul($sixtythirdF18, $nF18);
                    $sixtythirdF18 = $context->builder->fmul($sixtythirdF18, $nF18);
                        $sixtythirdF18 = $context->builder->fmul($sixtythirdF18, $nF18);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtythirdF18
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok18Block);
            $fiftyeighthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyseventhLong,
                $n
            );
            $ov19 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyeighthVar->longArithOverflowFlag);
            if (null === $ov19 || null === $fiftyeighthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **63 expected smul overflow metadata (fiftyeighth)');
            }
            $fiftyeighthLong = JITVariable::KIND_VARIABLE === $fiftyeighthVar->kind
                ? $context->builder->load($fiftyeighthVar->value)
                : $fiftyeighthVar->value;
            $ov19Block = BasicBlockHelper::append($context, 'pow_sixtythird_fiftyeighth_ov');
            $ok19Block = BasicBlockHelper::append($context, 'pow_sixtythird_fiftyeighth_ok');
            $context->builder->branchIf($ov19, $ov19Block, $ok19Block);

            $context->builder->positionAtEnd($ov19Block);
            $fiftyeighthF19 = $context->builder->load($fiftyeighthVar->longArithOverflowDoubleSlot);
            $nF19 = $context->builder->siToFp($n, $f64);
            $sixtythirdF19 = $context->builder->fmul($fiftyeighthF19, $nF19);
            $sixtythirdF19 = $context->builder->fmul($sixtythirdF19, $nF19);
                $sixtythirdF19 = $context->builder->fmul($sixtythirdF19, $nF19);
                    $sixtythirdF19 = $context->builder->fmul($sixtythirdF19, $nF19);
                        $sixtythirdF19 = $context->builder->fmul($sixtythirdF19, $nF19);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtythirdF19
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok19Block);
            $fiftyninthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyeighthLong,
                $n
            );
            $ov20 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyninthVar->longArithOverflowFlag);
            if (null === $ov20 || null === $fiftyninthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **63 expected smul overflow metadata (fiftyninth)');
            }
            $fiftyninthLong = JITVariable::KIND_VARIABLE === $fiftyninthVar->kind
                ? $context->builder->load($fiftyninthVar->value)
                : $fiftyninthVar->value;
            $ov20Block = BasicBlockHelper::append($context, 'pow_sixtythird_fiftyninth_ov');
            $ok20Block = BasicBlockHelper::append($context, 'pow_sixtythird_fiftyninth_ok');
            $context->builder->branchIf($ov20, $ov20Block, $ok20Block);

            $context->builder->positionAtEnd($ov20Block);
            $fiftyninthF20 = $context->builder->load($fiftyninthVar->longArithOverflowDoubleSlot);
            $nF20 = $context->builder->siToFp($n, $f64);
            $sixtythirdF20 = $context->builder->fmul($fiftyninthF20, $nF20);
                $sixtythirdF20 = $context->builder->fmul($sixtythirdF20, $nF20);
                    $sixtythirdF20 = $context->builder->fmul($sixtythirdF20, $nF20);
                        $sixtythirdF20 = $context->builder->fmul($sixtythirdF20, $nF20);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtythirdF20
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok20Block);
            $sixtiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyninthLong,
                $n
            );
            $ov21 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixtiethVar->longArithOverflowFlag);
            if (null === $ov21 || null === $sixtiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **63 expected smul overflow metadata (sixtieth)');
            }
            $sixtiethLong = JITVariable::KIND_VARIABLE === $sixtiethVar->kind
                ? $context->builder->load($sixtiethVar->value)
                : $sixtiethVar->value;
            $ov21Block = BasicBlockHelper::append($context, 'pow_sixtythird_sixtieth_ov');
            $ok21Block = BasicBlockHelper::append($context, 'pow_sixtythird_sixtieth_ok');
            $context->builder->branchIf($ov21, $ov21Block, $ok21Block);

            $context->builder->positionAtEnd($ov21Block);
            $sixtiethF21 = $context->builder->load($sixtiethVar->longArithOverflowDoubleSlot);
            $nF21 = $context->builder->siToFp($n, $f64);
            $sixtythirdF21 = $context->builder->fmul($sixtiethF21, $nF21);
                $sixtythirdF21 = $context->builder->fmul($sixtythirdF21, $nF21);
                    $sixtythirdF21 = $context->builder->fmul($sixtythirdF21, $nF21);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtythirdF21
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok21Block);
            $sixtyfirstVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixtiethLong,
                $n
            );
            $ov22 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixtyfirstVar->longArithOverflowFlag);
            if (null === $ov22 || null === $sixtyfirstVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **63 expected smul overflow metadata (sixtyfirst)');
            }
            $sixtyfirstLong = JITVariable::KIND_VARIABLE === $sixtyfirstVar->kind
                ? $context->builder->load($sixtyfirstVar->value)
                : $sixtyfirstVar->value;
            $ov22Block = BasicBlockHelper::append($context, 'pow_sixtythird_sixtyfirst_ov');
            $ok22Block = BasicBlockHelper::append($context, 'pow_sixtythird_sixtyfirst_ok');
            $context->builder->branchIf($ov22, $ov22Block, $ok22Block);

            $context->builder->positionAtEnd($ov22Block);
            $sixtyfirstF22 = $context->builder->load($sixtyfirstVar->longArithOverflowDoubleSlot);
            $nF22 = $context->builder->siToFp($n, $f64);
            $sixtythirdF22 = $context->builder->fmul($sixtyfirstF22, $nF22);
                $sixtythirdF22 = $context->builder->fmul($sixtythirdF22, $nF22);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtythirdF22
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok22Block);
            $sixtysecondVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixtyfirstLong,
                $n
            );
            $ov23 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixtysecondVar->longArithOverflowFlag);
            if (null === $ov23 || null === $sixtysecondVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **63 expected smul overflow metadata (sixtysecond)');
            }
            $sixtysecondLong = JITVariable::KIND_VARIABLE === $sixtysecondVar->kind
                ? $context->builder->load($sixtysecondVar->value)
                : $sixtysecondVar->value;
            $ov23Block = BasicBlockHelper::append($context, 'pow_sixtythird_sixtysecond_ov');
            $ok23Block = BasicBlockHelper::append($context, 'pow_sixtythird_sixtysecond_ok');
            $context->builder->branchIf($ov23, $ov23Block, $ok23Block);

            $context->builder->positionAtEnd($ov23Block);
            $sixtysecondF23 = $context->builder->load($sixtysecondVar->longArithOverflowDoubleSlot);
            $nF23 = $context->builder->siToFp($n, $f64);
            $sixtythirdF23 = $context->builder->fmul($sixtysecondF23, $nF23);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtythirdF23
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok23Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $sixtysecondLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);


            $context->builder->positionAtEnd($doneBlock);

            return true;
        }
        if ('sixtyfourth' === $expFold) {
            // n^64 = sixtythird*n = fortyeighth*sq*n*n*n*n*n*n*n*n*n*n*n*n*n: sq=n*n, cu=sq*n, fifth=cu*sq,
            // tenth=fifth*fifth, twentieth=tenth*tenth,
            // fortieth=twentieth*twentieth, fortysecond=fortieth*sq, fortyfourth=fortysecond*sq,
            // fortysixth=fortyfourth*sq, then fortysixth*sq*sq*n*n*n*n*n*n*n*n*n*n*n*n*n.
            // Overflow arms finish in float (sqF^25*nF*nF*nF*nF*nF*nF*nF*nF*nF*nF,
            // ((cuF*sqF)^2)^4*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF*nF, (fifthF^2)^4*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF, tenthF^4*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF,
            // twentiethF^2*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF*nF, fortiethF*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF, fortysecondF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF,
            // fortyfourthF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF*nF, fortysixthF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF).
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
                throw new \LogicException('pow() **64 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_sixtyfourth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_sixtyfourth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_sixtyfourth_done');
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
            $sixtyfourthFSq = $context->builder->fmul($fortyfourthF, $sqF);
            $sixtyfourthFEighth = $context->builder->fmul($sixtyfourthFSq, $sqF);
            $sixtyfourthF = $context->builder->fmul($sixtyfourthFEighth, $sqF);
            $nF = $context->builder->siToFp($n, $f64);
            $sixtyfourthF = $context->builder->fmul($sixtyfourthF, $nF);
            $sixtyfourthF = $context->builder->fmul($sixtyfourthF, $nF);
            $sixtyfourthF = $context->builder->fmul($sixtyfourthF, $nF);
            $sixtyfourthF = $context->builder->fmul($sixtyfourthF, $nF);
            $sixtyfourthF = $context->builder->fmul($sixtyfourthF, $nF);
            $sixtyfourthF = $context->builder->fmul($sixtyfourthF, $nF);
            $sixtyfourthF = $context->builder->fmul($sixtyfourthF, $nF);
            $sixtyfourthF = $context->builder->fmul($sixtyfourthF, $nF);
            $sixtyfourthF = $context->builder->fmul($sixtyfourthF, $nF);
            $sixtyfourthF = $context->builder->fmul($sixtyfourthF, $nF);
                $sixtyfourthF = $context->builder->fmul($sixtyfourthF, $nF);
                    $sixtyfourthF = $context->builder->fmul($sixtyfourthF, $nF);
                        $sixtyfourthF = $context->builder->fmul($sixtyfourthF, $nF);
                        $sixtyfourthF = $context->builder->fmul($sixtyfourthF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfourthF
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
                throw new \LogicException('pow() **64 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_sixtyfourth_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_sixtyfourth_cu_ok');
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
            $sixtyfourthF2Sq = $context->builder->fmul($fortyfourthF2, $sqF2);
            $sixtyfourthF2Eighth = $context->builder->fmul($sixtyfourthF2Sq, $sqF2);
            $sixtyfourthF2 = $context->builder->fmul($sixtyfourthF2Eighth, $sqF2);
            $nF2 = $context->builder->siToFp($n, $f64);
            $sixtyfourthF2 = $context->builder->fmul($sixtyfourthF2, $nF2);
            $sixtyfourthF2 = $context->builder->fmul($sixtyfourthF2, $nF2);
            $sixtyfourthF2 = $context->builder->fmul($sixtyfourthF2, $nF2);
            $sixtyfourthF2 = $context->builder->fmul($sixtyfourthF2, $nF2);
            $sixtyfourthF2 = $context->builder->fmul($sixtyfourthF2, $nF2);
            $sixtyfourthF2 = $context->builder->fmul($sixtyfourthF2, $nF2);
            $sixtyfourthF2 = $context->builder->fmul($sixtyfourthF2, $nF2);
            $sixtyfourthF2 = $context->builder->fmul($sixtyfourthF2, $nF2);
            $sixtyfourthF2 = $context->builder->fmul($sixtyfourthF2, $nF2);
            $sixtyfourthF2 = $context->builder->fmul($sixtyfourthF2, $nF2);
                $sixtyfourthF2 = $context->builder->fmul($sixtyfourthF2, $nF2);
                    $sixtyfourthF2 = $context->builder->fmul($sixtyfourthF2, $nF2);
                        $sixtyfourthF2 = $context->builder->fmul($sixtyfourthF2, $nF2);
                        $sixtyfourthF2 = $context->builder->fmul($sixtyfourthF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfourthF2
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
                throw new \LogicException('pow() **64 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_sixtyfourth_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_sixtyfourth_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $twentiethF3 = $context->builder->fmul($tenthF3, $tenthF3);
            $fortiethF3 = $context->builder->fmul($twentiethF3, $twentiethF3);
            $sqF3 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF3 = $context->builder->fmul($fortiethF3, $sqF3);
            $fortyfourthF3 = $context->builder->fmul($fortysecondF3, $sqF3);
            $sixtyfourthF3Sq = $context->builder->fmul($fortyfourthF3, $sqF3);
            $sixtyfourthF3Eighth = $context->builder->fmul($sixtyfourthF3Sq, $sqF3);
            $sixtyfourthF3 = $context->builder->fmul($sixtyfourthF3Eighth, $sqF3);
            $nF3 = $context->builder->siToFp($n, $f64);
            $sixtyfourthF3 = $context->builder->fmul($sixtyfourthF3, $nF3);
            $sixtyfourthF3 = $context->builder->fmul($sixtyfourthF3, $nF3);
            $sixtyfourthF3 = $context->builder->fmul($sixtyfourthF3, $nF3);
            $sixtyfourthF3 = $context->builder->fmul($sixtyfourthF3, $nF3);
            $sixtyfourthF3 = $context->builder->fmul($sixtyfourthF3, $nF3);
            $sixtyfourthF3 = $context->builder->fmul($sixtyfourthF3, $nF3);
            $sixtyfourthF3 = $context->builder->fmul($sixtyfourthF3, $nF3);
            $sixtyfourthF3 = $context->builder->fmul($sixtyfourthF3, $nF3);
            $sixtyfourthF3 = $context->builder->fmul($sixtyfourthF3, $nF3);
            $sixtyfourthF3 = $context->builder->fmul($sixtyfourthF3, $nF3);
                $sixtyfourthF3 = $context->builder->fmul($sixtyfourthF3, $nF3);
                    $sixtyfourthF3 = $context->builder->fmul($sixtyfourthF3, $nF3);
                        $sixtyfourthF3 = $context->builder->fmul($sixtyfourthF3, $nF3);
                        $sixtyfourthF3 = $context->builder->fmul($sixtyfourthF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfourthF3
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
                throw new \LogicException('pow() **64 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_sixtyfourth_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_sixtyfourth_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF4 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $twentiethF4 = $context->builder->fmul($tenthF4, $tenthF4);
            $fortiethF4 = $context->builder->fmul($twentiethF4, $twentiethF4);
            $sqF4 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF4 = $context->builder->fmul($fortiethF4, $sqF4);
            $fortyfourthF4 = $context->builder->fmul($fortysecondF4, $sqF4);
            $sixtyfourthF4Sq = $context->builder->fmul($fortyfourthF4, $sqF4);
            $sixtyfourthF4Eighth = $context->builder->fmul($sixtyfourthF4Sq, $sqF4);
            $sixtyfourthF4 = $context->builder->fmul($sixtyfourthF4Eighth, $sqF4);
            $nF4 = $context->builder->siToFp($n, $f64);
            $sixtyfourthF4 = $context->builder->fmul($sixtyfourthF4, $nF4);
            $sixtyfourthF4 = $context->builder->fmul($sixtyfourthF4, $nF4);
            $sixtyfourthF4 = $context->builder->fmul($sixtyfourthF4, $nF4);
            $sixtyfourthF4 = $context->builder->fmul($sixtyfourthF4, $nF4);
            $sixtyfourthF4 = $context->builder->fmul($sixtyfourthF4, $nF4);
            $sixtyfourthF4 = $context->builder->fmul($sixtyfourthF4, $nF4);
            $sixtyfourthF4 = $context->builder->fmul($sixtyfourthF4, $nF4);
            $sixtyfourthF4 = $context->builder->fmul($sixtyfourthF4, $nF4);
            $sixtyfourthF4 = $context->builder->fmul($sixtyfourthF4, $nF4);
            $sixtyfourthF4 = $context->builder->fmul($sixtyfourthF4, $nF4);
                $sixtyfourthF4 = $context->builder->fmul($sixtyfourthF4, $nF4);
                    $sixtyfourthF4 = $context->builder->fmul($sixtyfourthF4, $nF4);
                        $sixtyfourthF4 = $context->builder->fmul($sixtyfourthF4, $nF4);
                        $sixtyfourthF4 = $context->builder->fmul($sixtyfourthF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfourthF4
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
                throw new \LogicException('pow() **64 expected smul overflow metadata (twentieth)');
            }
            $twentiethLong = JITVariable::KIND_VARIABLE === $twentiethVar->kind
                ? $context->builder->load($twentiethVar->value)
                : $twentiethVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_sixtyfourth_twentieth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_sixtyfourth_twentieth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $twentiethF5 = $context->builder->load($twentiethVar->longArithOverflowDoubleSlot);
            $fortiethF5 = $context->builder->fmul($twentiethF5, $twentiethF5);
            $sqF5 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF5 = $context->builder->fmul($fortiethF5, $sqF5);
            $fortyfourthF5 = $context->builder->fmul($fortysecondF5, $sqF5);
            $sixtyfourthF5Sq = $context->builder->fmul($fortyfourthF5, $sqF5);
            $sixtyfourthF5Eighth = $context->builder->fmul($sixtyfourthF5Sq, $sqF5);
            $sixtyfourthF5 = $context->builder->fmul($sixtyfourthF5Eighth, $sqF5);
            $nF5 = $context->builder->siToFp($n, $f64);
            $sixtyfourthF5 = $context->builder->fmul($sixtyfourthF5, $nF5);
            $sixtyfourthF5 = $context->builder->fmul($sixtyfourthF5, $nF5);
            $sixtyfourthF5 = $context->builder->fmul($sixtyfourthF5, $nF5);
            $sixtyfourthF5 = $context->builder->fmul($sixtyfourthF5, $nF5);
            $sixtyfourthF5 = $context->builder->fmul($sixtyfourthF5, $nF5);
            $sixtyfourthF5 = $context->builder->fmul($sixtyfourthF5, $nF5);
            $sixtyfourthF5 = $context->builder->fmul($sixtyfourthF5, $nF5);
            $sixtyfourthF5 = $context->builder->fmul($sixtyfourthF5, $nF5);
            $sixtyfourthF5 = $context->builder->fmul($sixtyfourthF5, $nF5);
            $sixtyfourthF5 = $context->builder->fmul($sixtyfourthF5, $nF5);
                $sixtyfourthF5 = $context->builder->fmul($sixtyfourthF5, $nF5);
                    $sixtyfourthF5 = $context->builder->fmul($sixtyfourthF5, $nF5);
                        $sixtyfourthF5 = $context->builder->fmul($sixtyfourthF5, $nF5);
                        $sixtyfourthF5 = $context->builder->fmul($sixtyfourthF5, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfourthF5
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
                throw new \LogicException('pow() **64 expected smul overflow metadata (fortieth)');
            }
            $fortiethLong = JITVariable::KIND_VARIABLE === $fortiethVar->kind
                ? $context->builder->load($fortiethVar->value)
                : $fortiethVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_sixtyfourth_fortieth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_sixtyfourth_fortieth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $fortiethF6 = $context->builder->load($fortiethVar->longArithOverflowDoubleSlot);
            $sqF6 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF6 = $context->builder->fmul($fortiethF6, $sqF6);
            $fortyfourthF6 = $context->builder->fmul($fortysecondF6, $sqF6);
            $sixtyfourthF6Sq = $context->builder->fmul($fortyfourthF6, $sqF6);
            $sixtyfourthF6Eighth = $context->builder->fmul($sixtyfourthF6Sq, $sqF6);
            $sixtyfourthF6 = $context->builder->fmul($sixtyfourthF6Eighth, $sqF6);
            $nF6 = $context->builder->siToFp($n, $f64);
            $sixtyfourthF6 = $context->builder->fmul($sixtyfourthF6, $nF6);
            $sixtyfourthF6 = $context->builder->fmul($sixtyfourthF6, $nF6);
            $sixtyfourthF6 = $context->builder->fmul($sixtyfourthF6, $nF6);
            $sixtyfourthF6 = $context->builder->fmul($sixtyfourthF6, $nF6);
            $sixtyfourthF6 = $context->builder->fmul($sixtyfourthF6, $nF6);
            $sixtyfourthF6 = $context->builder->fmul($sixtyfourthF6, $nF6);
            $sixtyfourthF6 = $context->builder->fmul($sixtyfourthF6, $nF6);
            $sixtyfourthF6 = $context->builder->fmul($sixtyfourthF6, $nF6);
            $sixtyfourthF6 = $context->builder->fmul($sixtyfourthF6, $nF6);
            $sixtyfourthF6 = $context->builder->fmul($sixtyfourthF6, $nF6);
                $sixtyfourthF6 = $context->builder->fmul($sixtyfourthF6, $nF6);
                    $sixtyfourthF6 = $context->builder->fmul($sixtyfourthF6, $nF6);
                        $sixtyfourthF6 = $context->builder->fmul($sixtyfourthF6, $nF6);
                        $sixtyfourthF6 = $context->builder->fmul($sixtyfourthF6, $nF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfourthF6
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
                throw new \LogicException('pow() **64 expected smul overflow metadata (fortysecond)');
            }
            $fortysecondLong = JITVariable::KIND_VARIABLE === $fortysecondVar->kind
                ? $context->builder->load($fortysecondVar->value)
                : $fortysecondVar->value;
            $ov7Block = BasicBlockHelper::append($context, 'pow_sixtyfourth_fortysecond_ov');
            $ok7Block = BasicBlockHelper::append($context, 'pow_sixtyfourth_fortysecond_ok');
            $context->builder->branchIf($ov7, $ov7Block, $ok7Block);

            $context->builder->positionAtEnd($ov7Block);
            $fortysecondF7 = $context->builder->load($fortysecondVar->longArithOverflowDoubleSlot);
            $sqF7 = $context->builder->siToFp($sqLong, $f64);
            $fortyfourthF7 = $context->builder->fmul($fortysecondF7, $sqF7);
            $sixtyfourthF7Sq = $context->builder->fmul($fortyfourthF7, $sqF7);
            $sixtyfourthF7Eighth = $context->builder->fmul($sixtyfourthF7Sq, $sqF7);
            $sixtyfourthF7 = $context->builder->fmul($sixtyfourthF7Eighth, $sqF7);
            $nF7 = $context->builder->siToFp($n, $f64);
            $sixtyfourthF7 = $context->builder->fmul($sixtyfourthF7, $nF7);
            $sixtyfourthF7 = $context->builder->fmul($sixtyfourthF7, $nF7);
            $sixtyfourthF7 = $context->builder->fmul($sixtyfourthF7, $nF7);
            $sixtyfourthF7 = $context->builder->fmul($sixtyfourthF7, $nF7);
            $sixtyfourthF7 = $context->builder->fmul($sixtyfourthF7, $nF7);
            $sixtyfourthF7 = $context->builder->fmul($sixtyfourthF7, $nF7);
            $sixtyfourthF7 = $context->builder->fmul($sixtyfourthF7, $nF7);
            $sixtyfourthF7 = $context->builder->fmul($sixtyfourthF7, $nF7);
            $sixtyfourthF7 = $context->builder->fmul($sixtyfourthF7, $nF7);
            $sixtyfourthF7 = $context->builder->fmul($sixtyfourthF7, $nF7);
                $sixtyfourthF7 = $context->builder->fmul($sixtyfourthF7, $nF7);
                    $sixtyfourthF7 = $context->builder->fmul($sixtyfourthF7, $nF7);
                        $sixtyfourthF7 = $context->builder->fmul($sixtyfourthF7, $nF7);
                        $sixtyfourthF7 = $context->builder->fmul($sixtyfourthF7, $nF7);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfourthF7
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
                throw new \LogicException('pow() **64 expected smul overflow metadata (fortyfourth)');
            }
            $fortyfourthLong = JITVariable::KIND_VARIABLE === $fortyfourthVar->kind
                ? $context->builder->load($fortyfourthVar->value)
                : $fortyfourthVar->value;
            $ov8Block = BasicBlockHelper::append($context, 'pow_sixtyfourth_fortyfourth_ov');
            $ok8Block = BasicBlockHelper::append($context, 'pow_sixtyfourth_fortyfourth_ok');
            $context->builder->branchIf($ov8, $ov8Block, $ok8Block);

            $context->builder->positionAtEnd($ov8Block);
            $fortyfourthF8 = $context->builder->load($fortyfourthVar->longArithOverflowDoubleSlot);
            $sqF8 = $context->builder->siToFp($sqLong, $f64);
            $sixtyfourthF8Sq = $context->builder->fmul($fortyfourthF8, $sqF8);
            $sixtyfourthF8Eighth = $context->builder->fmul($sixtyfourthF8Sq, $sqF8);
            $sixtyfourthF8 = $context->builder->fmul($sixtyfourthF8Eighth, $sqF8);
            $nF8 = $context->builder->siToFp($n, $f64);
            $sixtyfourthF8 = $context->builder->fmul($sixtyfourthF8, $nF8);
            $sixtyfourthF8 = $context->builder->fmul($sixtyfourthF8, $nF8);
            $sixtyfourthF8 = $context->builder->fmul($sixtyfourthF8, $nF8);
            $sixtyfourthF8 = $context->builder->fmul($sixtyfourthF8, $nF8);
            $sixtyfourthF8 = $context->builder->fmul($sixtyfourthF8, $nF8);
            $sixtyfourthF8 = $context->builder->fmul($sixtyfourthF8, $nF8);
            $sixtyfourthF8 = $context->builder->fmul($sixtyfourthF8, $nF8);
            $sixtyfourthF8 = $context->builder->fmul($sixtyfourthF8, $nF8);
            $sixtyfourthF8 = $context->builder->fmul($sixtyfourthF8, $nF8);
            $sixtyfourthF8 = $context->builder->fmul($sixtyfourthF8, $nF8);
                $sixtyfourthF8 = $context->builder->fmul($sixtyfourthF8, $nF8);
                    $sixtyfourthF8 = $context->builder->fmul($sixtyfourthF8, $nF8);
                        $sixtyfourthF8 = $context->builder->fmul($sixtyfourthF8, $nF8);
                        $sixtyfourthF8 = $context->builder->fmul($sixtyfourthF8, $nF8);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfourthF8
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
                throw new \LogicException('pow() **64 expected smul overflow metadata (fortysixth)');
            }
            $fortysixthLong = JITVariable::KIND_VARIABLE === $fortysixthVar->kind
                ? $context->builder->load($fortysixthVar->value)
                : $fortysixthVar->value;
            $ov9Block = BasicBlockHelper::append($context, 'pow_sixtyfourth_fortysixth_ov');
            $ok9Block = BasicBlockHelper::append($context, 'pow_sixtyfourth_fortysixth_ok');
            $context->builder->branchIf($ov9, $ov9Block, $ok9Block);

            $context->builder->positionAtEnd($ov9Block);
            $fortysixthF9 = $context->builder->load($fortysixthVar->longArithOverflowDoubleSlot);
            $sqF9 = $context->builder->siToFp($sqLong, $f64);
            $fortyeighthF9 = $context->builder->fmul($fortysixthF9, $sqF9);
            $sixtyfourthF9 = $context->builder->fmul($fortyeighthF9, $sqF9);
            $nF9 = $context->builder->siToFp($n, $f64);
            $sixtyfourthF9 = $context->builder->fmul($sixtyfourthF9, $nF9);
            $sixtyfourthF9 = $context->builder->fmul($sixtyfourthF9, $nF9);
            $sixtyfourthF9 = $context->builder->fmul($sixtyfourthF9, $nF9);
            $sixtyfourthF9 = $context->builder->fmul($sixtyfourthF9, $nF9);
            $sixtyfourthF9 = $context->builder->fmul($sixtyfourthF9, $nF9);
            $sixtyfourthF9 = $context->builder->fmul($sixtyfourthF9, $nF9);
            $sixtyfourthF9 = $context->builder->fmul($sixtyfourthF9, $nF9);
            $sixtyfourthF9 = $context->builder->fmul($sixtyfourthF9, $nF9);
            $sixtyfourthF9 = $context->builder->fmul($sixtyfourthF9, $nF9);
            $sixtyfourthF9 = $context->builder->fmul($sixtyfourthF9, $nF9);
                $sixtyfourthF9 = $context->builder->fmul($sixtyfourthF9, $nF9);
                    $sixtyfourthF9 = $context->builder->fmul($sixtyfourthF9, $nF9);
                        $sixtyfourthF9 = $context->builder->fmul($sixtyfourthF9, $nF9);
                        $sixtyfourthF9 = $context->builder->fmul($sixtyfourthF9, $nF9);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfourthF9
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
                throw new \LogicException('pow() **64 expected smul overflow metadata (fortyeighth)');
            }
            $fortyeighthLong = JITVariable::KIND_VARIABLE === $fortyeighthVar->kind
                ? $context->builder->load($fortyeighthVar->value)
                : $fortyeighthVar->value;
            $ov10Block = BasicBlockHelper::append($context, 'pow_sixtyfourth_fortyeighth_ov');
            $ok10Block = BasicBlockHelper::append($context, 'pow_sixtyfourth_fortyeighth_ok');
            $context->builder->branchIf($ov10, $ov10Block, $ok10Block);

            $context->builder->positionAtEnd($ov10Block);
            $fortyeighthF10 = $context->builder->load($fortyeighthVar->longArithOverflowDoubleSlot);
            $sqF10 = $context->builder->siToFp($sqLong, $f64);
            $sixtyfourthF10 = $context->builder->fmul($fortyeighthF10, $sqF10);
            $nF10 = $context->builder->siToFp($n, $f64);
            $sixtyfourthF10 = $context->builder->fmul($sixtyfourthF10, $nF10);
            $sixtyfourthF10 = $context->builder->fmul($sixtyfourthF10, $nF10);
            $sixtyfourthF10 = $context->builder->fmul($sixtyfourthF10, $nF10);
            $sixtyfourthF10 = $context->builder->fmul($sixtyfourthF10, $nF10);
            $sixtyfourthF10 = $context->builder->fmul($sixtyfourthF10, $nF10);
            $sixtyfourthF10 = $context->builder->fmul($sixtyfourthF10, $nF10);
            $sixtyfourthF10 = $context->builder->fmul($sixtyfourthF10, $nF10);
            $sixtyfourthF10 = $context->builder->fmul($sixtyfourthF10, $nF10);
            $sixtyfourthF10 = $context->builder->fmul($sixtyfourthF10, $nF10);
            $sixtyfourthF10 = $context->builder->fmul($sixtyfourthF10, $nF10);
                $sixtyfourthF10 = $context->builder->fmul($sixtyfourthF10, $nF10);
                    $sixtyfourthF10 = $context->builder->fmul($sixtyfourthF10, $nF10);
                        $sixtyfourthF10 = $context->builder->fmul($sixtyfourthF10, $nF10);
                        $sixtyfourthF10 = $context->builder->fmul($sixtyfourthF10, $nF10);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfourthF10
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok10Block);
            $fiftiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fortyeighthLong,
                $sqLong
            );
            $ov11 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftiethVar->longArithOverflowFlag);
            if (null === $ov11 || null === $fiftiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **64 expected smul overflow metadata (fiftieth)');
            }
            $fiftiethLong = JITVariable::KIND_VARIABLE === $fiftiethVar->kind
                ? $context->builder->load($fiftiethVar->value)
                : $fiftiethVar->value;
            $ov11Block = BasicBlockHelper::append($context, 'pow_sixtyfourth_fiftieth_ov');
            $ok11Block = BasicBlockHelper::append($context, 'pow_sixtyfourth_fiftieth_ok');
            $context->builder->branchIf($ov11, $ov11Block, $ok11Block);

            $context->builder->positionAtEnd($ov11Block);
            $fiftiethF11 = $context->builder->load($fiftiethVar->longArithOverflowDoubleSlot);
            $nF11 = $context->builder->siToFp($n, $f64);
            $sixtyfourthF11 = $context->builder->fmul($fiftiethF11, $nF11);
            $sixtyfourthF11 = $context->builder->fmul($sixtyfourthF11, $nF11);
            $sixtyfourthF11 = $context->builder->fmul($sixtyfourthF11, $nF11);
            $sixtyfourthF11 = $context->builder->fmul($sixtyfourthF11, $nF11);
            $sixtyfourthF11 = $context->builder->fmul($sixtyfourthF11, $nF11);
            $sixtyfourthF11 = $context->builder->fmul($sixtyfourthF11, $nF11);
            $sixtyfourthF11 = $context->builder->fmul($sixtyfourthF11, $nF11);
            $sixtyfourthF11 = $context->builder->fmul($sixtyfourthF11, $nF11);
            $sixtyfourthF11 = $context->builder->fmul($sixtyfourthF11, $nF11);
            $sixtyfourthF11 = $context->builder->fmul($sixtyfourthF11, $nF11);
                $sixtyfourthF11 = $context->builder->fmul($sixtyfourthF11, $nF11);
                    $sixtyfourthF11 = $context->builder->fmul($sixtyfourthF11, $nF11);
                        $sixtyfourthF11 = $context->builder->fmul($sixtyfourthF11, $nF11);
                        $sixtyfourthF11 = $context->builder->fmul($sixtyfourthF11, $nF11);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfourthF11
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok11Block);
            $fiftyfirstVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftiethLong,
                $n
            );
            $ov12 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyfirstVar->longArithOverflowFlag);
            if (null === $ov12 || null === $fiftyfirstVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **64 expected smul overflow metadata (fiftyfirst)');
            }
            $fiftyfirstLong = JITVariable::KIND_VARIABLE === $fiftyfirstVar->kind
                ? $context->builder->load($fiftyfirstVar->value)
                : $fiftyfirstVar->value;
            $ov12Block = BasicBlockHelper::append($context, 'pow_sixtyfourth_fiftyfirst_ov');
            $ok12Block = BasicBlockHelper::append($context, 'pow_sixtyfourth_fiftyfirst_ok');
            $context->builder->branchIf($ov12, $ov12Block, $ok12Block);

            $context->builder->positionAtEnd($ov12Block);
            $fiftyfirstF12 = $context->builder->load($fiftyfirstVar->longArithOverflowDoubleSlot);
            $nF12 = $context->builder->siToFp($n, $f64);
            $sixtyfourthF12 = $context->builder->fmul($fiftyfirstF12, $nF12);
            $sixtyfourthF12 = $context->builder->fmul($sixtyfourthF12, $nF12);
            $sixtyfourthF12 = $context->builder->fmul($sixtyfourthF12, $nF12);
            $sixtyfourthF12 = $context->builder->fmul($sixtyfourthF12, $nF12);
            $sixtyfourthF12 = $context->builder->fmul($sixtyfourthF12, $nF12);
            $sixtyfourthF12 = $context->builder->fmul($sixtyfourthF12, $nF12);
            $sixtyfourthF12 = $context->builder->fmul($sixtyfourthF12, $nF12);
            $sixtyfourthF12 = $context->builder->fmul($sixtyfourthF12, $nF12);
            $sixtyfourthF12 = $context->builder->fmul($sixtyfourthF12, $nF12);
                $sixtyfourthF12 = $context->builder->fmul($sixtyfourthF12, $nF12);
                    $sixtyfourthF12 = $context->builder->fmul($sixtyfourthF12, $nF12);
                        $sixtyfourthF12 = $context->builder->fmul($sixtyfourthF12, $nF12);
                        $sixtyfourthF12 = $context->builder->fmul($sixtyfourthF12, $nF12);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfourthF12
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok12Block);
            $fiftysecondVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyfirstLong,
                $n
            );
            $ov13 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftysecondVar->longArithOverflowFlag);
            if (null === $ov13 || null === $fiftysecondVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **64 expected smul overflow metadata (fiftysecond)');
            }
            $fiftysecondLong = JITVariable::KIND_VARIABLE === $fiftysecondVar->kind
                ? $context->builder->load($fiftysecondVar->value)
                : $fiftysecondVar->value;
            $ov13Block = BasicBlockHelper::append($context, 'pow_sixtyfourth_fiftysecond_ov');
            $ok13Block = BasicBlockHelper::append($context, 'pow_sixtyfourth_fiftysecond_ok');
            $context->builder->branchIf($ov13, $ov13Block, $ok13Block);

            $context->builder->positionAtEnd($ov13Block);
            $fiftysecondF13 = $context->builder->load($fiftysecondVar->longArithOverflowDoubleSlot);
            $nF13 = $context->builder->siToFp($n, $f64);
            $sixtyfourthF13 = $context->builder->fmul($fiftysecondF13, $nF13);
            $sixtyfourthF13 = $context->builder->fmul($sixtyfourthF13, $nF13);
            $sixtyfourthF13 = $context->builder->fmul($sixtyfourthF13, $nF13);
            $sixtyfourthF13 = $context->builder->fmul($sixtyfourthF13, $nF13);
            $sixtyfourthF13 = $context->builder->fmul($sixtyfourthF13, $nF13);
            $sixtyfourthF13 = $context->builder->fmul($sixtyfourthF13, $nF13);
            $sixtyfourthF13 = $context->builder->fmul($sixtyfourthF13, $nF13);
            $sixtyfourthF13 = $context->builder->fmul($sixtyfourthF13, $nF13);
                $sixtyfourthF13 = $context->builder->fmul($sixtyfourthF13, $nF13);
                    $sixtyfourthF13 = $context->builder->fmul($sixtyfourthF13, $nF13);
                        $sixtyfourthF13 = $context->builder->fmul($sixtyfourthF13, $nF13);
                        $sixtyfourthF13 = $context->builder->fmul($sixtyfourthF13, $nF13);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfourthF13
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok13Block);
            $fiftythirdVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftysecondLong,
                $n
            );
            $ov14 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftythirdVar->longArithOverflowFlag);
            if (null === $ov14 || null === $fiftythirdVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **64 expected smul overflow metadata (fiftythird)');
            }
            $fiftythirdLong = JITVariable::KIND_VARIABLE === $fiftythirdVar->kind
                ? $context->builder->load($fiftythirdVar->value)
                : $fiftythirdVar->value;
            $ov14Block = BasicBlockHelper::append($context, 'pow_sixtyfourth_fiftythird_ov');
            $ok14Block = BasicBlockHelper::append($context, 'pow_sixtyfourth_fiftythird_ok');
            $context->builder->branchIf($ov14, $ov14Block, $ok14Block);

            $context->builder->positionAtEnd($ov14Block);
            $fiftythirdF14 = $context->builder->load($fiftythirdVar->longArithOverflowDoubleSlot);
            $nF14 = $context->builder->siToFp($n, $f64);
            $sixtyfourthF14 = $context->builder->fmul($fiftythirdF14, $nF14);
            $sixtyfourthF14 = $context->builder->fmul($sixtyfourthF14, $nF14);
            $sixtyfourthF14 = $context->builder->fmul($sixtyfourthF14, $nF14);
            $sixtyfourthF14 = $context->builder->fmul($sixtyfourthF14, $nF14);
            $sixtyfourthF14 = $context->builder->fmul($sixtyfourthF14, $nF14);
            $sixtyfourthF14 = $context->builder->fmul($sixtyfourthF14, $nF14);
            $sixtyfourthF14 = $context->builder->fmul($sixtyfourthF14, $nF14);
                $sixtyfourthF14 = $context->builder->fmul($sixtyfourthF14, $nF14);
                    $sixtyfourthF14 = $context->builder->fmul($sixtyfourthF14, $nF14);
                        $sixtyfourthF14 = $context->builder->fmul($sixtyfourthF14, $nF14);
                        $sixtyfourthF14 = $context->builder->fmul($sixtyfourthF14, $nF14);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfourthF14
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok14Block);
            $fiftyfourthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftythirdLong,
                $n
            );
            $ov15 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyfourthVar->longArithOverflowFlag);
            if (null === $ov15 || null === $fiftyfourthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **64 expected smul overflow metadata (fiftyfourth)');
            }
            $fiftyfourthLong = JITVariable::KIND_VARIABLE === $fiftyfourthVar->kind
                ? $context->builder->load($fiftyfourthVar->value)
                : $fiftyfourthVar->value;
            $ov15Block = BasicBlockHelper::append($context, 'pow_sixtyfourth_fiftyfourth_ov');
            $ok15Block = BasicBlockHelper::append($context, 'pow_sixtyfourth_fiftyfourth_ok');
            $context->builder->branchIf($ov15, $ov15Block, $ok15Block);

            $context->builder->positionAtEnd($ov15Block);
            $fiftyfourthF15 = $context->builder->load($fiftyfourthVar->longArithOverflowDoubleSlot);
            $nF15 = $context->builder->siToFp($n, $f64);
            $sixtyfourthF15 = $context->builder->fmul($fiftyfourthF15, $nF15);
            $sixtyfourthF15 = $context->builder->fmul($sixtyfourthF15, $nF15);
            $sixtyfourthF15 = $context->builder->fmul($sixtyfourthF15, $nF15);
            $sixtyfourthF15 = $context->builder->fmul($sixtyfourthF15, $nF15);
            $sixtyfourthF15 = $context->builder->fmul($sixtyfourthF15, $nF15);
            $sixtyfourthF15 = $context->builder->fmul($sixtyfourthF15, $nF15);
                $sixtyfourthF15 = $context->builder->fmul($sixtyfourthF15, $nF15);
                    $sixtyfourthF15 = $context->builder->fmul($sixtyfourthF15, $nF15);
                        $sixtyfourthF15 = $context->builder->fmul($sixtyfourthF15, $nF15);
                        $sixtyfourthF15 = $context->builder->fmul($sixtyfourthF15, $nF15);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfourthF15
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok15Block);
            $fiftyfifthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyfourthLong,
                $n
            );
            $ov16 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyfifthVar->longArithOverflowFlag);
            if (null === $ov16 || null === $fiftyfifthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **64 expected smul overflow metadata (fiftyfifth)');
            }
            $fiftyfifthLong = JITVariable::KIND_VARIABLE === $fiftyfifthVar->kind
                ? $context->builder->load($fiftyfifthVar->value)
                : $fiftyfifthVar->value;
            $ov16Block = BasicBlockHelper::append($context, 'pow_sixtyfourth_fiftyfifth_ov');
            $ok16Block = BasicBlockHelper::append($context, 'pow_sixtyfourth_fiftyfifth_ok');
            $context->builder->branchIf($ov16, $ov16Block, $ok16Block);

            $context->builder->positionAtEnd($ov16Block);
            $fiftyfifthF16 = $context->builder->load($fiftyfifthVar->longArithOverflowDoubleSlot);
            $nF16 = $context->builder->siToFp($n, $f64);
            $sixtyfourthF16 = $context->builder->fmul($fiftyfifthF16, $nF16);
            $sixtyfourthF16 = $context->builder->fmul($sixtyfourthF16, $nF16);
            $sixtyfourthF16 = $context->builder->fmul($sixtyfourthF16, $nF16);
            $sixtyfourthF16 = $context->builder->fmul($sixtyfourthF16, $nF16);
            $sixtyfourthF16 = $context->builder->fmul($sixtyfourthF16, $nF16);
                $sixtyfourthF16 = $context->builder->fmul($sixtyfourthF16, $nF16);
                    $sixtyfourthF16 = $context->builder->fmul($sixtyfourthF16, $nF16);
                        $sixtyfourthF16 = $context->builder->fmul($sixtyfourthF16, $nF16);
                        $sixtyfourthF16 = $context->builder->fmul($sixtyfourthF16, $nF16);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfourthF16
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok16Block);
            $fiftysixthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyfifthLong,
                $n
            );
            $ov17 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftysixthVar->longArithOverflowFlag);
            if (null === $ov17 || null === $fiftysixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **64 expected smul overflow metadata (fiftysixth)');
            }
            $fiftysixthLong = JITVariable::KIND_VARIABLE === $fiftysixthVar->kind
                ? $context->builder->load($fiftysixthVar->value)
                : $fiftysixthVar->value;
            $ov17Block = BasicBlockHelper::append($context, 'pow_sixtyfourth_fiftysixth_ov');
            $ok17Block = BasicBlockHelper::append($context, 'pow_sixtyfourth_fiftysixth_ok');
            $context->builder->branchIf($ov17, $ov17Block, $ok17Block);

            $context->builder->positionAtEnd($ov17Block);
            $fiftysixthF17 = $context->builder->load($fiftysixthVar->longArithOverflowDoubleSlot);
            $nF17 = $context->builder->siToFp($n, $f64);
            $sixtyfourthF17 = $context->builder->fmul($fiftysixthF17, $nF17);
            $sixtyfourthF17 = $context->builder->fmul($sixtyfourthF17, $nF17);
            $sixtyfourthF17 = $context->builder->fmul($sixtyfourthF17, $nF17);
            $sixtyfourthF17 = $context->builder->fmul($sixtyfourthF17, $nF17);
                $sixtyfourthF17 = $context->builder->fmul($sixtyfourthF17, $nF17);
                    $sixtyfourthF17 = $context->builder->fmul($sixtyfourthF17, $nF17);
                        $sixtyfourthF17 = $context->builder->fmul($sixtyfourthF17, $nF17);
                        $sixtyfourthF17 = $context->builder->fmul($sixtyfourthF17, $nF17);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfourthF17
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok17Block);
            $fiftyseventhVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftysixthLong,
                $n
            );
            $ov18 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyseventhVar->longArithOverflowFlag);
            if (null === $ov18 || null === $fiftyseventhVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **64 expected smul overflow metadata (fiftyseventh)');
            }
            $fiftyseventhLong = JITVariable::KIND_VARIABLE === $fiftyseventhVar->kind
                ? $context->builder->load($fiftyseventhVar->value)
                : $fiftyseventhVar->value;
            $ov18Block = BasicBlockHelper::append($context, 'pow_sixtyfourth_fiftyseventh_ov');
            $ok18Block = BasicBlockHelper::append($context, 'pow_sixtyfourth_fiftyseventh_ok');
            $context->builder->branchIf($ov18, $ov18Block, $ok18Block);

            $context->builder->positionAtEnd($ov18Block);
            $fiftyseventhF18 = $context->builder->load($fiftyseventhVar->longArithOverflowDoubleSlot);
            $nF18 = $context->builder->siToFp($n, $f64);
            $sixtyfourthF18 = $context->builder->fmul($fiftyseventhF18, $nF18);
            $sixtyfourthF18 = $context->builder->fmul($sixtyfourthF18, $nF18);
            $sixtyfourthF18 = $context->builder->fmul($sixtyfourthF18, $nF18);
                $sixtyfourthF18 = $context->builder->fmul($sixtyfourthF18, $nF18);
                    $sixtyfourthF18 = $context->builder->fmul($sixtyfourthF18, $nF18);
                        $sixtyfourthF18 = $context->builder->fmul($sixtyfourthF18, $nF18);
                        $sixtyfourthF18 = $context->builder->fmul($sixtyfourthF18, $nF18);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfourthF18
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok18Block);
            $fiftyeighthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyseventhLong,
                $n
            );
            $ov19 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyeighthVar->longArithOverflowFlag);
            if (null === $ov19 || null === $fiftyeighthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **64 expected smul overflow metadata (fiftyeighth)');
            }
            $fiftyeighthLong = JITVariable::KIND_VARIABLE === $fiftyeighthVar->kind
                ? $context->builder->load($fiftyeighthVar->value)
                : $fiftyeighthVar->value;
            $ov19Block = BasicBlockHelper::append($context, 'pow_sixtyfourth_fiftyeighth_ov');
            $ok19Block = BasicBlockHelper::append($context, 'pow_sixtyfourth_fiftyeighth_ok');
            $context->builder->branchIf($ov19, $ov19Block, $ok19Block);

            $context->builder->positionAtEnd($ov19Block);
            $fiftyeighthF19 = $context->builder->load($fiftyeighthVar->longArithOverflowDoubleSlot);
            $nF19 = $context->builder->siToFp($n, $f64);
            $sixtyfourthF19 = $context->builder->fmul($fiftyeighthF19, $nF19);
            $sixtyfourthF19 = $context->builder->fmul($sixtyfourthF19, $nF19);
                $sixtyfourthF19 = $context->builder->fmul($sixtyfourthF19, $nF19);
                    $sixtyfourthF19 = $context->builder->fmul($sixtyfourthF19, $nF19);
                        $sixtyfourthF19 = $context->builder->fmul($sixtyfourthF19, $nF19);
                        $sixtyfourthF19 = $context->builder->fmul($sixtyfourthF19, $nF19);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfourthF19
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok19Block);
            $fiftyninthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyeighthLong,
                $n
            );
            $ov20 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyninthVar->longArithOverflowFlag);
            if (null === $ov20 || null === $fiftyninthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **64 expected smul overflow metadata (fiftyninth)');
            }
            $fiftyninthLong = JITVariable::KIND_VARIABLE === $fiftyninthVar->kind
                ? $context->builder->load($fiftyninthVar->value)
                : $fiftyninthVar->value;
            $ov20Block = BasicBlockHelper::append($context, 'pow_sixtyfourth_fiftyninth_ov');
            $ok20Block = BasicBlockHelper::append($context, 'pow_sixtyfourth_fiftyninth_ok');
            $context->builder->branchIf($ov20, $ov20Block, $ok20Block);

            $context->builder->positionAtEnd($ov20Block);
            $fiftyninthF20 = $context->builder->load($fiftyninthVar->longArithOverflowDoubleSlot);
            $nF20 = $context->builder->siToFp($n, $f64);
            $sixtyfourthF20 = $context->builder->fmul($fiftyninthF20, $nF20);
                $sixtyfourthF20 = $context->builder->fmul($sixtyfourthF20, $nF20);
                    $sixtyfourthF20 = $context->builder->fmul($sixtyfourthF20, $nF20);
                        $sixtyfourthF20 = $context->builder->fmul($sixtyfourthF20, $nF20);
                        $sixtyfourthF20 = $context->builder->fmul($sixtyfourthF20, $nF20);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfourthF20
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok20Block);
            $sixtiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyninthLong,
                $n
            );
            $ov21 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixtiethVar->longArithOverflowFlag);
            if (null === $ov21 || null === $sixtiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **64 expected smul overflow metadata (sixtieth)');
            }
            $sixtiethLong = JITVariable::KIND_VARIABLE === $sixtiethVar->kind
                ? $context->builder->load($sixtiethVar->value)
                : $sixtiethVar->value;
            $ov21Block = BasicBlockHelper::append($context, 'pow_sixtyfourth_sixtieth_ov');
            $ok21Block = BasicBlockHelper::append($context, 'pow_sixtyfourth_sixtieth_ok');
            $context->builder->branchIf($ov21, $ov21Block, $ok21Block);

            $context->builder->positionAtEnd($ov21Block);
            $sixtiethF21 = $context->builder->load($sixtiethVar->longArithOverflowDoubleSlot);
            $nF21 = $context->builder->siToFp($n, $f64);
            $sixtyfourthF21 = $context->builder->fmul($sixtiethF21, $nF21);
                $sixtyfourthF21 = $context->builder->fmul($sixtyfourthF21, $nF21);
                    $sixtyfourthF21 = $context->builder->fmul($sixtyfourthF21, $nF21);
                    $sixtyfourthF21 = $context->builder->fmul($sixtyfourthF21, $nF21);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfourthF21
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok21Block);
            $sixtyfirstVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixtiethLong,
                $n
            );
            $ov22 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixtyfirstVar->longArithOverflowFlag);
            if (null === $ov22 || null === $sixtyfirstVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **64 expected smul overflow metadata (sixtyfirst)');
            }
            $sixtyfirstLong = JITVariable::KIND_VARIABLE === $sixtyfirstVar->kind
                ? $context->builder->load($sixtyfirstVar->value)
                : $sixtyfirstVar->value;
            $ov22Block = BasicBlockHelper::append($context, 'pow_sixtyfourth_sixtyfirst_ov');
            $ok22Block = BasicBlockHelper::append($context, 'pow_sixtyfourth_sixtyfirst_ok');
            $context->builder->branchIf($ov22, $ov22Block, $ok22Block);

            $context->builder->positionAtEnd($ov22Block);
            $sixtyfirstF22 = $context->builder->load($sixtyfirstVar->longArithOverflowDoubleSlot);
            $nF22 = $context->builder->siToFp($n, $f64);
            $sixtyfourthF22 = $context->builder->fmul($sixtyfirstF22, $nF22);
                $sixtyfourthF22 = $context->builder->fmul($sixtyfourthF22, $nF22);
                $sixtyfourthF22 = $context->builder->fmul($sixtyfourthF22, $nF22);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfourthF22
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok22Block);
            $sixtysecondVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixtyfirstLong,
                $n
            );
            $ov23 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixtysecondVar->longArithOverflowFlag);
            if (null === $ov23 || null === $sixtysecondVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **64 expected smul overflow metadata (sixtysecond)');
            }
            $sixtysecondLong = JITVariable::KIND_VARIABLE === $sixtysecondVar->kind
                ? $context->builder->load($sixtysecondVar->value)
                : $sixtysecondVar->value;
            $ov23Block = BasicBlockHelper::append($context, 'pow_sixtyfourth_sixtysecond_ov');
            $ok23Block = BasicBlockHelper::append($context, 'pow_sixtyfourth_sixtysecond_ok');
            $context->builder->branchIf($ov23, $ov23Block, $ok23Block);

            $context->builder->positionAtEnd($ov23Block);
            $sixtysecondF23 = $context->builder->load($sixtysecondVar->longArithOverflowDoubleSlot);
            $nF23 = $context->builder->siToFp($n, $f64);
            $sixtyfourthF23 = $context->builder->fmul($sixtysecondF23, $nF23);
            $sixtyfourthF23 = $context->builder->fmul($sixtyfourthF23, $nF23);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfourthF23
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok23Block);
            $sixtythirdVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixtysecondLong,
                $n
            );
            $ov24 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixtythirdVar->longArithOverflowFlag);
            if (null === $ov24 || null === $sixtythirdVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **64 expected smul overflow metadata (sixtythird)');
            }
            $sixtythirdLong = JITVariable::KIND_VARIABLE === $sixtythirdVar->kind
                ? $context->builder->load($sixtythirdVar->value)
                : $sixtythirdVar->value;
            $ov24Block = BasicBlockHelper::append($context, 'pow_sixtyfourth_sixtythird_ov');
            $ok24Block = BasicBlockHelper::append($context, 'pow_sixtyfourth_sixtythird_ok');
            $context->builder->branchIf($ov24, $ov24Block, $ok24Block);

            $context->builder->positionAtEnd($ov24Block);
            $sixtythirdF24 = $context->builder->load($sixtythirdVar->longArithOverflowDoubleSlot);
            $nF24 = $context->builder->siToFp($n, $f64);
            $sixtyfourthF24 = $context->builder->fmul($sixtythirdF24, $nF24);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfourthF24
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok24Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $sixtythirdLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);


            $context->builder->positionAtEnd($doneBlock);

            return true;
        }

        if ('sixtyfifth' === $expFold) {
            // n^65 = sixtyfourth*n = fortyeighth*sq*n*n*n*n*n*n*n*n*n*n*n*n*n: sq=n*n, cu=sq*n, fifth=cu*sq,
            // tenth=fifth*fifth, twentieth=tenth*tenth,
            // fortieth=twentieth*twentieth, fortysecond=fortieth*sq, fortyfourth=fortysecond*sq,
            // fortysixth=fortyfourth*sq, then fortysixth*sq*sq*n*n*n*n*n*n*n*n*n*n*n*n*n*n.
            // Overflow arms finish in float (sqF^25*nF*nF*nF*nF*nF*nF*nF*nF*nF*nF,
            // ((cuF*sqF)^2)^4*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF*nF, (fifthF^2)^4*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF, tenthF^4*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF,
            // twentiethF^2*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF*nF, fortiethF*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF, fortysecondF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF,
            // fortyfourthF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF*nF, fortysixthF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF*nF).
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
                throw new \LogicException('pow() **65 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_sixtyfifth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_sixtyfifth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_sixtyfifth_done');
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
            $sixtyfifthFSq = $context->builder->fmul($fortyfourthF, $sqF);
            $sixtyfifthFEighth = $context->builder->fmul($sixtyfifthFSq, $sqF);
            $sixtyfifthF = $context->builder->fmul($sixtyfifthFEighth, $sqF);
            $nF = $context->builder->siToFp($n, $f64);
            $sixtyfifthF = $context->builder->fmul($sixtyfifthF, $nF);
            $sixtyfifthF = $context->builder->fmul($sixtyfifthF, $nF);
            $sixtyfifthF = $context->builder->fmul($sixtyfifthF, $nF);
            $sixtyfifthF = $context->builder->fmul($sixtyfifthF, $nF);
            $sixtyfifthF = $context->builder->fmul($sixtyfifthF, $nF);
            $sixtyfifthF = $context->builder->fmul($sixtyfifthF, $nF);
            $sixtyfifthF = $context->builder->fmul($sixtyfifthF, $nF);
            $sixtyfifthF = $context->builder->fmul($sixtyfifthF, $nF);
            $sixtyfifthF = $context->builder->fmul($sixtyfifthF, $nF);
            $sixtyfifthF = $context->builder->fmul($sixtyfifthF, $nF);
                $sixtyfifthF = $context->builder->fmul($sixtyfifthF, $nF);
                    $sixtyfifthF = $context->builder->fmul($sixtyfifthF, $nF);
                        $sixtyfifthF = $context->builder->fmul($sixtyfifthF, $nF);
                        $sixtyfifthF = $context->builder->fmul($sixtyfifthF, $nF);
                        $sixtyfifthF = $context->builder->fmul($sixtyfifthF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfifthF
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
                throw new \LogicException('pow() **65 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_sixtyfifth_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_sixtyfifth_cu_ok');
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
            $sixtyfifthF2Sq = $context->builder->fmul($fortyfourthF2, $sqF2);
            $sixtyfifthF2Eighth = $context->builder->fmul($sixtyfifthF2Sq, $sqF2);
            $sixtyfifthF2 = $context->builder->fmul($sixtyfifthF2Eighth, $sqF2);
            $nF2 = $context->builder->siToFp($n, $f64);
            $sixtyfifthF2 = $context->builder->fmul($sixtyfifthF2, $nF2);
            $sixtyfifthF2 = $context->builder->fmul($sixtyfifthF2, $nF2);
            $sixtyfifthF2 = $context->builder->fmul($sixtyfifthF2, $nF2);
            $sixtyfifthF2 = $context->builder->fmul($sixtyfifthF2, $nF2);
            $sixtyfifthF2 = $context->builder->fmul($sixtyfifthF2, $nF2);
            $sixtyfifthF2 = $context->builder->fmul($sixtyfifthF2, $nF2);
            $sixtyfifthF2 = $context->builder->fmul($sixtyfifthF2, $nF2);
            $sixtyfifthF2 = $context->builder->fmul($sixtyfifthF2, $nF2);
            $sixtyfifthF2 = $context->builder->fmul($sixtyfifthF2, $nF2);
            $sixtyfifthF2 = $context->builder->fmul($sixtyfifthF2, $nF2);
                $sixtyfifthF2 = $context->builder->fmul($sixtyfifthF2, $nF2);
                    $sixtyfifthF2 = $context->builder->fmul($sixtyfifthF2, $nF2);
                        $sixtyfifthF2 = $context->builder->fmul($sixtyfifthF2, $nF2);
                        $sixtyfifthF2 = $context->builder->fmul($sixtyfifthF2, $nF2);
                        $sixtyfifthF2 = $context->builder->fmul($sixtyfifthF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfifthF2
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
                throw new \LogicException('pow() **65 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_sixtyfifth_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_sixtyfifth_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $twentiethF3 = $context->builder->fmul($tenthF3, $tenthF3);
            $fortiethF3 = $context->builder->fmul($twentiethF3, $twentiethF3);
            $sqF3 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF3 = $context->builder->fmul($fortiethF3, $sqF3);
            $fortyfourthF3 = $context->builder->fmul($fortysecondF3, $sqF3);
            $sixtyfifthF3Sq = $context->builder->fmul($fortyfourthF3, $sqF3);
            $sixtyfifthF3Eighth = $context->builder->fmul($sixtyfifthF3Sq, $sqF3);
            $sixtyfifthF3 = $context->builder->fmul($sixtyfifthF3Eighth, $sqF3);
            $nF3 = $context->builder->siToFp($n, $f64);
            $sixtyfifthF3 = $context->builder->fmul($sixtyfifthF3, $nF3);
            $sixtyfifthF3 = $context->builder->fmul($sixtyfifthF3, $nF3);
            $sixtyfifthF3 = $context->builder->fmul($sixtyfifthF3, $nF3);
            $sixtyfifthF3 = $context->builder->fmul($sixtyfifthF3, $nF3);
            $sixtyfifthF3 = $context->builder->fmul($sixtyfifthF3, $nF3);
            $sixtyfifthF3 = $context->builder->fmul($sixtyfifthF3, $nF3);
            $sixtyfifthF3 = $context->builder->fmul($sixtyfifthF3, $nF3);
            $sixtyfifthF3 = $context->builder->fmul($sixtyfifthF3, $nF3);
            $sixtyfifthF3 = $context->builder->fmul($sixtyfifthF3, $nF3);
            $sixtyfifthF3 = $context->builder->fmul($sixtyfifthF3, $nF3);
                $sixtyfifthF3 = $context->builder->fmul($sixtyfifthF3, $nF3);
                    $sixtyfifthF3 = $context->builder->fmul($sixtyfifthF3, $nF3);
                        $sixtyfifthF3 = $context->builder->fmul($sixtyfifthF3, $nF3);
                        $sixtyfifthF3 = $context->builder->fmul($sixtyfifthF3, $nF3);
                        $sixtyfifthF3 = $context->builder->fmul($sixtyfifthF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfifthF3
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
                throw new \LogicException('pow() **65 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_sixtyfifth_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_sixtyfifth_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF4 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $twentiethF4 = $context->builder->fmul($tenthF4, $tenthF4);
            $fortiethF4 = $context->builder->fmul($twentiethF4, $twentiethF4);
            $sqF4 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF4 = $context->builder->fmul($fortiethF4, $sqF4);
            $fortyfourthF4 = $context->builder->fmul($fortysecondF4, $sqF4);
            $sixtyfifthF4Sq = $context->builder->fmul($fortyfourthF4, $sqF4);
            $sixtyfifthF4Eighth = $context->builder->fmul($sixtyfifthF4Sq, $sqF4);
            $sixtyfifthF4 = $context->builder->fmul($sixtyfifthF4Eighth, $sqF4);
            $nF4 = $context->builder->siToFp($n, $f64);
            $sixtyfifthF4 = $context->builder->fmul($sixtyfifthF4, $nF4);
            $sixtyfifthF4 = $context->builder->fmul($sixtyfifthF4, $nF4);
            $sixtyfifthF4 = $context->builder->fmul($sixtyfifthF4, $nF4);
            $sixtyfifthF4 = $context->builder->fmul($sixtyfifthF4, $nF4);
            $sixtyfifthF4 = $context->builder->fmul($sixtyfifthF4, $nF4);
            $sixtyfifthF4 = $context->builder->fmul($sixtyfifthF4, $nF4);
            $sixtyfifthF4 = $context->builder->fmul($sixtyfifthF4, $nF4);
            $sixtyfifthF4 = $context->builder->fmul($sixtyfifthF4, $nF4);
            $sixtyfifthF4 = $context->builder->fmul($sixtyfifthF4, $nF4);
            $sixtyfifthF4 = $context->builder->fmul($sixtyfifthF4, $nF4);
                $sixtyfifthF4 = $context->builder->fmul($sixtyfifthF4, $nF4);
                    $sixtyfifthF4 = $context->builder->fmul($sixtyfifthF4, $nF4);
                        $sixtyfifthF4 = $context->builder->fmul($sixtyfifthF4, $nF4);
                        $sixtyfifthF4 = $context->builder->fmul($sixtyfifthF4, $nF4);
                        $sixtyfifthF4 = $context->builder->fmul($sixtyfifthF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfifthF4
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
                throw new \LogicException('pow() **65 expected smul overflow metadata (twentieth)');
            }
            $twentiethLong = JITVariable::KIND_VARIABLE === $twentiethVar->kind
                ? $context->builder->load($twentiethVar->value)
                : $twentiethVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_sixtyfifth_twentieth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_sixtyfifth_twentieth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $twentiethF5 = $context->builder->load($twentiethVar->longArithOverflowDoubleSlot);
            $fortiethF5 = $context->builder->fmul($twentiethF5, $twentiethF5);
            $sqF5 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF5 = $context->builder->fmul($fortiethF5, $sqF5);
            $fortyfourthF5 = $context->builder->fmul($fortysecondF5, $sqF5);
            $sixtyfifthF5Sq = $context->builder->fmul($fortyfourthF5, $sqF5);
            $sixtyfifthF5Eighth = $context->builder->fmul($sixtyfifthF5Sq, $sqF5);
            $sixtyfifthF5 = $context->builder->fmul($sixtyfifthF5Eighth, $sqF5);
            $nF5 = $context->builder->siToFp($n, $f64);
            $sixtyfifthF5 = $context->builder->fmul($sixtyfifthF5, $nF5);
            $sixtyfifthF5 = $context->builder->fmul($sixtyfifthF5, $nF5);
            $sixtyfifthF5 = $context->builder->fmul($sixtyfifthF5, $nF5);
            $sixtyfifthF5 = $context->builder->fmul($sixtyfifthF5, $nF5);
            $sixtyfifthF5 = $context->builder->fmul($sixtyfifthF5, $nF5);
            $sixtyfifthF5 = $context->builder->fmul($sixtyfifthF5, $nF5);
            $sixtyfifthF5 = $context->builder->fmul($sixtyfifthF5, $nF5);
            $sixtyfifthF5 = $context->builder->fmul($sixtyfifthF5, $nF5);
            $sixtyfifthF5 = $context->builder->fmul($sixtyfifthF5, $nF5);
            $sixtyfifthF5 = $context->builder->fmul($sixtyfifthF5, $nF5);
                $sixtyfifthF5 = $context->builder->fmul($sixtyfifthF5, $nF5);
                    $sixtyfifthF5 = $context->builder->fmul($sixtyfifthF5, $nF5);
                        $sixtyfifthF5 = $context->builder->fmul($sixtyfifthF5, $nF5);
                        $sixtyfifthF5 = $context->builder->fmul($sixtyfifthF5, $nF5);
                        $sixtyfifthF5 = $context->builder->fmul($sixtyfifthF5, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfifthF5
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
                throw new \LogicException('pow() **65 expected smul overflow metadata (fortieth)');
            }
            $fortiethLong = JITVariable::KIND_VARIABLE === $fortiethVar->kind
                ? $context->builder->load($fortiethVar->value)
                : $fortiethVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_sixtyfifth_fortieth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_sixtyfifth_fortieth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $fortiethF6 = $context->builder->load($fortiethVar->longArithOverflowDoubleSlot);
            $sqF6 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF6 = $context->builder->fmul($fortiethF6, $sqF6);
            $fortyfourthF6 = $context->builder->fmul($fortysecondF6, $sqF6);
            $sixtyfifthF6Sq = $context->builder->fmul($fortyfourthF6, $sqF6);
            $sixtyfifthF6Eighth = $context->builder->fmul($sixtyfifthF6Sq, $sqF6);
            $sixtyfifthF6 = $context->builder->fmul($sixtyfifthF6Eighth, $sqF6);
            $nF6 = $context->builder->siToFp($n, $f64);
            $sixtyfifthF6 = $context->builder->fmul($sixtyfifthF6, $nF6);
            $sixtyfifthF6 = $context->builder->fmul($sixtyfifthF6, $nF6);
            $sixtyfifthF6 = $context->builder->fmul($sixtyfifthF6, $nF6);
            $sixtyfifthF6 = $context->builder->fmul($sixtyfifthF6, $nF6);
            $sixtyfifthF6 = $context->builder->fmul($sixtyfifthF6, $nF6);
            $sixtyfifthF6 = $context->builder->fmul($sixtyfifthF6, $nF6);
            $sixtyfifthF6 = $context->builder->fmul($sixtyfifthF6, $nF6);
            $sixtyfifthF6 = $context->builder->fmul($sixtyfifthF6, $nF6);
            $sixtyfifthF6 = $context->builder->fmul($sixtyfifthF6, $nF6);
            $sixtyfifthF6 = $context->builder->fmul($sixtyfifthF6, $nF6);
                $sixtyfifthF6 = $context->builder->fmul($sixtyfifthF6, $nF6);
                    $sixtyfifthF6 = $context->builder->fmul($sixtyfifthF6, $nF6);
                        $sixtyfifthF6 = $context->builder->fmul($sixtyfifthF6, $nF6);
                        $sixtyfifthF6 = $context->builder->fmul($sixtyfifthF6, $nF6);
                        $sixtyfifthF6 = $context->builder->fmul($sixtyfifthF6, $nF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfifthF6
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
                throw new \LogicException('pow() **65 expected smul overflow metadata (fortysecond)');
            }
            $fortysecondLong = JITVariable::KIND_VARIABLE === $fortysecondVar->kind
                ? $context->builder->load($fortysecondVar->value)
                : $fortysecondVar->value;
            $ov7Block = BasicBlockHelper::append($context, 'pow_sixtyfifth_fortysecond_ov');
            $ok7Block = BasicBlockHelper::append($context, 'pow_sixtyfifth_fortysecond_ok');
            $context->builder->branchIf($ov7, $ov7Block, $ok7Block);

            $context->builder->positionAtEnd($ov7Block);
            $fortysecondF7 = $context->builder->load($fortysecondVar->longArithOverflowDoubleSlot);
            $sqF7 = $context->builder->siToFp($sqLong, $f64);
            $fortyfourthF7 = $context->builder->fmul($fortysecondF7, $sqF7);
            $sixtyfifthF7Sq = $context->builder->fmul($fortyfourthF7, $sqF7);
            $sixtyfifthF7Eighth = $context->builder->fmul($sixtyfifthF7Sq, $sqF7);
            $sixtyfifthF7 = $context->builder->fmul($sixtyfifthF7Eighth, $sqF7);
            $nF7 = $context->builder->siToFp($n, $f64);
            $sixtyfifthF7 = $context->builder->fmul($sixtyfifthF7, $nF7);
            $sixtyfifthF7 = $context->builder->fmul($sixtyfifthF7, $nF7);
            $sixtyfifthF7 = $context->builder->fmul($sixtyfifthF7, $nF7);
            $sixtyfifthF7 = $context->builder->fmul($sixtyfifthF7, $nF7);
            $sixtyfifthF7 = $context->builder->fmul($sixtyfifthF7, $nF7);
            $sixtyfifthF7 = $context->builder->fmul($sixtyfifthF7, $nF7);
            $sixtyfifthF7 = $context->builder->fmul($sixtyfifthF7, $nF7);
            $sixtyfifthF7 = $context->builder->fmul($sixtyfifthF7, $nF7);
            $sixtyfifthF7 = $context->builder->fmul($sixtyfifthF7, $nF7);
            $sixtyfifthF7 = $context->builder->fmul($sixtyfifthF7, $nF7);
                $sixtyfifthF7 = $context->builder->fmul($sixtyfifthF7, $nF7);
                    $sixtyfifthF7 = $context->builder->fmul($sixtyfifthF7, $nF7);
                        $sixtyfifthF7 = $context->builder->fmul($sixtyfifthF7, $nF7);
                        $sixtyfifthF7 = $context->builder->fmul($sixtyfifthF7, $nF7);
                        $sixtyfifthF7 = $context->builder->fmul($sixtyfifthF7, $nF7);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfifthF7
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
                throw new \LogicException('pow() **65 expected smul overflow metadata (fortyfourth)');
            }
            $fortyfourthLong = JITVariable::KIND_VARIABLE === $fortyfourthVar->kind
                ? $context->builder->load($fortyfourthVar->value)
                : $fortyfourthVar->value;
            $ov8Block = BasicBlockHelper::append($context, 'pow_sixtyfifth_fortyfourth_ov');
            $ok8Block = BasicBlockHelper::append($context, 'pow_sixtyfifth_fortyfourth_ok');
            $context->builder->branchIf($ov8, $ov8Block, $ok8Block);

            $context->builder->positionAtEnd($ov8Block);
            $fortyfourthF8 = $context->builder->load($fortyfourthVar->longArithOverflowDoubleSlot);
            $sqF8 = $context->builder->siToFp($sqLong, $f64);
            $sixtyfifthF8Sq = $context->builder->fmul($fortyfourthF8, $sqF8);
            $sixtyfifthF8Eighth = $context->builder->fmul($sixtyfifthF8Sq, $sqF8);
            $sixtyfifthF8 = $context->builder->fmul($sixtyfifthF8Eighth, $sqF8);
            $nF8 = $context->builder->siToFp($n, $f64);
            $sixtyfifthF8 = $context->builder->fmul($sixtyfifthF8, $nF8);
            $sixtyfifthF8 = $context->builder->fmul($sixtyfifthF8, $nF8);
            $sixtyfifthF8 = $context->builder->fmul($sixtyfifthF8, $nF8);
            $sixtyfifthF8 = $context->builder->fmul($sixtyfifthF8, $nF8);
            $sixtyfifthF8 = $context->builder->fmul($sixtyfifthF8, $nF8);
            $sixtyfifthF8 = $context->builder->fmul($sixtyfifthF8, $nF8);
            $sixtyfifthF8 = $context->builder->fmul($sixtyfifthF8, $nF8);
            $sixtyfifthF8 = $context->builder->fmul($sixtyfifthF8, $nF8);
            $sixtyfifthF8 = $context->builder->fmul($sixtyfifthF8, $nF8);
            $sixtyfifthF8 = $context->builder->fmul($sixtyfifthF8, $nF8);
                $sixtyfifthF8 = $context->builder->fmul($sixtyfifthF8, $nF8);
                    $sixtyfifthF8 = $context->builder->fmul($sixtyfifthF8, $nF8);
                        $sixtyfifthF8 = $context->builder->fmul($sixtyfifthF8, $nF8);
                        $sixtyfifthF8 = $context->builder->fmul($sixtyfifthF8, $nF8);
                        $sixtyfifthF8 = $context->builder->fmul($sixtyfifthF8, $nF8);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfifthF8
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
                throw new \LogicException('pow() **65 expected smul overflow metadata (fortysixth)');
            }
            $fortysixthLong = JITVariable::KIND_VARIABLE === $fortysixthVar->kind
                ? $context->builder->load($fortysixthVar->value)
                : $fortysixthVar->value;
            $ov9Block = BasicBlockHelper::append($context, 'pow_sixtyfifth_fortysixth_ov');
            $ok9Block = BasicBlockHelper::append($context, 'pow_sixtyfifth_fortysixth_ok');
            $context->builder->branchIf($ov9, $ov9Block, $ok9Block);

            $context->builder->positionAtEnd($ov9Block);
            $fortysixthF9 = $context->builder->load($fortysixthVar->longArithOverflowDoubleSlot);
            $sqF9 = $context->builder->siToFp($sqLong, $f64);
            $fortyeighthF9 = $context->builder->fmul($fortysixthF9, $sqF9);
            $sixtyfifthF9 = $context->builder->fmul($fortyeighthF9, $sqF9);
            $nF9 = $context->builder->siToFp($n, $f64);
            $sixtyfifthF9 = $context->builder->fmul($sixtyfifthF9, $nF9);
            $sixtyfifthF9 = $context->builder->fmul($sixtyfifthF9, $nF9);
            $sixtyfifthF9 = $context->builder->fmul($sixtyfifthF9, $nF9);
            $sixtyfifthF9 = $context->builder->fmul($sixtyfifthF9, $nF9);
            $sixtyfifthF9 = $context->builder->fmul($sixtyfifthF9, $nF9);
            $sixtyfifthF9 = $context->builder->fmul($sixtyfifthF9, $nF9);
            $sixtyfifthF9 = $context->builder->fmul($sixtyfifthF9, $nF9);
            $sixtyfifthF9 = $context->builder->fmul($sixtyfifthF9, $nF9);
            $sixtyfifthF9 = $context->builder->fmul($sixtyfifthF9, $nF9);
            $sixtyfifthF9 = $context->builder->fmul($sixtyfifthF9, $nF9);
                $sixtyfifthF9 = $context->builder->fmul($sixtyfifthF9, $nF9);
                    $sixtyfifthF9 = $context->builder->fmul($sixtyfifthF9, $nF9);
                        $sixtyfifthF9 = $context->builder->fmul($sixtyfifthF9, $nF9);
                        $sixtyfifthF9 = $context->builder->fmul($sixtyfifthF9, $nF9);
                        $sixtyfifthF9 = $context->builder->fmul($sixtyfifthF9, $nF9);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfifthF9
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
                throw new \LogicException('pow() **65 expected smul overflow metadata (fortyeighth)');
            }
            $fortyeighthLong = JITVariable::KIND_VARIABLE === $fortyeighthVar->kind
                ? $context->builder->load($fortyeighthVar->value)
                : $fortyeighthVar->value;
            $ov10Block = BasicBlockHelper::append($context, 'pow_sixtyfifth_fortyeighth_ov');
            $ok10Block = BasicBlockHelper::append($context, 'pow_sixtyfifth_fortyeighth_ok');
            $context->builder->branchIf($ov10, $ov10Block, $ok10Block);

            $context->builder->positionAtEnd($ov10Block);
            $fortyeighthF10 = $context->builder->load($fortyeighthVar->longArithOverflowDoubleSlot);
            $sqF10 = $context->builder->siToFp($sqLong, $f64);
            $sixtyfifthF10 = $context->builder->fmul($fortyeighthF10, $sqF10);
            $nF10 = $context->builder->siToFp($n, $f64);
            $sixtyfifthF10 = $context->builder->fmul($sixtyfifthF10, $nF10);
            $sixtyfifthF10 = $context->builder->fmul($sixtyfifthF10, $nF10);
            $sixtyfifthF10 = $context->builder->fmul($sixtyfifthF10, $nF10);
            $sixtyfifthF10 = $context->builder->fmul($sixtyfifthF10, $nF10);
            $sixtyfifthF10 = $context->builder->fmul($sixtyfifthF10, $nF10);
            $sixtyfifthF10 = $context->builder->fmul($sixtyfifthF10, $nF10);
            $sixtyfifthF10 = $context->builder->fmul($sixtyfifthF10, $nF10);
            $sixtyfifthF10 = $context->builder->fmul($sixtyfifthF10, $nF10);
            $sixtyfifthF10 = $context->builder->fmul($sixtyfifthF10, $nF10);
            $sixtyfifthF10 = $context->builder->fmul($sixtyfifthF10, $nF10);
                $sixtyfifthF10 = $context->builder->fmul($sixtyfifthF10, $nF10);
                    $sixtyfifthF10 = $context->builder->fmul($sixtyfifthF10, $nF10);
                        $sixtyfifthF10 = $context->builder->fmul($sixtyfifthF10, $nF10);
                        $sixtyfifthF10 = $context->builder->fmul($sixtyfifthF10, $nF10);
                        $sixtyfifthF10 = $context->builder->fmul($sixtyfifthF10, $nF10);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfifthF10
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok10Block);
            $fiftiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fortyeighthLong,
                $sqLong
            );
            $ov11 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftiethVar->longArithOverflowFlag);
            if (null === $ov11 || null === $fiftiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **65 expected smul overflow metadata (fiftieth)');
            }
            $fiftiethLong = JITVariable::KIND_VARIABLE === $fiftiethVar->kind
                ? $context->builder->load($fiftiethVar->value)
                : $fiftiethVar->value;
            $ov11Block = BasicBlockHelper::append($context, 'pow_sixtyfifth_fiftieth_ov');
            $ok11Block = BasicBlockHelper::append($context, 'pow_sixtyfifth_fiftieth_ok');
            $context->builder->branchIf($ov11, $ov11Block, $ok11Block);

            $context->builder->positionAtEnd($ov11Block);
            $fiftiethF11 = $context->builder->load($fiftiethVar->longArithOverflowDoubleSlot);
            $nF11 = $context->builder->siToFp($n, $f64);
            $sixtyfifthF11 = $context->builder->fmul($fiftiethF11, $nF11);
            $sixtyfifthF11 = $context->builder->fmul($sixtyfifthF11, $nF11);
            $sixtyfifthF11 = $context->builder->fmul($sixtyfifthF11, $nF11);
            $sixtyfifthF11 = $context->builder->fmul($sixtyfifthF11, $nF11);
            $sixtyfifthF11 = $context->builder->fmul($sixtyfifthF11, $nF11);
            $sixtyfifthF11 = $context->builder->fmul($sixtyfifthF11, $nF11);
            $sixtyfifthF11 = $context->builder->fmul($sixtyfifthF11, $nF11);
            $sixtyfifthF11 = $context->builder->fmul($sixtyfifthF11, $nF11);
            $sixtyfifthF11 = $context->builder->fmul($sixtyfifthF11, $nF11);
            $sixtyfifthF11 = $context->builder->fmul($sixtyfifthF11, $nF11);
                $sixtyfifthF11 = $context->builder->fmul($sixtyfifthF11, $nF11);
                    $sixtyfifthF11 = $context->builder->fmul($sixtyfifthF11, $nF11);
                        $sixtyfifthF11 = $context->builder->fmul($sixtyfifthF11, $nF11);
                        $sixtyfifthF11 = $context->builder->fmul($sixtyfifthF11, $nF11);
                        $sixtyfifthF11 = $context->builder->fmul($sixtyfifthF11, $nF11);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfifthF11
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok11Block);
            $fiftyfirstVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftiethLong,
                $n
            );
            $ov12 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyfirstVar->longArithOverflowFlag);
            if (null === $ov12 || null === $fiftyfirstVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **65 expected smul overflow metadata (fiftyfirst)');
            }
            $fiftyfirstLong = JITVariable::KIND_VARIABLE === $fiftyfirstVar->kind
                ? $context->builder->load($fiftyfirstVar->value)
                : $fiftyfirstVar->value;
            $ov12Block = BasicBlockHelper::append($context, 'pow_sixtyfifth_fiftyfirst_ov');
            $ok12Block = BasicBlockHelper::append($context, 'pow_sixtyfifth_fiftyfirst_ok');
            $context->builder->branchIf($ov12, $ov12Block, $ok12Block);

            $context->builder->positionAtEnd($ov12Block);
            $fiftyfirstF12 = $context->builder->load($fiftyfirstVar->longArithOverflowDoubleSlot);
            $nF12 = $context->builder->siToFp($n, $f64);
            $sixtyfifthF12 = $context->builder->fmul($fiftyfirstF12, $nF12);
            $sixtyfifthF12 = $context->builder->fmul($sixtyfifthF12, $nF12);
            $sixtyfifthF12 = $context->builder->fmul($sixtyfifthF12, $nF12);
            $sixtyfifthF12 = $context->builder->fmul($sixtyfifthF12, $nF12);
            $sixtyfifthF12 = $context->builder->fmul($sixtyfifthF12, $nF12);
            $sixtyfifthF12 = $context->builder->fmul($sixtyfifthF12, $nF12);
            $sixtyfifthF12 = $context->builder->fmul($sixtyfifthF12, $nF12);
            $sixtyfifthF12 = $context->builder->fmul($sixtyfifthF12, $nF12);
            $sixtyfifthF12 = $context->builder->fmul($sixtyfifthF12, $nF12);
                $sixtyfifthF12 = $context->builder->fmul($sixtyfifthF12, $nF12);
                    $sixtyfifthF12 = $context->builder->fmul($sixtyfifthF12, $nF12);
                        $sixtyfifthF12 = $context->builder->fmul($sixtyfifthF12, $nF12);
                        $sixtyfifthF12 = $context->builder->fmul($sixtyfifthF12, $nF12);
                        $sixtyfifthF12 = $context->builder->fmul($sixtyfifthF12, $nF12);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfifthF12
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok12Block);
            $fiftysecondVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyfirstLong,
                $n
            );
            $ov13 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftysecondVar->longArithOverflowFlag);
            if (null === $ov13 || null === $fiftysecondVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **65 expected smul overflow metadata (fiftysecond)');
            }
            $fiftysecondLong = JITVariable::KIND_VARIABLE === $fiftysecondVar->kind
                ? $context->builder->load($fiftysecondVar->value)
                : $fiftysecondVar->value;
            $ov13Block = BasicBlockHelper::append($context, 'pow_sixtyfifth_fiftysecond_ov');
            $ok13Block = BasicBlockHelper::append($context, 'pow_sixtyfifth_fiftysecond_ok');
            $context->builder->branchIf($ov13, $ov13Block, $ok13Block);

            $context->builder->positionAtEnd($ov13Block);
            $fiftysecondF13 = $context->builder->load($fiftysecondVar->longArithOverflowDoubleSlot);
            $nF13 = $context->builder->siToFp($n, $f64);
            $sixtyfifthF13 = $context->builder->fmul($fiftysecondF13, $nF13);
            $sixtyfifthF13 = $context->builder->fmul($sixtyfifthF13, $nF13);
            $sixtyfifthF13 = $context->builder->fmul($sixtyfifthF13, $nF13);
            $sixtyfifthF13 = $context->builder->fmul($sixtyfifthF13, $nF13);
            $sixtyfifthF13 = $context->builder->fmul($sixtyfifthF13, $nF13);
            $sixtyfifthF13 = $context->builder->fmul($sixtyfifthF13, $nF13);
            $sixtyfifthF13 = $context->builder->fmul($sixtyfifthF13, $nF13);
            $sixtyfifthF13 = $context->builder->fmul($sixtyfifthF13, $nF13);
                $sixtyfifthF13 = $context->builder->fmul($sixtyfifthF13, $nF13);
                    $sixtyfifthF13 = $context->builder->fmul($sixtyfifthF13, $nF13);
                        $sixtyfifthF13 = $context->builder->fmul($sixtyfifthF13, $nF13);
                        $sixtyfifthF13 = $context->builder->fmul($sixtyfifthF13, $nF13);
                        $sixtyfifthF13 = $context->builder->fmul($sixtyfifthF13, $nF13);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfifthF13
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok13Block);
            $fiftythirdVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftysecondLong,
                $n
            );
            $ov14 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftythirdVar->longArithOverflowFlag);
            if (null === $ov14 || null === $fiftythirdVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **65 expected smul overflow metadata (fiftythird)');
            }
            $fiftythirdLong = JITVariable::KIND_VARIABLE === $fiftythirdVar->kind
                ? $context->builder->load($fiftythirdVar->value)
                : $fiftythirdVar->value;
            $ov14Block = BasicBlockHelper::append($context, 'pow_sixtyfifth_fiftythird_ov');
            $ok14Block = BasicBlockHelper::append($context, 'pow_sixtyfifth_fiftythird_ok');
            $context->builder->branchIf($ov14, $ov14Block, $ok14Block);

            $context->builder->positionAtEnd($ov14Block);
            $fiftythirdF14 = $context->builder->load($fiftythirdVar->longArithOverflowDoubleSlot);
            $nF14 = $context->builder->siToFp($n, $f64);
            $sixtyfifthF14 = $context->builder->fmul($fiftythirdF14, $nF14);
            $sixtyfifthF14 = $context->builder->fmul($sixtyfifthF14, $nF14);
            $sixtyfifthF14 = $context->builder->fmul($sixtyfifthF14, $nF14);
            $sixtyfifthF14 = $context->builder->fmul($sixtyfifthF14, $nF14);
            $sixtyfifthF14 = $context->builder->fmul($sixtyfifthF14, $nF14);
            $sixtyfifthF14 = $context->builder->fmul($sixtyfifthF14, $nF14);
            $sixtyfifthF14 = $context->builder->fmul($sixtyfifthF14, $nF14);
                $sixtyfifthF14 = $context->builder->fmul($sixtyfifthF14, $nF14);
                    $sixtyfifthF14 = $context->builder->fmul($sixtyfifthF14, $nF14);
                        $sixtyfifthF14 = $context->builder->fmul($sixtyfifthF14, $nF14);
                        $sixtyfifthF14 = $context->builder->fmul($sixtyfifthF14, $nF14);
                        $sixtyfifthF14 = $context->builder->fmul($sixtyfifthF14, $nF14);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfifthF14
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok14Block);
            $fiftyfourthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftythirdLong,
                $n
            );
            $ov15 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyfourthVar->longArithOverflowFlag);
            if (null === $ov15 || null === $fiftyfourthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **65 expected smul overflow metadata (fiftyfourth)');
            }
            $fiftyfourthLong = JITVariable::KIND_VARIABLE === $fiftyfourthVar->kind
                ? $context->builder->load($fiftyfourthVar->value)
                : $fiftyfourthVar->value;
            $ov15Block = BasicBlockHelper::append($context, 'pow_sixtyfifth_fiftyfourth_ov');
            $ok15Block = BasicBlockHelper::append($context, 'pow_sixtyfifth_fiftyfourth_ok');
            $context->builder->branchIf($ov15, $ov15Block, $ok15Block);

            $context->builder->positionAtEnd($ov15Block);
            $fiftyfourthF15 = $context->builder->load($fiftyfourthVar->longArithOverflowDoubleSlot);
            $nF15 = $context->builder->siToFp($n, $f64);
            $sixtyfifthF15 = $context->builder->fmul($fiftyfourthF15, $nF15);
            $sixtyfifthF15 = $context->builder->fmul($sixtyfifthF15, $nF15);
            $sixtyfifthF15 = $context->builder->fmul($sixtyfifthF15, $nF15);
            $sixtyfifthF15 = $context->builder->fmul($sixtyfifthF15, $nF15);
            $sixtyfifthF15 = $context->builder->fmul($sixtyfifthF15, $nF15);
            $sixtyfifthF15 = $context->builder->fmul($sixtyfifthF15, $nF15);
                $sixtyfifthF15 = $context->builder->fmul($sixtyfifthF15, $nF15);
                    $sixtyfifthF15 = $context->builder->fmul($sixtyfifthF15, $nF15);
                        $sixtyfifthF15 = $context->builder->fmul($sixtyfifthF15, $nF15);
                        $sixtyfifthF15 = $context->builder->fmul($sixtyfifthF15, $nF15);
                        $sixtyfifthF15 = $context->builder->fmul($sixtyfifthF15, $nF15);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfifthF15
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok15Block);
            $fiftyfifthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyfourthLong,
                $n
            );
            $ov16 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyfifthVar->longArithOverflowFlag);
            if (null === $ov16 || null === $fiftyfifthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **65 expected smul overflow metadata (fiftyfifth)');
            }
            $fiftyfifthLong = JITVariable::KIND_VARIABLE === $fiftyfifthVar->kind
                ? $context->builder->load($fiftyfifthVar->value)
                : $fiftyfifthVar->value;
            $ov16Block = BasicBlockHelper::append($context, 'pow_sixtyfifth_fiftyfifth_ov');
            $ok16Block = BasicBlockHelper::append($context, 'pow_sixtyfifth_fiftyfifth_ok');
            $context->builder->branchIf($ov16, $ov16Block, $ok16Block);

            $context->builder->positionAtEnd($ov16Block);
            $fiftyfifthF16 = $context->builder->load($fiftyfifthVar->longArithOverflowDoubleSlot);
            $nF16 = $context->builder->siToFp($n, $f64);
            $sixtyfifthF16 = $context->builder->fmul($fiftyfifthF16, $nF16);
            $sixtyfifthF16 = $context->builder->fmul($sixtyfifthF16, $nF16);
            $sixtyfifthF16 = $context->builder->fmul($sixtyfifthF16, $nF16);
            $sixtyfifthF16 = $context->builder->fmul($sixtyfifthF16, $nF16);
            $sixtyfifthF16 = $context->builder->fmul($sixtyfifthF16, $nF16);
                $sixtyfifthF16 = $context->builder->fmul($sixtyfifthF16, $nF16);
                    $sixtyfifthF16 = $context->builder->fmul($sixtyfifthF16, $nF16);
                        $sixtyfifthF16 = $context->builder->fmul($sixtyfifthF16, $nF16);
                        $sixtyfifthF16 = $context->builder->fmul($sixtyfifthF16, $nF16);
                        $sixtyfifthF16 = $context->builder->fmul($sixtyfifthF16, $nF16);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfifthF16
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok16Block);
            $fiftysixthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyfifthLong,
                $n
            );
            $ov17 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftysixthVar->longArithOverflowFlag);
            if (null === $ov17 || null === $fiftysixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **65 expected smul overflow metadata (fiftysixth)');
            }
            $fiftysixthLong = JITVariable::KIND_VARIABLE === $fiftysixthVar->kind
                ? $context->builder->load($fiftysixthVar->value)
                : $fiftysixthVar->value;
            $ov17Block = BasicBlockHelper::append($context, 'pow_sixtyfifth_fiftysixth_ov');
            $ok17Block = BasicBlockHelper::append($context, 'pow_sixtyfifth_fiftysixth_ok');
            $context->builder->branchIf($ov17, $ov17Block, $ok17Block);

            $context->builder->positionAtEnd($ov17Block);
            $fiftysixthF17 = $context->builder->load($fiftysixthVar->longArithOverflowDoubleSlot);
            $nF17 = $context->builder->siToFp($n, $f64);
            $sixtyfifthF17 = $context->builder->fmul($fiftysixthF17, $nF17);
            $sixtyfifthF17 = $context->builder->fmul($sixtyfifthF17, $nF17);
            $sixtyfifthF17 = $context->builder->fmul($sixtyfifthF17, $nF17);
            $sixtyfifthF17 = $context->builder->fmul($sixtyfifthF17, $nF17);
                $sixtyfifthF17 = $context->builder->fmul($sixtyfifthF17, $nF17);
                    $sixtyfifthF17 = $context->builder->fmul($sixtyfifthF17, $nF17);
                        $sixtyfifthF17 = $context->builder->fmul($sixtyfifthF17, $nF17);
                        $sixtyfifthF17 = $context->builder->fmul($sixtyfifthF17, $nF17);
                        $sixtyfifthF17 = $context->builder->fmul($sixtyfifthF17, $nF17);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfifthF17
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok17Block);
            $fiftyseventhVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftysixthLong,
                $n
            );
            $ov18 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyseventhVar->longArithOverflowFlag);
            if (null === $ov18 || null === $fiftyseventhVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **65 expected smul overflow metadata (fiftyseventh)');
            }
            $fiftyseventhLong = JITVariable::KIND_VARIABLE === $fiftyseventhVar->kind
                ? $context->builder->load($fiftyseventhVar->value)
                : $fiftyseventhVar->value;
            $ov18Block = BasicBlockHelper::append($context, 'pow_sixtyfifth_fiftyseventh_ov');
            $ok18Block = BasicBlockHelper::append($context, 'pow_sixtyfifth_fiftyseventh_ok');
            $context->builder->branchIf($ov18, $ov18Block, $ok18Block);

            $context->builder->positionAtEnd($ov18Block);
            $fiftyseventhF18 = $context->builder->load($fiftyseventhVar->longArithOverflowDoubleSlot);
            $nF18 = $context->builder->siToFp($n, $f64);
            $sixtyfifthF18 = $context->builder->fmul($fiftyseventhF18, $nF18);
            $sixtyfifthF18 = $context->builder->fmul($sixtyfifthF18, $nF18);
            $sixtyfifthF18 = $context->builder->fmul($sixtyfifthF18, $nF18);
                $sixtyfifthF18 = $context->builder->fmul($sixtyfifthF18, $nF18);
                    $sixtyfifthF18 = $context->builder->fmul($sixtyfifthF18, $nF18);
                        $sixtyfifthF18 = $context->builder->fmul($sixtyfifthF18, $nF18);
                        $sixtyfifthF18 = $context->builder->fmul($sixtyfifthF18, $nF18);
                        $sixtyfifthF18 = $context->builder->fmul($sixtyfifthF18, $nF18);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfifthF18
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok18Block);
            $fiftyeighthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyseventhLong,
                $n
            );
            $ov19 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyeighthVar->longArithOverflowFlag);
            if (null === $ov19 || null === $fiftyeighthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **65 expected smul overflow metadata (fiftyeighth)');
            }
            $fiftyeighthLong = JITVariable::KIND_VARIABLE === $fiftyeighthVar->kind
                ? $context->builder->load($fiftyeighthVar->value)
                : $fiftyeighthVar->value;
            $ov19Block = BasicBlockHelper::append($context, 'pow_sixtyfifth_fiftyeighth_ov');
            $ok19Block = BasicBlockHelper::append($context, 'pow_sixtyfifth_fiftyeighth_ok');
            $context->builder->branchIf($ov19, $ov19Block, $ok19Block);

            $context->builder->positionAtEnd($ov19Block);
            $fiftyeighthF19 = $context->builder->load($fiftyeighthVar->longArithOverflowDoubleSlot);
            $nF19 = $context->builder->siToFp($n, $f64);
            $sixtyfifthF19 = $context->builder->fmul($fiftyeighthF19, $nF19);
            $sixtyfifthF19 = $context->builder->fmul($sixtyfifthF19, $nF19);
                $sixtyfifthF19 = $context->builder->fmul($sixtyfifthF19, $nF19);
                    $sixtyfifthF19 = $context->builder->fmul($sixtyfifthF19, $nF19);
                        $sixtyfifthF19 = $context->builder->fmul($sixtyfifthF19, $nF19);
                        $sixtyfifthF19 = $context->builder->fmul($sixtyfifthF19, $nF19);
                        $sixtyfifthF19 = $context->builder->fmul($sixtyfifthF19, $nF19);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfifthF19
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok19Block);
            $fiftyninthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyeighthLong,
                $n
            );
            $ov20 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyninthVar->longArithOverflowFlag);
            if (null === $ov20 || null === $fiftyninthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **65 expected smul overflow metadata (fiftyninth)');
            }
            $fiftyninthLong = JITVariable::KIND_VARIABLE === $fiftyninthVar->kind
                ? $context->builder->load($fiftyninthVar->value)
                : $fiftyninthVar->value;
            $ov20Block = BasicBlockHelper::append($context, 'pow_sixtyfifth_fiftyninth_ov');
            $ok20Block = BasicBlockHelper::append($context, 'pow_sixtyfifth_fiftyninth_ok');
            $context->builder->branchIf($ov20, $ov20Block, $ok20Block);

            $context->builder->positionAtEnd($ov20Block);
            $fiftyninthF20 = $context->builder->load($fiftyninthVar->longArithOverflowDoubleSlot);
            $nF20 = $context->builder->siToFp($n, $f64);
            $sixtyfifthF20 = $context->builder->fmul($fiftyninthF20, $nF20);
                $sixtyfifthF20 = $context->builder->fmul($sixtyfifthF20, $nF20);
                    $sixtyfifthF20 = $context->builder->fmul($sixtyfifthF20, $nF20);
                        $sixtyfifthF20 = $context->builder->fmul($sixtyfifthF20, $nF20);
                        $sixtyfifthF20 = $context->builder->fmul($sixtyfifthF20, $nF20);
                        $sixtyfifthF20 = $context->builder->fmul($sixtyfifthF20, $nF20);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfifthF20
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok20Block);
            $sixtiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyninthLong,
                $n
            );
            $ov21 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixtiethVar->longArithOverflowFlag);
            if (null === $ov21 || null === $sixtiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **65 expected smul overflow metadata (sixtieth)');
            }
            $sixtiethLong = JITVariable::KIND_VARIABLE === $sixtiethVar->kind
                ? $context->builder->load($sixtiethVar->value)
                : $sixtiethVar->value;
            $ov21Block = BasicBlockHelper::append($context, 'pow_sixtyfifth_sixtieth_ov');
            $ok21Block = BasicBlockHelper::append($context, 'pow_sixtyfifth_sixtieth_ok');
            $context->builder->branchIf($ov21, $ov21Block, $ok21Block);

            $context->builder->positionAtEnd($ov21Block);
            $sixtiethF21 = $context->builder->load($sixtiethVar->longArithOverflowDoubleSlot);
            $nF21 = $context->builder->siToFp($n, $f64);
            $sixtyfifthF21 = $context->builder->fmul($sixtiethF21, $nF21);
                $sixtyfifthF21 = $context->builder->fmul($sixtyfifthF21, $nF21);
                    $sixtyfifthF21 = $context->builder->fmul($sixtyfifthF21, $nF21);
                    $sixtyfifthF21 = $context->builder->fmul($sixtyfifthF21, $nF21);
                    $sixtyfifthF21 = $context->builder->fmul($sixtyfifthF21, $nF21);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfifthF21
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok21Block);
            $sixtyfirstVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixtiethLong,
                $n
            );
            $ov22 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixtyfirstVar->longArithOverflowFlag);
            if (null === $ov22 || null === $sixtyfirstVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **65 expected smul overflow metadata (sixtyfirst)');
            }
            $sixtyfirstLong = JITVariable::KIND_VARIABLE === $sixtyfirstVar->kind
                ? $context->builder->load($sixtyfirstVar->value)
                : $sixtyfirstVar->value;
            $ov22Block = BasicBlockHelper::append($context, 'pow_sixtyfifth_sixtyfirst_ov');
            $ok22Block = BasicBlockHelper::append($context, 'pow_sixtyfifth_sixtyfirst_ok');
            $context->builder->branchIf($ov22, $ov22Block, $ok22Block);

            $context->builder->positionAtEnd($ov22Block);
            $sixtyfirstF22 = $context->builder->load($sixtyfirstVar->longArithOverflowDoubleSlot);
            $nF22 = $context->builder->siToFp($n, $f64);
            $sixtyfifthF22 = $context->builder->fmul($sixtyfirstF22, $nF22);
                $sixtyfifthF22 = $context->builder->fmul($sixtyfifthF22, $nF22);
                $sixtyfifthF22 = $context->builder->fmul($sixtyfifthF22, $nF22);
                $sixtyfifthF22 = $context->builder->fmul($sixtyfifthF22, $nF22);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfifthF22
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok22Block);
            $sixtysecondVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixtyfirstLong,
                $n
            );
            $ov23 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixtysecondVar->longArithOverflowFlag);
            if (null === $ov23 || null === $sixtysecondVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **65 expected smul overflow metadata (sixtysecond)');
            }
            $sixtysecondLong = JITVariable::KIND_VARIABLE === $sixtysecondVar->kind
                ? $context->builder->load($sixtysecondVar->value)
                : $sixtysecondVar->value;
            $ov23Block = BasicBlockHelper::append($context, 'pow_sixtyfifth_sixtysecond_ov');
            $ok23Block = BasicBlockHelper::append($context, 'pow_sixtyfifth_sixtysecond_ok');
            $context->builder->branchIf($ov23, $ov23Block, $ok23Block);

            $context->builder->positionAtEnd($ov23Block);
            $sixtysecondF23 = $context->builder->load($sixtysecondVar->longArithOverflowDoubleSlot);
            $nF23 = $context->builder->siToFp($n, $f64);
            $sixtyfifthF23 = $context->builder->fmul($sixtysecondF23, $nF23);
            $sixtyfifthF23 = $context->builder->fmul($sixtyfifthF23, $nF23);
            $sixtyfifthF23 = $context->builder->fmul($sixtyfifthF23, $nF23);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfifthF23
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok23Block);
            $sixtythirdVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixtysecondLong,
                $n
            );
            $ov24 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixtythirdVar->longArithOverflowFlag);
            if (null === $ov24 || null === $sixtythirdVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **65 expected smul overflow metadata (sixtythird)');
            }
            $sixtythirdLong = JITVariable::KIND_VARIABLE === $sixtythirdVar->kind
                ? $context->builder->load($sixtythirdVar->value)
                : $sixtythirdVar->value;
            $ov24Block = BasicBlockHelper::append($context, 'pow_sixtyfifth_sixtythird_ov');
            $ok24Block = BasicBlockHelper::append($context, 'pow_sixtyfifth_sixtythird_ok');
            $context->builder->branchIf($ov24, $ov24Block, $ok24Block);

            $context->builder->positionAtEnd($ov24Block);
            $sixtythirdF24 = $context->builder->load($sixtythirdVar->longArithOverflowDoubleSlot);
            $nF24 = $context->builder->siToFp($n, $f64);
            $sixtyfifthF24 = $context->builder->fmul($sixtythirdF24, $nF24);
            $sixtyfifthF24 = $context->builder->fmul($sixtyfifthF24, $nF24);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfifthF24
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok24Block);
            $sixtyfourthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixtythirdLong,
                $n
            );
            $ov25 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixtyfourthVar->longArithOverflowFlag);
            if (null === $ov25 || null === $sixtyfourthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **65 expected smul overflow metadata (sixtyfourth)');
            }
            $sixtyfourthLong = JITVariable::KIND_VARIABLE === $sixtyfourthVar->kind
                ? $context->builder->load($sixtyfourthVar->value)
                : $sixtyfourthVar->value;
            $ov25Block = BasicBlockHelper::append($context, 'pow_sixtyfifth_sixtyfourth_ov');
            $ok25Block = BasicBlockHelper::append($context, 'pow_sixtyfifth_sixtyfourth_ok');
            $context->builder->branchIf($ov25, $ov25Block, $ok25Block);

            $context->builder->positionAtEnd($ov25Block);
            $sixtyfourthF25 = $context->builder->load($sixtyfourthVar->longArithOverflowDoubleSlot);
            $nF25 = $context->builder->siToFp($n, $f64);
            $sixtyfifthF25 = $context->builder->fmul($sixtyfourthF25, $nF25);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyfifthF25
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok25Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $sixtyfourthLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);


            $context->builder->positionAtEnd($doneBlock);

            return true;
        }

        if ('sixtysixth' === $expFold) {
            // n^66 = sixtyfifth*n = fortyeighth*sq*n*n*n*n*n*n*n*n*n*n*n*n*n: sq=n*n, cu=sq*n, fifth=cu*sq,
            // tenth=fifth*fifth, twentieth=tenth*tenth,
            // fortieth=twentieth*twentieth, fortysecond=fortieth*sq, fortyfourth=fortysecond*sq,
            // fortysixth=fortyfourth*sq, then fortysixth*sq*sq*n*n*n*n*n*n*n*n*n*n*n*n*n*n*n.
            // Overflow arms finish in float (sqF^25*nF*nF*nF*nF*nF*nF*nF*nF*nF*nF,
            // ((cuF*sqF)^2)^4*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF*nF, (fifthF^2)^4*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF, tenthF^4*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF,
            // twentiethF^2*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF*nF, fortiethF*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF, fortysecondF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF,
            // fortyfourthF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF*nF, fortysixthF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF*nF).
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
                throw new \LogicException('pow() **66 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_sixtysixth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_sixtysixth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_sixtysixth_done');
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
            $sixtysixthFSq = $context->builder->fmul($fortyfourthF, $sqF);
            $sixtysixthFEighth = $context->builder->fmul($sixtysixthFSq, $sqF);
            $sixtysixthF = $context->builder->fmul($sixtysixthFEighth, $sqF);
            $nF = $context->builder->siToFp($n, $f64);
            $sixtysixthF = $context->builder->fmul($sixtysixthF, $nF);
            $sixtysixthF = $context->builder->fmul($sixtysixthF, $nF);
            $sixtysixthF = $context->builder->fmul($sixtysixthF, $nF);
            $sixtysixthF = $context->builder->fmul($sixtysixthF, $nF);
            $sixtysixthF = $context->builder->fmul($sixtysixthF, $nF);
            $sixtysixthF = $context->builder->fmul($sixtysixthF, $nF);
            $sixtysixthF = $context->builder->fmul($sixtysixthF, $nF);
            $sixtysixthF = $context->builder->fmul($sixtysixthF, $nF);
            $sixtysixthF = $context->builder->fmul($sixtysixthF, $nF);
            $sixtysixthF = $context->builder->fmul($sixtysixthF, $nF);
                $sixtysixthF = $context->builder->fmul($sixtysixthF, $nF);
                    $sixtysixthF = $context->builder->fmul($sixtysixthF, $nF);
                        $sixtysixthF = $context->builder->fmul($sixtysixthF, $nF);
                        $sixtysixthF = $context->builder->fmul($sixtysixthF, $nF);
                        $sixtysixthF = $context->builder->fmul($sixtysixthF, $nF);
                        $sixtysixthF = $context->builder->fmul($sixtysixthF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtysixthF
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
                throw new \LogicException('pow() **66 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_sixtysixth_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_sixtysixth_cu_ok');
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
            $sixtysixthF2Sq = $context->builder->fmul($fortyfourthF2, $sqF2);
            $sixtysixthF2Eighth = $context->builder->fmul($sixtysixthF2Sq, $sqF2);
            $sixtysixthF2 = $context->builder->fmul($sixtysixthF2Eighth, $sqF2);
            $nF2 = $context->builder->siToFp($n, $f64);
            $sixtysixthF2 = $context->builder->fmul($sixtysixthF2, $nF2);
            $sixtysixthF2 = $context->builder->fmul($sixtysixthF2, $nF2);
            $sixtysixthF2 = $context->builder->fmul($sixtysixthF2, $nF2);
            $sixtysixthF2 = $context->builder->fmul($sixtysixthF2, $nF2);
            $sixtysixthF2 = $context->builder->fmul($sixtysixthF2, $nF2);
            $sixtysixthF2 = $context->builder->fmul($sixtysixthF2, $nF2);
            $sixtysixthF2 = $context->builder->fmul($sixtysixthF2, $nF2);
            $sixtysixthF2 = $context->builder->fmul($sixtysixthF2, $nF2);
            $sixtysixthF2 = $context->builder->fmul($sixtysixthF2, $nF2);
            $sixtysixthF2 = $context->builder->fmul($sixtysixthF2, $nF2);
                $sixtysixthF2 = $context->builder->fmul($sixtysixthF2, $nF2);
                    $sixtysixthF2 = $context->builder->fmul($sixtysixthF2, $nF2);
                        $sixtysixthF2 = $context->builder->fmul($sixtysixthF2, $nF2);
                        $sixtysixthF2 = $context->builder->fmul($sixtysixthF2, $nF2);
                        $sixtysixthF2 = $context->builder->fmul($sixtysixthF2, $nF2);
                        $sixtysixthF2 = $context->builder->fmul($sixtysixthF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtysixthF2
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
                throw new \LogicException('pow() **66 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_sixtysixth_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_sixtysixth_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $twentiethF3 = $context->builder->fmul($tenthF3, $tenthF3);
            $fortiethF3 = $context->builder->fmul($twentiethF3, $twentiethF3);
            $sqF3 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF3 = $context->builder->fmul($fortiethF3, $sqF3);
            $fortyfourthF3 = $context->builder->fmul($fortysecondF3, $sqF3);
            $sixtysixthF3Sq = $context->builder->fmul($fortyfourthF3, $sqF3);
            $sixtysixthF3Eighth = $context->builder->fmul($sixtysixthF3Sq, $sqF3);
            $sixtysixthF3 = $context->builder->fmul($sixtysixthF3Eighth, $sqF3);
            $nF3 = $context->builder->siToFp($n, $f64);
            $sixtysixthF3 = $context->builder->fmul($sixtysixthF3, $nF3);
            $sixtysixthF3 = $context->builder->fmul($sixtysixthF3, $nF3);
            $sixtysixthF3 = $context->builder->fmul($sixtysixthF3, $nF3);
            $sixtysixthF3 = $context->builder->fmul($sixtysixthF3, $nF3);
            $sixtysixthF3 = $context->builder->fmul($sixtysixthF3, $nF3);
            $sixtysixthF3 = $context->builder->fmul($sixtysixthF3, $nF3);
            $sixtysixthF3 = $context->builder->fmul($sixtysixthF3, $nF3);
            $sixtysixthF3 = $context->builder->fmul($sixtysixthF3, $nF3);
            $sixtysixthF3 = $context->builder->fmul($sixtysixthF3, $nF3);
            $sixtysixthF3 = $context->builder->fmul($sixtysixthF3, $nF3);
                $sixtysixthF3 = $context->builder->fmul($sixtysixthF3, $nF3);
                    $sixtysixthF3 = $context->builder->fmul($sixtysixthF3, $nF3);
                        $sixtysixthF3 = $context->builder->fmul($sixtysixthF3, $nF3);
                        $sixtysixthF3 = $context->builder->fmul($sixtysixthF3, $nF3);
                        $sixtysixthF3 = $context->builder->fmul($sixtysixthF3, $nF3);
                        $sixtysixthF3 = $context->builder->fmul($sixtysixthF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtysixthF3
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
                throw new \LogicException('pow() **66 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_sixtysixth_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_sixtysixth_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF4 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $twentiethF4 = $context->builder->fmul($tenthF4, $tenthF4);
            $fortiethF4 = $context->builder->fmul($twentiethF4, $twentiethF4);
            $sqF4 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF4 = $context->builder->fmul($fortiethF4, $sqF4);
            $fortyfourthF4 = $context->builder->fmul($fortysecondF4, $sqF4);
            $sixtysixthF4Sq = $context->builder->fmul($fortyfourthF4, $sqF4);
            $sixtysixthF4Eighth = $context->builder->fmul($sixtysixthF4Sq, $sqF4);
            $sixtysixthF4 = $context->builder->fmul($sixtysixthF4Eighth, $sqF4);
            $nF4 = $context->builder->siToFp($n, $f64);
            $sixtysixthF4 = $context->builder->fmul($sixtysixthF4, $nF4);
            $sixtysixthF4 = $context->builder->fmul($sixtysixthF4, $nF4);
            $sixtysixthF4 = $context->builder->fmul($sixtysixthF4, $nF4);
            $sixtysixthF4 = $context->builder->fmul($sixtysixthF4, $nF4);
            $sixtysixthF4 = $context->builder->fmul($sixtysixthF4, $nF4);
            $sixtysixthF4 = $context->builder->fmul($sixtysixthF4, $nF4);
            $sixtysixthF4 = $context->builder->fmul($sixtysixthF4, $nF4);
            $sixtysixthF4 = $context->builder->fmul($sixtysixthF4, $nF4);
            $sixtysixthF4 = $context->builder->fmul($sixtysixthF4, $nF4);
            $sixtysixthF4 = $context->builder->fmul($sixtysixthF4, $nF4);
                $sixtysixthF4 = $context->builder->fmul($sixtysixthF4, $nF4);
                    $sixtysixthF4 = $context->builder->fmul($sixtysixthF4, $nF4);
                        $sixtysixthF4 = $context->builder->fmul($sixtysixthF4, $nF4);
                        $sixtysixthF4 = $context->builder->fmul($sixtysixthF4, $nF4);
                        $sixtysixthF4 = $context->builder->fmul($sixtysixthF4, $nF4);
                        $sixtysixthF4 = $context->builder->fmul($sixtysixthF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtysixthF4
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
                throw new \LogicException('pow() **66 expected smul overflow metadata (twentieth)');
            }
            $twentiethLong = JITVariable::KIND_VARIABLE === $twentiethVar->kind
                ? $context->builder->load($twentiethVar->value)
                : $twentiethVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_sixtysixth_twentieth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_sixtysixth_twentieth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $twentiethF5 = $context->builder->load($twentiethVar->longArithOverflowDoubleSlot);
            $fortiethF5 = $context->builder->fmul($twentiethF5, $twentiethF5);
            $sqF5 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF5 = $context->builder->fmul($fortiethF5, $sqF5);
            $fortyfourthF5 = $context->builder->fmul($fortysecondF5, $sqF5);
            $sixtysixthF5Sq = $context->builder->fmul($fortyfourthF5, $sqF5);
            $sixtysixthF5Eighth = $context->builder->fmul($sixtysixthF5Sq, $sqF5);
            $sixtysixthF5 = $context->builder->fmul($sixtysixthF5Eighth, $sqF5);
            $nF5 = $context->builder->siToFp($n, $f64);
            $sixtysixthF5 = $context->builder->fmul($sixtysixthF5, $nF5);
            $sixtysixthF5 = $context->builder->fmul($sixtysixthF5, $nF5);
            $sixtysixthF5 = $context->builder->fmul($sixtysixthF5, $nF5);
            $sixtysixthF5 = $context->builder->fmul($sixtysixthF5, $nF5);
            $sixtysixthF5 = $context->builder->fmul($sixtysixthF5, $nF5);
            $sixtysixthF5 = $context->builder->fmul($sixtysixthF5, $nF5);
            $sixtysixthF5 = $context->builder->fmul($sixtysixthF5, $nF5);
            $sixtysixthF5 = $context->builder->fmul($sixtysixthF5, $nF5);
            $sixtysixthF5 = $context->builder->fmul($sixtysixthF5, $nF5);
            $sixtysixthF5 = $context->builder->fmul($sixtysixthF5, $nF5);
                $sixtysixthF5 = $context->builder->fmul($sixtysixthF5, $nF5);
                    $sixtysixthF5 = $context->builder->fmul($sixtysixthF5, $nF5);
                        $sixtysixthF5 = $context->builder->fmul($sixtysixthF5, $nF5);
                        $sixtysixthF5 = $context->builder->fmul($sixtysixthF5, $nF5);
                        $sixtysixthF5 = $context->builder->fmul($sixtysixthF5, $nF5);
                        $sixtysixthF5 = $context->builder->fmul($sixtysixthF5, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtysixthF5
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
                throw new \LogicException('pow() **66 expected smul overflow metadata (fortieth)');
            }
            $fortiethLong = JITVariable::KIND_VARIABLE === $fortiethVar->kind
                ? $context->builder->load($fortiethVar->value)
                : $fortiethVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_sixtysixth_fortieth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_sixtysixth_fortieth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $fortiethF6 = $context->builder->load($fortiethVar->longArithOverflowDoubleSlot);
            $sqF6 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF6 = $context->builder->fmul($fortiethF6, $sqF6);
            $fortyfourthF6 = $context->builder->fmul($fortysecondF6, $sqF6);
            $sixtysixthF6Sq = $context->builder->fmul($fortyfourthF6, $sqF6);
            $sixtysixthF6Eighth = $context->builder->fmul($sixtysixthF6Sq, $sqF6);
            $sixtysixthF6 = $context->builder->fmul($sixtysixthF6Eighth, $sqF6);
            $nF6 = $context->builder->siToFp($n, $f64);
            $sixtysixthF6 = $context->builder->fmul($sixtysixthF6, $nF6);
            $sixtysixthF6 = $context->builder->fmul($sixtysixthF6, $nF6);
            $sixtysixthF6 = $context->builder->fmul($sixtysixthF6, $nF6);
            $sixtysixthF6 = $context->builder->fmul($sixtysixthF6, $nF6);
            $sixtysixthF6 = $context->builder->fmul($sixtysixthF6, $nF6);
            $sixtysixthF6 = $context->builder->fmul($sixtysixthF6, $nF6);
            $sixtysixthF6 = $context->builder->fmul($sixtysixthF6, $nF6);
            $sixtysixthF6 = $context->builder->fmul($sixtysixthF6, $nF6);
            $sixtysixthF6 = $context->builder->fmul($sixtysixthF6, $nF6);
            $sixtysixthF6 = $context->builder->fmul($sixtysixthF6, $nF6);
                $sixtysixthF6 = $context->builder->fmul($sixtysixthF6, $nF6);
                    $sixtysixthF6 = $context->builder->fmul($sixtysixthF6, $nF6);
                        $sixtysixthF6 = $context->builder->fmul($sixtysixthF6, $nF6);
                        $sixtysixthF6 = $context->builder->fmul($sixtysixthF6, $nF6);
                        $sixtysixthF6 = $context->builder->fmul($sixtysixthF6, $nF6);
                        $sixtysixthF6 = $context->builder->fmul($sixtysixthF6, $nF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtysixthF6
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
                throw new \LogicException('pow() **66 expected smul overflow metadata (fortysecond)');
            }
            $fortysecondLong = JITVariable::KIND_VARIABLE === $fortysecondVar->kind
                ? $context->builder->load($fortysecondVar->value)
                : $fortysecondVar->value;
            $ov7Block = BasicBlockHelper::append($context, 'pow_sixtysixth_fortysecond_ov');
            $ok7Block = BasicBlockHelper::append($context, 'pow_sixtysixth_fortysecond_ok');
            $context->builder->branchIf($ov7, $ov7Block, $ok7Block);

            $context->builder->positionAtEnd($ov7Block);
            $fortysecondF7 = $context->builder->load($fortysecondVar->longArithOverflowDoubleSlot);
            $sqF7 = $context->builder->siToFp($sqLong, $f64);
            $fortyfourthF7 = $context->builder->fmul($fortysecondF7, $sqF7);
            $sixtysixthF7Sq = $context->builder->fmul($fortyfourthF7, $sqF7);
            $sixtysixthF7Eighth = $context->builder->fmul($sixtysixthF7Sq, $sqF7);
            $sixtysixthF7 = $context->builder->fmul($sixtysixthF7Eighth, $sqF7);
            $nF7 = $context->builder->siToFp($n, $f64);
            $sixtysixthF7 = $context->builder->fmul($sixtysixthF7, $nF7);
            $sixtysixthF7 = $context->builder->fmul($sixtysixthF7, $nF7);
            $sixtysixthF7 = $context->builder->fmul($sixtysixthF7, $nF7);
            $sixtysixthF7 = $context->builder->fmul($sixtysixthF7, $nF7);
            $sixtysixthF7 = $context->builder->fmul($sixtysixthF7, $nF7);
            $sixtysixthF7 = $context->builder->fmul($sixtysixthF7, $nF7);
            $sixtysixthF7 = $context->builder->fmul($sixtysixthF7, $nF7);
            $sixtysixthF7 = $context->builder->fmul($sixtysixthF7, $nF7);
            $sixtysixthF7 = $context->builder->fmul($sixtysixthF7, $nF7);
            $sixtysixthF7 = $context->builder->fmul($sixtysixthF7, $nF7);
                $sixtysixthF7 = $context->builder->fmul($sixtysixthF7, $nF7);
                    $sixtysixthF7 = $context->builder->fmul($sixtysixthF7, $nF7);
                        $sixtysixthF7 = $context->builder->fmul($sixtysixthF7, $nF7);
                        $sixtysixthF7 = $context->builder->fmul($sixtysixthF7, $nF7);
                        $sixtysixthF7 = $context->builder->fmul($sixtysixthF7, $nF7);
                        $sixtysixthF7 = $context->builder->fmul($sixtysixthF7, $nF7);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtysixthF7
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
                throw new \LogicException('pow() **66 expected smul overflow metadata (fortyfourth)');
            }
            $fortyfourthLong = JITVariable::KIND_VARIABLE === $fortyfourthVar->kind
                ? $context->builder->load($fortyfourthVar->value)
                : $fortyfourthVar->value;
            $ov8Block = BasicBlockHelper::append($context, 'pow_sixtysixth_fortyfourth_ov');
            $ok8Block = BasicBlockHelper::append($context, 'pow_sixtysixth_fortyfourth_ok');
            $context->builder->branchIf($ov8, $ov8Block, $ok8Block);

            $context->builder->positionAtEnd($ov8Block);
            $fortyfourthF8 = $context->builder->load($fortyfourthVar->longArithOverflowDoubleSlot);
            $sqF8 = $context->builder->siToFp($sqLong, $f64);
            $sixtysixthF8Sq = $context->builder->fmul($fortyfourthF8, $sqF8);
            $sixtysixthF8Eighth = $context->builder->fmul($sixtysixthF8Sq, $sqF8);
            $sixtysixthF8 = $context->builder->fmul($sixtysixthF8Eighth, $sqF8);
            $nF8 = $context->builder->siToFp($n, $f64);
            $sixtysixthF8 = $context->builder->fmul($sixtysixthF8, $nF8);
            $sixtysixthF8 = $context->builder->fmul($sixtysixthF8, $nF8);
            $sixtysixthF8 = $context->builder->fmul($sixtysixthF8, $nF8);
            $sixtysixthF8 = $context->builder->fmul($sixtysixthF8, $nF8);
            $sixtysixthF8 = $context->builder->fmul($sixtysixthF8, $nF8);
            $sixtysixthF8 = $context->builder->fmul($sixtysixthF8, $nF8);
            $sixtysixthF8 = $context->builder->fmul($sixtysixthF8, $nF8);
            $sixtysixthF8 = $context->builder->fmul($sixtysixthF8, $nF8);
            $sixtysixthF8 = $context->builder->fmul($sixtysixthF8, $nF8);
            $sixtysixthF8 = $context->builder->fmul($sixtysixthF8, $nF8);
                $sixtysixthF8 = $context->builder->fmul($sixtysixthF8, $nF8);
                    $sixtysixthF8 = $context->builder->fmul($sixtysixthF8, $nF8);
                        $sixtysixthF8 = $context->builder->fmul($sixtysixthF8, $nF8);
                        $sixtysixthF8 = $context->builder->fmul($sixtysixthF8, $nF8);
                        $sixtysixthF8 = $context->builder->fmul($sixtysixthF8, $nF8);
                        $sixtysixthF8 = $context->builder->fmul($sixtysixthF8, $nF8);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtysixthF8
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
                throw new \LogicException('pow() **66 expected smul overflow metadata (fortysixth)');
            }
            $fortysixthLong = JITVariable::KIND_VARIABLE === $fortysixthVar->kind
                ? $context->builder->load($fortysixthVar->value)
                : $fortysixthVar->value;
            $ov9Block = BasicBlockHelper::append($context, 'pow_sixtysixth_fortysixth_ov');
            $ok9Block = BasicBlockHelper::append($context, 'pow_sixtysixth_fortysixth_ok');
            $context->builder->branchIf($ov9, $ov9Block, $ok9Block);

            $context->builder->positionAtEnd($ov9Block);
            $fortysixthF9 = $context->builder->load($fortysixthVar->longArithOverflowDoubleSlot);
            $sqF9 = $context->builder->siToFp($sqLong, $f64);
            $fortyeighthF9 = $context->builder->fmul($fortysixthF9, $sqF9);
            $sixtysixthF9 = $context->builder->fmul($fortyeighthF9, $sqF9);
            $nF9 = $context->builder->siToFp($n, $f64);
            $sixtysixthF9 = $context->builder->fmul($sixtysixthF9, $nF9);
            $sixtysixthF9 = $context->builder->fmul($sixtysixthF9, $nF9);
            $sixtysixthF9 = $context->builder->fmul($sixtysixthF9, $nF9);
            $sixtysixthF9 = $context->builder->fmul($sixtysixthF9, $nF9);
            $sixtysixthF9 = $context->builder->fmul($sixtysixthF9, $nF9);
            $sixtysixthF9 = $context->builder->fmul($sixtysixthF9, $nF9);
            $sixtysixthF9 = $context->builder->fmul($sixtysixthF9, $nF9);
            $sixtysixthF9 = $context->builder->fmul($sixtysixthF9, $nF9);
            $sixtysixthF9 = $context->builder->fmul($sixtysixthF9, $nF9);
            $sixtysixthF9 = $context->builder->fmul($sixtysixthF9, $nF9);
                $sixtysixthF9 = $context->builder->fmul($sixtysixthF9, $nF9);
                    $sixtysixthF9 = $context->builder->fmul($sixtysixthF9, $nF9);
                        $sixtysixthF9 = $context->builder->fmul($sixtysixthF9, $nF9);
                        $sixtysixthF9 = $context->builder->fmul($sixtysixthF9, $nF9);
                        $sixtysixthF9 = $context->builder->fmul($sixtysixthF9, $nF9);
                        $sixtysixthF9 = $context->builder->fmul($sixtysixthF9, $nF9);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtysixthF9
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
                throw new \LogicException('pow() **66 expected smul overflow metadata (fortyeighth)');
            }
            $fortyeighthLong = JITVariable::KIND_VARIABLE === $fortyeighthVar->kind
                ? $context->builder->load($fortyeighthVar->value)
                : $fortyeighthVar->value;
            $ov10Block = BasicBlockHelper::append($context, 'pow_sixtysixth_fortyeighth_ov');
            $ok10Block = BasicBlockHelper::append($context, 'pow_sixtysixth_fortyeighth_ok');
            $context->builder->branchIf($ov10, $ov10Block, $ok10Block);

            $context->builder->positionAtEnd($ov10Block);
            $fortyeighthF10 = $context->builder->load($fortyeighthVar->longArithOverflowDoubleSlot);
            $sqF10 = $context->builder->siToFp($sqLong, $f64);
            $sixtysixthF10 = $context->builder->fmul($fortyeighthF10, $sqF10);
            $nF10 = $context->builder->siToFp($n, $f64);
            $sixtysixthF10 = $context->builder->fmul($sixtysixthF10, $nF10);
            $sixtysixthF10 = $context->builder->fmul($sixtysixthF10, $nF10);
            $sixtysixthF10 = $context->builder->fmul($sixtysixthF10, $nF10);
            $sixtysixthF10 = $context->builder->fmul($sixtysixthF10, $nF10);
            $sixtysixthF10 = $context->builder->fmul($sixtysixthF10, $nF10);
            $sixtysixthF10 = $context->builder->fmul($sixtysixthF10, $nF10);
            $sixtysixthF10 = $context->builder->fmul($sixtysixthF10, $nF10);
            $sixtysixthF10 = $context->builder->fmul($sixtysixthF10, $nF10);
            $sixtysixthF10 = $context->builder->fmul($sixtysixthF10, $nF10);
            $sixtysixthF10 = $context->builder->fmul($sixtysixthF10, $nF10);
                $sixtysixthF10 = $context->builder->fmul($sixtysixthF10, $nF10);
                    $sixtysixthF10 = $context->builder->fmul($sixtysixthF10, $nF10);
                        $sixtysixthF10 = $context->builder->fmul($sixtysixthF10, $nF10);
                        $sixtysixthF10 = $context->builder->fmul($sixtysixthF10, $nF10);
                        $sixtysixthF10 = $context->builder->fmul($sixtysixthF10, $nF10);
                        $sixtysixthF10 = $context->builder->fmul($sixtysixthF10, $nF10);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtysixthF10
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok10Block);
            $fiftiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fortyeighthLong,
                $sqLong
            );
            $ov11 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftiethVar->longArithOverflowFlag);
            if (null === $ov11 || null === $fiftiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **66 expected smul overflow metadata (fiftieth)');
            }
            $fiftiethLong = JITVariable::KIND_VARIABLE === $fiftiethVar->kind
                ? $context->builder->load($fiftiethVar->value)
                : $fiftiethVar->value;
            $ov11Block = BasicBlockHelper::append($context, 'pow_sixtysixth_fiftieth_ov');
            $ok11Block = BasicBlockHelper::append($context, 'pow_sixtysixth_fiftieth_ok');
            $context->builder->branchIf($ov11, $ov11Block, $ok11Block);

            $context->builder->positionAtEnd($ov11Block);
            $fiftiethF11 = $context->builder->load($fiftiethVar->longArithOverflowDoubleSlot);
            $nF11 = $context->builder->siToFp($n, $f64);
            $sixtysixthF11 = $context->builder->fmul($fiftiethF11, $nF11);
            $sixtysixthF11 = $context->builder->fmul($sixtysixthF11, $nF11);
            $sixtysixthF11 = $context->builder->fmul($sixtysixthF11, $nF11);
            $sixtysixthF11 = $context->builder->fmul($sixtysixthF11, $nF11);
            $sixtysixthF11 = $context->builder->fmul($sixtysixthF11, $nF11);
            $sixtysixthF11 = $context->builder->fmul($sixtysixthF11, $nF11);
            $sixtysixthF11 = $context->builder->fmul($sixtysixthF11, $nF11);
            $sixtysixthF11 = $context->builder->fmul($sixtysixthF11, $nF11);
            $sixtysixthF11 = $context->builder->fmul($sixtysixthF11, $nF11);
            $sixtysixthF11 = $context->builder->fmul($sixtysixthF11, $nF11);
                $sixtysixthF11 = $context->builder->fmul($sixtysixthF11, $nF11);
                    $sixtysixthF11 = $context->builder->fmul($sixtysixthF11, $nF11);
                        $sixtysixthF11 = $context->builder->fmul($sixtysixthF11, $nF11);
                        $sixtysixthF11 = $context->builder->fmul($sixtysixthF11, $nF11);
                        $sixtysixthF11 = $context->builder->fmul($sixtysixthF11, $nF11);
                        $sixtysixthF11 = $context->builder->fmul($sixtysixthF11, $nF11);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtysixthF11
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok11Block);
            $fiftyfirstVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftiethLong,
                $n
            );
            $ov12 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyfirstVar->longArithOverflowFlag);
            if (null === $ov12 || null === $fiftyfirstVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **66 expected smul overflow metadata (fiftyfirst)');
            }
            $fiftyfirstLong = JITVariable::KIND_VARIABLE === $fiftyfirstVar->kind
                ? $context->builder->load($fiftyfirstVar->value)
                : $fiftyfirstVar->value;
            $ov12Block = BasicBlockHelper::append($context, 'pow_sixtysixth_fiftyfirst_ov');
            $ok12Block = BasicBlockHelper::append($context, 'pow_sixtysixth_fiftyfirst_ok');
            $context->builder->branchIf($ov12, $ov12Block, $ok12Block);

            $context->builder->positionAtEnd($ov12Block);
            $fiftyfirstF12 = $context->builder->load($fiftyfirstVar->longArithOverflowDoubleSlot);
            $nF12 = $context->builder->siToFp($n, $f64);
            $sixtysixthF12 = $context->builder->fmul($fiftyfirstF12, $nF12);
            $sixtysixthF12 = $context->builder->fmul($sixtysixthF12, $nF12);
            $sixtysixthF12 = $context->builder->fmul($sixtysixthF12, $nF12);
            $sixtysixthF12 = $context->builder->fmul($sixtysixthF12, $nF12);
            $sixtysixthF12 = $context->builder->fmul($sixtysixthF12, $nF12);
            $sixtysixthF12 = $context->builder->fmul($sixtysixthF12, $nF12);
            $sixtysixthF12 = $context->builder->fmul($sixtysixthF12, $nF12);
            $sixtysixthF12 = $context->builder->fmul($sixtysixthF12, $nF12);
            $sixtysixthF12 = $context->builder->fmul($sixtysixthF12, $nF12);
                $sixtysixthF12 = $context->builder->fmul($sixtysixthF12, $nF12);
                    $sixtysixthF12 = $context->builder->fmul($sixtysixthF12, $nF12);
                        $sixtysixthF12 = $context->builder->fmul($sixtysixthF12, $nF12);
                        $sixtysixthF12 = $context->builder->fmul($sixtysixthF12, $nF12);
                        $sixtysixthF12 = $context->builder->fmul($sixtysixthF12, $nF12);
                        $sixtysixthF12 = $context->builder->fmul($sixtysixthF12, $nF12);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtysixthF12
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok12Block);
            $fiftysecondVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyfirstLong,
                $n
            );
            $ov13 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftysecondVar->longArithOverflowFlag);
            if (null === $ov13 || null === $fiftysecondVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **66 expected smul overflow metadata (fiftysecond)');
            }
            $fiftysecondLong = JITVariable::KIND_VARIABLE === $fiftysecondVar->kind
                ? $context->builder->load($fiftysecondVar->value)
                : $fiftysecondVar->value;
            $ov13Block = BasicBlockHelper::append($context, 'pow_sixtysixth_fiftysecond_ov');
            $ok13Block = BasicBlockHelper::append($context, 'pow_sixtysixth_fiftysecond_ok');
            $context->builder->branchIf($ov13, $ov13Block, $ok13Block);

            $context->builder->positionAtEnd($ov13Block);
            $fiftysecondF13 = $context->builder->load($fiftysecondVar->longArithOverflowDoubleSlot);
            $nF13 = $context->builder->siToFp($n, $f64);
            $sixtysixthF13 = $context->builder->fmul($fiftysecondF13, $nF13);
            $sixtysixthF13 = $context->builder->fmul($sixtysixthF13, $nF13);
            $sixtysixthF13 = $context->builder->fmul($sixtysixthF13, $nF13);
            $sixtysixthF13 = $context->builder->fmul($sixtysixthF13, $nF13);
            $sixtysixthF13 = $context->builder->fmul($sixtysixthF13, $nF13);
            $sixtysixthF13 = $context->builder->fmul($sixtysixthF13, $nF13);
            $sixtysixthF13 = $context->builder->fmul($sixtysixthF13, $nF13);
            $sixtysixthF13 = $context->builder->fmul($sixtysixthF13, $nF13);
                $sixtysixthF13 = $context->builder->fmul($sixtysixthF13, $nF13);
                    $sixtysixthF13 = $context->builder->fmul($sixtysixthF13, $nF13);
                        $sixtysixthF13 = $context->builder->fmul($sixtysixthF13, $nF13);
                        $sixtysixthF13 = $context->builder->fmul($sixtysixthF13, $nF13);
                        $sixtysixthF13 = $context->builder->fmul($sixtysixthF13, $nF13);
                        $sixtysixthF13 = $context->builder->fmul($sixtysixthF13, $nF13);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtysixthF13
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok13Block);
            $fiftythirdVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftysecondLong,
                $n
            );
            $ov14 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftythirdVar->longArithOverflowFlag);
            if (null === $ov14 || null === $fiftythirdVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **66 expected smul overflow metadata (fiftythird)');
            }
            $fiftythirdLong = JITVariable::KIND_VARIABLE === $fiftythirdVar->kind
                ? $context->builder->load($fiftythirdVar->value)
                : $fiftythirdVar->value;
            $ov14Block = BasicBlockHelper::append($context, 'pow_sixtysixth_fiftythird_ov');
            $ok14Block = BasicBlockHelper::append($context, 'pow_sixtysixth_fiftythird_ok');
            $context->builder->branchIf($ov14, $ov14Block, $ok14Block);

            $context->builder->positionAtEnd($ov14Block);
            $fiftythirdF14 = $context->builder->load($fiftythirdVar->longArithOverflowDoubleSlot);
            $nF14 = $context->builder->siToFp($n, $f64);
            $sixtysixthF14 = $context->builder->fmul($fiftythirdF14, $nF14);
            $sixtysixthF14 = $context->builder->fmul($sixtysixthF14, $nF14);
            $sixtysixthF14 = $context->builder->fmul($sixtysixthF14, $nF14);
            $sixtysixthF14 = $context->builder->fmul($sixtysixthF14, $nF14);
            $sixtysixthF14 = $context->builder->fmul($sixtysixthF14, $nF14);
            $sixtysixthF14 = $context->builder->fmul($sixtysixthF14, $nF14);
            $sixtysixthF14 = $context->builder->fmul($sixtysixthF14, $nF14);
                $sixtysixthF14 = $context->builder->fmul($sixtysixthF14, $nF14);
                    $sixtysixthF14 = $context->builder->fmul($sixtysixthF14, $nF14);
                        $sixtysixthF14 = $context->builder->fmul($sixtysixthF14, $nF14);
                        $sixtysixthF14 = $context->builder->fmul($sixtysixthF14, $nF14);
                        $sixtysixthF14 = $context->builder->fmul($sixtysixthF14, $nF14);
                        $sixtysixthF14 = $context->builder->fmul($sixtysixthF14, $nF14);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtysixthF14
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok14Block);
            $fiftyfourthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftythirdLong,
                $n
            );
            $ov15 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyfourthVar->longArithOverflowFlag);
            if (null === $ov15 || null === $fiftyfourthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **66 expected smul overflow metadata (fiftyfourth)');
            }
            $fiftyfourthLong = JITVariable::KIND_VARIABLE === $fiftyfourthVar->kind
                ? $context->builder->load($fiftyfourthVar->value)
                : $fiftyfourthVar->value;
            $ov15Block = BasicBlockHelper::append($context, 'pow_sixtysixth_fiftyfourth_ov');
            $ok15Block = BasicBlockHelper::append($context, 'pow_sixtysixth_fiftyfourth_ok');
            $context->builder->branchIf($ov15, $ov15Block, $ok15Block);

            $context->builder->positionAtEnd($ov15Block);
            $fiftyfourthF15 = $context->builder->load($fiftyfourthVar->longArithOverflowDoubleSlot);
            $nF15 = $context->builder->siToFp($n, $f64);
            $sixtysixthF15 = $context->builder->fmul($fiftyfourthF15, $nF15);
            $sixtysixthF15 = $context->builder->fmul($sixtysixthF15, $nF15);
            $sixtysixthF15 = $context->builder->fmul($sixtysixthF15, $nF15);
            $sixtysixthF15 = $context->builder->fmul($sixtysixthF15, $nF15);
            $sixtysixthF15 = $context->builder->fmul($sixtysixthF15, $nF15);
            $sixtysixthF15 = $context->builder->fmul($sixtysixthF15, $nF15);
                $sixtysixthF15 = $context->builder->fmul($sixtysixthF15, $nF15);
                    $sixtysixthF15 = $context->builder->fmul($sixtysixthF15, $nF15);
                        $sixtysixthF15 = $context->builder->fmul($sixtysixthF15, $nF15);
                        $sixtysixthF15 = $context->builder->fmul($sixtysixthF15, $nF15);
                        $sixtysixthF15 = $context->builder->fmul($sixtysixthF15, $nF15);
                        $sixtysixthF15 = $context->builder->fmul($sixtysixthF15, $nF15);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtysixthF15
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok15Block);
            $fiftyfifthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyfourthLong,
                $n
            );
            $ov16 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyfifthVar->longArithOverflowFlag);
            if (null === $ov16 || null === $fiftyfifthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **66 expected smul overflow metadata (fiftyfifth)');
            }
            $fiftyfifthLong = JITVariable::KIND_VARIABLE === $fiftyfifthVar->kind
                ? $context->builder->load($fiftyfifthVar->value)
                : $fiftyfifthVar->value;
            $ov16Block = BasicBlockHelper::append($context, 'pow_sixtysixth_fiftyfifth_ov');
            $ok16Block = BasicBlockHelper::append($context, 'pow_sixtysixth_fiftyfifth_ok');
            $context->builder->branchIf($ov16, $ov16Block, $ok16Block);

            $context->builder->positionAtEnd($ov16Block);
            $fiftyfifthF16 = $context->builder->load($fiftyfifthVar->longArithOverflowDoubleSlot);
            $nF16 = $context->builder->siToFp($n, $f64);
            $sixtysixthF16 = $context->builder->fmul($fiftyfifthF16, $nF16);
            $sixtysixthF16 = $context->builder->fmul($sixtysixthF16, $nF16);
            $sixtysixthF16 = $context->builder->fmul($sixtysixthF16, $nF16);
            $sixtysixthF16 = $context->builder->fmul($sixtysixthF16, $nF16);
            $sixtysixthF16 = $context->builder->fmul($sixtysixthF16, $nF16);
                $sixtysixthF16 = $context->builder->fmul($sixtysixthF16, $nF16);
                    $sixtysixthF16 = $context->builder->fmul($sixtysixthF16, $nF16);
                        $sixtysixthF16 = $context->builder->fmul($sixtysixthF16, $nF16);
                        $sixtysixthF16 = $context->builder->fmul($sixtysixthF16, $nF16);
                        $sixtysixthF16 = $context->builder->fmul($sixtysixthF16, $nF16);
                        $sixtysixthF16 = $context->builder->fmul($sixtysixthF16, $nF16);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtysixthF16
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok16Block);
            $fiftysixthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyfifthLong,
                $n
            );
            $ov17 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftysixthVar->longArithOverflowFlag);
            if (null === $ov17 || null === $fiftysixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **66 expected smul overflow metadata (fiftysixth)');
            }
            $fiftysixthLong = JITVariable::KIND_VARIABLE === $fiftysixthVar->kind
                ? $context->builder->load($fiftysixthVar->value)
                : $fiftysixthVar->value;
            $ov17Block = BasicBlockHelper::append($context, 'pow_sixtysixth_fiftysixth_ov');
            $ok17Block = BasicBlockHelper::append($context, 'pow_sixtysixth_fiftysixth_ok');
            $context->builder->branchIf($ov17, $ov17Block, $ok17Block);

            $context->builder->positionAtEnd($ov17Block);
            $fiftysixthF17 = $context->builder->load($fiftysixthVar->longArithOverflowDoubleSlot);
            $nF17 = $context->builder->siToFp($n, $f64);
            $sixtysixthF17 = $context->builder->fmul($fiftysixthF17, $nF17);
            $sixtysixthF17 = $context->builder->fmul($sixtysixthF17, $nF17);
            $sixtysixthF17 = $context->builder->fmul($sixtysixthF17, $nF17);
            $sixtysixthF17 = $context->builder->fmul($sixtysixthF17, $nF17);
                $sixtysixthF17 = $context->builder->fmul($sixtysixthF17, $nF17);
                    $sixtysixthF17 = $context->builder->fmul($sixtysixthF17, $nF17);
                        $sixtysixthF17 = $context->builder->fmul($sixtysixthF17, $nF17);
                        $sixtysixthF17 = $context->builder->fmul($sixtysixthF17, $nF17);
                        $sixtysixthF17 = $context->builder->fmul($sixtysixthF17, $nF17);
                        $sixtysixthF17 = $context->builder->fmul($sixtysixthF17, $nF17);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtysixthF17
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok17Block);
            $fiftyseventhVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftysixthLong,
                $n
            );
            $ov18 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyseventhVar->longArithOverflowFlag);
            if (null === $ov18 || null === $fiftyseventhVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **66 expected smul overflow metadata (fiftyseventh)');
            }
            $fiftyseventhLong = JITVariable::KIND_VARIABLE === $fiftyseventhVar->kind
                ? $context->builder->load($fiftyseventhVar->value)
                : $fiftyseventhVar->value;
            $ov18Block = BasicBlockHelper::append($context, 'pow_sixtysixth_fiftyseventh_ov');
            $ok18Block = BasicBlockHelper::append($context, 'pow_sixtysixth_fiftyseventh_ok');
            $context->builder->branchIf($ov18, $ov18Block, $ok18Block);

            $context->builder->positionAtEnd($ov18Block);
            $fiftyseventhF18 = $context->builder->load($fiftyseventhVar->longArithOverflowDoubleSlot);
            $nF18 = $context->builder->siToFp($n, $f64);
            $sixtysixthF18 = $context->builder->fmul($fiftyseventhF18, $nF18);
            $sixtysixthF18 = $context->builder->fmul($sixtysixthF18, $nF18);
            $sixtysixthF18 = $context->builder->fmul($sixtysixthF18, $nF18);
                $sixtysixthF18 = $context->builder->fmul($sixtysixthF18, $nF18);
                    $sixtysixthF18 = $context->builder->fmul($sixtysixthF18, $nF18);
                        $sixtysixthF18 = $context->builder->fmul($sixtysixthF18, $nF18);
                        $sixtysixthF18 = $context->builder->fmul($sixtysixthF18, $nF18);
                        $sixtysixthF18 = $context->builder->fmul($sixtysixthF18, $nF18);
                        $sixtysixthF18 = $context->builder->fmul($sixtysixthF18, $nF18);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtysixthF18
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok18Block);
            $fiftyeighthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyseventhLong,
                $n
            );
            $ov19 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyeighthVar->longArithOverflowFlag);
            if (null === $ov19 || null === $fiftyeighthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **66 expected smul overflow metadata (fiftyeighth)');
            }
            $fiftyeighthLong = JITVariable::KIND_VARIABLE === $fiftyeighthVar->kind
                ? $context->builder->load($fiftyeighthVar->value)
                : $fiftyeighthVar->value;
            $ov19Block = BasicBlockHelper::append($context, 'pow_sixtysixth_fiftyeighth_ov');
            $ok19Block = BasicBlockHelper::append($context, 'pow_sixtysixth_fiftyeighth_ok');
            $context->builder->branchIf($ov19, $ov19Block, $ok19Block);

            $context->builder->positionAtEnd($ov19Block);
            $fiftyeighthF19 = $context->builder->load($fiftyeighthVar->longArithOverflowDoubleSlot);
            $nF19 = $context->builder->siToFp($n, $f64);
            $sixtysixthF19 = $context->builder->fmul($fiftyeighthF19, $nF19);
            $sixtysixthF19 = $context->builder->fmul($sixtysixthF19, $nF19);
                $sixtysixthF19 = $context->builder->fmul($sixtysixthF19, $nF19);
                    $sixtysixthF19 = $context->builder->fmul($sixtysixthF19, $nF19);
                        $sixtysixthF19 = $context->builder->fmul($sixtysixthF19, $nF19);
                        $sixtysixthF19 = $context->builder->fmul($sixtysixthF19, $nF19);
                        $sixtysixthF19 = $context->builder->fmul($sixtysixthF19, $nF19);
                        $sixtysixthF19 = $context->builder->fmul($sixtysixthF19, $nF19);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtysixthF19
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok19Block);
            $fiftyninthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyeighthLong,
                $n
            );
            $ov20 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyninthVar->longArithOverflowFlag);
            if (null === $ov20 || null === $fiftyninthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **66 expected smul overflow metadata (fiftyninth)');
            }
            $fiftyninthLong = JITVariable::KIND_VARIABLE === $fiftyninthVar->kind
                ? $context->builder->load($fiftyninthVar->value)
                : $fiftyninthVar->value;
            $ov20Block = BasicBlockHelper::append($context, 'pow_sixtysixth_fiftyninth_ov');
            $ok20Block = BasicBlockHelper::append($context, 'pow_sixtysixth_fiftyninth_ok');
            $context->builder->branchIf($ov20, $ov20Block, $ok20Block);

            $context->builder->positionAtEnd($ov20Block);
            $fiftyninthF20 = $context->builder->load($fiftyninthVar->longArithOverflowDoubleSlot);
            $nF20 = $context->builder->siToFp($n, $f64);
            $sixtysixthF20 = $context->builder->fmul($fiftyninthF20, $nF20);
                $sixtysixthF20 = $context->builder->fmul($sixtysixthF20, $nF20);
                    $sixtysixthF20 = $context->builder->fmul($sixtysixthF20, $nF20);
                        $sixtysixthF20 = $context->builder->fmul($sixtysixthF20, $nF20);
                        $sixtysixthF20 = $context->builder->fmul($sixtysixthF20, $nF20);
                        $sixtysixthF20 = $context->builder->fmul($sixtysixthF20, $nF20);
                        $sixtysixthF20 = $context->builder->fmul($sixtysixthF20, $nF20);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtysixthF20
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok20Block);
            $sixtiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyninthLong,
                $n
            );
            $ov21 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixtiethVar->longArithOverflowFlag);
            if (null === $ov21 || null === $sixtiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **66 expected smul overflow metadata (sixtieth)');
            }
            $sixtiethLong = JITVariable::KIND_VARIABLE === $sixtiethVar->kind
                ? $context->builder->load($sixtiethVar->value)
                : $sixtiethVar->value;
            $ov21Block = BasicBlockHelper::append($context, 'pow_sixtysixth_sixtieth_ov');
            $ok21Block = BasicBlockHelper::append($context, 'pow_sixtysixth_sixtieth_ok');
            $context->builder->branchIf($ov21, $ov21Block, $ok21Block);

            $context->builder->positionAtEnd($ov21Block);
            $sixtiethF21 = $context->builder->load($sixtiethVar->longArithOverflowDoubleSlot);
            $nF21 = $context->builder->siToFp($n, $f64);
            $sixtysixthF21 = $context->builder->fmul($sixtiethF21, $nF21);
                $sixtysixthF21 = $context->builder->fmul($sixtysixthF21, $nF21);
                    $sixtysixthF21 = $context->builder->fmul($sixtysixthF21, $nF21);
                    $sixtysixthF21 = $context->builder->fmul($sixtysixthF21, $nF21);
                    $sixtysixthF21 = $context->builder->fmul($sixtysixthF21, $nF21);
                    $sixtysixthF21 = $context->builder->fmul($sixtysixthF21, $nF21);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtysixthF21
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok21Block);
            $sixtyfirstVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixtiethLong,
                $n
            );
            $ov22 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixtyfirstVar->longArithOverflowFlag);
            if (null === $ov22 || null === $sixtyfirstVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **66 expected smul overflow metadata (sixtyfirst)');
            }
            $sixtyfirstLong = JITVariable::KIND_VARIABLE === $sixtyfirstVar->kind
                ? $context->builder->load($sixtyfirstVar->value)
                : $sixtyfirstVar->value;
            $ov22Block = BasicBlockHelper::append($context, 'pow_sixtysixth_sixtyfirst_ov');
            $ok22Block = BasicBlockHelper::append($context, 'pow_sixtysixth_sixtyfirst_ok');
            $context->builder->branchIf($ov22, $ov22Block, $ok22Block);

            $context->builder->positionAtEnd($ov22Block);
            $sixtyfirstF22 = $context->builder->load($sixtyfirstVar->longArithOverflowDoubleSlot);
            $nF22 = $context->builder->siToFp($n, $f64);
            $sixtysixthF22 = $context->builder->fmul($sixtyfirstF22, $nF22);
                $sixtysixthF22 = $context->builder->fmul($sixtysixthF22, $nF22);
                $sixtysixthF22 = $context->builder->fmul($sixtysixthF22, $nF22);
                $sixtysixthF22 = $context->builder->fmul($sixtysixthF22, $nF22);
                $sixtysixthF22 = $context->builder->fmul($sixtysixthF22, $nF22);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtysixthF22
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok22Block);
            $sixtysecondVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixtyfirstLong,
                $n
            );
            $ov23 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixtysecondVar->longArithOverflowFlag);
            if (null === $ov23 || null === $sixtysecondVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **66 expected smul overflow metadata (sixtysecond)');
            }
            $sixtysecondLong = JITVariable::KIND_VARIABLE === $sixtysecondVar->kind
                ? $context->builder->load($sixtysecondVar->value)
                : $sixtysecondVar->value;
            $ov23Block = BasicBlockHelper::append($context, 'pow_sixtysixth_sixtysecond_ov');
            $ok23Block = BasicBlockHelper::append($context, 'pow_sixtysixth_sixtysecond_ok');
            $context->builder->branchIf($ov23, $ov23Block, $ok23Block);

            $context->builder->positionAtEnd($ov23Block);
            $sixtysecondF23 = $context->builder->load($sixtysecondVar->longArithOverflowDoubleSlot);
            $nF23 = $context->builder->siToFp($n, $f64);
            $sixtysixthF23 = $context->builder->fmul($sixtysecondF23, $nF23);
            $sixtysixthF23 = $context->builder->fmul($sixtysixthF23, $nF23);
            $sixtysixthF23 = $context->builder->fmul($sixtysixthF23, $nF23);
            $sixtysixthF23 = $context->builder->fmul($sixtysixthF23, $nF23);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtysixthF23
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok23Block);
            $sixtythirdVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixtysecondLong,
                $n
            );
            $ov24 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixtythirdVar->longArithOverflowFlag);
            if (null === $ov24 || null === $sixtythirdVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **66 expected smul overflow metadata (sixtythird)');
            }
            $sixtythirdLong = JITVariable::KIND_VARIABLE === $sixtythirdVar->kind
                ? $context->builder->load($sixtythirdVar->value)
                : $sixtythirdVar->value;
            $ov24Block = BasicBlockHelper::append($context, 'pow_sixtysixth_sixtythird_ov');
            $ok24Block = BasicBlockHelper::append($context, 'pow_sixtysixth_sixtythird_ok');
            $context->builder->branchIf($ov24, $ov24Block, $ok24Block);

            $context->builder->positionAtEnd($ov24Block);
            $sixtythirdF24 = $context->builder->load($sixtythirdVar->longArithOverflowDoubleSlot);
            $nF24 = $context->builder->siToFp($n, $f64);
            $sixtysixthF24 = $context->builder->fmul($sixtythirdF24, $nF24);
            $sixtysixthF24 = $context->builder->fmul($sixtysixthF24, $nF24);
            $sixtysixthF24 = $context->builder->fmul($sixtysixthF24, $nF24);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtysixthF24
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok24Block);
            $sixtyfourthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixtythirdLong,
                $n
            );
            $ov25 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixtyfourthVar->longArithOverflowFlag);
            if (null === $ov25 || null === $sixtyfourthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **66 expected smul overflow metadata (sixtyfourth)');
            }
            $sixtyfourthLong = JITVariable::KIND_VARIABLE === $sixtyfourthVar->kind
                ? $context->builder->load($sixtyfourthVar->value)
                : $sixtyfourthVar->value;
            $ov25Block = BasicBlockHelper::append($context, 'pow_sixtysixth_sixtyfourth_ov');
            $ok25Block = BasicBlockHelper::append($context, 'pow_sixtysixth_sixtyfourth_ok');
            $context->builder->branchIf($ov25, $ov25Block, $ok25Block);

            $context->builder->positionAtEnd($ov25Block);
            $sixtyfourthF25 = $context->builder->load($sixtyfourthVar->longArithOverflowDoubleSlot);
            $nF25 = $context->builder->siToFp($n, $f64);
            $sixtysixthF25 = $context->builder->fmul($sixtyfourthF25, $nF25);
            $sixtysixthF25 = $context->builder->fmul($sixtysixthF25, $nF25);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtysixthF25
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok25Block);
            $sixtyfifthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixtyfourthLong,
                $n
            );
            $ov26 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixtyfifthVar->longArithOverflowFlag);
            if (null === $ov26 || null === $sixtyfifthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **66 expected smul overflow metadata (sixtyfifth)');
            }
            $sixtyfifthLong = JITVariable::KIND_VARIABLE === $sixtyfifthVar->kind
                ? $context->builder->load($sixtyfifthVar->value)
                : $sixtyfifthVar->value;
            $ov26Block = BasicBlockHelper::append($context, 'pow_sixtysixth_sixtyfifth_ov');
            $ok26Block = BasicBlockHelper::append($context, 'pow_sixtysixth_sixtyfifth_ok');
            $context->builder->branchIf($ov26, $ov26Block, $ok26Block);

            $context->builder->positionAtEnd($ov26Block);
            $sixtyfifthF26 = $context->builder->load($sixtyfifthVar->longArithOverflowDoubleSlot);
            $nF26 = $context->builder->siToFp($n, $f64);
            $sixtysixthF26 = $context->builder->fmul($sixtyfifthF26, $nF26);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtysixthF26
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok26Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $sixtyfifthLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);


            $context->builder->positionAtEnd($doneBlock);

            return true;
        }
        if ('sixtyseventh' === $expFold) {
            // n^67 = sixtysixth*n = fortyeighth*sq*n*n*n*n*n*n*n*n*n*n*n*n*n: sq=n*n, cu=sq*n, fifth=cu*sq,
            // tenth=fifth*fifth, twentieth=tenth*tenth,
            // fortieth=twentieth*twentieth, fortysecond=fortieth*sq, fortyfourth=fortysecond*sq,
            // fortysixth=fortyfourth*sq, then fortysixth*sq*sq*n*n*n*n*n*n*n*n*n*n*n*n*n*n*n*n.
            // Overflow arms finish in float (sqF^25*nF*nF*nF*nF*nF*nF*nF*nF*nF*nF,
            // ((cuF*sqF)^2)^4*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF*nF, (fifthF^2)^4*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF, tenthF^4*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF,
            // twentiethF^2*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF*nF, fortiethF*sqF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF, fortysecondF*sqF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF,
            // fortyfourthF*sqF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF*nF, fortysixthF*sqF*sqF*nF*nF*nF*nF*nF*nF*nF*nF*nF*nF).
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
                throw new \LogicException('pow() **67 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_sixtyseventh_done');
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
            $sixtyseventhFSq = $context->builder->fmul($fortyfourthF, $sqF);
            $sixtyseventhFEighth = $context->builder->fmul($sixtyseventhFSq, $sqF);
            $sixtyseventhF = $context->builder->fmul($sixtyseventhFEighth, $sqF);
            $nF = $context->builder->siToFp($n, $f64);
            $sixtyseventhF = $context->builder->fmul($sixtyseventhF, $nF);
            $sixtyseventhF = $context->builder->fmul($sixtyseventhF, $nF);
            $sixtyseventhF = $context->builder->fmul($sixtyseventhF, $nF);
            $sixtyseventhF = $context->builder->fmul($sixtyseventhF, $nF);
            $sixtyseventhF = $context->builder->fmul($sixtyseventhF, $nF);
            $sixtyseventhF = $context->builder->fmul($sixtyseventhF, $nF);
            $sixtyseventhF = $context->builder->fmul($sixtyseventhF, $nF);
            $sixtyseventhF = $context->builder->fmul($sixtyseventhF, $nF);
            $sixtyseventhF = $context->builder->fmul($sixtyseventhF, $nF);
            $sixtyseventhF = $context->builder->fmul($sixtyseventhF, $nF);
                $sixtyseventhF = $context->builder->fmul($sixtyseventhF, $nF);
                    $sixtyseventhF = $context->builder->fmul($sixtyseventhF, $nF);
                        $sixtyseventhF = $context->builder->fmul($sixtyseventhF, $nF);
                        $sixtyseventhF = $context->builder->fmul($sixtyseventhF, $nF);
                        $sixtyseventhF = $context->builder->fmul($sixtyseventhF, $nF);
                        $sixtyseventhF = $context->builder->fmul($sixtyseventhF, $nF);
                        $sixtyseventhF = $context->builder->fmul($sixtyseventhF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyseventhF
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
                throw new \LogicException('pow() **67 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_cu_ok');
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
            $sixtyseventhF2Sq = $context->builder->fmul($fortyfourthF2, $sqF2);
            $sixtyseventhF2Eighth = $context->builder->fmul($sixtyseventhF2Sq, $sqF2);
            $sixtyseventhF2 = $context->builder->fmul($sixtyseventhF2Eighth, $sqF2);
            $nF2 = $context->builder->siToFp($n, $f64);
            $sixtyseventhF2 = $context->builder->fmul($sixtyseventhF2, $nF2);
            $sixtyseventhF2 = $context->builder->fmul($sixtyseventhF2, $nF2);
            $sixtyseventhF2 = $context->builder->fmul($sixtyseventhF2, $nF2);
            $sixtyseventhF2 = $context->builder->fmul($sixtyseventhF2, $nF2);
            $sixtyseventhF2 = $context->builder->fmul($sixtyseventhF2, $nF2);
            $sixtyseventhF2 = $context->builder->fmul($sixtyseventhF2, $nF2);
            $sixtyseventhF2 = $context->builder->fmul($sixtyseventhF2, $nF2);
            $sixtyseventhF2 = $context->builder->fmul($sixtyseventhF2, $nF2);
            $sixtyseventhF2 = $context->builder->fmul($sixtyseventhF2, $nF2);
            $sixtyseventhF2 = $context->builder->fmul($sixtyseventhF2, $nF2);
                $sixtyseventhF2 = $context->builder->fmul($sixtyseventhF2, $nF2);
                    $sixtyseventhF2 = $context->builder->fmul($sixtyseventhF2, $nF2);
                        $sixtyseventhF2 = $context->builder->fmul($sixtyseventhF2, $nF2);
                        $sixtyseventhF2 = $context->builder->fmul($sixtyseventhF2, $nF2);
                        $sixtyseventhF2 = $context->builder->fmul($sixtyseventhF2, $nF2);
                        $sixtyseventhF2 = $context->builder->fmul($sixtyseventhF2, $nF2);
                        $sixtyseventhF2 = $context->builder->fmul($sixtyseventhF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyseventhF2
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
                throw new \LogicException('pow() **67 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $twentiethF3 = $context->builder->fmul($tenthF3, $tenthF3);
            $fortiethF3 = $context->builder->fmul($twentiethF3, $twentiethF3);
            $sqF3 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF3 = $context->builder->fmul($fortiethF3, $sqF3);
            $fortyfourthF3 = $context->builder->fmul($fortysecondF3, $sqF3);
            $sixtyseventhF3Sq = $context->builder->fmul($fortyfourthF3, $sqF3);
            $sixtyseventhF3Eighth = $context->builder->fmul($sixtyseventhF3Sq, $sqF3);
            $sixtyseventhF3 = $context->builder->fmul($sixtyseventhF3Eighth, $sqF3);
            $nF3 = $context->builder->siToFp($n, $f64);
            $sixtyseventhF3 = $context->builder->fmul($sixtyseventhF3, $nF3);
            $sixtyseventhF3 = $context->builder->fmul($sixtyseventhF3, $nF3);
            $sixtyseventhF3 = $context->builder->fmul($sixtyseventhF3, $nF3);
            $sixtyseventhF3 = $context->builder->fmul($sixtyseventhF3, $nF3);
            $sixtyseventhF3 = $context->builder->fmul($sixtyseventhF3, $nF3);
            $sixtyseventhF3 = $context->builder->fmul($sixtyseventhF3, $nF3);
            $sixtyseventhF3 = $context->builder->fmul($sixtyseventhF3, $nF3);
            $sixtyseventhF3 = $context->builder->fmul($sixtyseventhF3, $nF3);
            $sixtyseventhF3 = $context->builder->fmul($sixtyseventhF3, $nF3);
            $sixtyseventhF3 = $context->builder->fmul($sixtyseventhF3, $nF3);
                $sixtyseventhF3 = $context->builder->fmul($sixtyseventhF3, $nF3);
                    $sixtyseventhF3 = $context->builder->fmul($sixtyseventhF3, $nF3);
                        $sixtyseventhF3 = $context->builder->fmul($sixtyseventhF3, $nF3);
                        $sixtyseventhF3 = $context->builder->fmul($sixtyseventhF3, $nF3);
                        $sixtyseventhF3 = $context->builder->fmul($sixtyseventhF3, $nF3);
                        $sixtyseventhF3 = $context->builder->fmul($sixtyseventhF3, $nF3);
                        $sixtyseventhF3 = $context->builder->fmul($sixtyseventhF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyseventhF3
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
                throw new \LogicException('pow() **67 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF4 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $twentiethF4 = $context->builder->fmul($tenthF4, $tenthF4);
            $fortiethF4 = $context->builder->fmul($twentiethF4, $twentiethF4);
            $sqF4 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF4 = $context->builder->fmul($fortiethF4, $sqF4);
            $fortyfourthF4 = $context->builder->fmul($fortysecondF4, $sqF4);
            $sixtyseventhF4Sq = $context->builder->fmul($fortyfourthF4, $sqF4);
            $sixtyseventhF4Eighth = $context->builder->fmul($sixtyseventhF4Sq, $sqF4);
            $sixtyseventhF4 = $context->builder->fmul($sixtyseventhF4Eighth, $sqF4);
            $nF4 = $context->builder->siToFp($n, $f64);
            $sixtyseventhF4 = $context->builder->fmul($sixtyseventhF4, $nF4);
            $sixtyseventhF4 = $context->builder->fmul($sixtyseventhF4, $nF4);
            $sixtyseventhF4 = $context->builder->fmul($sixtyseventhF4, $nF4);
            $sixtyseventhF4 = $context->builder->fmul($sixtyseventhF4, $nF4);
            $sixtyseventhF4 = $context->builder->fmul($sixtyseventhF4, $nF4);
            $sixtyseventhF4 = $context->builder->fmul($sixtyseventhF4, $nF4);
            $sixtyseventhF4 = $context->builder->fmul($sixtyseventhF4, $nF4);
            $sixtyseventhF4 = $context->builder->fmul($sixtyseventhF4, $nF4);
            $sixtyseventhF4 = $context->builder->fmul($sixtyseventhF4, $nF4);
            $sixtyseventhF4 = $context->builder->fmul($sixtyseventhF4, $nF4);
                $sixtyseventhF4 = $context->builder->fmul($sixtyseventhF4, $nF4);
                    $sixtyseventhF4 = $context->builder->fmul($sixtyseventhF4, $nF4);
                        $sixtyseventhF4 = $context->builder->fmul($sixtyseventhF4, $nF4);
                        $sixtyseventhF4 = $context->builder->fmul($sixtyseventhF4, $nF4);
                        $sixtyseventhF4 = $context->builder->fmul($sixtyseventhF4, $nF4);
                        $sixtyseventhF4 = $context->builder->fmul($sixtyseventhF4, $nF4);
                        $sixtyseventhF4 = $context->builder->fmul($sixtyseventhF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyseventhF4
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
                throw new \LogicException('pow() **67 expected smul overflow metadata (twentieth)');
            }
            $twentiethLong = JITVariable::KIND_VARIABLE === $twentiethVar->kind
                ? $context->builder->load($twentiethVar->value)
                : $twentiethVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_twentieth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_twentieth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $twentiethF5 = $context->builder->load($twentiethVar->longArithOverflowDoubleSlot);
            $fortiethF5 = $context->builder->fmul($twentiethF5, $twentiethF5);
            $sqF5 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF5 = $context->builder->fmul($fortiethF5, $sqF5);
            $fortyfourthF5 = $context->builder->fmul($fortysecondF5, $sqF5);
            $sixtyseventhF5Sq = $context->builder->fmul($fortyfourthF5, $sqF5);
            $sixtyseventhF5Eighth = $context->builder->fmul($sixtyseventhF5Sq, $sqF5);
            $sixtyseventhF5 = $context->builder->fmul($sixtyseventhF5Eighth, $sqF5);
            $nF5 = $context->builder->siToFp($n, $f64);
            $sixtyseventhF5 = $context->builder->fmul($sixtyseventhF5, $nF5);
            $sixtyseventhF5 = $context->builder->fmul($sixtyseventhF5, $nF5);
            $sixtyseventhF5 = $context->builder->fmul($sixtyseventhF5, $nF5);
            $sixtyseventhF5 = $context->builder->fmul($sixtyseventhF5, $nF5);
            $sixtyseventhF5 = $context->builder->fmul($sixtyseventhF5, $nF5);
            $sixtyseventhF5 = $context->builder->fmul($sixtyseventhF5, $nF5);
            $sixtyseventhF5 = $context->builder->fmul($sixtyseventhF5, $nF5);
            $sixtyseventhF5 = $context->builder->fmul($sixtyseventhF5, $nF5);
            $sixtyseventhF5 = $context->builder->fmul($sixtyseventhF5, $nF5);
            $sixtyseventhF5 = $context->builder->fmul($sixtyseventhF5, $nF5);
                $sixtyseventhF5 = $context->builder->fmul($sixtyseventhF5, $nF5);
                    $sixtyseventhF5 = $context->builder->fmul($sixtyseventhF5, $nF5);
                        $sixtyseventhF5 = $context->builder->fmul($sixtyseventhF5, $nF5);
                        $sixtyseventhF5 = $context->builder->fmul($sixtyseventhF5, $nF5);
                        $sixtyseventhF5 = $context->builder->fmul($sixtyseventhF5, $nF5);
                        $sixtyseventhF5 = $context->builder->fmul($sixtyseventhF5, $nF5);
                        $sixtyseventhF5 = $context->builder->fmul($sixtyseventhF5, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyseventhF5
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
                throw new \LogicException('pow() **67 expected smul overflow metadata (fortieth)');
            }
            $fortiethLong = JITVariable::KIND_VARIABLE === $fortiethVar->kind
                ? $context->builder->load($fortiethVar->value)
                : $fortiethVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_fortieth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_fortieth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $fortiethF6 = $context->builder->load($fortiethVar->longArithOverflowDoubleSlot);
            $sqF6 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF6 = $context->builder->fmul($fortiethF6, $sqF6);
            $fortyfourthF6 = $context->builder->fmul($fortysecondF6, $sqF6);
            $sixtyseventhF6Sq = $context->builder->fmul($fortyfourthF6, $sqF6);
            $sixtyseventhF6Eighth = $context->builder->fmul($sixtyseventhF6Sq, $sqF6);
            $sixtyseventhF6 = $context->builder->fmul($sixtyseventhF6Eighth, $sqF6);
            $nF6 = $context->builder->siToFp($n, $f64);
            $sixtyseventhF6 = $context->builder->fmul($sixtyseventhF6, $nF6);
            $sixtyseventhF6 = $context->builder->fmul($sixtyseventhF6, $nF6);
            $sixtyseventhF6 = $context->builder->fmul($sixtyseventhF6, $nF6);
            $sixtyseventhF6 = $context->builder->fmul($sixtyseventhF6, $nF6);
            $sixtyseventhF6 = $context->builder->fmul($sixtyseventhF6, $nF6);
            $sixtyseventhF6 = $context->builder->fmul($sixtyseventhF6, $nF6);
            $sixtyseventhF6 = $context->builder->fmul($sixtyseventhF6, $nF6);
            $sixtyseventhF6 = $context->builder->fmul($sixtyseventhF6, $nF6);
            $sixtyseventhF6 = $context->builder->fmul($sixtyseventhF6, $nF6);
            $sixtyseventhF6 = $context->builder->fmul($sixtyseventhF6, $nF6);
                $sixtyseventhF6 = $context->builder->fmul($sixtyseventhF6, $nF6);
                    $sixtyseventhF6 = $context->builder->fmul($sixtyseventhF6, $nF6);
                        $sixtyseventhF6 = $context->builder->fmul($sixtyseventhF6, $nF6);
                        $sixtyseventhF6 = $context->builder->fmul($sixtyseventhF6, $nF6);
                        $sixtyseventhF6 = $context->builder->fmul($sixtyseventhF6, $nF6);
                        $sixtyseventhF6 = $context->builder->fmul($sixtyseventhF6, $nF6);
                        $sixtyseventhF6 = $context->builder->fmul($sixtyseventhF6, $nF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyseventhF6
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
                throw new \LogicException('pow() **67 expected smul overflow metadata (fortysecond)');
            }
            $fortysecondLong = JITVariable::KIND_VARIABLE === $fortysecondVar->kind
                ? $context->builder->load($fortysecondVar->value)
                : $fortysecondVar->value;
            $ov7Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_fortysecond_ov');
            $ok7Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_fortysecond_ok');
            $context->builder->branchIf($ov7, $ov7Block, $ok7Block);

            $context->builder->positionAtEnd($ov7Block);
            $fortysecondF7 = $context->builder->load($fortysecondVar->longArithOverflowDoubleSlot);
            $sqF7 = $context->builder->siToFp($sqLong, $f64);
            $fortyfourthF7 = $context->builder->fmul($fortysecondF7, $sqF7);
            $sixtyseventhF7Sq = $context->builder->fmul($fortyfourthF7, $sqF7);
            $sixtyseventhF7Eighth = $context->builder->fmul($sixtyseventhF7Sq, $sqF7);
            $sixtyseventhF7 = $context->builder->fmul($sixtyseventhF7Eighth, $sqF7);
            $nF7 = $context->builder->siToFp($n, $f64);
            $sixtyseventhF7 = $context->builder->fmul($sixtyseventhF7, $nF7);
            $sixtyseventhF7 = $context->builder->fmul($sixtyseventhF7, $nF7);
            $sixtyseventhF7 = $context->builder->fmul($sixtyseventhF7, $nF7);
            $sixtyseventhF7 = $context->builder->fmul($sixtyseventhF7, $nF7);
            $sixtyseventhF7 = $context->builder->fmul($sixtyseventhF7, $nF7);
            $sixtyseventhF7 = $context->builder->fmul($sixtyseventhF7, $nF7);
            $sixtyseventhF7 = $context->builder->fmul($sixtyseventhF7, $nF7);
            $sixtyseventhF7 = $context->builder->fmul($sixtyseventhF7, $nF7);
            $sixtyseventhF7 = $context->builder->fmul($sixtyseventhF7, $nF7);
            $sixtyseventhF7 = $context->builder->fmul($sixtyseventhF7, $nF7);
                $sixtyseventhF7 = $context->builder->fmul($sixtyseventhF7, $nF7);
                    $sixtyseventhF7 = $context->builder->fmul($sixtyseventhF7, $nF7);
                        $sixtyseventhF7 = $context->builder->fmul($sixtyseventhF7, $nF7);
                        $sixtyseventhF7 = $context->builder->fmul($sixtyseventhF7, $nF7);
                        $sixtyseventhF7 = $context->builder->fmul($sixtyseventhF7, $nF7);
                        $sixtyseventhF7 = $context->builder->fmul($sixtyseventhF7, $nF7);
                        $sixtyseventhF7 = $context->builder->fmul($sixtyseventhF7, $nF7);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyseventhF7
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
                throw new \LogicException('pow() **67 expected smul overflow metadata (fortyfourth)');
            }
            $fortyfourthLong = JITVariable::KIND_VARIABLE === $fortyfourthVar->kind
                ? $context->builder->load($fortyfourthVar->value)
                : $fortyfourthVar->value;
            $ov8Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_fortyfourth_ov');
            $ok8Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_fortyfourth_ok');
            $context->builder->branchIf($ov8, $ov8Block, $ok8Block);

            $context->builder->positionAtEnd($ov8Block);
            $fortyfourthF8 = $context->builder->load($fortyfourthVar->longArithOverflowDoubleSlot);
            $sqF8 = $context->builder->siToFp($sqLong, $f64);
            $sixtyseventhF8Sq = $context->builder->fmul($fortyfourthF8, $sqF8);
            $sixtyseventhF8Eighth = $context->builder->fmul($sixtyseventhF8Sq, $sqF8);
            $sixtyseventhF8 = $context->builder->fmul($sixtyseventhF8Eighth, $sqF8);
            $nF8 = $context->builder->siToFp($n, $f64);
            $sixtyseventhF8 = $context->builder->fmul($sixtyseventhF8, $nF8);
            $sixtyseventhF8 = $context->builder->fmul($sixtyseventhF8, $nF8);
            $sixtyseventhF8 = $context->builder->fmul($sixtyseventhF8, $nF8);
            $sixtyseventhF8 = $context->builder->fmul($sixtyseventhF8, $nF8);
            $sixtyseventhF8 = $context->builder->fmul($sixtyseventhF8, $nF8);
            $sixtyseventhF8 = $context->builder->fmul($sixtyseventhF8, $nF8);
            $sixtyseventhF8 = $context->builder->fmul($sixtyseventhF8, $nF8);
            $sixtyseventhF8 = $context->builder->fmul($sixtyseventhF8, $nF8);
            $sixtyseventhF8 = $context->builder->fmul($sixtyseventhF8, $nF8);
            $sixtyseventhF8 = $context->builder->fmul($sixtyseventhF8, $nF8);
                $sixtyseventhF8 = $context->builder->fmul($sixtyseventhF8, $nF8);
                    $sixtyseventhF8 = $context->builder->fmul($sixtyseventhF8, $nF8);
                        $sixtyseventhF8 = $context->builder->fmul($sixtyseventhF8, $nF8);
                        $sixtyseventhF8 = $context->builder->fmul($sixtyseventhF8, $nF8);
                        $sixtyseventhF8 = $context->builder->fmul($sixtyseventhF8, $nF8);
                        $sixtyseventhF8 = $context->builder->fmul($sixtyseventhF8, $nF8);
                        $sixtyseventhF8 = $context->builder->fmul($sixtyseventhF8, $nF8);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyseventhF8
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
                throw new \LogicException('pow() **67 expected smul overflow metadata (fortysixth)');
            }
            $fortysixthLong = JITVariable::KIND_VARIABLE === $fortysixthVar->kind
                ? $context->builder->load($fortysixthVar->value)
                : $fortysixthVar->value;
            $ov9Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_fortysixth_ov');
            $ok9Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_fortysixth_ok');
            $context->builder->branchIf($ov9, $ov9Block, $ok9Block);

            $context->builder->positionAtEnd($ov9Block);
            $fortysixthF9 = $context->builder->load($fortysixthVar->longArithOverflowDoubleSlot);
            $sqF9 = $context->builder->siToFp($sqLong, $f64);
            $fortyeighthF9 = $context->builder->fmul($fortysixthF9, $sqF9);
            $sixtyseventhF9 = $context->builder->fmul($fortyeighthF9, $sqF9);
            $nF9 = $context->builder->siToFp($n, $f64);
            $sixtyseventhF9 = $context->builder->fmul($sixtyseventhF9, $nF9);
            $sixtyseventhF9 = $context->builder->fmul($sixtyseventhF9, $nF9);
            $sixtyseventhF9 = $context->builder->fmul($sixtyseventhF9, $nF9);
            $sixtyseventhF9 = $context->builder->fmul($sixtyseventhF9, $nF9);
            $sixtyseventhF9 = $context->builder->fmul($sixtyseventhF9, $nF9);
            $sixtyseventhF9 = $context->builder->fmul($sixtyseventhF9, $nF9);
            $sixtyseventhF9 = $context->builder->fmul($sixtyseventhF9, $nF9);
            $sixtyseventhF9 = $context->builder->fmul($sixtyseventhF9, $nF9);
            $sixtyseventhF9 = $context->builder->fmul($sixtyseventhF9, $nF9);
            $sixtyseventhF9 = $context->builder->fmul($sixtyseventhF9, $nF9);
                $sixtyseventhF9 = $context->builder->fmul($sixtyseventhF9, $nF9);
                    $sixtyseventhF9 = $context->builder->fmul($sixtyseventhF9, $nF9);
                        $sixtyseventhF9 = $context->builder->fmul($sixtyseventhF9, $nF9);
                        $sixtyseventhF9 = $context->builder->fmul($sixtyseventhF9, $nF9);
                        $sixtyseventhF9 = $context->builder->fmul($sixtyseventhF9, $nF9);
                        $sixtyseventhF9 = $context->builder->fmul($sixtyseventhF9, $nF9);
                        $sixtyseventhF9 = $context->builder->fmul($sixtyseventhF9, $nF9);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyseventhF9
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
                throw new \LogicException('pow() **67 expected smul overflow metadata (fortyeighth)');
            }
            $fortyeighthLong = JITVariable::KIND_VARIABLE === $fortyeighthVar->kind
                ? $context->builder->load($fortyeighthVar->value)
                : $fortyeighthVar->value;
            $ov10Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_fortyeighth_ov');
            $ok10Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_fortyeighth_ok');
            $context->builder->branchIf($ov10, $ov10Block, $ok10Block);

            $context->builder->positionAtEnd($ov10Block);
            $fortyeighthF10 = $context->builder->load($fortyeighthVar->longArithOverflowDoubleSlot);
            $sqF10 = $context->builder->siToFp($sqLong, $f64);
            $sixtyseventhF10 = $context->builder->fmul($fortyeighthF10, $sqF10);
            $nF10 = $context->builder->siToFp($n, $f64);
            $sixtyseventhF10 = $context->builder->fmul($sixtyseventhF10, $nF10);
            $sixtyseventhF10 = $context->builder->fmul($sixtyseventhF10, $nF10);
            $sixtyseventhF10 = $context->builder->fmul($sixtyseventhF10, $nF10);
            $sixtyseventhF10 = $context->builder->fmul($sixtyseventhF10, $nF10);
            $sixtyseventhF10 = $context->builder->fmul($sixtyseventhF10, $nF10);
            $sixtyseventhF10 = $context->builder->fmul($sixtyseventhF10, $nF10);
            $sixtyseventhF10 = $context->builder->fmul($sixtyseventhF10, $nF10);
            $sixtyseventhF10 = $context->builder->fmul($sixtyseventhF10, $nF10);
            $sixtyseventhF10 = $context->builder->fmul($sixtyseventhF10, $nF10);
            $sixtyseventhF10 = $context->builder->fmul($sixtyseventhF10, $nF10);
                $sixtyseventhF10 = $context->builder->fmul($sixtyseventhF10, $nF10);
                    $sixtyseventhF10 = $context->builder->fmul($sixtyseventhF10, $nF10);
                        $sixtyseventhF10 = $context->builder->fmul($sixtyseventhF10, $nF10);
                        $sixtyseventhF10 = $context->builder->fmul($sixtyseventhF10, $nF10);
                        $sixtyseventhF10 = $context->builder->fmul($sixtyseventhF10, $nF10);
                        $sixtyseventhF10 = $context->builder->fmul($sixtyseventhF10, $nF10);
                        $sixtyseventhF10 = $context->builder->fmul($sixtyseventhF10, $nF10);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyseventhF10
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok10Block);
            $fiftiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fortyeighthLong,
                $sqLong
            );
            $ov11 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftiethVar->longArithOverflowFlag);
            if (null === $ov11 || null === $fiftiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **67 expected smul overflow metadata (fiftieth)');
            }
            $fiftiethLong = JITVariable::KIND_VARIABLE === $fiftiethVar->kind
                ? $context->builder->load($fiftiethVar->value)
                : $fiftiethVar->value;
            $ov11Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_fiftieth_ov');
            $ok11Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_fiftieth_ok');
            $context->builder->branchIf($ov11, $ov11Block, $ok11Block);

            $context->builder->positionAtEnd($ov11Block);
            $fiftiethF11 = $context->builder->load($fiftiethVar->longArithOverflowDoubleSlot);
            $nF11 = $context->builder->siToFp($n, $f64);
            $sixtyseventhF11 = $context->builder->fmul($fiftiethF11, $nF11);
            $sixtyseventhF11 = $context->builder->fmul($sixtyseventhF11, $nF11);
            $sixtyseventhF11 = $context->builder->fmul($sixtyseventhF11, $nF11);
            $sixtyseventhF11 = $context->builder->fmul($sixtyseventhF11, $nF11);
            $sixtyseventhF11 = $context->builder->fmul($sixtyseventhF11, $nF11);
            $sixtyseventhF11 = $context->builder->fmul($sixtyseventhF11, $nF11);
            $sixtyseventhF11 = $context->builder->fmul($sixtyseventhF11, $nF11);
            $sixtyseventhF11 = $context->builder->fmul($sixtyseventhF11, $nF11);
            $sixtyseventhF11 = $context->builder->fmul($sixtyseventhF11, $nF11);
            $sixtyseventhF11 = $context->builder->fmul($sixtyseventhF11, $nF11);
                $sixtyseventhF11 = $context->builder->fmul($sixtyseventhF11, $nF11);
                    $sixtyseventhF11 = $context->builder->fmul($sixtyseventhF11, $nF11);
                        $sixtyseventhF11 = $context->builder->fmul($sixtyseventhF11, $nF11);
                        $sixtyseventhF11 = $context->builder->fmul($sixtyseventhF11, $nF11);
                        $sixtyseventhF11 = $context->builder->fmul($sixtyseventhF11, $nF11);
                        $sixtyseventhF11 = $context->builder->fmul($sixtyseventhF11, $nF11);
                        $sixtyseventhF11 = $context->builder->fmul($sixtyseventhF11, $nF11);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyseventhF11
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok11Block);
            $fiftyfirstVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftiethLong,
                $n
            );
            $ov12 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyfirstVar->longArithOverflowFlag);
            if (null === $ov12 || null === $fiftyfirstVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **67 expected smul overflow metadata (fiftyfirst)');
            }
            $fiftyfirstLong = JITVariable::KIND_VARIABLE === $fiftyfirstVar->kind
                ? $context->builder->load($fiftyfirstVar->value)
                : $fiftyfirstVar->value;
            $ov12Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_fiftyfirst_ov');
            $ok12Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_fiftyfirst_ok');
            $context->builder->branchIf($ov12, $ov12Block, $ok12Block);

            $context->builder->positionAtEnd($ov12Block);
            $fiftyfirstF12 = $context->builder->load($fiftyfirstVar->longArithOverflowDoubleSlot);
            $nF12 = $context->builder->siToFp($n, $f64);
            $sixtyseventhF12 = $context->builder->fmul($fiftyfirstF12, $nF12);
            $sixtyseventhF12 = $context->builder->fmul($sixtyseventhF12, $nF12);
            $sixtyseventhF12 = $context->builder->fmul($sixtyseventhF12, $nF12);
            $sixtyseventhF12 = $context->builder->fmul($sixtyseventhF12, $nF12);
            $sixtyseventhF12 = $context->builder->fmul($sixtyseventhF12, $nF12);
            $sixtyseventhF12 = $context->builder->fmul($sixtyseventhF12, $nF12);
            $sixtyseventhF12 = $context->builder->fmul($sixtyseventhF12, $nF12);
            $sixtyseventhF12 = $context->builder->fmul($sixtyseventhF12, $nF12);
            $sixtyseventhF12 = $context->builder->fmul($sixtyseventhF12, $nF12);
                $sixtyseventhF12 = $context->builder->fmul($sixtyseventhF12, $nF12);
                    $sixtyseventhF12 = $context->builder->fmul($sixtyseventhF12, $nF12);
                        $sixtyseventhF12 = $context->builder->fmul($sixtyseventhF12, $nF12);
                        $sixtyseventhF12 = $context->builder->fmul($sixtyseventhF12, $nF12);
                        $sixtyseventhF12 = $context->builder->fmul($sixtyseventhF12, $nF12);
                        $sixtyseventhF12 = $context->builder->fmul($sixtyseventhF12, $nF12);
                        $sixtyseventhF12 = $context->builder->fmul($sixtyseventhF12, $nF12);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyseventhF12
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok12Block);
            $fiftysecondVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyfirstLong,
                $n
            );
            $ov13 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftysecondVar->longArithOverflowFlag);
            if (null === $ov13 || null === $fiftysecondVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **67 expected smul overflow metadata (fiftysecond)');
            }
            $fiftysecondLong = JITVariable::KIND_VARIABLE === $fiftysecondVar->kind
                ? $context->builder->load($fiftysecondVar->value)
                : $fiftysecondVar->value;
            $ov13Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_fiftysecond_ov');
            $ok13Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_fiftysecond_ok');
            $context->builder->branchIf($ov13, $ov13Block, $ok13Block);

            $context->builder->positionAtEnd($ov13Block);
            $fiftysecondF13 = $context->builder->load($fiftysecondVar->longArithOverflowDoubleSlot);
            $nF13 = $context->builder->siToFp($n, $f64);
            $sixtyseventhF13 = $context->builder->fmul($fiftysecondF13, $nF13);
            $sixtyseventhF13 = $context->builder->fmul($sixtyseventhF13, $nF13);
            $sixtyseventhF13 = $context->builder->fmul($sixtyseventhF13, $nF13);
            $sixtyseventhF13 = $context->builder->fmul($sixtyseventhF13, $nF13);
            $sixtyseventhF13 = $context->builder->fmul($sixtyseventhF13, $nF13);
            $sixtyseventhF13 = $context->builder->fmul($sixtyseventhF13, $nF13);
            $sixtyseventhF13 = $context->builder->fmul($sixtyseventhF13, $nF13);
            $sixtyseventhF13 = $context->builder->fmul($sixtyseventhF13, $nF13);
                $sixtyseventhF13 = $context->builder->fmul($sixtyseventhF13, $nF13);
                    $sixtyseventhF13 = $context->builder->fmul($sixtyseventhF13, $nF13);
                        $sixtyseventhF13 = $context->builder->fmul($sixtyseventhF13, $nF13);
                        $sixtyseventhF13 = $context->builder->fmul($sixtyseventhF13, $nF13);
                        $sixtyseventhF13 = $context->builder->fmul($sixtyseventhF13, $nF13);
                        $sixtyseventhF13 = $context->builder->fmul($sixtyseventhF13, $nF13);
                        $sixtyseventhF13 = $context->builder->fmul($sixtyseventhF13, $nF13);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyseventhF13
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok13Block);
            $fiftythirdVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftysecondLong,
                $n
            );
            $ov14 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftythirdVar->longArithOverflowFlag);
            if (null === $ov14 || null === $fiftythirdVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **67 expected smul overflow metadata (fiftythird)');
            }
            $fiftythirdLong = JITVariable::KIND_VARIABLE === $fiftythirdVar->kind
                ? $context->builder->load($fiftythirdVar->value)
                : $fiftythirdVar->value;
            $ov14Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_fiftythird_ov');
            $ok14Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_fiftythird_ok');
            $context->builder->branchIf($ov14, $ov14Block, $ok14Block);

            $context->builder->positionAtEnd($ov14Block);
            $fiftythirdF14 = $context->builder->load($fiftythirdVar->longArithOverflowDoubleSlot);
            $nF14 = $context->builder->siToFp($n, $f64);
            $sixtyseventhF14 = $context->builder->fmul($fiftythirdF14, $nF14);
            $sixtyseventhF14 = $context->builder->fmul($sixtyseventhF14, $nF14);
            $sixtyseventhF14 = $context->builder->fmul($sixtyseventhF14, $nF14);
            $sixtyseventhF14 = $context->builder->fmul($sixtyseventhF14, $nF14);
            $sixtyseventhF14 = $context->builder->fmul($sixtyseventhF14, $nF14);
            $sixtyseventhF14 = $context->builder->fmul($sixtyseventhF14, $nF14);
            $sixtyseventhF14 = $context->builder->fmul($sixtyseventhF14, $nF14);
                $sixtyseventhF14 = $context->builder->fmul($sixtyseventhF14, $nF14);
                    $sixtyseventhF14 = $context->builder->fmul($sixtyseventhF14, $nF14);
                        $sixtyseventhF14 = $context->builder->fmul($sixtyseventhF14, $nF14);
                        $sixtyseventhF14 = $context->builder->fmul($sixtyseventhF14, $nF14);
                        $sixtyseventhF14 = $context->builder->fmul($sixtyseventhF14, $nF14);
                        $sixtyseventhF14 = $context->builder->fmul($sixtyseventhF14, $nF14);
                        $sixtyseventhF14 = $context->builder->fmul($sixtyseventhF14, $nF14);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyseventhF14
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok14Block);
            $fiftyfourthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftythirdLong,
                $n
            );
            $ov15 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyfourthVar->longArithOverflowFlag);
            if (null === $ov15 || null === $fiftyfourthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **67 expected smul overflow metadata (fiftyfourth)');
            }
            $fiftyfourthLong = JITVariable::KIND_VARIABLE === $fiftyfourthVar->kind
                ? $context->builder->load($fiftyfourthVar->value)
                : $fiftyfourthVar->value;
            $ov15Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_fiftyfourth_ov');
            $ok15Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_fiftyfourth_ok');
            $context->builder->branchIf($ov15, $ov15Block, $ok15Block);

            $context->builder->positionAtEnd($ov15Block);
            $fiftyfourthF15 = $context->builder->load($fiftyfourthVar->longArithOverflowDoubleSlot);
            $nF15 = $context->builder->siToFp($n, $f64);
            $sixtyseventhF15 = $context->builder->fmul($fiftyfourthF15, $nF15);
            $sixtyseventhF15 = $context->builder->fmul($sixtyseventhF15, $nF15);
            $sixtyseventhF15 = $context->builder->fmul($sixtyseventhF15, $nF15);
            $sixtyseventhF15 = $context->builder->fmul($sixtyseventhF15, $nF15);
            $sixtyseventhF15 = $context->builder->fmul($sixtyseventhF15, $nF15);
            $sixtyseventhF15 = $context->builder->fmul($sixtyseventhF15, $nF15);
                $sixtyseventhF15 = $context->builder->fmul($sixtyseventhF15, $nF15);
                    $sixtyseventhF15 = $context->builder->fmul($sixtyseventhF15, $nF15);
                        $sixtyseventhF15 = $context->builder->fmul($sixtyseventhF15, $nF15);
                        $sixtyseventhF15 = $context->builder->fmul($sixtyseventhF15, $nF15);
                        $sixtyseventhF15 = $context->builder->fmul($sixtyseventhF15, $nF15);
                        $sixtyseventhF15 = $context->builder->fmul($sixtyseventhF15, $nF15);
                        $sixtyseventhF15 = $context->builder->fmul($sixtyseventhF15, $nF15);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyseventhF15
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok15Block);
            $fiftyfifthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyfourthLong,
                $n
            );
            $ov16 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyfifthVar->longArithOverflowFlag);
            if (null === $ov16 || null === $fiftyfifthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **67 expected smul overflow metadata (fiftyfifth)');
            }
            $fiftyfifthLong = JITVariable::KIND_VARIABLE === $fiftyfifthVar->kind
                ? $context->builder->load($fiftyfifthVar->value)
                : $fiftyfifthVar->value;
            $ov16Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_fiftyfifth_ov');
            $ok16Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_fiftyfifth_ok');
            $context->builder->branchIf($ov16, $ov16Block, $ok16Block);

            $context->builder->positionAtEnd($ov16Block);
            $fiftyfifthF16 = $context->builder->load($fiftyfifthVar->longArithOverflowDoubleSlot);
            $nF16 = $context->builder->siToFp($n, $f64);
            $sixtyseventhF16 = $context->builder->fmul($fiftyfifthF16, $nF16);
            $sixtyseventhF16 = $context->builder->fmul($sixtyseventhF16, $nF16);
            $sixtyseventhF16 = $context->builder->fmul($sixtyseventhF16, $nF16);
            $sixtyseventhF16 = $context->builder->fmul($sixtyseventhF16, $nF16);
            $sixtyseventhF16 = $context->builder->fmul($sixtyseventhF16, $nF16);
                $sixtyseventhF16 = $context->builder->fmul($sixtyseventhF16, $nF16);
                    $sixtyseventhF16 = $context->builder->fmul($sixtyseventhF16, $nF16);
                        $sixtyseventhF16 = $context->builder->fmul($sixtyseventhF16, $nF16);
                        $sixtyseventhF16 = $context->builder->fmul($sixtyseventhF16, $nF16);
                        $sixtyseventhF16 = $context->builder->fmul($sixtyseventhF16, $nF16);
                        $sixtyseventhF16 = $context->builder->fmul($sixtyseventhF16, $nF16);
                        $sixtyseventhF16 = $context->builder->fmul($sixtyseventhF16, $nF16);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyseventhF16
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok16Block);
            $fiftysixthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyfifthLong,
                $n
            );
            $ov17 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftysixthVar->longArithOverflowFlag);
            if (null === $ov17 || null === $fiftysixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **67 expected smul overflow metadata (fiftysixth)');
            }
            $fiftysixthLong = JITVariable::KIND_VARIABLE === $fiftysixthVar->kind
                ? $context->builder->load($fiftysixthVar->value)
                : $fiftysixthVar->value;
            $ov17Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_fiftysixth_ov');
            $ok17Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_fiftysixth_ok');
            $context->builder->branchIf($ov17, $ov17Block, $ok17Block);

            $context->builder->positionAtEnd($ov17Block);
            $fiftysixthF17 = $context->builder->load($fiftysixthVar->longArithOverflowDoubleSlot);
            $nF17 = $context->builder->siToFp($n, $f64);
            $sixtyseventhF17 = $context->builder->fmul($fiftysixthF17, $nF17);
            $sixtyseventhF17 = $context->builder->fmul($sixtyseventhF17, $nF17);
            $sixtyseventhF17 = $context->builder->fmul($sixtyseventhF17, $nF17);
            $sixtyseventhF17 = $context->builder->fmul($sixtyseventhF17, $nF17);
                $sixtyseventhF17 = $context->builder->fmul($sixtyseventhF17, $nF17);
                    $sixtyseventhF17 = $context->builder->fmul($sixtyseventhF17, $nF17);
                        $sixtyseventhF17 = $context->builder->fmul($sixtyseventhF17, $nF17);
                        $sixtyseventhF17 = $context->builder->fmul($sixtyseventhF17, $nF17);
                        $sixtyseventhF17 = $context->builder->fmul($sixtyseventhF17, $nF17);
                        $sixtyseventhF17 = $context->builder->fmul($sixtyseventhF17, $nF17);
                        $sixtyseventhF17 = $context->builder->fmul($sixtyseventhF17, $nF17);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyseventhF17
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok17Block);
            $fiftyseventhVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftysixthLong,
                $n
            );
            $ov18 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyseventhVar->longArithOverflowFlag);
            if (null === $ov18 || null === $fiftyseventhVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **67 expected smul overflow metadata (fiftyseventh)');
            }
            $fiftyseventhLong = JITVariable::KIND_VARIABLE === $fiftyseventhVar->kind
                ? $context->builder->load($fiftyseventhVar->value)
                : $fiftyseventhVar->value;
            $ov18Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_fiftyseventh_ov');
            $ok18Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_fiftyseventh_ok');
            $context->builder->branchIf($ov18, $ov18Block, $ok18Block);

            $context->builder->positionAtEnd($ov18Block);
            $fiftyseventhF18 = $context->builder->load($fiftyseventhVar->longArithOverflowDoubleSlot);
            $nF18 = $context->builder->siToFp($n, $f64);
            $sixtyseventhF18 = $context->builder->fmul($fiftyseventhF18, $nF18);
            $sixtyseventhF18 = $context->builder->fmul($sixtyseventhF18, $nF18);
            $sixtyseventhF18 = $context->builder->fmul($sixtyseventhF18, $nF18);
                $sixtyseventhF18 = $context->builder->fmul($sixtyseventhF18, $nF18);
                    $sixtyseventhF18 = $context->builder->fmul($sixtyseventhF18, $nF18);
                        $sixtyseventhF18 = $context->builder->fmul($sixtyseventhF18, $nF18);
                        $sixtyseventhF18 = $context->builder->fmul($sixtyseventhF18, $nF18);
                        $sixtyseventhF18 = $context->builder->fmul($sixtyseventhF18, $nF18);
                        $sixtyseventhF18 = $context->builder->fmul($sixtyseventhF18, $nF18);
                        $sixtyseventhF18 = $context->builder->fmul($sixtyseventhF18, $nF18);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyseventhF18
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok18Block);
            $fiftyeighthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyseventhLong,
                $n
            );
            $ov19 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyeighthVar->longArithOverflowFlag);
            if (null === $ov19 || null === $fiftyeighthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **67 expected smul overflow metadata (fiftyeighth)');
            }
            $fiftyeighthLong = JITVariable::KIND_VARIABLE === $fiftyeighthVar->kind
                ? $context->builder->load($fiftyeighthVar->value)
                : $fiftyeighthVar->value;
            $ov19Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_fiftyeighth_ov');
            $ok19Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_fiftyeighth_ok');
            $context->builder->branchIf($ov19, $ov19Block, $ok19Block);

            $context->builder->positionAtEnd($ov19Block);
            $fiftyeighthF19 = $context->builder->load($fiftyeighthVar->longArithOverflowDoubleSlot);
            $nF19 = $context->builder->siToFp($n, $f64);
            $sixtyseventhF19 = $context->builder->fmul($fiftyeighthF19, $nF19);
            $sixtyseventhF19 = $context->builder->fmul($sixtyseventhF19, $nF19);
                $sixtyseventhF19 = $context->builder->fmul($sixtyseventhF19, $nF19);
                    $sixtyseventhF19 = $context->builder->fmul($sixtyseventhF19, $nF19);
                        $sixtyseventhF19 = $context->builder->fmul($sixtyseventhF19, $nF19);
                        $sixtyseventhF19 = $context->builder->fmul($sixtyseventhF19, $nF19);
                        $sixtyseventhF19 = $context->builder->fmul($sixtyseventhF19, $nF19);
                        $sixtyseventhF19 = $context->builder->fmul($sixtyseventhF19, $nF19);
                        $sixtyseventhF19 = $context->builder->fmul($sixtyseventhF19, $nF19);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyseventhF19
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok19Block);
            $fiftyninthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyeighthLong,
                $n
            );
            $ov20 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyninthVar->longArithOverflowFlag);
            if (null === $ov20 || null === $fiftyninthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **67 expected smul overflow metadata (fiftyninth)');
            }
            $fiftyninthLong = JITVariable::KIND_VARIABLE === $fiftyninthVar->kind
                ? $context->builder->load($fiftyninthVar->value)
                : $fiftyninthVar->value;
            $ov20Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_fiftyninth_ov');
            $ok20Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_fiftyninth_ok');
            $context->builder->branchIf($ov20, $ov20Block, $ok20Block);

            $context->builder->positionAtEnd($ov20Block);
            $fiftyninthF20 = $context->builder->load($fiftyninthVar->longArithOverflowDoubleSlot);
            $nF20 = $context->builder->siToFp($n, $f64);
            $sixtyseventhF20 = $context->builder->fmul($fiftyninthF20, $nF20);
                $sixtyseventhF20 = $context->builder->fmul($sixtyseventhF20, $nF20);
                    $sixtyseventhF20 = $context->builder->fmul($sixtyseventhF20, $nF20);
                        $sixtyseventhF20 = $context->builder->fmul($sixtyseventhF20, $nF20);
                        $sixtyseventhF20 = $context->builder->fmul($sixtyseventhF20, $nF20);
                        $sixtyseventhF20 = $context->builder->fmul($sixtyseventhF20, $nF20);
                        $sixtyseventhF20 = $context->builder->fmul($sixtyseventhF20, $nF20);
                        $sixtyseventhF20 = $context->builder->fmul($sixtyseventhF20, $nF20);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyseventhF20
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok20Block);
            $sixtiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyninthLong,
                $n
            );
            $ov21 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixtiethVar->longArithOverflowFlag);
            if (null === $ov21 || null === $sixtiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **67 expected smul overflow metadata (sixtieth)');
            }
            $sixtiethLong = JITVariable::KIND_VARIABLE === $sixtiethVar->kind
                ? $context->builder->load($sixtiethVar->value)
                : $sixtiethVar->value;
            $ov21Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_sixtieth_ov');
            $ok21Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_sixtieth_ok');
            $context->builder->branchIf($ov21, $ov21Block, $ok21Block);

            $context->builder->positionAtEnd($ov21Block);
            $sixtiethF21 = $context->builder->load($sixtiethVar->longArithOverflowDoubleSlot);
            $nF21 = $context->builder->siToFp($n, $f64);
            $sixtyseventhF21 = $context->builder->fmul($sixtiethF21, $nF21);
                $sixtyseventhF21 = $context->builder->fmul($sixtyseventhF21, $nF21);
                    $sixtyseventhF21 = $context->builder->fmul($sixtyseventhF21, $nF21);
                    $sixtyseventhF21 = $context->builder->fmul($sixtyseventhF21, $nF21);
                    $sixtyseventhF21 = $context->builder->fmul($sixtyseventhF21, $nF21);
                    $sixtyseventhF21 = $context->builder->fmul($sixtyseventhF21, $nF21);
                    $sixtyseventhF21 = $context->builder->fmul($sixtyseventhF21, $nF21);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyseventhF21
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok21Block);
            $sixtyfirstVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixtiethLong,
                $n
            );
            $ov22 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixtyfirstVar->longArithOverflowFlag);
            if (null === $ov22 || null === $sixtyfirstVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **67 expected smul overflow metadata (sixtyfirst)');
            }
            $sixtyfirstLong = JITVariable::KIND_VARIABLE === $sixtyfirstVar->kind
                ? $context->builder->load($sixtyfirstVar->value)
                : $sixtyfirstVar->value;
            $ov22Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_sixtyfirst_ov');
            $ok22Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_sixtyfirst_ok');
            $context->builder->branchIf($ov22, $ov22Block, $ok22Block);

            $context->builder->positionAtEnd($ov22Block);
            $sixtyfirstF22 = $context->builder->load($sixtyfirstVar->longArithOverflowDoubleSlot);
            $nF22 = $context->builder->siToFp($n, $f64);
            $sixtyseventhF22 = $context->builder->fmul($sixtyfirstF22, $nF22);
                $sixtyseventhF22 = $context->builder->fmul($sixtyseventhF22, $nF22);
                $sixtyseventhF22 = $context->builder->fmul($sixtyseventhF22, $nF22);
                $sixtyseventhF22 = $context->builder->fmul($sixtyseventhF22, $nF22);
                $sixtyseventhF22 = $context->builder->fmul($sixtyseventhF22, $nF22);
                $sixtyseventhF22 = $context->builder->fmul($sixtyseventhF22, $nF22);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyseventhF22
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok22Block);
            $sixtysecondVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixtyfirstLong,
                $n
            );
            $ov23 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixtysecondVar->longArithOverflowFlag);
            if (null === $ov23 || null === $sixtysecondVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **67 expected smul overflow metadata (sixtysecond)');
            }
            $sixtysecondLong = JITVariable::KIND_VARIABLE === $sixtysecondVar->kind
                ? $context->builder->load($sixtysecondVar->value)
                : $sixtysecondVar->value;
            $ov23Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_sixtysecond_ov');
            $ok23Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_sixtysecond_ok');
            $context->builder->branchIf($ov23, $ov23Block, $ok23Block);

            $context->builder->positionAtEnd($ov23Block);
            $sixtysecondF23 = $context->builder->load($sixtysecondVar->longArithOverflowDoubleSlot);
            $nF23 = $context->builder->siToFp($n, $f64);
            $sixtyseventhF23 = $context->builder->fmul($sixtysecondF23, $nF23);
            $sixtyseventhF23 = $context->builder->fmul($sixtyseventhF23, $nF23);
            $sixtyseventhF23 = $context->builder->fmul($sixtyseventhF23, $nF23);
            $sixtyseventhF23 = $context->builder->fmul($sixtyseventhF23, $nF23);
            $sixtyseventhF23 = $context->builder->fmul($sixtyseventhF23, $nF23);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyseventhF23
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok23Block);
            $sixtythirdVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixtysecondLong,
                $n
            );
            $ov24 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixtythirdVar->longArithOverflowFlag);
            if (null === $ov24 || null === $sixtythirdVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **67 expected smul overflow metadata (sixtythird)');
            }
            $sixtythirdLong = JITVariable::KIND_VARIABLE === $sixtythirdVar->kind
                ? $context->builder->load($sixtythirdVar->value)
                : $sixtythirdVar->value;
            $ov24Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_sixtythird_ov');
            $ok24Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_sixtythird_ok');
            $context->builder->branchIf($ov24, $ov24Block, $ok24Block);

            $context->builder->positionAtEnd($ov24Block);
            $sixtythirdF24 = $context->builder->load($sixtythirdVar->longArithOverflowDoubleSlot);
            $nF24 = $context->builder->siToFp($n, $f64);
            $sixtyseventhF24 = $context->builder->fmul($sixtythirdF24, $nF24);
            $sixtyseventhF24 = $context->builder->fmul($sixtyseventhF24, $nF24);
            $sixtyseventhF24 = $context->builder->fmul($sixtyseventhF24, $nF24);
            $sixtyseventhF24 = $context->builder->fmul($sixtyseventhF24, $nF24);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyseventhF24
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok24Block);
            $sixtyfourthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixtythirdLong,
                $n
            );
            $ov25 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixtyfourthVar->longArithOverflowFlag);
            if (null === $ov25 || null === $sixtyfourthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **67 expected smul overflow metadata (sixtyfourth)');
            }
            $sixtyfourthLong = JITVariable::KIND_VARIABLE === $sixtyfourthVar->kind
                ? $context->builder->load($sixtyfourthVar->value)
                : $sixtyfourthVar->value;
            $ov25Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_sixtyfourth_ov');
            $ok25Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_sixtyfourth_ok');
            $context->builder->branchIf($ov25, $ov25Block, $ok25Block);

            $context->builder->positionAtEnd($ov25Block);
            $sixtyfourthF25 = $context->builder->load($sixtyfourthVar->longArithOverflowDoubleSlot);
            $nF25 = $context->builder->siToFp($n, $f64);
            $sixtyseventhF25 = $context->builder->fmul($sixtyfourthF25, $nF25);
            $sixtyseventhF25 = $context->builder->fmul($sixtyseventhF25, $nF25);
            $sixtyseventhF25 = $context->builder->fmul($sixtyseventhF25, $nF25);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyseventhF25
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok25Block);
            $sixtyfifthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixtyfourthLong,
                $n
            );
            $ov26 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixtyfifthVar->longArithOverflowFlag);
            if (null === $ov26 || null === $sixtyfifthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **67 expected smul overflow metadata (sixtyfifth)');
            }
            $sixtyfifthLong = JITVariable::KIND_VARIABLE === $sixtyfifthVar->kind
                ? $context->builder->load($sixtyfifthVar->value)
                : $sixtyfifthVar->value;
            $ov26Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_sixtyfifth_ov');
            $ok26Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_sixtyfifth_ok');
            $context->builder->branchIf($ov26, $ov26Block, $ok26Block);

            $context->builder->positionAtEnd($ov26Block);
            $sixtyfifthF26 = $context->builder->load($sixtyfifthVar->longArithOverflowDoubleSlot);
            $nF26 = $context->builder->siToFp($n, $f64);
            $sixtyseventhF26 = $context->builder->fmul($sixtyfifthF26, $nF26);
            $sixtyseventhF26 = $context->builder->fmul($sixtyseventhF26, $nF26);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyseventhF26
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok26Block);
            $sixtysixthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixtyfifthLong,
                $n
            );
            $ov27 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixtysixthVar->longArithOverflowFlag);
            if (null === $ov27 || null === $sixtysixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **67 expected smul overflow metadata (sixtysixth)');
            }
            $sixtysixthLong = JITVariable::KIND_VARIABLE === $sixtysixthVar->kind
                ? $context->builder->load($sixtysixthVar->value)
                : $sixtysixthVar->value;
            $ov27Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_sixtysixth_ov');
            $ok27Block = BasicBlockHelper::append($context, 'pow_sixtyseventh_sixtysixth_ok');
            $context->builder->branchIf($ov27, $ov27Block, $ok27Block);

            $context->builder->positionAtEnd($ov27Block);
            $sixtysixthF27 = $context->builder->load($sixtysixthVar->longArithOverflowDoubleSlot);
            $nF27 = $context->builder->siToFp($n, $f64);
            $sixtyseventhF27 = $context->builder->fmul($sixtysixthF27, $nF27);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyseventhF27
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok27Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $sixtysixthLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);


            $context->builder->positionAtEnd($doneBlock);

            return true;
        }
if ('sixtyeighth' === $expFold) {
            // n^68 = sixtyseventh*n = fortysixth*sq*sq*n*n*n*n*n*n*n*n*n*n*n*n*n*n*n*n*n: sq=n*n, cu=sq*n, fifth=cu*sq,
            // tenth=fifth*fifth, twentieth=tenth*tenth,
            // fortieth=twentieth*twentieth, fortysecond=fortieth*sq, fortyfourth=fortysecond*sq,
            // fortysixth=fortyfourth*sq, then fortysixth*sq*sq*n… (sixtyseventh×n).
            // Overflow arms finish in float with one extra ×nF vs **67.
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
                throw new \LogicException('pow() **68 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_sixtyeighth_done');
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
            $sixtyeighthFSq = $context->builder->fmul($fortyfourthF, $sqF);
            $sixtyeighthFEighth = $context->builder->fmul($sixtyeighthFSq, $sqF);
            $sixtyeighthF = $context->builder->fmul($sixtyeighthFEighth, $sqF);
            $nF = $context->builder->siToFp($n, $f64);
            $sixtyeighthF = $context->builder->fmul($sixtyeighthF, $nF);
            $sixtyeighthF = $context->builder->fmul($sixtyeighthF, $nF);
            $sixtyeighthF = $context->builder->fmul($sixtyeighthF, $nF);
            $sixtyeighthF = $context->builder->fmul($sixtyeighthF, $nF);
            $sixtyeighthF = $context->builder->fmul($sixtyeighthF, $nF);
            $sixtyeighthF = $context->builder->fmul($sixtyeighthF, $nF);
            $sixtyeighthF = $context->builder->fmul($sixtyeighthF, $nF);
            $sixtyeighthF = $context->builder->fmul($sixtyeighthF, $nF);
            $sixtyeighthF = $context->builder->fmul($sixtyeighthF, $nF);
            $sixtyeighthF = $context->builder->fmul($sixtyeighthF, $nF);
                $sixtyeighthF = $context->builder->fmul($sixtyeighthF, $nF);
                    $sixtyeighthF = $context->builder->fmul($sixtyeighthF, $nF);
                        $sixtyeighthF = $context->builder->fmul($sixtyeighthF, $nF);
                        $sixtyeighthF = $context->builder->fmul($sixtyeighthF, $nF);
                        $sixtyeighthF = $context->builder->fmul($sixtyeighthF, $nF);
                        $sixtyeighthF = $context->builder->fmul($sixtyeighthF, $nF);
                        $sixtyeighthF = $context->builder->fmul($sixtyeighthF, $nF);
                        $sixtyeighthF = $context->builder->fmul($sixtyeighthF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyeighthF
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
                throw new \LogicException('pow() **68 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_cu_ok');
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
            $sixtyeighthF2Sq = $context->builder->fmul($fortyfourthF2, $sqF2);
            $sixtyeighthF2Eighth = $context->builder->fmul($sixtyeighthF2Sq, $sqF2);
            $sixtyeighthF2 = $context->builder->fmul($sixtyeighthF2Eighth, $sqF2);
            $nF2 = $context->builder->siToFp($n, $f64);
            $sixtyeighthF2 = $context->builder->fmul($sixtyeighthF2, $nF2);
            $sixtyeighthF2 = $context->builder->fmul($sixtyeighthF2, $nF2);
            $sixtyeighthF2 = $context->builder->fmul($sixtyeighthF2, $nF2);
            $sixtyeighthF2 = $context->builder->fmul($sixtyeighthF2, $nF2);
            $sixtyeighthF2 = $context->builder->fmul($sixtyeighthF2, $nF2);
            $sixtyeighthF2 = $context->builder->fmul($sixtyeighthF2, $nF2);
            $sixtyeighthF2 = $context->builder->fmul($sixtyeighthF2, $nF2);
            $sixtyeighthF2 = $context->builder->fmul($sixtyeighthF2, $nF2);
            $sixtyeighthF2 = $context->builder->fmul($sixtyeighthF2, $nF2);
            $sixtyeighthF2 = $context->builder->fmul($sixtyeighthF2, $nF2);
                $sixtyeighthF2 = $context->builder->fmul($sixtyeighthF2, $nF2);
                    $sixtyeighthF2 = $context->builder->fmul($sixtyeighthF2, $nF2);
                        $sixtyeighthF2 = $context->builder->fmul($sixtyeighthF2, $nF2);
                        $sixtyeighthF2 = $context->builder->fmul($sixtyeighthF2, $nF2);
                        $sixtyeighthF2 = $context->builder->fmul($sixtyeighthF2, $nF2);
                        $sixtyeighthF2 = $context->builder->fmul($sixtyeighthF2, $nF2);
                        $sixtyeighthF2 = $context->builder->fmul($sixtyeighthF2, $nF2);
                        $sixtyeighthF2 = $context->builder->fmul($sixtyeighthF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyeighthF2
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
                throw new \LogicException('pow() **68 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $twentiethF3 = $context->builder->fmul($tenthF3, $tenthF3);
            $fortiethF3 = $context->builder->fmul($twentiethF3, $twentiethF3);
            $sqF3 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF3 = $context->builder->fmul($fortiethF3, $sqF3);
            $fortyfourthF3 = $context->builder->fmul($fortysecondF3, $sqF3);
            $sixtyeighthF3Sq = $context->builder->fmul($fortyfourthF3, $sqF3);
            $sixtyeighthF3Eighth = $context->builder->fmul($sixtyeighthF3Sq, $sqF3);
            $sixtyeighthF3 = $context->builder->fmul($sixtyeighthF3Eighth, $sqF3);
            $nF3 = $context->builder->siToFp($n, $f64);
            $sixtyeighthF3 = $context->builder->fmul($sixtyeighthF3, $nF3);
            $sixtyeighthF3 = $context->builder->fmul($sixtyeighthF3, $nF3);
            $sixtyeighthF3 = $context->builder->fmul($sixtyeighthF3, $nF3);
            $sixtyeighthF3 = $context->builder->fmul($sixtyeighthF3, $nF3);
            $sixtyeighthF3 = $context->builder->fmul($sixtyeighthF3, $nF3);
            $sixtyeighthF3 = $context->builder->fmul($sixtyeighthF3, $nF3);
            $sixtyeighthF3 = $context->builder->fmul($sixtyeighthF3, $nF3);
            $sixtyeighthF3 = $context->builder->fmul($sixtyeighthF3, $nF3);
            $sixtyeighthF3 = $context->builder->fmul($sixtyeighthF3, $nF3);
            $sixtyeighthF3 = $context->builder->fmul($sixtyeighthF3, $nF3);
                $sixtyeighthF3 = $context->builder->fmul($sixtyeighthF3, $nF3);
                    $sixtyeighthF3 = $context->builder->fmul($sixtyeighthF3, $nF3);
                        $sixtyeighthF3 = $context->builder->fmul($sixtyeighthF3, $nF3);
                        $sixtyeighthF3 = $context->builder->fmul($sixtyeighthF3, $nF3);
                        $sixtyeighthF3 = $context->builder->fmul($sixtyeighthF3, $nF3);
                        $sixtyeighthF3 = $context->builder->fmul($sixtyeighthF3, $nF3);
                        $sixtyeighthF3 = $context->builder->fmul($sixtyeighthF3, $nF3);
                        $sixtyeighthF3 = $context->builder->fmul($sixtyeighthF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyeighthF3
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
                throw new \LogicException('pow() **68 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF4 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $twentiethF4 = $context->builder->fmul($tenthF4, $tenthF4);
            $fortiethF4 = $context->builder->fmul($twentiethF4, $twentiethF4);
            $sqF4 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF4 = $context->builder->fmul($fortiethF4, $sqF4);
            $fortyfourthF4 = $context->builder->fmul($fortysecondF4, $sqF4);
            $sixtyeighthF4Sq = $context->builder->fmul($fortyfourthF4, $sqF4);
            $sixtyeighthF4Eighth = $context->builder->fmul($sixtyeighthF4Sq, $sqF4);
            $sixtyeighthF4 = $context->builder->fmul($sixtyeighthF4Eighth, $sqF4);
            $nF4 = $context->builder->siToFp($n, $f64);
            $sixtyeighthF4 = $context->builder->fmul($sixtyeighthF4, $nF4);
            $sixtyeighthF4 = $context->builder->fmul($sixtyeighthF4, $nF4);
            $sixtyeighthF4 = $context->builder->fmul($sixtyeighthF4, $nF4);
            $sixtyeighthF4 = $context->builder->fmul($sixtyeighthF4, $nF4);
            $sixtyeighthF4 = $context->builder->fmul($sixtyeighthF4, $nF4);
            $sixtyeighthF4 = $context->builder->fmul($sixtyeighthF4, $nF4);
            $sixtyeighthF4 = $context->builder->fmul($sixtyeighthF4, $nF4);
            $sixtyeighthF4 = $context->builder->fmul($sixtyeighthF4, $nF4);
            $sixtyeighthF4 = $context->builder->fmul($sixtyeighthF4, $nF4);
            $sixtyeighthF4 = $context->builder->fmul($sixtyeighthF4, $nF4);
                $sixtyeighthF4 = $context->builder->fmul($sixtyeighthF4, $nF4);
                    $sixtyeighthF4 = $context->builder->fmul($sixtyeighthF4, $nF4);
                        $sixtyeighthF4 = $context->builder->fmul($sixtyeighthF4, $nF4);
                        $sixtyeighthF4 = $context->builder->fmul($sixtyeighthF4, $nF4);
                        $sixtyeighthF4 = $context->builder->fmul($sixtyeighthF4, $nF4);
                        $sixtyeighthF4 = $context->builder->fmul($sixtyeighthF4, $nF4);
                        $sixtyeighthF4 = $context->builder->fmul($sixtyeighthF4, $nF4);
                        $sixtyeighthF4 = $context->builder->fmul($sixtyeighthF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyeighthF4
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
                throw new \LogicException('pow() **68 expected smul overflow metadata (twentieth)');
            }
            $twentiethLong = JITVariable::KIND_VARIABLE === $twentiethVar->kind
                ? $context->builder->load($twentiethVar->value)
                : $twentiethVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_twentieth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_twentieth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $twentiethF5 = $context->builder->load($twentiethVar->longArithOverflowDoubleSlot);
            $fortiethF5 = $context->builder->fmul($twentiethF5, $twentiethF5);
            $sqF5 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF5 = $context->builder->fmul($fortiethF5, $sqF5);
            $fortyfourthF5 = $context->builder->fmul($fortysecondF5, $sqF5);
            $sixtyeighthF5Sq = $context->builder->fmul($fortyfourthF5, $sqF5);
            $sixtyeighthF5Eighth = $context->builder->fmul($sixtyeighthF5Sq, $sqF5);
            $sixtyeighthF5 = $context->builder->fmul($sixtyeighthF5Eighth, $sqF5);
            $nF5 = $context->builder->siToFp($n, $f64);
            $sixtyeighthF5 = $context->builder->fmul($sixtyeighthF5, $nF5);
            $sixtyeighthF5 = $context->builder->fmul($sixtyeighthF5, $nF5);
            $sixtyeighthF5 = $context->builder->fmul($sixtyeighthF5, $nF5);
            $sixtyeighthF5 = $context->builder->fmul($sixtyeighthF5, $nF5);
            $sixtyeighthF5 = $context->builder->fmul($sixtyeighthF5, $nF5);
            $sixtyeighthF5 = $context->builder->fmul($sixtyeighthF5, $nF5);
            $sixtyeighthF5 = $context->builder->fmul($sixtyeighthF5, $nF5);
            $sixtyeighthF5 = $context->builder->fmul($sixtyeighthF5, $nF5);
            $sixtyeighthF5 = $context->builder->fmul($sixtyeighthF5, $nF5);
            $sixtyeighthF5 = $context->builder->fmul($sixtyeighthF5, $nF5);
                $sixtyeighthF5 = $context->builder->fmul($sixtyeighthF5, $nF5);
                    $sixtyeighthF5 = $context->builder->fmul($sixtyeighthF5, $nF5);
                        $sixtyeighthF5 = $context->builder->fmul($sixtyeighthF5, $nF5);
                        $sixtyeighthF5 = $context->builder->fmul($sixtyeighthF5, $nF5);
                        $sixtyeighthF5 = $context->builder->fmul($sixtyeighthF5, $nF5);
                        $sixtyeighthF5 = $context->builder->fmul($sixtyeighthF5, $nF5);
                        $sixtyeighthF5 = $context->builder->fmul($sixtyeighthF5, $nF5);
                        $sixtyeighthF5 = $context->builder->fmul($sixtyeighthF5, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyeighthF5
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
                throw new \LogicException('pow() **68 expected smul overflow metadata (fortieth)');
            }
            $fortiethLong = JITVariable::KIND_VARIABLE === $fortiethVar->kind
                ? $context->builder->load($fortiethVar->value)
                : $fortiethVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_fortieth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_fortieth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $fortiethF6 = $context->builder->load($fortiethVar->longArithOverflowDoubleSlot);
            $sqF6 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF6 = $context->builder->fmul($fortiethF6, $sqF6);
            $fortyfourthF6 = $context->builder->fmul($fortysecondF6, $sqF6);
            $sixtyeighthF6Sq = $context->builder->fmul($fortyfourthF6, $sqF6);
            $sixtyeighthF6Eighth = $context->builder->fmul($sixtyeighthF6Sq, $sqF6);
            $sixtyeighthF6 = $context->builder->fmul($sixtyeighthF6Eighth, $sqF6);
            $nF6 = $context->builder->siToFp($n, $f64);
            $sixtyeighthF6 = $context->builder->fmul($sixtyeighthF6, $nF6);
            $sixtyeighthF6 = $context->builder->fmul($sixtyeighthF6, $nF6);
            $sixtyeighthF6 = $context->builder->fmul($sixtyeighthF6, $nF6);
            $sixtyeighthF6 = $context->builder->fmul($sixtyeighthF6, $nF6);
            $sixtyeighthF6 = $context->builder->fmul($sixtyeighthF6, $nF6);
            $sixtyeighthF6 = $context->builder->fmul($sixtyeighthF6, $nF6);
            $sixtyeighthF6 = $context->builder->fmul($sixtyeighthF6, $nF6);
            $sixtyeighthF6 = $context->builder->fmul($sixtyeighthF6, $nF6);
            $sixtyeighthF6 = $context->builder->fmul($sixtyeighthF6, $nF6);
            $sixtyeighthF6 = $context->builder->fmul($sixtyeighthF6, $nF6);
                $sixtyeighthF6 = $context->builder->fmul($sixtyeighthF6, $nF6);
                    $sixtyeighthF6 = $context->builder->fmul($sixtyeighthF6, $nF6);
                        $sixtyeighthF6 = $context->builder->fmul($sixtyeighthF6, $nF6);
                        $sixtyeighthF6 = $context->builder->fmul($sixtyeighthF6, $nF6);
                        $sixtyeighthF6 = $context->builder->fmul($sixtyeighthF6, $nF6);
                        $sixtyeighthF6 = $context->builder->fmul($sixtyeighthF6, $nF6);
                        $sixtyeighthF6 = $context->builder->fmul($sixtyeighthF6, $nF6);
                        $sixtyeighthF6 = $context->builder->fmul($sixtyeighthF6, $nF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyeighthF6
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
                throw new \LogicException('pow() **68 expected smul overflow metadata (fortysecond)');
            }
            $fortysecondLong = JITVariable::KIND_VARIABLE === $fortysecondVar->kind
                ? $context->builder->load($fortysecondVar->value)
                : $fortysecondVar->value;
            $ov7Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_fortysecond_ov');
            $ok7Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_fortysecond_ok');
            $context->builder->branchIf($ov7, $ov7Block, $ok7Block);

            $context->builder->positionAtEnd($ov7Block);
            $fortysecondF7 = $context->builder->load($fortysecondVar->longArithOverflowDoubleSlot);
            $sqF7 = $context->builder->siToFp($sqLong, $f64);
            $fortyfourthF7 = $context->builder->fmul($fortysecondF7, $sqF7);
            $sixtyeighthF7Sq = $context->builder->fmul($fortyfourthF7, $sqF7);
            $sixtyeighthF7Eighth = $context->builder->fmul($sixtyeighthF7Sq, $sqF7);
            $sixtyeighthF7 = $context->builder->fmul($sixtyeighthF7Eighth, $sqF7);
            $nF7 = $context->builder->siToFp($n, $f64);
            $sixtyeighthF7 = $context->builder->fmul($sixtyeighthF7, $nF7);
            $sixtyeighthF7 = $context->builder->fmul($sixtyeighthF7, $nF7);
            $sixtyeighthF7 = $context->builder->fmul($sixtyeighthF7, $nF7);
            $sixtyeighthF7 = $context->builder->fmul($sixtyeighthF7, $nF7);
            $sixtyeighthF7 = $context->builder->fmul($sixtyeighthF7, $nF7);
            $sixtyeighthF7 = $context->builder->fmul($sixtyeighthF7, $nF7);
            $sixtyeighthF7 = $context->builder->fmul($sixtyeighthF7, $nF7);
            $sixtyeighthF7 = $context->builder->fmul($sixtyeighthF7, $nF7);
            $sixtyeighthF7 = $context->builder->fmul($sixtyeighthF7, $nF7);
            $sixtyeighthF7 = $context->builder->fmul($sixtyeighthF7, $nF7);
                $sixtyeighthF7 = $context->builder->fmul($sixtyeighthF7, $nF7);
                    $sixtyeighthF7 = $context->builder->fmul($sixtyeighthF7, $nF7);
                        $sixtyeighthF7 = $context->builder->fmul($sixtyeighthF7, $nF7);
                        $sixtyeighthF7 = $context->builder->fmul($sixtyeighthF7, $nF7);
                        $sixtyeighthF7 = $context->builder->fmul($sixtyeighthF7, $nF7);
                        $sixtyeighthF7 = $context->builder->fmul($sixtyeighthF7, $nF7);
                        $sixtyeighthF7 = $context->builder->fmul($sixtyeighthF7, $nF7);
                        $sixtyeighthF7 = $context->builder->fmul($sixtyeighthF7, $nF7);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyeighthF7
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
                throw new \LogicException('pow() **68 expected smul overflow metadata (fortyfourth)');
            }
            $fortyfourthLong = JITVariable::KIND_VARIABLE === $fortyfourthVar->kind
                ? $context->builder->load($fortyfourthVar->value)
                : $fortyfourthVar->value;
            $ov8Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_fortyfourth_ov');
            $ok8Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_fortyfourth_ok');
            $context->builder->branchIf($ov8, $ov8Block, $ok8Block);

            $context->builder->positionAtEnd($ov8Block);
            $fortyfourthF8 = $context->builder->load($fortyfourthVar->longArithOverflowDoubleSlot);
            $sqF8 = $context->builder->siToFp($sqLong, $f64);
            $sixtyeighthF8Sq = $context->builder->fmul($fortyfourthF8, $sqF8);
            $sixtyeighthF8Eighth = $context->builder->fmul($sixtyeighthF8Sq, $sqF8);
            $sixtyeighthF8 = $context->builder->fmul($sixtyeighthF8Eighth, $sqF8);
            $nF8 = $context->builder->siToFp($n, $f64);
            $sixtyeighthF8 = $context->builder->fmul($sixtyeighthF8, $nF8);
            $sixtyeighthF8 = $context->builder->fmul($sixtyeighthF8, $nF8);
            $sixtyeighthF8 = $context->builder->fmul($sixtyeighthF8, $nF8);
            $sixtyeighthF8 = $context->builder->fmul($sixtyeighthF8, $nF8);
            $sixtyeighthF8 = $context->builder->fmul($sixtyeighthF8, $nF8);
            $sixtyeighthF8 = $context->builder->fmul($sixtyeighthF8, $nF8);
            $sixtyeighthF8 = $context->builder->fmul($sixtyeighthF8, $nF8);
            $sixtyeighthF8 = $context->builder->fmul($sixtyeighthF8, $nF8);
            $sixtyeighthF8 = $context->builder->fmul($sixtyeighthF8, $nF8);
            $sixtyeighthF8 = $context->builder->fmul($sixtyeighthF8, $nF8);
                $sixtyeighthF8 = $context->builder->fmul($sixtyeighthF8, $nF8);
                    $sixtyeighthF8 = $context->builder->fmul($sixtyeighthF8, $nF8);
                        $sixtyeighthF8 = $context->builder->fmul($sixtyeighthF8, $nF8);
                        $sixtyeighthF8 = $context->builder->fmul($sixtyeighthF8, $nF8);
                        $sixtyeighthF8 = $context->builder->fmul($sixtyeighthF8, $nF8);
                        $sixtyeighthF8 = $context->builder->fmul($sixtyeighthF8, $nF8);
                        $sixtyeighthF8 = $context->builder->fmul($sixtyeighthF8, $nF8);
                        $sixtyeighthF8 = $context->builder->fmul($sixtyeighthF8, $nF8);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyeighthF8
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
                throw new \LogicException('pow() **68 expected smul overflow metadata (fortysixth)');
            }
            $fortysixthLong = JITVariable::KIND_VARIABLE === $fortysixthVar->kind
                ? $context->builder->load($fortysixthVar->value)
                : $fortysixthVar->value;
            $ov9Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_fortysixth_ov');
            $ok9Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_fortysixth_ok');
            $context->builder->branchIf($ov9, $ov9Block, $ok9Block);

            $context->builder->positionAtEnd($ov9Block);
            $fortysixthF9 = $context->builder->load($fortysixthVar->longArithOverflowDoubleSlot);
            $sqF9 = $context->builder->siToFp($sqLong, $f64);
            $fortyeighthF9 = $context->builder->fmul($fortysixthF9, $sqF9);
            $sixtyeighthF9 = $context->builder->fmul($fortyeighthF9, $sqF9);
            $nF9 = $context->builder->siToFp($n, $f64);
            $sixtyeighthF9 = $context->builder->fmul($sixtyeighthF9, $nF9);
            $sixtyeighthF9 = $context->builder->fmul($sixtyeighthF9, $nF9);
            $sixtyeighthF9 = $context->builder->fmul($sixtyeighthF9, $nF9);
            $sixtyeighthF9 = $context->builder->fmul($sixtyeighthF9, $nF9);
            $sixtyeighthF9 = $context->builder->fmul($sixtyeighthF9, $nF9);
            $sixtyeighthF9 = $context->builder->fmul($sixtyeighthF9, $nF9);
            $sixtyeighthF9 = $context->builder->fmul($sixtyeighthF9, $nF9);
            $sixtyeighthF9 = $context->builder->fmul($sixtyeighthF9, $nF9);
            $sixtyeighthF9 = $context->builder->fmul($sixtyeighthF9, $nF9);
            $sixtyeighthF9 = $context->builder->fmul($sixtyeighthF9, $nF9);
                $sixtyeighthF9 = $context->builder->fmul($sixtyeighthF9, $nF9);
                    $sixtyeighthF9 = $context->builder->fmul($sixtyeighthF9, $nF9);
                        $sixtyeighthF9 = $context->builder->fmul($sixtyeighthF9, $nF9);
                        $sixtyeighthF9 = $context->builder->fmul($sixtyeighthF9, $nF9);
                        $sixtyeighthF9 = $context->builder->fmul($sixtyeighthF9, $nF9);
                        $sixtyeighthF9 = $context->builder->fmul($sixtyeighthF9, $nF9);
                        $sixtyeighthF9 = $context->builder->fmul($sixtyeighthF9, $nF9);
                        $sixtyeighthF9 = $context->builder->fmul($sixtyeighthF9, $nF9);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyeighthF9
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
                throw new \LogicException('pow() **68 expected smul overflow metadata (fortyeighth)');
            }
            $fortyeighthLong = JITVariable::KIND_VARIABLE === $fortyeighthVar->kind
                ? $context->builder->load($fortyeighthVar->value)
                : $fortyeighthVar->value;
            $ov10Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_fortyeighth_ov');
            $ok10Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_fortyeighth_ok');
            $context->builder->branchIf($ov10, $ov10Block, $ok10Block);

            $context->builder->positionAtEnd($ov10Block);
            $fortyeighthF10 = $context->builder->load($fortyeighthVar->longArithOverflowDoubleSlot);
            $sqF10 = $context->builder->siToFp($sqLong, $f64);
            $sixtyeighthF10 = $context->builder->fmul($fortyeighthF10, $sqF10);
            $nF10 = $context->builder->siToFp($n, $f64);
            $sixtyeighthF10 = $context->builder->fmul($sixtyeighthF10, $nF10);
            $sixtyeighthF10 = $context->builder->fmul($sixtyeighthF10, $nF10);
            $sixtyeighthF10 = $context->builder->fmul($sixtyeighthF10, $nF10);
            $sixtyeighthF10 = $context->builder->fmul($sixtyeighthF10, $nF10);
            $sixtyeighthF10 = $context->builder->fmul($sixtyeighthF10, $nF10);
            $sixtyeighthF10 = $context->builder->fmul($sixtyeighthF10, $nF10);
            $sixtyeighthF10 = $context->builder->fmul($sixtyeighthF10, $nF10);
            $sixtyeighthF10 = $context->builder->fmul($sixtyeighthF10, $nF10);
            $sixtyeighthF10 = $context->builder->fmul($sixtyeighthF10, $nF10);
            $sixtyeighthF10 = $context->builder->fmul($sixtyeighthF10, $nF10);
                $sixtyeighthF10 = $context->builder->fmul($sixtyeighthF10, $nF10);
                    $sixtyeighthF10 = $context->builder->fmul($sixtyeighthF10, $nF10);
                        $sixtyeighthF10 = $context->builder->fmul($sixtyeighthF10, $nF10);
                        $sixtyeighthF10 = $context->builder->fmul($sixtyeighthF10, $nF10);
                        $sixtyeighthF10 = $context->builder->fmul($sixtyeighthF10, $nF10);
                        $sixtyeighthF10 = $context->builder->fmul($sixtyeighthF10, $nF10);
                        $sixtyeighthF10 = $context->builder->fmul($sixtyeighthF10, $nF10);
                        $sixtyeighthF10 = $context->builder->fmul($sixtyeighthF10, $nF10);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyeighthF10
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok10Block);
            $fiftiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fortyeighthLong,
                $sqLong
            );
            $ov11 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftiethVar->longArithOverflowFlag);
            if (null === $ov11 || null === $fiftiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **68 expected smul overflow metadata (fiftieth)');
            }
            $fiftiethLong = JITVariable::KIND_VARIABLE === $fiftiethVar->kind
                ? $context->builder->load($fiftiethVar->value)
                : $fiftiethVar->value;
            $ov11Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_fiftieth_ov');
            $ok11Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_fiftieth_ok');
            $context->builder->branchIf($ov11, $ov11Block, $ok11Block);

            $context->builder->positionAtEnd($ov11Block);
            $fiftiethF11 = $context->builder->load($fiftiethVar->longArithOverflowDoubleSlot);
            $nF11 = $context->builder->siToFp($n, $f64);
            $sixtyeighthF11 = $context->builder->fmul($fiftiethF11, $nF11);
            $sixtyeighthF11 = $context->builder->fmul($sixtyeighthF11, $nF11);
            $sixtyeighthF11 = $context->builder->fmul($sixtyeighthF11, $nF11);
            $sixtyeighthF11 = $context->builder->fmul($sixtyeighthF11, $nF11);
            $sixtyeighthF11 = $context->builder->fmul($sixtyeighthF11, $nF11);
            $sixtyeighthF11 = $context->builder->fmul($sixtyeighthF11, $nF11);
            $sixtyeighthF11 = $context->builder->fmul($sixtyeighthF11, $nF11);
            $sixtyeighthF11 = $context->builder->fmul($sixtyeighthF11, $nF11);
            $sixtyeighthF11 = $context->builder->fmul($sixtyeighthF11, $nF11);
            $sixtyeighthF11 = $context->builder->fmul($sixtyeighthF11, $nF11);
                $sixtyeighthF11 = $context->builder->fmul($sixtyeighthF11, $nF11);
                    $sixtyeighthF11 = $context->builder->fmul($sixtyeighthF11, $nF11);
                        $sixtyeighthF11 = $context->builder->fmul($sixtyeighthF11, $nF11);
                        $sixtyeighthF11 = $context->builder->fmul($sixtyeighthF11, $nF11);
                        $sixtyeighthF11 = $context->builder->fmul($sixtyeighthF11, $nF11);
                        $sixtyeighthF11 = $context->builder->fmul($sixtyeighthF11, $nF11);
                        $sixtyeighthF11 = $context->builder->fmul($sixtyeighthF11, $nF11);
                        $sixtyeighthF11 = $context->builder->fmul($sixtyeighthF11, $nF11);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyeighthF11
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok11Block);
            $fiftyfirstVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftiethLong,
                $n
            );
            $ov12 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyfirstVar->longArithOverflowFlag);
            if (null === $ov12 || null === $fiftyfirstVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **68 expected smul overflow metadata (fiftyfirst)');
            }
            $fiftyfirstLong = JITVariable::KIND_VARIABLE === $fiftyfirstVar->kind
                ? $context->builder->load($fiftyfirstVar->value)
                : $fiftyfirstVar->value;
            $ov12Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_fiftyfirst_ov');
            $ok12Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_fiftyfirst_ok');
            $context->builder->branchIf($ov12, $ov12Block, $ok12Block);

            $context->builder->positionAtEnd($ov12Block);
            $fiftyfirstF12 = $context->builder->load($fiftyfirstVar->longArithOverflowDoubleSlot);
            $nF12 = $context->builder->siToFp($n, $f64);
            $sixtyeighthF12 = $context->builder->fmul($fiftyfirstF12, $nF12);
            $sixtyeighthF12 = $context->builder->fmul($sixtyeighthF12, $nF12);
            $sixtyeighthF12 = $context->builder->fmul($sixtyeighthF12, $nF12);
            $sixtyeighthF12 = $context->builder->fmul($sixtyeighthF12, $nF12);
            $sixtyeighthF12 = $context->builder->fmul($sixtyeighthF12, $nF12);
            $sixtyeighthF12 = $context->builder->fmul($sixtyeighthF12, $nF12);
            $sixtyeighthF12 = $context->builder->fmul($sixtyeighthF12, $nF12);
            $sixtyeighthF12 = $context->builder->fmul($sixtyeighthF12, $nF12);
            $sixtyeighthF12 = $context->builder->fmul($sixtyeighthF12, $nF12);
                $sixtyeighthF12 = $context->builder->fmul($sixtyeighthF12, $nF12);
                    $sixtyeighthF12 = $context->builder->fmul($sixtyeighthF12, $nF12);
                        $sixtyeighthF12 = $context->builder->fmul($sixtyeighthF12, $nF12);
                        $sixtyeighthF12 = $context->builder->fmul($sixtyeighthF12, $nF12);
                        $sixtyeighthF12 = $context->builder->fmul($sixtyeighthF12, $nF12);
                        $sixtyeighthF12 = $context->builder->fmul($sixtyeighthF12, $nF12);
                        $sixtyeighthF12 = $context->builder->fmul($sixtyeighthF12, $nF12);
                        $sixtyeighthF12 = $context->builder->fmul($sixtyeighthF12, $nF12);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyeighthF12
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok12Block);
            $fiftysecondVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyfirstLong,
                $n
            );
            $ov13 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftysecondVar->longArithOverflowFlag);
            if (null === $ov13 || null === $fiftysecondVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **68 expected smul overflow metadata (fiftysecond)');
            }
            $fiftysecondLong = JITVariable::KIND_VARIABLE === $fiftysecondVar->kind
                ? $context->builder->load($fiftysecondVar->value)
                : $fiftysecondVar->value;
            $ov13Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_fiftysecond_ov');
            $ok13Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_fiftysecond_ok');
            $context->builder->branchIf($ov13, $ov13Block, $ok13Block);

            $context->builder->positionAtEnd($ov13Block);
            $fiftysecondF13 = $context->builder->load($fiftysecondVar->longArithOverflowDoubleSlot);
            $nF13 = $context->builder->siToFp($n, $f64);
            $sixtyeighthF13 = $context->builder->fmul($fiftysecondF13, $nF13);
            $sixtyeighthF13 = $context->builder->fmul($sixtyeighthF13, $nF13);
            $sixtyeighthF13 = $context->builder->fmul($sixtyeighthF13, $nF13);
            $sixtyeighthF13 = $context->builder->fmul($sixtyeighthF13, $nF13);
            $sixtyeighthF13 = $context->builder->fmul($sixtyeighthF13, $nF13);
            $sixtyeighthF13 = $context->builder->fmul($sixtyeighthF13, $nF13);
            $sixtyeighthF13 = $context->builder->fmul($sixtyeighthF13, $nF13);
            $sixtyeighthF13 = $context->builder->fmul($sixtyeighthF13, $nF13);
                $sixtyeighthF13 = $context->builder->fmul($sixtyeighthF13, $nF13);
                    $sixtyeighthF13 = $context->builder->fmul($sixtyeighthF13, $nF13);
                        $sixtyeighthF13 = $context->builder->fmul($sixtyeighthF13, $nF13);
                        $sixtyeighthF13 = $context->builder->fmul($sixtyeighthF13, $nF13);
                        $sixtyeighthF13 = $context->builder->fmul($sixtyeighthF13, $nF13);
                        $sixtyeighthF13 = $context->builder->fmul($sixtyeighthF13, $nF13);
                        $sixtyeighthF13 = $context->builder->fmul($sixtyeighthF13, $nF13);
                        $sixtyeighthF13 = $context->builder->fmul($sixtyeighthF13, $nF13);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyeighthF13
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok13Block);
            $fiftythirdVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftysecondLong,
                $n
            );
            $ov14 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftythirdVar->longArithOverflowFlag);
            if (null === $ov14 || null === $fiftythirdVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **68 expected smul overflow metadata (fiftythird)');
            }
            $fiftythirdLong = JITVariable::KIND_VARIABLE === $fiftythirdVar->kind
                ? $context->builder->load($fiftythirdVar->value)
                : $fiftythirdVar->value;
            $ov14Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_fiftythird_ov');
            $ok14Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_fiftythird_ok');
            $context->builder->branchIf($ov14, $ov14Block, $ok14Block);

            $context->builder->positionAtEnd($ov14Block);
            $fiftythirdF14 = $context->builder->load($fiftythirdVar->longArithOverflowDoubleSlot);
            $nF14 = $context->builder->siToFp($n, $f64);
            $sixtyeighthF14 = $context->builder->fmul($fiftythirdF14, $nF14);
            $sixtyeighthF14 = $context->builder->fmul($sixtyeighthF14, $nF14);
            $sixtyeighthF14 = $context->builder->fmul($sixtyeighthF14, $nF14);
            $sixtyeighthF14 = $context->builder->fmul($sixtyeighthF14, $nF14);
            $sixtyeighthF14 = $context->builder->fmul($sixtyeighthF14, $nF14);
            $sixtyeighthF14 = $context->builder->fmul($sixtyeighthF14, $nF14);
            $sixtyeighthF14 = $context->builder->fmul($sixtyeighthF14, $nF14);
                $sixtyeighthF14 = $context->builder->fmul($sixtyeighthF14, $nF14);
                    $sixtyeighthF14 = $context->builder->fmul($sixtyeighthF14, $nF14);
                        $sixtyeighthF14 = $context->builder->fmul($sixtyeighthF14, $nF14);
                        $sixtyeighthF14 = $context->builder->fmul($sixtyeighthF14, $nF14);
                        $sixtyeighthF14 = $context->builder->fmul($sixtyeighthF14, $nF14);
                        $sixtyeighthF14 = $context->builder->fmul($sixtyeighthF14, $nF14);
                        $sixtyeighthF14 = $context->builder->fmul($sixtyeighthF14, $nF14);
                        $sixtyeighthF14 = $context->builder->fmul($sixtyeighthF14, $nF14);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyeighthF14
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok14Block);
            $fiftyfourthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftythirdLong,
                $n
            );
            $ov15 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyfourthVar->longArithOverflowFlag);
            if (null === $ov15 || null === $fiftyfourthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **68 expected smul overflow metadata (fiftyfourth)');
            }
            $fiftyfourthLong = JITVariable::KIND_VARIABLE === $fiftyfourthVar->kind
                ? $context->builder->load($fiftyfourthVar->value)
                : $fiftyfourthVar->value;
            $ov15Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_fiftyfourth_ov');
            $ok15Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_fiftyfourth_ok');
            $context->builder->branchIf($ov15, $ov15Block, $ok15Block);

            $context->builder->positionAtEnd($ov15Block);
            $fiftyfourthF15 = $context->builder->load($fiftyfourthVar->longArithOverflowDoubleSlot);
            $nF15 = $context->builder->siToFp($n, $f64);
            $sixtyeighthF15 = $context->builder->fmul($fiftyfourthF15, $nF15);
            $sixtyeighthF15 = $context->builder->fmul($sixtyeighthF15, $nF15);
            $sixtyeighthF15 = $context->builder->fmul($sixtyeighthF15, $nF15);
            $sixtyeighthF15 = $context->builder->fmul($sixtyeighthF15, $nF15);
            $sixtyeighthF15 = $context->builder->fmul($sixtyeighthF15, $nF15);
            $sixtyeighthF15 = $context->builder->fmul($sixtyeighthF15, $nF15);
                $sixtyeighthF15 = $context->builder->fmul($sixtyeighthF15, $nF15);
                    $sixtyeighthF15 = $context->builder->fmul($sixtyeighthF15, $nF15);
                        $sixtyeighthF15 = $context->builder->fmul($sixtyeighthF15, $nF15);
                        $sixtyeighthF15 = $context->builder->fmul($sixtyeighthF15, $nF15);
                        $sixtyeighthF15 = $context->builder->fmul($sixtyeighthF15, $nF15);
                        $sixtyeighthF15 = $context->builder->fmul($sixtyeighthF15, $nF15);
                        $sixtyeighthF15 = $context->builder->fmul($sixtyeighthF15, $nF15);
                        $sixtyeighthF15 = $context->builder->fmul($sixtyeighthF15, $nF15);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyeighthF15
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok15Block);
            $fiftyfifthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyfourthLong,
                $n
            );
            $ov16 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyfifthVar->longArithOverflowFlag);
            if (null === $ov16 || null === $fiftyfifthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **68 expected smul overflow metadata (fiftyfifth)');
            }
            $fiftyfifthLong = JITVariable::KIND_VARIABLE === $fiftyfifthVar->kind
                ? $context->builder->load($fiftyfifthVar->value)
                : $fiftyfifthVar->value;
            $ov16Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_fiftyfifth_ov');
            $ok16Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_fiftyfifth_ok');
            $context->builder->branchIf($ov16, $ov16Block, $ok16Block);

            $context->builder->positionAtEnd($ov16Block);
            $fiftyfifthF16 = $context->builder->load($fiftyfifthVar->longArithOverflowDoubleSlot);
            $nF16 = $context->builder->siToFp($n, $f64);
            $sixtyeighthF16 = $context->builder->fmul($fiftyfifthF16, $nF16);
            $sixtyeighthF16 = $context->builder->fmul($sixtyeighthF16, $nF16);
            $sixtyeighthF16 = $context->builder->fmul($sixtyeighthF16, $nF16);
            $sixtyeighthF16 = $context->builder->fmul($sixtyeighthF16, $nF16);
            $sixtyeighthF16 = $context->builder->fmul($sixtyeighthF16, $nF16);
                $sixtyeighthF16 = $context->builder->fmul($sixtyeighthF16, $nF16);
                    $sixtyeighthF16 = $context->builder->fmul($sixtyeighthF16, $nF16);
                        $sixtyeighthF16 = $context->builder->fmul($sixtyeighthF16, $nF16);
                        $sixtyeighthF16 = $context->builder->fmul($sixtyeighthF16, $nF16);
                        $sixtyeighthF16 = $context->builder->fmul($sixtyeighthF16, $nF16);
                        $sixtyeighthF16 = $context->builder->fmul($sixtyeighthF16, $nF16);
                        $sixtyeighthF16 = $context->builder->fmul($sixtyeighthF16, $nF16);
                        $sixtyeighthF16 = $context->builder->fmul($sixtyeighthF16, $nF16);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyeighthF16
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok16Block);
            $fiftysixthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyfifthLong,
                $n
            );
            $ov17 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftysixthVar->longArithOverflowFlag);
            if (null === $ov17 || null === $fiftysixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **68 expected smul overflow metadata (fiftysixth)');
            }
            $fiftysixthLong = JITVariable::KIND_VARIABLE === $fiftysixthVar->kind
                ? $context->builder->load($fiftysixthVar->value)
                : $fiftysixthVar->value;
            $ov17Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_fiftysixth_ov');
            $ok17Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_fiftysixth_ok');
            $context->builder->branchIf($ov17, $ov17Block, $ok17Block);

            $context->builder->positionAtEnd($ov17Block);
            $fiftysixthF17 = $context->builder->load($fiftysixthVar->longArithOverflowDoubleSlot);
            $nF17 = $context->builder->siToFp($n, $f64);
            $sixtyeighthF17 = $context->builder->fmul($fiftysixthF17, $nF17);
            $sixtyeighthF17 = $context->builder->fmul($sixtyeighthF17, $nF17);
            $sixtyeighthF17 = $context->builder->fmul($sixtyeighthF17, $nF17);
            $sixtyeighthF17 = $context->builder->fmul($sixtyeighthF17, $nF17);
                $sixtyeighthF17 = $context->builder->fmul($sixtyeighthF17, $nF17);
                    $sixtyeighthF17 = $context->builder->fmul($sixtyeighthF17, $nF17);
                        $sixtyeighthF17 = $context->builder->fmul($sixtyeighthF17, $nF17);
                        $sixtyeighthF17 = $context->builder->fmul($sixtyeighthF17, $nF17);
                        $sixtyeighthF17 = $context->builder->fmul($sixtyeighthF17, $nF17);
                        $sixtyeighthF17 = $context->builder->fmul($sixtyeighthF17, $nF17);
                        $sixtyeighthF17 = $context->builder->fmul($sixtyeighthF17, $nF17);
                        $sixtyeighthF17 = $context->builder->fmul($sixtyeighthF17, $nF17);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyeighthF17
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok17Block);
            $fiftyseventhVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftysixthLong,
                $n
            );
            $ov18 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyseventhVar->longArithOverflowFlag);
            if (null === $ov18 || null === $fiftyseventhVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **68 expected smul overflow metadata (fiftyseventh)');
            }
            $fiftyseventhLong = JITVariable::KIND_VARIABLE === $fiftyseventhVar->kind
                ? $context->builder->load($fiftyseventhVar->value)
                : $fiftyseventhVar->value;
            $ov18Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_fiftyseventh_ov');
            $ok18Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_fiftyseventh_ok');
            $context->builder->branchIf($ov18, $ov18Block, $ok18Block);

            $context->builder->positionAtEnd($ov18Block);
            $fiftyseventhF18 = $context->builder->load($fiftyseventhVar->longArithOverflowDoubleSlot);
            $nF18 = $context->builder->siToFp($n, $f64);
            $sixtyeighthF18 = $context->builder->fmul($fiftyseventhF18, $nF18);
            $sixtyeighthF18 = $context->builder->fmul($sixtyeighthF18, $nF18);
            $sixtyeighthF18 = $context->builder->fmul($sixtyeighthF18, $nF18);
                $sixtyeighthF18 = $context->builder->fmul($sixtyeighthF18, $nF18);
                    $sixtyeighthF18 = $context->builder->fmul($sixtyeighthF18, $nF18);
                        $sixtyeighthF18 = $context->builder->fmul($sixtyeighthF18, $nF18);
                        $sixtyeighthF18 = $context->builder->fmul($sixtyeighthF18, $nF18);
                        $sixtyeighthF18 = $context->builder->fmul($sixtyeighthF18, $nF18);
                        $sixtyeighthF18 = $context->builder->fmul($sixtyeighthF18, $nF18);
                        $sixtyeighthF18 = $context->builder->fmul($sixtyeighthF18, $nF18);
                        $sixtyeighthF18 = $context->builder->fmul($sixtyeighthF18, $nF18);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyeighthF18
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok18Block);
            $fiftyeighthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyseventhLong,
                $n
            );
            $ov19 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyeighthVar->longArithOverflowFlag);
            if (null === $ov19 || null === $fiftyeighthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **68 expected smul overflow metadata (fiftyeighth)');
            }
            $fiftyeighthLong = JITVariable::KIND_VARIABLE === $fiftyeighthVar->kind
                ? $context->builder->load($fiftyeighthVar->value)
                : $fiftyeighthVar->value;
            $ov19Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_fiftyeighth_ov');
            $ok19Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_fiftyeighth_ok');
            $context->builder->branchIf($ov19, $ov19Block, $ok19Block);

            $context->builder->positionAtEnd($ov19Block);
            $fiftyeighthF19 = $context->builder->load($fiftyeighthVar->longArithOverflowDoubleSlot);
            $nF19 = $context->builder->siToFp($n, $f64);
            $sixtyeighthF19 = $context->builder->fmul($fiftyeighthF19, $nF19);
            $sixtyeighthF19 = $context->builder->fmul($sixtyeighthF19, $nF19);
                $sixtyeighthF19 = $context->builder->fmul($sixtyeighthF19, $nF19);
                    $sixtyeighthF19 = $context->builder->fmul($sixtyeighthF19, $nF19);
                        $sixtyeighthF19 = $context->builder->fmul($sixtyeighthF19, $nF19);
                        $sixtyeighthF19 = $context->builder->fmul($sixtyeighthF19, $nF19);
                        $sixtyeighthF19 = $context->builder->fmul($sixtyeighthF19, $nF19);
                        $sixtyeighthF19 = $context->builder->fmul($sixtyeighthF19, $nF19);
                        $sixtyeighthF19 = $context->builder->fmul($sixtyeighthF19, $nF19);
                        $sixtyeighthF19 = $context->builder->fmul($sixtyeighthF19, $nF19);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyeighthF19
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok19Block);
            $fiftyninthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyeighthLong,
                $n
            );
            $ov20 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyninthVar->longArithOverflowFlag);
            if (null === $ov20 || null === $fiftyninthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **68 expected smul overflow metadata (fiftyninth)');
            }
            $fiftyninthLong = JITVariable::KIND_VARIABLE === $fiftyninthVar->kind
                ? $context->builder->load($fiftyninthVar->value)
                : $fiftyninthVar->value;
            $ov20Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_fiftyninth_ov');
            $ok20Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_fiftyninth_ok');
            $context->builder->branchIf($ov20, $ov20Block, $ok20Block);

            $context->builder->positionAtEnd($ov20Block);
            $fiftyninthF20 = $context->builder->load($fiftyninthVar->longArithOverflowDoubleSlot);
            $nF20 = $context->builder->siToFp($n, $f64);
            $sixtyeighthF20 = $context->builder->fmul($fiftyninthF20, $nF20);
                $sixtyeighthF20 = $context->builder->fmul($sixtyeighthF20, $nF20);
                    $sixtyeighthF20 = $context->builder->fmul($sixtyeighthF20, $nF20);
                        $sixtyeighthF20 = $context->builder->fmul($sixtyeighthF20, $nF20);
                        $sixtyeighthF20 = $context->builder->fmul($sixtyeighthF20, $nF20);
                        $sixtyeighthF20 = $context->builder->fmul($sixtyeighthF20, $nF20);
                        $sixtyeighthF20 = $context->builder->fmul($sixtyeighthF20, $nF20);
                        $sixtyeighthF20 = $context->builder->fmul($sixtyeighthF20, $nF20);
                        $sixtyeighthF20 = $context->builder->fmul($sixtyeighthF20, $nF20);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyeighthF20
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok20Block);
            $sixtiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyninthLong,
                $n
            );
            $ov21 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixtiethVar->longArithOverflowFlag);
            if (null === $ov21 || null === $sixtiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **68 expected smul overflow metadata (sixtieth)');
            }
            $sixtiethLong = JITVariable::KIND_VARIABLE === $sixtiethVar->kind
                ? $context->builder->load($sixtiethVar->value)
                : $sixtiethVar->value;
            $ov21Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_sixtieth_ov');
            $ok21Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_sixtieth_ok');
            $context->builder->branchIf($ov21, $ov21Block, $ok21Block);

            $context->builder->positionAtEnd($ov21Block);
            $sixtiethF21 = $context->builder->load($sixtiethVar->longArithOverflowDoubleSlot);
            $nF21 = $context->builder->siToFp($n, $f64);
            $sixtyeighthF21 = $context->builder->fmul($sixtiethF21, $nF21);
                $sixtyeighthF21 = $context->builder->fmul($sixtyeighthF21, $nF21);
                    $sixtyeighthF21 = $context->builder->fmul($sixtyeighthF21, $nF21);
                    $sixtyeighthF21 = $context->builder->fmul($sixtyeighthF21, $nF21);
                    $sixtyeighthF21 = $context->builder->fmul($sixtyeighthF21, $nF21);
                    $sixtyeighthF21 = $context->builder->fmul($sixtyeighthF21, $nF21);
                    $sixtyeighthF21 = $context->builder->fmul($sixtyeighthF21, $nF21);
                    $sixtyeighthF21 = $context->builder->fmul($sixtyeighthF21, $nF21);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyeighthF21
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok21Block);
            $sixtyfirstVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixtiethLong,
                $n
            );
            $ov22 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixtyfirstVar->longArithOverflowFlag);
            if (null === $ov22 || null === $sixtyfirstVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **68 expected smul overflow metadata (sixtyfirst)');
            }
            $sixtyfirstLong = JITVariable::KIND_VARIABLE === $sixtyfirstVar->kind
                ? $context->builder->load($sixtyfirstVar->value)
                : $sixtyfirstVar->value;
            $ov22Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_sixtyfirst_ov');
            $ok22Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_sixtyfirst_ok');
            $context->builder->branchIf($ov22, $ov22Block, $ok22Block);

            $context->builder->positionAtEnd($ov22Block);
            $sixtyfirstF22 = $context->builder->load($sixtyfirstVar->longArithOverflowDoubleSlot);
            $nF22 = $context->builder->siToFp($n, $f64);
            $sixtyeighthF22 = $context->builder->fmul($sixtyfirstF22, $nF22);
                $sixtyeighthF22 = $context->builder->fmul($sixtyeighthF22, $nF22);
                $sixtyeighthF22 = $context->builder->fmul($sixtyeighthF22, $nF22);
                $sixtyeighthF22 = $context->builder->fmul($sixtyeighthF22, $nF22);
                $sixtyeighthF22 = $context->builder->fmul($sixtyeighthF22, $nF22);
                $sixtyeighthF22 = $context->builder->fmul($sixtyeighthF22, $nF22);
                $sixtyeighthF22 = $context->builder->fmul($sixtyeighthF22, $nF22);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyeighthF22
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok22Block);
            $sixtysecondVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixtyfirstLong,
                $n
            );
            $ov23 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixtysecondVar->longArithOverflowFlag);
            if (null === $ov23 || null === $sixtysecondVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **68 expected smul overflow metadata (sixtysecond)');
            }
            $sixtysecondLong = JITVariable::KIND_VARIABLE === $sixtysecondVar->kind
                ? $context->builder->load($sixtysecondVar->value)
                : $sixtysecondVar->value;
            $ov23Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_sixtysecond_ov');
            $ok23Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_sixtysecond_ok');
            $context->builder->branchIf($ov23, $ov23Block, $ok23Block);

            $context->builder->positionAtEnd($ov23Block);
            $sixtysecondF23 = $context->builder->load($sixtysecondVar->longArithOverflowDoubleSlot);
            $nF23 = $context->builder->siToFp($n, $f64);
            $sixtyeighthF23 = $context->builder->fmul($sixtysecondF23, $nF23);
            $sixtyeighthF23 = $context->builder->fmul($sixtyeighthF23, $nF23);
            $sixtyeighthF23 = $context->builder->fmul($sixtyeighthF23, $nF23);
            $sixtyeighthF23 = $context->builder->fmul($sixtyeighthF23, $nF23);
            $sixtyeighthF23 = $context->builder->fmul($sixtyeighthF23, $nF23);
            $sixtyeighthF23 = $context->builder->fmul($sixtyeighthF23, $nF23);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyeighthF23
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok23Block);
            $sixtythirdVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixtysecondLong,
                $n
            );
            $ov24 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixtythirdVar->longArithOverflowFlag);
            if (null === $ov24 || null === $sixtythirdVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **68 expected smul overflow metadata (sixtythird)');
            }
            $sixtythirdLong = JITVariable::KIND_VARIABLE === $sixtythirdVar->kind
                ? $context->builder->load($sixtythirdVar->value)
                : $sixtythirdVar->value;
            $ov24Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_sixtythird_ov');
            $ok24Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_sixtythird_ok');
            $context->builder->branchIf($ov24, $ov24Block, $ok24Block);

            $context->builder->positionAtEnd($ov24Block);
            $sixtythirdF24 = $context->builder->load($sixtythirdVar->longArithOverflowDoubleSlot);
            $nF24 = $context->builder->siToFp($n, $f64);
            $sixtyeighthF24 = $context->builder->fmul($sixtythirdF24, $nF24);
            $sixtyeighthF24 = $context->builder->fmul($sixtyeighthF24, $nF24);
            $sixtyeighthF24 = $context->builder->fmul($sixtyeighthF24, $nF24);
            $sixtyeighthF24 = $context->builder->fmul($sixtyeighthF24, $nF24);
            $sixtyeighthF24 = $context->builder->fmul($sixtyeighthF24, $nF24);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyeighthF24
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok24Block);
            $sixtyfourthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixtythirdLong,
                $n
            );
            $ov25 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixtyfourthVar->longArithOverflowFlag);
            if (null === $ov25 || null === $sixtyfourthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **68 expected smul overflow metadata (sixtyfourth)');
            }
            $sixtyfourthLong = JITVariable::KIND_VARIABLE === $sixtyfourthVar->kind
                ? $context->builder->load($sixtyfourthVar->value)
                : $sixtyfourthVar->value;
            $ov25Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_sixtyfourth_ov');
            $ok25Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_sixtyfourth_ok');
            $context->builder->branchIf($ov25, $ov25Block, $ok25Block);

            $context->builder->positionAtEnd($ov25Block);
            $sixtyfourthF25 = $context->builder->load($sixtyfourthVar->longArithOverflowDoubleSlot);
            $nF25 = $context->builder->siToFp($n, $f64);
            $sixtyeighthF25 = $context->builder->fmul($sixtyfourthF25, $nF25);
            $sixtyeighthF25 = $context->builder->fmul($sixtyeighthF25, $nF25);
            $sixtyeighthF25 = $context->builder->fmul($sixtyeighthF25, $nF25);
            $sixtyeighthF25 = $context->builder->fmul($sixtyeighthF25, $nF25);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyeighthF25
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok25Block);
            $sixtyfifthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixtyfourthLong,
                $n
            );
            $ov26 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixtyfifthVar->longArithOverflowFlag);
            if (null === $ov26 || null === $sixtyfifthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **68 expected smul overflow metadata (sixtyfifth)');
            }
            $sixtyfifthLong = JITVariable::KIND_VARIABLE === $sixtyfifthVar->kind
                ? $context->builder->load($sixtyfifthVar->value)
                : $sixtyfifthVar->value;
            $ov26Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_sixtyfifth_ov');
            $ok26Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_sixtyfifth_ok');
            $context->builder->branchIf($ov26, $ov26Block, $ok26Block);

            $context->builder->positionAtEnd($ov26Block);
            $sixtyfifthF26 = $context->builder->load($sixtyfifthVar->longArithOverflowDoubleSlot);
            $nF26 = $context->builder->siToFp($n, $f64);
            $sixtyeighthF26 = $context->builder->fmul($sixtyfifthF26, $nF26);
            $sixtyeighthF26 = $context->builder->fmul($sixtyeighthF26, $nF26);
            $sixtyeighthF26 = $context->builder->fmul($sixtyeighthF26, $nF26);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyeighthF26
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok26Block);
            $sixtysixthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixtyfifthLong,
                $n
            );
            $ov27 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixtysixthVar->longArithOverflowFlag);
            if (null === $ov27 || null === $sixtysixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **68 expected smul overflow metadata (sixtysixth)');
            }
            $sixtysixthLong = JITVariable::KIND_VARIABLE === $sixtysixthVar->kind
                ? $context->builder->load($sixtysixthVar->value)
                : $sixtysixthVar->value;
            $ov27Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_sixtysixth_ov');
            $ok27Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_sixtysixth_ok');
            $context->builder->branchIf($ov27, $ov27Block, $ok27Block);

            $context->builder->positionAtEnd($ov27Block);
            $sixtysixthF27 = $context->builder->load($sixtysixthVar->longArithOverflowDoubleSlot);
            $nF27 = $context->builder->siToFp($n, $f64);
            $sixtyeighthF27 = $context->builder->fmul($sixtysixthF27, $nF27);
            $sixtyeighthF27 = $context->builder->fmul($sixtyeighthF27, $nF27);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyeighthF27
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok27Block);
            $sixtyseventhVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixtysixthLong,
                $n
            );
            $ov28 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixtyseventhVar->longArithOverflowFlag);
            if (null === $ov28 || null === $sixtyseventhVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **68 expected smul overflow metadata (sixtyseventh)');
            }
            $sixtyseventhLong = JITVariable::KIND_VARIABLE === $sixtyseventhVar->kind
                ? $context->builder->load($sixtyseventhVar->value)
                : $sixtyseventhVar->value;
            $ov28Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_sixtyseventh_ov');
            $ok28Block = BasicBlockHelper::append($context, 'pow_sixtyeighth_sixtyseventh_ok');
            $context->builder->branchIf($ov28, $ov28Block, $ok28Block);

            $context->builder->positionAtEnd($ov28Block);
            $sixtyseventhF28 = $context->builder->load($sixtyseventhVar->longArithOverflowDoubleSlot);
            $nF28 = $context->builder->siToFp($n, $f64);
            $sixtyeighthF28 = $context->builder->fmul($sixtyseventhF28, $nF28);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyeighthF28
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok28Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $sixtyseventhLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);


            $context->builder->positionAtEnd($doneBlock);

            return true;
        }
        if ('sixtyninth' === $expFold) {
            // n^69 = sixtyeighth*n; overflow arms +1 ×nF vs **68.
            // tenth=fifth*fifth, twentieth=tenth*tenth,
            // fortieth=twentieth*twentieth, fortysecond=fortieth*sq, fortyfourth=fortysecond*sq,
            // fortysixth=fortyfourth*sq, then fortysixth*sq*sq*n… (sixtyseventh×n).
            // Overflow arms finish in float with one extra ×nF vs **68.
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
                throw new \LogicException('pow() **69 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_sixtyninth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_sixtyninth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_sixtyninth_done');
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
            $sixtyninthFSq = $context->builder->fmul($fortyfourthF, $sqF);
            $sixtyninthFEighth = $context->builder->fmul($sixtyninthFSq, $sqF);
            $sixtyninthF = $context->builder->fmul($sixtyninthFEighth, $sqF);
            $nF = $context->builder->siToFp($n, $f64);
            $sixtyninthF = $context->builder->fmul($sixtyninthF, $nF);
            $sixtyninthF = $context->builder->fmul($sixtyninthF, $nF);
            $sixtyninthF = $context->builder->fmul($sixtyninthF, $nF);
            $sixtyninthF = $context->builder->fmul($sixtyninthF, $nF);
            $sixtyninthF = $context->builder->fmul($sixtyninthF, $nF);
            $sixtyninthF = $context->builder->fmul($sixtyninthF, $nF);
            $sixtyninthF = $context->builder->fmul($sixtyninthF, $nF);
            $sixtyninthF = $context->builder->fmul($sixtyninthF, $nF);
            $sixtyninthF = $context->builder->fmul($sixtyninthF, $nF);
            $sixtyninthF = $context->builder->fmul($sixtyninthF, $nF);
                $sixtyninthF = $context->builder->fmul($sixtyninthF, $nF);
                    $sixtyninthF = $context->builder->fmul($sixtyninthF, $nF);
                        $sixtyninthF = $context->builder->fmul($sixtyninthF, $nF);
                        $sixtyninthF = $context->builder->fmul($sixtyninthF, $nF);
                        $sixtyninthF = $context->builder->fmul($sixtyninthF, $nF);
                        $sixtyninthF = $context->builder->fmul($sixtyninthF, $nF);
                        $sixtyninthF = $context->builder->fmul($sixtyninthF, $nF);
                        $sixtyninthF = $context->builder->fmul($sixtyninthF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyninthF
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
                throw new \LogicException('pow() **69 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_sixtyninth_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_sixtyninth_cu_ok');
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
            $sixtyninthF2Sq = $context->builder->fmul($fortyfourthF2, $sqF2);
            $sixtyninthF2Eighth = $context->builder->fmul($sixtyninthF2Sq, $sqF2);
            $sixtyninthF2 = $context->builder->fmul($sixtyninthF2Eighth, $sqF2);
            $nF2 = $context->builder->siToFp($n, $f64);
            $sixtyninthF2 = $context->builder->fmul($sixtyninthF2, $nF2);
            $sixtyninthF2 = $context->builder->fmul($sixtyninthF2, $nF2);
            $sixtyninthF2 = $context->builder->fmul($sixtyninthF2, $nF2);
            $sixtyninthF2 = $context->builder->fmul($sixtyninthF2, $nF2);
            $sixtyninthF2 = $context->builder->fmul($sixtyninthF2, $nF2);
            $sixtyninthF2 = $context->builder->fmul($sixtyninthF2, $nF2);
            $sixtyninthF2 = $context->builder->fmul($sixtyninthF2, $nF2);
            $sixtyninthF2 = $context->builder->fmul($sixtyninthF2, $nF2);
            $sixtyninthF2 = $context->builder->fmul($sixtyninthF2, $nF2);
            $sixtyninthF2 = $context->builder->fmul($sixtyninthF2, $nF2);
                $sixtyninthF2 = $context->builder->fmul($sixtyninthF2, $nF2);
                    $sixtyninthF2 = $context->builder->fmul($sixtyninthF2, $nF2);
                        $sixtyninthF2 = $context->builder->fmul($sixtyninthF2, $nF2);
                        $sixtyninthF2 = $context->builder->fmul($sixtyninthF2, $nF2);
                        $sixtyninthF2 = $context->builder->fmul($sixtyninthF2, $nF2);
                        $sixtyninthF2 = $context->builder->fmul($sixtyninthF2, $nF2);
                        $sixtyninthF2 = $context->builder->fmul($sixtyninthF2, $nF2);
                        $sixtyninthF2 = $context->builder->fmul($sixtyninthF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyninthF2
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
                throw new \LogicException('pow() **69 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_sixtyninth_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_sixtyninth_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $twentiethF3 = $context->builder->fmul($tenthF3, $tenthF3);
            $fortiethF3 = $context->builder->fmul($twentiethF3, $twentiethF3);
            $sqF3 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF3 = $context->builder->fmul($fortiethF3, $sqF3);
            $fortyfourthF3 = $context->builder->fmul($fortysecondF3, $sqF3);
            $sixtyninthF3Sq = $context->builder->fmul($fortyfourthF3, $sqF3);
            $sixtyninthF3Eighth = $context->builder->fmul($sixtyninthF3Sq, $sqF3);
            $sixtyninthF3 = $context->builder->fmul($sixtyninthF3Eighth, $sqF3);
            $nF3 = $context->builder->siToFp($n, $f64);
            $sixtyninthF3 = $context->builder->fmul($sixtyninthF3, $nF3);
            $sixtyninthF3 = $context->builder->fmul($sixtyninthF3, $nF3);
            $sixtyninthF3 = $context->builder->fmul($sixtyninthF3, $nF3);
            $sixtyninthF3 = $context->builder->fmul($sixtyninthF3, $nF3);
            $sixtyninthF3 = $context->builder->fmul($sixtyninthF3, $nF3);
            $sixtyninthF3 = $context->builder->fmul($sixtyninthF3, $nF3);
            $sixtyninthF3 = $context->builder->fmul($sixtyninthF3, $nF3);
            $sixtyninthF3 = $context->builder->fmul($sixtyninthF3, $nF3);
            $sixtyninthF3 = $context->builder->fmul($sixtyninthF3, $nF3);
            $sixtyninthF3 = $context->builder->fmul($sixtyninthF3, $nF3);
                $sixtyninthF3 = $context->builder->fmul($sixtyninthF3, $nF3);
                    $sixtyninthF3 = $context->builder->fmul($sixtyninthF3, $nF3);
                        $sixtyninthF3 = $context->builder->fmul($sixtyninthF3, $nF3);
                        $sixtyninthF3 = $context->builder->fmul($sixtyninthF3, $nF3);
                        $sixtyninthF3 = $context->builder->fmul($sixtyninthF3, $nF3);
                        $sixtyninthF3 = $context->builder->fmul($sixtyninthF3, $nF3);
                        $sixtyninthF3 = $context->builder->fmul($sixtyninthF3, $nF3);
                        $sixtyninthF3 = $context->builder->fmul($sixtyninthF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyninthF3
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
                throw new \LogicException('pow() **69 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_sixtyninth_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_sixtyninth_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF4 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $twentiethF4 = $context->builder->fmul($tenthF4, $tenthF4);
            $fortiethF4 = $context->builder->fmul($twentiethF4, $twentiethF4);
            $sqF4 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF4 = $context->builder->fmul($fortiethF4, $sqF4);
            $fortyfourthF4 = $context->builder->fmul($fortysecondF4, $sqF4);
            $sixtyninthF4Sq = $context->builder->fmul($fortyfourthF4, $sqF4);
            $sixtyninthF4Eighth = $context->builder->fmul($sixtyninthF4Sq, $sqF4);
            $sixtyninthF4 = $context->builder->fmul($sixtyninthF4Eighth, $sqF4);
            $nF4 = $context->builder->siToFp($n, $f64);
            $sixtyninthF4 = $context->builder->fmul($sixtyninthF4, $nF4);
            $sixtyninthF4 = $context->builder->fmul($sixtyninthF4, $nF4);
            $sixtyninthF4 = $context->builder->fmul($sixtyninthF4, $nF4);
            $sixtyninthF4 = $context->builder->fmul($sixtyninthF4, $nF4);
            $sixtyninthF4 = $context->builder->fmul($sixtyninthF4, $nF4);
            $sixtyninthF4 = $context->builder->fmul($sixtyninthF4, $nF4);
            $sixtyninthF4 = $context->builder->fmul($sixtyninthF4, $nF4);
            $sixtyninthF4 = $context->builder->fmul($sixtyninthF4, $nF4);
            $sixtyninthF4 = $context->builder->fmul($sixtyninthF4, $nF4);
            $sixtyninthF4 = $context->builder->fmul($sixtyninthF4, $nF4);
                $sixtyninthF4 = $context->builder->fmul($sixtyninthF4, $nF4);
                    $sixtyninthF4 = $context->builder->fmul($sixtyninthF4, $nF4);
                        $sixtyninthF4 = $context->builder->fmul($sixtyninthF4, $nF4);
                        $sixtyninthF4 = $context->builder->fmul($sixtyninthF4, $nF4);
                        $sixtyninthF4 = $context->builder->fmul($sixtyninthF4, $nF4);
                        $sixtyninthF4 = $context->builder->fmul($sixtyninthF4, $nF4);
                        $sixtyninthF4 = $context->builder->fmul($sixtyninthF4, $nF4);
                        $sixtyninthF4 = $context->builder->fmul($sixtyninthF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyninthF4
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
                throw new \LogicException('pow() **69 expected smul overflow metadata (twentieth)');
            }
            $twentiethLong = JITVariable::KIND_VARIABLE === $twentiethVar->kind
                ? $context->builder->load($twentiethVar->value)
                : $twentiethVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_sixtyninth_twentieth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_sixtyninth_twentieth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $twentiethF5 = $context->builder->load($twentiethVar->longArithOverflowDoubleSlot);
            $fortiethF5 = $context->builder->fmul($twentiethF5, $twentiethF5);
            $sqF5 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF5 = $context->builder->fmul($fortiethF5, $sqF5);
            $fortyfourthF5 = $context->builder->fmul($fortysecondF5, $sqF5);
            $sixtyninthF5Sq = $context->builder->fmul($fortyfourthF5, $sqF5);
            $sixtyninthF5Eighth = $context->builder->fmul($sixtyninthF5Sq, $sqF5);
            $sixtyninthF5 = $context->builder->fmul($sixtyninthF5Eighth, $sqF5);
            $nF5 = $context->builder->siToFp($n, $f64);
            $sixtyninthF5 = $context->builder->fmul($sixtyninthF5, $nF5);
            $sixtyninthF5 = $context->builder->fmul($sixtyninthF5, $nF5);
            $sixtyninthF5 = $context->builder->fmul($sixtyninthF5, $nF5);
            $sixtyninthF5 = $context->builder->fmul($sixtyninthF5, $nF5);
            $sixtyninthF5 = $context->builder->fmul($sixtyninthF5, $nF5);
            $sixtyninthF5 = $context->builder->fmul($sixtyninthF5, $nF5);
            $sixtyninthF5 = $context->builder->fmul($sixtyninthF5, $nF5);
            $sixtyninthF5 = $context->builder->fmul($sixtyninthF5, $nF5);
            $sixtyninthF5 = $context->builder->fmul($sixtyninthF5, $nF5);
            $sixtyninthF5 = $context->builder->fmul($sixtyninthF5, $nF5);
                $sixtyninthF5 = $context->builder->fmul($sixtyninthF5, $nF5);
                    $sixtyninthF5 = $context->builder->fmul($sixtyninthF5, $nF5);
                        $sixtyninthF5 = $context->builder->fmul($sixtyninthF5, $nF5);
                        $sixtyninthF5 = $context->builder->fmul($sixtyninthF5, $nF5);
                        $sixtyninthF5 = $context->builder->fmul($sixtyninthF5, $nF5);
                        $sixtyninthF5 = $context->builder->fmul($sixtyninthF5, $nF5);
                        $sixtyninthF5 = $context->builder->fmul($sixtyninthF5, $nF5);
                        $sixtyninthF5 = $context->builder->fmul($sixtyninthF5, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyninthF5
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
                throw new \LogicException('pow() **69 expected smul overflow metadata (fortieth)');
            }
            $fortiethLong = JITVariable::KIND_VARIABLE === $fortiethVar->kind
                ? $context->builder->load($fortiethVar->value)
                : $fortiethVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_sixtyninth_fortieth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_sixtyninth_fortieth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $fortiethF6 = $context->builder->load($fortiethVar->longArithOverflowDoubleSlot);
            $sqF6 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF6 = $context->builder->fmul($fortiethF6, $sqF6);
            $fortyfourthF6 = $context->builder->fmul($fortysecondF6, $sqF6);
            $sixtyninthF6Sq = $context->builder->fmul($fortyfourthF6, $sqF6);
            $sixtyninthF6Eighth = $context->builder->fmul($sixtyninthF6Sq, $sqF6);
            $sixtyninthF6 = $context->builder->fmul($sixtyninthF6Eighth, $sqF6);
            $nF6 = $context->builder->siToFp($n, $f64);
            $sixtyninthF6 = $context->builder->fmul($sixtyninthF6, $nF6);
            $sixtyninthF6 = $context->builder->fmul($sixtyninthF6, $nF6);
            $sixtyninthF6 = $context->builder->fmul($sixtyninthF6, $nF6);
            $sixtyninthF6 = $context->builder->fmul($sixtyninthF6, $nF6);
            $sixtyninthF6 = $context->builder->fmul($sixtyninthF6, $nF6);
            $sixtyninthF6 = $context->builder->fmul($sixtyninthF6, $nF6);
            $sixtyninthF6 = $context->builder->fmul($sixtyninthF6, $nF6);
            $sixtyninthF6 = $context->builder->fmul($sixtyninthF6, $nF6);
            $sixtyninthF6 = $context->builder->fmul($sixtyninthF6, $nF6);
            $sixtyninthF6 = $context->builder->fmul($sixtyninthF6, $nF6);
                $sixtyninthF6 = $context->builder->fmul($sixtyninthF6, $nF6);
                    $sixtyninthF6 = $context->builder->fmul($sixtyninthF6, $nF6);
                        $sixtyninthF6 = $context->builder->fmul($sixtyninthF6, $nF6);
                        $sixtyninthF6 = $context->builder->fmul($sixtyninthF6, $nF6);
                        $sixtyninthF6 = $context->builder->fmul($sixtyninthF6, $nF6);
                        $sixtyninthF6 = $context->builder->fmul($sixtyninthF6, $nF6);
                        $sixtyninthF6 = $context->builder->fmul($sixtyninthF6, $nF6);
                        $sixtyninthF6 = $context->builder->fmul($sixtyninthF6, $nF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyninthF6
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
                throw new \LogicException('pow() **69 expected smul overflow metadata (fortysecond)');
            }
            $fortysecondLong = JITVariable::KIND_VARIABLE === $fortysecondVar->kind
                ? $context->builder->load($fortysecondVar->value)
                : $fortysecondVar->value;
            $ov7Block = BasicBlockHelper::append($context, 'pow_sixtyninth_fortysecond_ov');
            $ok7Block = BasicBlockHelper::append($context, 'pow_sixtyninth_fortysecond_ok');
            $context->builder->branchIf($ov7, $ov7Block, $ok7Block);

            $context->builder->positionAtEnd($ov7Block);
            $fortysecondF7 = $context->builder->load($fortysecondVar->longArithOverflowDoubleSlot);
            $sqF7 = $context->builder->siToFp($sqLong, $f64);
            $fortyfourthF7 = $context->builder->fmul($fortysecondF7, $sqF7);
            $sixtyninthF7Sq = $context->builder->fmul($fortyfourthF7, $sqF7);
            $sixtyninthF7Eighth = $context->builder->fmul($sixtyninthF7Sq, $sqF7);
            $sixtyninthF7 = $context->builder->fmul($sixtyninthF7Eighth, $sqF7);
            $nF7 = $context->builder->siToFp($n, $f64);
            $sixtyninthF7 = $context->builder->fmul($sixtyninthF7, $nF7);
            $sixtyninthF7 = $context->builder->fmul($sixtyninthF7, $nF7);
            $sixtyninthF7 = $context->builder->fmul($sixtyninthF7, $nF7);
            $sixtyninthF7 = $context->builder->fmul($sixtyninthF7, $nF7);
            $sixtyninthF7 = $context->builder->fmul($sixtyninthF7, $nF7);
            $sixtyninthF7 = $context->builder->fmul($sixtyninthF7, $nF7);
            $sixtyninthF7 = $context->builder->fmul($sixtyninthF7, $nF7);
            $sixtyninthF7 = $context->builder->fmul($sixtyninthF7, $nF7);
            $sixtyninthF7 = $context->builder->fmul($sixtyninthF7, $nF7);
            $sixtyninthF7 = $context->builder->fmul($sixtyninthF7, $nF7);
                $sixtyninthF7 = $context->builder->fmul($sixtyninthF7, $nF7);
                    $sixtyninthF7 = $context->builder->fmul($sixtyninthF7, $nF7);
                        $sixtyninthF7 = $context->builder->fmul($sixtyninthF7, $nF7);
                        $sixtyninthF7 = $context->builder->fmul($sixtyninthF7, $nF7);
                        $sixtyninthF7 = $context->builder->fmul($sixtyninthF7, $nF7);
                        $sixtyninthF7 = $context->builder->fmul($sixtyninthF7, $nF7);
                        $sixtyninthF7 = $context->builder->fmul($sixtyninthF7, $nF7);
                        $sixtyninthF7 = $context->builder->fmul($sixtyninthF7, $nF7);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyninthF7
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
                throw new \LogicException('pow() **69 expected smul overflow metadata (fortyfourth)');
            }
            $fortyfourthLong = JITVariable::KIND_VARIABLE === $fortyfourthVar->kind
                ? $context->builder->load($fortyfourthVar->value)
                : $fortyfourthVar->value;
            $ov8Block = BasicBlockHelper::append($context, 'pow_sixtyninth_fortyfourth_ov');
            $ok8Block = BasicBlockHelper::append($context, 'pow_sixtyninth_fortyfourth_ok');
            $context->builder->branchIf($ov8, $ov8Block, $ok8Block);

            $context->builder->positionAtEnd($ov8Block);
            $fortyfourthF8 = $context->builder->load($fortyfourthVar->longArithOverflowDoubleSlot);
            $sqF8 = $context->builder->siToFp($sqLong, $f64);
            $sixtyninthF8Sq = $context->builder->fmul($fortyfourthF8, $sqF8);
            $sixtyninthF8Eighth = $context->builder->fmul($sixtyninthF8Sq, $sqF8);
            $sixtyninthF8 = $context->builder->fmul($sixtyninthF8Eighth, $sqF8);
            $nF8 = $context->builder->siToFp($n, $f64);
            $sixtyninthF8 = $context->builder->fmul($sixtyninthF8, $nF8);
            $sixtyninthF8 = $context->builder->fmul($sixtyninthF8, $nF8);
            $sixtyninthF8 = $context->builder->fmul($sixtyninthF8, $nF8);
            $sixtyninthF8 = $context->builder->fmul($sixtyninthF8, $nF8);
            $sixtyninthF8 = $context->builder->fmul($sixtyninthF8, $nF8);
            $sixtyninthF8 = $context->builder->fmul($sixtyninthF8, $nF8);
            $sixtyninthF8 = $context->builder->fmul($sixtyninthF8, $nF8);
            $sixtyninthF8 = $context->builder->fmul($sixtyninthF8, $nF8);
            $sixtyninthF8 = $context->builder->fmul($sixtyninthF8, $nF8);
            $sixtyninthF8 = $context->builder->fmul($sixtyninthF8, $nF8);
                $sixtyninthF8 = $context->builder->fmul($sixtyninthF8, $nF8);
                    $sixtyninthF8 = $context->builder->fmul($sixtyninthF8, $nF8);
                        $sixtyninthF8 = $context->builder->fmul($sixtyninthF8, $nF8);
                        $sixtyninthF8 = $context->builder->fmul($sixtyninthF8, $nF8);
                        $sixtyninthF8 = $context->builder->fmul($sixtyninthF8, $nF8);
                        $sixtyninthF8 = $context->builder->fmul($sixtyninthF8, $nF8);
                        $sixtyninthF8 = $context->builder->fmul($sixtyninthF8, $nF8);
                        $sixtyninthF8 = $context->builder->fmul($sixtyninthF8, $nF8);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyninthF8
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
                throw new \LogicException('pow() **69 expected smul overflow metadata (fortysixth)');
            }
            $fortysixthLong = JITVariable::KIND_VARIABLE === $fortysixthVar->kind
                ? $context->builder->load($fortysixthVar->value)
                : $fortysixthVar->value;
            $ov9Block = BasicBlockHelper::append($context, 'pow_sixtyninth_fortysixth_ov');
            $ok9Block = BasicBlockHelper::append($context, 'pow_sixtyninth_fortysixth_ok');
            $context->builder->branchIf($ov9, $ov9Block, $ok9Block);

            $context->builder->positionAtEnd($ov9Block);
            $fortysixthF9 = $context->builder->load($fortysixthVar->longArithOverflowDoubleSlot);
            $sqF9 = $context->builder->siToFp($sqLong, $f64);
            $fortyeighthF9 = $context->builder->fmul($fortysixthF9, $sqF9);
            $sixtyninthF9 = $context->builder->fmul($fortyeighthF9, $sqF9);
            $nF9 = $context->builder->siToFp($n, $f64);
            $sixtyninthF9 = $context->builder->fmul($sixtyninthF9, $nF9);
            $sixtyninthF9 = $context->builder->fmul($sixtyninthF9, $nF9);
            $sixtyninthF9 = $context->builder->fmul($sixtyninthF9, $nF9);
            $sixtyninthF9 = $context->builder->fmul($sixtyninthF9, $nF9);
            $sixtyninthF9 = $context->builder->fmul($sixtyninthF9, $nF9);
            $sixtyninthF9 = $context->builder->fmul($sixtyninthF9, $nF9);
            $sixtyninthF9 = $context->builder->fmul($sixtyninthF9, $nF9);
            $sixtyninthF9 = $context->builder->fmul($sixtyninthF9, $nF9);
            $sixtyninthF9 = $context->builder->fmul($sixtyninthF9, $nF9);
            $sixtyninthF9 = $context->builder->fmul($sixtyninthF9, $nF9);
                $sixtyninthF9 = $context->builder->fmul($sixtyninthF9, $nF9);
                    $sixtyninthF9 = $context->builder->fmul($sixtyninthF9, $nF9);
                        $sixtyninthF9 = $context->builder->fmul($sixtyninthF9, $nF9);
                        $sixtyninthF9 = $context->builder->fmul($sixtyninthF9, $nF9);
                        $sixtyninthF9 = $context->builder->fmul($sixtyninthF9, $nF9);
                        $sixtyninthF9 = $context->builder->fmul($sixtyninthF9, $nF9);
                        $sixtyninthF9 = $context->builder->fmul($sixtyninthF9, $nF9);
                        $sixtyninthF9 = $context->builder->fmul($sixtyninthF9, $nF9);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyninthF9
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
                throw new \LogicException('pow() **69 expected smul overflow metadata (fortyeighth)');
            }
            $fortyeighthLong = JITVariable::KIND_VARIABLE === $fortyeighthVar->kind
                ? $context->builder->load($fortyeighthVar->value)
                : $fortyeighthVar->value;
            $ov10Block = BasicBlockHelper::append($context, 'pow_sixtyninth_fortyeighth_ov');
            $ok10Block = BasicBlockHelper::append($context, 'pow_sixtyninth_fortyeighth_ok');
            $context->builder->branchIf($ov10, $ov10Block, $ok10Block);

            $context->builder->positionAtEnd($ov10Block);
            $fortyeighthF10 = $context->builder->load($fortyeighthVar->longArithOverflowDoubleSlot);
            $sqF10 = $context->builder->siToFp($sqLong, $f64);
            $sixtyninthF10 = $context->builder->fmul($fortyeighthF10, $sqF10);
            $nF10 = $context->builder->siToFp($n, $f64);
            $sixtyninthF10 = $context->builder->fmul($sixtyninthF10, $nF10);
            $sixtyninthF10 = $context->builder->fmul($sixtyninthF10, $nF10);
            $sixtyninthF10 = $context->builder->fmul($sixtyninthF10, $nF10);
            $sixtyninthF10 = $context->builder->fmul($sixtyninthF10, $nF10);
            $sixtyninthF10 = $context->builder->fmul($sixtyninthF10, $nF10);
            $sixtyninthF10 = $context->builder->fmul($sixtyninthF10, $nF10);
            $sixtyninthF10 = $context->builder->fmul($sixtyninthF10, $nF10);
            $sixtyninthF10 = $context->builder->fmul($sixtyninthF10, $nF10);
            $sixtyninthF10 = $context->builder->fmul($sixtyninthF10, $nF10);
            $sixtyninthF10 = $context->builder->fmul($sixtyninthF10, $nF10);
                $sixtyninthF10 = $context->builder->fmul($sixtyninthF10, $nF10);
                    $sixtyninthF10 = $context->builder->fmul($sixtyninthF10, $nF10);
                        $sixtyninthF10 = $context->builder->fmul($sixtyninthF10, $nF10);
                        $sixtyninthF10 = $context->builder->fmul($sixtyninthF10, $nF10);
                        $sixtyninthF10 = $context->builder->fmul($sixtyninthF10, $nF10);
                        $sixtyninthF10 = $context->builder->fmul($sixtyninthF10, $nF10);
                        $sixtyninthF10 = $context->builder->fmul($sixtyninthF10, $nF10);
                        $sixtyninthF10 = $context->builder->fmul($sixtyninthF10, $nF10);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyninthF10
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok10Block);
            $fiftiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fortyeighthLong,
                $sqLong
            );
            $ov11 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftiethVar->longArithOverflowFlag);
            if (null === $ov11 || null === $fiftiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **69 expected smul overflow metadata (fiftieth)');
            }
            $fiftiethLong = JITVariable::KIND_VARIABLE === $fiftiethVar->kind
                ? $context->builder->load($fiftiethVar->value)
                : $fiftiethVar->value;
            $ov11Block = BasicBlockHelper::append($context, 'pow_sixtyninth_fiftieth_ov');
            $ok11Block = BasicBlockHelper::append($context, 'pow_sixtyninth_fiftieth_ok');
            $context->builder->branchIf($ov11, $ov11Block, $ok11Block);

            $context->builder->positionAtEnd($ov11Block);
            $fiftiethF11 = $context->builder->load($fiftiethVar->longArithOverflowDoubleSlot);
            $nF11 = $context->builder->siToFp($n, $f64);
            $sixtyninthF11 = $context->builder->fmul($fiftiethF11, $nF11);
            $sixtyninthF11 = $context->builder->fmul($sixtyninthF11, $nF11);
            $sixtyninthF11 = $context->builder->fmul($sixtyninthF11, $nF11);
            $sixtyninthF11 = $context->builder->fmul($sixtyninthF11, $nF11);
            $sixtyninthF11 = $context->builder->fmul($sixtyninthF11, $nF11);
            $sixtyninthF11 = $context->builder->fmul($sixtyninthF11, $nF11);
            $sixtyninthF11 = $context->builder->fmul($sixtyninthF11, $nF11);
            $sixtyninthF11 = $context->builder->fmul($sixtyninthF11, $nF11);
            $sixtyninthF11 = $context->builder->fmul($sixtyninthF11, $nF11);
            $sixtyninthF11 = $context->builder->fmul($sixtyninthF11, $nF11);
                $sixtyninthF11 = $context->builder->fmul($sixtyninthF11, $nF11);
                    $sixtyninthF11 = $context->builder->fmul($sixtyninthF11, $nF11);
                        $sixtyninthF11 = $context->builder->fmul($sixtyninthF11, $nF11);
                        $sixtyninthF11 = $context->builder->fmul($sixtyninthF11, $nF11);
                        $sixtyninthF11 = $context->builder->fmul($sixtyninthF11, $nF11);
                        $sixtyninthF11 = $context->builder->fmul($sixtyninthF11, $nF11);
                        $sixtyninthF11 = $context->builder->fmul($sixtyninthF11, $nF11);
                        $sixtyninthF11 = $context->builder->fmul($sixtyninthF11, $nF11);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyninthF11
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok11Block);
            $fiftyfirstVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftiethLong,
                $n
            );
            $ov12 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyfirstVar->longArithOverflowFlag);
            if (null === $ov12 || null === $fiftyfirstVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **69 expected smul overflow metadata (fiftyfirst)');
            }
            $fiftyfirstLong = JITVariable::KIND_VARIABLE === $fiftyfirstVar->kind
                ? $context->builder->load($fiftyfirstVar->value)
                : $fiftyfirstVar->value;
            $ov12Block = BasicBlockHelper::append($context, 'pow_sixtyninth_fiftyfirst_ov');
            $ok12Block = BasicBlockHelper::append($context, 'pow_sixtyninth_fiftyfirst_ok');
            $context->builder->branchIf($ov12, $ov12Block, $ok12Block);

            $context->builder->positionAtEnd($ov12Block);
            $fiftyfirstF12 = $context->builder->load($fiftyfirstVar->longArithOverflowDoubleSlot);
            $nF12 = $context->builder->siToFp($n, $f64);
            $sixtyninthF12 = $context->builder->fmul($fiftyfirstF12, $nF12);
            $sixtyninthF12 = $context->builder->fmul($sixtyninthF12, $nF12);
            $sixtyninthF12 = $context->builder->fmul($sixtyninthF12, $nF12);
            $sixtyninthF12 = $context->builder->fmul($sixtyninthF12, $nF12);
            $sixtyninthF12 = $context->builder->fmul($sixtyninthF12, $nF12);
            $sixtyninthF12 = $context->builder->fmul($sixtyninthF12, $nF12);
            $sixtyninthF12 = $context->builder->fmul($sixtyninthF12, $nF12);
            $sixtyninthF12 = $context->builder->fmul($sixtyninthF12, $nF12);
            $sixtyninthF12 = $context->builder->fmul($sixtyninthF12, $nF12);
                $sixtyninthF12 = $context->builder->fmul($sixtyninthF12, $nF12);
                    $sixtyninthF12 = $context->builder->fmul($sixtyninthF12, $nF12);
                        $sixtyninthF12 = $context->builder->fmul($sixtyninthF12, $nF12);
                        $sixtyninthF12 = $context->builder->fmul($sixtyninthF12, $nF12);
                        $sixtyninthF12 = $context->builder->fmul($sixtyninthF12, $nF12);
                        $sixtyninthF12 = $context->builder->fmul($sixtyninthF12, $nF12);
                        $sixtyninthF12 = $context->builder->fmul($sixtyninthF12, $nF12);
                        $sixtyninthF12 = $context->builder->fmul($sixtyninthF12, $nF12);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyninthF12
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok12Block);
            $fiftysecondVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyfirstLong,
                $n
            );
            $ov13 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftysecondVar->longArithOverflowFlag);
            if (null === $ov13 || null === $fiftysecondVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **69 expected smul overflow metadata (fiftysecond)');
            }
            $fiftysecondLong = JITVariable::KIND_VARIABLE === $fiftysecondVar->kind
                ? $context->builder->load($fiftysecondVar->value)
                : $fiftysecondVar->value;
            $ov13Block = BasicBlockHelper::append($context, 'pow_sixtyninth_fiftysecond_ov');
            $ok13Block = BasicBlockHelper::append($context, 'pow_sixtyninth_fiftysecond_ok');
            $context->builder->branchIf($ov13, $ov13Block, $ok13Block);

            $context->builder->positionAtEnd($ov13Block);
            $fiftysecondF13 = $context->builder->load($fiftysecondVar->longArithOverflowDoubleSlot);
            $nF13 = $context->builder->siToFp($n, $f64);
            $sixtyninthF13 = $context->builder->fmul($fiftysecondF13, $nF13);
            $sixtyninthF13 = $context->builder->fmul($sixtyninthF13, $nF13);
            $sixtyninthF13 = $context->builder->fmul($sixtyninthF13, $nF13);
            $sixtyninthF13 = $context->builder->fmul($sixtyninthF13, $nF13);
            $sixtyninthF13 = $context->builder->fmul($sixtyninthF13, $nF13);
            $sixtyninthF13 = $context->builder->fmul($sixtyninthF13, $nF13);
            $sixtyninthF13 = $context->builder->fmul($sixtyninthF13, $nF13);
            $sixtyninthF13 = $context->builder->fmul($sixtyninthF13, $nF13);
                $sixtyninthF13 = $context->builder->fmul($sixtyninthF13, $nF13);
                    $sixtyninthF13 = $context->builder->fmul($sixtyninthF13, $nF13);
                        $sixtyninthF13 = $context->builder->fmul($sixtyninthF13, $nF13);
                        $sixtyninthF13 = $context->builder->fmul($sixtyninthF13, $nF13);
                        $sixtyninthF13 = $context->builder->fmul($sixtyninthF13, $nF13);
                        $sixtyninthF13 = $context->builder->fmul($sixtyninthF13, $nF13);
                        $sixtyninthF13 = $context->builder->fmul($sixtyninthF13, $nF13);
                        $sixtyninthF13 = $context->builder->fmul($sixtyninthF13, $nF13);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyninthF13
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok13Block);
            $fiftythirdVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftysecondLong,
                $n
            );
            $ov14 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftythirdVar->longArithOverflowFlag);
            if (null === $ov14 || null === $fiftythirdVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **69 expected smul overflow metadata (fiftythird)');
            }
            $fiftythirdLong = JITVariable::KIND_VARIABLE === $fiftythirdVar->kind
                ? $context->builder->load($fiftythirdVar->value)
                : $fiftythirdVar->value;
            $ov14Block = BasicBlockHelper::append($context, 'pow_sixtyninth_fiftythird_ov');
            $ok14Block = BasicBlockHelper::append($context, 'pow_sixtyninth_fiftythird_ok');
            $context->builder->branchIf($ov14, $ov14Block, $ok14Block);

            $context->builder->positionAtEnd($ov14Block);
            $fiftythirdF14 = $context->builder->load($fiftythirdVar->longArithOverflowDoubleSlot);
            $nF14 = $context->builder->siToFp($n, $f64);
            $sixtyninthF14 = $context->builder->fmul($fiftythirdF14, $nF14);
            $sixtyninthF14 = $context->builder->fmul($sixtyninthF14, $nF14);
            $sixtyninthF14 = $context->builder->fmul($sixtyninthF14, $nF14);
            $sixtyninthF14 = $context->builder->fmul($sixtyninthF14, $nF14);
            $sixtyninthF14 = $context->builder->fmul($sixtyninthF14, $nF14);
            $sixtyninthF14 = $context->builder->fmul($sixtyninthF14, $nF14);
            $sixtyninthF14 = $context->builder->fmul($sixtyninthF14, $nF14);
                $sixtyninthF14 = $context->builder->fmul($sixtyninthF14, $nF14);
                    $sixtyninthF14 = $context->builder->fmul($sixtyninthF14, $nF14);
                        $sixtyninthF14 = $context->builder->fmul($sixtyninthF14, $nF14);
                        $sixtyninthF14 = $context->builder->fmul($sixtyninthF14, $nF14);
                        $sixtyninthF14 = $context->builder->fmul($sixtyninthF14, $nF14);
                        $sixtyninthF14 = $context->builder->fmul($sixtyninthF14, $nF14);
                        $sixtyninthF14 = $context->builder->fmul($sixtyninthF14, $nF14);
                        $sixtyninthF14 = $context->builder->fmul($sixtyninthF14, $nF14);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyninthF14
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok14Block);
            $fiftyfourthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftythirdLong,
                $n
            );
            $ov15 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyfourthVar->longArithOverflowFlag);
            if (null === $ov15 || null === $fiftyfourthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **69 expected smul overflow metadata (fiftyfourth)');
            }
            $fiftyfourthLong = JITVariable::KIND_VARIABLE === $fiftyfourthVar->kind
                ? $context->builder->load($fiftyfourthVar->value)
                : $fiftyfourthVar->value;
            $ov15Block = BasicBlockHelper::append($context, 'pow_sixtyninth_fiftyfourth_ov');
            $ok15Block = BasicBlockHelper::append($context, 'pow_sixtyninth_fiftyfourth_ok');
            $context->builder->branchIf($ov15, $ov15Block, $ok15Block);

            $context->builder->positionAtEnd($ov15Block);
            $fiftyfourthF15 = $context->builder->load($fiftyfourthVar->longArithOverflowDoubleSlot);
            $nF15 = $context->builder->siToFp($n, $f64);
            $sixtyninthF15 = $context->builder->fmul($fiftyfourthF15, $nF15);
            $sixtyninthF15 = $context->builder->fmul($sixtyninthF15, $nF15);
            $sixtyninthF15 = $context->builder->fmul($sixtyninthF15, $nF15);
            $sixtyninthF15 = $context->builder->fmul($sixtyninthF15, $nF15);
            $sixtyninthF15 = $context->builder->fmul($sixtyninthF15, $nF15);
            $sixtyninthF15 = $context->builder->fmul($sixtyninthF15, $nF15);
                $sixtyninthF15 = $context->builder->fmul($sixtyninthF15, $nF15);
                    $sixtyninthF15 = $context->builder->fmul($sixtyninthF15, $nF15);
                        $sixtyninthF15 = $context->builder->fmul($sixtyninthF15, $nF15);
                        $sixtyninthF15 = $context->builder->fmul($sixtyninthF15, $nF15);
                        $sixtyninthF15 = $context->builder->fmul($sixtyninthF15, $nF15);
                        $sixtyninthF15 = $context->builder->fmul($sixtyninthF15, $nF15);
                        $sixtyninthF15 = $context->builder->fmul($sixtyninthF15, $nF15);
                        $sixtyninthF15 = $context->builder->fmul($sixtyninthF15, $nF15);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyninthF15
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok15Block);
            $fiftyfifthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyfourthLong,
                $n
            );
            $ov16 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyfifthVar->longArithOverflowFlag);
            if (null === $ov16 || null === $fiftyfifthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **69 expected smul overflow metadata (fiftyfifth)');
            }
            $fiftyfifthLong = JITVariable::KIND_VARIABLE === $fiftyfifthVar->kind
                ? $context->builder->load($fiftyfifthVar->value)
                : $fiftyfifthVar->value;
            $ov16Block = BasicBlockHelper::append($context, 'pow_sixtyninth_fiftyfifth_ov');
            $ok16Block = BasicBlockHelper::append($context, 'pow_sixtyninth_fiftyfifth_ok');
            $context->builder->branchIf($ov16, $ov16Block, $ok16Block);

            $context->builder->positionAtEnd($ov16Block);
            $fiftyfifthF16 = $context->builder->load($fiftyfifthVar->longArithOverflowDoubleSlot);
            $nF16 = $context->builder->siToFp($n, $f64);
            $sixtyninthF16 = $context->builder->fmul($fiftyfifthF16, $nF16);
            $sixtyninthF16 = $context->builder->fmul($sixtyninthF16, $nF16);
            $sixtyninthF16 = $context->builder->fmul($sixtyninthF16, $nF16);
            $sixtyninthF16 = $context->builder->fmul($sixtyninthF16, $nF16);
            $sixtyninthF16 = $context->builder->fmul($sixtyninthF16, $nF16);
                $sixtyninthF16 = $context->builder->fmul($sixtyninthF16, $nF16);
                    $sixtyninthF16 = $context->builder->fmul($sixtyninthF16, $nF16);
                        $sixtyninthF16 = $context->builder->fmul($sixtyninthF16, $nF16);
                        $sixtyninthF16 = $context->builder->fmul($sixtyninthF16, $nF16);
                        $sixtyninthF16 = $context->builder->fmul($sixtyninthF16, $nF16);
                        $sixtyninthF16 = $context->builder->fmul($sixtyninthF16, $nF16);
                        $sixtyninthF16 = $context->builder->fmul($sixtyninthF16, $nF16);
                        $sixtyninthF16 = $context->builder->fmul($sixtyninthF16, $nF16);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyninthF16
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok16Block);
            $fiftysixthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyfifthLong,
                $n
            );
            $ov17 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftysixthVar->longArithOverflowFlag);
            if (null === $ov17 || null === $fiftysixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **69 expected smul overflow metadata (fiftysixth)');
            }
            $fiftysixthLong = JITVariable::KIND_VARIABLE === $fiftysixthVar->kind
                ? $context->builder->load($fiftysixthVar->value)
                : $fiftysixthVar->value;
            $ov17Block = BasicBlockHelper::append($context, 'pow_sixtyninth_fiftysixth_ov');
            $ok17Block = BasicBlockHelper::append($context, 'pow_sixtyninth_fiftysixth_ok');
            $context->builder->branchIf($ov17, $ov17Block, $ok17Block);

            $context->builder->positionAtEnd($ov17Block);
            $fiftysixthF17 = $context->builder->load($fiftysixthVar->longArithOverflowDoubleSlot);
            $nF17 = $context->builder->siToFp($n, $f64);
            $sixtyninthF17 = $context->builder->fmul($fiftysixthF17, $nF17);
            $sixtyninthF17 = $context->builder->fmul($sixtyninthF17, $nF17);
            $sixtyninthF17 = $context->builder->fmul($sixtyninthF17, $nF17);
            $sixtyninthF17 = $context->builder->fmul($sixtyninthF17, $nF17);
                $sixtyninthF17 = $context->builder->fmul($sixtyninthF17, $nF17);
                    $sixtyninthF17 = $context->builder->fmul($sixtyninthF17, $nF17);
                        $sixtyninthF17 = $context->builder->fmul($sixtyninthF17, $nF17);
                        $sixtyninthF17 = $context->builder->fmul($sixtyninthF17, $nF17);
                        $sixtyninthF17 = $context->builder->fmul($sixtyninthF17, $nF17);
                        $sixtyninthF17 = $context->builder->fmul($sixtyninthF17, $nF17);
                        $sixtyninthF17 = $context->builder->fmul($sixtyninthF17, $nF17);
                        $sixtyninthF17 = $context->builder->fmul($sixtyninthF17, $nF17);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyninthF17
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok17Block);
            $fiftyseventhVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftysixthLong,
                $n
            );
            $ov18 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyseventhVar->longArithOverflowFlag);
            if (null === $ov18 || null === $fiftyseventhVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **69 expected smul overflow metadata (fiftyseventh)');
            }
            $fiftyseventhLong = JITVariable::KIND_VARIABLE === $fiftyseventhVar->kind
                ? $context->builder->load($fiftyseventhVar->value)
                : $fiftyseventhVar->value;
            $ov18Block = BasicBlockHelper::append($context, 'pow_sixtyninth_fiftyseventh_ov');
            $ok18Block = BasicBlockHelper::append($context, 'pow_sixtyninth_fiftyseventh_ok');
            $context->builder->branchIf($ov18, $ov18Block, $ok18Block);

            $context->builder->positionAtEnd($ov18Block);
            $fiftyseventhF18 = $context->builder->load($fiftyseventhVar->longArithOverflowDoubleSlot);
            $nF18 = $context->builder->siToFp($n, $f64);
            $sixtyninthF18 = $context->builder->fmul($fiftyseventhF18, $nF18);
            $sixtyninthF18 = $context->builder->fmul($sixtyninthF18, $nF18);
            $sixtyninthF18 = $context->builder->fmul($sixtyninthF18, $nF18);
                $sixtyninthF18 = $context->builder->fmul($sixtyninthF18, $nF18);
                    $sixtyninthF18 = $context->builder->fmul($sixtyninthF18, $nF18);
                        $sixtyninthF18 = $context->builder->fmul($sixtyninthF18, $nF18);
                        $sixtyninthF18 = $context->builder->fmul($sixtyninthF18, $nF18);
                        $sixtyninthF18 = $context->builder->fmul($sixtyninthF18, $nF18);
                        $sixtyninthF18 = $context->builder->fmul($sixtyninthF18, $nF18);
                        $sixtyninthF18 = $context->builder->fmul($sixtyninthF18, $nF18);
                        $sixtyninthF18 = $context->builder->fmul($sixtyninthF18, $nF18);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyninthF18
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok18Block);
            $fiftyeighthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyseventhLong,
                $n
            );
            $ov19 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyeighthVar->longArithOverflowFlag);
            if (null === $ov19 || null === $fiftyeighthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **69 expected smul overflow metadata (fiftyeighth)');
            }
            $fiftyeighthLong = JITVariable::KIND_VARIABLE === $fiftyeighthVar->kind
                ? $context->builder->load($fiftyeighthVar->value)
                : $fiftyeighthVar->value;
            $ov19Block = BasicBlockHelper::append($context, 'pow_sixtyninth_fiftyeighth_ov');
            $ok19Block = BasicBlockHelper::append($context, 'pow_sixtyninth_fiftyeighth_ok');
            $context->builder->branchIf($ov19, $ov19Block, $ok19Block);

            $context->builder->positionAtEnd($ov19Block);
            $fiftyeighthF19 = $context->builder->load($fiftyeighthVar->longArithOverflowDoubleSlot);
            $nF19 = $context->builder->siToFp($n, $f64);
            $sixtyninthF19 = $context->builder->fmul($fiftyeighthF19, $nF19);
            $sixtyninthF19 = $context->builder->fmul($sixtyninthF19, $nF19);
                $sixtyninthF19 = $context->builder->fmul($sixtyninthF19, $nF19);
                    $sixtyninthF19 = $context->builder->fmul($sixtyninthF19, $nF19);
                        $sixtyninthF19 = $context->builder->fmul($sixtyninthF19, $nF19);
                        $sixtyninthF19 = $context->builder->fmul($sixtyninthF19, $nF19);
                        $sixtyninthF19 = $context->builder->fmul($sixtyninthF19, $nF19);
                        $sixtyninthF19 = $context->builder->fmul($sixtyninthF19, $nF19);
                        $sixtyninthF19 = $context->builder->fmul($sixtyninthF19, $nF19);
                        $sixtyninthF19 = $context->builder->fmul($sixtyninthF19, $nF19);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyninthF19
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok19Block);
            $fiftyninthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyeighthLong,
                $n
            );
            $ov20 = JitLongArithOverflow::loadOverflowFlagI1($context, $fiftyninthVar->longArithOverflowFlag);
            if (null === $ov20 || null === $fiftyninthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **69 expected smul overflow metadata (fiftyninth)');
            }
            $fiftyninthLong = JITVariable::KIND_VARIABLE === $fiftyninthVar->kind
                ? $context->builder->load($fiftyninthVar->value)
                : $fiftyninthVar->value;
            $ov20Block = BasicBlockHelper::append($context, 'pow_sixtyninth_fiftyninth_ov');
            $ok20Block = BasicBlockHelper::append($context, 'pow_sixtyninth_fiftyninth_ok');
            $context->builder->branchIf($ov20, $ov20Block, $ok20Block);

            $context->builder->positionAtEnd($ov20Block);
            $fiftyninthF20 = $context->builder->load($fiftyninthVar->longArithOverflowDoubleSlot);
            $nF20 = $context->builder->siToFp($n, $f64);
            $sixtyninthF20 = $context->builder->fmul($fiftyninthF20, $nF20);
                $sixtyninthF20 = $context->builder->fmul($sixtyninthF20, $nF20);
                    $sixtyninthF20 = $context->builder->fmul($sixtyninthF20, $nF20);
                        $sixtyninthF20 = $context->builder->fmul($sixtyninthF20, $nF20);
                        $sixtyninthF20 = $context->builder->fmul($sixtyninthF20, $nF20);
                        $sixtyninthF20 = $context->builder->fmul($sixtyninthF20, $nF20);
                        $sixtyninthF20 = $context->builder->fmul($sixtyninthF20, $nF20);
                        $sixtyninthF20 = $context->builder->fmul($sixtyninthF20, $nF20);
                        $sixtyninthF20 = $context->builder->fmul($sixtyninthF20, $nF20);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyninthF20
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok20Block);
            $sixtiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $fiftyninthLong,
                $n
            );
            $ov21 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixtiethVar->longArithOverflowFlag);
            if (null === $ov21 || null === $sixtiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **69 expected smul overflow metadata (sixtieth)');
            }
            $sixtiethLong = JITVariable::KIND_VARIABLE === $sixtiethVar->kind
                ? $context->builder->load($sixtiethVar->value)
                : $sixtiethVar->value;
            $ov21Block = BasicBlockHelper::append($context, 'pow_sixtyninth_sixtieth_ov');
            $ok21Block = BasicBlockHelper::append($context, 'pow_sixtyninth_sixtieth_ok');
            $context->builder->branchIf($ov21, $ov21Block, $ok21Block);

            $context->builder->positionAtEnd($ov21Block);
            $sixtiethF21 = $context->builder->load($sixtiethVar->longArithOverflowDoubleSlot);
            $nF21 = $context->builder->siToFp($n, $f64);
            $sixtyninthF21 = $context->builder->fmul($sixtiethF21, $nF21);
                $sixtyninthF21 = $context->builder->fmul($sixtyninthF21, $nF21);
                    $sixtyninthF21 = $context->builder->fmul($sixtyninthF21, $nF21);
                    $sixtyninthF21 = $context->builder->fmul($sixtyninthF21, $nF21);
                    $sixtyninthF21 = $context->builder->fmul($sixtyninthF21, $nF21);
                    $sixtyninthF21 = $context->builder->fmul($sixtyninthF21, $nF21);
                    $sixtyninthF21 = $context->builder->fmul($sixtyninthF21, $nF21);
                    $sixtyninthF21 = $context->builder->fmul($sixtyninthF21, $nF21);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyninthF21
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok21Block);
            $sixtyfirstVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixtiethLong,
                $n
            );
            $ov22 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixtyfirstVar->longArithOverflowFlag);
            if (null === $ov22 || null === $sixtyfirstVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **69 expected smul overflow metadata (sixtyfirst)');
            }
            $sixtyfirstLong = JITVariable::KIND_VARIABLE === $sixtyfirstVar->kind
                ? $context->builder->load($sixtyfirstVar->value)
                : $sixtyfirstVar->value;
            $ov22Block = BasicBlockHelper::append($context, 'pow_sixtyninth_sixtyfirst_ov');
            $ok22Block = BasicBlockHelper::append($context, 'pow_sixtyninth_sixtyfirst_ok');
            $context->builder->branchIf($ov22, $ov22Block, $ok22Block);

            $context->builder->positionAtEnd($ov22Block);
            $sixtyfirstF22 = $context->builder->load($sixtyfirstVar->longArithOverflowDoubleSlot);
            $nF22 = $context->builder->siToFp($n, $f64);
            $sixtyninthF22 = $context->builder->fmul($sixtyfirstF22, $nF22);
                $sixtyninthF22 = $context->builder->fmul($sixtyninthF22, $nF22);
                $sixtyninthF22 = $context->builder->fmul($sixtyninthF22, $nF22);
                $sixtyninthF22 = $context->builder->fmul($sixtyninthF22, $nF22);
                $sixtyninthF22 = $context->builder->fmul($sixtyninthF22, $nF22);
                $sixtyninthF22 = $context->builder->fmul($sixtyninthF22, $nF22);
                $sixtyninthF22 = $context->builder->fmul($sixtyninthF22, $nF22);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyninthF22
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok22Block);
            $sixtysecondVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixtyfirstLong,
                $n
            );
            $ov23 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixtysecondVar->longArithOverflowFlag);
            if (null === $ov23 || null === $sixtysecondVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **69 expected smul overflow metadata (sixtysecond)');
            }
            $sixtysecondLong = JITVariable::KIND_VARIABLE === $sixtysecondVar->kind
                ? $context->builder->load($sixtysecondVar->value)
                : $sixtysecondVar->value;
            $ov23Block = BasicBlockHelper::append($context, 'pow_sixtyninth_sixtysecond_ov');
            $ok23Block = BasicBlockHelper::append($context, 'pow_sixtyninth_sixtysecond_ok');
            $context->builder->branchIf($ov23, $ov23Block, $ok23Block);

            $context->builder->positionAtEnd($ov23Block);
            $sixtysecondF23 = $context->builder->load($sixtysecondVar->longArithOverflowDoubleSlot);
            $nF23 = $context->builder->siToFp($n, $f64);
            $sixtyninthF23 = $context->builder->fmul($sixtysecondF23, $nF23);
            $sixtyninthF23 = $context->builder->fmul($sixtyninthF23, $nF23);
            $sixtyninthF23 = $context->builder->fmul($sixtyninthF23, $nF23);
            $sixtyninthF23 = $context->builder->fmul($sixtyninthF23, $nF23);
            $sixtyninthF23 = $context->builder->fmul($sixtyninthF23, $nF23);
            $sixtyninthF23 = $context->builder->fmul($sixtyninthF23, $nF23);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyninthF23
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok23Block);
            $sixtythirdVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixtysecondLong,
                $n
            );
            $ov24 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixtythirdVar->longArithOverflowFlag);
            if (null === $ov24 || null === $sixtythirdVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **69 expected smul overflow metadata (sixtythird)');
            }
            $sixtythirdLong = JITVariable::KIND_VARIABLE === $sixtythirdVar->kind
                ? $context->builder->load($sixtythirdVar->value)
                : $sixtythirdVar->value;
            $ov24Block = BasicBlockHelper::append($context, 'pow_sixtyninth_sixtythird_ov');
            $ok24Block = BasicBlockHelper::append($context, 'pow_sixtyninth_sixtythird_ok');
            $context->builder->branchIf($ov24, $ov24Block, $ok24Block);

            $context->builder->positionAtEnd($ov24Block);
            $sixtythirdF24 = $context->builder->load($sixtythirdVar->longArithOverflowDoubleSlot);
            $nF24 = $context->builder->siToFp($n, $f64);
            $sixtyninthF24 = $context->builder->fmul($sixtythirdF24, $nF24);
            $sixtyninthF24 = $context->builder->fmul($sixtyninthF24, $nF24);
            $sixtyninthF24 = $context->builder->fmul($sixtyninthF24, $nF24);
            $sixtyninthF24 = $context->builder->fmul($sixtyninthF24, $nF24);
            $sixtyninthF24 = $context->builder->fmul($sixtyninthF24, $nF24);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyninthF24
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok24Block);
            $sixtyfourthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixtythirdLong,
                $n
            );
            $ov25 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixtyfourthVar->longArithOverflowFlag);
            if (null === $ov25 || null === $sixtyfourthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **69 expected smul overflow metadata (sixtyfourth)');
            }
            $sixtyfourthLong = JITVariable::KIND_VARIABLE === $sixtyfourthVar->kind
                ? $context->builder->load($sixtyfourthVar->value)
                : $sixtyfourthVar->value;
            $ov25Block = BasicBlockHelper::append($context, 'pow_sixtyninth_sixtyfourth_ov');
            $ok25Block = BasicBlockHelper::append($context, 'pow_sixtyninth_sixtyfourth_ok');
            $context->builder->branchIf($ov25, $ov25Block, $ok25Block);

            $context->builder->positionAtEnd($ov25Block);
            $sixtyfourthF25 = $context->builder->load($sixtyfourthVar->longArithOverflowDoubleSlot);
            $nF25 = $context->builder->siToFp($n, $f64);
            $sixtyninthF25 = $context->builder->fmul($sixtyfourthF25, $nF25);
            $sixtyninthF25 = $context->builder->fmul($sixtyninthF25, $nF25);
            $sixtyninthF25 = $context->builder->fmul($sixtyninthF25, $nF25);
            $sixtyninthF25 = $context->builder->fmul($sixtyninthF25, $nF25);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyninthF25
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok25Block);
            $sixtyfifthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixtyfourthLong,
                $n
            );
            $ov26 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixtyfifthVar->longArithOverflowFlag);
            if (null === $ov26 || null === $sixtyfifthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **69 expected smul overflow metadata (sixtyfifth)');
            }
            $sixtyfifthLong = JITVariable::KIND_VARIABLE === $sixtyfifthVar->kind
                ? $context->builder->load($sixtyfifthVar->value)
                : $sixtyfifthVar->value;
            $ov26Block = BasicBlockHelper::append($context, 'pow_sixtyninth_sixtyfifth_ov');
            $ok26Block = BasicBlockHelper::append($context, 'pow_sixtyninth_sixtyfifth_ok');
            $context->builder->branchIf($ov26, $ov26Block, $ok26Block);

            $context->builder->positionAtEnd($ov26Block);
            $sixtyfifthF26 = $context->builder->load($sixtyfifthVar->longArithOverflowDoubleSlot);
            $nF26 = $context->builder->siToFp($n, $f64);
            $sixtyninthF26 = $context->builder->fmul($sixtyfifthF26, $nF26);
            $sixtyninthF26 = $context->builder->fmul($sixtyninthF26, $nF26);
            $sixtyninthF26 = $context->builder->fmul($sixtyninthF26, $nF26);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyninthF26
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok26Block);
            $sixtysixthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixtyfifthLong,
                $n
            );
            $ov27 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixtysixthVar->longArithOverflowFlag);
            if (null === $ov27 || null === $sixtysixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **69 expected smul overflow metadata (sixtysixth)');
            }
            $sixtysixthLong = JITVariable::KIND_VARIABLE === $sixtysixthVar->kind
                ? $context->builder->load($sixtysixthVar->value)
                : $sixtysixthVar->value;
            $ov27Block = BasicBlockHelper::append($context, 'pow_sixtyninth_sixtysixth_ov');
            $ok27Block = BasicBlockHelper::append($context, 'pow_sixtyninth_sixtysixth_ok');
            $context->builder->branchIf($ov27, $ov27Block, $ok27Block);

            $context->builder->positionAtEnd($ov27Block);
            $sixtysixthF27 = $context->builder->load($sixtysixthVar->longArithOverflowDoubleSlot);
            $nF27 = $context->builder->siToFp($n, $f64);
            $sixtyninthF27 = $context->builder->fmul($sixtysixthF27, $nF27);
            $sixtyninthF27 = $context->builder->fmul($sixtyninthF27, $nF27);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyninthF27
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok27Block);
            $sixtyseventhVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixtysixthLong,
                $n
            );
            $ov28 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixtyseventhVar->longArithOverflowFlag);
            if (null === $ov28 || null === $sixtyseventhVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **69 expected smul overflow metadata (sixtyseventh)');
            }
            $sixtyseventhLong = JITVariable::KIND_VARIABLE === $sixtyseventhVar->kind
                ? $context->builder->load($sixtyseventhVar->value)
                : $sixtyseventhVar->value;
            $ov28Block = BasicBlockHelper::append($context, 'pow_sixtyninth_sixtyseventh_ov');
            $ok28Block = BasicBlockHelper::append($context, 'pow_sixtyninth_sixtyseventh_ok');
            $context->builder->branchIf($ov28, $ov28Block, $ok28Block);

            $context->builder->positionAtEnd($ov28Block);
            $sixtyseventhF28 = $context->builder->load($sixtyseventhVar->longArithOverflowDoubleSlot);
            $nF28 = $context->builder->siToFp($n, $f64);
            $sixtyninthF28 = $context->builder->fmul($sixtyseventhF28, $nF28);
            $sixtyninthF28 = $context->builder->fmul($sixtyninthF28, $nF28);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyninthF28
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok28Block);
            $sixtyeighthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixtyseventhLong,
                $n
            );
            $ov29 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixtyeighthVar->longArithOverflowFlag);
            if (null === $ov29 || null === $sixtyeighthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **69 expected smul overflow metadata (sixtyeighth)');
            }
            $sixtyeighthLong = JITVariable::KIND_VARIABLE === $sixtyeighthVar->kind
                ? $context->builder->load($sixtyeighthVar->value)
                : $sixtyeighthVar->value;
            $ov29Block = BasicBlockHelper::append($context, 'pow_sixtyninth_sixtyeighth_ov');
            $ok29Block = BasicBlockHelper::append($context, 'pow_sixtyninth_sixtyeighth_ok');
            $context->builder->branchIf($ov29, $ov29Block, $ok29Block);

            $context->builder->positionAtEnd($ov29Block);
            $sixtyeighthF29 = $context->builder->load($sixtyeighthVar->longArithOverflowDoubleSlot);
            $nF29 = $context->builder->siToFp($n, $f64);
            $sixtyninthF29 = $context->builder->fmul($sixtyeighthF29, $nF29);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $sixtyninthF29
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok29Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $sixtyeighthLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);


            $context->builder->positionAtEnd($doneBlock);

            return true;
        }

        return false;
    }
}
