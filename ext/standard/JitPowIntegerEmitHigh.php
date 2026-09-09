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
 * High compile-time exponent integer {@code pow}/{@code **} chained-smul
 * emit (ninetysecond … ninetyfourth) (#36387 / #36386).
 *
 * Extracted from {@see JitPowIntegerEmit} so gen-0 spine gets another TU
 * instead of one ~33k-line monolith; 80–91 live in
 * {@see JitPowIntegerEmitExponents80to91}.
 *
 * No new C ABI. php-src: Zend/zend_operators.c {@code pow_function} /
 * {@code zend_pow} / {@code mul_function}; ext/standard/math.c
 * {@code PHP_FUNCTION(pow)}.
 */
final class JitPowIntegerEmitHigh
{
    /**
     * @return bool true when {@code $expFold} was a high exponent and emit ran
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
        static $high = [
            'ninetysecond' => true,
            'ninetythird' => true,
            'ninetyfourth' => true,
        ];
        if (!isset($high[$expFold])) {
            return false;
        }


if ('ninetysecond' === $expFold) {
            // n^91 = ninetieth*n; overflow arms +1 ×nF vs **90.
            // tenth=fifth*fifth, twentieth=tenth*tenth,
            // fortieth=twentieth*twentieth, fortysecond=fortieth*sq, fortyfourth=fortysecond*sq,
            // fortysixth=fortyfourth*sq, then fortysixth*sq*sq*n… (sixtyseventh×n).
            // Overflow arms finish in float with one extra ×nF vs **91.
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
                throw new \LogicException('pow() **92 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_ninetysecond_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_ninetysecond_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_ninetysecond_done');
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
            $ninetysecondFSq = $context->builder->fmul($fortyfourthF, $sqF);
            $ninetysecondFEighth = $context->builder->fmul($ninetysecondFSq, $sqF);
            $ninetysecondF = $context->builder->fmul($ninetysecondFEighth, $sqF);
            $nF = $context->builder->siToFp($n, $f64);
            $ninetysecondF = $context->builder->fmul($ninetysecondF, $nF);
            $ninetysecondF = $context->builder->fmul($ninetysecondF, $nF);
            $ninetysecondF = $context->builder->fmul($ninetysecondF, $nF);
            $ninetysecondF = $context->builder->fmul($ninetysecondF, $nF);
            $ninetysecondF = $context->builder->fmul($ninetysecondF, $nF);
            $ninetysecondF = $context->builder->fmul($ninetysecondF, $nF);
            $ninetysecondF = $context->builder->fmul($ninetysecondF, $nF);
            $ninetysecondF = $context->builder->fmul($ninetysecondF, $nF);
            $ninetysecondF = $context->builder->fmul($ninetysecondF, $nF);
            $ninetysecondF = $context->builder->fmul($ninetysecondF, $nF);
                $ninetysecondF = $context->builder->fmul($ninetysecondF, $nF);
                    $ninetysecondF = $context->builder->fmul($ninetysecondF, $nF);
                        $ninetysecondF = $context->builder->fmul($ninetysecondF, $nF);
                        $ninetysecondF = $context->builder->fmul($ninetysecondF, $nF);
                        $ninetysecondF = $context->builder->fmul($ninetysecondF, $nF);
                        $ninetysecondF = $context->builder->fmul($ninetysecondF, $nF);
                        $ninetysecondF = $context->builder->fmul($ninetysecondF, $nF);
                        $ninetysecondF = $context->builder->fmul($ninetysecondF, $nF);
                        $ninetysecondF = $context->builder->fmul($ninetysecondF, $nF);
            $ninetysecondF = $context->builder->fmul($ninetysecondF, $nF);
            $ninetysecondF = $context->builder->fmul($ninetysecondF, $nF);
            $ninetysecondF = $context->builder->fmul($ninetysecondF, $nF);
            $ninetysecondF = $context->builder->fmul($ninetysecondF, $nF);
            $ninetysecondF = $context->builder->fmul($ninetysecondF, $nF);

            $ninetysecondF = $context->builder->fmul($ninetysecondF, $nF);
            $ninetysecondF = $context->builder->fmul($ninetysecondF, $nF);
            $ninetysecondF = $context->builder->fmul($ninetysecondF, $nF);
            $ninetysecondF = $context->builder->fmul($ninetysecondF, $nF);
            $ninetysecondF = $context->builder->fmul($ninetysecondF, $nF);
            $ninetysecondF = $context->builder->fmul($ninetysecondF, $nF);
            $ninetysecondF = $context->builder->fmul($ninetysecondF, $nF);
            $ninetysecondF = $context->builder->fmul($ninetysecondF, $nF);
            $ninetysecondF = $context->builder->fmul($ninetysecondF, $nF);
            $ninetysecondF = $context->builder->fmul($ninetysecondF, $nF);
            $ninetysecondF = $context->builder->fmul($ninetysecondF, $nF);
            $ninetysecondF = $context->builder->fmul($ninetysecondF, $nF);
            $ninetysecondF = $context->builder->fmul($ninetysecondF, $nF);
            $ninetysecondF = $context->builder->fmul($ninetysecondF, $nF);
            $ninetysecondF = $context->builder->fmul($ninetysecondF, $nF);
            $ninetysecondF = $context->builder->fmul($ninetysecondF, $nF);
            $ninetysecondF = $context->builder->fmul($ninetysecondF, $nF);
            $ninetysecondF = $context->builder->fmul($ninetysecondF, $nF);
            $ninetysecondF = $context->builder->fmul($ninetysecondF, $nF);
            $ninetysecondF = $context->builder->fmul($ninetysecondF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF
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
                throw new \LogicException('pow() **92 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_ninetysecond_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_ninetysecond_cu_ok');
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
            $ninetysecondF2Sq = $context->builder->fmul($fortyfourthF2, $sqF2);
            $ninetysecondF2Eighth = $context->builder->fmul($ninetysecondF2Sq, $sqF2);
            $ninetysecondF2 = $context->builder->fmul($ninetysecondF2Eighth, $sqF2);
            $nF2 = $context->builder->siToFp($n, $f64);
            $ninetysecondF2 = $context->builder->fmul($ninetysecondF2, $nF2);
            $ninetysecondF2 = $context->builder->fmul($ninetysecondF2, $nF2);
            $ninetysecondF2 = $context->builder->fmul($ninetysecondF2, $nF2);
            $ninetysecondF2 = $context->builder->fmul($ninetysecondF2, $nF2);
            $ninetysecondF2 = $context->builder->fmul($ninetysecondF2, $nF2);
            $ninetysecondF2 = $context->builder->fmul($ninetysecondF2, $nF2);
            $ninetysecondF2 = $context->builder->fmul($ninetysecondF2, $nF2);
            $ninetysecondF2 = $context->builder->fmul($ninetysecondF2, $nF2);
            $ninetysecondF2 = $context->builder->fmul($ninetysecondF2, $nF2);
            $ninetysecondF2 = $context->builder->fmul($ninetysecondF2, $nF2);
                $ninetysecondF2 = $context->builder->fmul($ninetysecondF2, $nF2);
                    $ninetysecondF2 = $context->builder->fmul($ninetysecondF2, $nF2);
                        $ninetysecondF2 = $context->builder->fmul($ninetysecondF2, $nF2);
                        $ninetysecondF2 = $context->builder->fmul($ninetysecondF2, $nF2);
                        $ninetysecondF2 = $context->builder->fmul($ninetysecondF2, $nF2);
                        $ninetysecondF2 = $context->builder->fmul($ninetysecondF2, $nF2);
                        $ninetysecondF2 = $context->builder->fmul($ninetysecondF2, $nF2);
                        $ninetysecondF2 = $context->builder->fmul($ninetysecondF2, $nF2);
                        $ninetysecondF2 = $context->builder->fmul($ninetysecondF2, $nF2);
            $ninetysecondF2 = $context->builder->fmul($ninetysecondF2, $nF2);
            $ninetysecondF2 = $context->builder->fmul($ninetysecondF2, $nF2);
            $ninetysecondF2 = $context->builder->fmul($ninetysecondF2, $nF2);
            $ninetysecondF2 = $context->builder->fmul($ninetysecondF2, $nF2);
            $ninetysecondF2 = $context->builder->fmul($ninetysecondF2, $nF2);

            $ninetysecondF2 = $context->builder->fmul($ninetysecondF2, $nF2);
            $ninetysecondF2 = $context->builder->fmul($ninetysecondF2, $nF2);
            $ninetysecondF2 = $context->builder->fmul($ninetysecondF2, $nF2);
            $ninetysecondF2 = $context->builder->fmul($ninetysecondF2, $nF2);
            $ninetysecondF2 = $context->builder->fmul($ninetysecondF2, $nF2);
            $ninetysecondF2 = $context->builder->fmul($ninetysecondF2, $nF2);
            $ninetysecondF2 = $context->builder->fmul($ninetysecondF2, $nF2);
            $ninetysecondF2 = $context->builder->fmul($ninetysecondF2, $nF2);
            $ninetysecondF2 = $context->builder->fmul($ninetysecondF2, $nF2);
            $ninetysecondF2 = $context->builder->fmul($ninetysecondF2, $nF2);
            $ninetysecondF2 = $context->builder->fmul($ninetysecondF2, $nF2);
            $ninetysecondF2 = $context->builder->fmul($ninetysecondF2, $nF2);
            $ninetysecondF2 = $context->builder->fmul($ninetysecondF2, $nF2);
            $ninetysecondF2 = $context->builder->fmul($ninetysecondF2, $nF2);
            $ninetysecondF2 = $context->builder->fmul($ninetysecondF2, $nF2);
            $ninetysecondF2 = $context->builder->fmul($ninetysecondF2, $nF2);
            $ninetysecondF2 = $context->builder->fmul($ninetysecondF2, $nF2);
            $ninetysecondF2 = $context->builder->fmul($ninetysecondF2, $nF2);
            $ninetysecondF2 = $context->builder->fmul($ninetysecondF2, $nF2);
            $ninetysecondF2 = $context->builder->fmul($ninetysecondF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF2
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
                throw new \LogicException('pow() **92 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_ninetysecond_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_ninetysecond_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $twentiethF3 = $context->builder->fmul($tenthF3, $tenthF3);
            $fortiethF3 = $context->builder->fmul($twentiethF3, $twentiethF3);
            $sqF3 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF3 = $context->builder->fmul($fortiethF3, $sqF3);
            $fortyfourthF3 = $context->builder->fmul($fortysecondF3, $sqF3);
            $ninetysecondF3Sq = $context->builder->fmul($fortyfourthF3, $sqF3);
            $ninetysecondF3Eighth = $context->builder->fmul($ninetysecondF3Sq, $sqF3);
            $ninetysecondF3 = $context->builder->fmul($ninetysecondF3Eighth, $sqF3);
            $nF3 = $context->builder->siToFp($n, $f64);
            $ninetysecondF3 = $context->builder->fmul($ninetysecondF3, $nF3);
            $ninetysecondF3 = $context->builder->fmul($ninetysecondF3, $nF3);
            $ninetysecondF3 = $context->builder->fmul($ninetysecondF3, $nF3);
            $ninetysecondF3 = $context->builder->fmul($ninetysecondF3, $nF3);
            $ninetysecondF3 = $context->builder->fmul($ninetysecondF3, $nF3);
            $ninetysecondF3 = $context->builder->fmul($ninetysecondF3, $nF3);
            $ninetysecondF3 = $context->builder->fmul($ninetysecondF3, $nF3);
            $ninetysecondF3 = $context->builder->fmul($ninetysecondF3, $nF3);
            $ninetysecondF3 = $context->builder->fmul($ninetysecondF3, $nF3);
            $ninetysecondF3 = $context->builder->fmul($ninetysecondF3, $nF3);
                $ninetysecondF3 = $context->builder->fmul($ninetysecondF3, $nF3);
                    $ninetysecondF3 = $context->builder->fmul($ninetysecondF3, $nF3);
                        $ninetysecondF3 = $context->builder->fmul($ninetysecondF3, $nF3);
                        $ninetysecondF3 = $context->builder->fmul($ninetysecondF3, $nF3);
                        $ninetysecondF3 = $context->builder->fmul($ninetysecondF3, $nF3);
                        $ninetysecondF3 = $context->builder->fmul($ninetysecondF3, $nF3);
                        $ninetysecondF3 = $context->builder->fmul($ninetysecondF3, $nF3);
                        $ninetysecondF3 = $context->builder->fmul($ninetysecondF3, $nF3);
                        $ninetysecondF3 = $context->builder->fmul($ninetysecondF3, $nF3);
            $ninetysecondF3 = $context->builder->fmul($ninetysecondF3, $nF3);
            $ninetysecondF3 = $context->builder->fmul($ninetysecondF3, $nF3);
            $ninetysecondF3 = $context->builder->fmul($ninetysecondF3, $nF3);
            $ninetysecondF3 = $context->builder->fmul($ninetysecondF3, $nF3);
            $ninetysecondF3 = $context->builder->fmul($ninetysecondF3, $nF3);

            $ninetysecondF3 = $context->builder->fmul($ninetysecondF3, $nF3);
            $ninetysecondF3 = $context->builder->fmul($ninetysecondF3, $nF3);
            $ninetysecondF3 = $context->builder->fmul($ninetysecondF3, $nF3);
            $ninetysecondF3 = $context->builder->fmul($ninetysecondF3, $nF3);
            $ninetysecondF3 = $context->builder->fmul($ninetysecondF3, $nF3);
            $ninetysecondF3 = $context->builder->fmul($ninetysecondF3, $nF3);
            $ninetysecondF3 = $context->builder->fmul($ninetysecondF3, $nF3);
            $ninetysecondF3 = $context->builder->fmul($ninetysecondF3, $nF3);
            $ninetysecondF3 = $context->builder->fmul($ninetysecondF3, $nF3);
            $ninetysecondF3 = $context->builder->fmul($ninetysecondF3, $nF3);
            $ninetysecondF3 = $context->builder->fmul($ninetysecondF3, $nF3);
            $ninetysecondF3 = $context->builder->fmul($ninetysecondF3, $nF3);
            $ninetysecondF3 = $context->builder->fmul($ninetysecondF3, $nF3);
            $ninetysecondF3 = $context->builder->fmul($ninetysecondF3, $nF3);
            $ninetysecondF3 = $context->builder->fmul($ninetysecondF3, $nF3);
            $ninetysecondF3 = $context->builder->fmul($ninetysecondF3, $nF3);
            $ninetysecondF3 = $context->builder->fmul($ninetysecondF3, $nF3);
            $ninetysecondF3 = $context->builder->fmul($ninetysecondF3, $nF3);
            $ninetysecondF3 = $context->builder->fmul($ninetysecondF3, $nF3);
            $ninetysecondF3 = $context->builder->fmul($ninetysecondF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF3
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
                throw new \LogicException('pow() **92 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_ninetysecond_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_ninetysecond_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF4 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $twentiethF4 = $context->builder->fmul($tenthF4, $tenthF4);
            $fortiethF4 = $context->builder->fmul($twentiethF4, $twentiethF4);
            $sqF4 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF4 = $context->builder->fmul($fortiethF4, $sqF4);
            $fortyfourthF4 = $context->builder->fmul($fortysecondF4, $sqF4);
            $ninetysecondF4Sq = $context->builder->fmul($fortyfourthF4, $sqF4);
            $ninetysecondF4Eighth = $context->builder->fmul($ninetysecondF4Sq, $sqF4);
            $ninetysecondF4 = $context->builder->fmul($ninetysecondF4Eighth, $sqF4);
            $nF4 = $context->builder->siToFp($n, $f64);
            $ninetysecondF4 = $context->builder->fmul($ninetysecondF4, $nF4);
            $ninetysecondF4 = $context->builder->fmul($ninetysecondF4, $nF4);
            $ninetysecondF4 = $context->builder->fmul($ninetysecondF4, $nF4);
            $ninetysecondF4 = $context->builder->fmul($ninetysecondF4, $nF4);
            $ninetysecondF4 = $context->builder->fmul($ninetysecondF4, $nF4);
            $ninetysecondF4 = $context->builder->fmul($ninetysecondF4, $nF4);
            $ninetysecondF4 = $context->builder->fmul($ninetysecondF4, $nF4);
            $ninetysecondF4 = $context->builder->fmul($ninetysecondF4, $nF4);
            $ninetysecondF4 = $context->builder->fmul($ninetysecondF4, $nF4);
            $ninetysecondF4 = $context->builder->fmul($ninetysecondF4, $nF4);
                $ninetysecondF4 = $context->builder->fmul($ninetysecondF4, $nF4);
                    $ninetysecondF4 = $context->builder->fmul($ninetysecondF4, $nF4);
                        $ninetysecondF4 = $context->builder->fmul($ninetysecondF4, $nF4);
                        $ninetysecondF4 = $context->builder->fmul($ninetysecondF4, $nF4);
                        $ninetysecondF4 = $context->builder->fmul($ninetysecondF4, $nF4);
                        $ninetysecondF4 = $context->builder->fmul($ninetysecondF4, $nF4);
                        $ninetysecondF4 = $context->builder->fmul($ninetysecondF4, $nF4);
                        $ninetysecondF4 = $context->builder->fmul($ninetysecondF4, $nF4);
                        $ninetysecondF4 = $context->builder->fmul($ninetysecondF4, $nF4);
            $ninetysecondF4 = $context->builder->fmul($ninetysecondF4, $nF4);
            $ninetysecondF4 = $context->builder->fmul($ninetysecondF4, $nF4);
            $ninetysecondF4 = $context->builder->fmul($ninetysecondF4, $nF4);
            $ninetysecondF4 = $context->builder->fmul($ninetysecondF4, $nF4);
            $ninetysecondF4 = $context->builder->fmul($ninetysecondF4, $nF4);

            $ninetysecondF4 = $context->builder->fmul($ninetysecondF4, $nF4);
            $ninetysecondF4 = $context->builder->fmul($ninetysecondF4, $nF4);
            $ninetysecondF4 = $context->builder->fmul($ninetysecondF4, $nF4);
            $ninetysecondF4 = $context->builder->fmul($ninetysecondF4, $nF4);
            $ninetysecondF4 = $context->builder->fmul($ninetysecondF4, $nF4);
            $ninetysecondF4 = $context->builder->fmul($ninetysecondF4, $nF4);
            $ninetysecondF4 = $context->builder->fmul($ninetysecondF4, $nF4);
            $ninetysecondF4 = $context->builder->fmul($ninetysecondF4, $nF4);
            $ninetysecondF4 = $context->builder->fmul($ninetysecondF4, $nF4);
            $ninetysecondF4 = $context->builder->fmul($ninetysecondF4, $nF4);
            $ninetysecondF4 = $context->builder->fmul($ninetysecondF4, $nF4);
            $ninetysecondF4 = $context->builder->fmul($ninetysecondF4, $nF4);
            $ninetysecondF4 = $context->builder->fmul($ninetysecondF4, $nF4);
            $ninetysecondF4 = $context->builder->fmul($ninetysecondF4, $nF4);
            $ninetysecondF4 = $context->builder->fmul($ninetysecondF4, $nF4);
            $ninetysecondF4 = $context->builder->fmul($ninetysecondF4, $nF4);
            $ninetysecondF4 = $context->builder->fmul($ninetysecondF4, $nF4);
            $ninetysecondF4 = $context->builder->fmul($ninetysecondF4, $nF4);
            $ninetysecondF4 = $context->builder->fmul($ninetysecondF4, $nF4);
            $ninetysecondF4 = $context->builder->fmul($ninetysecondF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF4
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
                throw new \LogicException('pow() **92 expected smul overflow metadata (twentieth)');
            }
            $twentiethLong = JITVariable::KIND_VARIABLE === $twentiethVar->kind
                ? $context->builder->load($twentiethVar->value)
                : $twentiethVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_ninetysecond_twentieth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_ninetysecond_twentieth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $twentiethF5 = $context->builder->load($twentiethVar->longArithOverflowDoubleSlot);
            $fortiethF5 = $context->builder->fmul($twentiethF5, $twentiethF5);
            $sqF5 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF5 = $context->builder->fmul($fortiethF5, $sqF5);
            $fortyfourthF5 = $context->builder->fmul($fortysecondF5, $sqF5);
            $ninetysecondF5Sq = $context->builder->fmul($fortyfourthF5, $sqF5);
            $ninetysecondF5Eighth = $context->builder->fmul($ninetysecondF5Sq, $sqF5);
            $ninetysecondF5 = $context->builder->fmul($ninetysecondF5Eighth, $sqF5);
            $nF5 = $context->builder->siToFp($n, $f64);
            $ninetysecondF5 = $context->builder->fmul($ninetysecondF5, $nF5);
            $ninetysecondF5 = $context->builder->fmul($ninetysecondF5, $nF5);
            $ninetysecondF5 = $context->builder->fmul($ninetysecondF5, $nF5);
            $ninetysecondF5 = $context->builder->fmul($ninetysecondF5, $nF5);
            $ninetysecondF5 = $context->builder->fmul($ninetysecondF5, $nF5);
            $ninetysecondF5 = $context->builder->fmul($ninetysecondF5, $nF5);
            $ninetysecondF5 = $context->builder->fmul($ninetysecondF5, $nF5);
            $ninetysecondF5 = $context->builder->fmul($ninetysecondF5, $nF5);
            $ninetysecondF5 = $context->builder->fmul($ninetysecondF5, $nF5);
            $ninetysecondF5 = $context->builder->fmul($ninetysecondF5, $nF5);
                $ninetysecondF5 = $context->builder->fmul($ninetysecondF5, $nF5);
                    $ninetysecondF5 = $context->builder->fmul($ninetysecondF5, $nF5);
                        $ninetysecondF5 = $context->builder->fmul($ninetysecondF5, $nF5);
                        $ninetysecondF5 = $context->builder->fmul($ninetysecondF5, $nF5);
                        $ninetysecondF5 = $context->builder->fmul($ninetysecondF5, $nF5);
                        $ninetysecondF5 = $context->builder->fmul($ninetysecondF5, $nF5);
                        $ninetysecondF5 = $context->builder->fmul($ninetysecondF5, $nF5);
                        $ninetysecondF5 = $context->builder->fmul($ninetysecondF5, $nF5);
                        $ninetysecondF5 = $context->builder->fmul($ninetysecondF5, $nF5);
            $ninetysecondF5 = $context->builder->fmul($ninetysecondF5, $nF5);
            $ninetysecondF5 = $context->builder->fmul($ninetysecondF5, $nF5);
            $ninetysecondF5 = $context->builder->fmul($ninetysecondF5, $nF5);
            $ninetysecondF5 = $context->builder->fmul($ninetysecondF5, $nF5);
            $ninetysecondF5 = $context->builder->fmul($ninetysecondF5, $nF5);

            $ninetysecondF5 = $context->builder->fmul($ninetysecondF5, $nF5);
            $ninetysecondF5 = $context->builder->fmul($ninetysecondF5, $nF5);
            $ninetysecondF5 = $context->builder->fmul($ninetysecondF5, $nF5);
            $ninetysecondF5 = $context->builder->fmul($ninetysecondF5, $nF5);
            $ninetysecondF5 = $context->builder->fmul($ninetysecondF5, $nF5);
            $ninetysecondF5 = $context->builder->fmul($ninetysecondF5, $nF5);
            $ninetysecondF5 = $context->builder->fmul($ninetysecondF5, $nF5);
            $ninetysecondF5 = $context->builder->fmul($ninetysecondF5, $nF5);
            $ninetysecondF5 = $context->builder->fmul($ninetysecondF5, $nF5);
            $ninetysecondF5 = $context->builder->fmul($ninetysecondF5, $nF5);
            $ninetysecondF5 = $context->builder->fmul($ninetysecondF5, $nF5);
            $ninetysecondF5 = $context->builder->fmul($ninetysecondF5, $nF5);
            $ninetysecondF5 = $context->builder->fmul($ninetysecondF5, $nF5);
            $ninetysecondF5 = $context->builder->fmul($ninetysecondF5, $nF5);
            $ninetysecondF5 = $context->builder->fmul($ninetysecondF5, $nF5);
            $ninetysecondF5 = $context->builder->fmul($ninetysecondF5, $nF5);
            $ninetysecondF5 = $context->builder->fmul($ninetysecondF5, $nF5);
            $ninetysecondF5 = $context->builder->fmul($ninetysecondF5, $nF5);
            $ninetysecondF5 = $context->builder->fmul($ninetysecondF5, $nF5);
            $ninetysecondF5 = $context->builder->fmul($ninetysecondF5, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF5
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
                throw new \LogicException('pow() **92 expected smul overflow metadata (fortieth)');
            }
            $fortiethLong = JITVariable::KIND_VARIABLE === $fortiethVar->kind
                ? $context->builder->load($fortiethVar->value)
                : $fortiethVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_ninetysecond_fortieth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_ninetysecond_fortieth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $fortiethF6 = $context->builder->load($fortiethVar->longArithOverflowDoubleSlot);
            $sqF6 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF6 = $context->builder->fmul($fortiethF6, $sqF6);
            $fortyfourthF6 = $context->builder->fmul($fortysecondF6, $sqF6);
            $ninetysecondF6Sq = $context->builder->fmul($fortyfourthF6, $sqF6);
            $ninetysecondF6Eighth = $context->builder->fmul($ninetysecondF6Sq, $sqF6);
            $ninetysecondF6 = $context->builder->fmul($ninetysecondF6Eighth, $sqF6);
            $nF6 = $context->builder->siToFp($n, $f64);
            $ninetysecondF6 = $context->builder->fmul($ninetysecondF6, $nF6);
            $ninetysecondF6 = $context->builder->fmul($ninetysecondF6, $nF6);
            $ninetysecondF6 = $context->builder->fmul($ninetysecondF6, $nF6);
            $ninetysecondF6 = $context->builder->fmul($ninetysecondF6, $nF6);
            $ninetysecondF6 = $context->builder->fmul($ninetysecondF6, $nF6);
            $ninetysecondF6 = $context->builder->fmul($ninetysecondF6, $nF6);
            $ninetysecondF6 = $context->builder->fmul($ninetysecondF6, $nF6);
            $ninetysecondF6 = $context->builder->fmul($ninetysecondF6, $nF6);
            $ninetysecondF6 = $context->builder->fmul($ninetysecondF6, $nF6);
            $ninetysecondF6 = $context->builder->fmul($ninetysecondF6, $nF6);
                $ninetysecondF6 = $context->builder->fmul($ninetysecondF6, $nF6);
                    $ninetysecondF6 = $context->builder->fmul($ninetysecondF6, $nF6);
                        $ninetysecondF6 = $context->builder->fmul($ninetysecondF6, $nF6);
                        $ninetysecondF6 = $context->builder->fmul($ninetysecondF6, $nF6);
                        $ninetysecondF6 = $context->builder->fmul($ninetysecondF6, $nF6);
                        $ninetysecondF6 = $context->builder->fmul($ninetysecondF6, $nF6);
                        $ninetysecondF6 = $context->builder->fmul($ninetysecondF6, $nF6);
                        $ninetysecondF6 = $context->builder->fmul($ninetysecondF6, $nF6);
                        $ninetysecondF6 = $context->builder->fmul($ninetysecondF6, $nF6);
            $ninetysecondF6 = $context->builder->fmul($ninetysecondF6, $nF6);
            $ninetysecondF6 = $context->builder->fmul($ninetysecondF6, $nF6);
            $ninetysecondF6 = $context->builder->fmul($ninetysecondF6, $nF6);
            $ninetysecondF6 = $context->builder->fmul($ninetysecondF6, $nF6);
            $ninetysecondF6 = $context->builder->fmul($ninetysecondF6, $nF6);

            $ninetysecondF6 = $context->builder->fmul($ninetysecondF6, $nF6);
            $ninetysecondF6 = $context->builder->fmul($ninetysecondF6, $nF6);
            $ninetysecondF6 = $context->builder->fmul($ninetysecondF6, $nF6);
            $ninetysecondF6 = $context->builder->fmul($ninetysecondF6, $nF6);
            $ninetysecondF6 = $context->builder->fmul($ninetysecondF6, $nF6);
            $ninetysecondF6 = $context->builder->fmul($ninetysecondF6, $nF6);
            $ninetysecondF6 = $context->builder->fmul($ninetysecondF6, $nF6);
            $ninetysecondF6 = $context->builder->fmul($ninetysecondF6, $nF6);
            $ninetysecondF6 = $context->builder->fmul($ninetysecondF6, $nF6);
            $ninetysecondF6 = $context->builder->fmul($ninetysecondF6, $nF6);
            $ninetysecondF6 = $context->builder->fmul($ninetysecondF6, $nF6);
            $ninetysecondF6 = $context->builder->fmul($ninetysecondF6, $nF6);
            $ninetysecondF6 = $context->builder->fmul($ninetysecondF6, $nF6);
            $ninetysecondF6 = $context->builder->fmul($ninetysecondF6, $nF6);
            $ninetysecondF6 = $context->builder->fmul($ninetysecondF6, $nF6);
            $ninetysecondF6 = $context->builder->fmul($ninetysecondF6, $nF6);
            $ninetysecondF6 = $context->builder->fmul($ninetysecondF6, $nF6);
            $ninetysecondF6 = $context->builder->fmul($ninetysecondF6, $nF6);
            $ninetysecondF6 = $context->builder->fmul($ninetysecondF6, $nF6);
            $ninetysecondF6 = $context->builder->fmul($ninetysecondF6, $nF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF6
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
                throw new \LogicException('pow() **92 expected smul overflow metadata (fortysecond)');
            }
            $fortysecondLong = JITVariable::KIND_VARIABLE === $fortysecondVar->kind
                ? $context->builder->load($fortysecondVar->value)
                : $fortysecondVar->value;
            $ov7Block = BasicBlockHelper::append($context, 'pow_ninetysecond_fortysecond_ov');
            $ok7Block = BasicBlockHelper::append($context, 'pow_ninetysecond_fortysecond_ok');
            $context->builder->branchIf($ov7, $ov7Block, $ok7Block);

            $context->builder->positionAtEnd($ov7Block);
            $fortysecondF7 = $context->builder->load($fortysecondVar->longArithOverflowDoubleSlot);
            $sqF7 = $context->builder->siToFp($sqLong, $f64);
            $fortyfourthF7 = $context->builder->fmul($fortysecondF7, $sqF7);
            $ninetysecondF7Sq = $context->builder->fmul($fortyfourthF7, $sqF7);
            $ninetysecondF7Eighth = $context->builder->fmul($ninetysecondF7Sq, $sqF7);
            $ninetysecondF7 = $context->builder->fmul($ninetysecondF7Eighth, $sqF7);
            $nF7 = $context->builder->siToFp($n, $f64);
            $ninetysecondF7 = $context->builder->fmul($ninetysecondF7, $nF7);
            $ninetysecondF7 = $context->builder->fmul($ninetysecondF7, $nF7);
            $ninetysecondF7 = $context->builder->fmul($ninetysecondF7, $nF7);
            $ninetysecondF7 = $context->builder->fmul($ninetysecondF7, $nF7);
            $ninetysecondF7 = $context->builder->fmul($ninetysecondF7, $nF7);
            $ninetysecondF7 = $context->builder->fmul($ninetysecondF7, $nF7);
            $ninetysecondF7 = $context->builder->fmul($ninetysecondF7, $nF7);
            $ninetysecondF7 = $context->builder->fmul($ninetysecondF7, $nF7);
            $ninetysecondF7 = $context->builder->fmul($ninetysecondF7, $nF7);
            $ninetysecondF7 = $context->builder->fmul($ninetysecondF7, $nF7);
                $ninetysecondF7 = $context->builder->fmul($ninetysecondF7, $nF7);
                    $ninetysecondF7 = $context->builder->fmul($ninetysecondF7, $nF7);
                        $ninetysecondF7 = $context->builder->fmul($ninetysecondF7, $nF7);
                        $ninetysecondF7 = $context->builder->fmul($ninetysecondF7, $nF7);
                        $ninetysecondF7 = $context->builder->fmul($ninetysecondF7, $nF7);
                        $ninetysecondF7 = $context->builder->fmul($ninetysecondF7, $nF7);
                        $ninetysecondF7 = $context->builder->fmul($ninetysecondF7, $nF7);
                        $ninetysecondF7 = $context->builder->fmul($ninetysecondF7, $nF7);
                        $ninetysecondF7 = $context->builder->fmul($ninetysecondF7, $nF7);
            $ninetysecondF7 = $context->builder->fmul($ninetysecondF7, $nF7);
            $ninetysecondF7 = $context->builder->fmul($ninetysecondF7, $nF7);
            $ninetysecondF7 = $context->builder->fmul($ninetysecondF7, $nF7);
            $ninetysecondF7 = $context->builder->fmul($ninetysecondF7, $nF7);
            $ninetysecondF7 = $context->builder->fmul($ninetysecondF7, $nF7);

            $ninetysecondF7 = $context->builder->fmul($ninetysecondF7, $nF7);
            $ninetysecondF7 = $context->builder->fmul($ninetysecondF7, $nF7);
            $ninetysecondF7 = $context->builder->fmul($ninetysecondF7, $nF7);
            $ninetysecondF7 = $context->builder->fmul($ninetysecondF7, $nF7);
            $ninetysecondF7 = $context->builder->fmul($ninetysecondF7, $nF7);
            $ninetysecondF7 = $context->builder->fmul($ninetysecondF7, $nF7);
            $ninetysecondF7 = $context->builder->fmul($ninetysecondF7, $nF7);
            $ninetysecondF7 = $context->builder->fmul($ninetysecondF7, $nF7);
            $ninetysecondF7 = $context->builder->fmul($ninetysecondF7, $nF7);
            $ninetysecondF7 = $context->builder->fmul($ninetysecondF7, $nF7);
            $ninetysecondF7 = $context->builder->fmul($ninetysecondF7, $nF7);
            $ninetysecondF7 = $context->builder->fmul($ninetysecondF7, $nF7);
            $ninetysecondF7 = $context->builder->fmul($ninetysecondF7, $nF7);
            $ninetysecondF7 = $context->builder->fmul($ninetysecondF7, $nF7);
            $ninetysecondF7 = $context->builder->fmul($ninetysecondF7, $nF7);
            $ninetysecondF7 = $context->builder->fmul($ninetysecondF7, $nF7);
            $ninetysecondF7 = $context->builder->fmul($ninetysecondF7, $nF7);
            $ninetysecondF7 = $context->builder->fmul($ninetysecondF7, $nF7);
            $ninetysecondF7 = $context->builder->fmul($ninetysecondF7, $nF7);
            $ninetysecondF7 = $context->builder->fmul($ninetysecondF7, $nF7);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF7
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
                throw new \LogicException('pow() **92 expected smul overflow metadata (fortyfourth)');
            }
            $fortyfourthLong = JITVariable::KIND_VARIABLE === $fortyfourthVar->kind
                ? $context->builder->load($fortyfourthVar->value)
                : $fortyfourthVar->value;
            $ov8Block = BasicBlockHelper::append($context, 'pow_ninetysecond_fortyfourth_ov');
            $ok8Block = BasicBlockHelper::append($context, 'pow_ninetysecond_fortyfourth_ok');
            $context->builder->branchIf($ov8, $ov8Block, $ok8Block);

            $context->builder->positionAtEnd($ov8Block);
            $fortyfourthF8 = $context->builder->load($fortyfourthVar->longArithOverflowDoubleSlot);
            $sqF8 = $context->builder->siToFp($sqLong, $f64);
            $ninetysecondF8Sq = $context->builder->fmul($fortyfourthF8, $sqF8);
            $ninetysecondF8Eighth = $context->builder->fmul($ninetysecondF8Sq, $sqF8);
            $ninetysecondF8 = $context->builder->fmul($ninetysecondF8Eighth, $sqF8);
            $nF8 = $context->builder->siToFp($n, $f64);
            $ninetysecondF8 = $context->builder->fmul($ninetysecondF8, $nF8);
            $ninetysecondF8 = $context->builder->fmul($ninetysecondF8, $nF8);
            $ninetysecondF8 = $context->builder->fmul($ninetysecondF8, $nF8);
            $ninetysecondF8 = $context->builder->fmul($ninetysecondF8, $nF8);
            $ninetysecondF8 = $context->builder->fmul($ninetysecondF8, $nF8);
            $ninetysecondF8 = $context->builder->fmul($ninetysecondF8, $nF8);
            $ninetysecondF8 = $context->builder->fmul($ninetysecondF8, $nF8);
            $ninetysecondF8 = $context->builder->fmul($ninetysecondF8, $nF8);
            $ninetysecondF8 = $context->builder->fmul($ninetysecondF8, $nF8);
            $ninetysecondF8 = $context->builder->fmul($ninetysecondF8, $nF8);
                $ninetysecondF8 = $context->builder->fmul($ninetysecondF8, $nF8);
                    $ninetysecondF8 = $context->builder->fmul($ninetysecondF8, $nF8);
                        $ninetysecondF8 = $context->builder->fmul($ninetysecondF8, $nF8);
                        $ninetysecondF8 = $context->builder->fmul($ninetysecondF8, $nF8);
                        $ninetysecondF8 = $context->builder->fmul($ninetysecondF8, $nF8);
                        $ninetysecondF8 = $context->builder->fmul($ninetysecondF8, $nF8);
                        $ninetysecondF8 = $context->builder->fmul($ninetysecondF8, $nF8);
                        $ninetysecondF8 = $context->builder->fmul($ninetysecondF8, $nF8);
                        $ninetysecondF8 = $context->builder->fmul($ninetysecondF8, $nF8);
            $ninetysecondF8 = $context->builder->fmul($ninetysecondF8, $nF8);
            $ninetysecondF8 = $context->builder->fmul($ninetysecondF8, $nF8);
            $ninetysecondF8 = $context->builder->fmul($ninetysecondF8, $nF8);
            $ninetysecondF8 = $context->builder->fmul($ninetysecondF8, $nF8);
            $ninetysecondF8 = $context->builder->fmul($ninetysecondF8, $nF8);

            $ninetysecondF8 = $context->builder->fmul($ninetysecondF8, $nF8);
            $ninetysecondF8 = $context->builder->fmul($ninetysecondF8, $nF8);
            $ninetysecondF8 = $context->builder->fmul($ninetysecondF8, $nF8);
            $ninetysecondF8 = $context->builder->fmul($ninetysecondF8, $nF8);
            $ninetysecondF8 = $context->builder->fmul($ninetysecondF8, $nF8);
            $ninetysecondF8 = $context->builder->fmul($ninetysecondF8, $nF8);
            $ninetysecondF8 = $context->builder->fmul($ninetysecondF8, $nF8);
            $ninetysecondF8 = $context->builder->fmul($ninetysecondF8, $nF8);
            $ninetysecondF8 = $context->builder->fmul($ninetysecondF8, $nF8);
            $ninetysecondF8 = $context->builder->fmul($ninetysecondF8, $nF8);
            $ninetysecondF8 = $context->builder->fmul($ninetysecondF8, $nF8);
            $ninetysecondF8 = $context->builder->fmul($ninetysecondF8, $nF8);
            $ninetysecondF8 = $context->builder->fmul($ninetysecondF8, $nF8);
            $ninetysecondF8 = $context->builder->fmul($ninetysecondF8, $nF8);
            $ninetysecondF8 = $context->builder->fmul($ninetysecondF8, $nF8);
            $ninetysecondF8 = $context->builder->fmul($ninetysecondF8, $nF8);
            $ninetysecondF8 = $context->builder->fmul($ninetysecondF8, $nF8);
            $ninetysecondF8 = $context->builder->fmul($ninetysecondF8, $nF8);
            $ninetysecondF8 = $context->builder->fmul($ninetysecondF8, $nF8);
            $ninetysecondF8 = $context->builder->fmul($ninetysecondF8, $nF8);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF8
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
                throw new \LogicException('pow() **92 expected smul overflow metadata (fortysixth)');
            }
            $fortysixthLong = JITVariable::KIND_VARIABLE === $fortysixthVar->kind
                ? $context->builder->load($fortysixthVar->value)
                : $fortysixthVar->value;
            $ov9Block = BasicBlockHelper::append($context, 'pow_ninetysecond_fortysixth_ov');
            $ok9Block = BasicBlockHelper::append($context, 'pow_ninetysecond_fortysixth_ok');
            $context->builder->branchIf($ov9, $ov9Block, $ok9Block);

            $context->builder->positionAtEnd($ov9Block);
            $fortysixthF9 = $context->builder->load($fortysixthVar->longArithOverflowDoubleSlot);
            $sqF9 = $context->builder->siToFp($sqLong, $f64);
            $fortyeighthF9 = $context->builder->fmul($fortysixthF9, $sqF9);
            $ninetysecondF9 = $context->builder->fmul($fortyeighthF9, $sqF9);
            $nF9 = $context->builder->siToFp($n, $f64);
            $ninetysecondF9 = $context->builder->fmul($ninetysecondF9, $nF9);
            $ninetysecondF9 = $context->builder->fmul($ninetysecondF9, $nF9);
            $ninetysecondF9 = $context->builder->fmul($ninetysecondF9, $nF9);
            $ninetysecondF9 = $context->builder->fmul($ninetysecondF9, $nF9);
            $ninetysecondF9 = $context->builder->fmul($ninetysecondF9, $nF9);
            $ninetysecondF9 = $context->builder->fmul($ninetysecondF9, $nF9);
            $ninetysecondF9 = $context->builder->fmul($ninetysecondF9, $nF9);
            $ninetysecondF9 = $context->builder->fmul($ninetysecondF9, $nF9);
            $ninetysecondF9 = $context->builder->fmul($ninetysecondF9, $nF9);
            $ninetysecondF9 = $context->builder->fmul($ninetysecondF9, $nF9);
                $ninetysecondF9 = $context->builder->fmul($ninetysecondF9, $nF9);
                    $ninetysecondF9 = $context->builder->fmul($ninetysecondF9, $nF9);
                        $ninetysecondF9 = $context->builder->fmul($ninetysecondF9, $nF9);
                        $ninetysecondF9 = $context->builder->fmul($ninetysecondF9, $nF9);
                        $ninetysecondF9 = $context->builder->fmul($ninetysecondF9, $nF9);
                        $ninetysecondF9 = $context->builder->fmul($ninetysecondF9, $nF9);
                        $ninetysecondF9 = $context->builder->fmul($ninetysecondF9, $nF9);
                        $ninetysecondF9 = $context->builder->fmul($ninetysecondF9, $nF9);
                        $ninetysecondF9 = $context->builder->fmul($ninetysecondF9, $nF9);
            $ninetysecondF9 = $context->builder->fmul($ninetysecondF9, $nF9);
            $ninetysecondF9 = $context->builder->fmul($ninetysecondF9, $nF9);
            $ninetysecondF9 = $context->builder->fmul($ninetysecondF9, $nF9);
            $ninetysecondF9 = $context->builder->fmul($ninetysecondF9, $nF9);
            $ninetysecondF9 = $context->builder->fmul($ninetysecondF9, $nF9);

            $ninetysecondF9 = $context->builder->fmul($ninetysecondF9, $nF9);
            $ninetysecondF9 = $context->builder->fmul($ninetysecondF9, $nF9);
            $ninetysecondF9 = $context->builder->fmul($ninetysecondF9, $nF9);
            $ninetysecondF9 = $context->builder->fmul($ninetysecondF9, $nF9);
            $ninetysecondF9 = $context->builder->fmul($ninetysecondF9, $nF9);
            $ninetysecondF9 = $context->builder->fmul($ninetysecondF9, $nF9);
            $ninetysecondF9 = $context->builder->fmul($ninetysecondF9, $nF9);
            $ninetysecondF9 = $context->builder->fmul($ninetysecondF9, $nF9);
            $ninetysecondF9 = $context->builder->fmul($ninetysecondF9, $nF9);
            $ninetysecondF9 = $context->builder->fmul($ninetysecondF9, $nF9);
            $ninetysecondF9 = $context->builder->fmul($ninetysecondF9, $nF9);
            $ninetysecondF9 = $context->builder->fmul($ninetysecondF9, $nF9);
            $ninetysecondF9 = $context->builder->fmul($ninetysecondF9, $nF9);
            $ninetysecondF9 = $context->builder->fmul($ninetysecondF9, $nF9);
            $ninetysecondF9 = $context->builder->fmul($ninetysecondF9, $nF9);
            $ninetysecondF9 = $context->builder->fmul($ninetysecondF9, $nF9);
            $ninetysecondF9 = $context->builder->fmul($ninetysecondF9, $nF9);
            $ninetysecondF9 = $context->builder->fmul($ninetysecondF9, $nF9);
            $ninetysecondF9 = $context->builder->fmul($ninetysecondF9, $nF9);
            $ninetysecondF9 = $context->builder->fmul($ninetysecondF9, $nF9);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF9
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
                throw new \LogicException('pow() **92 expected smul overflow metadata (fortyeighth)');
            }
            $fortyeighthLong = JITVariable::KIND_VARIABLE === $fortyeighthVar->kind
                ? $context->builder->load($fortyeighthVar->value)
                : $fortyeighthVar->value;
            $ov10Block = BasicBlockHelper::append($context, 'pow_ninetysecond_fortyeighth_ov');
            $ok10Block = BasicBlockHelper::append($context, 'pow_ninetysecond_fortyeighth_ok');
            $context->builder->branchIf($ov10, $ov10Block, $ok10Block);

            $context->builder->positionAtEnd($ov10Block);
            $fortyeighthF10 = $context->builder->load($fortyeighthVar->longArithOverflowDoubleSlot);
            $sqF10 = $context->builder->siToFp($sqLong, $f64);
            $ninetysecondF10 = $context->builder->fmul($fortyeighthF10, $sqF10);
            $nF10 = $context->builder->siToFp($n, $f64);
            $ninetysecondF10 = $context->builder->fmul($ninetysecondF10, $nF10);
            $ninetysecondF10 = $context->builder->fmul($ninetysecondF10, $nF10);
            $ninetysecondF10 = $context->builder->fmul($ninetysecondF10, $nF10);
            $ninetysecondF10 = $context->builder->fmul($ninetysecondF10, $nF10);
            $ninetysecondF10 = $context->builder->fmul($ninetysecondF10, $nF10);
            $ninetysecondF10 = $context->builder->fmul($ninetysecondF10, $nF10);
            $ninetysecondF10 = $context->builder->fmul($ninetysecondF10, $nF10);
            $ninetysecondF10 = $context->builder->fmul($ninetysecondF10, $nF10);
            $ninetysecondF10 = $context->builder->fmul($ninetysecondF10, $nF10);
            $ninetysecondF10 = $context->builder->fmul($ninetysecondF10, $nF10);
                $ninetysecondF10 = $context->builder->fmul($ninetysecondF10, $nF10);
                    $ninetysecondF10 = $context->builder->fmul($ninetysecondF10, $nF10);
                        $ninetysecondF10 = $context->builder->fmul($ninetysecondF10, $nF10);
                        $ninetysecondF10 = $context->builder->fmul($ninetysecondF10, $nF10);
                        $ninetysecondF10 = $context->builder->fmul($ninetysecondF10, $nF10);
                        $ninetysecondF10 = $context->builder->fmul($ninetysecondF10, $nF10);
                        $ninetysecondF10 = $context->builder->fmul($ninetysecondF10, $nF10);
                        $ninetysecondF10 = $context->builder->fmul($ninetysecondF10, $nF10);
                        $ninetysecondF10 = $context->builder->fmul($ninetysecondF10, $nF10);
            $ninetysecondF10 = $context->builder->fmul($ninetysecondF10, $nF10);
            $ninetysecondF10 = $context->builder->fmul($ninetysecondF10, $nF10);
            $ninetysecondF10 = $context->builder->fmul($ninetysecondF10, $nF10);
            $ninetysecondF10 = $context->builder->fmul($ninetysecondF10, $nF10);
            $ninetysecondF10 = $context->builder->fmul($ninetysecondF10, $nF10);

            $ninetysecondF10 = $context->builder->fmul($ninetysecondF10, $nF10);
            $ninetysecondF10 = $context->builder->fmul($ninetysecondF10, $nF10);
            $ninetysecondF10 = $context->builder->fmul($ninetysecondF10, $nF10);
            $ninetysecondF10 = $context->builder->fmul($ninetysecondF10, $nF10);
            $ninetysecondF10 = $context->builder->fmul($ninetysecondF10, $nF10);
            $ninetysecondF10 = $context->builder->fmul($ninetysecondF10, $nF10);
            $ninetysecondF10 = $context->builder->fmul($ninetysecondF10, $nF10);
            $ninetysecondF10 = $context->builder->fmul($ninetysecondF10, $nF10);
            $ninetysecondF10 = $context->builder->fmul($ninetysecondF10, $nF10);
            $ninetysecondF10 = $context->builder->fmul($ninetysecondF10, $nF10);
            $ninetysecondF10 = $context->builder->fmul($ninetysecondF10, $nF10);
            $ninetysecondF10 = $context->builder->fmul($ninetysecondF10, $nF10);
            $ninetysecondF10 = $context->builder->fmul($ninetysecondF10, $nF10);
            $ninetysecondF10 = $context->builder->fmul($ninetysecondF10, $nF10);
            $ninetysecondF10 = $context->builder->fmul($ninetysecondF10, $nF10);
            $ninetysecondF10 = $context->builder->fmul($ninetysecondF10, $nF10);
            $ninetysecondF10 = $context->builder->fmul($ninetysecondF10, $nF10);
            $ninetysecondF10 = $context->builder->fmul($ninetysecondF10, $nF10);
            $ninetysecondF10 = $context->builder->fmul($ninetysecondF10, $nF10);
            $ninetysecondF10 = $context->builder->fmul($ninetysecondF10, $nF10);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF10
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
                throw new \LogicException('pow() **92 expected smul overflow metadata (fiftieth)');
            }
            $fiftiethLong = JITVariable::KIND_VARIABLE === $fiftiethVar->kind
                ? $context->builder->load($fiftiethVar->value)
                : $fiftiethVar->value;
            $ov11Block = BasicBlockHelper::append($context, 'pow_ninetysecond_fiftieth_ov');
            $ok11Block = BasicBlockHelper::append($context, 'pow_ninetysecond_fiftieth_ok');
            $context->builder->branchIf($ov11, $ov11Block, $ok11Block);

            $context->builder->positionAtEnd($ov11Block);
            $fiftiethF11 = $context->builder->load($fiftiethVar->longArithOverflowDoubleSlot);
            $nF11 = $context->builder->siToFp($n, $f64);
            $ninetysecondF11 = $context->builder->fmul($fiftiethF11, $nF11);
            $ninetysecondF11 = $context->builder->fmul($ninetysecondF11, $nF11);
            $ninetysecondF11 = $context->builder->fmul($ninetysecondF11, $nF11);
            $ninetysecondF11 = $context->builder->fmul($ninetysecondF11, $nF11);
            $ninetysecondF11 = $context->builder->fmul($ninetysecondF11, $nF11);
            $ninetysecondF11 = $context->builder->fmul($ninetysecondF11, $nF11);
            $ninetysecondF11 = $context->builder->fmul($ninetysecondF11, $nF11);
            $ninetysecondF11 = $context->builder->fmul($ninetysecondF11, $nF11);
            $ninetysecondF11 = $context->builder->fmul($ninetysecondF11, $nF11);
            $ninetysecondF11 = $context->builder->fmul($ninetysecondF11, $nF11);
                $ninetysecondF11 = $context->builder->fmul($ninetysecondF11, $nF11);
                    $ninetysecondF11 = $context->builder->fmul($ninetysecondF11, $nF11);
                        $ninetysecondF11 = $context->builder->fmul($ninetysecondF11, $nF11);
                        $ninetysecondF11 = $context->builder->fmul($ninetysecondF11, $nF11);
                        $ninetysecondF11 = $context->builder->fmul($ninetysecondF11, $nF11);
                        $ninetysecondF11 = $context->builder->fmul($ninetysecondF11, $nF11);
                        $ninetysecondF11 = $context->builder->fmul($ninetysecondF11, $nF11);
                        $ninetysecondF11 = $context->builder->fmul($ninetysecondF11, $nF11);
                        $ninetysecondF11 = $context->builder->fmul($ninetysecondF11, $nF11);
            $ninetysecondF11 = $context->builder->fmul($ninetysecondF11, $nF11);
            $ninetysecondF11 = $context->builder->fmul($ninetysecondF11, $nF11);
            $ninetysecondF11 = $context->builder->fmul($ninetysecondF11, $nF11);
            $ninetysecondF11 = $context->builder->fmul($ninetysecondF11, $nF11);
            $ninetysecondF11 = $context->builder->fmul($ninetysecondF11, $nF11);

            $ninetysecondF11 = $context->builder->fmul($ninetysecondF11, $nF11);
            $ninetysecondF11 = $context->builder->fmul($ninetysecondF11, $nF11);
            $ninetysecondF11 = $context->builder->fmul($ninetysecondF11, $nF11);
            $ninetysecondF11 = $context->builder->fmul($ninetysecondF11, $nF11);
            $ninetysecondF11 = $context->builder->fmul($ninetysecondF11, $nF11);
            $ninetysecondF11 = $context->builder->fmul($ninetysecondF11, $nF11);
            $ninetysecondF11 = $context->builder->fmul($ninetysecondF11, $nF11);
            $ninetysecondF11 = $context->builder->fmul($ninetysecondF11, $nF11);
            $ninetysecondF11 = $context->builder->fmul($ninetysecondF11, $nF11);
            $ninetysecondF11 = $context->builder->fmul($ninetysecondF11, $nF11);
            $ninetysecondF11 = $context->builder->fmul($ninetysecondF11, $nF11);
            $ninetysecondF11 = $context->builder->fmul($ninetysecondF11, $nF11);
            $ninetysecondF11 = $context->builder->fmul($ninetysecondF11, $nF11);
            $ninetysecondF11 = $context->builder->fmul($ninetysecondF11, $nF11);
            $ninetysecondF11 = $context->builder->fmul($ninetysecondF11, $nF11);
            $ninetysecondF11 = $context->builder->fmul($ninetysecondF11, $nF11);
            $ninetysecondF11 = $context->builder->fmul($ninetysecondF11, $nF11);
            $ninetysecondF11 = $context->builder->fmul($ninetysecondF11, $nF11);
            $ninetysecondF11 = $context->builder->fmul($ninetysecondF11, $nF11);
            $ninetysecondF11 = $context->builder->fmul($ninetysecondF11, $nF11);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF11
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
                throw new \LogicException('pow() **92 expected smul overflow metadata (fiftyfirst)');
            }
            $fiftyfirstLong = JITVariable::KIND_VARIABLE === $fiftyfirstVar->kind
                ? $context->builder->load($fiftyfirstVar->value)
                : $fiftyfirstVar->value;
            $ov12Block = BasicBlockHelper::append($context, 'pow_ninetysecond_fiftyfirst_ov');
            $ok12Block = BasicBlockHelper::append($context, 'pow_ninetysecond_fiftyfirst_ok');
            $context->builder->branchIf($ov12, $ov12Block, $ok12Block);

            $context->builder->positionAtEnd($ov12Block);
            $fiftyfirstF12 = $context->builder->load($fiftyfirstVar->longArithOverflowDoubleSlot);
            $nF12 = $context->builder->siToFp($n, $f64);
            $ninetysecondF12 = $context->builder->fmul($fiftyfirstF12, $nF12);
            $ninetysecondF12 = $context->builder->fmul($ninetysecondF12, $nF12);
            $ninetysecondF12 = $context->builder->fmul($ninetysecondF12, $nF12);
            $ninetysecondF12 = $context->builder->fmul($ninetysecondF12, $nF12);
            $ninetysecondF12 = $context->builder->fmul($ninetysecondF12, $nF12);
            $ninetysecondF12 = $context->builder->fmul($ninetysecondF12, $nF12);
            $ninetysecondF12 = $context->builder->fmul($ninetysecondF12, $nF12);
            $ninetysecondF12 = $context->builder->fmul($ninetysecondF12, $nF12);
            $ninetysecondF12 = $context->builder->fmul($ninetysecondF12, $nF12);
                $ninetysecondF12 = $context->builder->fmul($ninetysecondF12, $nF12);
                    $ninetysecondF12 = $context->builder->fmul($ninetysecondF12, $nF12);
                        $ninetysecondF12 = $context->builder->fmul($ninetysecondF12, $nF12);
                        $ninetysecondF12 = $context->builder->fmul($ninetysecondF12, $nF12);
                        $ninetysecondF12 = $context->builder->fmul($ninetysecondF12, $nF12);
                        $ninetysecondF12 = $context->builder->fmul($ninetysecondF12, $nF12);
                        $ninetysecondF12 = $context->builder->fmul($ninetysecondF12, $nF12);
                        $ninetysecondF12 = $context->builder->fmul($ninetysecondF12, $nF12);
                        $ninetysecondF12 = $context->builder->fmul($ninetysecondF12, $nF12);
            $ninetysecondF12 = $context->builder->fmul($ninetysecondF12, $nF12);
            $ninetysecondF12 = $context->builder->fmul($ninetysecondF12, $nF12);
            $ninetysecondF12 = $context->builder->fmul($ninetysecondF12, $nF12);
            $ninetysecondF12 = $context->builder->fmul($ninetysecondF12, $nF12);
            $ninetysecondF12 = $context->builder->fmul($ninetysecondF12, $nF12);

            $ninetysecondF12 = $context->builder->fmul($ninetysecondF12, $nF12);
            $ninetysecondF12 = $context->builder->fmul($ninetysecondF12, $nF12);
            $ninetysecondF12 = $context->builder->fmul($ninetysecondF12, $nF12);
            $ninetysecondF12 = $context->builder->fmul($ninetysecondF12, $nF12);
            $ninetysecondF12 = $context->builder->fmul($ninetysecondF12, $nF12);
            $ninetysecondF12 = $context->builder->fmul($ninetysecondF12, $nF12);
            $ninetysecondF12 = $context->builder->fmul($ninetysecondF12, $nF12);
            $ninetysecondF12 = $context->builder->fmul($ninetysecondF12, $nF12);
            $ninetysecondF12 = $context->builder->fmul($ninetysecondF12, $nF12);
            $ninetysecondF12 = $context->builder->fmul($ninetysecondF12, $nF12);
            $ninetysecondF12 = $context->builder->fmul($ninetysecondF12, $nF12);
            $ninetysecondF12 = $context->builder->fmul($ninetysecondF12, $nF12);
            $ninetysecondF12 = $context->builder->fmul($ninetysecondF12, $nF12);
            $ninetysecondF12 = $context->builder->fmul($ninetysecondF12, $nF12);
            $ninetysecondF12 = $context->builder->fmul($ninetysecondF12, $nF12);
            $ninetysecondF12 = $context->builder->fmul($ninetysecondF12, $nF12);
            $ninetysecondF12 = $context->builder->fmul($ninetysecondF12, $nF12);
            $ninetysecondF12 = $context->builder->fmul($ninetysecondF12, $nF12);
            $ninetysecondF12 = $context->builder->fmul($ninetysecondF12, $nF12);
            $ninetysecondF12 = $context->builder->fmul($ninetysecondF12, $nF12);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF12
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
                throw new \LogicException('pow() **92 expected smul overflow metadata (fiftysecond)');
            }
            $fiftysecondLong = JITVariable::KIND_VARIABLE === $fiftysecondVar->kind
                ? $context->builder->load($fiftysecondVar->value)
                : $fiftysecondVar->value;
            $ov13Block = BasicBlockHelper::append($context, 'pow_ninetysecond_fiftysecond_ov');
            $ok13Block = BasicBlockHelper::append($context, 'pow_ninetysecond_fiftysecond_ok');
            $context->builder->branchIf($ov13, $ov13Block, $ok13Block);

            $context->builder->positionAtEnd($ov13Block);
            $fiftysecondF13 = $context->builder->load($fiftysecondVar->longArithOverflowDoubleSlot);
            $nF13 = $context->builder->siToFp($n, $f64);
            $ninetysecondF13 = $context->builder->fmul($fiftysecondF13, $nF13);
            $ninetysecondF13 = $context->builder->fmul($ninetysecondF13, $nF13);
            $ninetysecondF13 = $context->builder->fmul($ninetysecondF13, $nF13);
            $ninetysecondF13 = $context->builder->fmul($ninetysecondF13, $nF13);
            $ninetysecondF13 = $context->builder->fmul($ninetysecondF13, $nF13);
            $ninetysecondF13 = $context->builder->fmul($ninetysecondF13, $nF13);
            $ninetysecondF13 = $context->builder->fmul($ninetysecondF13, $nF13);
            $ninetysecondF13 = $context->builder->fmul($ninetysecondF13, $nF13);
                $ninetysecondF13 = $context->builder->fmul($ninetysecondF13, $nF13);
                    $ninetysecondF13 = $context->builder->fmul($ninetysecondF13, $nF13);
                        $ninetysecondF13 = $context->builder->fmul($ninetysecondF13, $nF13);
                        $ninetysecondF13 = $context->builder->fmul($ninetysecondF13, $nF13);
                        $ninetysecondF13 = $context->builder->fmul($ninetysecondF13, $nF13);
                        $ninetysecondF13 = $context->builder->fmul($ninetysecondF13, $nF13);
                        $ninetysecondF13 = $context->builder->fmul($ninetysecondF13, $nF13);
                        $ninetysecondF13 = $context->builder->fmul($ninetysecondF13, $nF13);
                        $ninetysecondF13 = $context->builder->fmul($ninetysecondF13, $nF13);
            $ninetysecondF13 = $context->builder->fmul($ninetysecondF13, $nF13);
            $ninetysecondF13 = $context->builder->fmul($ninetysecondF13, $nF13);
            $ninetysecondF13 = $context->builder->fmul($ninetysecondF13, $nF13);
            $ninetysecondF13 = $context->builder->fmul($ninetysecondF13, $nF13);
            $ninetysecondF13 = $context->builder->fmul($ninetysecondF13, $nF13);

            $ninetysecondF13 = $context->builder->fmul($ninetysecondF13, $nF13);
            $ninetysecondF13 = $context->builder->fmul($ninetysecondF13, $nF13);
            $ninetysecondF13 = $context->builder->fmul($ninetysecondF13, $nF13);
            $ninetysecondF13 = $context->builder->fmul($ninetysecondF13, $nF13);
            $ninetysecondF13 = $context->builder->fmul($ninetysecondF13, $nF13);
            $ninetysecondF13 = $context->builder->fmul($ninetysecondF13, $nF13);
            $ninetysecondF13 = $context->builder->fmul($ninetysecondF13, $nF13);
            $ninetysecondF13 = $context->builder->fmul($ninetysecondF13, $nF13);
            $ninetysecondF13 = $context->builder->fmul($ninetysecondF13, $nF13);
            $ninetysecondF13 = $context->builder->fmul($ninetysecondF13, $nF13);
            $ninetysecondF13 = $context->builder->fmul($ninetysecondF13, $nF13);
            $ninetysecondF13 = $context->builder->fmul($ninetysecondF13, $nF13);
            $ninetysecondF13 = $context->builder->fmul($ninetysecondF13, $nF13);
            $ninetysecondF13 = $context->builder->fmul($ninetysecondF13, $nF13);
            $ninetysecondF13 = $context->builder->fmul($ninetysecondF13, $nF13);
            $ninetysecondF13 = $context->builder->fmul($ninetysecondF13, $nF13);
            $ninetysecondF13 = $context->builder->fmul($ninetysecondF13, $nF13);
            $ninetysecondF13 = $context->builder->fmul($ninetysecondF13, $nF13);
            $ninetysecondF13 = $context->builder->fmul($ninetysecondF13, $nF13);
            $ninetysecondF13 = $context->builder->fmul($ninetysecondF13, $nF13);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF13
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
                throw new \LogicException('pow() **92 expected smul overflow metadata (fiftythird)');
            }
            $fiftythirdLong = JITVariable::KIND_VARIABLE === $fiftythirdVar->kind
                ? $context->builder->load($fiftythirdVar->value)
                : $fiftythirdVar->value;
            $ov14Block = BasicBlockHelper::append($context, 'pow_ninetysecond_fiftythird_ov');
            $ok14Block = BasicBlockHelper::append($context, 'pow_ninetysecond_fiftythird_ok');
            $context->builder->branchIf($ov14, $ov14Block, $ok14Block);

            $context->builder->positionAtEnd($ov14Block);
            $fiftythirdF14 = $context->builder->load($fiftythirdVar->longArithOverflowDoubleSlot);
            $nF14 = $context->builder->siToFp($n, $f64);
            $ninetysecondF14 = $context->builder->fmul($fiftythirdF14, $nF14);
            $ninetysecondF14 = $context->builder->fmul($ninetysecondF14, $nF14);
            $ninetysecondF14 = $context->builder->fmul($ninetysecondF14, $nF14);
            $ninetysecondF14 = $context->builder->fmul($ninetysecondF14, $nF14);
            $ninetysecondF14 = $context->builder->fmul($ninetysecondF14, $nF14);
            $ninetysecondF14 = $context->builder->fmul($ninetysecondF14, $nF14);
            $ninetysecondF14 = $context->builder->fmul($ninetysecondF14, $nF14);
                $ninetysecondF14 = $context->builder->fmul($ninetysecondF14, $nF14);
                    $ninetysecondF14 = $context->builder->fmul($ninetysecondF14, $nF14);
                        $ninetysecondF14 = $context->builder->fmul($ninetysecondF14, $nF14);
                        $ninetysecondF14 = $context->builder->fmul($ninetysecondF14, $nF14);
                        $ninetysecondF14 = $context->builder->fmul($ninetysecondF14, $nF14);
                        $ninetysecondF14 = $context->builder->fmul($ninetysecondF14, $nF14);
                        $ninetysecondF14 = $context->builder->fmul($ninetysecondF14, $nF14);
                        $ninetysecondF14 = $context->builder->fmul($ninetysecondF14, $nF14);
                        $ninetysecondF14 = $context->builder->fmul($ninetysecondF14, $nF14);
            $ninetysecondF14 = $context->builder->fmul($ninetysecondF14, $nF14);
            $ninetysecondF14 = $context->builder->fmul($ninetysecondF14, $nF14);
            $ninetysecondF14 = $context->builder->fmul($ninetysecondF14, $nF14);
            $ninetysecondF14 = $context->builder->fmul($ninetysecondF14, $nF14);
            $ninetysecondF14 = $context->builder->fmul($ninetysecondF14, $nF14);

            $ninetysecondF14 = $context->builder->fmul($ninetysecondF14, $nF14);
            $ninetysecondF14 = $context->builder->fmul($ninetysecondF14, $nF14);
            $ninetysecondF14 = $context->builder->fmul($ninetysecondF14, $nF14);
            $ninetysecondF14 = $context->builder->fmul($ninetysecondF14, $nF14);
            $ninetysecondF14 = $context->builder->fmul($ninetysecondF14, $nF14);
            $ninetysecondF14 = $context->builder->fmul($ninetysecondF14, $nF14);
            $ninetysecondF14 = $context->builder->fmul($ninetysecondF14, $nF14);
            $ninetysecondF14 = $context->builder->fmul($ninetysecondF14, $nF14);
            $ninetysecondF14 = $context->builder->fmul($ninetysecondF14, $nF14);
            $ninetysecondF14 = $context->builder->fmul($ninetysecondF14, $nF14);
            $ninetysecondF14 = $context->builder->fmul($ninetysecondF14, $nF14);
            $ninetysecondF14 = $context->builder->fmul($ninetysecondF14, $nF14);
            $ninetysecondF14 = $context->builder->fmul($ninetysecondF14, $nF14);
            $ninetysecondF14 = $context->builder->fmul($ninetysecondF14, $nF14);
            $ninetysecondF14 = $context->builder->fmul($ninetysecondF14, $nF14);
            $ninetysecondF14 = $context->builder->fmul($ninetysecondF14, $nF14);
            $ninetysecondF14 = $context->builder->fmul($ninetysecondF14, $nF14);
            $ninetysecondF14 = $context->builder->fmul($ninetysecondF14, $nF14);
            $ninetysecondF14 = $context->builder->fmul($ninetysecondF14, $nF14);
            $ninetysecondF14 = $context->builder->fmul($ninetysecondF14, $nF14);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF14
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
                throw new \LogicException('pow() **92 expected smul overflow metadata (fiftyfourth)');
            }
            $fiftyfourthLong = JITVariable::KIND_VARIABLE === $fiftyfourthVar->kind
                ? $context->builder->load($fiftyfourthVar->value)
                : $fiftyfourthVar->value;
            $ov15Block = BasicBlockHelper::append($context, 'pow_ninetysecond_fiftyfourth_ov');
            $ok15Block = BasicBlockHelper::append($context, 'pow_ninetysecond_fiftyfourth_ok');
            $context->builder->branchIf($ov15, $ov15Block, $ok15Block);

            $context->builder->positionAtEnd($ov15Block);
            $fiftyfourthF15 = $context->builder->load($fiftyfourthVar->longArithOverflowDoubleSlot);
            $nF15 = $context->builder->siToFp($n, $f64);
            $ninetysecondF15 = $context->builder->fmul($fiftyfourthF15, $nF15);
            $ninetysecondF15 = $context->builder->fmul($ninetysecondF15, $nF15);
            $ninetysecondF15 = $context->builder->fmul($ninetysecondF15, $nF15);
            $ninetysecondF15 = $context->builder->fmul($ninetysecondF15, $nF15);
            $ninetysecondF15 = $context->builder->fmul($ninetysecondF15, $nF15);
            $ninetysecondF15 = $context->builder->fmul($ninetysecondF15, $nF15);
                $ninetysecondF15 = $context->builder->fmul($ninetysecondF15, $nF15);
                    $ninetysecondF15 = $context->builder->fmul($ninetysecondF15, $nF15);
                        $ninetysecondF15 = $context->builder->fmul($ninetysecondF15, $nF15);
                        $ninetysecondF15 = $context->builder->fmul($ninetysecondF15, $nF15);
                        $ninetysecondF15 = $context->builder->fmul($ninetysecondF15, $nF15);
                        $ninetysecondF15 = $context->builder->fmul($ninetysecondF15, $nF15);
                        $ninetysecondF15 = $context->builder->fmul($ninetysecondF15, $nF15);
                        $ninetysecondF15 = $context->builder->fmul($ninetysecondF15, $nF15);
                        $ninetysecondF15 = $context->builder->fmul($ninetysecondF15, $nF15);
            $ninetysecondF15 = $context->builder->fmul($ninetysecondF15, $nF15);
            $ninetysecondF15 = $context->builder->fmul($ninetysecondF15, $nF15);
            $ninetysecondF15 = $context->builder->fmul($ninetysecondF15, $nF15);
            $ninetysecondF15 = $context->builder->fmul($ninetysecondF15, $nF15);
            $ninetysecondF15 = $context->builder->fmul($ninetysecondF15, $nF15);

            $ninetysecondF15 = $context->builder->fmul($ninetysecondF15, $nF15);
            $ninetysecondF15 = $context->builder->fmul($ninetysecondF15, $nF15);
            $ninetysecondF15 = $context->builder->fmul($ninetysecondF15, $nF15);
            $ninetysecondF15 = $context->builder->fmul($ninetysecondF15, $nF15);
            $ninetysecondF15 = $context->builder->fmul($ninetysecondF15, $nF15);
            $ninetysecondF15 = $context->builder->fmul($ninetysecondF15, $nF15);
            $ninetysecondF15 = $context->builder->fmul($ninetysecondF15, $nF15);
            $ninetysecondF15 = $context->builder->fmul($ninetysecondF15, $nF15);
            $ninetysecondF15 = $context->builder->fmul($ninetysecondF15, $nF15);
            $ninetysecondF15 = $context->builder->fmul($ninetysecondF15, $nF15);
            $ninetysecondF15 = $context->builder->fmul($ninetysecondF15, $nF15);
            $ninetysecondF15 = $context->builder->fmul($ninetysecondF15, $nF15);
            $ninetysecondF15 = $context->builder->fmul($ninetysecondF15, $nF15);
            $ninetysecondF15 = $context->builder->fmul($ninetysecondF15, $nF15);
            $ninetysecondF15 = $context->builder->fmul($ninetysecondF15, $nF15);
            $ninetysecondF15 = $context->builder->fmul($ninetysecondF15, $nF15);
            $ninetysecondF15 = $context->builder->fmul($ninetysecondF15, $nF15);
            $ninetysecondF15 = $context->builder->fmul($ninetysecondF15, $nF15);
            $ninetysecondF15 = $context->builder->fmul($ninetysecondF15, $nF15);
            $ninetysecondF15 = $context->builder->fmul($ninetysecondF15, $nF15);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF15
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
                throw new \LogicException('pow() **92 expected smul overflow metadata (fiftyfifth)');
            }
            $fiftyfifthLong = JITVariable::KIND_VARIABLE === $fiftyfifthVar->kind
                ? $context->builder->load($fiftyfifthVar->value)
                : $fiftyfifthVar->value;
            $ov16Block = BasicBlockHelper::append($context, 'pow_ninetysecond_fiftyfifth_ov');
            $ok16Block = BasicBlockHelper::append($context, 'pow_ninetysecond_fiftyfifth_ok');
            $context->builder->branchIf($ov16, $ov16Block, $ok16Block);

            $context->builder->positionAtEnd($ov16Block);
            $fiftyfifthF16 = $context->builder->load($fiftyfifthVar->longArithOverflowDoubleSlot);
            $nF16 = $context->builder->siToFp($n, $f64);
            $ninetysecondF16 = $context->builder->fmul($fiftyfifthF16, $nF16);
            $ninetysecondF16 = $context->builder->fmul($ninetysecondF16, $nF16);
            $ninetysecondF16 = $context->builder->fmul($ninetysecondF16, $nF16);
            $ninetysecondF16 = $context->builder->fmul($ninetysecondF16, $nF16);
            $ninetysecondF16 = $context->builder->fmul($ninetysecondF16, $nF16);
                $ninetysecondF16 = $context->builder->fmul($ninetysecondF16, $nF16);
                    $ninetysecondF16 = $context->builder->fmul($ninetysecondF16, $nF16);
                        $ninetysecondF16 = $context->builder->fmul($ninetysecondF16, $nF16);
                        $ninetysecondF16 = $context->builder->fmul($ninetysecondF16, $nF16);
                        $ninetysecondF16 = $context->builder->fmul($ninetysecondF16, $nF16);
                        $ninetysecondF16 = $context->builder->fmul($ninetysecondF16, $nF16);
                        $ninetysecondF16 = $context->builder->fmul($ninetysecondF16, $nF16);
                        $ninetysecondF16 = $context->builder->fmul($ninetysecondF16, $nF16);
                        $ninetysecondF16 = $context->builder->fmul($ninetysecondF16, $nF16);
            $ninetysecondF16 = $context->builder->fmul($ninetysecondF16, $nF16);
            $ninetysecondF16 = $context->builder->fmul($ninetysecondF16, $nF16);
            $ninetysecondF16 = $context->builder->fmul($ninetysecondF16, $nF16);
            $ninetysecondF16 = $context->builder->fmul($ninetysecondF16, $nF16);
            $ninetysecondF16 = $context->builder->fmul($ninetysecondF16, $nF16);

            $ninetysecondF16 = $context->builder->fmul($ninetysecondF16, $nF16);
            $ninetysecondF16 = $context->builder->fmul($ninetysecondF16, $nF16);
            $ninetysecondF16 = $context->builder->fmul($ninetysecondF16, $nF16);
            $ninetysecondF16 = $context->builder->fmul($ninetysecondF16, $nF16);
            $ninetysecondF16 = $context->builder->fmul($ninetysecondF16, $nF16);
            $ninetysecondF16 = $context->builder->fmul($ninetysecondF16, $nF16);
            $ninetysecondF16 = $context->builder->fmul($ninetysecondF16, $nF16);
            $ninetysecondF16 = $context->builder->fmul($ninetysecondF16, $nF16);
            $ninetysecondF16 = $context->builder->fmul($ninetysecondF16, $nF16);
            $ninetysecondF16 = $context->builder->fmul($ninetysecondF16, $nF16);
            $ninetysecondF16 = $context->builder->fmul($ninetysecondF16, $nF16);
            $ninetysecondF16 = $context->builder->fmul($ninetysecondF16, $nF16);
            $ninetysecondF16 = $context->builder->fmul($ninetysecondF16, $nF16);
            $ninetysecondF16 = $context->builder->fmul($ninetysecondF16, $nF16);
            $ninetysecondF16 = $context->builder->fmul($ninetysecondF16, $nF16);
            $ninetysecondF16 = $context->builder->fmul($ninetysecondF16, $nF16);
            $ninetysecondF16 = $context->builder->fmul($ninetysecondF16, $nF16);
            $ninetysecondF16 = $context->builder->fmul($ninetysecondF16, $nF16);
            $ninetysecondF16 = $context->builder->fmul($ninetysecondF16, $nF16);
            $ninetysecondF16 = $context->builder->fmul($ninetysecondF16, $nF16);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF16
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
                throw new \LogicException('pow() **92 expected smul overflow metadata (fiftysixth)');
            }
            $fiftysixthLong = JITVariable::KIND_VARIABLE === $fiftysixthVar->kind
                ? $context->builder->load($fiftysixthVar->value)
                : $fiftysixthVar->value;
            $ov17Block = BasicBlockHelper::append($context, 'pow_ninetysecond_fiftysixth_ov');
            $ok17Block = BasicBlockHelper::append($context, 'pow_ninetysecond_fiftysixth_ok');
            $context->builder->branchIf($ov17, $ov17Block, $ok17Block);

            $context->builder->positionAtEnd($ov17Block);
            $fiftysixthF17 = $context->builder->load($fiftysixthVar->longArithOverflowDoubleSlot);
            $nF17 = $context->builder->siToFp($n, $f64);
            $ninetysecondF17 = $context->builder->fmul($fiftysixthF17, $nF17);
            $ninetysecondF17 = $context->builder->fmul($ninetysecondF17, $nF17);
            $ninetysecondF17 = $context->builder->fmul($ninetysecondF17, $nF17);
            $ninetysecondF17 = $context->builder->fmul($ninetysecondF17, $nF17);
                $ninetysecondF17 = $context->builder->fmul($ninetysecondF17, $nF17);
                    $ninetysecondF17 = $context->builder->fmul($ninetysecondF17, $nF17);
                        $ninetysecondF17 = $context->builder->fmul($ninetysecondF17, $nF17);
                        $ninetysecondF17 = $context->builder->fmul($ninetysecondF17, $nF17);
                        $ninetysecondF17 = $context->builder->fmul($ninetysecondF17, $nF17);
                        $ninetysecondF17 = $context->builder->fmul($ninetysecondF17, $nF17);
                        $ninetysecondF17 = $context->builder->fmul($ninetysecondF17, $nF17);
                        $ninetysecondF17 = $context->builder->fmul($ninetysecondF17, $nF17);
                        $ninetysecondF17 = $context->builder->fmul($ninetysecondF17, $nF17);
            $ninetysecondF17 = $context->builder->fmul($ninetysecondF17, $nF17);
            $ninetysecondF17 = $context->builder->fmul($ninetysecondF17, $nF17);
            $ninetysecondF17 = $context->builder->fmul($ninetysecondF17, $nF17);
            $ninetysecondF17 = $context->builder->fmul($ninetysecondF17, $nF17);
            $ninetysecondF17 = $context->builder->fmul($ninetysecondF17, $nF17);

            $ninetysecondF17 = $context->builder->fmul($ninetysecondF17, $nF17);
            $ninetysecondF17 = $context->builder->fmul($ninetysecondF17, $nF17);
            $ninetysecondF17 = $context->builder->fmul($ninetysecondF17, $nF17);
            $ninetysecondF17 = $context->builder->fmul($ninetysecondF17, $nF17);
            $ninetysecondF17 = $context->builder->fmul($ninetysecondF17, $nF17);
            $ninetysecondF17 = $context->builder->fmul($ninetysecondF17, $nF17);
            $ninetysecondF17 = $context->builder->fmul($ninetysecondF17, $nF17);
            $ninetysecondF17 = $context->builder->fmul($ninetysecondF17, $nF17);
            $ninetysecondF17 = $context->builder->fmul($ninetysecondF17, $nF17);
            $ninetysecondF17 = $context->builder->fmul($ninetysecondF17, $nF17);
            $ninetysecondF17 = $context->builder->fmul($ninetysecondF17, $nF17);
            $ninetysecondF17 = $context->builder->fmul($ninetysecondF17, $nF17);
            $ninetysecondF17 = $context->builder->fmul($ninetysecondF17, $nF17);
            $ninetysecondF17 = $context->builder->fmul($ninetysecondF17, $nF17);
            $ninetysecondF17 = $context->builder->fmul($ninetysecondF17, $nF17);
            $ninetysecondF17 = $context->builder->fmul($ninetysecondF17, $nF17);
            $ninetysecondF17 = $context->builder->fmul($ninetysecondF17, $nF17);
            $ninetysecondF17 = $context->builder->fmul($ninetysecondF17, $nF17);
            $ninetysecondF17 = $context->builder->fmul($ninetysecondF17, $nF17);
            $ninetysecondF17 = $context->builder->fmul($ninetysecondF17, $nF17);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF17
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
                throw new \LogicException('pow() **92 expected smul overflow metadata (fiftyseventh)');
            }
            $fiftyseventhLong = JITVariable::KIND_VARIABLE === $fiftyseventhVar->kind
                ? $context->builder->load($fiftyseventhVar->value)
                : $fiftyseventhVar->value;
            $ov18Block = BasicBlockHelper::append($context, 'pow_ninetysecond_fiftyseventh_ov');
            $ok18Block = BasicBlockHelper::append($context, 'pow_ninetysecond_fiftyseventh_ok');
            $context->builder->branchIf($ov18, $ov18Block, $ok18Block);

            $context->builder->positionAtEnd($ov18Block);
            $fiftyseventhF18 = $context->builder->load($fiftyseventhVar->longArithOverflowDoubleSlot);
            $nF18 = $context->builder->siToFp($n, $f64);
            $ninetysecondF18 = $context->builder->fmul($fiftyseventhF18, $nF18);
            $ninetysecondF18 = $context->builder->fmul($ninetysecondF18, $nF18);
            $ninetysecondF18 = $context->builder->fmul($ninetysecondF18, $nF18);
                $ninetysecondF18 = $context->builder->fmul($ninetysecondF18, $nF18);
                    $ninetysecondF18 = $context->builder->fmul($ninetysecondF18, $nF18);
                        $ninetysecondF18 = $context->builder->fmul($ninetysecondF18, $nF18);
                        $ninetysecondF18 = $context->builder->fmul($ninetysecondF18, $nF18);
                        $ninetysecondF18 = $context->builder->fmul($ninetysecondF18, $nF18);
                        $ninetysecondF18 = $context->builder->fmul($ninetysecondF18, $nF18);
                        $ninetysecondF18 = $context->builder->fmul($ninetysecondF18, $nF18);
                        $ninetysecondF18 = $context->builder->fmul($ninetysecondF18, $nF18);
                        $ninetysecondF18 = $context->builder->fmul($ninetysecondF18, $nF18);
            $ninetysecondF18 = $context->builder->fmul($ninetysecondF18, $nF18);
            $ninetysecondF18 = $context->builder->fmul($ninetysecondF18, $nF18);
            $ninetysecondF18 = $context->builder->fmul($ninetysecondF18, $nF18);
            $ninetysecondF18 = $context->builder->fmul($ninetysecondF18, $nF18);
            $ninetysecondF18 = $context->builder->fmul($ninetysecondF18, $nF18);

            $ninetysecondF18 = $context->builder->fmul($ninetysecondF18, $nF18);
            $ninetysecondF18 = $context->builder->fmul($ninetysecondF18, $nF18);
            $ninetysecondF18 = $context->builder->fmul($ninetysecondF18, $nF18);
            $ninetysecondF18 = $context->builder->fmul($ninetysecondF18, $nF18);
            $ninetysecondF18 = $context->builder->fmul($ninetysecondF18, $nF18);
            $ninetysecondF18 = $context->builder->fmul($ninetysecondF18, $nF18);
            $ninetysecondF18 = $context->builder->fmul($ninetysecondF18, $nF18);
            $ninetysecondF18 = $context->builder->fmul($ninetysecondF18, $nF18);
            $ninetysecondF18 = $context->builder->fmul($ninetysecondF18, $nF18);
            $ninetysecondF18 = $context->builder->fmul($ninetysecondF18, $nF18);
            $ninetysecondF18 = $context->builder->fmul($ninetysecondF18, $nF18);
            $ninetysecondF18 = $context->builder->fmul($ninetysecondF18, $nF18);
            $ninetysecondF18 = $context->builder->fmul($ninetysecondF18, $nF18);
            $ninetysecondF18 = $context->builder->fmul($ninetysecondF18, $nF18);
            $ninetysecondF18 = $context->builder->fmul($ninetysecondF18, $nF18);
            $ninetysecondF18 = $context->builder->fmul($ninetysecondF18, $nF18);
            $ninetysecondF18 = $context->builder->fmul($ninetysecondF18, $nF18);
            $ninetysecondF18 = $context->builder->fmul($ninetysecondF18, $nF18);
            $ninetysecondF18 = $context->builder->fmul($ninetysecondF18, $nF18);
            $ninetysecondF18 = $context->builder->fmul($ninetysecondF18, $nF18);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF18
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
                throw new \LogicException('pow() **92 expected smul overflow metadata (fiftyeighth)');
            }
            $fiftyeighthLong = JITVariable::KIND_VARIABLE === $fiftyeighthVar->kind
                ? $context->builder->load($fiftyeighthVar->value)
                : $fiftyeighthVar->value;
            $ov19Block = BasicBlockHelper::append($context, 'pow_ninetysecond_fiftyeighth_ov');
            $ok19Block = BasicBlockHelper::append($context, 'pow_ninetysecond_fiftyeighth_ok');
            $context->builder->branchIf($ov19, $ov19Block, $ok19Block);

            $context->builder->positionAtEnd($ov19Block);
            $fiftyeighthF19 = $context->builder->load($fiftyeighthVar->longArithOverflowDoubleSlot);
            $nF19 = $context->builder->siToFp($n, $f64);
            $ninetysecondF19 = $context->builder->fmul($fiftyeighthF19, $nF19);
            $ninetysecondF19 = $context->builder->fmul($ninetysecondF19, $nF19);
                $ninetysecondF19 = $context->builder->fmul($ninetysecondF19, $nF19);
                    $ninetysecondF19 = $context->builder->fmul($ninetysecondF19, $nF19);
                        $ninetysecondF19 = $context->builder->fmul($ninetysecondF19, $nF19);
                        $ninetysecondF19 = $context->builder->fmul($ninetysecondF19, $nF19);
                        $ninetysecondF19 = $context->builder->fmul($ninetysecondF19, $nF19);
                        $ninetysecondF19 = $context->builder->fmul($ninetysecondF19, $nF19);
                        $ninetysecondF19 = $context->builder->fmul($ninetysecondF19, $nF19);
                        $ninetysecondF19 = $context->builder->fmul($ninetysecondF19, $nF19);
                        $ninetysecondF19 = $context->builder->fmul($ninetysecondF19, $nF19);
            $ninetysecondF19 = $context->builder->fmul($ninetysecondF19, $nF19);
            $ninetysecondF19 = $context->builder->fmul($ninetysecondF19, $nF19);
            $ninetysecondF19 = $context->builder->fmul($ninetysecondF19, $nF19);
            $ninetysecondF19 = $context->builder->fmul($ninetysecondF19, $nF19);
            $ninetysecondF19 = $context->builder->fmul($ninetysecondF19, $nF19);

            $ninetysecondF19 = $context->builder->fmul($ninetysecondF19, $nF19);
            $ninetysecondF19 = $context->builder->fmul($ninetysecondF19, $nF19);
            $ninetysecondF19 = $context->builder->fmul($ninetysecondF19, $nF19);
            $ninetysecondF19 = $context->builder->fmul($ninetysecondF19, $nF19);
            $ninetysecondF19 = $context->builder->fmul($ninetysecondF19, $nF19);
            $ninetysecondF19 = $context->builder->fmul($ninetysecondF19, $nF19);
            $ninetysecondF19 = $context->builder->fmul($ninetysecondF19, $nF19);
            $ninetysecondF19 = $context->builder->fmul($ninetysecondF19, $nF19);
            $ninetysecondF19 = $context->builder->fmul($ninetysecondF19, $nF19);
            $ninetysecondF19 = $context->builder->fmul($ninetysecondF19, $nF19);
            $ninetysecondF19 = $context->builder->fmul($ninetysecondF19, $nF19);
            $ninetysecondF19 = $context->builder->fmul($ninetysecondF19, $nF19);
            $ninetysecondF19 = $context->builder->fmul($ninetysecondF19, $nF19);
            $ninetysecondF19 = $context->builder->fmul($ninetysecondF19, $nF19);
            $ninetysecondF19 = $context->builder->fmul($ninetysecondF19, $nF19);
            $ninetysecondF19 = $context->builder->fmul($ninetysecondF19, $nF19);
            $ninetysecondF19 = $context->builder->fmul($ninetysecondF19, $nF19);
            $ninetysecondF19 = $context->builder->fmul($ninetysecondF19, $nF19);
            $ninetysecondF19 = $context->builder->fmul($ninetysecondF19, $nF19);
            $ninetysecondF19 = $context->builder->fmul($ninetysecondF19, $nF19);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF19
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
                throw new \LogicException('pow() **92 expected smul overflow metadata (fiftyninth)');
            }
            $fiftyninthLong = JITVariable::KIND_VARIABLE === $fiftyninthVar->kind
                ? $context->builder->load($fiftyninthVar->value)
                : $fiftyninthVar->value;
            $ov20Block = BasicBlockHelper::append($context, 'pow_ninetysecond_fiftyninth_ov');
            $ok20Block = BasicBlockHelper::append($context, 'pow_ninetysecond_fiftyninth_ok');
            $context->builder->branchIf($ov20, $ov20Block, $ok20Block);

            $context->builder->positionAtEnd($ov20Block);
            $fiftyninthF20 = $context->builder->load($fiftyninthVar->longArithOverflowDoubleSlot);
            $nF20 = $context->builder->siToFp($n, $f64);
            $ninetysecondF20 = $context->builder->fmul($fiftyninthF20, $nF20);
                $ninetysecondF20 = $context->builder->fmul($ninetysecondF20, $nF20);
                    $ninetysecondF20 = $context->builder->fmul($ninetysecondF20, $nF20);
                        $ninetysecondF20 = $context->builder->fmul($ninetysecondF20, $nF20);
                        $ninetysecondF20 = $context->builder->fmul($ninetysecondF20, $nF20);
                        $ninetysecondF20 = $context->builder->fmul($ninetysecondF20, $nF20);
                        $ninetysecondF20 = $context->builder->fmul($ninetysecondF20, $nF20);
                        $ninetysecondF20 = $context->builder->fmul($ninetysecondF20, $nF20);
                        $ninetysecondF20 = $context->builder->fmul($ninetysecondF20, $nF20);
                        $ninetysecondF20 = $context->builder->fmul($ninetysecondF20, $nF20);
            $ninetysecondF20 = $context->builder->fmul($ninetysecondF20, $nF20);
            $ninetysecondF20 = $context->builder->fmul($ninetysecondF20, $nF20);
            $ninetysecondF20 = $context->builder->fmul($ninetysecondF20, $nF20);
            $ninetysecondF20 = $context->builder->fmul($ninetysecondF20, $nF20);
            $ninetysecondF20 = $context->builder->fmul($ninetysecondF20, $nF20);

            $ninetysecondF20 = $context->builder->fmul($ninetysecondF20, $nF20);
            $ninetysecondF20 = $context->builder->fmul($ninetysecondF20, $nF20);
            $ninetysecondF20 = $context->builder->fmul($ninetysecondF20, $nF20);
            $ninetysecondF20 = $context->builder->fmul($ninetysecondF20, $nF20);
            $ninetysecondF20 = $context->builder->fmul($ninetysecondF20, $nF20);
            $ninetysecondF20 = $context->builder->fmul($ninetysecondF20, $nF20);
            $ninetysecondF20 = $context->builder->fmul($ninetysecondF20, $nF20);
            $ninetysecondF20 = $context->builder->fmul($ninetysecondF20, $nF20);
            $ninetysecondF20 = $context->builder->fmul($ninetysecondF20, $nF20);
            $ninetysecondF20 = $context->builder->fmul($ninetysecondF20, $nF20);
            $ninetysecondF20 = $context->builder->fmul($ninetysecondF20, $nF20);
            $ninetysecondF20 = $context->builder->fmul($ninetysecondF20, $nF20);
            $ninetysecondF20 = $context->builder->fmul($ninetysecondF20, $nF20);
            $ninetysecondF20 = $context->builder->fmul($ninetysecondF20, $nF20);
            $ninetysecondF20 = $context->builder->fmul($ninetysecondF20, $nF20);
            $ninetysecondF20 = $context->builder->fmul($ninetysecondF20, $nF20);
            $ninetysecondF20 = $context->builder->fmul($ninetysecondF20, $nF20);
            $ninetysecondF20 = $context->builder->fmul($ninetysecondF20, $nF20);
            $ninetysecondF20 = $context->builder->fmul($ninetysecondF20, $nF20);
            $ninetysecondF20 = $context->builder->fmul($ninetysecondF20, $nF20);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF20
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
                throw new \LogicException('pow() **92 expected smul overflow metadata (sixtieth)');
            }
            $sixtiethLong = JITVariable::KIND_VARIABLE === $sixtiethVar->kind
                ? $context->builder->load($sixtiethVar->value)
                : $sixtiethVar->value;
            $ov21Block = BasicBlockHelper::append($context, 'pow_ninetysecond_sixtieth_ov');
            $ok21Block = BasicBlockHelper::append($context, 'pow_ninetysecond_sixtieth_ok');
            $context->builder->branchIf($ov21, $ov21Block, $ok21Block);

            $context->builder->positionAtEnd($ov21Block);
            $sixtiethF21 = $context->builder->load($sixtiethVar->longArithOverflowDoubleSlot);
            $nF21 = $context->builder->siToFp($n, $f64);
            $ninetysecondF21 = $context->builder->fmul($sixtiethF21, $nF21);
                $ninetysecondF21 = $context->builder->fmul($ninetysecondF21, $nF21);
                    $ninetysecondF21 = $context->builder->fmul($ninetysecondF21, $nF21);
                    $ninetysecondF21 = $context->builder->fmul($ninetysecondF21, $nF21);
                    $ninetysecondF21 = $context->builder->fmul($ninetysecondF21, $nF21);
                    $ninetysecondF21 = $context->builder->fmul($ninetysecondF21, $nF21);
                    $ninetysecondF21 = $context->builder->fmul($ninetysecondF21, $nF21);
                    $ninetysecondF21 = $context->builder->fmul($ninetysecondF21, $nF21);
                    $ninetysecondF21 = $context->builder->fmul($ninetysecondF21, $nF21);
            $ninetysecondF21 = $context->builder->fmul($ninetysecondF21, $nF21);
            $ninetysecondF21 = $context->builder->fmul($ninetysecondF21, $nF21);
            $ninetysecondF21 = $context->builder->fmul($ninetysecondF21, $nF21);
            $ninetysecondF21 = $context->builder->fmul($ninetysecondF21, $nF21);
            $ninetysecondF21 = $context->builder->fmul($ninetysecondF21, $nF21);

            $ninetysecondF21 = $context->builder->fmul($ninetysecondF21, $nF21);
            $ninetysecondF21 = $context->builder->fmul($ninetysecondF21, $nF21);
            $ninetysecondF21 = $context->builder->fmul($ninetysecondF21, $nF21);
            $ninetysecondF21 = $context->builder->fmul($ninetysecondF21, $nF21);
            $ninetysecondF21 = $context->builder->fmul($ninetysecondF21, $nF21);
            $ninetysecondF21 = $context->builder->fmul($ninetysecondF21, $nF21);
            $ninetysecondF21 = $context->builder->fmul($ninetysecondF21, $nF21);
            $ninetysecondF21 = $context->builder->fmul($ninetysecondF21, $nF21);
            $ninetysecondF21 = $context->builder->fmul($ninetysecondF21, $nF21);
            $ninetysecondF21 = $context->builder->fmul($ninetysecondF21, $nF21);
            $ninetysecondF21 = $context->builder->fmul($ninetysecondF21, $nF21);
            $ninetysecondF21 = $context->builder->fmul($ninetysecondF21, $nF21);
            $ninetysecondF21 = $context->builder->fmul($ninetysecondF21, $nF21);
            $ninetysecondF21 = $context->builder->fmul($ninetysecondF21, $nF21);
            $ninetysecondF21 = $context->builder->fmul($ninetysecondF21, $nF21);
            $ninetysecondF21 = $context->builder->fmul($ninetysecondF21, $nF21);
            $ninetysecondF21 = $context->builder->fmul($ninetysecondF21, $nF21);
            $ninetysecondF21 = $context->builder->fmul($ninetysecondF21, $nF21);
            $ninetysecondF21 = $context->builder->fmul($ninetysecondF21, $nF21);
            $ninetysecondF21 = $context->builder->fmul($ninetysecondF21, $nF21);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF21
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
                throw new \LogicException('pow() **92 expected smul overflow metadata (sixtyfirst)');
            }
            $sixtyfirstLong = JITVariable::KIND_VARIABLE === $sixtyfirstVar->kind
                ? $context->builder->load($sixtyfirstVar->value)
                : $sixtyfirstVar->value;
            $ov22Block = BasicBlockHelper::append($context, 'pow_ninetysecond_sixtyfirst_ov');
            $ok22Block = BasicBlockHelper::append($context, 'pow_ninetysecond_sixtyfirst_ok');
            $context->builder->branchIf($ov22, $ov22Block, $ok22Block);

            $context->builder->positionAtEnd($ov22Block);
            $sixtyfirstF22 = $context->builder->load($sixtyfirstVar->longArithOverflowDoubleSlot);
            $nF22 = $context->builder->siToFp($n, $f64);
            $ninetysecondF22 = $context->builder->fmul($sixtyfirstF22, $nF22);
                $ninetysecondF22 = $context->builder->fmul($ninetysecondF22, $nF22);
                $ninetysecondF22 = $context->builder->fmul($ninetysecondF22, $nF22);
                $ninetysecondF22 = $context->builder->fmul($ninetysecondF22, $nF22);
                $ninetysecondF22 = $context->builder->fmul($ninetysecondF22, $nF22);
                $ninetysecondF22 = $context->builder->fmul($ninetysecondF22, $nF22);
                $ninetysecondF22 = $context->builder->fmul($ninetysecondF22, $nF22);
                $ninetysecondF22 = $context->builder->fmul($ninetysecondF22, $nF22);
            $ninetysecondF22 = $context->builder->fmul($ninetysecondF22, $nF22);
            $ninetysecondF22 = $context->builder->fmul($ninetysecondF22, $nF22);
            $ninetysecondF22 = $context->builder->fmul($ninetysecondF22, $nF22);
            $ninetysecondF22 = $context->builder->fmul($ninetysecondF22, $nF22);
            $ninetysecondF22 = $context->builder->fmul($ninetysecondF22, $nF22);

            $ninetysecondF22 = $context->builder->fmul($ninetysecondF22, $nF22);
            $ninetysecondF22 = $context->builder->fmul($ninetysecondF22, $nF22);
            $ninetysecondF22 = $context->builder->fmul($ninetysecondF22, $nF22);
            $ninetysecondF22 = $context->builder->fmul($ninetysecondF22, $nF22);
            $ninetysecondF22 = $context->builder->fmul($ninetysecondF22, $nF22);
            $ninetysecondF22 = $context->builder->fmul($ninetysecondF22, $nF22);
            $ninetysecondF22 = $context->builder->fmul($ninetysecondF22, $nF22);
            $ninetysecondF22 = $context->builder->fmul($ninetysecondF22, $nF22);
            $ninetysecondF22 = $context->builder->fmul($ninetysecondF22, $nF22);
            $ninetysecondF22 = $context->builder->fmul($ninetysecondF22, $nF22);
            $ninetysecondF22 = $context->builder->fmul($ninetysecondF22, $nF22);
            $ninetysecondF22 = $context->builder->fmul($ninetysecondF22, $nF22);
            $ninetysecondF22 = $context->builder->fmul($ninetysecondF22, $nF22);
            $ninetysecondF22 = $context->builder->fmul($ninetysecondF22, $nF22);
            $ninetysecondF22 = $context->builder->fmul($ninetysecondF22, $nF22);
            $ninetysecondF22 = $context->builder->fmul($ninetysecondF22, $nF22);
            $ninetysecondF22 = $context->builder->fmul($ninetysecondF22, $nF22);
            $ninetysecondF22 = $context->builder->fmul($ninetysecondF22, $nF22);
            $ninetysecondF22 = $context->builder->fmul($ninetysecondF22, $nF22);
            $ninetysecondF22 = $context->builder->fmul($ninetysecondF22, $nF22);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF22
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
                throw new \LogicException('pow() **92 expected smul overflow metadata (sixtysecond)');
            }
            $sixtysecondLong = JITVariable::KIND_VARIABLE === $sixtysecondVar->kind
                ? $context->builder->load($sixtysecondVar->value)
                : $sixtysecondVar->value;
            $ov23Block = BasicBlockHelper::append($context, 'pow_ninetysecond_sixtysecond_ov');
            $ok23Block = BasicBlockHelper::append($context, 'pow_ninetysecond_sixtysecond_ok');
            $context->builder->branchIf($ov23, $ov23Block, $ok23Block);

            $context->builder->positionAtEnd($ov23Block);
            $sixtysecondF23 = $context->builder->load($sixtysecondVar->longArithOverflowDoubleSlot);
            $nF23 = $context->builder->siToFp($n, $f64);
            $ninetysecondF23 = $context->builder->fmul($sixtysecondF23, $nF23);
            $ninetysecondF23 = $context->builder->fmul($ninetysecondF23, $nF23);
            $ninetysecondF23 = $context->builder->fmul($ninetysecondF23, $nF23);
            $ninetysecondF23 = $context->builder->fmul($ninetysecondF23, $nF23);
            $ninetysecondF23 = $context->builder->fmul($ninetysecondF23, $nF23);
            $ninetysecondF23 = $context->builder->fmul($ninetysecondF23, $nF23);
            $ninetysecondF23 = $context->builder->fmul($ninetysecondF23, $nF23);
            $ninetysecondF23 = $context->builder->fmul($ninetysecondF23, $nF23);
            $ninetysecondF23 = $context->builder->fmul($ninetysecondF23, $nF23);
            $ninetysecondF23 = $context->builder->fmul($ninetysecondF23, $nF23);
            $ninetysecondF23 = $context->builder->fmul($ninetysecondF23, $nF23);
            $ninetysecondF23 = $context->builder->fmul($ninetysecondF23, $nF23);

            $ninetysecondF23 = $context->builder->fmul($ninetysecondF23, $nF23);
            $ninetysecondF23 = $context->builder->fmul($ninetysecondF23, $nF23);
            $ninetysecondF23 = $context->builder->fmul($ninetysecondF23, $nF23);
            $ninetysecondF23 = $context->builder->fmul($ninetysecondF23, $nF23);
            $ninetysecondF23 = $context->builder->fmul($ninetysecondF23, $nF23);
            $ninetysecondF23 = $context->builder->fmul($ninetysecondF23, $nF23);
            $ninetysecondF23 = $context->builder->fmul($ninetysecondF23, $nF23);
            $ninetysecondF23 = $context->builder->fmul($ninetysecondF23, $nF23);
            $ninetysecondF23 = $context->builder->fmul($ninetysecondF23, $nF23);
            $ninetysecondF23 = $context->builder->fmul($ninetysecondF23, $nF23);
            $ninetysecondF23 = $context->builder->fmul($ninetysecondF23, $nF23);
            $ninetysecondF23 = $context->builder->fmul($ninetysecondF23, $nF23);
            $ninetysecondF23 = $context->builder->fmul($ninetysecondF23, $nF23);
            $ninetysecondF23 = $context->builder->fmul($ninetysecondF23, $nF23);
            $ninetysecondF23 = $context->builder->fmul($ninetysecondF23, $nF23);
            $ninetysecondF23 = $context->builder->fmul($ninetysecondF23, $nF23);
            $ninetysecondF23 = $context->builder->fmul($ninetysecondF23, $nF23);
            $ninetysecondF23 = $context->builder->fmul($ninetysecondF23, $nF23);
            $ninetysecondF23 = $context->builder->fmul($ninetysecondF23, $nF23);
            $ninetysecondF23 = $context->builder->fmul($ninetysecondF23, $nF23);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF23
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
                throw new \LogicException('pow() **92 expected smul overflow metadata (sixtythird)');
            }
            $sixtythirdLong = JITVariable::KIND_VARIABLE === $sixtythirdVar->kind
                ? $context->builder->load($sixtythirdVar->value)
                : $sixtythirdVar->value;
            $ov24Block = BasicBlockHelper::append($context, 'pow_ninetysecond_sixtythird_ov');
            $ok24Block = BasicBlockHelper::append($context, 'pow_ninetysecond_sixtythird_ok');
            $context->builder->branchIf($ov24, $ov24Block, $ok24Block);

            $context->builder->positionAtEnd($ov24Block);
            $sixtythirdF24 = $context->builder->load($sixtythirdVar->longArithOverflowDoubleSlot);
            $nF24 = $context->builder->siToFp($n, $f64);
            $ninetysecondF24 = $context->builder->fmul($sixtythirdF24, $nF24);
            $ninetysecondF24 = $context->builder->fmul($ninetysecondF24, $nF24);
            $ninetysecondF24 = $context->builder->fmul($ninetysecondF24, $nF24);
            $ninetysecondF24 = $context->builder->fmul($ninetysecondF24, $nF24);
            $ninetysecondF24 = $context->builder->fmul($ninetysecondF24, $nF24);
            $ninetysecondF24 = $context->builder->fmul($ninetysecondF24, $nF24);
            $ninetysecondF24 = $context->builder->fmul($ninetysecondF24, $nF24);
            $ninetysecondF24 = $context->builder->fmul($ninetysecondF24, $nF24);
            $ninetysecondF24 = $context->builder->fmul($ninetysecondF24, $nF24);
            $ninetysecondF24 = $context->builder->fmul($ninetysecondF24, $nF24);
            $ninetysecondF24 = $context->builder->fmul($ninetysecondF24, $nF24);

            $ninetysecondF24 = $context->builder->fmul($ninetysecondF24, $nF24);
            $ninetysecondF24 = $context->builder->fmul($ninetysecondF24, $nF24);
            $ninetysecondF24 = $context->builder->fmul($ninetysecondF24, $nF24);
            $ninetysecondF24 = $context->builder->fmul($ninetysecondF24, $nF24);
            $ninetysecondF24 = $context->builder->fmul($ninetysecondF24, $nF24);
            $ninetysecondF24 = $context->builder->fmul($ninetysecondF24, $nF24);
            $ninetysecondF24 = $context->builder->fmul($ninetysecondF24, $nF24);
            $ninetysecondF24 = $context->builder->fmul($ninetysecondF24, $nF24);
            $ninetysecondF24 = $context->builder->fmul($ninetysecondF24, $nF24);
            $ninetysecondF24 = $context->builder->fmul($ninetysecondF24, $nF24);
            $ninetysecondF24 = $context->builder->fmul($ninetysecondF24, $nF24);
            $ninetysecondF24 = $context->builder->fmul($ninetysecondF24, $nF24);
            $ninetysecondF24 = $context->builder->fmul($ninetysecondF24, $nF24);
            $ninetysecondF24 = $context->builder->fmul($ninetysecondF24, $nF24);
            $ninetysecondF24 = $context->builder->fmul($ninetysecondF24, $nF24);
            $ninetysecondF24 = $context->builder->fmul($ninetysecondF24, $nF24);
            $ninetysecondF24 = $context->builder->fmul($ninetysecondF24, $nF24);
            $ninetysecondF24 = $context->builder->fmul($ninetysecondF24, $nF24);
            $ninetysecondF24 = $context->builder->fmul($ninetysecondF24, $nF24);
            $ninetysecondF24 = $context->builder->fmul($ninetysecondF24, $nF24);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF24
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
                throw new \LogicException('pow() **92 expected smul overflow metadata (sixtyfourth)');
            }
            $sixtyfourthLong = JITVariable::KIND_VARIABLE === $sixtyfourthVar->kind
                ? $context->builder->load($sixtyfourthVar->value)
                : $sixtyfourthVar->value;
            $ov25Block = BasicBlockHelper::append($context, 'pow_ninetysecond_sixtyfourth_ov');
            $ok25Block = BasicBlockHelper::append($context, 'pow_ninetysecond_sixtyfourth_ok');
            $context->builder->branchIf($ov25, $ov25Block, $ok25Block);

            $context->builder->positionAtEnd($ov25Block);
            $sixtyfourthF25 = $context->builder->load($sixtyfourthVar->longArithOverflowDoubleSlot);
            $nF25 = $context->builder->siToFp($n, $f64);
            $ninetysecondF25 = $context->builder->fmul($sixtyfourthF25, $nF25);
            $ninetysecondF25 = $context->builder->fmul($ninetysecondF25, $nF25);
            $ninetysecondF25 = $context->builder->fmul($ninetysecondF25, $nF25);
            $ninetysecondF25 = $context->builder->fmul($ninetysecondF25, $nF25);
            $ninetysecondF25 = $context->builder->fmul($ninetysecondF25, $nF25);
            $ninetysecondF25 = $context->builder->fmul($ninetysecondF25, $nF25);
            $ninetysecondF25 = $context->builder->fmul($ninetysecondF25, $nF25);
            $ninetysecondF25 = $context->builder->fmul($ninetysecondF25, $nF25);
            $ninetysecondF25 = $context->builder->fmul($ninetysecondF25, $nF25);
            $ninetysecondF25 = $context->builder->fmul($ninetysecondF25, $nF25);

            $ninetysecondF25 = $context->builder->fmul($ninetysecondF25, $nF25);
            $ninetysecondF25 = $context->builder->fmul($ninetysecondF25, $nF25);
            $ninetysecondF25 = $context->builder->fmul($ninetysecondF25, $nF25);
            $ninetysecondF25 = $context->builder->fmul($ninetysecondF25, $nF25);
            $ninetysecondF25 = $context->builder->fmul($ninetysecondF25, $nF25);
            $ninetysecondF25 = $context->builder->fmul($ninetysecondF25, $nF25);
            $ninetysecondF25 = $context->builder->fmul($ninetysecondF25, $nF25);
            $ninetysecondF25 = $context->builder->fmul($ninetysecondF25, $nF25);
            $ninetysecondF25 = $context->builder->fmul($ninetysecondF25, $nF25);
            $ninetysecondF25 = $context->builder->fmul($ninetysecondF25, $nF25);
            $ninetysecondF25 = $context->builder->fmul($ninetysecondF25, $nF25);
            $ninetysecondF25 = $context->builder->fmul($ninetysecondF25, $nF25);
            $ninetysecondF25 = $context->builder->fmul($ninetysecondF25, $nF25);
            $ninetysecondF25 = $context->builder->fmul($ninetysecondF25, $nF25);
            $ninetysecondF25 = $context->builder->fmul($ninetysecondF25, $nF25);
            $ninetysecondF25 = $context->builder->fmul($ninetysecondF25, $nF25);
            $ninetysecondF25 = $context->builder->fmul($ninetysecondF25, $nF25);
            $ninetysecondF25 = $context->builder->fmul($ninetysecondF25, $nF25);
            $ninetysecondF25 = $context->builder->fmul($ninetysecondF25, $nF25);
            $ninetysecondF25 = $context->builder->fmul($ninetysecondF25, $nF25);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF25
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
                throw new \LogicException('pow() **92 expected smul overflow metadata (sixtyfifth)');
            }
            $sixtyfifthLong = JITVariable::KIND_VARIABLE === $sixtyfifthVar->kind
                ? $context->builder->load($sixtyfifthVar->value)
                : $sixtyfifthVar->value;
            $ov26Block = BasicBlockHelper::append($context, 'pow_ninetysecond_sixtyfifth_ov');
            $ok26Block = BasicBlockHelper::append($context, 'pow_ninetysecond_sixtyfifth_ok');
            $context->builder->branchIf($ov26, $ov26Block, $ok26Block);

            $context->builder->positionAtEnd($ov26Block);
            $sixtyfifthF26 = $context->builder->load($sixtyfifthVar->longArithOverflowDoubleSlot);
            $nF26 = $context->builder->siToFp($n, $f64);
            $ninetysecondF26 = $context->builder->fmul($sixtyfifthF26, $nF26);
            $ninetysecondF26 = $context->builder->fmul($ninetysecondF26, $nF26);
            $ninetysecondF26 = $context->builder->fmul($ninetysecondF26, $nF26);
            $ninetysecondF26 = $context->builder->fmul($ninetysecondF26, $nF26);
            $ninetysecondF26 = $context->builder->fmul($ninetysecondF26, $nF26);
            $ninetysecondF26 = $context->builder->fmul($ninetysecondF26, $nF26);
            $ninetysecondF26 = $context->builder->fmul($ninetysecondF26, $nF26);
            $ninetysecondF26 = $context->builder->fmul($ninetysecondF26, $nF26);
            $ninetysecondF26 = $context->builder->fmul($ninetysecondF26, $nF26);

            $ninetysecondF26 = $context->builder->fmul($ninetysecondF26, $nF26);
            $ninetysecondF26 = $context->builder->fmul($ninetysecondF26, $nF26);
            $ninetysecondF26 = $context->builder->fmul($ninetysecondF26, $nF26);
            $ninetysecondF26 = $context->builder->fmul($ninetysecondF26, $nF26);
            $ninetysecondF26 = $context->builder->fmul($ninetysecondF26, $nF26);
            $ninetysecondF26 = $context->builder->fmul($ninetysecondF26, $nF26);
            $ninetysecondF26 = $context->builder->fmul($ninetysecondF26, $nF26);
            $ninetysecondF26 = $context->builder->fmul($ninetysecondF26, $nF26);
            $ninetysecondF26 = $context->builder->fmul($ninetysecondF26, $nF26);
            $ninetysecondF26 = $context->builder->fmul($ninetysecondF26, $nF26);
            $ninetysecondF26 = $context->builder->fmul($ninetysecondF26, $nF26);
            $ninetysecondF26 = $context->builder->fmul($ninetysecondF26, $nF26);
            $ninetysecondF26 = $context->builder->fmul($ninetysecondF26, $nF26);
            $ninetysecondF26 = $context->builder->fmul($ninetysecondF26, $nF26);
            $ninetysecondF26 = $context->builder->fmul($ninetysecondF26, $nF26);
            $ninetysecondF26 = $context->builder->fmul($ninetysecondF26, $nF26);
            $ninetysecondF26 = $context->builder->fmul($ninetysecondF26, $nF26);
            $ninetysecondF26 = $context->builder->fmul($ninetysecondF26, $nF26);
            $ninetysecondF26 = $context->builder->fmul($ninetysecondF26, $nF26);
            $ninetysecondF26 = $context->builder->fmul($ninetysecondF26, $nF26);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF26
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
                throw new \LogicException('pow() **92 expected smul overflow metadata (sixtysixth)');
            }
            $sixtysixthLong = JITVariable::KIND_VARIABLE === $sixtysixthVar->kind
                ? $context->builder->load($sixtysixthVar->value)
                : $sixtysixthVar->value;
            $ov27Block = BasicBlockHelper::append($context, 'pow_ninetysecond_sixtysixth_ov');
            $ok27Block = BasicBlockHelper::append($context, 'pow_ninetysecond_sixtysixth_ok');
            $context->builder->branchIf($ov27, $ov27Block, $ok27Block);

            $context->builder->positionAtEnd($ov27Block);
            $sixtysixthF27 = $context->builder->load($sixtysixthVar->longArithOverflowDoubleSlot);
            $nF27 = $context->builder->siToFp($n, $f64);
            $ninetysecondF27 = $context->builder->fmul($sixtysixthF27, $nF27);
            $ninetysecondF27 = $context->builder->fmul($ninetysecondF27, $nF27);
            $ninetysecondF27 = $context->builder->fmul($ninetysecondF27, $nF27);
            $ninetysecondF27 = $context->builder->fmul($ninetysecondF27, $nF27);
            $ninetysecondF27 = $context->builder->fmul($ninetysecondF27, $nF27);
            $ninetysecondF27 = $context->builder->fmul($ninetysecondF27, $nF27);
            $ninetysecondF27 = $context->builder->fmul($ninetysecondF27, $nF27);
            $ninetysecondF27 = $context->builder->fmul($ninetysecondF27, $nF27);

            $ninetysecondF27 = $context->builder->fmul($ninetysecondF27, $nF27);
            $ninetysecondF27 = $context->builder->fmul($ninetysecondF27, $nF27);
            $ninetysecondF27 = $context->builder->fmul($ninetysecondF27, $nF27);
            $ninetysecondF27 = $context->builder->fmul($ninetysecondF27, $nF27);
            $ninetysecondF27 = $context->builder->fmul($ninetysecondF27, $nF27);
            $ninetysecondF27 = $context->builder->fmul($ninetysecondF27, $nF27);
            $ninetysecondF27 = $context->builder->fmul($ninetysecondF27, $nF27);
            $ninetysecondF27 = $context->builder->fmul($ninetysecondF27, $nF27);
            $ninetysecondF27 = $context->builder->fmul($ninetysecondF27, $nF27);
            $ninetysecondF27 = $context->builder->fmul($ninetysecondF27, $nF27);
            $ninetysecondF27 = $context->builder->fmul($ninetysecondF27, $nF27);
            $ninetysecondF27 = $context->builder->fmul($ninetysecondF27, $nF27);
            $ninetysecondF27 = $context->builder->fmul($ninetysecondF27, $nF27);
            $ninetysecondF27 = $context->builder->fmul($ninetysecondF27, $nF27);
            $ninetysecondF27 = $context->builder->fmul($ninetysecondF27, $nF27);
            $ninetysecondF27 = $context->builder->fmul($ninetysecondF27, $nF27);
            $ninetysecondF27 = $context->builder->fmul($ninetysecondF27, $nF27);
            $ninetysecondF27 = $context->builder->fmul($ninetysecondF27, $nF27);
            $ninetysecondF27 = $context->builder->fmul($ninetysecondF27, $nF27);
            $ninetysecondF27 = $context->builder->fmul($ninetysecondF27, $nF27);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF27
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
                throw new \LogicException('pow() **92 expected smul overflow metadata (sixtyseventh)');
            }
            $sixtyseventhLong = JITVariable::KIND_VARIABLE === $sixtyseventhVar->kind
                ? $context->builder->load($sixtyseventhVar->value)
                : $sixtyseventhVar->value;
            $ov28Block = BasicBlockHelper::append($context, 'pow_ninetysecond_sixtyseventh_ov');
            $ok28Block = BasicBlockHelper::append($context, 'pow_ninetysecond_sixtyseventh_ok');
            $context->builder->branchIf($ov28, $ov28Block, $ok28Block);

            $context->builder->positionAtEnd($ov28Block);
            $sixtyseventhF28 = $context->builder->load($sixtyseventhVar->longArithOverflowDoubleSlot);
            $nF28 = $context->builder->siToFp($n, $f64);
            $ninetysecondF28 = $context->builder->fmul($sixtyseventhF28, $nF28);
            $ninetysecondF28 = $context->builder->fmul($ninetysecondF28, $nF28);
            $ninetysecondF28 = $context->builder->fmul($ninetysecondF28, $nF28);
            $ninetysecondF28 = $context->builder->fmul($ninetysecondF28, $nF28);
            $ninetysecondF28 = $context->builder->fmul($ninetysecondF28, $nF28);
            $ninetysecondF28 = $context->builder->fmul($ninetysecondF28, $nF28);
            $ninetysecondF28 = $context->builder->fmul($ninetysecondF28, $nF28);
            $ninetysecondF28 = $context->builder->fmul($ninetysecondF28, $nF28);

            $ninetysecondF28 = $context->builder->fmul($ninetysecondF28, $nF28);
            $ninetysecondF28 = $context->builder->fmul($ninetysecondF28, $nF28);
            $ninetysecondF28 = $context->builder->fmul($ninetysecondF28, $nF28);
            $ninetysecondF28 = $context->builder->fmul($ninetysecondF28, $nF28);
            $ninetysecondF28 = $context->builder->fmul($ninetysecondF28, $nF28);
            $ninetysecondF28 = $context->builder->fmul($ninetysecondF28, $nF28);
            $ninetysecondF28 = $context->builder->fmul($ninetysecondF28, $nF28);
            $ninetysecondF28 = $context->builder->fmul($ninetysecondF28, $nF28);
            $ninetysecondF28 = $context->builder->fmul($ninetysecondF28, $nF28);
            $ninetysecondF28 = $context->builder->fmul($ninetysecondF28, $nF28);
            $ninetysecondF28 = $context->builder->fmul($ninetysecondF28, $nF28);
            $ninetysecondF28 = $context->builder->fmul($ninetysecondF28, $nF28);
            $ninetysecondF28 = $context->builder->fmul($ninetysecondF28, $nF28);
            $ninetysecondF28 = $context->builder->fmul($ninetysecondF28, $nF28);
            $ninetysecondF28 = $context->builder->fmul($ninetysecondF28, $nF28);
            $ninetysecondF28 = $context->builder->fmul($ninetysecondF28, $nF28);
            $ninetysecondF28 = $context->builder->fmul($ninetysecondF28, $nF28);
            $ninetysecondF28 = $context->builder->fmul($ninetysecondF28, $nF28);
            $ninetysecondF28 = $context->builder->fmul($ninetysecondF28, $nF28);
            $ninetysecondF28 = $context->builder->fmul($ninetysecondF28, $nF28);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF28
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
                throw new \LogicException('pow() **92 expected smul overflow metadata (sixtyeighth)');
            }
            $sixtyeighthLong = JITVariable::KIND_VARIABLE === $sixtyeighthVar->kind
                ? $context->builder->load($sixtyeighthVar->value)
                : $sixtyeighthVar->value;
            $ov29Block = BasicBlockHelper::append($context, 'pow_ninetysecond_sixtyeighth_ov');
            $ok29Block = BasicBlockHelper::append($context, 'pow_ninetysecond_sixtyeighth_ok');
            $context->builder->branchIf($ov29, $ov29Block, $ok29Block);

            $context->builder->positionAtEnd($ov29Block);
            $sixtyeighthF29 = $context->builder->load($sixtyeighthVar->longArithOverflowDoubleSlot);
            $nF29 = $context->builder->siToFp($n, $f64);
            $ninetysecondF29 = $context->builder->fmul($sixtyeighthF29, $nF29);
            $ninetysecondF29 = $context->builder->fmul($ninetysecondF29, $nF29);
            $ninetysecondF29 = $context->builder->fmul($ninetysecondF29, $nF29);
            $ninetysecondF29 = $context->builder->fmul($ninetysecondF29, $nF29);
            $ninetysecondF29 = $context->builder->fmul($ninetysecondF29, $nF29);
            $ninetysecondF29 = $context->builder->fmul($ninetysecondF29, $nF29);

            $ninetysecondF29 = $context->builder->fmul($ninetysecondF29, $nF29);
            $ninetysecondF29 = $context->builder->fmul($ninetysecondF29, $nF29);
            $ninetysecondF29 = $context->builder->fmul($ninetysecondF29, $nF29);
            $ninetysecondF29 = $context->builder->fmul($ninetysecondF29, $nF29);
            $ninetysecondF29 = $context->builder->fmul($ninetysecondF29, $nF29);
            $ninetysecondF29 = $context->builder->fmul($ninetysecondF29, $nF29);
            $ninetysecondF29 = $context->builder->fmul($ninetysecondF29, $nF29);
            $ninetysecondF29 = $context->builder->fmul($ninetysecondF29, $nF29);
            $ninetysecondF29 = $context->builder->fmul($ninetysecondF29, $nF29);
            $ninetysecondF29 = $context->builder->fmul($ninetysecondF29, $nF29);
            $ninetysecondF29 = $context->builder->fmul($ninetysecondF29, $nF29);
            $ninetysecondF29 = $context->builder->fmul($ninetysecondF29, $nF29);
            $ninetysecondF29 = $context->builder->fmul($ninetysecondF29, $nF29);
            $ninetysecondF29 = $context->builder->fmul($ninetysecondF29, $nF29);
            $ninetysecondF29 = $context->builder->fmul($ninetysecondF29, $nF29);
            $ninetysecondF29 = $context->builder->fmul($ninetysecondF29, $nF29);
            $ninetysecondF29 = $context->builder->fmul($ninetysecondF29, $nF29);
            $ninetysecondF29 = $context->builder->fmul($ninetysecondF29, $nF29);
            $ninetysecondF29 = $context->builder->fmul($ninetysecondF29, $nF29);
            $ninetysecondF29 = $context->builder->fmul($ninetysecondF29, $nF29);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF29
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok29Block);
            $sixtyninthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixtyeighthLong,
                $n
            );
            $ov30 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixtyninthVar->longArithOverflowFlag);
            if (null === $ov30 || null === $sixtyninthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **92 expected smul overflow metadata (sixtyninth)');
            }
            $sixtyninthLong = JITVariable::KIND_VARIABLE === $sixtyninthVar->kind
                ? $context->builder->load($sixtyninthVar->value)
                : $sixtyninthVar->value;
            $ov30Block = BasicBlockHelper::append($context, 'pow_ninetysecond_sixtyninth_ov');
            $ok30Block = BasicBlockHelper::append($context, 'pow_ninetysecond_sixtyninth_ok');
            $context->builder->branchIf($ov30, $ov30Block, $ok30Block);

            $context->builder->positionAtEnd($ov30Block);
            $sixtyninthF30 = $context->builder->load($sixtyninthVar->longArithOverflowDoubleSlot);
            $nF30 = $context->builder->siToFp($n, $f64);
            $ninetysecondF30 = $context->builder->fmul($sixtyninthF30, $nF30);
            $ninetysecondF30 = $context->builder->fmul($ninetysecondF30, $nF30);
            $ninetysecondF30 = $context->builder->fmul($ninetysecondF30, $nF30);
            $ninetysecondF30 = $context->builder->fmul($ninetysecondF30, $nF30);
            $ninetysecondF30 = $context->builder->fmul($ninetysecondF30, $nF30);
            $ninetysecondF30 = $context->builder->fmul($ninetysecondF30, $nF30);
            $ninetysecondF30 = $context->builder->fmul($ninetysecondF30, $nF30);
            $ninetysecondF30 = $context->builder->fmul($ninetysecondF30, $nF30);
            $ninetysecondF30 = $context->builder->fmul($ninetysecondF30, $nF30);
            $ninetysecondF30 = $context->builder->fmul($ninetysecondF30, $nF30);
            $ninetysecondF30 = $context->builder->fmul($ninetysecondF30, $nF30);
            $ninetysecondF30 = $context->builder->fmul($ninetysecondF30, $nF30);
            $ninetysecondF30 = $context->builder->fmul($ninetysecondF30, $nF30);
            $ninetysecondF30 = $context->builder->fmul($ninetysecondF30, $nF30);
            $ninetysecondF30 = $context->builder->fmul($ninetysecondF30, $nF30);
            $ninetysecondF30 = $context->builder->fmul($ninetysecondF30, $nF30);
            $ninetysecondF30 = $context->builder->fmul($ninetysecondF30, $nF30);
            $ninetysecondF30 = $context->builder->fmul($ninetysecondF30, $nF30);
            $ninetysecondF30 = $context->builder->fmul($ninetysecondF30, $nF30);
            $ninetysecondF30 = $context->builder->fmul($ninetysecondF30, $nF30);
            $ninetysecondF30 = $context->builder->fmul($ninetysecondF30, $nF30);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF30
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok30Block);
            $seventiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixtyninthLong,
                $n
            );
            $ov31 = JitLongArithOverflow::loadOverflowFlagI1($context, $seventiethVar->longArithOverflowFlag);
            if (null === $ov31 || null === $seventiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **92 expected smul overflow metadata (seventieth)');
            }
            $seventiethLong = JITVariable::KIND_VARIABLE === $seventiethVar->kind
                ? $context->builder->load($seventiethVar->value)
                : $seventiethVar->value;
            $ov31Block = BasicBlockHelper::append($context, 'pow_ninetysecond_seventieth_ov');
            $ok31Block = BasicBlockHelper::append($context, 'pow_ninetysecond_seventieth_ok');
            $context->builder->branchIf($ov31, $ov31Block, $ok31Block);

            $context->builder->positionAtEnd($ov31Block);
            $seventiethF31 = $context->builder->load($seventiethVar->longArithOverflowDoubleSlot);
            $nF31 = $context->builder->siToFp($n, $f64);
            $ninetysecondF31 = $context->builder->fmul($seventiethF31, $nF31);
            $ninetysecondF31 = $context->builder->fmul($ninetysecondF31, $nF31);
            $ninetysecondF31 = $context->builder->fmul($ninetysecondF31, $nF31);
            $ninetysecondF31 = $context->builder->fmul($ninetysecondF31, $nF31);
            $ninetysecondF31 = $context->builder->fmul($ninetysecondF31, $nF31);
            $ninetysecondF31 = $context->builder->fmul($ninetysecondF31, $nF31);
            $ninetysecondF31 = $context->builder->fmul($ninetysecondF31, $nF31);
            $ninetysecondF31 = $context->builder->fmul($ninetysecondF31, $nF31);
            $ninetysecondF31 = $context->builder->fmul($ninetysecondF31, $nF31);
            $ninetysecondF31 = $context->builder->fmul($ninetysecondF31, $nF31);
            $ninetysecondF31 = $context->builder->fmul($ninetysecondF31, $nF31);
            $ninetysecondF31 = $context->builder->fmul($ninetysecondF31, $nF31);
            $ninetysecondF31 = $context->builder->fmul($ninetysecondF31, $nF31);
            $ninetysecondF31 = $context->builder->fmul($ninetysecondF31, $nF31);
            $ninetysecondF31 = $context->builder->fmul($ninetysecondF31, $nF31);
            $ninetysecondF31 = $context->builder->fmul($ninetysecondF31, $nF31);
            $ninetysecondF31 = $context->builder->fmul($ninetysecondF31, $nF31);
            $ninetysecondF31 = $context->builder->fmul($ninetysecondF31, $nF31);
            $ninetysecondF31 = $context->builder->fmul($ninetysecondF31, $nF31);
            $ninetysecondF31 = $context->builder->fmul($ninetysecondF31, $nF31);
            $ninetysecondF31 = $context->builder->fmul($ninetysecondF31, $nF31);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF31
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok31Block);
            $seventyfirstVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $seventiethLong,
                $n
            );
            $ov32 = JitLongArithOverflow::loadOverflowFlagI1($context, $seventyfirstVar->longArithOverflowFlag);
            if (null === $ov32 || null === $seventyfirstVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **92 expected smul overflow metadata (seventyfirst)');
            }
            $seventyfirstLong = JITVariable::KIND_VARIABLE === $seventyfirstVar->kind
                ? $context->builder->load($seventyfirstVar->value)
                : $seventyfirstVar->value;
            $ov32Block = BasicBlockHelper::append($context, 'pow_ninetysecond_seventyfirst_ov');
            $ok32Block = BasicBlockHelper::append($context, 'pow_ninetysecond_seventyfirst_ok');
            $context->builder->branchIf($ov32, $ov32Block, $ok32Block);

            $context->builder->positionAtEnd($ov32Block);
            $seventyfirstF32 = $context->builder->load($seventyfirstVar->longArithOverflowDoubleSlot);
            $nF32 = $context->builder->siToFp($n, $f64);
            $ninetysecondF32 = $context->builder->fmul($seventyfirstF32, $nF32);
            $ninetysecondF32 = $context->builder->fmul($ninetysecondF32, $nF32);
            $ninetysecondF32 = $context->builder->fmul($ninetysecondF32, $nF32);
            $ninetysecondF32 = $context->builder->fmul($ninetysecondF32, $nF32);
            $ninetysecondF32 = $context->builder->fmul($ninetysecondF32, $nF32);
            $ninetysecondF32 = $context->builder->fmul($ninetysecondF32, $nF32);
            $ninetysecondF32 = $context->builder->fmul($ninetysecondF32, $nF32);
            $ninetysecondF32 = $context->builder->fmul($ninetysecondF32, $nF32);
            $ninetysecondF32 = $context->builder->fmul($ninetysecondF32, $nF32);
            $ninetysecondF32 = $context->builder->fmul($ninetysecondF32, $nF32);
            $ninetysecondF32 = $context->builder->fmul($ninetysecondF32, $nF32);
            $ninetysecondF32 = $context->builder->fmul($ninetysecondF32, $nF32);
            $ninetysecondF32 = $context->builder->fmul($ninetysecondF32, $nF32);
            $ninetysecondF32 = $context->builder->fmul($ninetysecondF32, $nF32);
            $ninetysecondF32 = $context->builder->fmul($ninetysecondF32, $nF32);
            $ninetysecondF32 = $context->builder->fmul($ninetysecondF32, $nF32);
            $ninetysecondF32 = $context->builder->fmul($ninetysecondF32, $nF32);
            $ninetysecondF32 = $context->builder->fmul($ninetysecondF32, $nF32);
            $ninetysecondF32 = $context->builder->fmul($ninetysecondF32, $nF32);
            $ninetysecondF32 = $context->builder->fmul($ninetysecondF32, $nF32);
            $ninetysecondF32 = $context->builder->fmul($ninetysecondF32, $nF32);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF32
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok32Block);
            $seventysecondVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $seventyfirstLong,
                $n
            );
            $ov33 = JitLongArithOverflow::loadOverflowFlagI1($context, $seventysecondVar->longArithOverflowFlag);
            if (null === $ov33 || null === $seventysecondVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **92 expected smul overflow metadata (seventysecond)');
            }
            $seventysecondLong = JITVariable::KIND_VARIABLE === $seventysecondVar->kind
                ? $context->builder->load($seventysecondVar->value)
                : $seventysecondVar->value;
            $ov33Block = BasicBlockHelper::append($context, 'pow_ninetysecond_seventysecond_ov');
            $ok33Block = BasicBlockHelper::append($context, 'pow_ninetysecond_seventysecond_ok');
            $context->builder->branchIf($ov33, $ov33Block, $ok33Block);

            $context->builder->positionAtEnd($ov33Block);
            $seventysecondF33 = $context->builder->load($seventysecondVar->longArithOverflowDoubleSlot);
            $nF33 = $context->builder->siToFp($n, $f64);
            $ninetysecondF33 = $context->builder->fmul($seventysecondF33, $nF33);
            $ninetysecondF33 = $context->builder->fmul($seventysecondF33, $nF33);
            $ninetysecondF33 = $context->builder->fmul($ninetysecondF33, $nF33);
            $ninetysecondF33 = $context->builder->fmul($ninetysecondF33, $nF33);
            $ninetysecondF33 = $context->builder->fmul($ninetysecondF33, $nF33);
            $ninetysecondF33 = $context->builder->fmul($ninetysecondF33, $nF33);
            $ninetysecondF33 = $context->builder->fmul($ninetysecondF33, $nF33);
            $ninetysecondF33 = $context->builder->fmul($ninetysecondF33, $nF33);
            $ninetysecondF33 = $context->builder->fmul($ninetysecondF33, $nF33);
            $ninetysecondF33 = $context->builder->fmul($ninetysecondF33, $nF33);
            $ninetysecondF33 = $context->builder->fmul($ninetysecondF33, $nF33);
            $ninetysecondF33 = $context->builder->fmul($ninetysecondF33, $nF33);
            $ninetysecondF33 = $context->builder->fmul($ninetysecondF33, $nF33);
            $ninetysecondF33 = $context->builder->fmul($ninetysecondF33, $nF33);
            $ninetysecondF33 = $context->builder->fmul($ninetysecondF33, $nF33);
            $ninetysecondF33 = $context->builder->fmul($ninetysecondF33, $nF33);
            $ninetysecondF33 = $context->builder->fmul($ninetysecondF33, $nF33);
            $ninetysecondF33 = $context->builder->fmul($ninetysecondF33, $nF33);
            $ninetysecondF33 = $context->builder->fmul($ninetysecondF33, $nF33);
            $ninetysecondF33 = $context->builder->fmul($ninetysecondF33, $nF33);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF33
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok33Block);
            $seventythirdVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $seventysecondLong,
                $n
            );
            $ov34 = JitLongArithOverflow::loadOverflowFlagI1($context, $seventythirdVar->longArithOverflowFlag);
            if (null === $ov34 || null === $seventythirdVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **92 expected smul overflow metadata (seventythird)');
            }
            $seventythirdLong = JITVariable::KIND_VARIABLE === $seventythirdVar->kind
                ? $context->builder->load($seventythirdVar->value)
                : $seventythirdVar->value;
            $ov34Block = BasicBlockHelper::append($context, 'pow_ninetysecond_seventythird_ov');
            $ok34Block = BasicBlockHelper::append($context, 'pow_ninetysecond_seventythird_ok');
            $context->builder->branchIf($ov34, $ov34Block, $ok34Block);

            $context->builder->positionAtEnd($ov34Block);
            $seventythirdF34 = $context->builder->load($seventythirdVar->longArithOverflowDoubleSlot);
            $nF34 = $context->builder->siToFp($n, $f64);
            $ninetysecondF34 = $context->builder->fmul($seventythirdF34, $nF34);
            $ninetysecondF34 = $context->builder->fmul($ninetysecondF34, $nF34);
            $ninetysecondF34 = $context->builder->fmul($ninetysecondF34, $nF34);
            $ninetysecondF34 = $context->builder->fmul($ninetysecondF34, $nF34);
            $ninetysecondF34 = $context->builder->fmul($ninetysecondF34, $nF34);
            $ninetysecondF34 = $context->builder->fmul($ninetysecondF34, $nF34);
            $ninetysecondF34 = $context->builder->fmul($ninetysecondF34, $nF34);
            $ninetysecondF34 = $context->builder->fmul($ninetysecondF34, $nF34);
            $ninetysecondF34 = $context->builder->fmul($ninetysecondF34, $nF34);
            $ninetysecondF34 = $context->builder->fmul($ninetysecondF34, $nF34);
            $ninetysecondF34 = $context->builder->fmul($ninetysecondF34, $nF34);
            $ninetysecondF34 = $context->builder->fmul($ninetysecondF34, $nF34);
            $ninetysecondF34 = $context->builder->fmul($ninetysecondF34, $nF34);
            $ninetysecondF34 = $context->builder->fmul($ninetysecondF34, $nF34);
            $ninetysecondF34 = $context->builder->fmul($ninetysecondF34, $nF34);
            $ninetysecondF34 = $context->builder->fmul($ninetysecondF34, $nF34);
            $ninetysecondF34 = $context->builder->fmul($ninetysecondF34, $nF34);
            $ninetysecondF34 = $context->builder->fmul($ninetysecondF34, $nF34);
            $ninetysecondF34 = $context->builder->fmul($ninetysecondF34, $nF34);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF34
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok34Block);
            $seventyfourthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $seventythirdLong,
                $n
            );
            $ov35 = JitLongArithOverflow::loadOverflowFlagI1($context, $seventyfourthVar->longArithOverflowFlag);
            if (null === $ov35 || null === $seventyfourthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **92 expected smul overflow metadata (seventyfourth)');
            }
            $seventyfourthLong = JITVariable::KIND_VARIABLE === $seventyfourthVar->kind
                ? $context->builder->load($seventyfourthVar->value)
                : $seventyfourthVar->value;
            $ov35Block = BasicBlockHelper::append($context, 'pow_ninetysecond_seventyfourth_ov');
            $ok35Block = BasicBlockHelper::append($context, 'pow_ninetysecond_seventyfourth_ok');
            $context->builder->branchIf($ov35, $ov35Block, $ok35Block);

            $context->builder->positionAtEnd($ov35Block);
            $seventyfourthF35 = $context->builder->load($seventyfourthVar->longArithOverflowDoubleSlot);
            $nF35 = $context->builder->siToFp($n, $f64);
            $ninetysecondF35 = $context->builder->fmul($seventyfourthF35, $nF35);
            $ninetysecondF35 = $context->builder->fmul($seventyfourthF35, $nF35);
            $ninetysecondF35 = $context->builder->fmul($ninetysecondF35, $nF35);
            $ninetysecondF35 = $context->builder->fmul($ninetysecondF35, $nF35);
            $ninetysecondF35 = $context->builder->fmul($ninetysecondF35, $nF35);
            $ninetysecondF35 = $context->builder->fmul($ninetysecondF35, $nF35);
            $ninetysecondF35 = $context->builder->fmul($ninetysecondF35, $nF35);
            $ninetysecondF35 = $context->builder->fmul($ninetysecondF35, $nF35);
            $ninetysecondF35 = $context->builder->fmul($ninetysecondF35, $nF35);
            $ninetysecondF35 = $context->builder->fmul($ninetysecondF35, $nF35);
            $ninetysecondF35 = $context->builder->fmul($ninetysecondF35, $nF35);
            $ninetysecondF35 = $context->builder->fmul($ninetysecondF35, $nF35);
            $ninetysecondF35 = $context->builder->fmul($ninetysecondF35, $nF35);
            $ninetysecondF35 = $context->builder->fmul($ninetysecondF35, $nF35);
            $ninetysecondF35 = $context->builder->fmul($ninetysecondF35, $nF35);
            $ninetysecondF35 = $context->builder->fmul($ninetysecondF35, $nF35);
            $ninetysecondF35 = $context->builder->fmul($ninetysecondF35, $nF35);
            $ninetysecondF35 = $context->builder->fmul($ninetysecondF35, $nF35);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF35
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok35Block);
            $seventyfifthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $seventyfourthLong,
                $n
            );
            $ov36 = JitLongArithOverflow::loadOverflowFlagI1($context, $seventyfifthVar->longArithOverflowFlag);
            if (null === $ov36 || null === $seventyfifthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **92 expected smul overflow metadata (seventyfifth)');
            }
            $seventyfifthLong = JITVariable::KIND_VARIABLE === $seventyfifthVar->kind
                ? $context->builder->load($seventyfifthVar->value)
                : $seventyfifthVar->value;
            $ov36Block = BasicBlockHelper::append($context, 'pow_ninetysecond_seventyfifth_ov');
            $ok36Block = BasicBlockHelper::append($context, 'pow_ninetysecond_seventyfifth_ok');
            $context->builder->branchIf($ov36, $ov36Block, $ok36Block);

            $context->builder->positionAtEnd($ov36Block);
            $seventyfifthF36 = $context->builder->load($seventyfifthVar->longArithOverflowDoubleSlot);
            $nF36 = $context->builder->siToFp($n, $f64);
            $ninetysecondF36 = $context->builder->fmul($seventyfifthF36, $nF36);
            $ninetysecondF36 = $context->builder->fmul($ninetysecondF36, $nF36);
            $ninetysecondF36 = $context->builder->fmul($ninetysecondF36, $nF36);
            $ninetysecondF36 = $context->builder->fmul($ninetysecondF36, $nF36);
            $ninetysecondF36 = $context->builder->fmul($ninetysecondF36, $nF36);
            $ninetysecondF36 = $context->builder->fmul($ninetysecondF36, $nF36);
            $ninetysecondF36 = $context->builder->fmul($ninetysecondF36, $nF36);
            $ninetysecondF36 = $context->builder->fmul($ninetysecondF36, $nF36);
            $ninetysecondF36 = $context->builder->fmul($ninetysecondF36, $nF36);
            $ninetysecondF36 = $context->builder->fmul($ninetysecondF36, $nF36);
            $ninetysecondF36 = $context->builder->fmul($ninetysecondF36, $nF36);
            $ninetysecondF36 = $context->builder->fmul($ninetysecondF36, $nF36);
            $ninetysecondF36 = $context->builder->fmul($ninetysecondF36, $nF36);
            $ninetysecondF36 = $context->builder->fmul($ninetysecondF36, $nF36);
            $ninetysecondF36 = $context->builder->fmul($ninetysecondF36, $nF36);
            $ninetysecondF36 = $context->builder->fmul($ninetysecondF36, $nF36);
            $ninetysecondF36 = $context->builder->fmul($ninetysecondF36, $nF36);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF36
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok36Block);
            $seventysixthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $seventyfifthLong,
                $n
            );
            $ov37 = JitLongArithOverflow::loadOverflowFlagI1($context, $seventysixthVar->longArithOverflowFlag);
            if (null === $ov37 || null === $seventysixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **92 expected smul overflow metadata (seventysixth)');
            }
            $seventysixthLong = JITVariable::KIND_VARIABLE === $seventysixthVar->kind
                ? $context->builder->load($seventysixthVar->value)
                : $seventysixthVar->value;
            $ov37Block = BasicBlockHelper::append($context, 'pow_ninetysecond_seventysixth_ov');
            $ok37Block = BasicBlockHelper::append($context, 'pow_ninetysecond_seventysixth_ok');
            $context->builder->branchIf($ov37, $ov37Block, $ok37Block);

            $context->builder->positionAtEnd($ov37Block);
            $seventysixthF37 = $context->builder->load($seventysixthVar->longArithOverflowDoubleSlot);
            $nF37 = $context->builder->siToFp($n, $f64);
            $ninetysecondF37 = $context->builder->fmul($seventysixthF37, $nF37);
            $ninetysecondF37 = $context->builder->fmul($ninetysecondF37, $nF37);
            $ninetysecondF37 = $context->builder->fmul($ninetysecondF37, $nF37);
            $ninetysecondF37 = $context->builder->fmul($ninetysecondF37, $nF37);
            $ninetysecondF37 = $context->builder->fmul($ninetysecondF37, $nF37);
            $ninetysecondF37 = $context->builder->fmul($ninetysecondF37, $nF37);
            $ninetysecondF37 = $context->builder->fmul($ninetysecondF37, $nF37);
            $ninetysecondF37 = $context->builder->fmul($ninetysecondF37, $nF37);
            $ninetysecondF37 = $context->builder->fmul($ninetysecondF37, $nF37);
            $ninetysecondF37 = $context->builder->fmul($ninetysecondF37, $nF37);
            $ninetysecondF37 = $context->builder->fmul($ninetysecondF37, $nF37);
            $ninetysecondF37 = $context->builder->fmul($ninetysecondF37, $nF37);
            $ninetysecondF37 = $context->builder->fmul($ninetysecondF37, $nF37);
            $ninetysecondF37 = $context->builder->fmul($ninetysecondF37, $nF37);
            $ninetysecondF37 = $context->builder->fmul($ninetysecondF37, $nF37);
            $ninetysecondF37 = $context->builder->fmul($ninetysecondF37, $nF37);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF37
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok37Block);
            $seventyseventhVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $seventysixthLong,
                $n
            );
            $ov38 = JitLongArithOverflow::loadOverflowFlagI1($context, $seventyseventhVar->longArithOverflowFlag);
            if (null === $ov38 || null === $seventyseventhVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **92 expected smul overflow metadata (seventyseventh)');
            }
            $seventyseventhLong = JITVariable::KIND_VARIABLE === $seventyseventhVar->kind
                ? $context->builder->load($seventyseventhVar->value)
                : $seventyseventhVar->value;
            $ov38Block = BasicBlockHelper::append($context, 'pow_ninetysecond_seventyseventh_ov');
            $ok38Block = BasicBlockHelper::append($context, 'pow_ninetysecond_seventyseventh_ok');
            $context->builder->branchIf($ov38, $ov38Block, $ok38Block);

            $context->builder->positionAtEnd($ov38Block);
            $seventyseventhF38 = $context->builder->load($seventyseventhVar->longArithOverflowDoubleSlot);
            $nF38 = $context->builder->siToFp($n, $f64);
            $ninetysecondF38 = $context->builder->fmul($seventyseventhF38, $nF38);
            $ninetysecondF38 = $context->builder->fmul($seventyseventhF38, $nF38);
            $ninetysecondF38 = $context->builder->fmul($ninetysecondF38, $nF38);
            $ninetysecondF38 = $context->builder->fmul($ninetysecondF38, $nF38);
            $ninetysecondF38 = $context->builder->fmul($ninetysecondF38, $nF38);
            $ninetysecondF38 = $context->builder->fmul($ninetysecondF38, $nF38);
            $ninetysecondF38 = $context->builder->fmul($ninetysecondF38, $nF38);
            $ninetysecondF38 = $context->builder->fmul($ninetysecondF38, $nF38);
            $ninetysecondF38 = $context->builder->fmul($ninetysecondF38, $nF38);
            $ninetysecondF38 = $context->builder->fmul($ninetysecondF38, $nF38);
            $ninetysecondF38 = $context->builder->fmul($ninetysecondF38, $nF38);
            $ninetysecondF38 = $context->builder->fmul($ninetysecondF38, $nF38);
            $ninetysecondF38 = $context->builder->fmul($ninetysecondF38, $nF38);
            $ninetysecondF38 = $context->builder->fmul($ninetysecondF38, $nF38);
            $ninetysecondF38 = $context->builder->fmul($ninetysecondF38, $nF38);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF38
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok38Block);
            $seventyeighthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $seventyseventhLong,
                $n
            );
            $ov39 = JitLongArithOverflow::loadOverflowFlagI1($context, $seventyeighthVar->longArithOverflowFlag);
            if (null === $ov39 || null === $seventyeighthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **92 expected smul overflow metadata (seventyeighth)');
            }
            $seventyeighthLong = JITVariable::KIND_VARIABLE === $seventyeighthVar->kind
                ? $context->builder->load($seventyeighthVar->value)
                : $seventyeighthVar->value;
            $ov39Block = BasicBlockHelper::append($context, 'pow_ninetysecond_seventyeighth_ov');
            $ok39Block = BasicBlockHelper::append($context, 'pow_ninetysecond_seventyeighth_ok');
            $context->builder->branchIf($ov39, $ov39Block, $ok39Block);

            $context->builder->positionAtEnd($ov39Block);
            $seventyeighthF39 = $context->builder->load($seventyeighthVar->longArithOverflowDoubleSlot);
            $nF39 = $context->builder->siToFp($n, $f64);
            $ninetysecondF39 = $context->builder->fmul($seventyeighthF39, $nF39);
            $ninetysecondF39 = $context->builder->fmul($ninetysecondF39, $nF39);
            $ninetysecondF39 = $context->builder->fmul($ninetysecondF39, $nF39);
            $ninetysecondF39 = $context->builder->fmul($ninetysecondF39, $nF39);
            $ninetysecondF39 = $context->builder->fmul($ninetysecondF39, $nF39);
            $ninetysecondF39 = $context->builder->fmul($ninetysecondF39, $nF39);
            $ninetysecondF39 = $context->builder->fmul($ninetysecondF39, $nF39);
            $ninetysecondF39 = $context->builder->fmul($ninetysecondF39, $nF39);
            $ninetysecondF39 = $context->builder->fmul($ninetysecondF39, $nF39);
            $ninetysecondF39 = $context->builder->fmul($ninetysecondF39, $nF39);
            $ninetysecondF39 = $context->builder->fmul($ninetysecondF39, $nF39);
            $ninetysecondF39 = $context->builder->fmul($ninetysecondF39, $nF39);
            $ninetysecondF39 = $context->builder->fmul($ninetysecondF39, $nF39);
            $ninetysecondF39 = $context->builder->fmul($ninetysecondF39, $nF39);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF39
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok39Block);
            $seventyninthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $seventyeighthLong,
                $n
            );
            $ov40 = JitLongArithOverflow::loadOverflowFlagI1($context, $seventyninthVar->longArithOverflowFlag);
            if (null === $ov40 || null === $seventyninthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **92 expected smul overflow metadata (seventyninth)');
            }
            $seventyninthLong = JITVariable::KIND_VARIABLE === $seventyninthVar->kind
                ? $context->builder->load($seventyninthVar->value)
                : $seventyninthVar->value;
            $ov40Block = BasicBlockHelper::append($context, 'pow_ninetysecond_seventyninth_ov');
            $ok40Block = BasicBlockHelper::append($context, 'pow_ninetysecond_seventyninth_ok');
            $context->builder->branchIf($ov40, $ov40Block, $ok40Block);

            $context->builder->positionAtEnd($ov40Block);
            $seventyninthF40 = $context->builder->load($seventyninthVar->longArithOverflowDoubleSlot);
            $nF40 = $context->builder->siToFp($n, $f64);
            $ninetysecondF40 = $context->builder->fmul($seventyninthF40, $nF40);
            $ninetysecondF40 = $context->builder->fmul($ninetysecondF40, $nF40);
            $ninetysecondF40 = $context->builder->fmul($ninetysecondF40, $nF40);
            $ninetysecondF40 = $context->builder->fmul($ninetysecondF40, $nF40);
            $ninetysecondF40 = $context->builder->fmul($ninetysecondF40, $nF40);
            $ninetysecondF40 = $context->builder->fmul($ninetysecondF40, $nF40);
            $ninetysecondF40 = $context->builder->fmul($ninetysecondF40, $nF40);
            $ninetysecondF40 = $context->builder->fmul($ninetysecondF40, $nF40);
            $ninetysecondF40 = $context->builder->fmul($ninetysecondF40, $nF40);
            $ninetysecondF40 = $context->builder->fmul($ninetysecondF40, $nF40);
            $ninetysecondF40 = $context->builder->fmul($ninetysecondF40, $nF40);
            $ninetysecondF40 = $context->builder->fmul($ninetysecondF40, $nF40);
            $ninetysecondF40 = $context->builder->fmul($ninetysecondF40, $nF40);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF40
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok40Block);
            $eightiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $seventyninthLong,
                $n
            );
            $ov41 = JitLongArithOverflow::loadOverflowFlagI1($context, $eightiethVar->longArithOverflowFlag);
            if (null === $ov41 || null === $eightiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **92 expected smul overflow metadata (eightieth)');
            }
            $eightiethLong = JITVariable::KIND_VARIABLE === $eightiethVar->kind
                ? $context->builder->load($eightiethVar->value)
                : $eightiethVar->value;
            $ov41Block = BasicBlockHelper::append($context, 'pow_ninetysecond_eightieth_ov');
            $ok41Block = BasicBlockHelper::append($context, 'pow_ninetysecond_eightieth_ok');
            $context->builder->branchIf($ov41, $ov41Block, $ok41Block);

            $context->builder->positionAtEnd($ov41Block);
            $eightiethF41 = $context->builder->load($eightiethVar->longArithOverflowDoubleSlot);
            $nF41 = $context->builder->siToFp($n, $f64);
            $ninetysecondF41 = $context->builder->fmul($eightiethF41, $nF41);
            $ninetysecondF41 = $context->builder->fmul($ninetysecondF41, $nF41);
            $ninetysecondF41 = $context->builder->fmul($ninetysecondF41, $nF41);
            $ninetysecondF41 = $context->builder->fmul($ninetysecondF41, $nF41);
            $ninetysecondF41 = $context->builder->fmul($ninetysecondF41, $nF41);
            $ninetysecondF41 = $context->builder->fmul($ninetysecondF41, $nF41);
            $ninetysecondF41 = $context->builder->fmul($ninetysecondF41, $nF41);
            $ninetysecondF41 = $context->builder->fmul($ninetysecondF41, $nF41);
            $ninetysecondF41 = $context->builder->fmul($ninetysecondF41, $nF41);
            $ninetysecondF41 = $context->builder->fmul($ninetysecondF41, $nF41);
            $ninetysecondF41 = $context->builder->fmul($ninetysecondF41, $nF41);
            $ninetysecondF41 = $context->builder->fmul($ninetysecondF41, $nF41);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF41
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok41Block);
            $eightyfirstVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $eightiethLong,
                $n
            );
            $ov42 = JitLongArithOverflow::loadOverflowFlagI1($context, $eightyfirstVar->longArithOverflowFlag);
            if (null === $ov42 || null === $eightyfirstVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **92 expected smul overflow metadata (eightyfirst)');
            }
            $eightyfirstLong = JITVariable::KIND_VARIABLE === $eightyfirstVar->kind
                ? $context->builder->load($eightyfirstVar->value)
                : $eightyfirstVar->value;
            $ov42Block = BasicBlockHelper::append($context, 'pow_ninetysecond_eightyfirst_ov');
            $ok42Block = BasicBlockHelper::append($context, 'pow_ninetysecond_eightyfirst_ok');
            $context->builder->branchIf($ov42, $ov42Block, $ok42Block);

            $context->builder->positionAtEnd($ov42Block);
            $eightyfirstF42 = $context->builder->load($eightyfirstVar->longArithOverflowDoubleSlot);
            $nF42 = $context->builder->siToFp($n, $f64);
            $ninetysecondF42 = $context->builder->fmul($eightyfirstF42, $nF42);
            $ninetysecondF42 = $context->builder->fmul($ninetysecondF42, $nF42);
            $ninetysecondF42 = $context->builder->fmul($ninetysecondF42, $nF42);
            $ninetysecondF42 = $context->builder->fmul($ninetysecondF42, $nF42);
            $ninetysecondF42 = $context->builder->fmul($ninetysecondF42, $nF42);
            $ninetysecondF42 = $context->builder->fmul($ninetysecondF42, $nF42);
            $ninetysecondF42 = $context->builder->fmul($ninetysecondF42, $nF42);
            $ninetysecondF42 = $context->builder->fmul($ninetysecondF42, $nF42);
            $ninetysecondF42 = $context->builder->fmul($ninetysecondF42, $nF42);
            $ninetysecondF42 = $context->builder->fmul($ninetysecondF42, $nF42);
            $ninetysecondF42 = $context->builder->fmul($ninetysecondF42, $nF42);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF42
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok42Block);
            $eightysecondVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $eightyfirstLong,
                $n
            );
            $ov43 = JitLongArithOverflow::loadOverflowFlagI1($context, $eightysecondVar->longArithOverflowFlag);
            if (null === $ov43 || null === $eightysecondVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **92 expected smul overflow metadata (eightysecond)');
            }
            $eightysecondLong = JITVariable::KIND_VARIABLE === $eightysecondVar->kind
                ? $context->builder->load($eightysecondVar->value)
                : $eightysecondVar->value;
            $ov43Block = BasicBlockHelper::append($context, 'pow_ninetysecond_eightysecond_ov');
            $ok43Block = BasicBlockHelper::append($context, 'pow_ninetysecond_eightysecond_ok');
            $context->builder->branchIf($ov43, $ov43Block, $ok43Block);

            $context->builder->positionAtEnd($ov43Block);
            $eightysecondF43 = $context->builder->load($eightysecondVar->longArithOverflowDoubleSlot);
            $nF43 = $context->builder->siToFp($n, $f64);
            $ninetysecondF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $ninetysecondF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $ninetysecondF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $ninetysecondF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $ninetysecondF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $ninetysecondF43 = $context->builder->fmul($ninetysecondF43, $nF43);
            $ninetysecondF43 = $context->builder->fmul($ninetysecondF43, $nF43);
            $ninetysecondF43 = $context->builder->fmul($ninetysecondF43, $nF43);
            $ninetysecondF43 = $context->builder->fmul($ninetysecondF43, $nF43);
            $ninetysecondF43 = $context->builder->fmul($ninetysecondF43, $nF43);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF43
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok43Block);
            $eightythirdVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $eightysecondLong,
                $n
            );
            $ov44 = JitLongArithOverflow::loadOverflowFlagI1($context, $eightythirdVar->longArithOverflowFlag);
            if (null === $ov44 || null === $eightythirdVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **92 expected smul overflow metadata (eightythird)');
            }
            $eightythirdLong = JITVariable::KIND_VARIABLE === $eightythirdVar->kind
                ? $context->builder->load($eightythirdVar->value)
                : $eightythirdVar->value;
            $ov44Block = BasicBlockHelper::append($context, 'pow_ninetysecond_eightythird_ov');
            $ok44Block = BasicBlockHelper::append($context, 'pow_ninetysecond_eightythird_ok');
            $context->builder->branchIf($ov44, $ov44Block, $ok44Block);

            $context->builder->positionAtEnd($ov44Block);
            $eightythirdF44 = $context->builder->load($eightythirdVar->longArithOverflowDoubleSlot);
            $nF44 = $context->builder->siToFp($n, $f64);
            $ninetysecondF44 = $context->builder->fmul($eightythirdF44, $nF44);
            $ninetysecondF44 = $context->builder->fmul($eightythirdF44, $nF44);
            $ninetysecondF44 = $context->builder->fmul($eightythirdF44, $nF44);
            $ninetysecondF44 = $context->builder->fmul($eightythirdF44, $nF44);
            $ninetysecondF44 = $context->builder->fmul($ninetysecondF44, $nF44);
            $ninetysecondF44 = $context->builder->fmul($ninetysecondF44, $nF44);
            $ninetysecondF44 = $context->builder->fmul($ninetysecondF44, $nF44);
            $ninetysecondF44 = $context->builder->fmul($ninetysecondF44, $nF44);
            $ninetysecondF44 = $context->builder->fmul($ninetysecondF44, $nF44);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF44
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok44Block);
            $eightyfourthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $eightythirdLong,
                $n
            );
            $ov45 = JitLongArithOverflow::loadOverflowFlagI1($context, $eightyfourthVar->longArithOverflowFlag);
            if (null === $ov45 || null === $eightyfourthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **92 expected smul overflow metadata (eightyfourth)');
            }
            $eightyfourthLong = JITVariable::KIND_VARIABLE === $eightyfourthVar->kind
                ? $context->builder->load($eightyfourthVar->value)
                : $eightyfourthVar->value;
            $ov45Block = BasicBlockHelper::append($context, 'pow_ninetysecond_eightyfourth_ov');
            $ok45Block = BasicBlockHelper::append($context, 'pow_ninetysecond_eightyfourth_ok');
            $context->builder->branchIf($ov45, $ov45Block, $ok45Block);

            $context->builder->positionAtEnd($ov45Block);
            $eightyfourthF45 = $context->builder->load($eightyfourthVar->longArithOverflowDoubleSlot);
            $nF45 = $context->builder->siToFp($n, $f64);
            $ninetysecondF45 = $context->builder->fmul($eightyfourthF45, $nF45);
            $ninetysecondF45 = $context->builder->fmul($eightyfourthF45, $nF45);
            $ninetysecondF45 = $context->builder->fmul($eightyfourthF45, $nF45);
            $ninetysecondF45 = $context->builder->fmul($ninetysecondF45, $nF45);
            $ninetysecondF45 = $context->builder->fmul($ninetysecondF45, $nF45);
            $ninetysecondF45 = $context->builder->fmul($ninetysecondF45, $nF45);
            $ninetysecondF45 = $context->builder->fmul($ninetysecondF45, $nF45);
            $ninetysecondF45 = $context->builder->fmul($ninetysecondF45, $nF45);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF45
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok45Block);
            $eightyfifthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $eightyfourthLong,
                $n
            );
            $ov46 = JitLongArithOverflow::loadOverflowFlagI1($context, $eightyfifthVar->longArithOverflowFlag);
            if (null === $ov46 || null === $eightyfifthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **92 expected smul overflow metadata (eightyfifth)');
            }
            $eightyfifthLong = JITVariable::KIND_VARIABLE === $eightyfifthVar->kind
                ? $context->builder->load($eightyfifthVar->value)
                : $eightyfifthVar->value;
            $ov46Block = BasicBlockHelper::append($context, 'pow_ninetysecond_eightyfifth_ov');
            $ok46Block = BasicBlockHelper::append($context, 'pow_ninetysecond_eightyfifth_ok');
            $context->builder->branchIf($ov46, $ov46Block, $ok46Block);

            $context->builder->positionAtEnd($ov46Block);
            $eightyfifthF46 = $context->builder->load($eightyfifthVar->longArithOverflowDoubleSlot);
            $nF46 = $context->builder->siToFp($n, $f64);
            $ninetysecondF46 = $context->builder->fmul($eightyfifthF46, $nF46);
            $ninetysecondF46 = $context->builder->fmul($eightyfifthF46, $nF46);
            $ninetysecondF46 = $context->builder->fmul($ninetysecondF46, $nF46);
            $ninetysecondF46 = $context->builder->fmul($ninetysecondF46, $nF46);
            $ninetysecondF46 = $context->builder->fmul($ninetysecondF46, $nF46);
            $ninetysecondF46 = $context->builder->fmul($ninetysecondF46, $nF46);
            $ninetysecondF46 = $context->builder->fmul($ninetysecondF46, $nF46);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF46
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok46Block);
            $eightysixthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $eightyfifthLong,
                $n
            );
            $ov47 = JitLongArithOverflow::loadOverflowFlagI1($context, $eightysixthVar->longArithOverflowFlag);
            if (null === $ov47 || null === $eightysixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **92 expected smul overflow metadata (eightysixth)');
            }
            $eightysixthLong = JITVariable::KIND_VARIABLE === $eightysixthVar->kind
                ? $context->builder->load($eightysixthVar->value)
                : $eightysixthVar->value;
            $ov47Block = BasicBlockHelper::append($context, 'pow_ninetysecond_eightysixth_ov');
            $ok47Block = BasicBlockHelper::append($context, 'pow_ninetysecond_eightysixth_ok');
            $context->builder->branchIf($ov47, $ov47Block, $ok47Block);

            $context->builder->positionAtEnd($ov47Block);
            $eightysixthF47 = $context->builder->load($eightysixthVar->longArithOverflowDoubleSlot);
            $nF47 = $context->builder->siToFp($n, $f64);
            $ninetysecondF47 = $context->builder->fmul($eightysixthF47, $nF47);
            $ninetysecondF47 = $context->builder->fmul($ninetysecondF47, $nF47);
            $ninetysecondF47 = $context->builder->fmul($ninetysecondF47, $nF47);
            $ninetysecondF47 = $context->builder->fmul($ninetysecondF47, $nF47);
            $ninetysecondF47 = $context->builder->fmul($ninetysecondF47, $nF47);
            $ninetysecondF47 = $context->builder->fmul($ninetysecondF47, $nF47);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF47
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok47Block);

            $eightyseventhVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $eightysixthLong,
                $n
            );
            $ov48 = JitLongArithOverflow::loadOverflowFlagI1($context, $eightyseventhVar->longArithOverflowFlag);
            if (null === $ov48 || null === $eightyseventhVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **92 expected smul overflow metadata (eightyseventh)');
            }
            $eightyseventhLong = JITVariable::KIND_VARIABLE === $eightyseventhVar->kind
                ? $context->builder->load($eightyseventhVar->value)
                : $eightyseventhVar->value;
            $ov48Block = BasicBlockHelper::append($context, 'pow_ninetysecond_eightyseventh_ov');
            $ok48Block = BasicBlockHelper::append($context, 'pow_ninetysecond_eightyseventh_ok');
            $context->builder->branchIf($ov48, $ov48Block, $ok48Block);

            $context->builder->positionAtEnd($ov48Block);
            $eightyseventhF48 = $context->builder->load($eightyseventhVar->longArithOverflowDoubleSlot);
            $nF48 = $context->builder->siToFp($n, $f64);
            $ninetysecondF48 = $context->builder->fmul($eightyseventhF48, $nF48);
            $ninetysecondF48 = $context->builder->fmul($eightyseventhF48, $nF48);
            $ninetysecondF48 = $context->builder->fmul($ninetysecondF48, $nF48);
            $ninetysecondF48 = $context->builder->fmul($ninetysecondF48, $nF48);
            $ninetysecondF48 = $context->builder->fmul($ninetysecondF48, $nF48);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF48
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok48Block);
            $eightyeighthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $eightyseventhLong,
                $n
            );
            $ov49 = JitLongArithOverflow::loadOverflowFlagI1($context, $eightyeighthVar->longArithOverflowFlag);
            if (null === $ov49 || null === $eightyeighthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **92 expected smul overflow metadata (eightyeighth)');
            }
            $eightyeighthLong = JITVariable::KIND_VARIABLE === $eightyeighthVar->kind
                ? $context->builder->load($eightyeighthVar->value)
                : $eightyeighthVar->value;
            $ov49Block = BasicBlockHelper::append($context, 'pow_ninetysecond_eightyeighth_ov');
            $ok49Block = BasicBlockHelper::append($context, 'pow_ninetysecond_eightyeighth_ok');
            $context->builder->branchIf($ov49, $ov49Block, $ok49Block);

            $context->builder->positionAtEnd($ov49Block);
            $eightyeighthF49 = $context->builder->load($eightyeighthVar->longArithOverflowDoubleSlot);
            $nF49 = $context->builder->siToFp($n, $f64);
            $ninetysecondF49 = $context->builder->fmul($eightyeighthF49, $nF49);
            $ninetysecondF49 = $context->builder->fmul($ninetysecondF49, $nF49);
            $ninetysecondF49 = $context->builder->fmul($ninetysecondF49, $nF49);
            $ninetysecondF49 = $context->builder->fmul($ninetysecondF49, $nF49);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF49
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok49Block);
            $eightyninthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $eightyeighthLong,
                $n
            );
            $ov50 = JitLongArithOverflow::loadOverflowFlagI1($context, $eightyninthVar->longArithOverflowFlag);
            if (null === $ov50 || null === $eightyninthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **92 expected smul overflow metadata (eightyninth)');
            }
            $eightyninthLong = JITVariable::KIND_VARIABLE === $eightyninthVar->kind
                ? $context->builder->load($eightyninthVar->value)
                : $eightyninthVar->value;
            $ov50Block = BasicBlockHelper::append($context, 'pow_ninetysecond_eightyninth_ov');
            $ok50Block = BasicBlockHelper::append($context, 'pow_ninetysecond_eightyninth_ok');
            $context->builder->branchIf($ov50, $ov50Block, $ok50Block);

            $context->builder->positionAtEnd($ov50Block);
            $eightyninthF50 = $context->builder->load($eightyninthVar->longArithOverflowDoubleSlot);
            $nF50 = $context->builder->siToFp($n, $f64);
            $ninetysecondF50 = $context->builder->fmul($eightyninthF50, $nF50);
            $ninetysecondF50 = $context->builder->fmul($ninetysecondF50, $nF50);
            $ninetysecondF50 = $context->builder->fmul($ninetysecondF50, $nF50);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF50
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok50Block);
            $ninetiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $eightyninthLong,
                $n
            );
            $ov51 = JitLongArithOverflow::loadOverflowFlagI1($context, $ninetiethVar->longArithOverflowFlag);
            if (null === $ov51 || null === $ninetiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **92 expected smul overflow metadata (ninetieth)');
            }
            $ninetiethLong = JITVariable::KIND_VARIABLE === $ninetiethVar->kind
                ? $context->builder->load($ninetiethVar->value)
                : $ninetiethVar->value;
            $ov51Block = BasicBlockHelper::append($context, 'pow_ninetysecond_ninetieth_ov');
            $ok51Block = BasicBlockHelper::append($context, 'pow_ninetysecond_ninetieth_ok');
            $context->builder->branchIf($ov51, $ov51Block, $ok51Block);

            $context->builder->positionAtEnd($ov51Block);
            $ninetiethF51 = $context->builder->load($ninetiethVar->longArithOverflowDoubleSlot);
            $nF51 = $context->builder->siToFp($n, $f64);
            $ninetysecondF51 = $context->builder->fmul($ninetiethF51, $nF51);
            $ninetysecondF51 = $context->builder->fmul($ninetysecondF51, $nF51);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF51
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok51Block);
            $ninetyfirstVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $ninetiethLong,
                $n
            );
            $ov52 = JitLongArithOverflow::loadOverflowFlagI1($context, $ninetyfirstVar->longArithOverflowFlag);
            if (null === $ov52 || null === $ninetyfirstVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **92 expected smul overflow metadata (ninetyfirst)');
            }
            $ninetyfirstLong = JITVariable::KIND_VARIABLE === $ninetyfirstVar->kind
                ? $context->builder->load($ninetyfirstVar->value)
                : $ninetyfirstVar->value;
            $ov52Block = BasicBlockHelper::append($context, 'pow_ninetysecond_ninetyfirst_ov');
            $ok52Block = BasicBlockHelper::append($context, 'pow_ninetysecond_ninetyfirst_ok');
            $context->builder->branchIf($ov52, $ov52Block, $ok52Block);

            $context->builder->positionAtEnd($ov52Block);
            $ninetyfirstF52 = $context->builder->load($ninetyfirstVar->longArithOverflowDoubleSlot);
            $nF52 = $context->builder->siToFp($n, $f64);
            $ninetysecondF52 = $context->builder->fmul($ninetyfirstF52, $nF52);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetysecondF52
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok52Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $ninetyfirstLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);


            $context->builder->positionAtEnd($doneBlock);

            return true;
        }
if ('ninetythird' === $expFold) {
            // n^91 = ninetieth*n; overflow arms +1 ×nF vs **90.
            // tenth=fifth*fifth, twentieth=tenth*tenth,
            // fortieth=twentieth*twentieth, fortysecond=fortieth*sq, fortyfourth=fortysecond*sq,
            // fortysixth=fortyfourth*sq, then fortysixth*sq*sq*n… (sixtyseventh×n).
            // Overflow arms finish in float with one extra ×nF vs **91.
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
                throw new \LogicException('pow() **93 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_ninetythird_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_ninetythird_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_ninetythird_done');
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
            $ninetythirdFSq = $context->builder->fmul($fortyfourthF, $sqF);
            $ninetythirdFEighth = $context->builder->fmul($ninetythirdFSq, $sqF);
            $ninetythirdF = $context->builder->fmul($ninetythirdFEighth, $sqF);
            $nF = $context->builder->siToFp($n, $f64);
            $ninetythirdF = $context->builder->fmul($ninetythirdF, $nF);
            $ninetythirdF = $context->builder->fmul($ninetythirdF, $nF);
            $ninetythirdF = $context->builder->fmul($ninetythirdF, $nF);
            $ninetythirdF = $context->builder->fmul($ninetythirdF, $nF);
            $ninetythirdF = $context->builder->fmul($ninetythirdF, $nF);
            $ninetythirdF = $context->builder->fmul($ninetythirdF, $nF);
            $ninetythirdF = $context->builder->fmul($ninetythirdF, $nF);
            $ninetythirdF = $context->builder->fmul($ninetythirdF, $nF);
            $ninetythirdF = $context->builder->fmul($ninetythirdF, $nF);
            $ninetythirdF = $context->builder->fmul($ninetythirdF, $nF);
                $ninetythirdF = $context->builder->fmul($ninetythirdF, $nF);
                    $ninetythirdF = $context->builder->fmul($ninetythirdF, $nF);
                        $ninetythirdF = $context->builder->fmul($ninetythirdF, $nF);
                        $ninetythirdF = $context->builder->fmul($ninetythirdF, $nF);
                        $ninetythirdF = $context->builder->fmul($ninetythirdF, $nF);
                        $ninetythirdF = $context->builder->fmul($ninetythirdF, $nF);
                        $ninetythirdF = $context->builder->fmul($ninetythirdF, $nF);
                        $ninetythirdF = $context->builder->fmul($ninetythirdF, $nF);
                        $ninetythirdF = $context->builder->fmul($ninetythirdF, $nF);
            $ninetythirdF = $context->builder->fmul($ninetythirdF, $nF);
            $ninetythirdF = $context->builder->fmul($ninetythirdF, $nF);
            $ninetythirdF = $context->builder->fmul($ninetythirdF, $nF);
            $ninetythirdF = $context->builder->fmul($ninetythirdF, $nF);
            $ninetythirdF = $context->builder->fmul($ninetythirdF, $nF);

            $ninetythirdF = $context->builder->fmul($ninetythirdF, $nF);
            $ninetythirdF = $context->builder->fmul($ninetythirdF, $nF);
            $ninetythirdF = $context->builder->fmul($ninetythirdF, $nF);
            $ninetythirdF = $context->builder->fmul($ninetythirdF, $nF);
            $ninetythirdF = $context->builder->fmul($ninetythirdF, $nF);
            $ninetythirdF = $context->builder->fmul($ninetythirdF, $nF);
            $ninetythirdF = $context->builder->fmul($ninetythirdF, $nF);
            $ninetythirdF = $context->builder->fmul($ninetythirdF, $nF);
            $ninetythirdF = $context->builder->fmul($ninetythirdF, $nF);
            $ninetythirdF = $context->builder->fmul($ninetythirdF, $nF);
            $ninetythirdF = $context->builder->fmul($ninetythirdF, $nF);
            $ninetythirdF = $context->builder->fmul($ninetythirdF, $nF);
            $ninetythirdF = $context->builder->fmul($ninetythirdF, $nF);
            $ninetythirdF = $context->builder->fmul($ninetythirdF, $nF);
            $ninetythirdF = $context->builder->fmul($ninetythirdF, $nF);
            $ninetythirdF = $context->builder->fmul($ninetythirdF, $nF);
            $ninetythirdF = $context->builder->fmul($ninetythirdF, $nF);
            $ninetythirdF = $context->builder->fmul($ninetythirdF, $nF);
            $ninetythirdF = $context->builder->fmul($ninetythirdF, $nF);
            $ninetythirdF = $context->builder->fmul($ninetythirdF, $nF);
            $ninetythirdF = $context->builder->fmul($ninetythirdF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF
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
                throw new \LogicException('pow() **93 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_ninetythird_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_ninetythird_cu_ok');
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
            $ninetythirdF2Sq = $context->builder->fmul($fortyfourthF2, $sqF2);
            $ninetythirdF2Eighth = $context->builder->fmul($ninetythirdF2Sq, $sqF2);
            $ninetythirdF2 = $context->builder->fmul($ninetythirdF2Eighth, $sqF2);
            $nF2 = $context->builder->siToFp($n, $f64);
            $ninetythirdF2 = $context->builder->fmul($ninetythirdF2, $nF2);
            $ninetythirdF2 = $context->builder->fmul($ninetythirdF2, $nF2);
            $ninetythirdF2 = $context->builder->fmul($ninetythirdF2, $nF2);
            $ninetythirdF2 = $context->builder->fmul($ninetythirdF2, $nF2);
            $ninetythirdF2 = $context->builder->fmul($ninetythirdF2, $nF2);
            $ninetythirdF2 = $context->builder->fmul($ninetythirdF2, $nF2);
            $ninetythirdF2 = $context->builder->fmul($ninetythirdF2, $nF2);
            $ninetythirdF2 = $context->builder->fmul($ninetythirdF2, $nF2);
            $ninetythirdF2 = $context->builder->fmul($ninetythirdF2, $nF2);
            $ninetythirdF2 = $context->builder->fmul($ninetythirdF2, $nF2);
                $ninetythirdF2 = $context->builder->fmul($ninetythirdF2, $nF2);
                    $ninetythirdF2 = $context->builder->fmul($ninetythirdF2, $nF2);
                        $ninetythirdF2 = $context->builder->fmul($ninetythirdF2, $nF2);
                        $ninetythirdF2 = $context->builder->fmul($ninetythirdF2, $nF2);
                        $ninetythirdF2 = $context->builder->fmul($ninetythirdF2, $nF2);
                        $ninetythirdF2 = $context->builder->fmul($ninetythirdF2, $nF2);
                        $ninetythirdF2 = $context->builder->fmul($ninetythirdF2, $nF2);
                        $ninetythirdF2 = $context->builder->fmul($ninetythirdF2, $nF2);
                        $ninetythirdF2 = $context->builder->fmul($ninetythirdF2, $nF2);
            $ninetythirdF2 = $context->builder->fmul($ninetythirdF2, $nF2);
            $ninetythirdF2 = $context->builder->fmul($ninetythirdF2, $nF2);
            $ninetythirdF2 = $context->builder->fmul($ninetythirdF2, $nF2);
            $ninetythirdF2 = $context->builder->fmul($ninetythirdF2, $nF2);
            $ninetythirdF2 = $context->builder->fmul($ninetythirdF2, $nF2);

            $ninetythirdF2 = $context->builder->fmul($ninetythirdF2, $nF2);
            $ninetythirdF2 = $context->builder->fmul($ninetythirdF2, $nF2);
            $ninetythirdF2 = $context->builder->fmul($ninetythirdF2, $nF2);
            $ninetythirdF2 = $context->builder->fmul($ninetythirdF2, $nF2);
            $ninetythirdF2 = $context->builder->fmul($ninetythirdF2, $nF2);
            $ninetythirdF2 = $context->builder->fmul($ninetythirdF2, $nF2);
            $ninetythirdF2 = $context->builder->fmul($ninetythirdF2, $nF2);
            $ninetythirdF2 = $context->builder->fmul($ninetythirdF2, $nF2);
            $ninetythirdF2 = $context->builder->fmul($ninetythirdF2, $nF2);
            $ninetythirdF2 = $context->builder->fmul($ninetythirdF2, $nF2);
            $ninetythirdF2 = $context->builder->fmul($ninetythirdF2, $nF2);
            $ninetythirdF2 = $context->builder->fmul($ninetythirdF2, $nF2);
            $ninetythirdF2 = $context->builder->fmul($ninetythirdF2, $nF2);
            $ninetythirdF2 = $context->builder->fmul($ninetythirdF2, $nF2);
            $ninetythirdF2 = $context->builder->fmul($ninetythirdF2, $nF2);
            $ninetythirdF2 = $context->builder->fmul($ninetythirdF2, $nF2);
            $ninetythirdF2 = $context->builder->fmul($ninetythirdF2, $nF2);
            $ninetythirdF2 = $context->builder->fmul($ninetythirdF2, $nF2);
            $ninetythirdF2 = $context->builder->fmul($ninetythirdF2, $nF2);
            $ninetythirdF2 = $context->builder->fmul($ninetythirdF2, $nF2);
            $ninetythirdF2 = $context->builder->fmul($ninetythirdF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF2
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
                throw new \LogicException('pow() **93 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_ninetythird_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_ninetythird_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $twentiethF3 = $context->builder->fmul($tenthF3, $tenthF3);
            $fortiethF3 = $context->builder->fmul($twentiethF3, $twentiethF3);
            $sqF3 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF3 = $context->builder->fmul($fortiethF3, $sqF3);
            $fortyfourthF3 = $context->builder->fmul($fortysecondF3, $sqF3);
            $ninetythirdF3Sq = $context->builder->fmul($fortyfourthF3, $sqF3);
            $ninetythirdF3Eighth = $context->builder->fmul($ninetythirdF3Sq, $sqF3);
            $ninetythirdF3 = $context->builder->fmul($ninetythirdF3Eighth, $sqF3);
            $nF3 = $context->builder->siToFp($n, $f64);
            $ninetythirdF3 = $context->builder->fmul($ninetythirdF3, $nF3);
            $ninetythirdF3 = $context->builder->fmul($ninetythirdF3, $nF3);
            $ninetythirdF3 = $context->builder->fmul($ninetythirdF3, $nF3);
            $ninetythirdF3 = $context->builder->fmul($ninetythirdF3, $nF3);
            $ninetythirdF3 = $context->builder->fmul($ninetythirdF3, $nF3);
            $ninetythirdF3 = $context->builder->fmul($ninetythirdF3, $nF3);
            $ninetythirdF3 = $context->builder->fmul($ninetythirdF3, $nF3);
            $ninetythirdF3 = $context->builder->fmul($ninetythirdF3, $nF3);
            $ninetythirdF3 = $context->builder->fmul($ninetythirdF3, $nF3);
            $ninetythirdF3 = $context->builder->fmul($ninetythirdF3, $nF3);
                $ninetythirdF3 = $context->builder->fmul($ninetythirdF3, $nF3);
                    $ninetythirdF3 = $context->builder->fmul($ninetythirdF3, $nF3);
                        $ninetythirdF3 = $context->builder->fmul($ninetythirdF3, $nF3);
                        $ninetythirdF3 = $context->builder->fmul($ninetythirdF3, $nF3);
                        $ninetythirdF3 = $context->builder->fmul($ninetythirdF3, $nF3);
                        $ninetythirdF3 = $context->builder->fmul($ninetythirdF3, $nF3);
                        $ninetythirdF3 = $context->builder->fmul($ninetythirdF3, $nF3);
                        $ninetythirdF3 = $context->builder->fmul($ninetythirdF3, $nF3);
                        $ninetythirdF3 = $context->builder->fmul($ninetythirdF3, $nF3);
            $ninetythirdF3 = $context->builder->fmul($ninetythirdF3, $nF3);
            $ninetythirdF3 = $context->builder->fmul($ninetythirdF3, $nF3);
            $ninetythirdF3 = $context->builder->fmul($ninetythirdF3, $nF3);
            $ninetythirdF3 = $context->builder->fmul($ninetythirdF3, $nF3);
            $ninetythirdF3 = $context->builder->fmul($ninetythirdF3, $nF3);

            $ninetythirdF3 = $context->builder->fmul($ninetythirdF3, $nF3);
            $ninetythirdF3 = $context->builder->fmul($ninetythirdF3, $nF3);
            $ninetythirdF3 = $context->builder->fmul($ninetythirdF3, $nF3);
            $ninetythirdF3 = $context->builder->fmul($ninetythirdF3, $nF3);
            $ninetythirdF3 = $context->builder->fmul($ninetythirdF3, $nF3);
            $ninetythirdF3 = $context->builder->fmul($ninetythirdF3, $nF3);
            $ninetythirdF3 = $context->builder->fmul($ninetythirdF3, $nF3);
            $ninetythirdF3 = $context->builder->fmul($ninetythirdF3, $nF3);
            $ninetythirdF3 = $context->builder->fmul($ninetythirdF3, $nF3);
            $ninetythirdF3 = $context->builder->fmul($ninetythirdF3, $nF3);
            $ninetythirdF3 = $context->builder->fmul($ninetythirdF3, $nF3);
            $ninetythirdF3 = $context->builder->fmul($ninetythirdF3, $nF3);
            $ninetythirdF3 = $context->builder->fmul($ninetythirdF3, $nF3);
            $ninetythirdF3 = $context->builder->fmul($ninetythirdF3, $nF3);
            $ninetythirdF3 = $context->builder->fmul($ninetythirdF3, $nF3);
            $ninetythirdF3 = $context->builder->fmul($ninetythirdF3, $nF3);
            $ninetythirdF3 = $context->builder->fmul($ninetythirdF3, $nF3);
            $ninetythirdF3 = $context->builder->fmul($ninetythirdF3, $nF3);
            $ninetythirdF3 = $context->builder->fmul($ninetythirdF3, $nF3);
            $ninetythirdF3 = $context->builder->fmul($ninetythirdF3, $nF3);
            $ninetythirdF3 = $context->builder->fmul($ninetythirdF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF3
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
                throw new \LogicException('pow() **93 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_ninetythird_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_ninetythird_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF4 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $twentiethF4 = $context->builder->fmul($tenthF4, $tenthF4);
            $fortiethF4 = $context->builder->fmul($twentiethF4, $twentiethF4);
            $sqF4 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF4 = $context->builder->fmul($fortiethF4, $sqF4);
            $fortyfourthF4 = $context->builder->fmul($fortysecondF4, $sqF4);
            $ninetythirdF4Sq = $context->builder->fmul($fortyfourthF4, $sqF4);
            $ninetythirdF4Eighth = $context->builder->fmul($ninetythirdF4Sq, $sqF4);
            $ninetythirdF4 = $context->builder->fmul($ninetythirdF4Eighth, $sqF4);
            $nF4 = $context->builder->siToFp($n, $f64);
            $ninetythirdF4 = $context->builder->fmul($ninetythirdF4, $nF4);
            $ninetythirdF4 = $context->builder->fmul($ninetythirdF4, $nF4);
            $ninetythirdF4 = $context->builder->fmul($ninetythirdF4, $nF4);
            $ninetythirdF4 = $context->builder->fmul($ninetythirdF4, $nF4);
            $ninetythirdF4 = $context->builder->fmul($ninetythirdF4, $nF4);
            $ninetythirdF4 = $context->builder->fmul($ninetythirdF4, $nF4);
            $ninetythirdF4 = $context->builder->fmul($ninetythirdF4, $nF4);
            $ninetythirdF4 = $context->builder->fmul($ninetythirdF4, $nF4);
            $ninetythirdF4 = $context->builder->fmul($ninetythirdF4, $nF4);
            $ninetythirdF4 = $context->builder->fmul($ninetythirdF4, $nF4);
                $ninetythirdF4 = $context->builder->fmul($ninetythirdF4, $nF4);
                    $ninetythirdF4 = $context->builder->fmul($ninetythirdF4, $nF4);
                        $ninetythirdF4 = $context->builder->fmul($ninetythirdF4, $nF4);
                        $ninetythirdF4 = $context->builder->fmul($ninetythirdF4, $nF4);
                        $ninetythirdF4 = $context->builder->fmul($ninetythirdF4, $nF4);
                        $ninetythirdF4 = $context->builder->fmul($ninetythirdF4, $nF4);
                        $ninetythirdF4 = $context->builder->fmul($ninetythirdF4, $nF4);
                        $ninetythirdF4 = $context->builder->fmul($ninetythirdF4, $nF4);
                        $ninetythirdF4 = $context->builder->fmul($ninetythirdF4, $nF4);
            $ninetythirdF4 = $context->builder->fmul($ninetythirdF4, $nF4);
            $ninetythirdF4 = $context->builder->fmul($ninetythirdF4, $nF4);
            $ninetythirdF4 = $context->builder->fmul($ninetythirdF4, $nF4);
            $ninetythirdF4 = $context->builder->fmul($ninetythirdF4, $nF4);
            $ninetythirdF4 = $context->builder->fmul($ninetythirdF4, $nF4);

            $ninetythirdF4 = $context->builder->fmul($ninetythirdF4, $nF4);
            $ninetythirdF4 = $context->builder->fmul($ninetythirdF4, $nF4);
            $ninetythirdF4 = $context->builder->fmul($ninetythirdF4, $nF4);
            $ninetythirdF4 = $context->builder->fmul($ninetythirdF4, $nF4);
            $ninetythirdF4 = $context->builder->fmul($ninetythirdF4, $nF4);
            $ninetythirdF4 = $context->builder->fmul($ninetythirdF4, $nF4);
            $ninetythirdF4 = $context->builder->fmul($ninetythirdF4, $nF4);
            $ninetythirdF4 = $context->builder->fmul($ninetythirdF4, $nF4);
            $ninetythirdF4 = $context->builder->fmul($ninetythirdF4, $nF4);
            $ninetythirdF4 = $context->builder->fmul($ninetythirdF4, $nF4);
            $ninetythirdF4 = $context->builder->fmul($ninetythirdF4, $nF4);
            $ninetythirdF4 = $context->builder->fmul($ninetythirdF4, $nF4);
            $ninetythirdF4 = $context->builder->fmul($ninetythirdF4, $nF4);
            $ninetythirdF4 = $context->builder->fmul($ninetythirdF4, $nF4);
            $ninetythirdF4 = $context->builder->fmul($ninetythirdF4, $nF4);
            $ninetythirdF4 = $context->builder->fmul($ninetythirdF4, $nF4);
            $ninetythirdF4 = $context->builder->fmul($ninetythirdF4, $nF4);
            $ninetythirdF4 = $context->builder->fmul($ninetythirdF4, $nF4);
            $ninetythirdF4 = $context->builder->fmul($ninetythirdF4, $nF4);
            $ninetythirdF4 = $context->builder->fmul($ninetythirdF4, $nF4);
            $ninetythirdF4 = $context->builder->fmul($ninetythirdF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF4
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
                throw new \LogicException('pow() **93 expected smul overflow metadata (twentieth)');
            }
            $twentiethLong = JITVariable::KIND_VARIABLE === $twentiethVar->kind
                ? $context->builder->load($twentiethVar->value)
                : $twentiethVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_ninetythird_twentieth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_ninetythird_twentieth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $twentiethF5 = $context->builder->load($twentiethVar->longArithOverflowDoubleSlot);
            $fortiethF5 = $context->builder->fmul($twentiethF5, $twentiethF5);
            $sqF5 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF5 = $context->builder->fmul($fortiethF5, $sqF5);
            $fortyfourthF5 = $context->builder->fmul($fortysecondF5, $sqF5);
            $ninetythirdF5Sq = $context->builder->fmul($fortyfourthF5, $sqF5);
            $ninetythirdF5Eighth = $context->builder->fmul($ninetythirdF5Sq, $sqF5);
            $ninetythirdF5 = $context->builder->fmul($ninetythirdF5Eighth, $sqF5);
            $nF5 = $context->builder->siToFp($n, $f64);
            $ninetythirdF5 = $context->builder->fmul($ninetythirdF5, $nF5);
            $ninetythirdF5 = $context->builder->fmul($ninetythirdF5, $nF5);
            $ninetythirdF5 = $context->builder->fmul($ninetythirdF5, $nF5);
            $ninetythirdF5 = $context->builder->fmul($ninetythirdF5, $nF5);
            $ninetythirdF5 = $context->builder->fmul($ninetythirdF5, $nF5);
            $ninetythirdF5 = $context->builder->fmul($ninetythirdF5, $nF5);
            $ninetythirdF5 = $context->builder->fmul($ninetythirdF5, $nF5);
            $ninetythirdF5 = $context->builder->fmul($ninetythirdF5, $nF5);
            $ninetythirdF5 = $context->builder->fmul($ninetythirdF5, $nF5);
            $ninetythirdF5 = $context->builder->fmul($ninetythirdF5, $nF5);
                $ninetythirdF5 = $context->builder->fmul($ninetythirdF5, $nF5);
                    $ninetythirdF5 = $context->builder->fmul($ninetythirdF5, $nF5);
                        $ninetythirdF5 = $context->builder->fmul($ninetythirdF5, $nF5);
                        $ninetythirdF5 = $context->builder->fmul($ninetythirdF5, $nF5);
                        $ninetythirdF5 = $context->builder->fmul($ninetythirdF5, $nF5);
                        $ninetythirdF5 = $context->builder->fmul($ninetythirdF5, $nF5);
                        $ninetythirdF5 = $context->builder->fmul($ninetythirdF5, $nF5);
                        $ninetythirdF5 = $context->builder->fmul($ninetythirdF5, $nF5);
                        $ninetythirdF5 = $context->builder->fmul($ninetythirdF5, $nF5);
            $ninetythirdF5 = $context->builder->fmul($ninetythirdF5, $nF5);
            $ninetythirdF5 = $context->builder->fmul($ninetythirdF5, $nF5);
            $ninetythirdF5 = $context->builder->fmul($ninetythirdF5, $nF5);
            $ninetythirdF5 = $context->builder->fmul($ninetythirdF5, $nF5);
            $ninetythirdF5 = $context->builder->fmul($ninetythirdF5, $nF5);

            $ninetythirdF5 = $context->builder->fmul($ninetythirdF5, $nF5);
            $ninetythirdF5 = $context->builder->fmul($ninetythirdF5, $nF5);
            $ninetythirdF5 = $context->builder->fmul($ninetythirdF5, $nF5);
            $ninetythirdF5 = $context->builder->fmul($ninetythirdF5, $nF5);
            $ninetythirdF5 = $context->builder->fmul($ninetythirdF5, $nF5);
            $ninetythirdF5 = $context->builder->fmul($ninetythirdF5, $nF5);
            $ninetythirdF5 = $context->builder->fmul($ninetythirdF5, $nF5);
            $ninetythirdF5 = $context->builder->fmul($ninetythirdF5, $nF5);
            $ninetythirdF5 = $context->builder->fmul($ninetythirdF5, $nF5);
            $ninetythirdF5 = $context->builder->fmul($ninetythirdF5, $nF5);
            $ninetythirdF5 = $context->builder->fmul($ninetythirdF5, $nF5);
            $ninetythirdF5 = $context->builder->fmul($ninetythirdF5, $nF5);
            $ninetythirdF5 = $context->builder->fmul($ninetythirdF5, $nF5);
            $ninetythirdF5 = $context->builder->fmul($ninetythirdF5, $nF5);
            $ninetythirdF5 = $context->builder->fmul($ninetythirdF5, $nF5);
            $ninetythirdF5 = $context->builder->fmul($ninetythirdF5, $nF5);
            $ninetythirdF5 = $context->builder->fmul($ninetythirdF5, $nF5);
            $ninetythirdF5 = $context->builder->fmul($ninetythirdF5, $nF5);
            $ninetythirdF5 = $context->builder->fmul($ninetythirdF5, $nF5);
            $ninetythirdF5 = $context->builder->fmul($ninetythirdF5, $nF5);
            $ninetythirdF5 = $context->builder->fmul($ninetythirdF5, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF5
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
                throw new \LogicException('pow() **93 expected smul overflow metadata (fortieth)');
            }
            $fortiethLong = JITVariable::KIND_VARIABLE === $fortiethVar->kind
                ? $context->builder->load($fortiethVar->value)
                : $fortiethVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_ninetythird_fortieth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_ninetythird_fortieth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $fortiethF6 = $context->builder->load($fortiethVar->longArithOverflowDoubleSlot);
            $sqF6 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF6 = $context->builder->fmul($fortiethF6, $sqF6);
            $fortyfourthF6 = $context->builder->fmul($fortysecondF6, $sqF6);
            $ninetythirdF6Sq = $context->builder->fmul($fortyfourthF6, $sqF6);
            $ninetythirdF6Eighth = $context->builder->fmul($ninetythirdF6Sq, $sqF6);
            $ninetythirdF6 = $context->builder->fmul($ninetythirdF6Eighth, $sqF6);
            $nF6 = $context->builder->siToFp($n, $f64);
            $ninetythirdF6 = $context->builder->fmul($ninetythirdF6, $nF6);
            $ninetythirdF6 = $context->builder->fmul($ninetythirdF6, $nF6);
            $ninetythirdF6 = $context->builder->fmul($ninetythirdF6, $nF6);
            $ninetythirdF6 = $context->builder->fmul($ninetythirdF6, $nF6);
            $ninetythirdF6 = $context->builder->fmul($ninetythirdF6, $nF6);
            $ninetythirdF6 = $context->builder->fmul($ninetythirdF6, $nF6);
            $ninetythirdF6 = $context->builder->fmul($ninetythirdF6, $nF6);
            $ninetythirdF6 = $context->builder->fmul($ninetythirdF6, $nF6);
            $ninetythirdF6 = $context->builder->fmul($ninetythirdF6, $nF6);
            $ninetythirdF6 = $context->builder->fmul($ninetythirdF6, $nF6);
                $ninetythirdF6 = $context->builder->fmul($ninetythirdF6, $nF6);
                    $ninetythirdF6 = $context->builder->fmul($ninetythirdF6, $nF6);
                        $ninetythirdF6 = $context->builder->fmul($ninetythirdF6, $nF6);
                        $ninetythirdF6 = $context->builder->fmul($ninetythirdF6, $nF6);
                        $ninetythirdF6 = $context->builder->fmul($ninetythirdF6, $nF6);
                        $ninetythirdF6 = $context->builder->fmul($ninetythirdF6, $nF6);
                        $ninetythirdF6 = $context->builder->fmul($ninetythirdF6, $nF6);
                        $ninetythirdF6 = $context->builder->fmul($ninetythirdF6, $nF6);
                        $ninetythirdF6 = $context->builder->fmul($ninetythirdF6, $nF6);
            $ninetythirdF6 = $context->builder->fmul($ninetythirdF6, $nF6);
            $ninetythirdF6 = $context->builder->fmul($ninetythirdF6, $nF6);
            $ninetythirdF6 = $context->builder->fmul($ninetythirdF6, $nF6);
            $ninetythirdF6 = $context->builder->fmul($ninetythirdF6, $nF6);
            $ninetythirdF6 = $context->builder->fmul($ninetythirdF6, $nF6);

            $ninetythirdF6 = $context->builder->fmul($ninetythirdF6, $nF6);
            $ninetythirdF6 = $context->builder->fmul($ninetythirdF6, $nF6);
            $ninetythirdF6 = $context->builder->fmul($ninetythirdF6, $nF6);
            $ninetythirdF6 = $context->builder->fmul($ninetythirdF6, $nF6);
            $ninetythirdF6 = $context->builder->fmul($ninetythirdF6, $nF6);
            $ninetythirdF6 = $context->builder->fmul($ninetythirdF6, $nF6);
            $ninetythirdF6 = $context->builder->fmul($ninetythirdF6, $nF6);
            $ninetythirdF6 = $context->builder->fmul($ninetythirdF6, $nF6);
            $ninetythirdF6 = $context->builder->fmul($ninetythirdF6, $nF6);
            $ninetythirdF6 = $context->builder->fmul($ninetythirdF6, $nF6);
            $ninetythirdF6 = $context->builder->fmul($ninetythirdF6, $nF6);
            $ninetythirdF6 = $context->builder->fmul($ninetythirdF6, $nF6);
            $ninetythirdF6 = $context->builder->fmul($ninetythirdF6, $nF6);
            $ninetythirdF6 = $context->builder->fmul($ninetythirdF6, $nF6);
            $ninetythirdF6 = $context->builder->fmul($ninetythirdF6, $nF6);
            $ninetythirdF6 = $context->builder->fmul($ninetythirdF6, $nF6);
            $ninetythirdF6 = $context->builder->fmul($ninetythirdF6, $nF6);
            $ninetythirdF6 = $context->builder->fmul($ninetythirdF6, $nF6);
            $ninetythirdF6 = $context->builder->fmul($ninetythirdF6, $nF6);
            $ninetythirdF6 = $context->builder->fmul($ninetythirdF6, $nF6);
            $ninetythirdF6 = $context->builder->fmul($ninetythirdF6, $nF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF6
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
                throw new \LogicException('pow() **93 expected smul overflow metadata (fortysecond)');
            }
            $fortysecondLong = JITVariable::KIND_VARIABLE === $fortysecondVar->kind
                ? $context->builder->load($fortysecondVar->value)
                : $fortysecondVar->value;
            $ov7Block = BasicBlockHelper::append($context, 'pow_ninetythird_fortysecond_ov');
            $ok7Block = BasicBlockHelper::append($context, 'pow_ninetythird_fortysecond_ok');
            $context->builder->branchIf($ov7, $ov7Block, $ok7Block);

            $context->builder->positionAtEnd($ov7Block);
            $fortysecondF7 = $context->builder->load($fortysecondVar->longArithOverflowDoubleSlot);
            $sqF7 = $context->builder->siToFp($sqLong, $f64);
            $fortyfourthF7 = $context->builder->fmul($fortysecondF7, $sqF7);
            $ninetythirdF7Sq = $context->builder->fmul($fortyfourthF7, $sqF7);
            $ninetythirdF7Eighth = $context->builder->fmul($ninetythirdF7Sq, $sqF7);
            $ninetythirdF7 = $context->builder->fmul($ninetythirdF7Eighth, $sqF7);
            $nF7 = $context->builder->siToFp($n, $f64);
            $ninetythirdF7 = $context->builder->fmul($ninetythirdF7, $nF7);
            $ninetythirdF7 = $context->builder->fmul($ninetythirdF7, $nF7);
            $ninetythirdF7 = $context->builder->fmul($ninetythirdF7, $nF7);
            $ninetythirdF7 = $context->builder->fmul($ninetythirdF7, $nF7);
            $ninetythirdF7 = $context->builder->fmul($ninetythirdF7, $nF7);
            $ninetythirdF7 = $context->builder->fmul($ninetythirdF7, $nF7);
            $ninetythirdF7 = $context->builder->fmul($ninetythirdF7, $nF7);
            $ninetythirdF7 = $context->builder->fmul($ninetythirdF7, $nF7);
            $ninetythirdF7 = $context->builder->fmul($ninetythirdF7, $nF7);
            $ninetythirdF7 = $context->builder->fmul($ninetythirdF7, $nF7);
                $ninetythirdF7 = $context->builder->fmul($ninetythirdF7, $nF7);
                    $ninetythirdF7 = $context->builder->fmul($ninetythirdF7, $nF7);
                        $ninetythirdF7 = $context->builder->fmul($ninetythirdF7, $nF7);
                        $ninetythirdF7 = $context->builder->fmul($ninetythirdF7, $nF7);
                        $ninetythirdF7 = $context->builder->fmul($ninetythirdF7, $nF7);
                        $ninetythirdF7 = $context->builder->fmul($ninetythirdF7, $nF7);
                        $ninetythirdF7 = $context->builder->fmul($ninetythirdF7, $nF7);
                        $ninetythirdF7 = $context->builder->fmul($ninetythirdF7, $nF7);
                        $ninetythirdF7 = $context->builder->fmul($ninetythirdF7, $nF7);
            $ninetythirdF7 = $context->builder->fmul($ninetythirdF7, $nF7);
            $ninetythirdF7 = $context->builder->fmul($ninetythirdF7, $nF7);
            $ninetythirdF7 = $context->builder->fmul($ninetythirdF7, $nF7);
            $ninetythirdF7 = $context->builder->fmul($ninetythirdF7, $nF7);
            $ninetythirdF7 = $context->builder->fmul($ninetythirdF7, $nF7);

            $ninetythirdF7 = $context->builder->fmul($ninetythirdF7, $nF7);
            $ninetythirdF7 = $context->builder->fmul($ninetythirdF7, $nF7);
            $ninetythirdF7 = $context->builder->fmul($ninetythirdF7, $nF7);
            $ninetythirdF7 = $context->builder->fmul($ninetythirdF7, $nF7);
            $ninetythirdF7 = $context->builder->fmul($ninetythirdF7, $nF7);
            $ninetythirdF7 = $context->builder->fmul($ninetythirdF7, $nF7);
            $ninetythirdF7 = $context->builder->fmul($ninetythirdF7, $nF7);
            $ninetythirdF7 = $context->builder->fmul($ninetythirdF7, $nF7);
            $ninetythirdF7 = $context->builder->fmul($ninetythirdF7, $nF7);
            $ninetythirdF7 = $context->builder->fmul($ninetythirdF7, $nF7);
            $ninetythirdF7 = $context->builder->fmul($ninetythirdF7, $nF7);
            $ninetythirdF7 = $context->builder->fmul($ninetythirdF7, $nF7);
            $ninetythirdF7 = $context->builder->fmul($ninetythirdF7, $nF7);
            $ninetythirdF7 = $context->builder->fmul($ninetythirdF7, $nF7);
            $ninetythirdF7 = $context->builder->fmul($ninetythirdF7, $nF7);
            $ninetythirdF7 = $context->builder->fmul($ninetythirdF7, $nF7);
            $ninetythirdF7 = $context->builder->fmul($ninetythirdF7, $nF7);
            $ninetythirdF7 = $context->builder->fmul($ninetythirdF7, $nF7);
            $ninetythirdF7 = $context->builder->fmul($ninetythirdF7, $nF7);
            $ninetythirdF7 = $context->builder->fmul($ninetythirdF7, $nF7);
            $ninetythirdF7 = $context->builder->fmul($ninetythirdF7, $nF7);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF7
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
                throw new \LogicException('pow() **93 expected smul overflow metadata (fortyfourth)');
            }
            $fortyfourthLong = JITVariable::KIND_VARIABLE === $fortyfourthVar->kind
                ? $context->builder->load($fortyfourthVar->value)
                : $fortyfourthVar->value;
            $ov8Block = BasicBlockHelper::append($context, 'pow_ninetythird_fortyfourth_ov');
            $ok8Block = BasicBlockHelper::append($context, 'pow_ninetythird_fortyfourth_ok');
            $context->builder->branchIf($ov8, $ov8Block, $ok8Block);

            $context->builder->positionAtEnd($ov8Block);
            $fortyfourthF8 = $context->builder->load($fortyfourthVar->longArithOverflowDoubleSlot);
            $sqF8 = $context->builder->siToFp($sqLong, $f64);
            $ninetythirdF8Sq = $context->builder->fmul($fortyfourthF8, $sqF8);
            $ninetythirdF8Eighth = $context->builder->fmul($ninetythirdF8Sq, $sqF8);
            $ninetythirdF8 = $context->builder->fmul($ninetythirdF8Eighth, $sqF8);
            $nF8 = $context->builder->siToFp($n, $f64);
            $ninetythirdF8 = $context->builder->fmul($ninetythirdF8, $nF8);
            $ninetythirdF8 = $context->builder->fmul($ninetythirdF8, $nF8);
            $ninetythirdF8 = $context->builder->fmul($ninetythirdF8, $nF8);
            $ninetythirdF8 = $context->builder->fmul($ninetythirdF8, $nF8);
            $ninetythirdF8 = $context->builder->fmul($ninetythirdF8, $nF8);
            $ninetythirdF8 = $context->builder->fmul($ninetythirdF8, $nF8);
            $ninetythirdF8 = $context->builder->fmul($ninetythirdF8, $nF8);
            $ninetythirdF8 = $context->builder->fmul($ninetythirdF8, $nF8);
            $ninetythirdF8 = $context->builder->fmul($ninetythirdF8, $nF8);
            $ninetythirdF8 = $context->builder->fmul($ninetythirdF8, $nF8);
                $ninetythirdF8 = $context->builder->fmul($ninetythirdF8, $nF8);
                    $ninetythirdF8 = $context->builder->fmul($ninetythirdF8, $nF8);
                        $ninetythirdF8 = $context->builder->fmul($ninetythirdF8, $nF8);
                        $ninetythirdF8 = $context->builder->fmul($ninetythirdF8, $nF8);
                        $ninetythirdF8 = $context->builder->fmul($ninetythirdF8, $nF8);
                        $ninetythirdF8 = $context->builder->fmul($ninetythirdF8, $nF8);
                        $ninetythirdF8 = $context->builder->fmul($ninetythirdF8, $nF8);
                        $ninetythirdF8 = $context->builder->fmul($ninetythirdF8, $nF8);
                        $ninetythirdF8 = $context->builder->fmul($ninetythirdF8, $nF8);
            $ninetythirdF8 = $context->builder->fmul($ninetythirdF8, $nF8);
            $ninetythirdF8 = $context->builder->fmul($ninetythirdF8, $nF8);
            $ninetythirdF8 = $context->builder->fmul($ninetythirdF8, $nF8);
            $ninetythirdF8 = $context->builder->fmul($ninetythirdF8, $nF8);
            $ninetythirdF8 = $context->builder->fmul($ninetythirdF8, $nF8);

            $ninetythirdF8 = $context->builder->fmul($ninetythirdF8, $nF8);
            $ninetythirdF8 = $context->builder->fmul($ninetythirdF8, $nF8);
            $ninetythirdF8 = $context->builder->fmul($ninetythirdF8, $nF8);
            $ninetythirdF8 = $context->builder->fmul($ninetythirdF8, $nF8);
            $ninetythirdF8 = $context->builder->fmul($ninetythirdF8, $nF8);
            $ninetythirdF8 = $context->builder->fmul($ninetythirdF8, $nF8);
            $ninetythirdF8 = $context->builder->fmul($ninetythirdF8, $nF8);
            $ninetythirdF8 = $context->builder->fmul($ninetythirdF8, $nF8);
            $ninetythirdF8 = $context->builder->fmul($ninetythirdF8, $nF8);
            $ninetythirdF8 = $context->builder->fmul($ninetythirdF8, $nF8);
            $ninetythirdF8 = $context->builder->fmul($ninetythirdF8, $nF8);
            $ninetythirdF8 = $context->builder->fmul($ninetythirdF8, $nF8);
            $ninetythirdF8 = $context->builder->fmul($ninetythirdF8, $nF8);
            $ninetythirdF8 = $context->builder->fmul($ninetythirdF8, $nF8);
            $ninetythirdF8 = $context->builder->fmul($ninetythirdF8, $nF8);
            $ninetythirdF8 = $context->builder->fmul($ninetythirdF8, $nF8);
            $ninetythirdF8 = $context->builder->fmul($ninetythirdF8, $nF8);
            $ninetythirdF8 = $context->builder->fmul($ninetythirdF8, $nF8);
            $ninetythirdF8 = $context->builder->fmul($ninetythirdF8, $nF8);
            $ninetythirdF8 = $context->builder->fmul($ninetythirdF8, $nF8);
            $ninetythirdF8 = $context->builder->fmul($ninetythirdF8, $nF8);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF8
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
                throw new \LogicException('pow() **93 expected smul overflow metadata (fortysixth)');
            }
            $fortysixthLong = JITVariable::KIND_VARIABLE === $fortysixthVar->kind
                ? $context->builder->load($fortysixthVar->value)
                : $fortysixthVar->value;
            $ov9Block = BasicBlockHelper::append($context, 'pow_ninetythird_fortysixth_ov');
            $ok9Block = BasicBlockHelper::append($context, 'pow_ninetythird_fortysixth_ok');
            $context->builder->branchIf($ov9, $ov9Block, $ok9Block);

            $context->builder->positionAtEnd($ov9Block);
            $fortysixthF9 = $context->builder->load($fortysixthVar->longArithOverflowDoubleSlot);
            $sqF9 = $context->builder->siToFp($sqLong, $f64);
            $fortyeighthF9 = $context->builder->fmul($fortysixthF9, $sqF9);
            $ninetythirdF9 = $context->builder->fmul($fortyeighthF9, $sqF9);
            $nF9 = $context->builder->siToFp($n, $f64);
            $ninetythirdF9 = $context->builder->fmul($ninetythirdF9, $nF9);
            $ninetythirdF9 = $context->builder->fmul($ninetythirdF9, $nF9);
            $ninetythirdF9 = $context->builder->fmul($ninetythirdF9, $nF9);
            $ninetythirdF9 = $context->builder->fmul($ninetythirdF9, $nF9);
            $ninetythirdF9 = $context->builder->fmul($ninetythirdF9, $nF9);
            $ninetythirdF9 = $context->builder->fmul($ninetythirdF9, $nF9);
            $ninetythirdF9 = $context->builder->fmul($ninetythirdF9, $nF9);
            $ninetythirdF9 = $context->builder->fmul($ninetythirdF9, $nF9);
            $ninetythirdF9 = $context->builder->fmul($ninetythirdF9, $nF9);
            $ninetythirdF9 = $context->builder->fmul($ninetythirdF9, $nF9);
                $ninetythirdF9 = $context->builder->fmul($ninetythirdF9, $nF9);
                    $ninetythirdF9 = $context->builder->fmul($ninetythirdF9, $nF9);
                        $ninetythirdF9 = $context->builder->fmul($ninetythirdF9, $nF9);
                        $ninetythirdF9 = $context->builder->fmul($ninetythirdF9, $nF9);
                        $ninetythirdF9 = $context->builder->fmul($ninetythirdF9, $nF9);
                        $ninetythirdF9 = $context->builder->fmul($ninetythirdF9, $nF9);
                        $ninetythirdF9 = $context->builder->fmul($ninetythirdF9, $nF9);
                        $ninetythirdF9 = $context->builder->fmul($ninetythirdF9, $nF9);
                        $ninetythirdF9 = $context->builder->fmul($ninetythirdF9, $nF9);
            $ninetythirdF9 = $context->builder->fmul($ninetythirdF9, $nF9);
            $ninetythirdF9 = $context->builder->fmul($ninetythirdF9, $nF9);
            $ninetythirdF9 = $context->builder->fmul($ninetythirdF9, $nF9);
            $ninetythirdF9 = $context->builder->fmul($ninetythirdF9, $nF9);
            $ninetythirdF9 = $context->builder->fmul($ninetythirdF9, $nF9);

            $ninetythirdF9 = $context->builder->fmul($ninetythirdF9, $nF9);
            $ninetythirdF9 = $context->builder->fmul($ninetythirdF9, $nF9);
            $ninetythirdF9 = $context->builder->fmul($ninetythirdF9, $nF9);
            $ninetythirdF9 = $context->builder->fmul($ninetythirdF9, $nF9);
            $ninetythirdF9 = $context->builder->fmul($ninetythirdF9, $nF9);
            $ninetythirdF9 = $context->builder->fmul($ninetythirdF9, $nF9);
            $ninetythirdF9 = $context->builder->fmul($ninetythirdF9, $nF9);
            $ninetythirdF9 = $context->builder->fmul($ninetythirdF9, $nF9);
            $ninetythirdF9 = $context->builder->fmul($ninetythirdF9, $nF9);
            $ninetythirdF9 = $context->builder->fmul($ninetythirdF9, $nF9);
            $ninetythirdF9 = $context->builder->fmul($ninetythirdF9, $nF9);
            $ninetythirdF9 = $context->builder->fmul($ninetythirdF9, $nF9);
            $ninetythirdF9 = $context->builder->fmul($ninetythirdF9, $nF9);
            $ninetythirdF9 = $context->builder->fmul($ninetythirdF9, $nF9);
            $ninetythirdF9 = $context->builder->fmul($ninetythirdF9, $nF9);
            $ninetythirdF9 = $context->builder->fmul($ninetythirdF9, $nF9);
            $ninetythirdF9 = $context->builder->fmul($ninetythirdF9, $nF9);
            $ninetythirdF9 = $context->builder->fmul($ninetythirdF9, $nF9);
            $ninetythirdF9 = $context->builder->fmul($ninetythirdF9, $nF9);
            $ninetythirdF9 = $context->builder->fmul($ninetythirdF9, $nF9);
            $ninetythirdF9 = $context->builder->fmul($ninetythirdF9, $nF9);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF9
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
                throw new \LogicException('pow() **93 expected smul overflow metadata (fortyeighth)');
            }
            $fortyeighthLong = JITVariable::KIND_VARIABLE === $fortyeighthVar->kind
                ? $context->builder->load($fortyeighthVar->value)
                : $fortyeighthVar->value;
            $ov10Block = BasicBlockHelper::append($context, 'pow_ninetythird_fortyeighth_ov');
            $ok10Block = BasicBlockHelper::append($context, 'pow_ninetythird_fortyeighth_ok');
            $context->builder->branchIf($ov10, $ov10Block, $ok10Block);

            $context->builder->positionAtEnd($ov10Block);
            $fortyeighthF10 = $context->builder->load($fortyeighthVar->longArithOverflowDoubleSlot);
            $sqF10 = $context->builder->siToFp($sqLong, $f64);
            $ninetythirdF10 = $context->builder->fmul($fortyeighthF10, $sqF10);
            $nF10 = $context->builder->siToFp($n, $f64);
            $ninetythirdF10 = $context->builder->fmul($ninetythirdF10, $nF10);
            $ninetythirdF10 = $context->builder->fmul($ninetythirdF10, $nF10);
            $ninetythirdF10 = $context->builder->fmul($ninetythirdF10, $nF10);
            $ninetythirdF10 = $context->builder->fmul($ninetythirdF10, $nF10);
            $ninetythirdF10 = $context->builder->fmul($ninetythirdF10, $nF10);
            $ninetythirdF10 = $context->builder->fmul($ninetythirdF10, $nF10);
            $ninetythirdF10 = $context->builder->fmul($ninetythirdF10, $nF10);
            $ninetythirdF10 = $context->builder->fmul($ninetythirdF10, $nF10);
            $ninetythirdF10 = $context->builder->fmul($ninetythirdF10, $nF10);
            $ninetythirdF10 = $context->builder->fmul($ninetythirdF10, $nF10);
                $ninetythirdF10 = $context->builder->fmul($ninetythirdF10, $nF10);
                    $ninetythirdF10 = $context->builder->fmul($ninetythirdF10, $nF10);
                        $ninetythirdF10 = $context->builder->fmul($ninetythirdF10, $nF10);
                        $ninetythirdF10 = $context->builder->fmul($ninetythirdF10, $nF10);
                        $ninetythirdF10 = $context->builder->fmul($ninetythirdF10, $nF10);
                        $ninetythirdF10 = $context->builder->fmul($ninetythirdF10, $nF10);
                        $ninetythirdF10 = $context->builder->fmul($ninetythirdF10, $nF10);
                        $ninetythirdF10 = $context->builder->fmul($ninetythirdF10, $nF10);
                        $ninetythirdF10 = $context->builder->fmul($ninetythirdF10, $nF10);
            $ninetythirdF10 = $context->builder->fmul($ninetythirdF10, $nF10);
            $ninetythirdF10 = $context->builder->fmul($ninetythirdF10, $nF10);
            $ninetythirdF10 = $context->builder->fmul($ninetythirdF10, $nF10);
            $ninetythirdF10 = $context->builder->fmul($ninetythirdF10, $nF10);
            $ninetythirdF10 = $context->builder->fmul($ninetythirdF10, $nF10);

            $ninetythirdF10 = $context->builder->fmul($ninetythirdF10, $nF10);
            $ninetythirdF10 = $context->builder->fmul($ninetythirdF10, $nF10);
            $ninetythirdF10 = $context->builder->fmul($ninetythirdF10, $nF10);
            $ninetythirdF10 = $context->builder->fmul($ninetythirdF10, $nF10);
            $ninetythirdF10 = $context->builder->fmul($ninetythirdF10, $nF10);
            $ninetythirdF10 = $context->builder->fmul($ninetythirdF10, $nF10);
            $ninetythirdF10 = $context->builder->fmul($ninetythirdF10, $nF10);
            $ninetythirdF10 = $context->builder->fmul($ninetythirdF10, $nF10);
            $ninetythirdF10 = $context->builder->fmul($ninetythirdF10, $nF10);
            $ninetythirdF10 = $context->builder->fmul($ninetythirdF10, $nF10);
            $ninetythirdF10 = $context->builder->fmul($ninetythirdF10, $nF10);
            $ninetythirdF10 = $context->builder->fmul($ninetythirdF10, $nF10);
            $ninetythirdF10 = $context->builder->fmul($ninetythirdF10, $nF10);
            $ninetythirdF10 = $context->builder->fmul($ninetythirdF10, $nF10);
            $ninetythirdF10 = $context->builder->fmul($ninetythirdF10, $nF10);
            $ninetythirdF10 = $context->builder->fmul($ninetythirdF10, $nF10);
            $ninetythirdF10 = $context->builder->fmul($ninetythirdF10, $nF10);
            $ninetythirdF10 = $context->builder->fmul($ninetythirdF10, $nF10);
            $ninetythirdF10 = $context->builder->fmul($ninetythirdF10, $nF10);
            $ninetythirdF10 = $context->builder->fmul($ninetythirdF10, $nF10);
            $ninetythirdF10 = $context->builder->fmul($ninetythirdF10, $nF10);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF10
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
                throw new \LogicException('pow() **93 expected smul overflow metadata (fiftieth)');
            }
            $fiftiethLong = JITVariable::KIND_VARIABLE === $fiftiethVar->kind
                ? $context->builder->load($fiftiethVar->value)
                : $fiftiethVar->value;
            $ov11Block = BasicBlockHelper::append($context, 'pow_ninetythird_fiftieth_ov');
            $ok11Block = BasicBlockHelper::append($context, 'pow_ninetythird_fiftieth_ok');
            $context->builder->branchIf($ov11, $ov11Block, $ok11Block);

            $context->builder->positionAtEnd($ov11Block);
            $fiftiethF11 = $context->builder->load($fiftiethVar->longArithOverflowDoubleSlot);
            $nF11 = $context->builder->siToFp($n, $f64);
            $ninetythirdF11 = $context->builder->fmul($fiftiethF11, $nF11);
            $ninetythirdF11 = $context->builder->fmul($ninetythirdF11, $nF11);
            $ninetythirdF11 = $context->builder->fmul($ninetythirdF11, $nF11);
            $ninetythirdF11 = $context->builder->fmul($ninetythirdF11, $nF11);
            $ninetythirdF11 = $context->builder->fmul($ninetythirdF11, $nF11);
            $ninetythirdF11 = $context->builder->fmul($ninetythirdF11, $nF11);
            $ninetythirdF11 = $context->builder->fmul($ninetythirdF11, $nF11);
            $ninetythirdF11 = $context->builder->fmul($ninetythirdF11, $nF11);
            $ninetythirdF11 = $context->builder->fmul($ninetythirdF11, $nF11);
            $ninetythirdF11 = $context->builder->fmul($ninetythirdF11, $nF11);
                $ninetythirdF11 = $context->builder->fmul($ninetythirdF11, $nF11);
                    $ninetythirdF11 = $context->builder->fmul($ninetythirdF11, $nF11);
                        $ninetythirdF11 = $context->builder->fmul($ninetythirdF11, $nF11);
                        $ninetythirdF11 = $context->builder->fmul($ninetythirdF11, $nF11);
                        $ninetythirdF11 = $context->builder->fmul($ninetythirdF11, $nF11);
                        $ninetythirdF11 = $context->builder->fmul($ninetythirdF11, $nF11);
                        $ninetythirdF11 = $context->builder->fmul($ninetythirdF11, $nF11);
                        $ninetythirdF11 = $context->builder->fmul($ninetythirdF11, $nF11);
                        $ninetythirdF11 = $context->builder->fmul($ninetythirdF11, $nF11);
            $ninetythirdF11 = $context->builder->fmul($ninetythirdF11, $nF11);
            $ninetythirdF11 = $context->builder->fmul($ninetythirdF11, $nF11);
            $ninetythirdF11 = $context->builder->fmul($ninetythirdF11, $nF11);
            $ninetythirdF11 = $context->builder->fmul($ninetythirdF11, $nF11);
            $ninetythirdF11 = $context->builder->fmul($ninetythirdF11, $nF11);

            $ninetythirdF11 = $context->builder->fmul($ninetythirdF11, $nF11);
            $ninetythirdF11 = $context->builder->fmul($ninetythirdF11, $nF11);
            $ninetythirdF11 = $context->builder->fmul($ninetythirdF11, $nF11);
            $ninetythirdF11 = $context->builder->fmul($ninetythirdF11, $nF11);
            $ninetythirdF11 = $context->builder->fmul($ninetythirdF11, $nF11);
            $ninetythirdF11 = $context->builder->fmul($ninetythirdF11, $nF11);
            $ninetythirdF11 = $context->builder->fmul($ninetythirdF11, $nF11);
            $ninetythirdF11 = $context->builder->fmul($ninetythirdF11, $nF11);
            $ninetythirdF11 = $context->builder->fmul($ninetythirdF11, $nF11);
            $ninetythirdF11 = $context->builder->fmul($ninetythirdF11, $nF11);
            $ninetythirdF11 = $context->builder->fmul($ninetythirdF11, $nF11);
            $ninetythirdF11 = $context->builder->fmul($ninetythirdF11, $nF11);
            $ninetythirdF11 = $context->builder->fmul($ninetythirdF11, $nF11);
            $ninetythirdF11 = $context->builder->fmul($ninetythirdF11, $nF11);
            $ninetythirdF11 = $context->builder->fmul($ninetythirdF11, $nF11);
            $ninetythirdF11 = $context->builder->fmul($ninetythirdF11, $nF11);
            $ninetythirdF11 = $context->builder->fmul($ninetythirdF11, $nF11);
            $ninetythirdF11 = $context->builder->fmul($ninetythirdF11, $nF11);
            $ninetythirdF11 = $context->builder->fmul($ninetythirdF11, $nF11);
            $ninetythirdF11 = $context->builder->fmul($ninetythirdF11, $nF11);
            $ninetythirdF11 = $context->builder->fmul($ninetythirdF11, $nF11);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF11
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
                throw new \LogicException('pow() **93 expected smul overflow metadata (fiftyfirst)');
            }
            $fiftyfirstLong = JITVariable::KIND_VARIABLE === $fiftyfirstVar->kind
                ? $context->builder->load($fiftyfirstVar->value)
                : $fiftyfirstVar->value;
            $ov12Block = BasicBlockHelper::append($context, 'pow_ninetythird_fiftyfirst_ov');
            $ok12Block = BasicBlockHelper::append($context, 'pow_ninetythird_fiftyfirst_ok');
            $context->builder->branchIf($ov12, $ov12Block, $ok12Block);

            $context->builder->positionAtEnd($ov12Block);
            $fiftyfirstF12 = $context->builder->load($fiftyfirstVar->longArithOverflowDoubleSlot);
            $nF12 = $context->builder->siToFp($n, $f64);
            $ninetythirdF12 = $context->builder->fmul($fiftyfirstF12, $nF12);
            $ninetythirdF12 = $context->builder->fmul($ninetythirdF12, $nF12);
            $ninetythirdF12 = $context->builder->fmul($ninetythirdF12, $nF12);
            $ninetythirdF12 = $context->builder->fmul($ninetythirdF12, $nF12);
            $ninetythirdF12 = $context->builder->fmul($ninetythirdF12, $nF12);
            $ninetythirdF12 = $context->builder->fmul($ninetythirdF12, $nF12);
            $ninetythirdF12 = $context->builder->fmul($ninetythirdF12, $nF12);
            $ninetythirdF12 = $context->builder->fmul($ninetythirdF12, $nF12);
            $ninetythirdF12 = $context->builder->fmul($ninetythirdF12, $nF12);
                $ninetythirdF12 = $context->builder->fmul($ninetythirdF12, $nF12);
                    $ninetythirdF12 = $context->builder->fmul($ninetythirdF12, $nF12);
                        $ninetythirdF12 = $context->builder->fmul($ninetythirdF12, $nF12);
                        $ninetythirdF12 = $context->builder->fmul($ninetythirdF12, $nF12);
                        $ninetythirdF12 = $context->builder->fmul($ninetythirdF12, $nF12);
                        $ninetythirdF12 = $context->builder->fmul($ninetythirdF12, $nF12);
                        $ninetythirdF12 = $context->builder->fmul($ninetythirdF12, $nF12);
                        $ninetythirdF12 = $context->builder->fmul($ninetythirdF12, $nF12);
                        $ninetythirdF12 = $context->builder->fmul($ninetythirdF12, $nF12);
            $ninetythirdF12 = $context->builder->fmul($ninetythirdF12, $nF12);
            $ninetythirdF12 = $context->builder->fmul($ninetythirdF12, $nF12);
            $ninetythirdF12 = $context->builder->fmul($ninetythirdF12, $nF12);
            $ninetythirdF12 = $context->builder->fmul($ninetythirdF12, $nF12);
            $ninetythirdF12 = $context->builder->fmul($ninetythirdF12, $nF12);

            $ninetythirdF12 = $context->builder->fmul($ninetythirdF12, $nF12);
            $ninetythirdF12 = $context->builder->fmul($ninetythirdF12, $nF12);
            $ninetythirdF12 = $context->builder->fmul($ninetythirdF12, $nF12);
            $ninetythirdF12 = $context->builder->fmul($ninetythirdF12, $nF12);
            $ninetythirdF12 = $context->builder->fmul($ninetythirdF12, $nF12);
            $ninetythirdF12 = $context->builder->fmul($ninetythirdF12, $nF12);
            $ninetythirdF12 = $context->builder->fmul($ninetythirdF12, $nF12);
            $ninetythirdF12 = $context->builder->fmul($ninetythirdF12, $nF12);
            $ninetythirdF12 = $context->builder->fmul($ninetythirdF12, $nF12);
            $ninetythirdF12 = $context->builder->fmul($ninetythirdF12, $nF12);
            $ninetythirdF12 = $context->builder->fmul($ninetythirdF12, $nF12);
            $ninetythirdF12 = $context->builder->fmul($ninetythirdF12, $nF12);
            $ninetythirdF12 = $context->builder->fmul($ninetythirdF12, $nF12);
            $ninetythirdF12 = $context->builder->fmul($ninetythirdF12, $nF12);
            $ninetythirdF12 = $context->builder->fmul($ninetythirdF12, $nF12);
            $ninetythirdF12 = $context->builder->fmul($ninetythirdF12, $nF12);
            $ninetythirdF12 = $context->builder->fmul($ninetythirdF12, $nF12);
            $ninetythirdF12 = $context->builder->fmul($ninetythirdF12, $nF12);
            $ninetythirdF12 = $context->builder->fmul($ninetythirdF12, $nF12);
            $ninetythirdF12 = $context->builder->fmul($ninetythirdF12, $nF12);
            $ninetythirdF12 = $context->builder->fmul($ninetythirdF12, $nF12);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF12
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
                throw new \LogicException('pow() **93 expected smul overflow metadata (fiftysecond)');
            }
            $fiftysecondLong = JITVariable::KIND_VARIABLE === $fiftysecondVar->kind
                ? $context->builder->load($fiftysecondVar->value)
                : $fiftysecondVar->value;
            $ov13Block = BasicBlockHelper::append($context, 'pow_ninetythird_fiftysecond_ov');
            $ok13Block = BasicBlockHelper::append($context, 'pow_ninetythird_fiftysecond_ok');
            $context->builder->branchIf($ov13, $ov13Block, $ok13Block);

            $context->builder->positionAtEnd($ov13Block);
            $fiftysecondF13 = $context->builder->load($fiftysecondVar->longArithOverflowDoubleSlot);
            $nF13 = $context->builder->siToFp($n, $f64);
            $ninetythirdF13 = $context->builder->fmul($fiftysecondF13, $nF13);
            $ninetythirdF13 = $context->builder->fmul($ninetythirdF13, $nF13);
            $ninetythirdF13 = $context->builder->fmul($ninetythirdF13, $nF13);
            $ninetythirdF13 = $context->builder->fmul($ninetythirdF13, $nF13);
            $ninetythirdF13 = $context->builder->fmul($ninetythirdF13, $nF13);
            $ninetythirdF13 = $context->builder->fmul($ninetythirdF13, $nF13);
            $ninetythirdF13 = $context->builder->fmul($ninetythirdF13, $nF13);
            $ninetythirdF13 = $context->builder->fmul($ninetythirdF13, $nF13);
                $ninetythirdF13 = $context->builder->fmul($ninetythirdF13, $nF13);
                    $ninetythirdF13 = $context->builder->fmul($ninetythirdF13, $nF13);
                        $ninetythirdF13 = $context->builder->fmul($ninetythirdF13, $nF13);
                        $ninetythirdF13 = $context->builder->fmul($ninetythirdF13, $nF13);
                        $ninetythirdF13 = $context->builder->fmul($ninetythirdF13, $nF13);
                        $ninetythirdF13 = $context->builder->fmul($ninetythirdF13, $nF13);
                        $ninetythirdF13 = $context->builder->fmul($ninetythirdF13, $nF13);
                        $ninetythirdF13 = $context->builder->fmul($ninetythirdF13, $nF13);
                        $ninetythirdF13 = $context->builder->fmul($ninetythirdF13, $nF13);
            $ninetythirdF13 = $context->builder->fmul($ninetythirdF13, $nF13);
            $ninetythirdF13 = $context->builder->fmul($ninetythirdF13, $nF13);
            $ninetythirdF13 = $context->builder->fmul($ninetythirdF13, $nF13);
            $ninetythirdF13 = $context->builder->fmul($ninetythirdF13, $nF13);
            $ninetythirdF13 = $context->builder->fmul($ninetythirdF13, $nF13);

            $ninetythirdF13 = $context->builder->fmul($ninetythirdF13, $nF13);
            $ninetythirdF13 = $context->builder->fmul($ninetythirdF13, $nF13);
            $ninetythirdF13 = $context->builder->fmul($ninetythirdF13, $nF13);
            $ninetythirdF13 = $context->builder->fmul($ninetythirdF13, $nF13);
            $ninetythirdF13 = $context->builder->fmul($ninetythirdF13, $nF13);
            $ninetythirdF13 = $context->builder->fmul($ninetythirdF13, $nF13);
            $ninetythirdF13 = $context->builder->fmul($ninetythirdF13, $nF13);
            $ninetythirdF13 = $context->builder->fmul($ninetythirdF13, $nF13);
            $ninetythirdF13 = $context->builder->fmul($ninetythirdF13, $nF13);
            $ninetythirdF13 = $context->builder->fmul($ninetythirdF13, $nF13);
            $ninetythirdF13 = $context->builder->fmul($ninetythirdF13, $nF13);
            $ninetythirdF13 = $context->builder->fmul($ninetythirdF13, $nF13);
            $ninetythirdF13 = $context->builder->fmul($ninetythirdF13, $nF13);
            $ninetythirdF13 = $context->builder->fmul($ninetythirdF13, $nF13);
            $ninetythirdF13 = $context->builder->fmul($ninetythirdF13, $nF13);
            $ninetythirdF13 = $context->builder->fmul($ninetythirdF13, $nF13);
            $ninetythirdF13 = $context->builder->fmul($ninetythirdF13, $nF13);
            $ninetythirdF13 = $context->builder->fmul($ninetythirdF13, $nF13);
            $ninetythirdF13 = $context->builder->fmul($ninetythirdF13, $nF13);
            $ninetythirdF13 = $context->builder->fmul($ninetythirdF13, $nF13);
            $ninetythirdF13 = $context->builder->fmul($ninetythirdF13, $nF13);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF13
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
                throw new \LogicException('pow() **93 expected smul overflow metadata (fiftythird)');
            }
            $fiftythirdLong = JITVariable::KIND_VARIABLE === $fiftythirdVar->kind
                ? $context->builder->load($fiftythirdVar->value)
                : $fiftythirdVar->value;
            $ov14Block = BasicBlockHelper::append($context, 'pow_ninetythird_fiftythird_ov');
            $ok14Block = BasicBlockHelper::append($context, 'pow_ninetythird_fiftythird_ok');
            $context->builder->branchIf($ov14, $ov14Block, $ok14Block);

            $context->builder->positionAtEnd($ov14Block);
            $fiftythirdF14 = $context->builder->load($fiftythirdVar->longArithOverflowDoubleSlot);
            $nF14 = $context->builder->siToFp($n, $f64);
            $ninetythirdF14 = $context->builder->fmul($fiftythirdF14, $nF14);
            $ninetythirdF14 = $context->builder->fmul($ninetythirdF14, $nF14);
            $ninetythirdF14 = $context->builder->fmul($ninetythirdF14, $nF14);
            $ninetythirdF14 = $context->builder->fmul($ninetythirdF14, $nF14);
            $ninetythirdF14 = $context->builder->fmul($ninetythirdF14, $nF14);
            $ninetythirdF14 = $context->builder->fmul($ninetythirdF14, $nF14);
            $ninetythirdF14 = $context->builder->fmul($ninetythirdF14, $nF14);
                $ninetythirdF14 = $context->builder->fmul($ninetythirdF14, $nF14);
                    $ninetythirdF14 = $context->builder->fmul($ninetythirdF14, $nF14);
                        $ninetythirdF14 = $context->builder->fmul($ninetythirdF14, $nF14);
                        $ninetythirdF14 = $context->builder->fmul($ninetythirdF14, $nF14);
                        $ninetythirdF14 = $context->builder->fmul($ninetythirdF14, $nF14);
                        $ninetythirdF14 = $context->builder->fmul($ninetythirdF14, $nF14);
                        $ninetythirdF14 = $context->builder->fmul($ninetythirdF14, $nF14);
                        $ninetythirdF14 = $context->builder->fmul($ninetythirdF14, $nF14);
                        $ninetythirdF14 = $context->builder->fmul($ninetythirdF14, $nF14);
            $ninetythirdF14 = $context->builder->fmul($ninetythirdF14, $nF14);
            $ninetythirdF14 = $context->builder->fmul($ninetythirdF14, $nF14);
            $ninetythirdF14 = $context->builder->fmul($ninetythirdF14, $nF14);
            $ninetythirdF14 = $context->builder->fmul($ninetythirdF14, $nF14);
            $ninetythirdF14 = $context->builder->fmul($ninetythirdF14, $nF14);

            $ninetythirdF14 = $context->builder->fmul($ninetythirdF14, $nF14);
            $ninetythirdF14 = $context->builder->fmul($ninetythirdF14, $nF14);
            $ninetythirdF14 = $context->builder->fmul($ninetythirdF14, $nF14);
            $ninetythirdF14 = $context->builder->fmul($ninetythirdF14, $nF14);
            $ninetythirdF14 = $context->builder->fmul($ninetythirdF14, $nF14);
            $ninetythirdF14 = $context->builder->fmul($ninetythirdF14, $nF14);
            $ninetythirdF14 = $context->builder->fmul($ninetythirdF14, $nF14);
            $ninetythirdF14 = $context->builder->fmul($ninetythirdF14, $nF14);
            $ninetythirdF14 = $context->builder->fmul($ninetythirdF14, $nF14);
            $ninetythirdF14 = $context->builder->fmul($ninetythirdF14, $nF14);
            $ninetythirdF14 = $context->builder->fmul($ninetythirdF14, $nF14);
            $ninetythirdF14 = $context->builder->fmul($ninetythirdF14, $nF14);
            $ninetythirdF14 = $context->builder->fmul($ninetythirdF14, $nF14);
            $ninetythirdF14 = $context->builder->fmul($ninetythirdF14, $nF14);
            $ninetythirdF14 = $context->builder->fmul($ninetythirdF14, $nF14);
            $ninetythirdF14 = $context->builder->fmul($ninetythirdF14, $nF14);
            $ninetythirdF14 = $context->builder->fmul($ninetythirdF14, $nF14);
            $ninetythirdF14 = $context->builder->fmul($ninetythirdF14, $nF14);
            $ninetythirdF14 = $context->builder->fmul($ninetythirdF14, $nF14);
            $ninetythirdF14 = $context->builder->fmul($ninetythirdF14, $nF14);
            $ninetythirdF14 = $context->builder->fmul($ninetythirdF14, $nF14);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF14
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
                throw new \LogicException('pow() **93 expected smul overflow metadata (fiftyfourth)');
            }
            $fiftyfourthLong = JITVariable::KIND_VARIABLE === $fiftyfourthVar->kind
                ? $context->builder->load($fiftyfourthVar->value)
                : $fiftyfourthVar->value;
            $ov15Block = BasicBlockHelper::append($context, 'pow_ninetythird_fiftyfourth_ov');
            $ok15Block = BasicBlockHelper::append($context, 'pow_ninetythird_fiftyfourth_ok');
            $context->builder->branchIf($ov15, $ov15Block, $ok15Block);

            $context->builder->positionAtEnd($ov15Block);
            $fiftyfourthF15 = $context->builder->load($fiftyfourthVar->longArithOverflowDoubleSlot);
            $nF15 = $context->builder->siToFp($n, $f64);
            $ninetythirdF15 = $context->builder->fmul($fiftyfourthF15, $nF15);
            $ninetythirdF15 = $context->builder->fmul($ninetythirdF15, $nF15);
            $ninetythirdF15 = $context->builder->fmul($ninetythirdF15, $nF15);
            $ninetythirdF15 = $context->builder->fmul($ninetythirdF15, $nF15);
            $ninetythirdF15 = $context->builder->fmul($ninetythirdF15, $nF15);
            $ninetythirdF15 = $context->builder->fmul($ninetythirdF15, $nF15);
                $ninetythirdF15 = $context->builder->fmul($ninetythirdF15, $nF15);
                    $ninetythirdF15 = $context->builder->fmul($ninetythirdF15, $nF15);
                        $ninetythirdF15 = $context->builder->fmul($ninetythirdF15, $nF15);
                        $ninetythirdF15 = $context->builder->fmul($ninetythirdF15, $nF15);
                        $ninetythirdF15 = $context->builder->fmul($ninetythirdF15, $nF15);
                        $ninetythirdF15 = $context->builder->fmul($ninetythirdF15, $nF15);
                        $ninetythirdF15 = $context->builder->fmul($ninetythirdF15, $nF15);
                        $ninetythirdF15 = $context->builder->fmul($ninetythirdF15, $nF15);
                        $ninetythirdF15 = $context->builder->fmul($ninetythirdF15, $nF15);
            $ninetythirdF15 = $context->builder->fmul($ninetythirdF15, $nF15);
            $ninetythirdF15 = $context->builder->fmul($ninetythirdF15, $nF15);
            $ninetythirdF15 = $context->builder->fmul($ninetythirdF15, $nF15);
            $ninetythirdF15 = $context->builder->fmul($ninetythirdF15, $nF15);
            $ninetythirdF15 = $context->builder->fmul($ninetythirdF15, $nF15);

            $ninetythirdF15 = $context->builder->fmul($ninetythirdF15, $nF15);
            $ninetythirdF15 = $context->builder->fmul($ninetythirdF15, $nF15);
            $ninetythirdF15 = $context->builder->fmul($ninetythirdF15, $nF15);
            $ninetythirdF15 = $context->builder->fmul($ninetythirdF15, $nF15);
            $ninetythirdF15 = $context->builder->fmul($ninetythirdF15, $nF15);
            $ninetythirdF15 = $context->builder->fmul($ninetythirdF15, $nF15);
            $ninetythirdF15 = $context->builder->fmul($ninetythirdF15, $nF15);
            $ninetythirdF15 = $context->builder->fmul($ninetythirdF15, $nF15);
            $ninetythirdF15 = $context->builder->fmul($ninetythirdF15, $nF15);
            $ninetythirdF15 = $context->builder->fmul($ninetythirdF15, $nF15);
            $ninetythirdF15 = $context->builder->fmul($ninetythirdF15, $nF15);
            $ninetythirdF15 = $context->builder->fmul($ninetythirdF15, $nF15);
            $ninetythirdF15 = $context->builder->fmul($ninetythirdF15, $nF15);
            $ninetythirdF15 = $context->builder->fmul($ninetythirdF15, $nF15);
            $ninetythirdF15 = $context->builder->fmul($ninetythirdF15, $nF15);
            $ninetythirdF15 = $context->builder->fmul($ninetythirdF15, $nF15);
            $ninetythirdF15 = $context->builder->fmul($ninetythirdF15, $nF15);
            $ninetythirdF15 = $context->builder->fmul($ninetythirdF15, $nF15);
            $ninetythirdF15 = $context->builder->fmul($ninetythirdF15, $nF15);
            $ninetythirdF15 = $context->builder->fmul($ninetythirdF15, $nF15);
            $ninetythirdF15 = $context->builder->fmul($ninetythirdF15, $nF15);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF15
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
                throw new \LogicException('pow() **93 expected smul overflow metadata (fiftyfifth)');
            }
            $fiftyfifthLong = JITVariable::KIND_VARIABLE === $fiftyfifthVar->kind
                ? $context->builder->load($fiftyfifthVar->value)
                : $fiftyfifthVar->value;
            $ov16Block = BasicBlockHelper::append($context, 'pow_ninetythird_fiftyfifth_ov');
            $ok16Block = BasicBlockHelper::append($context, 'pow_ninetythird_fiftyfifth_ok');
            $context->builder->branchIf($ov16, $ov16Block, $ok16Block);

            $context->builder->positionAtEnd($ov16Block);
            $fiftyfifthF16 = $context->builder->load($fiftyfifthVar->longArithOverflowDoubleSlot);
            $nF16 = $context->builder->siToFp($n, $f64);
            $ninetythirdF16 = $context->builder->fmul($fiftyfifthF16, $nF16);
            $ninetythirdF16 = $context->builder->fmul($ninetythirdF16, $nF16);
            $ninetythirdF16 = $context->builder->fmul($ninetythirdF16, $nF16);
            $ninetythirdF16 = $context->builder->fmul($ninetythirdF16, $nF16);
            $ninetythirdF16 = $context->builder->fmul($ninetythirdF16, $nF16);
                $ninetythirdF16 = $context->builder->fmul($ninetythirdF16, $nF16);
                    $ninetythirdF16 = $context->builder->fmul($ninetythirdF16, $nF16);
                        $ninetythirdF16 = $context->builder->fmul($ninetythirdF16, $nF16);
                        $ninetythirdF16 = $context->builder->fmul($ninetythirdF16, $nF16);
                        $ninetythirdF16 = $context->builder->fmul($ninetythirdF16, $nF16);
                        $ninetythirdF16 = $context->builder->fmul($ninetythirdF16, $nF16);
                        $ninetythirdF16 = $context->builder->fmul($ninetythirdF16, $nF16);
                        $ninetythirdF16 = $context->builder->fmul($ninetythirdF16, $nF16);
                        $ninetythirdF16 = $context->builder->fmul($ninetythirdF16, $nF16);
            $ninetythirdF16 = $context->builder->fmul($ninetythirdF16, $nF16);
            $ninetythirdF16 = $context->builder->fmul($ninetythirdF16, $nF16);
            $ninetythirdF16 = $context->builder->fmul($ninetythirdF16, $nF16);
            $ninetythirdF16 = $context->builder->fmul($ninetythirdF16, $nF16);
            $ninetythirdF16 = $context->builder->fmul($ninetythirdF16, $nF16);

            $ninetythirdF16 = $context->builder->fmul($ninetythirdF16, $nF16);
            $ninetythirdF16 = $context->builder->fmul($ninetythirdF16, $nF16);
            $ninetythirdF16 = $context->builder->fmul($ninetythirdF16, $nF16);
            $ninetythirdF16 = $context->builder->fmul($ninetythirdF16, $nF16);
            $ninetythirdF16 = $context->builder->fmul($ninetythirdF16, $nF16);
            $ninetythirdF16 = $context->builder->fmul($ninetythirdF16, $nF16);
            $ninetythirdF16 = $context->builder->fmul($ninetythirdF16, $nF16);
            $ninetythirdF16 = $context->builder->fmul($ninetythirdF16, $nF16);
            $ninetythirdF16 = $context->builder->fmul($ninetythirdF16, $nF16);
            $ninetythirdF16 = $context->builder->fmul($ninetythirdF16, $nF16);
            $ninetythirdF16 = $context->builder->fmul($ninetythirdF16, $nF16);
            $ninetythirdF16 = $context->builder->fmul($ninetythirdF16, $nF16);
            $ninetythirdF16 = $context->builder->fmul($ninetythirdF16, $nF16);
            $ninetythirdF16 = $context->builder->fmul($ninetythirdF16, $nF16);
            $ninetythirdF16 = $context->builder->fmul($ninetythirdF16, $nF16);
            $ninetythirdF16 = $context->builder->fmul($ninetythirdF16, $nF16);
            $ninetythirdF16 = $context->builder->fmul($ninetythirdF16, $nF16);
            $ninetythirdF16 = $context->builder->fmul($ninetythirdF16, $nF16);
            $ninetythirdF16 = $context->builder->fmul($ninetythirdF16, $nF16);
            $ninetythirdF16 = $context->builder->fmul($ninetythirdF16, $nF16);
            $ninetythirdF16 = $context->builder->fmul($ninetythirdF16, $nF16);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF16
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
                throw new \LogicException('pow() **93 expected smul overflow metadata (fiftysixth)');
            }
            $fiftysixthLong = JITVariable::KIND_VARIABLE === $fiftysixthVar->kind
                ? $context->builder->load($fiftysixthVar->value)
                : $fiftysixthVar->value;
            $ov17Block = BasicBlockHelper::append($context, 'pow_ninetythird_fiftysixth_ov');
            $ok17Block = BasicBlockHelper::append($context, 'pow_ninetythird_fiftysixth_ok');
            $context->builder->branchIf($ov17, $ov17Block, $ok17Block);

            $context->builder->positionAtEnd($ov17Block);
            $fiftysixthF17 = $context->builder->load($fiftysixthVar->longArithOverflowDoubleSlot);
            $nF17 = $context->builder->siToFp($n, $f64);
            $ninetythirdF17 = $context->builder->fmul($fiftysixthF17, $nF17);
            $ninetythirdF17 = $context->builder->fmul($ninetythirdF17, $nF17);
            $ninetythirdF17 = $context->builder->fmul($ninetythirdF17, $nF17);
            $ninetythirdF17 = $context->builder->fmul($ninetythirdF17, $nF17);
                $ninetythirdF17 = $context->builder->fmul($ninetythirdF17, $nF17);
                    $ninetythirdF17 = $context->builder->fmul($ninetythirdF17, $nF17);
                        $ninetythirdF17 = $context->builder->fmul($ninetythirdF17, $nF17);
                        $ninetythirdF17 = $context->builder->fmul($ninetythirdF17, $nF17);
                        $ninetythirdF17 = $context->builder->fmul($ninetythirdF17, $nF17);
                        $ninetythirdF17 = $context->builder->fmul($ninetythirdF17, $nF17);
                        $ninetythirdF17 = $context->builder->fmul($ninetythirdF17, $nF17);
                        $ninetythirdF17 = $context->builder->fmul($ninetythirdF17, $nF17);
                        $ninetythirdF17 = $context->builder->fmul($ninetythirdF17, $nF17);
            $ninetythirdF17 = $context->builder->fmul($ninetythirdF17, $nF17);
            $ninetythirdF17 = $context->builder->fmul($ninetythirdF17, $nF17);
            $ninetythirdF17 = $context->builder->fmul($ninetythirdF17, $nF17);
            $ninetythirdF17 = $context->builder->fmul($ninetythirdF17, $nF17);
            $ninetythirdF17 = $context->builder->fmul($ninetythirdF17, $nF17);

            $ninetythirdF17 = $context->builder->fmul($ninetythirdF17, $nF17);
            $ninetythirdF17 = $context->builder->fmul($ninetythirdF17, $nF17);
            $ninetythirdF17 = $context->builder->fmul($ninetythirdF17, $nF17);
            $ninetythirdF17 = $context->builder->fmul($ninetythirdF17, $nF17);
            $ninetythirdF17 = $context->builder->fmul($ninetythirdF17, $nF17);
            $ninetythirdF17 = $context->builder->fmul($ninetythirdF17, $nF17);
            $ninetythirdF17 = $context->builder->fmul($ninetythirdF17, $nF17);
            $ninetythirdF17 = $context->builder->fmul($ninetythirdF17, $nF17);
            $ninetythirdF17 = $context->builder->fmul($ninetythirdF17, $nF17);
            $ninetythirdF17 = $context->builder->fmul($ninetythirdF17, $nF17);
            $ninetythirdF17 = $context->builder->fmul($ninetythirdF17, $nF17);
            $ninetythirdF17 = $context->builder->fmul($ninetythirdF17, $nF17);
            $ninetythirdF17 = $context->builder->fmul($ninetythirdF17, $nF17);
            $ninetythirdF17 = $context->builder->fmul($ninetythirdF17, $nF17);
            $ninetythirdF17 = $context->builder->fmul($ninetythirdF17, $nF17);
            $ninetythirdF17 = $context->builder->fmul($ninetythirdF17, $nF17);
            $ninetythirdF17 = $context->builder->fmul($ninetythirdF17, $nF17);
            $ninetythirdF17 = $context->builder->fmul($ninetythirdF17, $nF17);
            $ninetythirdF17 = $context->builder->fmul($ninetythirdF17, $nF17);
            $ninetythirdF17 = $context->builder->fmul($ninetythirdF17, $nF17);
            $ninetythirdF17 = $context->builder->fmul($ninetythirdF17, $nF17);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF17
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
                throw new \LogicException('pow() **93 expected smul overflow metadata (fiftyseventh)');
            }
            $fiftyseventhLong = JITVariable::KIND_VARIABLE === $fiftyseventhVar->kind
                ? $context->builder->load($fiftyseventhVar->value)
                : $fiftyseventhVar->value;
            $ov18Block = BasicBlockHelper::append($context, 'pow_ninetythird_fiftyseventh_ov');
            $ok18Block = BasicBlockHelper::append($context, 'pow_ninetythird_fiftyseventh_ok');
            $context->builder->branchIf($ov18, $ov18Block, $ok18Block);

            $context->builder->positionAtEnd($ov18Block);
            $fiftyseventhF18 = $context->builder->load($fiftyseventhVar->longArithOverflowDoubleSlot);
            $nF18 = $context->builder->siToFp($n, $f64);
            $ninetythirdF18 = $context->builder->fmul($fiftyseventhF18, $nF18);
            $ninetythirdF18 = $context->builder->fmul($ninetythirdF18, $nF18);
            $ninetythirdF18 = $context->builder->fmul($ninetythirdF18, $nF18);
                $ninetythirdF18 = $context->builder->fmul($ninetythirdF18, $nF18);
                    $ninetythirdF18 = $context->builder->fmul($ninetythirdF18, $nF18);
                        $ninetythirdF18 = $context->builder->fmul($ninetythirdF18, $nF18);
                        $ninetythirdF18 = $context->builder->fmul($ninetythirdF18, $nF18);
                        $ninetythirdF18 = $context->builder->fmul($ninetythirdF18, $nF18);
                        $ninetythirdF18 = $context->builder->fmul($ninetythirdF18, $nF18);
                        $ninetythirdF18 = $context->builder->fmul($ninetythirdF18, $nF18);
                        $ninetythirdF18 = $context->builder->fmul($ninetythirdF18, $nF18);
                        $ninetythirdF18 = $context->builder->fmul($ninetythirdF18, $nF18);
            $ninetythirdF18 = $context->builder->fmul($ninetythirdF18, $nF18);
            $ninetythirdF18 = $context->builder->fmul($ninetythirdF18, $nF18);
            $ninetythirdF18 = $context->builder->fmul($ninetythirdF18, $nF18);
            $ninetythirdF18 = $context->builder->fmul($ninetythirdF18, $nF18);
            $ninetythirdF18 = $context->builder->fmul($ninetythirdF18, $nF18);

            $ninetythirdF18 = $context->builder->fmul($ninetythirdF18, $nF18);
            $ninetythirdF18 = $context->builder->fmul($ninetythirdF18, $nF18);
            $ninetythirdF18 = $context->builder->fmul($ninetythirdF18, $nF18);
            $ninetythirdF18 = $context->builder->fmul($ninetythirdF18, $nF18);
            $ninetythirdF18 = $context->builder->fmul($ninetythirdF18, $nF18);
            $ninetythirdF18 = $context->builder->fmul($ninetythirdF18, $nF18);
            $ninetythirdF18 = $context->builder->fmul($ninetythirdF18, $nF18);
            $ninetythirdF18 = $context->builder->fmul($ninetythirdF18, $nF18);
            $ninetythirdF18 = $context->builder->fmul($ninetythirdF18, $nF18);
            $ninetythirdF18 = $context->builder->fmul($ninetythirdF18, $nF18);
            $ninetythirdF18 = $context->builder->fmul($ninetythirdF18, $nF18);
            $ninetythirdF18 = $context->builder->fmul($ninetythirdF18, $nF18);
            $ninetythirdF18 = $context->builder->fmul($ninetythirdF18, $nF18);
            $ninetythirdF18 = $context->builder->fmul($ninetythirdF18, $nF18);
            $ninetythirdF18 = $context->builder->fmul($ninetythirdF18, $nF18);
            $ninetythirdF18 = $context->builder->fmul($ninetythirdF18, $nF18);
            $ninetythirdF18 = $context->builder->fmul($ninetythirdF18, $nF18);
            $ninetythirdF18 = $context->builder->fmul($ninetythirdF18, $nF18);
            $ninetythirdF18 = $context->builder->fmul($ninetythirdF18, $nF18);
            $ninetythirdF18 = $context->builder->fmul($ninetythirdF18, $nF18);
            $ninetythirdF18 = $context->builder->fmul($ninetythirdF18, $nF18);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF18
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
                throw new \LogicException('pow() **93 expected smul overflow metadata (fiftyeighth)');
            }
            $fiftyeighthLong = JITVariable::KIND_VARIABLE === $fiftyeighthVar->kind
                ? $context->builder->load($fiftyeighthVar->value)
                : $fiftyeighthVar->value;
            $ov19Block = BasicBlockHelper::append($context, 'pow_ninetythird_fiftyeighth_ov');
            $ok19Block = BasicBlockHelper::append($context, 'pow_ninetythird_fiftyeighth_ok');
            $context->builder->branchIf($ov19, $ov19Block, $ok19Block);

            $context->builder->positionAtEnd($ov19Block);
            $fiftyeighthF19 = $context->builder->load($fiftyeighthVar->longArithOverflowDoubleSlot);
            $nF19 = $context->builder->siToFp($n, $f64);
            $ninetythirdF19 = $context->builder->fmul($fiftyeighthF19, $nF19);
            $ninetythirdF19 = $context->builder->fmul($ninetythirdF19, $nF19);
                $ninetythirdF19 = $context->builder->fmul($ninetythirdF19, $nF19);
                    $ninetythirdF19 = $context->builder->fmul($ninetythirdF19, $nF19);
                        $ninetythirdF19 = $context->builder->fmul($ninetythirdF19, $nF19);
                        $ninetythirdF19 = $context->builder->fmul($ninetythirdF19, $nF19);
                        $ninetythirdF19 = $context->builder->fmul($ninetythirdF19, $nF19);
                        $ninetythirdF19 = $context->builder->fmul($ninetythirdF19, $nF19);
                        $ninetythirdF19 = $context->builder->fmul($ninetythirdF19, $nF19);
                        $ninetythirdF19 = $context->builder->fmul($ninetythirdF19, $nF19);
                        $ninetythirdF19 = $context->builder->fmul($ninetythirdF19, $nF19);
            $ninetythirdF19 = $context->builder->fmul($ninetythirdF19, $nF19);
            $ninetythirdF19 = $context->builder->fmul($ninetythirdF19, $nF19);
            $ninetythirdF19 = $context->builder->fmul($ninetythirdF19, $nF19);
            $ninetythirdF19 = $context->builder->fmul($ninetythirdF19, $nF19);
            $ninetythirdF19 = $context->builder->fmul($ninetythirdF19, $nF19);

            $ninetythirdF19 = $context->builder->fmul($ninetythirdF19, $nF19);
            $ninetythirdF19 = $context->builder->fmul($ninetythirdF19, $nF19);
            $ninetythirdF19 = $context->builder->fmul($ninetythirdF19, $nF19);
            $ninetythirdF19 = $context->builder->fmul($ninetythirdF19, $nF19);
            $ninetythirdF19 = $context->builder->fmul($ninetythirdF19, $nF19);
            $ninetythirdF19 = $context->builder->fmul($ninetythirdF19, $nF19);
            $ninetythirdF19 = $context->builder->fmul($ninetythirdF19, $nF19);
            $ninetythirdF19 = $context->builder->fmul($ninetythirdF19, $nF19);
            $ninetythirdF19 = $context->builder->fmul($ninetythirdF19, $nF19);
            $ninetythirdF19 = $context->builder->fmul($ninetythirdF19, $nF19);
            $ninetythirdF19 = $context->builder->fmul($ninetythirdF19, $nF19);
            $ninetythirdF19 = $context->builder->fmul($ninetythirdF19, $nF19);
            $ninetythirdF19 = $context->builder->fmul($ninetythirdF19, $nF19);
            $ninetythirdF19 = $context->builder->fmul($ninetythirdF19, $nF19);
            $ninetythirdF19 = $context->builder->fmul($ninetythirdF19, $nF19);
            $ninetythirdF19 = $context->builder->fmul($ninetythirdF19, $nF19);
            $ninetythirdF19 = $context->builder->fmul($ninetythirdF19, $nF19);
            $ninetythirdF19 = $context->builder->fmul($ninetythirdF19, $nF19);
            $ninetythirdF19 = $context->builder->fmul($ninetythirdF19, $nF19);
            $ninetythirdF19 = $context->builder->fmul($ninetythirdF19, $nF19);
            $ninetythirdF19 = $context->builder->fmul($ninetythirdF19, $nF19);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF19
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
                throw new \LogicException('pow() **93 expected smul overflow metadata (fiftyninth)');
            }
            $fiftyninthLong = JITVariable::KIND_VARIABLE === $fiftyninthVar->kind
                ? $context->builder->load($fiftyninthVar->value)
                : $fiftyninthVar->value;
            $ov20Block = BasicBlockHelper::append($context, 'pow_ninetythird_fiftyninth_ov');
            $ok20Block = BasicBlockHelper::append($context, 'pow_ninetythird_fiftyninth_ok');
            $context->builder->branchIf($ov20, $ov20Block, $ok20Block);

            $context->builder->positionAtEnd($ov20Block);
            $fiftyninthF20 = $context->builder->load($fiftyninthVar->longArithOverflowDoubleSlot);
            $nF20 = $context->builder->siToFp($n, $f64);
            $ninetythirdF20 = $context->builder->fmul($fiftyninthF20, $nF20);
                $ninetythirdF20 = $context->builder->fmul($ninetythirdF20, $nF20);
                    $ninetythirdF20 = $context->builder->fmul($ninetythirdF20, $nF20);
                        $ninetythirdF20 = $context->builder->fmul($ninetythirdF20, $nF20);
                        $ninetythirdF20 = $context->builder->fmul($ninetythirdF20, $nF20);
                        $ninetythirdF20 = $context->builder->fmul($ninetythirdF20, $nF20);
                        $ninetythirdF20 = $context->builder->fmul($ninetythirdF20, $nF20);
                        $ninetythirdF20 = $context->builder->fmul($ninetythirdF20, $nF20);
                        $ninetythirdF20 = $context->builder->fmul($ninetythirdF20, $nF20);
                        $ninetythirdF20 = $context->builder->fmul($ninetythirdF20, $nF20);
            $ninetythirdF20 = $context->builder->fmul($ninetythirdF20, $nF20);
            $ninetythirdF20 = $context->builder->fmul($ninetythirdF20, $nF20);
            $ninetythirdF20 = $context->builder->fmul($ninetythirdF20, $nF20);
            $ninetythirdF20 = $context->builder->fmul($ninetythirdF20, $nF20);
            $ninetythirdF20 = $context->builder->fmul($ninetythirdF20, $nF20);

            $ninetythirdF20 = $context->builder->fmul($ninetythirdF20, $nF20);
            $ninetythirdF20 = $context->builder->fmul($ninetythirdF20, $nF20);
            $ninetythirdF20 = $context->builder->fmul($ninetythirdF20, $nF20);
            $ninetythirdF20 = $context->builder->fmul($ninetythirdF20, $nF20);
            $ninetythirdF20 = $context->builder->fmul($ninetythirdF20, $nF20);
            $ninetythirdF20 = $context->builder->fmul($ninetythirdF20, $nF20);
            $ninetythirdF20 = $context->builder->fmul($ninetythirdF20, $nF20);
            $ninetythirdF20 = $context->builder->fmul($ninetythirdF20, $nF20);
            $ninetythirdF20 = $context->builder->fmul($ninetythirdF20, $nF20);
            $ninetythirdF20 = $context->builder->fmul($ninetythirdF20, $nF20);
            $ninetythirdF20 = $context->builder->fmul($ninetythirdF20, $nF20);
            $ninetythirdF20 = $context->builder->fmul($ninetythirdF20, $nF20);
            $ninetythirdF20 = $context->builder->fmul($ninetythirdF20, $nF20);
            $ninetythirdF20 = $context->builder->fmul($ninetythirdF20, $nF20);
            $ninetythirdF20 = $context->builder->fmul($ninetythirdF20, $nF20);
            $ninetythirdF20 = $context->builder->fmul($ninetythirdF20, $nF20);
            $ninetythirdF20 = $context->builder->fmul($ninetythirdF20, $nF20);
            $ninetythirdF20 = $context->builder->fmul($ninetythirdF20, $nF20);
            $ninetythirdF20 = $context->builder->fmul($ninetythirdF20, $nF20);
            $ninetythirdF20 = $context->builder->fmul($ninetythirdF20, $nF20);
            $ninetythirdF20 = $context->builder->fmul($ninetythirdF20, $nF20);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF20
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
                throw new \LogicException('pow() **93 expected smul overflow metadata (sixtieth)');
            }
            $sixtiethLong = JITVariable::KIND_VARIABLE === $sixtiethVar->kind
                ? $context->builder->load($sixtiethVar->value)
                : $sixtiethVar->value;
            $ov21Block = BasicBlockHelper::append($context, 'pow_ninetythird_sixtieth_ov');
            $ok21Block = BasicBlockHelper::append($context, 'pow_ninetythird_sixtieth_ok');
            $context->builder->branchIf($ov21, $ov21Block, $ok21Block);

            $context->builder->positionAtEnd($ov21Block);
            $sixtiethF21 = $context->builder->load($sixtiethVar->longArithOverflowDoubleSlot);
            $nF21 = $context->builder->siToFp($n, $f64);
            $ninetythirdF21 = $context->builder->fmul($sixtiethF21, $nF21);
                $ninetythirdF21 = $context->builder->fmul($ninetythirdF21, $nF21);
                    $ninetythirdF21 = $context->builder->fmul($ninetythirdF21, $nF21);
                    $ninetythirdF21 = $context->builder->fmul($ninetythirdF21, $nF21);
                    $ninetythirdF21 = $context->builder->fmul($ninetythirdF21, $nF21);
                    $ninetythirdF21 = $context->builder->fmul($ninetythirdF21, $nF21);
                    $ninetythirdF21 = $context->builder->fmul($ninetythirdF21, $nF21);
                    $ninetythirdF21 = $context->builder->fmul($ninetythirdF21, $nF21);
                    $ninetythirdF21 = $context->builder->fmul($ninetythirdF21, $nF21);
            $ninetythirdF21 = $context->builder->fmul($ninetythirdF21, $nF21);
            $ninetythirdF21 = $context->builder->fmul($ninetythirdF21, $nF21);
            $ninetythirdF21 = $context->builder->fmul($ninetythirdF21, $nF21);
            $ninetythirdF21 = $context->builder->fmul($ninetythirdF21, $nF21);
            $ninetythirdF21 = $context->builder->fmul($ninetythirdF21, $nF21);

            $ninetythirdF21 = $context->builder->fmul($ninetythirdF21, $nF21);
            $ninetythirdF21 = $context->builder->fmul($ninetythirdF21, $nF21);
            $ninetythirdF21 = $context->builder->fmul($ninetythirdF21, $nF21);
            $ninetythirdF21 = $context->builder->fmul($ninetythirdF21, $nF21);
            $ninetythirdF21 = $context->builder->fmul($ninetythirdF21, $nF21);
            $ninetythirdF21 = $context->builder->fmul($ninetythirdF21, $nF21);
            $ninetythirdF21 = $context->builder->fmul($ninetythirdF21, $nF21);
            $ninetythirdF21 = $context->builder->fmul($ninetythirdF21, $nF21);
            $ninetythirdF21 = $context->builder->fmul($ninetythirdF21, $nF21);
            $ninetythirdF21 = $context->builder->fmul($ninetythirdF21, $nF21);
            $ninetythirdF21 = $context->builder->fmul($ninetythirdF21, $nF21);
            $ninetythirdF21 = $context->builder->fmul($ninetythirdF21, $nF21);
            $ninetythirdF21 = $context->builder->fmul($ninetythirdF21, $nF21);
            $ninetythirdF21 = $context->builder->fmul($ninetythirdF21, $nF21);
            $ninetythirdF21 = $context->builder->fmul($ninetythirdF21, $nF21);
            $ninetythirdF21 = $context->builder->fmul($ninetythirdF21, $nF21);
            $ninetythirdF21 = $context->builder->fmul($ninetythirdF21, $nF21);
            $ninetythirdF21 = $context->builder->fmul($ninetythirdF21, $nF21);
            $ninetythirdF21 = $context->builder->fmul($ninetythirdF21, $nF21);
            $ninetythirdF21 = $context->builder->fmul($ninetythirdF21, $nF21);
            $ninetythirdF21 = $context->builder->fmul($ninetythirdF21, $nF21);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF21
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
                throw new \LogicException('pow() **93 expected smul overflow metadata (sixtyfirst)');
            }
            $sixtyfirstLong = JITVariable::KIND_VARIABLE === $sixtyfirstVar->kind
                ? $context->builder->load($sixtyfirstVar->value)
                : $sixtyfirstVar->value;
            $ov22Block = BasicBlockHelper::append($context, 'pow_ninetythird_sixtyfirst_ov');
            $ok22Block = BasicBlockHelper::append($context, 'pow_ninetythird_sixtyfirst_ok');
            $context->builder->branchIf($ov22, $ov22Block, $ok22Block);

            $context->builder->positionAtEnd($ov22Block);
            $sixtyfirstF22 = $context->builder->load($sixtyfirstVar->longArithOverflowDoubleSlot);
            $nF22 = $context->builder->siToFp($n, $f64);
            $ninetythirdF22 = $context->builder->fmul($sixtyfirstF22, $nF22);
                $ninetythirdF22 = $context->builder->fmul($ninetythirdF22, $nF22);
                $ninetythirdF22 = $context->builder->fmul($ninetythirdF22, $nF22);
                $ninetythirdF22 = $context->builder->fmul($ninetythirdF22, $nF22);
                $ninetythirdF22 = $context->builder->fmul($ninetythirdF22, $nF22);
                $ninetythirdF22 = $context->builder->fmul($ninetythirdF22, $nF22);
                $ninetythirdF22 = $context->builder->fmul($ninetythirdF22, $nF22);
                $ninetythirdF22 = $context->builder->fmul($ninetythirdF22, $nF22);
            $ninetythirdF22 = $context->builder->fmul($ninetythirdF22, $nF22);
            $ninetythirdF22 = $context->builder->fmul($ninetythirdF22, $nF22);
            $ninetythirdF22 = $context->builder->fmul($ninetythirdF22, $nF22);
            $ninetythirdF22 = $context->builder->fmul($ninetythirdF22, $nF22);
            $ninetythirdF22 = $context->builder->fmul($ninetythirdF22, $nF22);

            $ninetythirdF22 = $context->builder->fmul($ninetythirdF22, $nF22);
            $ninetythirdF22 = $context->builder->fmul($ninetythirdF22, $nF22);
            $ninetythirdF22 = $context->builder->fmul($ninetythirdF22, $nF22);
            $ninetythirdF22 = $context->builder->fmul($ninetythirdF22, $nF22);
            $ninetythirdF22 = $context->builder->fmul($ninetythirdF22, $nF22);
            $ninetythirdF22 = $context->builder->fmul($ninetythirdF22, $nF22);
            $ninetythirdF22 = $context->builder->fmul($ninetythirdF22, $nF22);
            $ninetythirdF22 = $context->builder->fmul($ninetythirdF22, $nF22);
            $ninetythirdF22 = $context->builder->fmul($ninetythirdF22, $nF22);
            $ninetythirdF22 = $context->builder->fmul($ninetythirdF22, $nF22);
            $ninetythirdF22 = $context->builder->fmul($ninetythirdF22, $nF22);
            $ninetythirdF22 = $context->builder->fmul($ninetythirdF22, $nF22);
            $ninetythirdF22 = $context->builder->fmul($ninetythirdF22, $nF22);
            $ninetythirdF22 = $context->builder->fmul($ninetythirdF22, $nF22);
            $ninetythirdF22 = $context->builder->fmul($ninetythirdF22, $nF22);
            $ninetythirdF22 = $context->builder->fmul($ninetythirdF22, $nF22);
            $ninetythirdF22 = $context->builder->fmul($ninetythirdF22, $nF22);
            $ninetythirdF22 = $context->builder->fmul($ninetythirdF22, $nF22);
            $ninetythirdF22 = $context->builder->fmul($ninetythirdF22, $nF22);
            $ninetythirdF22 = $context->builder->fmul($ninetythirdF22, $nF22);
            $ninetythirdF22 = $context->builder->fmul($ninetythirdF22, $nF22);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF22
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
                throw new \LogicException('pow() **93 expected smul overflow metadata (sixtysecond)');
            }
            $sixtysecondLong = JITVariable::KIND_VARIABLE === $sixtysecondVar->kind
                ? $context->builder->load($sixtysecondVar->value)
                : $sixtysecondVar->value;
            $ov23Block = BasicBlockHelper::append($context, 'pow_ninetythird_sixtysecond_ov');
            $ok23Block = BasicBlockHelper::append($context, 'pow_ninetythird_sixtysecond_ok');
            $context->builder->branchIf($ov23, $ov23Block, $ok23Block);

            $context->builder->positionAtEnd($ov23Block);
            $sixtysecondF23 = $context->builder->load($sixtysecondVar->longArithOverflowDoubleSlot);
            $nF23 = $context->builder->siToFp($n, $f64);
            $ninetythirdF23 = $context->builder->fmul($sixtysecondF23, $nF23);
            $ninetythirdF23 = $context->builder->fmul($ninetythirdF23, $nF23);
            $ninetythirdF23 = $context->builder->fmul($ninetythirdF23, $nF23);
            $ninetythirdF23 = $context->builder->fmul($ninetythirdF23, $nF23);
            $ninetythirdF23 = $context->builder->fmul($ninetythirdF23, $nF23);
            $ninetythirdF23 = $context->builder->fmul($ninetythirdF23, $nF23);
            $ninetythirdF23 = $context->builder->fmul($ninetythirdF23, $nF23);
            $ninetythirdF23 = $context->builder->fmul($ninetythirdF23, $nF23);
            $ninetythirdF23 = $context->builder->fmul($ninetythirdF23, $nF23);
            $ninetythirdF23 = $context->builder->fmul($ninetythirdF23, $nF23);
            $ninetythirdF23 = $context->builder->fmul($ninetythirdF23, $nF23);
            $ninetythirdF23 = $context->builder->fmul($ninetythirdF23, $nF23);

            $ninetythirdF23 = $context->builder->fmul($ninetythirdF23, $nF23);
            $ninetythirdF23 = $context->builder->fmul($ninetythirdF23, $nF23);
            $ninetythirdF23 = $context->builder->fmul($ninetythirdF23, $nF23);
            $ninetythirdF23 = $context->builder->fmul($ninetythirdF23, $nF23);
            $ninetythirdF23 = $context->builder->fmul($ninetythirdF23, $nF23);
            $ninetythirdF23 = $context->builder->fmul($ninetythirdF23, $nF23);
            $ninetythirdF23 = $context->builder->fmul($ninetythirdF23, $nF23);
            $ninetythirdF23 = $context->builder->fmul($ninetythirdF23, $nF23);
            $ninetythirdF23 = $context->builder->fmul($ninetythirdF23, $nF23);
            $ninetythirdF23 = $context->builder->fmul($ninetythirdF23, $nF23);
            $ninetythirdF23 = $context->builder->fmul($ninetythirdF23, $nF23);
            $ninetythirdF23 = $context->builder->fmul($ninetythirdF23, $nF23);
            $ninetythirdF23 = $context->builder->fmul($ninetythirdF23, $nF23);
            $ninetythirdF23 = $context->builder->fmul($ninetythirdF23, $nF23);
            $ninetythirdF23 = $context->builder->fmul($ninetythirdF23, $nF23);
            $ninetythirdF23 = $context->builder->fmul($ninetythirdF23, $nF23);
            $ninetythirdF23 = $context->builder->fmul($ninetythirdF23, $nF23);
            $ninetythirdF23 = $context->builder->fmul($ninetythirdF23, $nF23);
            $ninetythirdF23 = $context->builder->fmul($ninetythirdF23, $nF23);
            $ninetythirdF23 = $context->builder->fmul($ninetythirdF23, $nF23);
            $ninetythirdF23 = $context->builder->fmul($ninetythirdF23, $nF23);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF23
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
                throw new \LogicException('pow() **93 expected smul overflow metadata (sixtythird)');
            }
            $sixtythirdLong = JITVariable::KIND_VARIABLE === $sixtythirdVar->kind
                ? $context->builder->load($sixtythirdVar->value)
                : $sixtythirdVar->value;
            $ov24Block = BasicBlockHelper::append($context, 'pow_ninetythird_sixtythird_ov');
            $ok24Block = BasicBlockHelper::append($context, 'pow_ninetythird_sixtythird_ok');
            $context->builder->branchIf($ov24, $ov24Block, $ok24Block);

            $context->builder->positionAtEnd($ov24Block);
            $sixtythirdF24 = $context->builder->load($sixtythirdVar->longArithOverflowDoubleSlot);
            $nF24 = $context->builder->siToFp($n, $f64);
            $ninetythirdF24 = $context->builder->fmul($sixtythirdF24, $nF24);
            $ninetythirdF24 = $context->builder->fmul($ninetythirdF24, $nF24);
            $ninetythirdF24 = $context->builder->fmul($ninetythirdF24, $nF24);
            $ninetythirdF24 = $context->builder->fmul($ninetythirdF24, $nF24);
            $ninetythirdF24 = $context->builder->fmul($ninetythirdF24, $nF24);
            $ninetythirdF24 = $context->builder->fmul($ninetythirdF24, $nF24);
            $ninetythirdF24 = $context->builder->fmul($ninetythirdF24, $nF24);
            $ninetythirdF24 = $context->builder->fmul($ninetythirdF24, $nF24);
            $ninetythirdF24 = $context->builder->fmul($ninetythirdF24, $nF24);
            $ninetythirdF24 = $context->builder->fmul($ninetythirdF24, $nF24);
            $ninetythirdF24 = $context->builder->fmul($ninetythirdF24, $nF24);

            $ninetythirdF24 = $context->builder->fmul($ninetythirdF24, $nF24);
            $ninetythirdF24 = $context->builder->fmul($ninetythirdF24, $nF24);
            $ninetythirdF24 = $context->builder->fmul($ninetythirdF24, $nF24);
            $ninetythirdF24 = $context->builder->fmul($ninetythirdF24, $nF24);
            $ninetythirdF24 = $context->builder->fmul($ninetythirdF24, $nF24);
            $ninetythirdF24 = $context->builder->fmul($ninetythirdF24, $nF24);
            $ninetythirdF24 = $context->builder->fmul($ninetythirdF24, $nF24);
            $ninetythirdF24 = $context->builder->fmul($ninetythirdF24, $nF24);
            $ninetythirdF24 = $context->builder->fmul($ninetythirdF24, $nF24);
            $ninetythirdF24 = $context->builder->fmul($ninetythirdF24, $nF24);
            $ninetythirdF24 = $context->builder->fmul($ninetythirdF24, $nF24);
            $ninetythirdF24 = $context->builder->fmul($ninetythirdF24, $nF24);
            $ninetythirdF24 = $context->builder->fmul($ninetythirdF24, $nF24);
            $ninetythirdF24 = $context->builder->fmul($ninetythirdF24, $nF24);
            $ninetythirdF24 = $context->builder->fmul($ninetythirdF24, $nF24);
            $ninetythirdF24 = $context->builder->fmul($ninetythirdF24, $nF24);
            $ninetythirdF24 = $context->builder->fmul($ninetythirdF24, $nF24);
            $ninetythirdF24 = $context->builder->fmul($ninetythirdF24, $nF24);
            $ninetythirdF24 = $context->builder->fmul($ninetythirdF24, $nF24);
            $ninetythirdF24 = $context->builder->fmul($ninetythirdF24, $nF24);
            $ninetythirdF24 = $context->builder->fmul($ninetythirdF24, $nF24);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF24
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
                throw new \LogicException('pow() **93 expected smul overflow metadata (sixtyfourth)');
            }
            $sixtyfourthLong = JITVariable::KIND_VARIABLE === $sixtyfourthVar->kind
                ? $context->builder->load($sixtyfourthVar->value)
                : $sixtyfourthVar->value;
            $ov25Block = BasicBlockHelper::append($context, 'pow_ninetythird_sixtyfourth_ov');
            $ok25Block = BasicBlockHelper::append($context, 'pow_ninetythird_sixtyfourth_ok');
            $context->builder->branchIf($ov25, $ov25Block, $ok25Block);

            $context->builder->positionAtEnd($ov25Block);
            $sixtyfourthF25 = $context->builder->load($sixtyfourthVar->longArithOverflowDoubleSlot);
            $nF25 = $context->builder->siToFp($n, $f64);
            $ninetythirdF25 = $context->builder->fmul($sixtyfourthF25, $nF25);
            $ninetythirdF25 = $context->builder->fmul($ninetythirdF25, $nF25);
            $ninetythirdF25 = $context->builder->fmul($ninetythirdF25, $nF25);
            $ninetythirdF25 = $context->builder->fmul($ninetythirdF25, $nF25);
            $ninetythirdF25 = $context->builder->fmul($ninetythirdF25, $nF25);
            $ninetythirdF25 = $context->builder->fmul($ninetythirdF25, $nF25);
            $ninetythirdF25 = $context->builder->fmul($ninetythirdF25, $nF25);
            $ninetythirdF25 = $context->builder->fmul($ninetythirdF25, $nF25);
            $ninetythirdF25 = $context->builder->fmul($ninetythirdF25, $nF25);
            $ninetythirdF25 = $context->builder->fmul($ninetythirdF25, $nF25);

            $ninetythirdF25 = $context->builder->fmul($ninetythirdF25, $nF25);
            $ninetythirdF25 = $context->builder->fmul($ninetythirdF25, $nF25);
            $ninetythirdF25 = $context->builder->fmul($ninetythirdF25, $nF25);
            $ninetythirdF25 = $context->builder->fmul($ninetythirdF25, $nF25);
            $ninetythirdF25 = $context->builder->fmul($ninetythirdF25, $nF25);
            $ninetythirdF25 = $context->builder->fmul($ninetythirdF25, $nF25);
            $ninetythirdF25 = $context->builder->fmul($ninetythirdF25, $nF25);
            $ninetythirdF25 = $context->builder->fmul($ninetythirdF25, $nF25);
            $ninetythirdF25 = $context->builder->fmul($ninetythirdF25, $nF25);
            $ninetythirdF25 = $context->builder->fmul($ninetythirdF25, $nF25);
            $ninetythirdF25 = $context->builder->fmul($ninetythirdF25, $nF25);
            $ninetythirdF25 = $context->builder->fmul($ninetythirdF25, $nF25);
            $ninetythirdF25 = $context->builder->fmul($ninetythirdF25, $nF25);
            $ninetythirdF25 = $context->builder->fmul($ninetythirdF25, $nF25);
            $ninetythirdF25 = $context->builder->fmul($ninetythirdF25, $nF25);
            $ninetythirdF25 = $context->builder->fmul($ninetythirdF25, $nF25);
            $ninetythirdF25 = $context->builder->fmul($ninetythirdF25, $nF25);
            $ninetythirdF25 = $context->builder->fmul($ninetythirdF25, $nF25);
            $ninetythirdF25 = $context->builder->fmul($ninetythirdF25, $nF25);
            $ninetythirdF25 = $context->builder->fmul($ninetythirdF25, $nF25);
            $ninetythirdF25 = $context->builder->fmul($ninetythirdF25, $nF25);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF25
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
                throw new \LogicException('pow() **93 expected smul overflow metadata (sixtyfifth)');
            }
            $sixtyfifthLong = JITVariable::KIND_VARIABLE === $sixtyfifthVar->kind
                ? $context->builder->load($sixtyfifthVar->value)
                : $sixtyfifthVar->value;
            $ov26Block = BasicBlockHelper::append($context, 'pow_ninetythird_sixtyfifth_ov');
            $ok26Block = BasicBlockHelper::append($context, 'pow_ninetythird_sixtyfifth_ok');
            $context->builder->branchIf($ov26, $ov26Block, $ok26Block);

            $context->builder->positionAtEnd($ov26Block);
            $sixtyfifthF26 = $context->builder->load($sixtyfifthVar->longArithOverflowDoubleSlot);
            $nF26 = $context->builder->siToFp($n, $f64);
            $ninetythirdF26 = $context->builder->fmul($sixtyfifthF26, $nF26);
            $ninetythirdF26 = $context->builder->fmul($ninetythirdF26, $nF26);
            $ninetythirdF26 = $context->builder->fmul($ninetythirdF26, $nF26);
            $ninetythirdF26 = $context->builder->fmul($ninetythirdF26, $nF26);
            $ninetythirdF26 = $context->builder->fmul($ninetythirdF26, $nF26);
            $ninetythirdF26 = $context->builder->fmul($ninetythirdF26, $nF26);
            $ninetythirdF26 = $context->builder->fmul($ninetythirdF26, $nF26);
            $ninetythirdF26 = $context->builder->fmul($ninetythirdF26, $nF26);
            $ninetythirdF26 = $context->builder->fmul($ninetythirdF26, $nF26);

            $ninetythirdF26 = $context->builder->fmul($ninetythirdF26, $nF26);
            $ninetythirdF26 = $context->builder->fmul($ninetythirdF26, $nF26);
            $ninetythirdF26 = $context->builder->fmul($ninetythirdF26, $nF26);
            $ninetythirdF26 = $context->builder->fmul($ninetythirdF26, $nF26);
            $ninetythirdF26 = $context->builder->fmul($ninetythirdF26, $nF26);
            $ninetythirdF26 = $context->builder->fmul($ninetythirdF26, $nF26);
            $ninetythirdF26 = $context->builder->fmul($ninetythirdF26, $nF26);
            $ninetythirdF26 = $context->builder->fmul($ninetythirdF26, $nF26);
            $ninetythirdF26 = $context->builder->fmul($ninetythirdF26, $nF26);
            $ninetythirdF26 = $context->builder->fmul($ninetythirdF26, $nF26);
            $ninetythirdF26 = $context->builder->fmul($ninetythirdF26, $nF26);
            $ninetythirdF26 = $context->builder->fmul($ninetythirdF26, $nF26);
            $ninetythirdF26 = $context->builder->fmul($ninetythirdF26, $nF26);
            $ninetythirdF26 = $context->builder->fmul($ninetythirdF26, $nF26);
            $ninetythirdF26 = $context->builder->fmul($ninetythirdF26, $nF26);
            $ninetythirdF26 = $context->builder->fmul($ninetythirdF26, $nF26);
            $ninetythirdF26 = $context->builder->fmul($ninetythirdF26, $nF26);
            $ninetythirdF26 = $context->builder->fmul($ninetythirdF26, $nF26);
            $ninetythirdF26 = $context->builder->fmul($ninetythirdF26, $nF26);
            $ninetythirdF26 = $context->builder->fmul($ninetythirdF26, $nF26);
            $ninetythirdF26 = $context->builder->fmul($ninetythirdF26, $nF26);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF26
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
                throw new \LogicException('pow() **93 expected smul overflow metadata (sixtysixth)');
            }
            $sixtysixthLong = JITVariable::KIND_VARIABLE === $sixtysixthVar->kind
                ? $context->builder->load($sixtysixthVar->value)
                : $sixtysixthVar->value;
            $ov27Block = BasicBlockHelper::append($context, 'pow_ninetythird_sixtysixth_ov');
            $ok27Block = BasicBlockHelper::append($context, 'pow_ninetythird_sixtysixth_ok');
            $context->builder->branchIf($ov27, $ov27Block, $ok27Block);

            $context->builder->positionAtEnd($ov27Block);
            $sixtysixthF27 = $context->builder->load($sixtysixthVar->longArithOverflowDoubleSlot);
            $nF27 = $context->builder->siToFp($n, $f64);
            $ninetythirdF27 = $context->builder->fmul($sixtysixthF27, $nF27);
            $ninetythirdF27 = $context->builder->fmul($ninetythirdF27, $nF27);
            $ninetythirdF27 = $context->builder->fmul($ninetythirdF27, $nF27);
            $ninetythirdF27 = $context->builder->fmul($ninetythirdF27, $nF27);
            $ninetythirdF27 = $context->builder->fmul($ninetythirdF27, $nF27);
            $ninetythirdF27 = $context->builder->fmul($ninetythirdF27, $nF27);
            $ninetythirdF27 = $context->builder->fmul($ninetythirdF27, $nF27);
            $ninetythirdF27 = $context->builder->fmul($ninetythirdF27, $nF27);

            $ninetythirdF27 = $context->builder->fmul($ninetythirdF27, $nF27);
            $ninetythirdF27 = $context->builder->fmul($ninetythirdF27, $nF27);
            $ninetythirdF27 = $context->builder->fmul($ninetythirdF27, $nF27);
            $ninetythirdF27 = $context->builder->fmul($ninetythirdF27, $nF27);
            $ninetythirdF27 = $context->builder->fmul($ninetythirdF27, $nF27);
            $ninetythirdF27 = $context->builder->fmul($ninetythirdF27, $nF27);
            $ninetythirdF27 = $context->builder->fmul($ninetythirdF27, $nF27);
            $ninetythirdF27 = $context->builder->fmul($ninetythirdF27, $nF27);
            $ninetythirdF27 = $context->builder->fmul($ninetythirdF27, $nF27);
            $ninetythirdF27 = $context->builder->fmul($ninetythirdF27, $nF27);
            $ninetythirdF27 = $context->builder->fmul($ninetythirdF27, $nF27);
            $ninetythirdF27 = $context->builder->fmul($ninetythirdF27, $nF27);
            $ninetythirdF27 = $context->builder->fmul($ninetythirdF27, $nF27);
            $ninetythirdF27 = $context->builder->fmul($ninetythirdF27, $nF27);
            $ninetythirdF27 = $context->builder->fmul($ninetythirdF27, $nF27);
            $ninetythirdF27 = $context->builder->fmul($ninetythirdF27, $nF27);
            $ninetythirdF27 = $context->builder->fmul($ninetythirdF27, $nF27);
            $ninetythirdF27 = $context->builder->fmul($ninetythirdF27, $nF27);
            $ninetythirdF27 = $context->builder->fmul($ninetythirdF27, $nF27);
            $ninetythirdF27 = $context->builder->fmul($ninetythirdF27, $nF27);
            $ninetythirdF27 = $context->builder->fmul($ninetythirdF27, $nF27);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF27
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
                throw new \LogicException('pow() **93 expected smul overflow metadata (sixtyseventh)');
            }
            $sixtyseventhLong = JITVariable::KIND_VARIABLE === $sixtyseventhVar->kind
                ? $context->builder->load($sixtyseventhVar->value)
                : $sixtyseventhVar->value;
            $ov28Block = BasicBlockHelper::append($context, 'pow_ninetythird_sixtyseventh_ov');
            $ok28Block = BasicBlockHelper::append($context, 'pow_ninetythird_sixtyseventh_ok');
            $context->builder->branchIf($ov28, $ov28Block, $ok28Block);

            $context->builder->positionAtEnd($ov28Block);
            $sixtyseventhF28 = $context->builder->load($sixtyseventhVar->longArithOverflowDoubleSlot);
            $nF28 = $context->builder->siToFp($n, $f64);
            $ninetythirdF28 = $context->builder->fmul($sixtyseventhF28, $nF28);
            $ninetythirdF28 = $context->builder->fmul($ninetythirdF28, $nF28);
            $ninetythirdF28 = $context->builder->fmul($ninetythirdF28, $nF28);
            $ninetythirdF28 = $context->builder->fmul($ninetythirdF28, $nF28);
            $ninetythirdF28 = $context->builder->fmul($ninetythirdF28, $nF28);
            $ninetythirdF28 = $context->builder->fmul($ninetythirdF28, $nF28);
            $ninetythirdF28 = $context->builder->fmul($ninetythirdF28, $nF28);
            $ninetythirdF28 = $context->builder->fmul($ninetythirdF28, $nF28);

            $ninetythirdF28 = $context->builder->fmul($ninetythirdF28, $nF28);
            $ninetythirdF28 = $context->builder->fmul($ninetythirdF28, $nF28);
            $ninetythirdF28 = $context->builder->fmul($ninetythirdF28, $nF28);
            $ninetythirdF28 = $context->builder->fmul($ninetythirdF28, $nF28);
            $ninetythirdF28 = $context->builder->fmul($ninetythirdF28, $nF28);
            $ninetythirdF28 = $context->builder->fmul($ninetythirdF28, $nF28);
            $ninetythirdF28 = $context->builder->fmul($ninetythirdF28, $nF28);
            $ninetythirdF28 = $context->builder->fmul($ninetythirdF28, $nF28);
            $ninetythirdF28 = $context->builder->fmul($ninetythirdF28, $nF28);
            $ninetythirdF28 = $context->builder->fmul($ninetythirdF28, $nF28);
            $ninetythirdF28 = $context->builder->fmul($ninetythirdF28, $nF28);
            $ninetythirdF28 = $context->builder->fmul($ninetythirdF28, $nF28);
            $ninetythirdF28 = $context->builder->fmul($ninetythirdF28, $nF28);
            $ninetythirdF28 = $context->builder->fmul($ninetythirdF28, $nF28);
            $ninetythirdF28 = $context->builder->fmul($ninetythirdF28, $nF28);
            $ninetythirdF28 = $context->builder->fmul($ninetythirdF28, $nF28);
            $ninetythirdF28 = $context->builder->fmul($ninetythirdF28, $nF28);
            $ninetythirdF28 = $context->builder->fmul($ninetythirdF28, $nF28);
            $ninetythirdF28 = $context->builder->fmul($ninetythirdF28, $nF28);
            $ninetythirdF28 = $context->builder->fmul($ninetythirdF28, $nF28);
            $ninetythirdF28 = $context->builder->fmul($ninetythirdF28, $nF28);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF28
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
                throw new \LogicException('pow() **93 expected smul overflow metadata (sixtyeighth)');
            }
            $sixtyeighthLong = JITVariable::KIND_VARIABLE === $sixtyeighthVar->kind
                ? $context->builder->load($sixtyeighthVar->value)
                : $sixtyeighthVar->value;
            $ov29Block = BasicBlockHelper::append($context, 'pow_ninetythird_sixtyeighth_ov');
            $ok29Block = BasicBlockHelper::append($context, 'pow_ninetythird_sixtyeighth_ok');
            $context->builder->branchIf($ov29, $ov29Block, $ok29Block);

            $context->builder->positionAtEnd($ov29Block);
            $sixtyeighthF29 = $context->builder->load($sixtyeighthVar->longArithOverflowDoubleSlot);
            $nF29 = $context->builder->siToFp($n, $f64);
            $ninetythirdF29 = $context->builder->fmul($sixtyeighthF29, $nF29);
            $ninetythirdF29 = $context->builder->fmul($ninetythirdF29, $nF29);
            $ninetythirdF29 = $context->builder->fmul($ninetythirdF29, $nF29);
            $ninetythirdF29 = $context->builder->fmul($ninetythirdF29, $nF29);
            $ninetythirdF29 = $context->builder->fmul($ninetythirdF29, $nF29);
            $ninetythirdF29 = $context->builder->fmul($ninetythirdF29, $nF29);

            $ninetythirdF29 = $context->builder->fmul($ninetythirdF29, $nF29);
            $ninetythirdF29 = $context->builder->fmul($ninetythirdF29, $nF29);
            $ninetythirdF29 = $context->builder->fmul($ninetythirdF29, $nF29);
            $ninetythirdF29 = $context->builder->fmul($ninetythirdF29, $nF29);
            $ninetythirdF29 = $context->builder->fmul($ninetythirdF29, $nF29);
            $ninetythirdF29 = $context->builder->fmul($ninetythirdF29, $nF29);
            $ninetythirdF29 = $context->builder->fmul($ninetythirdF29, $nF29);
            $ninetythirdF29 = $context->builder->fmul($ninetythirdF29, $nF29);
            $ninetythirdF29 = $context->builder->fmul($ninetythirdF29, $nF29);
            $ninetythirdF29 = $context->builder->fmul($ninetythirdF29, $nF29);
            $ninetythirdF29 = $context->builder->fmul($ninetythirdF29, $nF29);
            $ninetythirdF29 = $context->builder->fmul($ninetythirdF29, $nF29);
            $ninetythirdF29 = $context->builder->fmul($ninetythirdF29, $nF29);
            $ninetythirdF29 = $context->builder->fmul($ninetythirdF29, $nF29);
            $ninetythirdF29 = $context->builder->fmul($ninetythirdF29, $nF29);
            $ninetythirdF29 = $context->builder->fmul($ninetythirdF29, $nF29);
            $ninetythirdF29 = $context->builder->fmul($ninetythirdF29, $nF29);
            $ninetythirdF29 = $context->builder->fmul($ninetythirdF29, $nF29);
            $ninetythirdF29 = $context->builder->fmul($ninetythirdF29, $nF29);
            $ninetythirdF29 = $context->builder->fmul($ninetythirdF29, $nF29);
            $ninetythirdF29 = $context->builder->fmul($ninetythirdF29, $nF29);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF29
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok29Block);
            $sixtyninthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixtyeighthLong,
                $n
            );
            $ov30 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixtyninthVar->longArithOverflowFlag);
            if (null === $ov30 || null === $sixtyninthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **93 expected smul overflow metadata (sixtyninth)');
            }
            $sixtyninthLong = JITVariable::KIND_VARIABLE === $sixtyninthVar->kind
                ? $context->builder->load($sixtyninthVar->value)
                : $sixtyninthVar->value;
            $ov30Block = BasicBlockHelper::append($context, 'pow_ninetythird_sixtyninth_ov');
            $ok30Block = BasicBlockHelper::append($context, 'pow_ninetythird_sixtyninth_ok');
            $context->builder->branchIf($ov30, $ov30Block, $ok30Block);

            $context->builder->positionAtEnd($ov30Block);
            $sixtyninthF30 = $context->builder->load($sixtyninthVar->longArithOverflowDoubleSlot);
            $nF30 = $context->builder->siToFp($n, $f64);
            $ninetythirdF30 = $context->builder->fmul($sixtyninthF30, $nF30);
            $ninetythirdF30 = $context->builder->fmul($ninetythirdF30, $nF30);
            $ninetythirdF30 = $context->builder->fmul($ninetythirdF30, $nF30);
            $ninetythirdF30 = $context->builder->fmul($ninetythirdF30, $nF30);
            $ninetythirdF30 = $context->builder->fmul($ninetythirdF30, $nF30);
            $ninetythirdF30 = $context->builder->fmul($ninetythirdF30, $nF30);
            $ninetythirdF30 = $context->builder->fmul($ninetythirdF30, $nF30);
            $ninetythirdF30 = $context->builder->fmul($ninetythirdF30, $nF30);
            $ninetythirdF30 = $context->builder->fmul($ninetythirdF30, $nF30);
            $ninetythirdF30 = $context->builder->fmul($ninetythirdF30, $nF30);
            $ninetythirdF30 = $context->builder->fmul($ninetythirdF30, $nF30);
            $ninetythirdF30 = $context->builder->fmul($ninetythirdF30, $nF30);
            $ninetythirdF30 = $context->builder->fmul($ninetythirdF30, $nF30);
            $ninetythirdF30 = $context->builder->fmul($ninetythirdF30, $nF30);
            $ninetythirdF30 = $context->builder->fmul($ninetythirdF30, $nF30);
            $ninetythirdF30 = $context->builder->fmul($ninetythirdF30, $nF30);
            $ninetythirdF30 = $context->builder->fmul($ninetythirdF30, $nF30);
            $ninetythirdF30 = $context->builder->fmul($ninetythirdF30, $nF30);
            $ninetythirdF30 = $context->builder->fmul($ninetythirdF30, $nF30);
            $ninetythirdF30 = $context->builder->fmul($ninetythirdF30, $nF30);
            $ninetythirdF30 = $context->builder->fmul($ninetythirdF30, $nF30);
            $ninetythirdF30 = $context->builder->fmul($ninetythirdF30, $nF30);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF30
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok30Block);
            $seventiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixtyninthLong,
                $n
            );
            $ov31 = JitLongArithOverflow::loadOverflowFlagI1($context, $seventiethVar->longArithOverflowFlag);
            if (null === $ov31 || null === $seventiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **93 expected smul overflow metadata (seventieth)');
            }
            $seventiethLong = JITVariable::KIND_VARIABLE === $seventiethVar->kind
                ? $context->builder->load($seventiethVar->value)
                : $seventiethVar->value;
            $ov31Block = BasicBlockHelper::append($context, 'pow_ninetythird_seventieth_ov');
            $ok31Block = BasicBlockHelper::append($context, 'pow_ninetythird_seventieth_ok');
            $context->builder->branchIf($ov31, $ov31Block, $ok31Block);

            $context->builder->positionAtEnd($ov31Block);
            $seventiethF31 = $context->builder->load($seventiethVar->longArithOverflowDoubleSlot);
            $nF31 = $context->builder->siToFp($n, $f64);
            $ninetythirdF31 = $context->builder->fmul($seventiethF31, $nF31);
            $ninetythirdF31 = $context->builder->fmul($ninetythirdF31, $nF31);
            $ninetythirdF31 = $context->builder->fmul($ninetythirdF31, $nF31);
            $ninetythirdF31 = $context->builder->fmul($ninetythirdF31, $nF31);
            $ninetythirdF31 = $context->builder->fmul($ninetythirdF31, $nF31);
            $ninetythirdF31 = $context->builder->fmul($ninetythirdF31, $nF31);
            $ninetythirdF31 = $context->builder->fmul($ninetythirdF31, $nF31);
            $ninetythirdF31 = $context->builder->fmul($ninetythirdF31, $nF31);
            $ninetythirdF31 = $context->builder->fmul($ninetythirdF31, $nF31);
            $ninetythirdF31 = $context->builder->fmul($ninetythirdF31, $nF31);
            $ninetythirdF31 = $context->builder->fmul($ninetythirdF31, $nF31);
            $ninetythirdF31 = $context->builder->fmul($ninetythirdF31, $nF31);
            $ninetythirdF31 = $context->builder->fmul($ninetythirdF31, $nF31);
            $ninetythirdF31 = $context->builder->fmul($ninetythirdF31, $nF31);
            $ninetythirdF31 = $context->builder->fmul($ninetythirdF31, $nF31);
            $ninetythirdF31 = $context->builder->fmul($ninetythirdF31, $nF31);
            $ninetythirdF31 = $context->builder->fmul($ninetythirdF31, $nF31);
            $ninetythirdF31 = $context->builder->fmul($ninetythirdF31, $nF31);
            $ninetythirdF31 = $context->builder->fmul($ninetythirdF31, $nF31);
            $ninetythirdF31 = $context->builder->fmul($ninetythirdF31, $nF31);
            $ninetythirdF31 = $context->builder->fmul($ninetythirdF31, $nF31);
            $ninetythirdF31 = $context->builder->fmul($ninetythirdF31, $nF31);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF31
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok31Block);
            $seventyfirstVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $seventiethLong,
                $n
            );
            $ov32 = JitLongArithOverflow::loadOverflowFlagI1($context, $seventyfirstVar->longArithOverflowFlag);
            if (null === $ov32 || null === $seventyfirstVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **93 expected smul overflow metadata (seventyfirst)');
            }
            $seventyfirstLong = JITVariable::KIND_VARIABLE === $seventyfirstVar->kind
                ? $context->builder->load($seventyfirstVar->value)
                : $seventyfirstVar->value;
            $ov32Block = BasicBlockHelper::append($context, 'pow_ninetythird_seventyfirst_ov');
            $ok32Block = BasicBlockHelper::append($context, 'pow_ninetythird_seventyfirst_ok');
            $context->builder->branchIf($ov32, $ov32Block, $ok32Block);

            $context->builder->positionAtEnd($ov32Block);
            $seventyfirstF32 = $context->builder->load($seventyfirstVar->longArithOverflowDoubleSlot);
            $nF32 = $context->builder->siToFp($n, $f64);
            $ninetythirdF32 = $context->builder->fmul($seventyfirstF32, $nF32);
            $ninetythirdF32 = $context->builder->fmul($ninetythirdF32, $nF32);
            $ninetythirdF32 = $context->builder->fmul($ninetythirdF32, $nF32);
            $ninetythirdF32 = $context->builder->fmul($ninetythirdF32, $nF32);
            $ninetythirdF32 = $context->builder->fmul($ninetythirdF32, $nF32);
            $ninetythirdF32 = $context->builder->fmul($ninetythirdF32, $nF32);
            $ninetythirdF32 = $context->builder->fmul($ninetythirdF32, $nF32);
            $ninetythirdF32 = $context->builder->fmul($ninetythirdF32, $nF32);
            $ninetythirdF32 = $context->builder->fmul($ninetythirdF32, $nF32);
            $ninetythirdF32 = $context->builder->fmul($ninetythirdF32, $nF32);
            $ninetythirdF32 = $context->builder->fmul($ninetythirdF32, $nF32);
            $ninetythirdF32 = $context->builder->fmul($ninetythirdF32, $nF32);
            $ninetythirdF32 = $context->builder->fmul($ninetythirdF32, $nF32);
            $ninetythirdF32 = $context->builder->fmul($ninetythirdF32, $nF32);
            $ninetythirdF32 = $context->builder->fmul($ninetythirdF32, $nF32);
            $ninetythirdF32 = $context->builder->fmul($ninetythirdF32, $nF32);
            $ninetythirdF32 = $context->builder->fmul($ninetythirdF32, $nF32);
            $ninetythirdF32 = $context->builder->fmul($ninetythirdF32, $nF32);
            $ninetythirdF32 = $context->builder->fmul($ninetythirdF32, $nF32);
            $ninetythirdF32 = $context->builder->fmul($ninetythirdF32, $nF32);
            $ninetythirdF32 = $context->builder->fmul($ninetythirdF32, $nF32);
            $ninetythirdF32 = $context->builder->fmul($ninetythirdF32, $nF32);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF32
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok32Block);
            $seventysecondVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $seventyfirstLong,
                $n
            );
            $ov33 = JitLongArithOverflow::loadOverflowFlagI1($context, $seventysecondVar->longArithOverflowFlag);
            if (null === $ov33 || null === $seventysecondVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **93 expected smul overflow metadata (seventysecond)');
            }
            $seventysecondLong = JITVariable::KIND_VARIABLE === $seventysecondVar->kind
                ? $context->builder->load($seventysecondVar->value)
                : $seventysecondVar->value;
            $ov33Block = BasicBlockHelper::append($context, 'pow_ninetythird_seventysecond_ov');
            $ok33Block = BasicBlockHelper::append($context, 'pow_ninetythird_seventysecond_ok');
            $context->builder->branchIf($ov33, $ov33Block, $ok33Block);

            $context->builder->positionAtEnd($ov33Block);
            $seventysecondF33 = $context->builder->load($seventysecondVar->longArithOverflowDoubleSlot);
            $nF33 = $context->builder->siToFp($n, $f64);
            $ninetythirdF33 = $context->builder->fmul($seventysecondF33, $nF33);
            $ninetythirdF33 = $context->builder->fmul($seventysecondF33, $nF33);
            $ninetythirdF33 = $context->builder->fmul($ninetythirdF33, $nF33);
            $ninetythirdF33 = $context->builder->fmul($ninetythirdF33, $nF33);
            $ninetythirdF33 = $context->builder->fmul($ninetythirdF33, $nF33);
            $ninetythirdF33 = $context->builder->fmul($ninetythirdF33, $nF33);
            $ninetythirdF33 = $context->builder->fmul($ninetythirdF33, $nF33);
            $ninetythirdF33 = $context->builder->fmul($ninetythirdF33, $nF33);
            $ninetythirdF33 = $context->builder->fmul($ninetythirdF33, $nF33);
            $ninetythirdF33 = $context->builder->fmul($ninetythirdF33, $nF33);
            $ninetythirdF33 = $context->builder->fmul($ninetythirdF33, $nF33);
            $ninetythirdF33 = $context->builder->fmul($ninetythirdF33, $nF33);
            $ninetythirdF33 = $context->builder->fmul($ninetythirdF33, $nF33);
            $ninetythirdF33 = $context->builder->fmul($ninetythirdF33, $nF33);
            $ninetythirdF33 = $context->builder->fmul($ninetythirdF33, $nF33);
            $ninetythirdF33 = $context->builder->fmul($ninetythirdF33, $nF33);
            $ninetythirdF33 = $context->builder->fmul($ninetythirdF33, $nF33);
            $ninetythirdF33 = $context->builder->fmul($ninetythirdF33, $nF33);
            $ninetythirdF33 = $context->builder->fmul($ninetythirdF33, $nF33);
            $ninetythirdF33 = $context->builder->fmul($ninetythirdF33, $nF33);
            $ninetythirdF33 = $context->builder->fmul($ninetythirdF33, $nF33);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF33
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok33Block);
            $seventythirdVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $seventysecondLong,
                $n
            );
            $ov34 = JitLongArithOverflow::loadOverflowFlagI1($context, $seventythirdVar->longArithOverflowFlag);
            if (null === $ov34 || null === $seventythirdVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **93 expected smul overflow metadata (seventythird)');
            }
            $seventythirdLong = JITVariable::KIND_VARIABLE === $seventythirdVar->kind
                ? $context->builder->load($seventythirdVar->value)
                : $seventythirdVar->value;
            $ov34Block = BasicBlockHelper::append($context, 'pow_ninetythird_seventythird_ov');
            $ok34Block = BasicBlockHelper::append($context, 'pow_ninetythird_seventythird_ok');
            $context->builder->branchIf($ov34, $ov34Block, $ok34Block);

            $context->builder->positionAtEnd($ov34Block);
            $seventythirdF34 = $context->builder->load($seventythirdVar->longArithOverflowDoubleSlot);
            $nF34 = $context->builder->siToFp($n, $f64);
            $ninetythirdF34 = $context->builder->fmul($seventythirdF34, $nF34);
            $ninetythirdF34 = $context->builder->fmul($ninetythirdF34, $nF34);
            $ninetythirdF34 = $context->builder->fmul($ninetythirdF34, $nF34);
            $ninetythirdF34 = $context->builder->fmul($ninetythirdF34, $nF34);
            $ninetythirdF34 = $context->builder->fmul($ninetythirdF34, $nF34);
            $ninetythirdF34 = $context->builder->fmul($ninetythirdF34, $nF34);
            $ninetythirdF34 = $context->builder->fmul($ninetythirdF34, $nF34);
            $ninetythirdF34 = $context->builder->fmul($ninetythirdF34, $nF34);
            $ninetythirdF34 = $context->builder->fmul($ninetythirdF34, $nF34);
            $ninetythirdF34 = $context->builder->fmul($ninetythirdF34, $nF34);
            $ninetythirdF34 = $context->builder->fmul($ninetythirdF34, $nF34);
            $ninetythirdF34 = $context->builder->fmul($ninetythirdF34, $nF34);
            $ninetythirdF34 = $context->builder->fmul($ninetythirdF34, $nF34);
            $ninetythirdF34 = $context->builder->fmul($ninetythirdF34, $nF34);
            $ninetythirdF34 = $context->builder->fmul($ninetythirdF34, $nF34);
            $ninetythirdF34 = $context->builder->fmul($ninetythirdF34, $nF34);
            $ninetythirdF34 = $context->builder->fmul($ninetythirdF34, $nF34);
            $ninetythirdF34 = $context->builder->fmul($ninetythirdF34, $nF34);
            $ninetythirdF34 = $context->builder->fmul($ninetythirdF34, $nF34);
            $ninetythirdF34 = $context->builder->fmul($ninetythirdF34, $nF34);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF34
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok34Block);
            $seventyfourthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $seventythirdLong,
                $n
            );
            $ov35 = JitLongArithOverflow::loadOverflowFlagI1($context, $seventyfourthVar->longArithOverflowFlag);
            if (null === $ov35 || null === $seventyfourthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **93 expected smul overflow metadata (seventyfourth)');
            }
            $seventyfourthLong = JITVariable::KIND_VARIABLE === $seventyfourthVar->kind
                ? $context->builder->load($seventyfourthVar->value)
                : $seventyfourthVar->value;
            $ov35Block = BasicBlockHelper::append($context, 'pow_ninetythird_seventyfourth_ov');
            $ok35Block = BasicBlockHelper::append($context, 'pow_ninetythird_seventyfourth_ok');
            $context->builder->branchIf($ov35, $ov35Block, $ok35Block);

            $context->builder->positionAtEnd($ov35Block);
            $seventyfourthF35 = $context->builder->load($seventyfourthVar->longArithOverflowDoubleSlot);
            $nF35 = $context->builder->siToFp($n, $f64);
            $ninetythirdF35 = $context->builder->fmul($seventyfourthF35, $nF35);
            $ninetythirdF35 = $context->builder->fmul($seventyfourthF35, $nF35);
            $ninetythirdF35 = $context->builder->fmul($ninetythirdF35, $nF35);
            $ninetythirdF35 = $context->builder->fmul($ninetythirdF35, $nF35);
            $ninetythirdF35 = $context->builder->fmul($ninetythirdF35, $nF35);
            $ninetythirdF35 = $context->builder->fmul($ninetythirdF35, $nF35);
            $ninetythirdF35 = $context->builder->fmul($ninetythirdF35, $nF35);
            $ninetythirdF35 = $context->builder->fmul($ninetythirdF35, $nF35);
            $ninetythirdF35 = $context->builder->fmul($ninetythirdF35, $nF35);
            $ninetythirdF35 = $context->builder->fmul($ninetythirdF35, $nF35);
            $ninetythirdF35 = $context->builder->fmul($ninetythirdF35, $nF35);
            $ninetythirdF35 = $context->builder->fmul($ninetythirdF35, $nF35);
            $ninetythirdF35 = $context->builder->fmul($ninetythirdF35, $nF35);
            $ninetythirdF35 = $context->builder->fmul($ninetythirdF35, $nF35);
            $ninetythirdF35 = $context->builder->fmul($ninetythirdF35, $nF35);
            $ninetythirdF35 = $context->builder->fmul($ninetythirdF35, $nF35);
            $ninetythirdF35 = $context->builder->fmul($ninetythirdF35, $nF35);
            $ninetythirdF35 = $context->builder->fmul($ninetythirdF35, $nF35);
            $ninetythirdF35 = $context->builder->fmul($ninetythirdF35, $nF35);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF35
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok35Block);
            $seventyfifthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $seventyfourthLong,
                $n
            );
            $ov36 = JitLongArithOverflow::loadOverflowFlagI1($context, $seventyfifthVar->longArithOverflowFlag);
            if (null === $ov36 || null === $seventyfifthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **93 expected smul overflow metadata (seventyfifth)');
            }
            $seventyfifthLong = JITVariable::KIND_VARIABLE === $seventyfifthVar->kind
                ? $context->builder->load($seventyfifthVar->value)
                : $seventyfifthVar->value;
            $ov36Block = BasicBlockHelper::append($context, 'pow_ninetythird_seventyfifth_ov');
            $ok36Block = BasicBlockHelper::append($context, 'pow_ninetythird_seventyfifth_ok');
            $context->builder->branchIf($ov36, $ov36Block, $ok36Block);

            $context->builder->positionAtEnd($ov36Block);
            $seventyfifthF36 = $context->builder->load($seventyfifthVar->longArithOverflowDoubleSlot);
            $nF36 = $context->builder->siToFp($n, $f64);
            $ninetythirdF36 = $context->builder->fmul($seventyfifthF36, $nF36);
            $ninetythirdF36 = $context->builder->fmul($ninetythirdF36, $nF36);
            $ninetythirdF36 = $context->builder->fmul($ninetythirdF36, $nF36);
            $ninetythirdF36 = $context->builder->fmul($ninetythirdF36, $nF36);
            $ninetythirdF36 = $context->builder->fmul($ninetythirdF36, $nF36);
            $ninetythirdF36 = $context->builder->fmul($ninetythirdF36, $nF36);
            $ninetythirdF36 = $context->builder->fmul($ninetythirdF36, $nF36);
            $ninetythirdF36 = $context->builder->fmul($ninetythirdF36, $nF36);
            $ninetythirdF36 = $context->builder->fmul($ninetythirdF36, $nF36);
            $ninetythirdF36 = $context->builder->fmul($ninetythirdF36, $nF36);
            $ninetythirdF36 = $context->builder->fmul($ninetythirdF36, $nF36);
            $ninetythirdF36 = $context->builder->fmul($ninetythirdF36, $nF36);
            $ninetythirdF36 = $context->builder->fmul($ninetythirdF36, $nF36);
            $ninetythirdF36 = $context->builder->fmul($ninetythirdF36, $nF36);
            $ninetythirdF36 = $context->builder->fmul($ninetythirdF36, $nF36);
            $ninetythirdF36 = $context->builder->fmul($ninetythirdF36, $nF36);
            $ninetythirdF36 = $context->builder->fmul($ninetythirdF36, $nF36);
            $ninetythirdF36 = $context->builder->fmul($ninetythirdF36, $nF36);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF36
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok36Block);
            $seventysixthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $seventyfifthLong,
                $n
            );
            $ov37 = JitLongArithOverflow::loadOverflowFlagI1($context, $seventysixthVar->longArithOverflowFlag);
            if (null === $ov37 || null === $seventysixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **93 expected smul overflow metadata (seventysixth)');
            }
            $seventysixthLong = JITVariable::KIND_VARIABLE === $seventysixthVar->kind
                ? $context->builder->load($seventysixthVar->value)
                : $seventysixthVar->value;
            $ov37Block = BasicBlockHelper::append($context, 'pow_ninetythird_seventysixth_ov');
            $ok37Block = BasicBlockHelper::append($context, 'pow_ninetythird_seventysixth_ok');
            $context->builder->branchIf($ov37, $ov37Block, $ok37Block);

            $context->builder->positionAtEnd($ov37Block);
            $seventysixthF37 = $context->builder->load($seventysixthVar->longArithOverflowDoubleSlot);
            $nF37 = $context->builder->siToFp($n, $f64);
            $ninetythirdF37 = $context->builder->fmul($seventysixthF37, $nF37);
            $ninetythirdF37 = $context->builder->fmul($ninetythirdF37, $nF37);
            $ninetythirdF37 = $context->builder->fmul($ninetythirdF37, $nF37);
            $ninetythirdF37 = $context->builder->fmul($ninetythirdF37, $nF37);
            $ninetythirdF37 = $context->builder->fmul($ninetythirdF37, $nF37);
            $ninetythirdF37 = $context->builder->fmul($ninetythirdF37, $nF37);
            $ninetythirdF37 = $context->builder->fmul($ninetythirdF37, $nF37);
            $ninetythirdF37 = $context->builder->fmul($ninetythirdF37, $nF37);
            $ninetythirdF37 = $context->builder->fmul($ninetythirdF37, $nF37);
            $ninetythirdF37 = $context->builder->fmul($ninetythirdF37, $nF37);
            $ninetythirdF37 = $context->builder->fmul($ninetythirdF37, $nF37);
            $ninetythirdF37 = $context->builder->fmul($ninetythirdF37, $nF37);
            $ninetythirdF37 = $context->builder->fmul($ninetythirdF37, $nF37);
            $ninetythirdF37 = $context->builder->fmul($ninetythirdF37, $nF37);
            $ninetythirdF37 = $context->builder->fmul($ninetythirdF37, $nF37);
            $ninetythirdF37 = $context->builder->fmul($ninetythirdF37, $nF37);
            $ninetythirdF37 = $context->builder->fmul($ninetythirdF37, $nF37);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF37
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok37Block);
            $seventyseventhVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $seventysixthLong,
                $n
            );
            $ov38 = JitLongArithOverflow::loadOverflowFlagI1($context, $seventyseventhVar->longArithOverflowFlag);
            if (null === $ov38 || null === $seventyseventhVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **93 expected smul overflow metadata (seventyseventh)');
            }
            $seventyseventhLong = JITVariable::KIND_VARIABLE === $seventyseventhVar->kind
                ? $context->builder->load($seventyseventhVar->value)
                : $seventyseventhVar->value;
            $ov38Block = BasicBlockHelper::append($context, 'pow_ninetythird_seventyseventh_ov');
            $ok38Block = BasicBlockHelper::append($context, 'pow_ninetythird_seventyseventh_ok');
            $context->builder->branchIf($ov38, $ov38Block, $ok38Block);

            $context->builder->positionAtEnd($ov38Block);
            $seventyseventhF38 = $context->builder->load($seventyseventhVar->longArithOverflowDoubleSlot);
            $nF38 = $context->builder->siToFp($n, $f64);
            $ninetythirdF38 = $context->builder->fmul($seventyseventhF38, $nF38);
            $ninetythirdF38 = $context->builder->fmul($seventyseventhF38, $nF38);
            $ninetythirdF38 = $context->builder->fmul($ninetythirdF38, $nF38);
            $ninetythirdF38 = $context->builder->fmul($ninetythirdF38, $nF38);
            $ninetythirdF38 = $context->builder->fmul($ninetythirdF38, $nF38);
            $ninetythirdF38 = $context->builder->fmul($ninetythirdF38, $nF38);
            $ninetythirdF38 = $context->builder->fmul($ninetythirdF38, $nF38);
            $ninetythirdF38 = $context->builder->fmul($ninetythirdF38, $nF38);
            $ninetythirdF38 = $context->builder->fmul($ninetythirdF38, $nF38);
            $ninetythirdF38 = $context->builder->fmul($ninetythirdF38, $nF38);
            $ninetythirdF38 = $context->builder->fmul($ninetythirdF38, $nF38);
            $ninetythirdF38 = $context->builder->fmul($ninetythirdF38, $nF38);
            $ninetythirdF38 = $context->builder->fmul($ninetythirdF38, $nF38);
            $ninetythirdF38 = $context->builder->fmul($ninetythirdF38, $nF38);
            $ninetythirdF38 = $context->builder->fmul($ninetythirdF38, $nF38);
            $ninetythirdF38 = $context->builder->fmul($ninetythirdF38, $nF38);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF38
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok38Block);
            $seventyeighthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $seventyseventhLong,
                $n
            );
            $ov39 = JitLongArithOverflow::loadOverflowFlagI1($context, $seventyeighthVar->longArithOverflowFlag);
            if (null === $ov39 || null === $seventyeighthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **93 expected smul overflow metadata (seventyeighth)');
            }
            $seventyeighthLong = JITVariable::KIND_VARIABLE === $seventyeighthVar->kind
                ? $context->builder->load($seventyeighthVar->value)
                : $seventyeighthVar->value;
            $ov39Block = BasicBlockHelper::append($context, 'pow_ninetythird_seventyeighth_ov');
            $ok39Block = BasicBlockHelper::append($context, 'pow_ninetythird_seventyeighth_ok');
            $context->builder->branchIf($ov39, $ov39Block, $ok39Block);

            $context->builder->positionAtEnd($ov39Block);
            $seventyeighthF39 = $context->builder->load($seventyeighthVar->longArithOverflowDoubleSlot);
            $nF39 = $context->builder->siToFp($n, $f64);
            $ninetythirdF39 = $context->builder->fmul($seventyeighthF39, $nF39);
            $ninetythirdF39 = $context->builder->fmul($ninetythirdF39, $nF39);
            $ninetythirdF39 = $context->builder->fmul($ninetythirdF39, $nF39);
            $ninetythirdF39 = $context->builder->fmul($ninetythirdF39, $nF39);
            $ninetythirdF39 = $context->builder->fmul($ninetythirdF39, $nF39);
            $ninetythirdF39 = $context->builder->fmul($ninetythirdF39, $nF39);
            $ninetythirdF39 = $context->builder->fmul($ninetythirdF39, $nF39);
            $ninetythirdF39 = $context->builder->fmul($ninetythirdF39, $nF39);
            $ninetythirdF39 = $context->builder->fmul($ninetythirdF39, $nF39);
            $ninetythirdF39 = $context->builder->fmul($ninetythirdF39, $nF39);
            $ninetythirdF39 = $context->builder->fmul($ninetythirdF39, $nF39);
            $ninetythirdF39 = $context->builder->fmul($ninetythirdF39, $nF39);
            $ninetythirdF39 = $context->builder->fmul($ninetythirdF39, $nF39);
            $ninetythirdF39 = $context->builder->fmul($ninetythirdF39, $nF39);
            $ninetythirdF39 = $context->builder->fmul($ninetythirdF39, $nF39);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF39
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok39Block);
            $seventyninthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $seventyeighthLong,
                $n
            );
            $ov40 = JitLongArithOverflow::loadOverflowFlagI1($context, $seventyninthVar->longArithOverflowFlag);
            if (null === $ov40 || null === $seventyninthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **93 expected smul overflow metadata (seventyninth)');
            }
            $seventyninthLong = JITVariable::KIND_VARIABLE === $seventyninthVar->kind
                ? $context->builder->load($seventyninthVar->value)
                : $seventyninthVar->value;
            $ov40Block = BasicBlockHelper::append($context, 'pow_ninetythird_seventyninth_ov');
            $ok40Block = BasicBlockHelper::append($context, 'pow_ninetythird_seventyninth_ok');
            $context->builder->branchIf($ov40, $ov40Block, $ok40Block);

            $context->builder->positionAtEnd($ov40Block);
            $seventyninthF40 = $context->builder->load($seventyninthVar->longArithOverflowDoubleSlot);
            $nF40 = $context->builder->siToFp($n, $f64);
            $ninetythirdF40 = $context->builder->fmul($seventyninthF40, $nF40);
            $ninetythirdF40 = $context->builder->fmul($ninetythirdF40, $nF40);
            $ninetythirdF40 = $context->builder->fmul($ninetythirdF40, $nF40);
            $ninetythirdF40 = $context->builder->fmul($ninetythirdF40, $nF40);
            $ninetythirdF40 = $context->builder->fmul($ninetythirdF40, $nF40);
            $ninetythirdF40 = $context->builder->fmul($ninetythirdF40, $nF40);
            $ninetythirdF40 = $context->builder->fmul($ninetythirdF40, $nF40);
            $ninetythirdF40 = $context->builder->fmul($ninetythirdF40, $nF40);
            $ninetythirdF40 = $context->builder->fmul($ninetythirdF40, $nF40);
            $ninetythirdF40 = $context->builder->fmul($ninetythirdF40, $nF40);
            $ninetythirdF40 = $context->builder->fmul($ninetythirdF40, $nF40);
            $ninetythirdF40 = $context->builder->fmul($ninetythirdF40, $nF40);
            $ninetythirdF40 = $context->builder->fmul($ninetythirdF40, $nF40);
            $ninetythirdF40 = $context->builder->fmul($ninetythirdF40, $nF40);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF40
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok40Block);
            $eightiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $seventyninthLong,
                $n
            );
            $ov41 = JitLongArithOverflow::loadOverflowFlagI1($context, $eightiethVar->longArithOverflowFlag);
            if (null === $ov41 || null === $eightiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **93 expected smul overflow metadata (eightieth)');
            }
            $eightiethLong = JITVariable::KIND_VARIABLE === $eightiethVar->kind
                ? $context->builder->load($eightiethVar->value)
                : $eightiethVar->value;
            $ov41Block = BasicBlockHelper::append($context, 'pow_ninetythird_eightieth_ov');
            $ok41Block = BasicBlockHelper::append($context, 'pow_ninetythird_eightieth_ok');
            $context->builder->branchIf($ov41, $ov41Block, $ok41Block);

            $context->builder->positionAtEnd($ov41Block);
            $eightiethF41 = $context->builder->load($eightiethVar->longArithOverflowDoubleSlot);
            $nF41 = $context->builder->siToFp($n, $f64);
            $ninetythirdF41 = $context->builder->fmul($eightiethF41, $nF41);
            $ninetythirdF41 = $context->builder->fmul($ninetythirdF41, $nF41);
            $ninetythirdF41 = $context->builder->fmul($ninetythirdF41, $nF41);
            $ninetythirdF41 = $context->builder->fmul($ninetythirdF41, $nF41);
            $ninetythirdF41 = $context->builder->fmul($ninetythirdF41, $nF41);
            $ninetythirdF41 = $context->builder->fmul($ninetythirdF41, $nF41);
            $ninetythirdF41 = $context->builder->fmul($ninetythirdF41, $nF41);
            $ninetythirdF41 = $context->builder->fmul($ninetythirdF41, $nF41);
            $ninetythirdF41 = $context->builder->fmul($ninetythirdF41, $nF41);
            $ninetythirdF41 = $context->builder->fmul($ninetythirdF41, $nF41);
            $ninetythirdF41 = $context->builder->fmul($ninetythirdF41, $nF41);
            $ninetythirdF41 = $context->builder->fmul($ninetythirdF41, $nF41);
            $ninetythirdF41 = $context->builder->fmul($ninetythirdF41, $nF41);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF41
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok41Block);
            $eightyfirstVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $eightiethLong,
                $n
            );
            $ov42 = JitLongArithOverflow::loadOverflowFlagI1($context, $eightyfirstVar->longArithOverflowFlag);
            if (null === $ov42 || null === $eightyfirstVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **93 expected smul overflow metadata (eightyfirst)');
            }
            $eightyfirstLong = JITVariable::KIND_VARIABLE === $eightyfirstVar->kind
                ? $context->builder->load($eightyfirstVar->value)
                : $eightyfirstVar->value;
            $ov42Block = BasicBlockHelper::append($context, 'pow_ninetythird_eightyfirst_ov');
            $ok42Block = BasicBlockHelper::append($context, 'pow_ninetythird_eightyfirst_ok');
            $context->builder->branchIf($ov42, $ov42Block, $ok42Block);

            $context->builder->positionAtEnd($ov42Block);
            $eightyfirstF42 = $context->builder->load($eightyfirstVar->longArithOverflowDoubleSlot);
            $nF42 = $context->builder->siToFp($n, $f64);
            $ninetythirdF42 = $context->builder->fmul($eightyfirstF42, $nF42);
            $ninetythirdF42 = $context->builder->fmul($ninetythirdF42, $nF42);
            $ninetythirdF42 = $context->builder->fmul($ninetythirdF42, $nF42);
            $ninetythirdF42 = $context->builder->fmul($ninetythirdF42, $nF42);
            $ninetythirdF42 = $context->builder->fmul($ninetythirdF42, $nF42);
            $ninetythirdF42 = $context->builder->fmul($ninetythirdF42, $nF42);
            $ninetythirdF42 = $context->builder->fmul($ninetythirdF42, $nF42);
            $ninetythirdF42 = $context->builder->fmul($ninetythirdF42, $nF42);
            $ninetythirdF42 = $context->builder->fmul($ninetythirdF42, $nF42);
            $ninetythirdF42 = $context->builder->fmul($ninetythirdF42, $nF42);
            $ninetythirdF42 = $context->builder->fmul($ninetythirdF42, $nF42);
            $ninetythirdF42 = $context->builder->fmul($ninetythirdF42, $nF42);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF42
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok42Block);
            $eightysecondVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $eightyfirstLong,
                $n
            );
            $ov43 = JitLongArithOverflow::loadOverflowFlagI1($context, $eightysecondVar->longArithOverflowFlag);
            if (null === $ov43 || null === $eightysecondVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **93 expected smul overflow metadata (eightysecond)');
            }
            $eightysecondLong = JITVariable::KIND_VARIABLE === $eightysecondVar->kind
                ? $context->builder->load($eightysecondVar->value)
                : $eightysecondVar->value;
            $ov43Block = BasicBlockHelper::append($context, 'pow_ninetythird_eightysecond_ov');
            $ok43Block = BasicBlockHelper::append($context, 'pow_ninetythird_eightysecond_ok');
            $context->builder->branchIf($ov43, $ov43Block, $ok43Block);

            $context->builder->positionAtEnd($ov43Block);
            $eightysecondF43 = $context->builder->load($eightysecondVar->longArithOverflowDoubleSlot);
            $nF43 = $context->builder->siToFp($n, $f64);
            $ninetythirdF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $ninetythirdF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $ninetythirdF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $ninetythirdF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $ninetythirdF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $ninetythirdF43 = $context->builder->fmul($ninetythirdF43, $nF43);
            $ninetythirdF43 = $context->builder->fmul($ninetythirdF43, $nF43);
            $ninetythirdF43 = $context->builder->fmul($ninetythirdF43, $nF43);
            $ninetythirdF43 = $context->builder->fmul($ninetythirdF43, $nF43);
            $ninetythirdF43 = $context->builder->fmul($ninetythirdF43, $nF43);
            $ninetythirdF43 = $context->builder->fmul($ninetythirdF43, $nF43);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF43
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok43Block);
            $eightythirdVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $eightysecondLong,
                $n
            );
            $ov44 = JitLongArithOverflow::loadOverflowFlagI1($context, $eightythirdVar->longArithOverflowFlag);
            if (null === $ov44 || null === $eightythirdVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **93 expected smul overflow metadata (eightythird)');
            }
            $eightythirdLong = JITVariable::KIND_VARIABLE === $eightythirdVar->kind
                ? $context->builder->load($eightythirdVar->value)
                : $eightythirdVar->value;
            $ov44Block = BasicBlockHelper::append($context, 'pow_ninetythird_eightythird_ov');
            $ok44Block = BasicBlockHelper::append($context, 'pow_ninetythird_eightythird_ok');
            $context->builder->branchIf($ov44, $ov44Block, $ok44Block);

            $context->builder->positionAtEnd($ov44Block);
            $eightythirdF44 = $context->builder->load($eightythirdVar->longArithOverflowDoubleSlot);
            $nF44 = $context->builder->siToFp($n, $f64);
            $ninetythirdF44 = $context->builder->fmul($eightythirdF44, $nF44);
            $ninetythirdF44 = $context->builder->fmul($eightythirdF44, $nF44);
            $ninetythirdF44 = $context->builder->fmul($eightythirdF44, $nF44);
            $ninetythirdF44 = $context->builder->fmul($eightythirdF44, $nF44);
            $ninetythirdF44 = $context->builder->fmul($ninetythirdF44, $nF44);
            $ninetythirdF44 = $context->builder->fmul($ninetythirdF44, $nF44);
            $ninetythirdF44 = $context->builder->fmul($ninetythirdF44, $nF44);
            $ninetythirdF44 = $context->builder->fmul($ninetythirdF44, $nF44);
            $ninetythirdF44 = $context->builder->fmul($ninetythirdF44, $nF44);
            $ninetythirdF44 = $context->builder->fmul($ninetythirdF44, $nF44);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF44
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok44Block);
            $eightyfourthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $eightythirdLong,
                $n
            );
            $ov45 = JitLongArithOverflow::loadOverflowFlagI1($context, $eightyfourthVar->longArithOverflowFlag);
            if (null === $ov45 || null === $eightyfourthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **93 expected smul overflow metadata (eightyfourth)');
            }
            $eightyfourthLong = JITVariable::KIND_VARIABLE === $eightyfourthVar->kind
                ? $context->builder->load($eightyfourthVar->value)
                : $eightyfourthVar->value;
            $ov45Block = BasicBlockHelper::append($context, 'pow_ninetythird_eightyfourth_ov');
            $ok45Block = BasicBlockHelper::append($context, 'pow_ninetythird_eightyfourth_ok');
            $context->builder->branchIf($ov45, $ov45Block, $ok45Block);

            $context->builder->positionAtEnd($ov45Block);
            $eightyfourthF45 = $context->builder->load($eightyfourthVar->longArithOverflowDoubleSlot);
            $nF45 = $context->builder->siToFp($n, $f64);
            $ninetythirdF45 = $context->builder->fmul($eightyfourthF45, $nF45);
            $ninetythirdF45 = $context->builder->fmul($eightyfourthF45, $nF45);
            $ninetythirdF45 = $context->builder->fmul($eightyfourthF45, $nF45);
            $ninetythirdF45 = $context->builder->fmul($ninetythirdF45, $nF45);
            $ninetythirdF45 = $context->builder->fmul($ninetythirdF45, $nF45);
            $ninetythirdF45 = $context->builder->fmul($ninetythirdF45, $nF45);
            $ninetythirdF45 = $context->builder->fmul($ninetythirdF45, $nF45);
            $ninetythirdF45 = $context->builder->fmul($ninetythirdF45, $nF45);
            $ninetythirdF45 = $context->builder->fmul($ninetythirdF45, $nF45);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF45
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok45Block);
            $eightyfifthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $eightyfourthLong,
                $n
            );
            $ov46 = JitLongArithOverflow::loadOverflowFlagI1($context, $eightyfifthVar->longArithOverflowFlag);
            if (null === $ov46 || null === $eightyfifthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **93 expected smul overflow metadata (eightyfifth)');
            }
            $eightyfifthLong = JITVariable::KIND_VARIABLE === $eightyfifthVar->kind
                ? $context->builder->load($eightyfifthVar->value)
                : $eightyfifthVar->value;
            $ov46Block = BasicBlockHelper::append($context, 'pow_ninetythird_eightyfifth_ov');
            $ok46Block = BasicBlockHelper::append($context, 'pow_ninetythird_eightyfifth_ok');
            $context->builder->branchIf($ov46, $ov46Block, $ok46Block);

            $context->builder->positionAtEnd($ov46Block);
            $eightyfifthF46 = $context->builder->load($eightyfifthVar->longArithOverflowDoubleSlot);
            $nF46 = $context->builder->siToFp($n, $f64);
            $ninetythirdF46 = $context->builder->fmul($eightyfifthF46, $nF46);
            $ninetythirdF46 = $context->builder->fmul($eightyfifthF46, $nF46);
            $ninetythirdF46 = $context->builder->fmul($ninetythirdF46, $nF46);
            $ninetythirdF46 = $context->builder->fmul($ninetythirdF46, $nF46);
            $ninetythirdF46 = $context->builder->fmul($ninetythirdF46, $nF46);
            $ninetythirdF46 = $context->builder->fmul($ninetythirdF46, $nF46);
            $ninetythirdF46 = $context->builder->fmul($ninetythirdF46, $nF46);
            $ninetythirdF46 = $context->builder->fmul($ninetythirdF46, $nF46);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF46
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok46Block);
            $eightysixthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $eightyfifthLong,
                $n
            );
            $ov47 = JitLongArithOverflow::loadOverflowFlagI1($context, $eightysixthVar->longArithOverflowFlag);
            if (null === $ov47 || null === $eightysixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **93 expected smul overflow metadata (eightysixth)');
            }
            $eightysixthLong = JITVariable::KIND_VARIABLE === $eightysixthVar->kind
                ? $context->builder->load($eightysixthVar->value)
                : $eightysixthVar->value;
            $ov47Block = BasicBlockHelper::append($context, 'pow_ninetythird_eightysixth_ov');
            $ok47Block = BasicBlockHelper::append($context, 'pow_ninetythird_eightysixth_ok');
            $context->builder->branchIf($ov47, $ov47Block, $ok47Block);

            $context->builder->positionAtEnd($ov47Block);
            $eightysixthF47 = $context->builder->load($eightysixthVar->longArithOverflowDoubleSlot);
            $nF47 = $context->builder->siToFp($n, $f64);
            $ninetythirdF47 = $context->builder->fmul($eightysixthF47, $nF47);
            $ninetythirdF47 = $context->builder->fmul($ninetythirdF47, $nF47);
            $ninetythirdF47 = $context->builder->fmul($ninetythirdF47, $nF47);
            $ninetythirdF47 = $context->builder->fmul($ninetythirdF47, $nF47);
            $ninetythirdF47 = $context->builder->fmul($ninetythirdF47, $nF47);
            $ninetythirdF47 = $context->builder->fmul($ninetythirdF47, $nF47);
            $ninetythirdF47 = $context->builder->fmul($ninetythirdF47, $nF47);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF47
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok47Block);

            $eightyseventhVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $eightysixthLong,
                $n
            );
            $ov48 = JitLongArithOverflow::loadOverflowFlagI1($context, $eightyseventhVar->longArithOverflowFlag);
            if (null === $ov48 || null === $eightyseventhVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **93 expected smul overflow metadata (eightyseventh)');
            }
            $eightyseventhLong = JITVariable::KIND_VARIABLE === $eightyseventhVar->kind
                ? $context->builder->load($eightyseventhVar->value)
                : $eightyseventhVar->value;
            $ov48Block = BasicBlockHelper::append($context, 'pow_ninetythird_eightyseventh_ov');
            $ok48Block = BasicBlockHelper::append($context, 'pow_ninetythird_eightyseventh_ok');
            $context->builder->branchIf($ov48, $ov48Block, $ok48Block);

            $context->builder->positionAtEnd($ov48Block);
            $eightyseventhF48 = $context->builder->load($eightyseventhVar->longArithOverflowDoubleSlot);
            $nF48 = $context->builder->siToFp($n, $f64);
            $ninetythirdF48 = $context->builder->fmul($eightyseventhF48, $nF48);
            $ninetythirdF48 = $context->builder->fmul($eightyseventhF48, $nF48);
            $ninetythirdF48 = $context->builder->fmul($ninetythirdF48, $nF48);
            $ninetythirdF48 = $context->builder->fmul($ninetythirdF48, $nF48);
            $ninetythirdF48 = $context->builder->fmul($ninetythirdF48, $nF48);
            $ninetythirdF48 = $context->builder->fmul($ninetythirdF48, $nF48);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF48
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok48Block);
            $eightyeighthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $eightyseventhLong,
                $n
            );
            $ov49 = JitLongArithOverflow::loadOverflowFlagI1($context, $eightyeighthVar->longArithOverflowFlag);
            if (null === $ov49 || null === $eightyeighthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **93 expected smul overflow metadata (eightyeighth)');
            }
            $eightyeighthLong = JITVariable::KIND_VARIABLE === $eightyeighthVar->kind
                ? $context->builder->load($eightyeighthVar->value)
                : $eightyeighthVar->value;
            $ov49Block = BasicBlockHelper::append($context, 'pow_ninetythird_eightyeighth_ov');
            $ok49Block = BasicBlockHelper::append($context, 'pow_ninetythird_eightyeighth_ok');
            $context->builder->branchIf($ov49, $ov49Block, $ok49Block);

            $context->builder->positionAtEnd($ov49Block);
            $eightyeighthF49 = $context->builder->load($eightyeighthVar->longArithOverflowDoubleSlot);
            $nF49 = $context->builder->siToFp($n, $f64);
            $ninetythirdF49 = $context->builder->fmul($eightyeighthF49, $nF49);
            $ninetythirdF49 = $context->builder->fmul($ninetythirdF49, $nF49);
            $ninetythirdF49 = $context->builder->fmul($ninetythirdF49, $nF49);
            $ninetythirdF49 = $context->builder->fmul($ninetythirdF49, $nF49);
            $ninetythirdF49 = $context->builder->fmul($ninetythirdF49, $nF49);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF49
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok49Block);
            $eightyninthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $eightyeighthLong,
                $n
            );
            $ov50 = JitLongArithOverflow::loadOverflowFlagI1($context, $eightyninthVar->longArithOverflowFlag);
            if (null === $ov50 || null === $eightyninthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **93 expected smul overflow metadata (eightyninth)');
            }
            $eightyninthLong = JITVariable::KIND_VARIABLE === $eightyninthVar->kind
                ? $context->builder->load($eightyninthVar->value)
                : $eightyninthVar->value;
            $ov50Block = BasicBlockHelper::append($context, 'pow_ninetythird_eightyninth_ov');
            $ok50Block = BasicBlockHelper::append($context, 'pow_ninetythird_eightyninth_ok');
            $context->builder->branchIf($ov50, $ov50Block, $ok50Block);

            $context->builder->positionAtEnd($ov50Block);
            $eightyninthF50 = $context->builder->load($eightyninthVar->longArithOverflowDoubleSlot);
            $nF50 = $context->builder->siToFp($n, $f64);
            $ninetythirdF50 = $context->builder->fmul($eightyninthF50, $nF50);
            $ninetythirdF50 = $context->builder->fmul($ninetythirdF50, $nF50);
            $ninetythirdF50 = $context->builder->fmul($ninetythirdF50, $nF50);
            $ninetythirdF50 = $context->builder->fmul($ninetythirdF50, $nF50);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF50
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok50Block);
            $ninetiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $eightyninthLong,
                $n
            );
            $ov51 = JitLongArithOverflow::loadOverflowFlagI1($context, $ninetiethVar->longArithOverflowFlag);
            if (null === $ov51 || null === $ninetiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **93 expected smul overflow metadata (ninetieth)');
            }
            $ninetiethLong = JITVariable::KIND_VARIABLE === $ninetiethVar->kind
                ? $context->builder->load($ninetiethVar->value)
                : $ninetiethVar->value;
            $ov51Block = BasicBlockHelper::append($context, 'pow_ninetythird_ninetieth_ov');
            $ok51Block = BasicBlockHelper::append($context, 'pow_ninetythird_ninetieth_ok');
            $context->builder->branchIf($ov51, $ov51Block, $ok51Block);

            $context->builder->positionAtEnd($ov51Block);
            $ninetiethF51 = $context->builder->load($ninetiethVar->longArithOverflowDoubleSlot);
            $nF51 = $context->builder->siToFp($n, $f64);
            $ninetythirdF51 = $context->builder->fmul($ninetiethF51, $nF51);
            $ninetythirdF51 = $context->builder->fmul($ninetythirdF51, $nF51);
            $ninetythirdF51 = $context->builder->fmul($ninetythirdF51, $nF51);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF51
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok51Block);
            $ninetyfirstVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $ninetiethLong,
                $n
            );
            $ov52 = JitLongArithOverflow::loadOverflowFlagI1($context, $ninetyfirstVar->longArithOverflowFlag);
            if (null === $ov52 || null === $ninetyfirstVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **93 expected smul overflow metadata (ninetyfirst)');
            }
            $ninetyfirstLong = JITVariable::KIND_VARIABLE === $ninetyfirstVar->kind
                ? $context->builder->load($ninetyfirstVar->value)
                : $ninetyfirstVar->value;
            $ov52Block = BasicBlockHelper::append($context, 'pow_ninetythird_ninetyfirst_ov');
            $ok52Block = BasicBlockHelper::append($context, 'pow_ninetythird_ninetyfirst_ok');
            $context->builder->branchIf($ov52, $ov52Block, $ok52Block);

            $context->builder->positionAtEnd($ov52Block);
            $ninetyfirstF52 = $context->builder->load($ninetyfirstVar->longArithOverflowDoubleSlot);
            $nF52 = $context->builder->siToFp($n, $f64);
            $ninetythirdF52 = $context->builder->fmul($ninetyfirstF52, $nF52);
            $ninetythirdF52 = $context->builder->fmul($ninetythirdF52, $nF52);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF52
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok52Block);
            $ninetysecondVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $ninetyfirstLong,
                $n
            );
            $ov53 = JitLongArithOverflow::loadOverflowFlagI1($context, $ninetysecondVar->longArithOverflowFlag);
            if (null === $ov53 || null === $ninetysecondVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **93 expected smul overflow metadata (ninetysecond)');
            }
            $ninetysecondLong = JITVariable::KIND_VARIABLE === $ninetysecondVar->kind
                ? $context->builder->load($ninetysecondVar->value)
                : $ninetysecondVar->value;
            $ov53Block = BasicBlockHelper::append($context, 'pow_ninetythird_ninetysecond_ov');
            $ok53Block = BasicBlockHelper::append($context, 'pow_ninetythird_ninetysecond_ok');
            $context->builder->branchIf($ov53, $ov53Block, $ok53Block);

            $context->builder->positionAtEnd($ov53Block);
            $ninetysecondF53 = $context->builder->load($ninetysecondVar->longArithOverflowDoubleSlot);
            $nF53 = $context->builder->siToFp($n, $f64);
            $ninetythirdF53 = $context->builder->fmul($ninetysecondF53, $nF53);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetythirdF53
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok53Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $ninetysecondLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);


            $context->builder->positionAtEnd($doneBlock);

            return true;
        }

if ('ninetyfourth' === $expFold) {
            // n^91 = ninetieth*n; overflow arms +1 ×nF vs **90.
            // tenth=fifth*fifth, twentieth=tenth*tenth,
            // fortieth=twentieth*twentieth, fortysecond=fortieth*sq, fortyfourth=fortysecond*sq,
            // fortysixth=fortyfourth*sq, then fortysixth*sq*sq*n… (sixtyseventh×n).
            // Overflow arms finish in float with one extra ×nF vs **91.
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
                throw new \LogicException('pow() **94 expected smul overflow metadata (sq)');
            }
            $sqLong = JITVariable::KIND_VARIABLE === $sqVar->kind
                ? $context->builder->load($sqVar->value)
                : $sqVar->value;
            $ov1Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_sq_ov');
            $ok1Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_sq_ok');
            $doneBlock = BasicBlockHelper::append($context, 'pow_ninetyfourth_done');
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
            $ninetyfourthFSq = $context->builder->fmul($fortyfourthF, $sqF);
            $ninetyfourthFEighth = $context->builder->fmul($ninetyfourthFSq, $sqF);
            $ninetyfourthF = $context->builder->fmul($ninetyfourthFEighth, $sqF);
            $nF = $context->builder->siToFp($n, $f64);
            $ninetyfourthF = $context->builder->fmul($ninetyfourthF, $nF);
            $ninetyfourthF = $context->builder->fmul($ninetyfourthF, $nF);
            $ninetyfourthF = $context->builder->fmul($ninetyfourthF, $nF);
            $ninetyfourthF = $context->builder->fmul($ninetyfourthF, $nF);
            $ninetyfourthF = $context->builder->fmul($ninetyfourthF, $nF);
            $ninetyfourthF = $context->builder->fmul($ninetyfourthF, $nF);
            $ninetyfourthF = $context->builder->fmul($ninetyfourthF, $nF);
            $ninetyfourthF = $context->builder->fmul($ninetyfourthF, $nF);
            $ninetyfourthF = $context->builder->fmul($ninetyfourthF, $nF);
            $ninetyfourthF = $context->builder->fmul($ninetyfourthF, $nF);
                $ninetyfourthF = $context->builder->fmul($ninetyfourthF, $nF);
                    $ninetyfourthF = $context->builder->fmul($ninetyfourthF, $nF);
                        $ninetyfourthF = $context->builder->fmul($ninetyfourthF, $nF);
                        $ninetyfourthF = $context->builder->fmul($ninetyfourthF, $nF);
                        $ninetyfourthF = $context->builder->fmul($ninetyfourthF, $nF);
                        $ninetyfourthF = $context->builder->fmul($ninetyfourthF, $nF);
                        $ninetyfourthF = $context->builder->fmul($ninetyfourthF, $nF);
                        $ninetyfourthF = $context->builder->fmul($ninetyfourthF, $nF);
                        $ninetyfourthF = $context->builder->fmul($ninetyfourthF, $nF);
            $ninetyfourthF = $context->builder->fmul($ninetyfourthF, $nF);
            $ninetyfourthF = $context->builder->fmul($ninetyfourthF, $nF);
            $ninetyfourthF = $context->builder->fmul($ninetyfourthF, $nF);
            $ninetyfourthF = $context->builder->fmul($ninetyfourthF, $nF);
            $ninetyfourthF = $context->builder->fmul($ninetyfourthF, $nF);

            $ninetyfourthF = $context->builder->fmul($ninetyfourthF, $nF);
            $ninetyfourthF = $context->builder->fmul($ninetyfourthF, $nF);
            $ninetyfourthF = $context->builder->fmul($ninetyfourthF, $nF);
            $ninetyfourthF = $context->builder->fmul($ninetyfourthF, $nF);
            $ninetyfourthF = $context->builder->fmul($ninetyfourthF, $nF);
            $ninetyfourthF = $context->builder->fmul($ninetyfourthF, $nF);
            $ninetyfourthF = $context->builder->fmul($ninetyfourthF, $nF);
            $ninetyfourthF = $context->builder->fmul($ninetyfourthF, $nF);
            $ninetyfourthF = $context->builder->fmul($ninetyfourthF, $nF);
            $ninetyfourthF = $context->builder->fmul($ninetyfourthF, $nF);
            $ninetyfourthF = $context->builder->fmul($ninetyfourthF, $nF);
            $ninetyfourthF = $context->builder->fmul($ninetyfourthF, $nF);
            $ninetyfourthF = $context->builder->fmul($ninetyfourthF, $nF);
            $ninetyfourthF = $context->builder->fmul($ninetyfourthF, $nF);
            $ninetyfourthF = $context->builder->fmul($ninetyfourthF, $nF);
            $ninetyfourthF = $context->builder->fmul($ninetyfourthF, $nF);
            $ninetyfourthF = $context->builder->fmul($ninetyfourthF, $nF);
            $ninetyfourthF = $context->builder->fmul($ninetyfourthF, $nF);
            $ninetyfourthF = $context->builder->fmul($ninetyfourthF, $nF);
            $ninetyfourthF = $context->builder->fmul($ninetyfourthF, $nF);
            $ninetyfourthF = $context->builder->fmul($ninetyfourthF, $nF);
            $ninetyfourthF = $context->builder->fmul($ninetyfourthF, $nF);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF
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
                throw new \LogicException('pow() **94 expected smul overflow metadata (cu)');
            }
            $cuLong = JITVariable::KIND_VARIABLE === $cuVar->kind
                ? $context->builder->load($cuVar->value)
                : $cuVar->value;
            $ov2Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_cu_ov');
            $ok2Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_cu_ok');
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
            $ninetyfourthF2Sq = $context->builder->fmul($fortyfourthF2, $sqF2);
            $ninetyfourthF2Eighth = $context->builder->fmul($ninetyfourthF2Sq, $sqF2);
            $ninetyfourthF2 = $context->builder->fmul($ninetyfourthF2Eighth, $sqF2);
            $nF2 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF2 = $context->builder->fmul($ninetyfourthF2, $nF2);
            $ninetyfourthF2 = $context->builder->fmul($ninetyfourthF2, $nF2);
            $ninetyfourthF2 = $context->builder->fmul($ninetyfourthF2, $nF2);
            $ninetyfourthF2 = $context->builder->fmul($ninetyfourthF2, $nF2);
            $ninetyfourthF2 = $context->builder->fmul($ninetyfourthF2, $nF2);
            $ninetyfourthF2 = $context->builder->fmul($ninetyfourthF2, $nF2);
            $ninetyfourthF2 = $context->builder->fmul($ninetyfourthF2, $nF2);
            $ninetyfourthF2 = $context->builder->fmul($ninetyfourthF2, $nF2);
            $ninetyfourthF2 = $context->builder->fmul($ninetyfourthF2, $nF2);
            $ninetyfourthF2 = $context->builder->fmul($ninetyfourthF2, $nF2);
                $ninetyfourthF2 = $context->builder->fmul($ninetyfourthF2, $nF2);
                    $ninetyfourthF2 = $context->builder->fmul($ninetyfourthF2, $nF2);
                        $ninetyfourthF2 = $context->builder->fmul($ninetyfourthF2, $nF2);
                        $ninetyfourthF2 = $context->builder->fmul($ninetyfourthF2, $nF2);
                        $ninetyfourthF2 = $context->builder->fmul($ninetyfourthF2, $nF2);
                        $ninetyfourthF2 = $context->builder->fmul($ninetyfourthF2, $nF2);
                        $ninetyfourthF2 = $context->builder->fmul($ninetyfourthF2, $nF2);
                        $ninetyfourthF2 = $context->builder->fmul($ninetyfourthF2, $nF2);
                        $ninetyfourthF2 = $context->builder->fmul($ninetyfourthF2, $nF2);
            $ninetyfourthF2 = $context->builder->fmul($ninetyfourthF2, $nF2);
            $ninetyfourthF2 = $context->builder->fmul($ninetyfourthF2, $nF2);
            $ninetyfourthF2 = $context->builder->fmul($ninetyfourthF2, $nF2);
            $ninetyfourthF2 = $context->builder->fmul($ninetyfourthF2, $nF2);
            $ninetyfourthF2 = $context->builder->fmul($ninetyfourthF2, $nF2);

            $ninetyfourthF2 = $context->builder->fmul($ninetyfourthF2, $nF2);
            $ninetyfourthF2 = $context->builder->fmul($ninetyfourthF2, $nF2);
            $ninetyfourthF2 = $context->builder->fmul($ninetyfourthF2, $nF2);
            $ninetyfourthF2 = $context->builder->fmul($ninetyfourthF2, $nF2);
            $ninetyfourthF2 = $context->builder->fmul($ninetyfourthF2, $nF2);
            $ninetyfourthF2 = $context->builder->fmul($ninetyfourthF2, $nF2);
            $ninetyfourthF2 = $context->builder->fmul($ninetyfourthF2, $nF2);
            $ninetyfourthF2 = $context->builder->fmul($ninetyfourthF2, $nF2);
            $ninetyfourthF2 = $context->builder->fmul($ninetyfourthF2, $nF2);
            $ninetyfourthF2 = $context->builder->fmul($ninetyfourthF2, $nF2);
            $ninetyfourthF2 = $context->builder->fmul($ninetyfourthF2, $nF2);
            $ninetyfourthF2 = $context->builder->fmul($ninetyfourthF2, $nF2);
            $ninetyfourthF2 = $context->builder->fmul($ninetyfourthF2, $nF2);
            $ninetyfourthF2 = $context->builder->fmul($ninetyfourthF2, $nF2);
            $ninetyfourthF2 = $context->builder->fmul($ninetyfourthF2, $nF2);
            $ninetyfourthF2 = $context->builder->fmul($ninetyfourthF2, $nF2);
            $ninetyfourthF2 = $context->builder->fmul($ninetyfourthF2, $nF2);
            $ninetyfourthF2 = $context->builder->fmul($ninetyfourthF2, $nF2);
            $ninetyfourthF2 = $context->builder->fmul($ninetyfourthF2, $nF2);
            $ninetyfourthF2 = $context->builder->fmul($ninetyfourthF2, $nF2);
            $ninetyfourthF2 = $context->builder->fmul($ninetyfourthF2, $nF2);
            $ninetyfourthF2 = $context->builder->fmul($ninetyfourthF2, $nF2);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF2
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
                throw new \LogicException('pow() **94 expected smul overflow metadata (fifth)');
            }
            $fifthLong = JITVariable::KIND_VARIABLE === $fifthVar->kind
                ? $context->builder->load($fifthVar->value)
                : $fifthVar->value;
            $ov3Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_fifth_ov');
            $ok3Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_fifth_ok');
            $context->builder->branchIf($ov3, $ov3Block, $ok3Block);

            $context->builder->positionAtEnd($ov3Block);
            $fifthF2 = $context->builder->load($fifthVar->longArithOverflowDoubleSlot);
            $tenthF3 = $context->builder->fmul($fifthF2, $fifthF2);
            $twentiethF3 = $context->builder->fmul($tenthF3, $tenthF3);
            $fortiethF3 = $context->builder->fmul($twentiethF3, $twentiethF3);
            $sqF3 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF3 = $context->builder->fmul($fortiethF3, $sqF3);
            $fortyfourthF3 = $context->builder->fmul($fortysecondF3, $sqF3);
            $ninetyfourthF3Sq = $context->builder->fmul($fortyfourthF3, $sqF3);
            $ninetyfourthF3Eighth = $context->builder->fmul($ninetyfourthF3Sq, $sqF3);
            $ninetyfourthF3 = $context->builder->fmul($ninetyfourthF3Eighth, $sqF3);
            $nF3 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF3 = $context->builder->fmul($ninetyfourthF3, $nF3);
            $ninetyfourthF3 = $context->builder->fmul($ninetyfourthF3, $nF3);
            $ninetyfourthF3 = $context->builder->fmul($ninetyfourthF3, $nF3);
            $ninetyfourthF3 = $context->builder->fmul($ninetyfourthF3, $nF3);
            $ninetyfourthF3 = $context->builder->fmul($ninetyfourthF3, $nF3);
            $ninetyfourthF3 = $context->builder->fmul($ninetyfourthF3, $nF3);
            $ninetyfourthF3 = $context->builder->fmul($ninetyfourthF3, $nF3);
            $ninetyfourthF3 = $context->builder->fmul($ninetyfourthF3, $nF3);
            $ninetyfourthF3 = $context->builder->fmul($ninetyfourthF3, $nF3);
            $ninetyfourthF3 = $context->builder->fmul($ninetyfourthF3, $nF3);
                $ninetyfourthF3 = $context->builder->fmul($ninetyfourthF3, $nF3);
                    $ninetyfourthF3 = $context->builder->fmul($ninetyfourthF3, $nF3);
                        $ninetyfourthF3 = $context->builder->fmul($ninetyfourthF3, $nF3);
                        $ninetyfourthF3 = $context->builder->fmul($ninetyfourthF3, $nF3);
                        $ninetyfourthF3 = $context->builder->fmul($ninetyfourthF3, $nF3);
                        $ninetyfourthF3 = $context->builder->fmul($ninetyfourthF3, $nF3);
                        $ninetyfourthF3 = $context->builder->fmul($ninetyfourthF3, $nF3);
                        $ninetyfourthF3 = $context->builder->fmul($ninetyfourthF3, $nF3);
                        $ninetyfourthF3 = $context->builder->fmul($ninetyfourthF3, $nF3);
            $ninetyfourthF3 = $context->builder->fmul($ninetyfourthF3, $nF3);
            $ninetyfourthF3 = $context->builder->fmul($ninetyfourthF3, $nF3);
            $ninetyfourthF3 = $context->builder->fmul($ninetyfourthF3, $nF3);
            $ninetyfourthF3 = $context->builder->fmul($ninetyfourthF3, $nF3);
            $ninetyfourthF3 = $context->builder->fmul($ninetyfourthF3, $nF3);

            $ninetyfourthF3 = $context->builder->fmul($ninetyfourthF3, $nF3);
            $ninetyfourthF3 = $context->builder->fmul($ninetyfourthF3, $nF3);
            $ninetyfourthF3 = $context->builder->fmul($ninetyfourthF3, $nF3);
            $ninetyfourthF3 = $context->builder->fmul($ninetyfourthF3, $nF3);
            $ninetyfourthF3 = $context->builder->fmul($ninetyfourthF3, $nF3);
            $ninetyfourthF3 = $context->builder->fmul($ninetyfourthF3, $nF3);
            $ninetyfourthF3 = $context->builder->fmul($ninetyfourthF3, $nF3);
            $ninetyfourthF3 = $context->builder->fmul($ninetyfourthF3, $nF3);
            $ninetyfourthF3 = $context->builder->fmul($ninetyfourthF3, $nF3);
            $ninetyfourthF3 = $context->builder->fmul($ninetyfourthF3, $nF3);
            $ninetyfourthF3 = $context->builder->fmul($ninetyfourthF3, $nF3);
            $ninetyfourthF3 = $context->builder->fmul($ninetyfourthF3, $nF3);
            $ninetyfourthF3 = $context->builder->fmul($ninetyfourthF3, $nF3);
            $ninetyfourthF3 = $context->builder->fmul($ninetyfourthF3, $nF3);
            $ninetyfourthF3 = $context->builder->fmul($ninetyfourthF3, $nF3);
            $ninetyfourthF3 = $context->builder->fmul($ninetyfourthF3, $nF3);
            $ninetyfourthF3 = $context->builder->fmul($ninetyfourthF3, $nF3);
            $ninetyfourthF3 = $context->builder->fmul($ninetyfourthF3, $nF3);
            $ninetyfourthF3 = $context->builder->fmul($ninetyfourthF3, $nF3);
            $ninetyfourthF3 = $context->builder->fmul($ninetyfourthF3, $nF3);
            $ninetyfourthF3 = $context->builder->fmul($ninetyfourthF3, $nF3);
            $ninetyfourthF3 = $context->builder->fmul($ninetyfourthF3, $nF3);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF3
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
                throw new \LogicException('pow() **94 expected smul overflow metadata (tenth)');
            }
            $tenthLong = JITVariable::KIND_VARIABLE === $tenthVar->kind
                ? $context->builder->load($tenthVar->value)
                : $tenthVar->value;
            $ov4Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_tenth_ov');
            $ok4Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_tenth_ok');
            $context->builder->branchIf($ov4, $ov4Block, $ok4Block);

            $context->builder->positionAtEnd($ov4Block);
            $tenthF4 = $context->builder->load($tenthVar->longArithOverflowDoubleSlot);
            $twentiethF4 = $context->builder->fmul($tenthF4, $tenthF4);
            $fortiethF4 = $context->builder->fmul($twentiethF4, $twentiethF4);
            $sqF4 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF4 = $context->builder->fmul($fortiethF4, $sqF4);
            $fortyfourthF4 = $context->builder->fmul($fortysecondF4, $sqF4);
            $ninetyfourthF4Sq = $context->builder->fmul($fortyfourthF4, $sqF4);
            $ninetyfourthF4Eighth = $context->builder->fmul($ninetyfourthF4Sq, $sqF4);
            $ninetyfourthF4 = $context->builder->fmul($ninetyfourthF4Eighth, $sqF4);
            $nF4 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF4 = $context->builder->fmul($ninetyfourthF4, $nF4);
            $ninetyfourthF4 = $context->builder->fmul($ninetyfourthF4, $nF4);
            $ninetyfourthF4 = $context->builder->fmul($ninetyfourthF4, $nF4);
            $ninetyfourthF4 = $context->builder->fmul($ninetyfourthF4, $nF4);
            $ninetyfourthF4 = $context->builder->fmul($ninetyfourthF4, $nF4);
            $ninetyfourthF4 = $context->builder->fmul($ninetyfourthF4, $nF4);
            $ninetyfourthF4 = $context->builder->fmul($ninetyfourthF4, $nF4);
            $ninetyfourthF4 = $context->builder->fmul($ninetyfourthF4, $nF4);
            $ninetyfourthF4 = $context->builder->fmul($ninetyfourthF4, $nF4);
            $ninetyfourthF4 = $context->builder->fmul($ninetyfourthF4, $nF4);
                $ninetyfourthF4 = $context->builder->fmul($ninetyfourthF4, $nF4);
                    $ninetyfourthF4 = $context->builder->fmul($ninetyfourthF4, $nF4);
                        $ninetyfourthF4 = $context->builder->fmul($ninetyfourthF4, $nF4);
                        $ninetyfourthF4 = $context->builder->fmul($ninetyfourthF4, $nF4);
                        $ninetyfourthF4 = $context->builder->fmul($ninetyfourthF4, $nF4);
                        $ninetyfourthF4 = $context->builder->fmul($ninetyfourthF4, $nF4);
                        $ninetyfourthF4 = $context->builder->fmul($ninetyfourthF4, $nF4);
                        $ninetyfourthF4 = $context->builder->fmul($ninetyfourthF4, $nF4);
                        $ninetyfourthF4 = $context->builder->fmul($ninetyfourthF4, $nF4);
            $ninetyfourthF4 = $context->builder->fmul($ninetyfourthF4, $nF4);
            $ninetyfourthF4 = $context->builder->fmul($ninetyfourthF4, $nF4);
            $ninetyfourthF4 = $context->builder->fmul($ninetyfourthF4, $nF4);
            $ninetyfourthF4 = $context->builder->fmul($ninetyfourthF4, $nF4);
            $ninetyfourthF4 = $context->builder->fmul($ninetyfourthF4, $nF4);

            $ninetyfourthF4 = $context->builder->fmul($ninetyfourthF4, $nF4);
            $ninetyfourthF4 = $context->builder->fmul($ninetyfourthF4, $nF4);
            $ninetyfourthF4 = $context->builder->fmul($ninetyfourthF4, $nF4);
            $ninetyfourthF4 = $context->builder->fmul($ninetyfourthF4, $nF4);
            $ninetyfourthF4 = $context->builder->fmul($ninetyfourthF4, $nF4);
            $ninetyfourthF4 = $context->builder->fmul($ninetyfourthF4, $nF4);
            $ninetyfourthF4 = $context->builder->fmul($ninetyfourthF4, $nF4);
            $ninetyfourthF4 = $context->builder->fmul($ninetyfourthF4, $nF4);
            $ninetyfourthF4 = $context->builder->fmul($ninetyfourthF4, $nF4);
            $ninetyfourthF4 = $context->builder->fmul($ninetyfourthF4, $nF4);
            $ninetyfourthF4 = $context->builder->fmul($ninetyfourthF4, $nF4);
            $ninetyfourthF4 = $context->builder->fmul($ninetyfourthF4, $nF4);
            $ninetyfourthF4 = $context->builder->fmul($ninetyfourthF4, $nF4);
            $ninetyfourthF4 = $context->builder->fmul($ninetyfourthF4, $nF4);
            $ninetyfourthF4 = $context->builder->fmul($ninetyfourthF4, $nF4);
            $ninetyfourthF4 = $context->builder->fmul($ninetyfourthF4, $nF4);
            $ninetyfourthF4 = $context->builder->fmul($ninetyfourthF4, $nF4);
            $ninetyfourthF4 = $context->builder->fmul($ninetyfourthF4, $nF4);
            $ninetyfourthF4 = $context->builder->fmul($ninetyfourthF4, $nF4);
            $ninetyfourthF4 = $context->builder->fmul($ninetyfourthF4, $nF4);
            $ninetyfourthF4 = $context->builder->fmul($ninetyfourthF4, $nF4);
            $ninetyfourthF4 = $context->builder->fmul($ninetyfourthF4, $nF4);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF4
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
                throw new \LogicException('pow() **94 expected smul overflow metadata (twentieth)');
            }
            $twentiethLong = JITVariable::KIND_VARIABLE === $twentiethVar->kind
                ? $context->builder->load($twentiethVar->value)
                : $twentiethVar->value;
            $ov5Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_twentieth_ov');
            $ok5Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_twentieth_ok');
            $context->builder->branchIf($ov5, $ov5Block, $ok5Block);

            $context->builder->positionAtEnd($ov5Block);
            $twentiethF5 = $context->builder->load($twentiethVar->longArithOverflowDoubleSlot);
            $fortiethF5 = $context->builder->fmul($twentiethF5, $twentiethF5);
            $sqF5 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF5 = $context->builder->fmul($fortiethF5, $sqF5);
            $fortyfourthF5 = $context->builder->fmul($fortysecondF5, $sqF5);
            $ninetyfourthF5Sq = $context->builder->fmul($fortyfourthF5, $sqF5);
            $ninetyfourthF5Eighth = $context->builder->fmul($ninetyfourthF5Sq, $sqF5);
            $ninetyfourthF5 = $context->builder->fmul($ninetyfourthF5Eighth, $sqF5);
            $nF5 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF5 = $context->builder->fmul($ninetyfourthF5, $nF5);
            $ninetyfourthF5 = $context->builder->fmul($ninetyfourthF5, $nF5);
            $ninetyfourthF5 = $context->builder->fmul($ninetyfourthF5, $nF5);
            $ninetyfourthF5 = $context->builder->fmul($ninetyfourthF5, $nF5);
            $ninetyfourthF5 = $context->builder->fmul($ninetyfourthF5, $nF5);
            $ninetyfourthF5 = $context->builder->fmul($ninetyfourthF5, $nF5);
            $ninetyfourthF5 = $context->builder->fmul($ninetyfourthF5, $nF5);
            $ninetyfourthF5 = $context->builder->fmul($ninetyfourthF5, $nF5);
            $ninetyfourthF5 = $context->builder->fmul($ninetyfourthF5, $nF5);
            $ninetyfourthF5 = $context->builder->fmul($ninetyfourthF5, $nF5);
                $ninetyfourthF5 = $context->builder->fmul($ninetyfourthF5, $nF5);
                    $ninetyfourthF5 = $context->builder->fmul($ninetyfourthF5, $nF5);
                        $ninetyfourthF5 = $context->builder->fmul($ninetyfourthF5, $nF5);
                        $ninetyfourthF5 = $context->builder->fmul($ninetyfourthF5, $nF5);
                        $ninetyfourthF5 = $context->builder->fmul($ninetyfourthF5, $nF5);
                        $ninetyfourthF5 = $context->builder->fmul($ninetyfourthF5, $nF5);
                        $ninetyfourthF5 = $context->builder->fmul($ninetyfourthF5, $nF5);
                        $ninetyfourthF5 = $context->builder->fmul($ninetyfourthF5, $nF5);
                        $ninetyfourthF5 = $context->builder->fmul($ninetyfourthF5, $nF5);
            $ninetyfourthF5 = $context->builder->fmul($ninetyfourthF5, $nF5);
            $ninetyfourthF5 = $context->builder->fmul($ninetyfourthF5, $nF5);
            $ninetyfourthF5 = $context->builder->fmul($ninetyfourthF5, $nF5);
            $ninetyfourthF5 = $context->builder->fmul($ninetyfourthF5, $nF5);
            $ninetyfourthF5 = $context->builder->fmul($ninetyfourthF5, $nF5);

            $ninetyfourthF5 = $context->builder->fmul($ninetyfourthF5, $nF5);
            $ninetyfourthF5 = $context->builder->fmul($ninetyfourthF5, $nF5);
            $ninetyfourthF5 = $context->builder->fmul($ninetyfourthF5, $nF5);
            $ninetyfourthF5 = $context->builder->fmul($ninetyfourthF5, $nF5);
            $ninetyfourthF5 = $context->builder->fmul($ninetyfourthF5, $nF5);
            $ninetyfourthF5 = $context->builder->fmul($ninetyfourthF5, $nF5);
            $ninetyfourthF5 = $context->builder->fmul($ninetyfourthF5, $nF5);
            $ninetyfourthF5 = $context->builder->fmul($ninetyfourthF5, $nF5);
            $ninetyfourthF5 = $context->builder->fmul($ninetyfourthF5, $nF5);
            $ninetyfourthF5 = $context->builder->fmul($ninetyfourthF5, $nF5);
            $ninetyfourthF5 = $context->builder->fmul($ninetyfourthF5, $nF5);
            $ninetyfourthF5 = $context->builder->fmul($ninetyfourthF5, $nF5);
            $ninetyfourthF5 = $context->builder->fmul($ninetyfourthF5, $nF5);
            $ninetyfourthF5 = $context->builder->fmul($ninetyfourthF5, $nF5);
            $ninetyfourthF5 = $context->builder->fmul($ninetyfourthF5, $nF5);
            $ninetyfourthF5 = $context->builder->fmul($ninetyfourthF5, $nF5);
            $ninetyfourthF5 = $context->builder->fmul($ninetyfourthF5, $nF5);
            $ninetyfourthF5 = $context->builder->fmul($ninetyfourthF5, $nF5);
            $ninetyfourthF5 = $context->builder->fmul($ninetyfourthF5, $nF5);
            $ninetyfourthF5 = $context->builder->fmul($ninetyfourthF5, $nF5);
            $ninetyfourthF5 = $context->builder->fmul($ninetyfourthF5, $nF5);
            $ninetyfourthF5 = $context->builder->fmul($ninetyfourthF5, $nF5);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF5
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
                throw new \LogicException('pow() **94 expected smul overflow metadata (fortieth)');
            }
            $fortiethLong = JITVariable::KIND_VARIABLE === $fortiethVar->kind
                ? $context->builder->load($fortiethVar->value)
                : $fortiethVar->value;
            $ov6Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_fortieth_ov');
            $ok6Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_fortieth_ok');
            $context->builder->branchIf($ov6, $ov6Block, $ok6Block);

            $context->builder->positionAtEnd($ov6Block);
            $fortiethF6 = $context->builder->load($fortiethVar->longArithOverflowDoubleSlot);
            $sqF6 = $context->builder->siToFp($sqLong, $f64);
            $fortysecondF6 = $context->builder->fmul($fortiethF6, $sqF6);
            $fortyfourthF6 = $context->builder->fmul($fortysecondF6, $sqF6);
            $ninetyfourthF6Sq = $context->builder->fmul($fortyfourthF6, $sqF6);
            $ninetyfourthF6Eighth = $context->builder->fmul($ninetyfourthF6Sq, $sqF6);
            $ninetyfourthF6 = $context->builder->fmul($ninetyfourthF6Eighth, $sqF6);
            $nF6 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF6 = $context->builder->fmul($ninetyfourthF6, $nF6);
            $ninetyfourthF6 = $context->builder->fmul($ninetyfourthF6, $nF6);
            $ninetyfourthF6 = $context->builder->fmul($ninetyfourthF6, $nF6);
            $ninetyfourthF6 = $context->builder->fmul($ninetyfourthF6, $nF6);
            $ninetyfourthF6 = $context->builder->fmul($ninetyfourthF6, $nF6);
            $ninetyfourthF6 = $context->builder->fmul($ninetyfourthF6, $nF6);
            $ninetyfourthF6 = $context->builder->fmul($ninetyfourthF6, $nF6);
            $ninetyfourthF6 = $context->builder->fmul($ninetyfourthF6, $nF6);
            $ninetyfourthF6 = $context->builder->fmul($ninetyfourthF6, $nF6);
            $ninetyfourthF6 = $context->builder->fmul($ninetyfourthF6, $nF6);
                $ninetyfourthF6 = $context->builder->fmul($ninetyfourthF6, $nF6);
                    $ninetyfourthF6 = $context->builder->fmul($ninetyfourthF6, $nF6);
                        $ninetyfourthF6 = $context->builder->fmul($ninetyfourthF6, $nF6);
                        $ninetyfourthF6 = $context->builder->fmul($ninetyfourthF6, $nF6);
                        $ninetyfourthF6 = $context->builder->fmul($ninetyfourthF6, $nF6);
                        $ninetyfourthF6 = $context->builder->fmul($ninetyfourthF6, $nF6);
                        $ninetyfourthF6 = $context->builder->fmul($ninetyfourthF6, $nF6);
                        $ninetyfourthF6 = $context->builder->fmul($ninetyfourthF6, $nF6);
                        $ninetyfourthF6 = $context->builder->fmul($ninetyfourthF6, $nF6);
            $ninetyfourthF6 = $context->builder->fmul($ninetyfourthF6, $nF6);
            $ninetyfourthF6 = $context->builder->fmul($ninetyfourthF6, $nF6);
            $ninetyfourthF6 = $context->builder->fmul($ninetyfourthF6, $nF6);
            $ninetyfourthF6 = $context->builder->fmul($ninetyfourthF6, $nF6);
            $ninetyfourthF6 = $context->builder->fmul($ninetyfourthF6, $nF6);

            $ninetyfourthF6 = $context->builder->fmul($ninetyfourthF6, $nF6);
            $ninetyfourthF6 = $context->builder->fmul($ninetyfourthF6, $nF6);
            $ninetyfourthF6 = $context->builder->fmul($ninetyfourthF6, $nF6);
            $ninetyfourthF6 = $context->builder->fmul($ninetyfourthF6, $nF6);
            $ninetyfourthF6 = $context->builder->fmul($ninetyfourthF6, $nF6);
            $ninetyfourthF6 = $context->builder->fmul($ninetyfourthF6, $nF6);
            $ninetyfourthF6 = $context->builder->fmul($ninetyfourthF6, $nF6);
            $ninetyfourthF6 = $context->builder->fmul($ninetyfourthF6, $nF6);
            $ninetyfourthF6 = $context->builder->fmul($ninetyfourthF6, $nF6);
            $ninetyfourthF6 = $context->builder->fmul($ninetyfourthF6, $nF6);
            $ninetyfourthF6 = $context->builder->fmul($ninetyfourthF6, $nF6);
            $ninetyfourthF6 = $context->builder->fmul($ninetyfourthF6, $nF6);
            $ninetyfourthF6 = $context->builder->fmul($ninetyfourthF6, $nF6);
            $ninetyfourthF6 = $context->builder->fmul($ninetyfourthF6, $nF6);
            $ninetyfourthF6 = $context->builder->fmul($ninetyfourthF6, $nF6);
            $ninetyfourthF6 = $context->builder->fmul($ninetyfourthF6, $nF6);
            $ninetyfourthF6 = $context->builder->fmul($ninetyfourthF6, $nF6);
            $ninetyfourthF6 = $context->builder->fmul($ninetyfourthF6, $nF6);
            $ninetyfourthF6 = $context->builder->fmul($ninetyfourthF6, $nF6);
            $ninetyfourthF6 = $context->builder->fmul($ninetyfourthF6, $nF6);
            $ninetyfourthF6 = $context->builder->fmul($ninetyfourthF6, $nF6);
            $ninetyfourthF6 = $context->builder->fmul($ninetyfourthF6, $nF6);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF6
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
                throw new \LogicException('pow() **94 expected smul overflow metadata (fortysecond)');
            }
            $fortysecondLong = JITVariable::KIND_VARIABLE === $fortysecondVar->kind
                ? $context->builder->load($fortysecondVar->value)
                : $fortysecondVar->value;
            $ov7Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_fortysecond_ov');
            $ok7Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_fortysecond_ok');
            $context->builder->branchIf($ov7, $ov7Block, $ok7Block);

            $context->builder->positionAtEnd($ov7Block);
            $fortysecondF7 = $context->builder->load($fortysecondVar->longArithOverflowDoubleSlot);
            $sqF7 = $context->builder->siToFp($sqLong, $f64);
            $fortyfourthF7 = $context->builder->fmul($fortysecondF7, $sqF7);
            $ninetyfourthF7Sq = $context->builder->fmul($fortyfourthF7, $sqF7);
            $ninetyfourthF7Eighth = $context->builder->fmul($ninetyfourthF7Sq, $sqF7);
            $ninetyfourthF7 = $context->builder->fmul($ninetyfourthF7Eighth, $sqF7);
            $nF7 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF7 = $context->builder->fmul($ninetyfourthF7, $nF7);
            $ninetyfourthF7 = $context->builder->fmul($ninetyfourthF7, $nF7);
            $ninetyfourthF7 = $context->builder->fmul($ninetyfourthF7, $nF7);
            $ninetyfourthF7 = $context->builder->fmul($ninetyfourthF7, $nF7);
            $ninetyfourthF7 = $context->builder->fmul($ninetyfourthF7, $nF7);
            $ninetyfourthF7 = $context->builder->fmul($ninetyfourthF7, $nF7);
            $ninetyfourthF7 = $context->builder->fmul($ninetyfourthF7, $nF7);
            $ninetyfourthF7 = $context->builder->fmul($ninetyfourthF7, $nF7);
            $ninetyfourthF7 = $context->builder->fmul($ninetyfourthF7, $nF7);
            $ninetyfourthF7 = $context->builder->fmul($ninetyfourthF7, $nF7);
                $ninetyfourthF7 = $context->builder->fmul($ninetyfourthF7, $nF7);
                    $ninetyfourthF7 = $context->builder->fmul($ninetyfourthF7, $nF7);
                        $ninetyfourthF7 = $context->builder->fmul($ninetyfourthF7, $nF7);
                        $ninetyfourthF7 = $context->builder->fmul($ninetyfourthF7, $nF7);
                        $ninetyfourthF7 = $context->builder->fmul($ninetyfourthF7, $nF7);
                        $ninetyfourthF7 = $context->builder->fmul($ninetyfourthF7, $nF7);
                        $ninetyfourthF7 = $context->builder->fmul($ninetyfourthF7, $nF7);
                        $ninetyfourthF7 = $context->builder->fmul($ninetyfourthF7, $nF7);
                        $ninetyfourthF7 = $context->builder->fmul($ninetyfourthF7, $nF7);
            $ninetyfourthF7 = $context->builder->fmul($ninetyfourthF7, $nF7);
            $ninetyfourthF7 = $context->builder->fmul($ninetyfourthF7, $nF7);
            $ninetyfourthF7 = $context->builder->fmul($ninetyfourthF7, $nF7);
            $ninetyfourthF7 = $context->builder->fmul($ninetyfourthF7, $nF7);
            $ninetyfourthF7 = $context->builder->fmul($ninetyfourthF7, $nF7);

            $ninetyfourthF7 = $context->builder->fmul($ninetyfourthF7, $nF7);
            $ninetyfourthF7 = $context->builder->fmul($ninetyfourthF7, $nF7);
            $ninetyfourthF7 = $context->builder->fmul($ninetyfourthF7, $nF7);
            $ninetyfourthF7 = $context->builder->fmul($ninetyfourthF7, $nF7);
            $ninetyfourthF7 = $context->builder->fmul($ninetyfourthF7, $nF7);
            $ninetyfourthF7 = $context->builder->fmul($ninetyfourthF7, $nF7);
            $ninetyfourthF7 = $context->builder->fmul($ninetyfourthF7, $nF7);
            $ninetyfourthF7 = $context->builder->fmul($ninetyfourthF7, $nF7);
            $ninetyfourthF7 = $context->builder->fmul($ninetyfourthF7, $nF7);
            $ninetyfourthF7 = $context->builder->fmul($ninetyfourthF7, $nF7);
            $ninetyfourthF7 = $context->builder->fmul($ninetyfourthF7, $nF7);
            $ninetyfourthF7 = $context->builder->fmul($ninetyfourthF7, $nF7);
            $ninetyfourthF7 = $context->builder->fmul($ninetyfourthF7, $nF7);
            $ninetyfourthF7 = $context->builder->fmul($ninetyfourthF7, $nF7);
            $ninetyfourthF7 = $context->builder->fmul($ninetyfourthF7, $nF7);
            $ninetyfourthF7 = $context->builder->fmul($ninetyfourthF7, $nF7);
            $ninetyfourthF7 = $context->builder->fmul($ninetyfourthF7, $nF7);
            $ninetyfourthF7 = $context->builder->fmul($ninetyfourthF7, $nF7);
            $ninetyfourthF7 = $context->builder->fmul($ninetyfourthF7, $nF7);
            $ninetyfourthF7 = $context->builder->fmul($ninetyfourthF7, $nF7);
            $ninetyfourthF7 = $context->builder->fmul($ninetyfourthF7, $nF7);
            $ninetyfourthF7 = $context->builder->fmul($ninetyfourthF7, $nF7);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF7
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
                throw new \LogicException('pow() **94 expected smul overflow metadata (fortyfourth)');
            }
            $fortyfourthLong = JITVariable::KIND_VARIABLE === $fortyfourthVar->kind
                ? $context->builder->load($fortyfourthVar->value)
                : $fortyfourthVar->value;
            $ov8Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_fortyfourth_ov');
            $ok8Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_fortyfourth_ok');
            $context->builder->branchIf($ov8, $ov8Block, $ok8Block);

            $context->builder->positionAtEnd($ov8Block);
            $fortyfourthF8 = $context->builder->load($fortyfourthVar->longArithOverflowDoubleSlot);
            $sqF8 = $context->builder->siToFp($sqLong, $f64);
            $ninetyfourthF8Sq = $context->builder->fmul($fortyfourthF8, $sqF8);
            $ninetyfourthF8Eighth = $context->builder->fmul($ninetyfourthF8Sq, $sqF8);
            $ninetyfourthF8 = $context->builder->fmul($ninetyfourthF8Eighth, $sqF8);
            $nF8 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF8 = $context->builder->fmul($ninetyfourthF8, $nF8);
            $ninetyfourthF8 = $context->builder->fmul($ninetyfourthF8, $nF8);
            $ninetyfourthF8 = $context->builder->fmul($ninetyfourthF8, $nF8);
            $ninetyfourthF8 = $context->builder->fmul($ninetyfourthF8, $nF8);
            $ninetyfourthF8 = $context->builder->fmul($ninetyfourthF8, $nF8);
            $ninetyfourthF8 = $context->builder->fmul($ninetyfourthF8, $nF8);
            $ninetyfourthF8 = $context->builder->fmul($ninetyfourthF8, $nF8);
            $ninetyfourthF8 = $context->builder->fmul($ninetyfourthF8, $nF8);
            $ninetyfourthF8 = $context->builder->fmul($ninetyfourthF8, $nF8);
            $ninetyfourthF8 = $context->builder->fmul($ninetyfourthF8, $nF8);
                $ninetyfourthF8 = $context->builder->fmul($ninetyfourthF8, $nF8);
                    $ninetyfourthF8 = $context->builder->fmul($ninetyfourthF8, $nF8);
                        $ninetyfourthF8 = $context->builder->fmul($ninetyfourthF8, $nF8);
                        $ninetyfourthF8 = $context->builder->fmul($ninetyfourthF8, $nF8);
                        $ninetyfourthF8 = $context->builder->fmul($ninetyfourthF8, $nF8);
                        $ninetyfourthF8 = $context->builder->fmul($ninetyfourthF8, $nF8);
                        $ninetyfourthF8 = $context->builder->fmul($ninetyfourthF8, $nF8);
                        $ninetyfourthF8 = $context->builder->fmul($ninetyfourthF8, $nF8);
                        $ninetyfourthF8 = $context->builder->fmul($ninetyfourthF8, $nF8);
            $ninetyfourthF8 = $context->builder->fmul($ninetyfourthF8, $nF8);
            $ninetyfourthF8 = $context->builder->fmul($ninetyfourthF8, $nF8);
            $ninetyfourthF8 = $context->builder->fmul($ninetyfourthF8, $nF8);
            $ninetyfourthF8 = $context->builder->fmul($ninetyfourthF8, $nF8);
            $ninetyfourthF8 = $context->builder->fmul($ninetyfourthF8, $nF8);

            $ninetyfourthF8 = $context->builder->fmul($ninetyfourthF8, $nF8);
            $ninetyfourthF8 = $context->builder->fmul($ninetyfourthF8, $nF8);
            $ninetyfourthF8 = $context->builder->fmul($ninetyfourthF8, $nF8);
            $ninetyfourthF8 = $context->builder->fmul($ninetyfourthF8, $nF8);
            $ninetyfourthF8 = $context->builder->fmul($ninetyfourthF8, $nF8);
            $ninetyfourthF8 = $context->builder->fmul($ninetyfourthF8, $nF8);
            $ninetyfourthF8 = $context->builder->fmul($ninetyfourthF8, $nF8);
            $ninetyfourthF8 = $context->builder->fmul($ninetyfourthF8, $nF8);
            $ninetyfourthF8 = $context->builder->fmul($ninetyfourthF8, $nF8);
            $ninetyfourthF8 = $context->builder->fmul($ninetyfourthF8, $nF8);
            $ninetyfourthF8 = $context->builder->fmul($ninetyfourthF8, $nF8);
            $ninetyfourthF8 = $context->builder->fmul($ninetyfourthF8, $nF8);
            $ninetyfourthF8 = $context->builder->fmul($ninetyfourthF8, $nF8);
            $ninetyfourthF8 = $context->builder->fmul($ninetyfourthF8, $nF8);
            $ninetyfourthF8 = $context->builder->fmul($ninetyfourthF8, $nF8);
            $ninetyfourthF8 = $context->builder->fmul($ninetyfourthF8, $nF8);
            $ninetyfourthF8 = $context->builder->fmul($ninetyfourthF8, $nF8);
            $ninetyfourthF8 = $context->builder->fmul($ninetyfourthF8, $nF8);
            $ninetyfourthF8 = $context->builder->fmul($ninetyfourthF8, $nF8);
            $ninetyfourthF8 = $context->builder->fmul($ninetyfourthF8, $nF8);
            $ninetyfourthF8 = $context->builder->fmul($ninetyfourthF8, $nF8);
            $ninetyfourthF8 = $context->builder->fmul($ninetyfourthF8, $nF8);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF8
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
                throw new \LogicException('pow() **94 expected smul overflow metadata (fortysixth)');
            }
            $fortysixthLong = JITVariable::KIND_VARIABLE === $fortysixthVar->kind
                ? $context->builder->load($fortysixthVar->value)
                : $fortysixthVar->value;
            $ov9Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_fortysixth_ov');
            $ok9Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_fortysixth_ok');
            $context->builder->branchIf($ov9, $ov9Block, $ok9Block);

            $context->builder->positionAtEnd($ov9Block);
            $fortysixthF9 = $context->builder->load($fortysixthVar->longArithOverflowDoubleSlot);
            $sqF9 = $context->builder->siToFp($sqLong, $f64);
            $fortyeighthF9 = $context->builder->fmul($fortysixthF9, $sqF9);
            $ninetyfourthF9 = $context->builder->fmul($fortyeighthF9, $sqF9);
            $nF9 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF9 = $context->builder->fmul($ninetyfourthF9, $nF9);
            $ninetyfourthF9 = $context->builder->fmul($ninetyfourthF9, $nF9);
            $ninetyfourthF9 = $context->builder->fmul($ninetyfourthF9, $nF9);
            $ninetyfourthF9 = $context->builder->fmul($ninetyfourthF9, $nF9);
            $ninetyfourthF9 = $context->builder->fmul($ninetyfourthF9, $nF9);
            $ninetyfourthF9 = $context->builder->fmul($ninetyfourthF9, $nF9);
            $ninetyfourthF9 = $context->builder->fmul($ninetyfourthF9, $nF9);
            $ninetyfourthF9 = $context->builder->fmul($ninetyfourthF9, $nF9);
            $ninetyfourthF9 = $context->builder->fmul($ninetyfourthF9, $nF9);
            $ninetyfourthF9 = $context->builder->fmul($ninetyfourthF9, $nF9);
                $ninetyfourthF9 = $context->builder->fmul($ninetyfourthF9, $nF9);
                    $ninetyfourthF9 = $context->builder->fmul($ninetyfourthF9, $nF9);
                        $ninetyfourthF9 = $context->builder->fmul($ninetyfourthF9, $nF9);
                        $ninetyfourthF9 = $context->builder->fmul($ninetyfourthF9, $nF9);
                        $ninetyfourthF9 = $context->builder->fmul($ninetyfourthF9, $nF9);
                        $ninetyfourthF9 = $context->builder->fmul($ninetyfourthF9, $nF9);
                        $ninetyfourthF9 = $context->builder->fmul($ninetyfourthF9, $nF9);
                        $ninetyfourthF9 = $context->builder->fmul($ninetyfourthF9, $nF9);
                        $ninetyfourthF9 = $context->builder->fmul($ninetyfourthF9, $nF9);
            $ninetyfourthF9 = $context->builder->fmul($ninetyfourthF9, $nF9);
            $ninetyfourthF9 = $context->builder->fmul($ninetyfourthF9, $nF9);
            $ninetyfourthF9 = $context->builder->fmul($ninetyfourthF9, $nF9);
            $ninetyfourthF9 = $context->builder->fmul($ninetyfourthF9, $nF9);
            $ninetyfourthF9 = $context->builder->fmul($ninetyfourthF9, $nF9);

            $ninetyfourthF9 = $context->builder->fmul($ninetyfourthF9, $nF9);
            $ninetyfourthF9 = $context->builder->fmul($ninetyfourthF9, $nF9);
            $ninetyfourthF9 = $context->builder->fmul($ninetyfourthF9, $nF9);
            $ninetyfourthF9 = $context->builder->fmul($ninetyfourthF9, $nF9);
            $ninetyfourthF9 = $context->builder->fmul($ninetyfourthF9, $nF9);
            $ninetyfourthF9 = $context->builder->fmul($ninetyfourthF9, $nF9);
            $ninetyfourthF9 = $context->builder->fmul($ninetyfourthF9, $nF9);
            $ninetyfourthF9 = $context->builder->fmul($ninetyfourthF9, $nF9);
            $ninetyfourthF9 = $context->builder->fmul($ninetyfourthF9, $nF9);
            $ninetyfourthF9 = $context->builder->fmul($ninetyfourthF9, $nF9);
            $ninetyfourthF9 = $context->builder->fmul($ninetyfourthF9, $nF9);
            $ninetyfourthF9 = $context->builder->fmul($ninetyfourthF9, $nF9);
            $ninetyfourthF9 = $context->builder->fmul($ninetyfourthF9, $nF9);
            $ninetyfourthF9 = $context->builder->fmul($ninetyfourthF9, $nF9);
            $ninetyfourthF9 = $context->builder->fmul($ninetyfourthF9, $nF9);
            $ninetyfourthF9 = $context->builder->fmul($ninetyfourthF9, $nF9);
            $ninetyfourthF9 = $context->builder->fmul($ninetyfourthF9, $nF9);
            $ninetyfourthF9 = $context->builder->fmul($ninetyfourthF9, $nF9);
            $ninetyfourthF9 = $context->builder->fmul($ninetyfourthF9, $nF9);
            $ninetyfourthF9 = $context->builder->fmul($ninetyfourthF9, $nF9);
            $ninetyfourthF9 = $context->builder->fmul($ninetyfourthF9, $nF9);
            $ninetyfourthF9 = $context->builder->fmul($ninetyfourthF9, $nF9);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF9
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
                throw new \LogicException('pow() **94 expected smul overflow metadata (fortyeighth)');
            }
            $fortyeighthLong = JITVariable::KIND_VARIABLE === $fortyeighthVar->kind
                ? $context->builder->load($fortyeighthVar->value)
                : $fortyeighthVar->value;
            $ov10Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_fortyeighth_ov');
            $ok10Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_fortyeighth_ok');
            $context->builder->branchIf($ov10, $ov10Block, $ok10Block);

            $context->builder->positionAtEnd($ov10Block);
            $fortyeighthF10 = $context->builder->load($fortyeighthVar->longArithOverflowDoubleSlot);
            $sqF10 = $context->builder->siToFp($sqLong, $f64);
            $ninetyfourthF10 = $context->builder->fmul($fortyeighthF10, $sqF10);
            $nF10 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF10 = $context->builder->fmul($ninetyfourthF10, $nF10);
            $ninetyfourthF10 = $context->builder->fmul($ninetyfourthF10, $nF10);
            $ninetyfourthF10 = $context->builder->fmul($ninetyfourthF10, $nF10);
            $ninetyfourthF10 = $context->builder->fmul($ninetyfourthF10, $nF10);
            $ninetyfourthF10 = $context->builder->fmul($ninetyfourthF10, $nF10);
            $ninetyfourthF10 = $context->builder->fmul($ninetyfourthF10, $nF10);
            $ninetyfourthF10 = $context->builder->fmul($ninetyfourthF10, $nF10);
            $ninetyfourthF10 = $context->builder->fmul($ninetyfourthF10, $nF10);
            $ninetyfourthF10 = $context->builder->fmul($ninetyfourthF10, $nF10);
            $ninetyfourthF10 = $context->builder->fmul($ninetyfourthF10, $nF10);
                $ninetyfourthF10 = $context->builder->fmul($ninetyfourthF10, $nF10);
                    $ninetyfourthF10 = $context->builder->fmul($ninetyfourthF10, $nF10);
                        $ninetyfourthF10 = $context->builder->fmul($ninetyfourthF10, $nF10);
                        $ninetyfourthF10 = $context->builder->fmul($ninetyfourthF10, $nF10);
                        $ninetyfourthF10 = $context->builder->fmul($ninetyfourthF10, $nF10);
                        $ninetyfourthF10 = $context->builder->fmul($ninetyfourthF10, $nF10);
                        $ninetyfourthF10 = $context->builder->fmul($ninetyfourthF10, $nF10);
                        $ninetyfourthF10 = $context->builder->fmul($ninetyfourthF10, $nF10);
                        $ninetyfourthF10 = $context->builder->fmul($ninetyfourthF10, $nF10);
            $ninetyfourthF10 = $context->builder->fmul($ninetyfourthF10, $nF10);
            $ninetyfourthF10 = $context->builder->fmul($ninetyfourthF10, $nF10);
            $ninetyfourthF10 = $context->builder->fmul($ninetyfourthF10, $nF10);
            $ninetyfourthF10 = $context->builder->fmul($ninetyfourthF10, $nF10);
            $ninetyfourthF10 = $context->builder->fmul($ninetyfourthF10, $nF10);

            $ninetyfourthF10 = $context->builder->fmul($ninetyfourthF10, $nF10);
            $ninetyfourthF10 = $context->builder->fmul($ninetyfourthF10, $nF10);
            $ninetyfourthF10 = $context->builder->fmul($ninetyfourthF10, $nF10);
            $ninetyfourthF10 = $context->builder->fmul($ninetyfourthF10, $nF10);
            $ninetyfourthF10 = $context->builder->fmul($ninetyfourthF10, $nF10);
            $ninetyfourthF10 = $context->builder->fmul($ninetyfourthF10, $nF10);
            $ninetyfourthF10 = $context->builder->fmul($ninetyfourthF10, $nF10);
            $ninetyfourthF10 = $context->builder->fmul($ninetyfourthF10, $nF10);
            $ninetyfourthF10 = $context->builder->fmul($ninetyfourthF10, $nF10);
            $ninetyfourthF10 = $context->builder->fmul($ninetyfourthF10, $nF10);
            $ninetyfourthF10 = $context->builder->fmul($ninetyfourthF10, $nF10);
            $ninetyfourthF10 = $context->builder->fmul($ninetyfourthF10, $nF10);
            $ninetyfourthF10 = $context->builder->fmul($ninetyfourthF10, $nF10);
            $ninetyfourthF10 = $context->builder->fmul($ninetyfourthF10, $nF10);
            $ninetyfourthF10 = $context->builder->fmul($ninetyfourthF10, $nF10);
            $ninetyfourthF10 = $context->builder->fmul($ninetyfourthF10, $nF10);
            $ninetyfourthF10 = $context->builder->fmul($ninetyfourthF10, $nF10);
            $ninetyfourthF10 = $context->builder->fmul($ninetyfourthF10, $nF10);
            $ninetyfourthF10 = $context->builder->fmul($ninetyfourthF10, $nF10);
            $ninetyfourthF10 = $context->builder->fmul($ninetyfourthF10, $nF10);
            $ninetyfourthF10 = $context->builder->fmul($ninetyfourthF10, $nF10);
            $ninetyfourthF10 = $context->builder->fmul($ninetyfourthF10, $nF10);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF10
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
                throw new \LogicException('pow() **94 expected smul overflow metadata (fiftieth)');
            }
            $fiftiethLong = JITVariable::KIND_VARIABLE === $fiftiethVar->kind
                ? $context->builder->load($fiftiethVar->value)
                : $fiftiethVar->value;
            $ov11Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_fiftieth_ov');
            $ok11Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_fiftieth_ok');
            $context->builder->branchIf($ov11, $ov11Block, $ok11Block);

            $context->builder->positionAtEnd($ov11Block);
            $fiftiethF11 = $context->builder->load($fiftiethVar->longArithOverflowDoubleSlot);
            $nF11 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF11 = $context->builder->fmul($fiftiethF11, $nF11);
            $ninetyfourthF11 = $context->builder->fmul($ninetyfourthF11, $nF11);
            $ninetyfourthF11 = $context->builder->fmul($ninetyfourthF11, $nF11);
            $ninetyfourthF11 = $context->builder->fmul($ninetyfourthF11, $nF11);
            $ninetyfourthF11 = $context->builder->fmul($ninetyfourthF11, $nF11);
            $ninetyfourthF11 = $context->builder->fmul($ninetyfourthF11, $nF11);
            $ninetyfourthF11 = $context->builder->fmul($ninetyfourthF11, $nF11);
            $ninetyfourthF11 = $context->builder->fmul($ninetyfourthF11, $nF11);
            $ninetyfourthF11 = $context->builder->fmul($ninetyfourthF11, $nF11);
            $ninetyfourthF11 = $context->builder->fmul($ninetyfourthF11, $nF11);
                $ninetyfourthF11 = $context->builder->fmul($ninetyfourthF11, $nF11);
                    $ninetyfourthF11 = $context->builder->fmul($ninetyfourthF11, $nF11);
                        $ninetyfourthF11 = $context->builder->fmul($ninetyfourthF11, $nF11);
                        $ninetyfourthF11 = $context->builder->fmul($ninetyfourthF11, $nF11);
                        $ninetyfourthF11 = $context->builder->fmul($ninetyfourthF11, $nF11);
                        $ninetyfourthF11 = $context->builder->fmul($ninetyfourthF11, $nF11);
                        $ninetyfourthF11 = $context->builder->fmul($ninetyfourthF11, $nF11);
                        $ninetyfourthF11 = $context->builder->fmul($ninetyfourthF11, $nF11);
                        $ninetyfourthF11 = $context->builder->fmul($ninetyfourthF11, $nF11);
            $ninetyfourthF11 = $context->builder->fmul($ninetyfourthF11, $nF11);
            $ninetyfourthF11 = $context->builder->fmul($ninetyfourthF11, $nF11);
            $ninetyfourthF11 = $context->builder->fmul($ninetyfourthF11, $nF11);
            $ninetyfourthF11 = $context->builder->fmul($ninetyfourthF11, $nF11);
            $ninetyfourthF11 = $context->builder->fmul($ninetyfourthF11, $nF11);

            $ninetyfourthF11 = $context->builder->fmul($ninetyfourthF11, $nF11);
            $ninetyfourthF11 = $context->builder->fmul($ninetyfourthF11, $nF11);
            $ninetyfourthF11 = $context->builder->fmul($ninetyfourthF11, $nF11);
            $ninetyfourthF11 = $context->builder->fmul($ninetyfourthF11, $nF11);
            $ninetyfourthF11 = $context->builder->fmul($ninetyfourthF11, $nF11);
            $ninetyfourthF11 = $context->builder->fmul($ninetyfourthF11, $nF11);
            $ninetyfourthF11 = $context->builder->fmul($ninetyfourthF11, $nF11);
            $ninetyfourthF11 = $context->builder->fmul($ninetyfourthF11, $nF11);
            $ninetyfourthF11 = $context->builder->fmul($ninetyfourthF11, $nF11);
            $ninetyfourthF11 = $context->builder->fmul($ninetyfourthF11, $nF11);
            $ninetyfourthF11 = $context->builder->fmul($ninetyfourthF11, $nF11);
            $ninetyfourthF11 = $context->builder->fmul($ninetyfourthF11, $nF11);
            $ninetyfourthF11 = $context->builder->fmul($ninetyfourthF11, $nF11);
            $ninetyfourthF11 = $context->builder->fmul($ninetyfourthF11, $nF11);
            $ninetyfourthF11 = $context->builder->fmul($ninetyfourthF11, $nF11);
            $ninetyfourthF11 = $context->builder->fmul($ninetyfourthF11, $nF11);
            $ninetyfourthF11 = $context->builder->fmul($ninetyfourthF11, $nF11);
            $ninetyfourthF11 = $context->builder->fmul($ninetyfourthF11, $nF11);
            $ninetyfourthF11 = $context->builder->fmul($ninetyfourthF11, $nF11);
            $ninetyfourthF11 = $context->builder->fmul($ninetyfourthF11, $nF11);
            $ninetyfourthF11 = $context->builder->fmul($ninetyfourthF11, $nF11);
            $ninetyfourthF11 = $context->builder->fmul($ninetyfourthF11, $nF11);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF11
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
                throw new \LogicException('pow() **94 expected smul overflow metadata (fiftyfirst)');
            }
            $fiftyfirstLong = JITVariable::KIND_VARIABLE === $fiftyfirstVar->kind
                ? $context->builder->load($fiftyfirstVar->value)
                : $fiftyfirstVar->value;
            $ov12Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_fiftyfirst_ov');
            $ok12Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_fiftyfirst_ok');
            $context->builder->branchIf($ov12, $ov12Block, $ok12Block);

            $context->builder->positionAtEnd($ov12Block);
            $fiftyfirstF12 = $context->builder->load($fiftyfirstVar->longArithOverflowDoubleSlot);
            $nF12 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF12 = $context->builder->fmul($fiftyfirstF12, $nF12);
            $ninetyfourthF12 = $context->builder->fmul($ninetyfourthF12, $nF12);
            $ninetyfourthF12 = $context->builder->fmul($ninetyfourthF12, $nF12);
            $ninetyfourthF12 = $context->builder->fmul($ninetyfourthF12, $nF12);
            $ninetyfourthF12 = $context->builder->fmul($ninetyfourthF12, $nF12);
            $ninetyfourthF12 = $context->builder->fmul($ninetyfourthF12, $nF12);
            $ninetyfourthF12 = $context->builder->fmul($ninetyfourthF12, $nF12);
            $ninetyfourthF12 = $context->builder->fmul($ninetyfourthF12, $nF12);
            $ninetyfourthF12 = $context->builder->fmul($ninetyfourthF12, $nF12);
                $ninetyfourthF12 = $context->builder->fmul($ninetyfourthF12, $nF12);
                    $ninetyfourthF12 = $context->builder->fmul($ninetyfourthF12, $nF12);
                        $ninetyfourthF12 = $context->builder->fmul($ninetyfourthF12, $nF12);
                        $ninetyfourthF12 = $context->builder->fmul($ninetyfourthF12, $nF12);
                        $ninetyfourthF12 = $context->builder->fmul($ninetyfourthF12, $nF12);
                        $ninetyfourthF12 = $context->builder->fmul($ninetyfourthF12, $nF12);
                        $ninetyfourthF12 = $context->builder->fmul($ninetyfourthF12, $nF12);
                        $ninetyfourthF12 = $context->builder->fmul($ninetyfourthF12, $nF12);
                        $ninetyfourthF12 = $context->builder->fmul($ninetyfourthF12, $nF12);
            $ninetyfourthF12 = $context->builder->fmul($ninetyfourthF12, $nF12);
            $ninetyfourthF12 = $context->builder->fmul($ninetyfourthF12, $nF12);
            $ninetyfourthF12 = $context->builder->fmul($ninetyfourthF12, $nF12);
            $ninetyfourthF12 = $context->builder->fmul($ninetyfourthF12, $nF12);
            $ninetyfourthF12 = $context->builder->fmul($ninetyfourthF12, $nF12);

            $ninetyfourthF12 = $context->builder->fmul($ninetyfourthF12, $nF12);
            $ninetyfourthF12 = $context->builder->fmul($ninetyfourthF12, $nF12);
            $ninetyfourthF12 = $context->builder->fmul($ninetyfourthF12, $nF12);
            $ninetyfourthF12 = $context->builder->fmul($ninetyfourthF12, $nF12);
            $ninetyfourthF12 = $context->builder->fmul($ninetyfourthF12, $nF12);
            $ninetyfourthF12 = $context->builder->fmul($ninetyfourthF12, $nF12);
            $ninetyfourthF12 = $context->builder->fmul($ninetyfourthF12, $nF12);
            $ninetyfourthF12 = $context->builder->fmul($ninetyfourthF12, $nF12);
            $ninetyfourthF12 = $context->builder->fmul($ninetyfourthF12, $nF12);
            $ninetyfourthF12 = $context->builder->fmul($ninetyfourthF12, $nF12);
            $ninetyfourthF12 = $context->builder->fmul($ninetyfourthF12, $nF12);
            $ninetyfourthF12 = $context->builder->fmul($ninetyfourthF12, $nF12);
            $ninetyfourthF12 = $context->builder->fmul($ninetyfourthF12, $nF12);
            $ninetyfourthF12 = $context->builder->fmul($ninetyfourthF12, $nF12);
            $ninetyfourthF12 = $context->builder->fmul($ninetyfourthF12, $nF12);
            $ninetyfourthF12 = $context->builder->fmul($ninetyfourthF12, $nF12);
            $ninetyfourthF12 = $context->builder->fmul($ninetyfourthF12, $nF12);
            $ninetyfourthF12 = $context->builder->fmul($ninetyfourthF12, $nF12);
            $ninetyfourthF12 = $context->builder->fmul($ninetyfourthF12, $nF12);
            $ninetyfourthF12 = $context->builder->fmul($ninetyfourthF12, $nF12);
            $ninetyfourthF12 = $context->builder->fmul($ninetyfourthF12, $nF12);
            $ninetyfourthF12 = $context->builder->fmul($ninetyfourthF12, $nF12);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF12
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
                throw new \LogicException('pow() **94 expected smul overflow metadata (fiftysecond)');
            }
            $fiftysecondLong = JITVariable::KIND_VARIABLE === $fiftysecondVar->kind
                ? $context->builder->load($fiftysecondVar->value)
                : $fiftysecondVar->value;
            $ov13Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_fiftysecond_ov');
            $ok13Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_fiftysecond_ok');
            $context->builder->branchIf($ov13, $ov13Block, $ok13Block);

            $context->builder->positionAtEnd($ov13Block);
            $fiftysecondF13 = $context->builder->load($fiftysecondVar->longArithOverflowDoubleSlot);
            $nF13 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF13 = $context->builder->fmul($fiftysecondF13, $nF13);
            $ninetyfourthF13 = $context->builder->fmul($ninetyfourthF13, $nF13);
            $ninetyfourthF13 = $context->builder->fmul($ninetyfourthF13, $nF13);
            $ninetyfourthF13 = $context->builder->fmul($ninetyfourthF13, $nF13);
            $ninetyfourthF13 = $context->builder->fmul($ninetyfourthF13, $nF13);
            $ninetyfourthF13 = $context->builder->fmul($ninetyfourthF13, $nF13);
            $ninetyfourthF13 = $context->builder->fmul($ninetyfourthF13, $nF13);
            $ninetyfourthF13 = $context->builder->fmul($ninetyfourthF13, $nF13);
                $ninetyfourthF13 = $context->builder->fmul($ninetyfourthF13, $nF13);
                    $ninetyfourthF13 = $context->builder->fmul($ninetyfourthF13, $nF13);
                        $ninetyfourthF13 = $context->builder->fmul($ninetyfourthF13, $nF13);
                        $ninetyfourthF13 = $context->builder->fmul($ninetyfourthF13, $nF13);
                        $ninetyfourthF13 = $context->builder->fmul($ninetyfourthF13, $nF13);
                        $ninetyfourthF13 = $context->builder->fmul($ninetyfourthF13, $nF13);
                        $ninetyfourthF13 = $context->builder->fmul($ninetyfourthF13, $nF13);
                        $ninetyfourthF13 = $context->builder->fmul($ninetyfourthF13, $nF13);
                        $ninetyfourthF13 = $context->builder->fmul($ninetyfourthF13, $nF13);
            $ninetyfourthF13 = $context->builder->fmul($ninetyfourthF13, $nF13);
            $ninetyfourthF13 = $context->builder->fmul($ninetyfourthF13, $nF13);
            $ninetyfourthF13 = $context->builder->fmul($ninetyfourthF13, $nF13);
            $ninetyfourthF13 = $context->builder->fmul($ninetyfourthF13, $nF13);
            $ninetyfourthF13 = $context->builder->fmul($ninetyfourthF13, $nF13);

            $ninetyfourthF13 = $context->builder->fmul($ninetyfourthF13, $nF13);
            $ninetyfourthF13 = $context->builder->fmul($ninetyfourthF13, $nF13);
            $ninetyfourthF13 = $context->builder->fmul($ninetyfourthF13, $nF13);
            $ninetyfourthF13 = $context->builder->fmul($ninetyfourthF13, $nF13);
            $ninetyfourthF13 = $context->builder->fmul($ninetyfourthF13, $nF13);
            $ninetyfourthF13 = $context->builder->fmul($ninetyfourthF13, $nF13);
            $ninetyfourthF13 = $context->builder->fmul($ninetyfourthF13, $nF13);
            $ninetyfourthF13 = $context->builder->fmul($ninetyfourthF13, $nF13);
            $ninetyfourthF13 = $context->builder->fmul($ninetyfourthF13, $nF13);
            $ninetyfourthF13 = $context->builder->fmul($ninetyfourthF13, $nF13);
            $ninetyfourthF13 = $context->builder->fmul($ninetyfourthF13, $nF13);
            $ninetyfourthF13 = $context->builder->fmul($ninetyfourthF13, $nF13);
            $ninetyfourthF13 = $context->builder->fmul($ninetyfourthF13, $nF13);
            $ninetyfourthF13 = $context->builder->fmul($ninetyfourthF13, $nF13);
            $ninetyfourthF13 = $context->builder->fmul($ninetyfourthF13, $nF13);
            $ninetyfourthF13 = $context->builder->fmul($ninetyfourthF13, $nF13);
            $ninetyfourthF13 = $context->builder->fmul($ninetyfourthF13, $nF13);
            $ninetyfourthF13 = $context->builder->fmul($ninetyfourthF13, $nF13);
            $ninetyfourthF13 = $context->builder->fmul($ninetyfourthF13, $nF13);
            $ninetyfourthF13 = $context->builder->fmul($ninetyfourthF13, $nF13);
            $ninetyfourthF13 = $context->builder->fmul($ninetyfourthF13, $nF13);
            $ninetyfourthF13 = $context->builder->fmul($ninetyfourthF13, $nF13);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF13
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
                throw new \LogicException('pow() **94 expected smul overflow metadata (fiftythird)');
            }
            $fiftythirdLong = JITVariable::KIND_VARIABLE === $fiftythirdVar->kind
                ? $context->builder->load($fiftythirdVar->value)
                : $fiftythirdVar->value;
            $ov14Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_fiftythird_ov');
            $ok14Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_fiftythird_ok');
            $context->builder->branchIf($ov14, $ov14Block, $ok14Block);

            $context->builder->positionAtEnd($ov14Block);
            $fiftythirdF14 = $context->builder->load($fiftythirdVar->longArithOverflowDoubleSlot);
            $nF14 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF14 = $context->builder->fmul($fiftythirdF14, $nF14);
            $ninetyfourthF14 = $context->builder->fmul($ninetyfourthF14, $nF14);
            $ninetyfourthF14 = $context->builder->fmul($ninetyfourthF14, $nF14);
            $ninetyfourthF14 = $context->builder->fmul($ninetyfourthF14, $nF14);
            $ninetyfourthF14 = $context->builder->fmul($ninetyfourthF14, $nF14);
            $ninetyfourthF14 = $context->builder->fmul($ninetyfourthF14, $nF14);
            $ninetyfourthF14 = $context->builder->fmul($ninetyfourthF14, $nF14);
                $ninetyfourthF14 = $context->builder->fmul($ninetyfourthF14, $nF14);
                    $ninetyfourthF14 = $context->builder->fmul($ninetyfourthF14, $nF14);
                        $ninetyfourthF14 = $context->builder->fmul($ninetyfourthF14, $nF14);
                        $ninetyfourthF14 = $context->builder->fmul($ninetyfourthF14, $nF14);
                        $ninetyfourthF14 = $context->builder->fmul($ninetyfourthF14, $nF14);
                        $ninetyfourthF14 = $context->builder->fmul($ninetyfourthF14, $nF14);
                        $ninetyfourthF14 = $context->builder->fmul($ninetyfourthF14, $nF14);
                        $ninetyfourthF14 = $context->builder->fmul($ninetyfourthF14, $nF14);
                        $ninetyfourthF14 = $context->builder->fmul($ninetyfourthF14, $nF14);
            $ninetyfourthF14 = $context->builder->fmul($ninetyfourthF14, $nF14);
            $ninetyfourthF14 = $context->builder->fmul($ninetyfourthF14, $nF14);
            $ninetyfourthF14 = $context->builder->fmul($ninetyfourthF14, $nF14);
            $ninetyfourthF14 = $context->builder->fmul($ninetyfourthF14, $nF14);
            $ninetyfourthF14 = $context->builder->fmul($ninetyfourthF14, $nF14);

            $ninetyfourthF14 = $context->builder->fmul($ninetyfourthF14, $nF14);
            $ninetyfourthF14 = $context->builder->fmul($ninetyfourthF14, $nF14);
            $ninetyfourthF14 = $context->builder->fmul($ninetyfourthF14, $nF14);
            $ninetyfourthF14 = $context->builder->fmul($ninetyfourthF14, $nF14);
            $ninetyfourthF14 = $context->builder->fmul($ninetyfourthF14, $nF14);
            $ninetyfourthF14 = $context->builder->fmul($ninetyfourthF14, $nF14);
            $ninetyfourthF14 = $context->builder->fmul($ninetyfourthF14, $nF14);
            $ninetyfourthF14 = $context->builder->fmul($ninetyfourthF14, $nF14);
            $ninetyfourthF14 = $context->builder->fmul($ninetyfourthF14, $nF14);
            $ninetyfourthF14 = $context->builder->fmul($ninetyfourthF14, $nF14);
            $ninetyfourthF14 = $context->builder->fmul($ninetyfourthF14, $nF14);
            $ninetyfourthF14 = $context->builder->fmul($ninetyfourthF14, $nF14);
            $ninetyfourthF14 = $context->builder->fmul($ninetyfourthF14, $nF14);
            $ninetyfourthF14 = $context->builder->fmul($ninetyfourthF14, $nF14);
            $ninetyfourthF14 = $context->builder->fmul($ninetyfourthF14, $nF14);
            $ninetyfourthF14 = $context->builder->fmul($ninetyfourthF14, $nF14);
            $ninetyfourthF14 = $context->builder->fmul($ninetyfourthF14, $nF14);
            $ninetyfourthF14 = $context->builder->fmul($ninetyfourthF14, $nF14);
            $ninetyfourthF14 = $context->builder->fmul($ninetyfourthF14, $nF14);
            $ninetyfourthF14 = $context->builder->fmul($ninetyfourthF14, $nF14);
            $ninetyfourthF14 = $context->builder->fmul($ninetyfourthF14, $nF14);
            $ninetyfourthF14 = $context->builder->fmul($ninetyfourthF14, $nF14);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF14
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
                throw new \LogicException('pow() **94 expected smul overflow metadata (fiftyfourth)');
            }
            $fiftyfourthLong = JITVariable::KIND_VARIABLE === $fiftyfourthVar->kind
                ? $context->builder->load($fiftyfourthVar->value)
                : $fiftyfourthVar->value;
            $ov15Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_fiftyfourth_ov');
            $ok15Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_fiftyfourth_ok');
            $context->builder->branchIf($ov15, $ov15Block, $ok15Block);

            $context->builder->positionAtEnd($ov15Block);
            $fiftyfourthF15 = $context->builder->load($fiftyfourthVar->longArithOverflowDoubleSlot);
            $nF15 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF15 = $context->builder->fmul($fiftyfourthF15, $nF15);
            $ninetyfourthF15 = $context->builder->fmul($ninetyfourthF15, $nF15);
            $ninetyfourthF15 = $context->builder->fmul($ninetyfourthF15, $nF15);
            $ninetyfourthF15 = $context->builder->fmul($ninetyfourthF15, $nF15);
            $ninetyfourthF15 = $context->builder->fmul($ninetyfourthF15, $nF15);
            $ninetyfourthF15 = $context->builder->fmul($ninetyfourthF15, $nF15);
                $ninetyfourthF15 = $context->builder->fmul($ninetyfourthF15, $nF15);
                    $ninetyfourthF15 = $context->builder->fmul($ninetyfourthF15, $nF15);
                        $ninetyfourthF15 = $context->builder->fmul($ninetyfourthF15, $nF15);
                        $ninetyfourthF15 = $context->builder->fmul($ninetyfourthF15, $nF15);
                        $ninetyfourthF15 = $context->builder->fmul($ninetyfourthF15, $nF15);
                        $ninetyfourthF15 = $context->builder->fmul($ninetyfourthF15, $nF15);
                        $ninetyfourthF15 = $context->builder->fmul($ninetyfourthF15, $nF15);
                        $ninetyfourthF15 = $context->builder->fmul($ninetyfourthF15, $nF15);
                        $ninetyfourthF15 = $context->builder->fmul($ninetyfourthF15, $nF15);
            $ninetyfourthF15 = $context->builder->fmul($ninetyfourthF15, $nF15);
            $ninetyfourthF15 = $context->builder->fmul($ninetyfourthF15, $nF15);
            $ninetyfourthF15 = $context->builder->fmul($ninetyfourthF15, $nF15);
            $ninetyfourthF15 = $context->builder->fmul($ninetyfourthF15, $nF15);
            $ninetyfourthF15 = $context->builder->fmul($ninetyfourthF15, $nF15);

            $ninetyfourthF15 = $context->builder->fmul($ninetyfourthF15, $nF15);
            $ninetyfourthF15 = $context->builder->fmul($ninetyfourthF15, $nF15);
            $ninetyfourthF15 = $context->builder->fmul($ninetyfourthF15, $nF15);
            $ninetyfourthF15 = $context->builder->fmul($ninetyfourthF15, $nF15);
            $ninetyfourthF15 = $context->builder->fmul($ninetyfourthF15, $nF15);
            $ninetyfourthF15 = $context->builder->fmul($ninetyfourthF15, $nF15);
            $ninetyfourthF15 = $context->builder->fmul($ninetyfourthF15, $nF15);
            $ninetyfourthF15 = $context->builder->fmul($ninetyfourthF15, $nF15);
            $ninetyfourthF15 = $context->builder->fmul($ninetyfourthF15, $nF15);
            $ninetyfourthF15 = $context->builder->fmul($ninetyfourthF15, $nF15);
            $ninetyfourthF15 = $context->builder->fmul($ninetyfourthF15, $nF15);
            $ninetyfourthF15 = $context->builder->fmul($ninetyfourthF15, $nF15);
            $ninetyfourthF15 = $context->builder->fmul($ninetyfourthF15, $nF15);
            $ninetyfourthF15 = $context->builder->fmul($ninetyfourthF15, $nF15);
            $ninetyfourthF15 = $context->builder->fmul($ninetyfourthF15, $nF15);
            $ninetyfourthF15 = $context->builder->fmul($ninetyfourthF15, $nF15);
            $ninetyfourthF15 = $context->builder->fmul($ninetyfourthF15, $nF15);
            $ninetyfourthF15 = $context->builder->fmul($ninetyfourthF15, $nF15);
            $ninetyfourthF15 = $context->builder->fmul($ninetyfourthF15, $nF15);
            $ninetyfourthF15 = $context->builder->fmul($ninetyfourthF15, $nF15);
            $ninetyfourthF15 = $context->builder->fmul($ninetyfourthF15, $nF15);
            $ninetyfourthF15 = $context->builder->fmul($ninetyfourthF15, $nF15);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF15
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
                throw new \LogicException('pow() **94 expected smul overflow metadata (fiftyfifth)');
            }
            $fiftyfifthLong = JITVariable::KIND_VARIABLE === $fiftyfifthVar->kind
                ? $context->builder->load($fiftyfifthVar->value)
                : $fiftyfifthVar->value;
            $ov16Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_fiftyfifth_ov');
            $ok16Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_fiftyfifth_ok');
            $context->builder->branchIf($ov16, $ov16Block, $ok16Block);

            $context->builder->positionAtEnd($ov16Block);
            $fiftyfifthF16 = $context->builder->load($fiftyfifthVar->longArithOverflowDoubleSlot);
            $nF16 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF16 = $context->builder->fmul($fiftyfifthF16, $nF16);
            $ninetyfourthF16 = $context->builder->fmul($ninetyfourthF16, $nF16);
            $ninetyfourthF16 = $context->builder->fmul($ninetyfourthF16, $nF16);
            $ninetyfourthF16 = $context->builder->fmul($ninetyfourthF16, $nF16);
            $ninetyfourthF16 = $context->builder->fmul($ninetyfourthF16, $nF16);
                $ninetyfourthF16 = $context->builder->fmul($ninetyfourthF16, $nF16);
                    $ninetyfourthF16 = $context->builder->fmul($ninetyfourthF16, $nF16);
                        $ninetyfourthF16 = $context->builder->fmul($ninetyfourthF16, $nF16);
                        $ninetyfourthF16 = $context->builder->fmul($ninetyfourthF16, $nF16);
                        $ninetyfourthF16 = $context->builder->fmul($ninetyfourthF16, $nF16);
                        $ninetyfourthF16 = $context->builder->fmul($ninetyfourthF16, $nF16);
                        $ninetyfourthF16 = $context->builder->fmul($ninetyfourthF16, $nF16);
                        $ninetyfourthF16 = $context->builder->fmul($ninetyfourthF16, $nF16);
                        $ninetyfourthF16 = $context->builder->fmul($ninetyfourthF16, $nF16);
            $ninetyfourthF16 = $context->builder->fmul($ninetyfourthF16, $nF16);
            $ninetyfourthF16 = $context->builder->fmul($ninetyfourthF16, $nF16);
            $ninetyfourthF16 = $context->builder->fmul($ninetyfourthF16, $nF16);
            $ninetyfourthF16 = $context->builder->fmul($ninetyfourthF16, $nF16);
            $ninetyfourthF16 = $context->builder->fmul($ninetyfourthF16, $nF16);

            $ninetyfourthF16 = $context->builder->fmul($ninetyfourthF16, $nF16);
            $ninetyfourthF16 = $context->builder->fmul($ninetyfourthF16, $nF16);
            $ninetyfourthF16 = $context->builder->fmul($ninetyfourthF16, $nF16);
            $ninetyfourthF16 = $context->builder->fmul($ninetyfourthF16, $nF16);
            $ninetyfourthF16 = $context->builder->fmul($ninetyfourthF16, $nF16);
            $ninetyfourthF16 = $context->builder->fmul($ninetyfourthF16, $nF16);
            $ninetyfourthF16 = $context->builder->fmul($ninetyfourthF16, $nF16);
            $ninetyfourthF16 = $context->builder->fmul($ninetyfourthF16, $nF16);
            $ninetyfourthF16 = $context->builder->fmul($ninetyfourthF16, $nF16);
            $ninetyfourthF16 = $context->builder->fmul($ninetyfourthF16, $nF16);
            $ninetyfourthF16 = $context->builder->fmul($ninetyfourthF16, $nF16);
            $ninetyfourthF16 = $context->builder->fmul($ninetyfourthF16, $nF16);
            $ninetyfourthF16 = $context->builder->fmul($ninetyfourthF16, $nF16);
            $ninetyfourthF16 = $context->builder->fmul($ninetyfourthF16, $nF16);
            $ninetyfourthF16 = $context->builder->fmul($ninetyfourthF16, $nF16);
            $ninetyfourthF16 = $context->builder->fmul($ninetyfourthF16, $nF16);
            $ninetyfourthF16 = $context->builder->fmul($ninetyfourthF16, $nF16);
            $ninetyfourthF16 = $context->builder->fmul($ninetyfourthF16, $nF16);
            $ninetyfourthF16 = $context->builder->fmul($ninetyfourthF16, $nF16);
            $ninetyfourthF16 = $context->builder->fmul($ninetyfourthF16, $nF16);
            $ninetyfourthF16 = $context->builder->fmul($ninetyfourthF16, $nF16);
            $ninetyfourthF16 = $context->builder->fmul($ninetyfourthF16, $nF16);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF16
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
                throw new \LogicException('pow() **94 expected smul overflow metadata (fiftysixth)');
            }
            $fiftysixthLong = JITVariable::KIND_VARIABLE === $fiftysixthVar->kind
                ? $context->builder->load($fiftysixthVar->value)
                : $fiftysixthVar->value;
            $ov17Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_fiftysixth_ov');
            $ok17Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_fiftysixth_ok');
            $context->builder->branchIf($ov17, $ov17Block, $ok17Block);

            $context->builder->positionAtEnd($ov17Block);
            $fiftysixthF17 = $context->builder->load($fiftysixthVar->longArithOverflowDoubleSlot);
            $nF17 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF17 = $context->builder->fmul($fiftysixthF17, $nF17);
            $ninetyfourthF17 = $context->builder->fmul($ninetyfourthF17, $nF17);
            $ninetyfourthF17 = $context->builder->fmul($ninetyfourthF17, $nF17);
            $ninetyfourthF17 = $context->builder->fmul($ninetyfourthF17, $nF17);
                $ninetyfourthF17 = $context->builder->fmul($ninetyfourthF17, $nF17);
                    $ninetyfourthF17 = $context->builder->fmul($ninetyfourthF17, $nF17);
                        $ninetyfourthF17 = $context->builder->fmul($ninetyfourthF17, $nF17);
                        $ninetyfourthF17 = $context->builder->fmul($ninetyfourthF17, $nF17);
                        $ninetyfourthF17 = $context->builder->fmul($ninetyfourthF17, $nF17);
                        $ninetyfourthF17 = $context->builder->fmul($ninetyfourthF17, $nF17);
                        $ninetyfourthF17 = $context->builder->fmul($ninetyfourthF17, $nF17);
                        $ninetyfourthF17 = $context->builder->fmul($ninetyfourthF17, $nF17);
                        $ninetyfourthF17 = $context->builder->fmul($ninetyfourthF17, $nF17);
            $ninetyfourthF17 = $context->builder->fmul($ninetyfourthF17, $nF17);
            $ninetyfourthF17 = $context->builder->fmul($ninetyfourthF17, $nF17);
            $ninetyfourthF17 = $context->builder->fmul($ninetyfourthF17, $nF17);
            $ninetyfourthF17 = $context->builder->fmul($ninetyfourthF17, $nF17);
            $ninetyfourthF17 = $context->builder->fmul($ninetyfourthF17, $nF17);

            $ninetyfourthF17 = $context->builder->fmul($ninetyfourthF17, $nF17);
            $ninetyfourthF17 = $context->builder->fmul($ninetyfourthF17, $nF17);
            $ninetyfourthF17 = $context->builder->fmul($ninetyfourthF17, $nF17);
            $ninetyfourthF17 = $context->builder->fmul($ninetyfourthF17, $nF17);
            $ninetyfourthF17 = $context->builder->fmul($ninetyfourthF17, $nF17);
            $ninetyfourthF17 = $context->builder->fmul($ninetyfourthF17, $nF17);
            $ninetyfourthF17 = $context->builder->fmul($ninetyfourthF17, $nF17);
            $ninetyfourthF17 = $context->builder->fmul($ninetyfourthF17, $nF17);
            $ninetyfourthF17 = $context->builder->fmul($ninetyfourthF17, $nF17);
            $ninetyfourthF17 = $context->builder->fmul($ninetyfourthF17, $nF17);
            $ninetyfourthF17 = $context->builder->fmul($ninetyfourthF17, $nF17);
            $ninetyfourthF17 = $context->builder->fmul($ninetyfourthF17, $nF17);
            $ninetyfourthF17 = $context->builder->fmul($ninetyfourthF17, $nF17);
            $ninetyfourthF17 = $context->builder->fmul($ninetyfourthF17, $nF17);
            $ninetyfourthF17 = $context->builder->fmul($ninetyfourthF17, $nF17);
            $ninetyfourthF17 = $context->builder->fmul($ninetyfourthF17, $nF17);
            $ninetyfourthF17 = $context->builder->fmul($ninetyfourthF17, $nF17);
            $ninetyfourthF17 = $context->builder->fmul($ninetyfourthF17, $nF17);
            $ninetyfourthF17 = $context->builder->fmul($ninetyfourthF17, $nF17);
            $ninetyfourthF17 = $context->builder->fmul($ninetyfourthF17, $nF17);
            $ninetyfourthF17 = $context->builder->fmul($ninetyfourthF17, $nF17);
            $ninetyfourthF17 = $context->builder->fmul($ninetyfourthF17, $nF17);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF17
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
                throw new \LogicException('pow() **94 expected smul overflow metadata (fiftyseventh)');
            }
            $fiftyseventhLong = JITVariable::KIND_VARIABLE === $fiftyseventhVar->kind
                ? $context->builder->load($fiftyseventhVar->value)
                : $fiftyseventhVar->value;
            $ov18Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_fiftyseventh_ov');
            $ok18Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_fiftyseventh_ok');
            $context->builder->branchIf($ov18, $ov18Block, $ok18Block);

            $context->builder->positionAtEnd($ov18Block);
            $fiftyseventhF18 = $context->builder->load($fiftyseventhVar->longArithOverflowDoubleSlot);
            $nF18 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF18 = $context->builder->fmul($fiftyseventhF18, $nF18);
            $ninetyfourthF18 = $context->builder->fmul($ninetyfourthF18, $nF18);
            $ninetyfourthF18 = $context->builder->fmul($ninetyfourthF18, $nF18);
                $ninetyfourthF18 = $context->builder->fmul($ninetyfourthF18, $nF18);
                    $ninetyfourthF18 = $context->builder->fmul($ninetyfourthF18, $nF18);
                        $ninetyfourthF18 = $context->builder->fmul($ninetyfourthF18, $nF18);
                        $ninetyfourthF18 = $context->builder->fmul($ninetyfourthF18, $nF18);
                        $ninetyfourthF18 = $context->builder->fmul($ninetyfourthF18, $nF18);
                        $ninetyfourthF18 = $context->builder->fmul($ninetyfourthF18, $nF18);
                        $ninetyfourthF18 = $context->builder->fmul($ninetyfourthF18, $nF18);
                        $ninetyfourthF18 = $context->builder->fmul($ninetyfourthF18, $nF18);
                        $ninetyfourthF18 = $context->builder->fmul($ninetyfourthF18, $nF18);
            $ninetyfourthF18 = $context->builder->fmul($ninetyfourthF18, $nF18);
            $ninetyfourthF18 = $context->builder->fmul($ninetyfourthF18, $nF18);
            $ninetyfourthF18 = $context->builder->fmul($ninetyfourthF18, $nF18);
            $ninetyfourthF18 = $context->builder->fmul($ninetyfourthF18, $nF18);
            $ninetyfourthF18 = $context->builder->fmul($ninetyfourthF18, $nF18);

            $ninetyfourthF18 = $context->builder->fmul($ninetyfourthF18, $nF18);
            $ninetyfourthF18 = $context->builder->fmul($ninetyfourthF18, $nF18);
            $ninetyfourthF18 = $context->builder->fmul($ninetyfourthF18, $nF18);
            $ninetyfourthF18 = $context->builder->fmul($ninetyfourthF18, $nF18);
            $ninetyfourthF18 = $context->builder->fmul($ninetyfourthF18, $nF18);
            $ninetyfourthF18 = $context->builder->fmul($ninetyfourthF18, $nF18);
            $ninetyfourthF18 = $context->builder->fmul($ninetyfourthF18, $nF18);
            $ninetyfourthF18 = $context->builder->fmul($ninetyfourthF18, $nF18);
            $ninetyfourthF18 = $context->builder->fmul($ninetyfourthF18, $nF18);
            $ninetyfourthF18 = $context->builder->fmul($ninetyfourthF18, $nF18);
            $ninetyfourthF18 = $context->builder->fmul($ninetyfourthF18, $nF18);
            $ninetyfourthF18 = $context->builder->fmul($ninetyfourthF18, $nF18);
            $ninetyfourthF18 = $context->builder->fmul($ninetyfourthF18, $nF18);
            $ninetyfourthF18 = $context->builder->fmul($ninetyfourthF18, $nF18);
            $ninetyfourthF18 = $context->builder->fmul($ninetyfourthF18, $nF18);
            $ninetyfourthF18 = $context->builder->fmul($ninetyfourthF18, $nF18);
            $ninetyfourthF18 = $context->builder->fmul($ninetyfourthF18, $nF18);
            $ninetyfourthF18 = $context->builder->fmul($ninetyfourthF18, $nF18);
            $ninetyfourthF18 = $context->builder->fmul($ninetyfourthF18, $nF18);
            $ninetyfourthF18 = $context->builder->fmul($ninetyfourthF18, $nF18);
            $ninetyfourthF18 = $context->builder->fmul($ninetyfourthF18, $nF18);
            $ninetyfourthF18 = $context->builder->fmul($ninetyfourthF18, $nF18);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF18
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
                throw new \LogicException('pow() **94 expected smul overflow metadata (fiftyeighth)');
            }
            $fiftyeighthLong = JITVariable::KIND_VARIABLE === $fiftyeighthVar->kind
                ? $context->builder->load($fiftyeighthVar->value)
                : $fiftyeighthVar->value;
            $ov19Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_fiftyeighth_ov');
            $ok19Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_fiftyeighth_ok');
            $context->builder->branchIf($ov19, $ov19Block, $ok19Block);

            $context->builder->positionAtEnd($ov19Block);
            $fiftyeighthF19 = $context->builder->load($fiftyeighthVar->longArithOverflowDoubleSlot);
            $nF19 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF19 = $context->builder->fmul($fiftyeighthF19, $nF19);
            $ninetyfourthF19 = $context->builder->fmul($ninetyfourthF19, $nF19);
                $ninetyfourthF19 = $context->builder->fmul($ninetyfourthF19, $nF19);
                    $ninetyfourthF19 = $context->builder->fmul($ninetyfourthF19, $nF19);
                        $ninetyfourthF19 = $context->builder->fmul($ninetyfourthF19, $nF19);
                        $ninetyfourthF19 = $context->builder->fmul($ninetyfourthF19, $nF19);
                        $ninetyfourthF19 = $context->builder->fmul($ninetyfourthF19, $nF19);
                        $ninetyfourthF19 = $context->builder->fmul($ninetyfourthF19, $nF19);
                        $ninetyfourthF19 = $context->builder->fmul($ninetyfourthF19, $nF19);
                        $ninetyfourthF19 = $context->builder->fmul($ninetyfourthF19, $nF19);
                        $ninetyfourthF19 = $context->builder->fmul($ninetyfourthF19, $nF19);
            $ninetyfourthF19 = $context->builder->fmul($ninetyfourthF19, $nF19);
            $ninetyfourthF19 = $context->builder->fmul($ninetyfourthF19, $nF19);
            $ninetyfourthF19 = $context->builder->fmul($ninetyfourthF19, $nF19);
            $ninetyfourthF19 = $context->builder->fmul($ninetyfourthF19, $nF19);
            $ninetyfourthF19 = $context->builder->fmul($ninetyfourthF19, $nF19);

            $ninetyfourthF19 = $context->builder->fmul($ninetyfourthF19, $nF19);
            $ninetyfourthF19 = $context->builder->fmul($ninetyfourthF19, $nF19);
            $ninetyfourthF19 = $context->builder->fmul($ninetyfourthF19, $nF19);
            $ninetyfourthF19 = $context->builder->fmul($ninetyfourthF19, $nF19);
            $ninetyfourthF19 = $context->builder->fmul($ninetyfourthF19, $nF19);
            $ninetyfourthF19 = $context->builder->fmul($ninetyfourthF19, $nF19);
            $ninetyfourthF19 = $context->builder->fmul($ninetyfourthF19, $nF19);
            $ninetyfourthF19 = $context->builder->fmul($ninetyfourthF19, $nF19);
            $ninetyfourthF19 = $context->builder->fmul($ninetyfourthF19, $nF19);
            $ninetyfourthF19 = $context->builder->fmul($ninetyfourthF19, $nF19);
            $ninetyfourthF19 = $context->builder->fmul($ninetyfourthF19, $nF19);
            $ninetyfourthF19 = $context->builder->fmul($ninetyfourthF19, $nF19);
            $ninetyfourthF19 = $context->builder->fmul($ninetyfourthF19, $nF19);
            $ninetyfourthF19 = $context->builder->fmul($ninetyfourthF19, $nF19);
            $ninetyfourthF19 = $context->builder->fmul($ninetyfourthF19, $nF19);
            $ninetyfourthF19 = $context->builder->fmul($ninetyfourthF19, $nF19);
            $ninetyfourthF19 = $context->builder->fmul($ninetyfourthF19, $nF19);
            $ninetyfourthF19 = $context->builder->fmul($ninetyfourthF19, $nF19);
            $ninetyfourthF19 = $context->builder->fmul($ninetyfourthF19, $nF19);
            $ninetyfourthF19 = $context->builder->fmul($ninetyfourthF19, $nF19);
            $ninetyfourthF19 = $context->builder->fmul($ninetyfourthF19, $nF19);
            $ninetyfourthF19 = $context->builder->fmul($ninetyfourthF19, $nF19);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF19
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
                throw new \LogicException('pow() **94 expected smul overflow metadata (fiftyninth)');
            }
            $fiftyninthLong = JITVariable::KIND_VARIABLE === $fiftyninthVar->kind
                ? $context->builder->load($fiftyninthVar->value)
                : $fiftyninthVar->value;
            $ov20Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_fiftyninth_ov');
            $ok20Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_fiftyninth_ok');
            $context->builder->branchIf($ov20, $ov20Block, $ok20Block);

            $context->builder->positionAtEnd($ov20Block);
            $fiftyninthF20 = $context->builder->load($fiftyninthVar->longArithOverflowDoubleSlot);
            $nF20 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF20 = $context->builder->fmul($fiftyninthF20, $nF20);
                $ninetyfourthF20 = $context->builder->fmul($ninetyfourthF20, $nF20);
                    $ninetyfourthF20 = $context->builder->fmul($ninetyfourthF20, $nF20);
                        $ninetyfourthF20 = $context->builder->fmul($ninetyfourthF20, $nF20);
                        $ninetyfourthF20 = $context->builder->fmul($ninetyfourthF20, $nF20);
                        $ninetyfourthF20 = $context->builder->fmul($ninetyfourthF20, $nF20);
                        $ninetyfourthF20 = $context->builder->fmul($ninetyfourthF20, $nF20);
                        $ninetyfourthF20 = $context->builder->fmul($ninetyfourthF20, $nF20);
                        $ninetyfourthF20 = $context->builder->fmul($ninetyfourthF20, $nF20);
                        $ninetyfourthF20 = $context->builder->fmul($ninetyfourthF20, $nF20);
            $ninetyfourthF20 = $context->builder->fmul($ninetyfourthF20, $nF20);
            $ninetyfourthF20 = $context->builder->fmul($ninetyfourthF20, $nF20);
            $ninetyfourthF20 = $context->builder->fmul($ninetyfourthF20, $nF20);
            $ninetyfourthF20 = $context->builder->fmul($ninetyfourthF20, $nF20);
            $ninetyfourthF20 = $context->builder->fmul($ninetyfourthF20, $nF20);

            $ninetyfourthF20 = $context->builder->fmul($ninetyfourthF20, $nF20);
            $ninetyfourthF20 = $context->builder->fmul($ninetyfourthF20, $nF20);
            $ninetyfourthF20 = $context->builder->fmul($ninetyfourthF20, $nF20);
            $ninetyfourthF20 = $context->builder->fmul($ninetyfourthF20, $nF20);
            $ninetyfourthF20 = $context->builder->fmul($ninetyfourthF20, $nF20);
            $ninetyfourthF20 = $context->builder->fmul($ninetyfourthF20, $nF20);
            $ninetyfourthF20 = $context->builder->fmul($ninetyfourthF20, $nF20);
            $ninetyfourthF20 = $context->builder->fmul($ninetyfourthF20, $nF20);
            $ninetyfourthF20 = $context->builder->fmul($ninetyfourthF20, $nF20);
            $ninetyfourthF20 = $context->builder->fmul($ninetyfourthF20, $nF20);
            $ninetyfourthF20 = $context->builder->fmul($ninetyfourthF20, $nF20);
            $ninetyfourthF20 = $context->builder->fmul($ninetyfourthF20, $nF20);
            $ninetyfourthF20 = $context->builder->fmul($ninetyfourthF20, $nF20);
            $ninetyfourthF20 = $context->builder->fmul($ninetyfourthF20, $nF20);
            $ninetyfourthF20 = $context->builder->fmul($ninetyfourthF20, $nF20);
            $ninetyfourthF20 = $context->builder->fmul($ninetyfourthF20, $nF20);
            $ninetyfourthF20 = $context->builder->fmul($ninetyfourthF20, $nF20);
            $ninetyfourthF20 = $context->builder->fmul($ninetyfourthF20, $nF20);
            $ninetyfourthF20 = $context->builder->fmul($ninetyfourthF20, $nF20);
            $ninetyfourthF20 = $context->builder->fmul($ninetyfourthF20, $nF20);
            $ninetyfourthF20 = $context->builder->fmul($ninetyfourthF20, $nF20);
            $ninetyfourthF20 = $context->builder->fmul($ninetyfourthF20, $nF20);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF20
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
                throw new \LogicException('pow() **94 expected smul overflow metadata (sixtieth)');
            }
            $sixtiethLong = JITVariable::KIND_VARIABLE === $sixtiethVar->kind
                ? $context->builder->load($sixtiethVar->value)
                : $sixtiethVar->value;
            $ov21Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_sixtieth_ov');
            $ok21Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_sixtieth_ok');
            $context->builder->branchIf($ov21, $ov21Block, $ok21Block);

            $context->builder->positionAtEnd($ov21Block);
            $sixtiethF21 = $context->builder->load($sixtiethVar->longArithOverflowDoubleSlot);
            $nF21 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF21 = $context->builder->fmul($sixtiethF21, $nF21);
                $ninetyfourthF21 = $context->builder->fmul($ninetyfourthF21, $nF21);
                    $ninetyfourthF21 = $context->builder->fmul($ninetyfourthF21, $nF21);
                    $ninetyfourthF21 = $context->builder->fmul($ninetyfourthF21, $nF21);
                    $ninetyfourthF21 = $context->builder->fmul($ninetyfourthF21, $nF21);
                    $ninetyfourthF21 = $context->builder->fmul($ninetyfourthF21, $nF21);
                    $ninetyfourthF21 = $context->builder->fmul($ninetyfourthF21, $nF21);
                    $ninetyfourthF21 = $context->builder->fmul($ninetyfourthF21, $nF21);
                    $ninetyfourthF21 = $context->builder->fmul($ninetyfourthF21, $nF21);
            $ninetyfourthF21 = $context->builder->fmul($ninetyfourthF21, $nF21);
            $ninetyfourthF21 = $context->builder->fmul($ninetyfourthF21, $nF21);
            $ninetyfourthF21 = $context->builder->fmul($ninetyfourthF21, $nF21);
            $ninetyfourthF21 = $context->builder->fmul($ninetyfourthF21, $nF21);
            $ninetyfourthF21 = $context->builder->fmul($ninetyfourthF21, $nF21);

            $ninetyfourthF21 = $context->builder->fmul($ninetyfourthF21, $nF21);
            $ninetyfourthF21 = $context->builder->fmul($ninetyfourthF21, $nF21);
            $ninetyfourthF21 = $context->builder->fmul($ninetyfourthF21, $nF21);
            $ninetyfourthF21 = $context->builder->fmul($ninetyfourthF21, $nF21);
            $ninetyfourthF21 = $context->builder->fmul($ninetyfourthF21, $nF21);
            $ninetyfourthF21 = $context->builder->fmul($ninetyfourthF21, $nF21);
            $ninetyfourthF21 = $context->builder->fmul($ninetyfourthF21, $nF21);
            $ninetyfourthF21 = $context->builder->fmul($ninetyfourthF21, $nF21);
            $ninetyfourthF21 = $context->builder->fmul($ninetyfourthF21, $nF21);
            $ninetyfourthF21 = $context->builder->fmul($ninetyfourthF21, $nF21);
            $ninetyfourthF21 = $context->builder->fmul($ninetyfourthF21, $nF21);
            $ninetyfourthF21 = $context->builder->fmul($ninetyfourthF21, $nF21);
            $ninetyfourthF21 = $context->builder->fmul($ninetyfourthF21, $nF21);
            $ninetyfourthF21 = $context->builder->fmul($ninetyfourthF21, $nF21);
            $ninetyfourthF21 = $context->builder->fmul($ninetyfourthF21, $nF21);
            $ninetyfourthF21 = $context->builder->fmul($ninetyfourthF21, $nF21);
            $ninetyfourthF21 = $context->builder->fmul($ninetyfourthF21, $nF21);
            $ninetyfourthF21 = $context->builder->fmul($ninetyfourthF21, $nF21);
            $ninetyfourthF21 = $context->builder->fmul($ninetyfourthF21, $nF21);
            $ninetyfourthF21 = $context->builder->fmul($ninetyfourthF21, $nF21);
            $ninetyfourthF21 = $context->builder->fmul($ninetyfourthF21, $nF21);
            $ninetyfourthF21 = $context->builder->fmul($ninetyfourthF21, $nF21);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF21
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
                throw new \LogicException('pow() **94 expected smul overflow metadata (sixtyfirst)');
            }
            $sixtyfirstLong = JITVariable::KIND_VARIABLE === $sixtyfirstVar->kind
                ? $context->builder->load($sixtyfirstVar->value)
                : $sixtyfirstVar->value;
            $ov22Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_sixtyfirst_ov');
            $ok22Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_sixtyfirst_ok');
            $context->builder->branchIf($ov22, $ov22Block, $ok22Block);

            $context->builder->positionAtEnd($ov22Block);
            $sixtyfirstF22 = $context->builder->load($sixtyfirstVar->longArithOverflowDoubleSlot);
            $nF22 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF22 = $context->builder->fmul($sixtyfirstF22, $nF22);
                $ninetyfourthF22 = $context->builder->fmul($ninetyfourthF22, $nF22);
                $ninetyfourthF22 = $context->builder->fmul($ninetyfourthF22, $nF22);
                $ninetyfourthF22 = $context->builder->fmul($ninetyfourthF22, $nF22);
                $ninetyfourthF22 = $context->builder->fmul($ninetyfourthF22, $nF22);
                $ninetyfourthF22 = $context->builder->fmul($ninetyfourthF22, $nF22);
                $ninetyfourthF22 = $context->builder->fmul($ninetyfourthF22, $nF22);
                $ninetyfourthF22 = $context->builder->fmul($ninetyfourthF22, $nF22);
            $ninetyfourthF22 = $context->builder->fmul($ninetyfourthF22, $nF22);
            $ninetyfourthF22 = $context->builder->fmul($ninetyfourthF22, $nF22);
            $ninetyfourthF22 = $context->builder->fmul($ninetyfourthF22, $nF22);
            $ninetyfourthF22 = $context->builder->fmul($ninetyfourthF22, $nF22);
            $ninetyfourthF22 = $context->builder->fmul($ninetyfourthF22, $nF22);

            $ninetyfourthF22 = $context->builder->fmul($ninetyfourthF22, $nF22);
            $ninetyfourthF22 = $context->builder->fmul($ninetyfourthF22, $nF22);
            $ninetyfourthF22 = $context->builder->fmul($ninetyfourthF22, $nF22);
            $ninetyfourthF22 = $context->builder->fmul($ninetyfourthF22, $nF22);
            $ninetyfourthF22 = $context->builder->fmul($ninetyfourthF22, $nF22);
            $ninetyfourthF22 = $context->builder->fmul($ninetyfourthF22, $nF22);
            $ninetyfourthF22 = $context->builder->fmul($ninetyfourthF22, $nF22);
            $ninetyfourthF22 = $context->builder->fmul($ninetyfourthF22, $nF22);
            $ninetyfourthF22 = $context->builder->fmul($ninetyfourthF22, $nF22);
            $ninetyfourthF22 = $context->builder->fmul($ninetyfourthF22, $nF22);
            $ninetyfourthF22 = $context->builder->fmul($ninetyfourthF22, $nF22);
            $ninetyfourthF22 = $context->builder->fmul($ninetyfourthF22, $nF22);
            $ninetyfourthF22 = $context->builder->fmul($ninetyfourthF22, $nF22);
            $ninetyfourthF22 = $context->builder->fmul($ninetyfourthF22, $nF22);
            $ninetyfourthF22 = $context->builder->fmul($ninetyfourthF22, $nF22);
            $ninetyfourthF22 = $context->builder->fmul($ninetyfourthF22, $nF22);
            $ninetyfourthF22 = $context->builder->fmul($ninetyfourthF22, $nF22);
            $ninetyfourthF22 = $context->builder->fmul($ninetyfourthF22, $nF22);
            $ninetyfourthF22 = $context->builder->fmul($ninetyfourthF22, $nF22);
            $ninetyfourthF22 = $context->builder->fmul($ninetyfourthF22, $nF22);
            $ninetyfourthF22 = $context->builder->fmul($ninetyfourthF22, $nF22);
            $ninetyfourthF22 = $context->builder->fmul($ninetyfourthF22, $nF22);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF22
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
                throw new \LogicException('pow() **94 expected smul overflow metadata (sixtysecond)');
            }
            $sixtysecondLong = JITVariable::KIND_VARIABLE === $sixtysecondVar->kind
                ? $context->builder->load($sixtysecondVar->value)
                : $sixtysecondVar->value;
            $ov23Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_sixtysecond_ov');
            $ok23Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_sixtysecond_ok');
            $context->builder->branchIf($ov23, $ov23Block, $ok23Block);

            $context->builder->positionAtEnd($ov23Block);
            $sixtysecondF23 = $context->builder->load($sixtysecondVar->longArithOverflowDoubleSlot);
            $nF23 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF23 = $context->builder->fmul($sixtysecondF23, $nF23);
            $ninetyfourthF23 = $context->builder->fmul($ninetyfourthF23, $nF23);
            $ninetyfourthF23 = $context->builder->fmul($ninetyfourthF23, $nF23);
            $ninetyfourthF23 = $context->builder->fmul($ninetyfourthF23, $nF23);
            $ninetyfourthF23 = $context->builder->fmul($ninetyfourthF23, $nF23);
            $ninetyfourthF23 = $context->builder->fmul($ninetyfourthF23, $nF23);
            $ninetyfourthF23 = $context->builder->fmul($ninetyfourthF23, $nF23);
            $ninetyfourthF23 = $context->builder->fmul($ninetyfourthF23, $nF23);
            $ninetyfourthF23 = $context->builder->fmul($ninetyfourthF23, $nF23);
            $ninetyfourthF23 = $context->builder->fmul($ninetyfourthF23, $nF23);
            $ninetyfourthF23 = $context->builder->fmul($ninetyfourthF23, $nF23);
            $ninetyfourthF23 = $context->builder->fmul($ninetyfourthF23, $nF23);

            $ninetyfourthF23 = $context->builder->fmul($ninetyfourthF23, $nF23);
            $ninetyfourthF23 = $context->builder->fmul($ninetyfourthF23, $nF23);
            $ninetyfourthF23 = $context->builder->fmul($ninetyfourthF23, $nF23);
            $ninetyfourthF23 = $context->builder->fmul($ninetyfourthF23, $nF23);
            $ninetyfourthF23 = $context->builder->fmul($ninetyfourthF23, $nF23);
            $ninetyfourthF23 = $context->builder->fmul($ninetyfourthF23, $nF23);
            $ninetyfourthF23 = $context->builder->fmul($ninetyfourthF23, $nF23);
            $ninetyfourthF23 = $context->builder->fmul($ninetyfourthF23, $nF23);
            $ninetyfourthF23 = $context->builder->fmul($ninetyfourthF23, $nF23);
            $ninetyfourthF23 = $context->builder->fmul($ninetyfourthF23, $nF23);
            $ninetyfourthF23 = $context->builder->fmul($ninetyfourthF23, $nF23);
            $ninetyfourthF23 = $context->builder->fmul($ninetyfourthF23, $nF23);
            $ninetyfourthF23 = $context->builder->fmul($ninetyfourthF23, $nF23);
            $ninetyfourthF23 = $context->builder->fmul($ninetyfourthF23, $nF23);
            $ninetyfourthF23 = $context->builder->fmul($ninetyfourthF23, $nF23);
            $ninetyfourthF23 = $context->builder->fmul($ninetyfourthF23, $nF23);
            $ninetyfourthF23 = $context->builder->fmul($ninetyfourthF23, $nF23);
            $ninetyfourthF23 = $context->builder->fmul($ninetyfourthF23, $nF23);
            $ninetyfourthF23 = $context->builder->fmul($ninetyfourthF23, $nF23);
            $ninetyfourthF23 = $context->builder->fmul($ninetyfourthF23, $nF23);
            $ninetyfourthF23 = $context->builder->fmul($ninetyfourthF23, $nF23);
            $ninetyfourthF23 = $context->builder->fmul($ninetyfourthF23, $nF23);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF23
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
                throw new \LogicException('pow() **94 expected smul overflow metadata (sixtythird)');
            }
            $sixtythirdLong = JITVariable::KIND_VARIABLE === $sixtythirdVar->kind
                ? $context->builder->load($sixtythirdVar->value)
                : $sixtythirdVar->value;
            $ov24Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_sixtythird_ov');
            $ok24Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_sixtythird_ok');
            $context->builder->branchIf($ov24, $ov24Block, $ok24Block);

            $context->builder->positionAtEnd($ov24Block);
            $sixtythirdF24 = $context->builder->load($sixtythirdVar->longArithOverflowDoubleSlot);
            $nF24 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF24 = $context->builder->fmul($sixtythirdF24, $nF24);
            $ninetyfourthF24 = $context->builder->fmul($ninetyfourthF24, $nF24);
            $ninetyfourthF24 = $context->builder->fmul($ninetyfourthF24, $nF24);
            $ninetyfourthF24 = $context->builder->fmul($ninetyfourthF24, $nF24);
            $ninetyfourthF24 = $context->builder->fmul($ninetyfourthF24, $nF24);
            $ninetyfourthF24 = $context->builder->fmul($ninetyfourthF24, $nF24);
            $ninetyfourthF24 = $context->builder->fmul($ninetyfourthF24, $nF24);
            $ninetyfourthF24 = $context->builder->fmul($ninetyfourthF24, $nF24);
            $ninetyfourthF24 = $context->builder->fmul($ninetyfourthF24, $nF24);
            $ninetyfourthF24 = $context->builder->fmul($ninetyfourthF24, $nF24);
            $ninetyfourthF24 = $context->builder->fmul($ninetyfourthF24, $nF24);

            $ninetyfourthF24 = $context->builder->fmul($ninetyfourthF24, $nF24);
            $ninetyfourthF24 = $context->builder->fmul($ninetyfourthF24, $nF24);
            $ninetyfourthF24 = $context->builder->fmul($ninetyfourthF24, $nF24);
            $ninetyfourthF24 = $context->builder->fmul($ninetyfourthF24, $nF24);
            $ninetyfourthF24 = $context->builder->fmul($ninetyfourthF24, $nF24);
            $ninetyfourthF24 = $context->builder->fmul($ninetyfourthF24, $nF24);
            $ninetyfourthF24 = $context->builder->fmul($ninetyfourthF24, $nF24);
            $ninetyfourthF24 = $context->builder->fmul($ninetyfourthF24, $nF24);
            $ninetyfourthF24 = $context->builder->fmul($ninetyfourthF24, $nF24);
            $ninetyfourthF24 = $context->builder->fmul($ninetyfourthF24, $nF24);
            $ninetyfourthF24 = $context->builder->fmul($ninetyfourthF24, $nF24);
            $ninetyfourthF24 = $context->builder->fmul($ninetyfourthF24, $nF24);
            $ninetyfourthF24 = $context->builder->fmul($ninetyfourthF24, $nF24);
            $ninetyfourthF24 = $context->builder->fmul($ninetyfourthF24, $nF24);
            $ninetyfourthF24 = $context->builder->fmul($ninetyfourthF24, $nF24);
            $ninetyfourthF24 = $context->builder->fmul($ninetyfourthF24, $nF24);
            $ninetyfourthF24 = $context->builder->fmul($ninetyfourthF24, $nF24);
            $ninetyfourthF24 = $context->builder->fmul($ninetyfourthF24, $nF24);
            $ninetyfourthF24 = $context->builder->fmul($ninetyfourthF24, $nF24);
            $ninetyfourthF24 = $context->builder->fmul($ninetyfourthF24, $nF24);
            $ninetyfourthF24 = $context->builder->fmul($ninetyfourthF24, $nF24);
            $ninetyfourthF24 = $context->builder->fmul($ninetyfourthF24, $nF24);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF24
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
                throw new \LogicException('pow() **94 expected smul overflow metadata (sixtyfourth)');
            }
            $sixtyfourthLong = JITVariable::KIND_VARIABLE === $sixtyfourthVar->kind
                ? $context->builder->load($sixtyfourthVar->value)
                : $sixtyfourthVar->value;
            $ov25Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_sixtyfourth_ov');
            $ok25Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_sixtyfourth_ok');
            $context->builder->branchIf($ov25, $ov25Block, $ok25Block);

            $context->builder->positionAtEnd($ov25Block);
            $sixtyfourthF25 = $context->builder->load($sixtyfourthVar->longArithOverflowDoubleSlot);
            $nF25 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF25 = $context->builder->fmul($sixtyfourthF25, $nF25);
            $ninetyfourthF25 = $context->builder->fmul($ninetyfourthF25, $nF25);
            $ninetyfourthF25 = $context->builder->fmul($ninetyfourthF25, $nF25);
            $ninetyfourthF25 = $context->builder->fmul($ninetyfourthF25, $nF25);
            $ninetyfourthF25 = $context->builder->fmul($ninetyfourthF25, $nF25);
            $ninetyfourthF25 = $context->builder->fmul($ninetyfourthF25, $nF25);
            $ninetyfourthF25 = $context->builder->fmul($ninetyfourthF25, $nF25);
            $ninetyfourthF25 = $context->builder->fmul($ninetyfourthF25, $nF25);
            $ninetyfourthF25 = $context->builder->fmul($ninetyfourthF25, $nF25);
            $ninetyfourthF25 = $context->builder->fmul($ninetyfourthF25, $nF25);

            $ninetyfourthF25 = $context->builder->fmul($ninetyfourthF25, $nF25);
            $ninetyfourthF25 = $context->builder->fmul($ninetyfourthF25, $nF25);
            $ninetyfourthF25 = $context->builder->fmul($ninetyfourthF25, $nF25);
            $ninetyfourthF25 = $context->builder->fmul($ninetyfourthF25, $nF25);
            $ninetyfourthF25 = $context->builder->fmul($ninetyfourthF25, $nF25);
            $ninetyfourthF25 = $context->builder->fmul($ninetyfourthF25, $nF25);
            $ninetyfourthF25 = $context->builder->fmul($ninetyfourthF25, $nF25);
            $ninetyfourthF25 = $context->builder->fmul($ninetyfourthF25, $nF25);
            $ninetyfourthF25 = $context->builder->fmul($ninetyfourthF25, $nF25);
            $ninetyfourthF25 = $context->builder->fmul($ninetyfourthF25, $nF25);
            $ninetyfourthF25 = $context->builder->fmul($ninetyfourthF25, $nF25);
            $ninetyfourthF25 = $context->builder->fmul($ninetyfourthF25, $nF25);
            $ninetyfourthF25 = $context->builder->fmul($ninetyfourthF25, $nF25);
            $ninetyfourthF25 = $context->builder->fmul($ninetyfourthF25, $nF25);
            $ninetyfourthF25 = $context->builder->fmul($ninetyfourthF25, $nF25);
            $ninetyfourthF25 = $context->builder->fmul($ninetyfourthF25, $nF25);
            $ninetyfourthF25 = $context->builder->fmul($ninetyfourthF25, $nF25);
            $ninetyfourthF25 = $context->builder->fmul($ninetyfourthF25, $nF25);
            $ninetyfourthF25 = $context->builder->fmul($ninetyfourthF25, $nF25);
            $ninetyfourthF25 = $context->builder->fmul($ninetyfourthF25, $nF25);
            $ninetyfourthF25 = $context->builder->fmul($ninetyfourthF25, $nF25);
            $ninetyfourthF25 = $context->builder->fmul($ninetyfourthF25, $nF25);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF25
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
                throw new \LogicException('pow() **94 expected smul overflow metadata (sixtyfifth)');
            }
            $sixtyfifthLong = JITVariable::KIND_VARIABLE === $sixtyfifthVar->kind
                ? $context->builder->load($sixtyfifthVar->value)
                : $sixtyfifthVar->value;
            $ov26Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_sixtyfifth_ov');
            $ok26Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_sixtyfifth_ok');
            $context->builder->branchIf($ov26, $ov26Block, $ok26Block);

            $context->builder->positionAtEnd($ov26Block);
            $sixtyfifthF26 = $context->builder->load($sixtyfifthVar->longArithOverflowDoubleSlot);
            $nF26 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF26 = $context->builder->fmul($sixtyfifthF26, $nF26);
            $ninetyfourthF26 = $context->builder->fmul($ninetyfourthF26, $nF26);
            $ninetyfourthF26 = $context->builder->fmul($ninetyfourthF26, $nF26);
            $ninetyfourthF26 = $context->builder->fmul($ninetyfourthF26, $nF26);
            $ninetyfourthF26 = $context->builder->fmul($ninetyfourthF26, $nF26);
            $ninetyfourthF26 = $context->builder->fmul($ninetyfourthF26, $nF26);
            $ninetyfourthF26 = $context->builder->fmul($ninetyfourthF26, $nF26);
            $ninetyfourthF26 = $context->builder->fmul($ninetyfourthF26, $nF26);
            $ninetyfourthF26 = $context->builder->fmul($ninetyfourthF26, $nF26);

            $ninetyfourthF26 = $context->builder->fmul($ninetyfourthF26, $nF26);
            $ninetyfourthF26 = $context->builder->fmul($ninetyfourthF26, $nF26);
            $ninetyfourthF26 = $context->builder->fmul($ninetyfourthF26, $nF26);
            $ninetyfourthF26 = $context->builder->fmul($ninetyfourthF26, $nF26);
            $ninetyfourthF26 = $context->builder->fmul($ninetyfourthF26, $nF26);
            $ninetyfourthF26 = $context->builder->fmul($ninetyfourthF26, $nF26);
            $ninetyfourthF26 = $context->builder->fmul($ninetyfourthF26, $nF26);
            $ninetyfourthF26 = $context->builder->fmul($ninetyfourthF26, $nF26);
            $ninetyfourthF26 = $context->builder->fmul($ninetyfourthF26, $nF26);
            $ninetyfourthF26 = $context->builder->fmul($ninetyfourthF26, $nF26);
            $ninetyfourthF26 = $context->builder->fmul($ninetyfourthF26, $nF26);
            $ninetyfourthF26 = $context->builder->fmul($ninetyfourthF26, $nF26);
            $ninetyfourthF26 = $context->builder->fmul($ninetyfourthF26, $nF26);
            $ninetyfourthF26 = $context->builder->fmul($ninetyfourthF26, $nF26);
            $ninetyfourthF26 = $context->builder->fmul($ninetyfourthF26, $nF26);
            $ninetyfourthF26 = $context->builder->fmul($ninetyfourthF26, $nF26);
            $ninetyfourthF26 = $context->builder->fmul($ninetyfourthF26, $nF26);
            $ninetyfourthF26 = $context->builder->fmul($ninetyfourthF26, $nF26);
            $ninetyfourthF26 = $context->builder->fmul($ninetyfourthF26, $nF26);
            $ninetyfourthF26 = $context->builder->fmul($ninetyfourthF26, $nF26);
            $ninetyfourthF26 = $context->builder->fmul($ninetyfourthF26, $nF26);
            $ninetyfourthF26 = $context->builder->fmul($ninetyfourthF26, $nF26);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF26
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
                throw new \LogicException('pow() **94 expected smul overflow metadata (sixtysixth)');
            }
            $sixtysixthLong = JITVariable::KIND_VARIABLE === $sixtysixthVar->kind
                ? $context->builder->load($sixtysixthVar->value)
                : $sixtysixthVar->value;
            $ov27Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_sixtysixth_ov');
            $ok27Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_sixtysixth_ok');
            $context->builder->branchIf($ov27, $ov27Block, $ok27Block);

            $context->builder->positionAtEnd($ov27Block);
            $sixtysixthF27 = $context->builder->load($sixtysixthVar->longArithOverflowDoubleSlot);
            $nF27 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF27 = $context->builder->fmul($sixtysixthF27, $nF27);
            $ninetyfourthF27 = $context->builder->fmul($ninetyfourthF27, $nF27);
            $ninetyfourthF27 = $context->builder->fmul($ninetyfourthF27, $nF27);
            $ninetyfourthF27 = $context->builder->fmul($ninetyfourthF27, $nF27);
            $ninetyfourthF27 = $context->builder->fmul($ninetyfourthF27, $nF27);
            $ninetyfourthF27 = $context->builder->fmul($ninetyfourthF27, $nF27);
            $ninetyfourthF27 = $context->builder->fmul($ninetyfourthF27, $nF27);
            $ninetyfourthF27 = $context->builder->fmul($ninetyfourthF27, $nF27);

            $ninetyfourthF27 = $context->builder->fmul($ninetyfourthF27, $nF27);
            $ninetyfourthF27 = $context->builder->fmul($ninetyfourthF27, $nF27);
            $ninetyfourthF27 = $context->builder->fmul($ninetyfourthF27, $nF27);
            $ninetyfourthF27 = $context->builder->fmul($ninetyfourthF27, $nF27);
            $ninetyfourthF27 = $context->builder->fmul($ninetyfourthF27, $nF27);
            $ninetyfourthF27 = $context->builder->fmul($ninetyfourthF27, $nF27);
            $ninetyfourthF27 = $context->builder->fmul($ninetyfourthF27, $nF27);
            $ninetyfourthF27 = $context->builder->fmul($ninetyfourthF27, $nF27);
            $ninetyfourthF27 = $context->builder->fmul($ninetyfourthF27, $nF27);
            $ninetyfourthF27 = $context->builder->fmul($ninetyfourthF27, $nF27);
            $ninetyfourthF27 = $context->builder->fmul($ninetyfourthF27, $nF27);
            $ninetyfourthF27 = $context->builder->fmul($ninetyfourthF27, $nF27);
            $ninetyfourthF27 = $context->builder->fmul($ninetyfourthF27, $nF27);
            $ninetyfourthF27 = $context->builder->fmul($ninetyfourthF27, $nF27);
            $ninetyfourthF27 = $context->builder->fmul($ninetyfourthF27, $nF27);
            $ninetyfourthF27 = $context->builder->fmul($ninetyfourthF27, $nF27);
            $ninetyfourthF27 = $context->builder->fmul($ninetyfourthF27, $nF27);
            $ninetyfourthF27 = $context->builder->fmul($ninetyfourthF27, $nF27);
            $ninetyfourthF27 = $context->builder->fmul($ninetyfourthF27, $nF27);
            $ninetyfourthF27 = $context->builder->fmul($ninetyfourthF27, $nF27);
            $ninetyfourthF27 = $context->builder->fmul($ninetyfourthF27, $nF27);
            $ninetyfourthF27 = $context->builder->fmul($ninetyfourthF27, $nF27);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF27
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
                throw new \LogicException('pow() **94 expected smul overflow metadata (sixtyseventh)');
            }
            $sixtyseventhLong = JITVariable::KIND_VARIABLE === $sixtyseventhVar->kind
                ? $context->builder->load($sixtyseventhVar->value)
                : $sixtyseventhVar->value;
            $ov28Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_sixtyseventh_ov');
            $ok28Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_sixtyseventh_ok');
            $context->builder->branchIf($ov28, $ov28Block, $ok28Block);

            $context->builder->positionAtEnd($ov28Block);
            $sixtyseventhF28 = $context->builder->load($sixtyseventhVar->longArithOverflowDoubleSlot);
            $nF28 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF28 = $context->builder->fmul($sixtyseventhF28, $nF28);
            $ninetyfourthF28 = $context->builder->fmul($ninetyfourthF28, $nF28);
            $ninetyfourthF28 = $context->builder->fmul($ninetyfourthF28, $nF28);
            $ninetyfourthF28 = $context->builder->fmul($ninetyfourthF28, $nF28);
            $ninetyfourthF28 = $context->builder->fmul($ninetyfourthF28, $nF28);
            $ninetyfourthF28 = $context->builder->fmul($ninetyfourthF28, $nF28);
            $ninetyfourthF28 = $context->builder->fmul($ninetyfourthF28, $nF28);
            $ninetyfourthF28 = $context->builder->fmul($ninetyfourthF28, $nF28);

            $ninetyfourthF28 = $context->builder->fmul($ninetyfourthF28, $nF28);
            $ninetyfourthF28 = $context->builder->fmul($ninetyfourthF28, $nF28);
            $ninetyfourthF28 = $context->builder->fmul($ninetyfourthF28, $nF28);
            $ninetyfourthF28 = $context->builder->fmul($ninetyfourthF28, $nF28);
            $ninetyfourthF28 = $context->builder->fmul($ninetyfourthF28, $nF28);
            $ninetyfourthF28 = $context->builder->fmul($ninetyfourthF28, $nF28);
            $ninetyfourthF28 = $context->builder->fmul($ninetyfourthF28, $nF28);
            $ninetyfourthF28 = $context->builder->fmul($ninetyfourthF28, $nF28);
            $ninetyfourthF28 = $context->builder->fmul($ninetyfourthF28, $nF28);
            $ninetyfourthF28 = $context->builder->fmul($ninetyfourthF28, $nF28);
            $ninetyfourthF28 = $context->builder->fmul($ninetyfourthF28, $nF28);
            $ninetyfourthF28 = $context->builder->fmul($ninetyfourthF28, $nF28);
            $ninetyfourthF28 = $context->builder->fmul($ninetyfourthF28, $nF28);
            $ninetyfourthF28 = $context->builder->fmul($ninetyfourthF28, $nF28);
            $ninetyfourthF28 = $context->builder->fmul($ninetyfourthF28, $nF28);
            $ninetyfourthF28 = $context->builder->fmul($ninetyfourthF28, $nF28);
            $ninetyfourthF28 = $context->builder->fmul($ninetyfourthF28, $nF28);
            $ninetyfourthF28 = $context->builder->fmul($ninetyfourthF28, $nF28);
            $ninetyfourthF28 = $context->builder->fmul($ninetyfourthF28, $nF28);
            $ninetyfourthF28 = $context->builder->fmul($ninetyfourthF28, $nF28);
            $ninetyfourthF28 = $context->builder->fmul($ninetyfourthF28, $nF28);
            $ninetyfourthF28 = $context->builder->fmul($ninetyfourthF28, $nF28);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF28
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
                throw new \LogicException('pow() **94 expected smul overflow metadata (sixtyeighth)');
            }
            $sixtyeighthLong = JITVariable::KIND_VARIABLE === $sixtyeighthVar->kind
                ? $context->builder->load($sixtyeighthVar->value)
                : $sixtyeighthVar->value;
            $ov29Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_sixtyeighth_ov');
            $ok29Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_sixtyeighth_ok');
            $context->builder->branchIf($ov29, $ov29Block, $ok29Block);

            $context->builder->positionAtEnd($ov29Block);
            $sixtyeighthF29 = $context->builder->load($sixtyeighthVar->longArithOverflowDoubleSlot);
            $nF29 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF29 = $context->builder->fmul($sixtyeighthF29, $nF29);
            $ninetyfourthF29 = $context->builder->fmul($ninetyfourthF29, $nF29);
            $ninetyfourthF29 = $context->builder->fmul($ninetyfourthF29, $nF29);
            $ninetyfourthF29 = $context->builder->fmul($ninetyfourthF29, $nF29);
            $ninetyfourthF29 = $context->builder->fmul($ninetyfourthF29, $nF29);
            $ninetyfourthF29 = $context->builder->fmul($ninetyfourthF29, $nF29);

            $ninetyfourthF29 = $context->builder->fmul($ninetyfourthF29, $nF29);
            $ninetyfourthF29 = $context->builder->fmul($ninetyfourthF29, $nF29);
            $ninetyfourthF29 = $context->builder->fmul($ninetyfourthF29, $nF29);
            $ninetyfourthF29 = $context->builder->fmul($ninetyfourthF29, $nF29);
            $ninetyfourthF29 = $context->builder->fmul($ninetyfourthF29, $nF29);
            $ninetyfourthF29 = $context->builder->fmul($ninetyfourthF29, $nF29);
            $ninetyfourthF29 = $context->builder->fmul($ninetyfourthF29, $nF29);
            $ninetyfourthF29 = $context->builder->fmul($ninetyfourthF29, $nF29);
            $ninetyfourthF29 = $context->builder->fmul($ninetyfourthF29, $nF29);
            $ninetyfourthF29 = $context->builder->fmul($ninetyfourthF29, $nF29);
            $ninetyfourthF29 = $context->builder->fmul($ninetyfourthF29, $nF29);
            $ninetyfourthF29 = $context->builder->fmul($ninetyfourthF29, $nF29);
            $ninetyfourthF29 = $context->builder->fmul($ninetyfourthF29, $nF29);
            $ninetyfourthF29 = $context->builder->fmul($ninetyfourthF29, $nF29);
            $ninetyfourthF29 = $context->builder->fmul($ninetyfourthF29, $nF29);
            $ninetyfourthF29 = $context->builder->fmul($ninetyfourthF29, $nF29);
            $ninetyfourthF29 = $context->builder->fmul($ninetyfourthF29, $nF29);
            $ninetyfourthF29 = $context->builder->fmul($ninetyfourthF29, $nF29);
            $ninetyfourthF29 = $context->builder->fmul($ninetyfourthF29, $nF29);
            $ninetyfourthF29 = $context->builder->fmul($ninetyfourthF29, $nF29);
            $ninetyfourthF29 = $context->builder->fmul($ninetyfourthF29, $nF29);
            $ninetyfourthF29 = $context->builder->fmul($ninetyfourthF29, $nF29);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF29
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok29Block);
            $sixtyninthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixtyeighthLong,
                $n
            );
            $ov30 = JitLongArithOverflow::loadOverflowFlagI1($context, $sixtyninthVar->longArithOverflowFlag);
            if (null === $ov30 || null === $sixtyninthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **94 expected smul overflow metadata (sixtyninth)');
            }
            $sixtyninthLong = JITVariable::KIND_VARIABLE === $sixtyninthVar->kind
                ? $context->builder->load($sixtyninthVar->value)
                : $sixtyninthVar->value;
            $ov30Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_sixtyninth_ov');
            $ok30Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_sixtyninth_ok');
            $context->builder->branchIf($ov30, $ov30Block, $ok30Block);

            $context->builder->positionAtEnd($ov30Block);
            $sixtyninthF30 = $context->builder->load($sixtyninthVar->longArithOverflowDoubleSlot);
            $nF30 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF30 = $context->builder->fmul($sixtyninthF30, $nF30);
            $ninetyfourthF30 = $context->builder->fmul($ninetyfourthF30, $nF30);
            $ninetyfourthF30 = $context->builder->fmul($ninetyfourthF30, $nF30);
            $ninetyfourthF30 = $context->builder->fmul($ninetyfourthF30, $nF30);
            $ninetyfourthF30 = $context->builder->fmul($ninetyfourthF30, $nF30);
            $ninetyfourthF30 = $context->builder->fmul($ninetyfourthF30, $nF30);
            $ninetyfourthF30 = $context->builder->fmul($ninetyfourthF30, $nF30);
            $ninetyfourthF30 = $context->builder->fmul($ninetyfourthF30, $nF30);
            $ninetyfourthF30 = $context->builder->fmul($ninetyfourthF30, $nF30);
            $ninetyfourthF30 = $context->builder->fmul($ninetyfourthF30, $nF30);
            $ninetyfourthF30 = $context->builder->fmul($ninetyfourthF30, $nF30);
            $ninetyfourthF30 = $context->builder->fmul($ninetyfourthF30, $nF30);
            $ninetyfourthF30 = $context->builder->fmul($ninetyfourthF30, $nF30);
            $ninetyfourthF30 = $context->builder->fmul($ninetyfourthF30, $nF30);
            $ninetyfourthF30 = $context->builder->fmul($ninetyfourthF30, $nF30);
            $ninetyfourthF30 = $context->builder->fmul($ninetyfourthF30, $nF30);
            $ninetyfourthF30 = $context->builder->fmul($ninetyfourthF30, $nF30);
            $ninetyfourthF30 = $context->builder->fmul($ninetyfourthF30, $nF30);
            $ninetyfourthF30 = $context->builder->fmul($ninetyfourthF30, $nF30);
            $ninetyfourthF30 = $context->builder->fmul($ninetyfourthF30, $nF30);
            $ninetyfourthF30 = $context->builder->fmul($ninetyfourthF30, $nF30);
            $ninetyfourthF30 = $context->builder->fmul($ninetyfourthF30, $nF30);
            $ninetyfourthF30 = $context->builder->fmul($ninetyfourthF30, $nF30);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF30
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok30Block);
            $seventiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $sixtyninthLong,
                $n
            );
            $ov31 = JitLongArithOverflow::loadOverflowFlagI1($context, $seventiethVar->longArithOverflowFlag);
            if (null === $ov31 || null === $seventiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **94 expected smul overflow metadata (seventieth)');
            }
            $seventiethLong = JITVariable::KIND_VARIABLE === $seventiethVar->kind
                ? $context->builder->load($seventiethVar->value)
                : $seventiethVar->value;
            $ov31Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_seventieth_ov');
            $ok31Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_seventieth_ok');
            $context->builder->branchIf($ov31, $ov31Block, $ok31Block);

            $context->builder->positionAtEnd($ov31Block);
            $seventiethF31 = $context->builder->load($seventiethVar->longArithOverflowDoubleSlot);
            $nF31 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF31 = $context->builder->fmul($seventiethF31, $nF31);
            $ninetyfourthF31 = $context->builder->fmul($ninetyfourthF31, $nF31);
            $ninetyfourthF31 = $context->builder->fmul($ninetyfourthF31, $nF31);
            $ninetyfourthF31 = $context->builder->fmul($ninetyfourthF31, $nF31);
            $ninetyfourthF31 = $context->builder->fmul($ninetyfourthF31, $nF31);
            $ninetyfourthF31 = $context->builder->fmul($ninetyfourthF31, $nF31);
            $ninetyfourthF31 = $context->builder->fmul($ninetyfourthF31, $nF31);
            $ninetyfourthF31 = $context->builder->fmul($ninetyfourthF31, $nF31);
            $ninetyfourthF31 = $context->builder->fmul($ninetyfourthF31, $nF31);
            $ninetyfourthF31 = $context->builder->fmul($ninetyfourthF31, $nF31);
            $ninetyfourthF31 = $context->builder->fmul($ninetyfourthF31, $nF31);
            $ninetyfourthF31 = $context->builder->fmul($ninetyfourthF31, $nF31);
            $ninetyfourthF31 = $context->builder->fmul($ninetyfourthF31, $nF31);
            $ninetyfourthF31 = $context->builder->fmul($ninetyfourthF31, $nF31);
            $ninetyfourthF31 = $context->builder->fmul($ninetyfourthF31, $nF31);
            $ninetyfourthF31 = $context->builder->fmul($ninetyfourthF31, $nF31);
            $ninetyfourthF31 = $context->builder->fmul($ninetyfourthF31, $nF31);
            $ninetyfourthF31 = $context->builder->fmul($ninetyfourthF31, $nF31);
            $ninetyfourthF31 = $context->builder->fmul($ninetyfourthF31, $nF31);
            $ninetyfourthF31 = $context->builder->fmul($ninetyfourthF31, $nF31);
            $ninetyfourthF31 = $context->builder->fmul($ninetyfourthF31, $nF31);
            $ninetyfourthF31 = $context->builder->fmul($ninetyfourthF31, $nF31);
            $ninetyfourthF31 = $context->builder->fmul($ninetyfourthF31, $nF31);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF31
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok31Block);
            $seventyfirstVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $seventiethLong,
                $n
            );
            $ov32 = JitLongArithOverflow::loadOverflowFlagI1($context, $seventyfirstVar->longArithOverflowFlag);
            if (null === $ov32 || null === $seventyfirstVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **94 expected smul overflow metadata (seventyfirst)');
            }
            $seventyfirstLong = JITVariable::KIND_VARIABLE === $seventyfirstVar->kind
                ? $context->builder->load($seventyfirstVar->value)
                : $seventyfirstVar->value;
            $ov32Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_seventyfirst_ov');
            $ok32Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_seventyfirst_ok');
            $context->builder->branchIf($ov32, $ov32Block, $ok32Block);

            $context->builder->positionAtEnd($ov32Block);
            $seventyfirstF32 = $context->builder->load($seventyfirstVar->longArithOverflowDoubleSlot);
            $nF32 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF32 = $context->builder->fmul($seventyfirstF32, $nF32);
            $ninetyfourthF32 = $context->builder->fmul($ninetyfourthF32, $nF32);
            $ninetyfourthF32 = $context->builder->fmul($ninetyfourthF32, $nF32);
            $ninetyfourthF32 = $context->builder->fmul($ninetyfourthF32, $nF32);
            $ninetyfourthF32 = $context->builder->fmul($ninetyfourthF32, $nF32);
            $ninetyfourthF32 = $context->builder->fmul($ninetyfourthF32, $nF32);
            $ninetyfourthF32 = $context->builder->fmul($ninetyfourthF32, $nF32);
            $ninetyfourthF32 = $context->builder->fmul($ninetyfourthF32, $nF32);
            $ninetyfourthF32 = $context->builder->fmul($ninetyfourthF32, $nF32);
            $ninetyfourthF32 = $context->builder->fmul($ninetyfourthF32, $nF32);
            $ninetyfourthF32 = $context->builder->fmul($ninetyfourthF32, $nF32);
            $ninetyfourthF32 = $context->builder->fmul($ninetyfourthF32, $nF32);
            $ninetyfourthF32 = $context->builder->fmul($ninetyfourthF32, $nF32);
            $ninetyfourthF32 = $context->builder->fmul($ninetyfourthF32, $nF32);
            $ninetyfourthF32 = $context->builder->fmul($ninetyfourthF32, $nF32);
            $ninetyfourthF32 = $context->builder->fmul($ninetyfourthF32, $nF32);
            $ninetyfourthF32 = $context->builder->fmul($ninetyfourthF32, $nF32);
            $ninetyfourthF32 = $context->builder->fmul($ninetyfourthF32, $nF32);
            $ninetyfourthF32 = $context->builder->fmul($ninetyfourthF32, $nF32);
            $ninetyfourthF32 = $context->builder->fmul($ninetyfourthF32, $nF32);
            $ninetyfourthF32 = $context->builder->fmul($ninetyfourthF32, $nF32);
            $ninetyfourthF32 = $context->builder->fmul($ninetyfourthF32, $nF32);
            $ninetyfourthF32 = $context->builder->fmul($ninetyfourthF32, $nF32);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF32
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok32Block);
            $seventysecondVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $seventyfirstLong,
                $n
            );
            $ov33 = JitLongArithOverflow::loadOverflowFlagI1($context, $seventysecondVar->longArithOverflowFlag);
            if (null === $ov33 || null === $seventysecondVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **94 expected smul overflow metadata (seventysecond)');
            }
            $seventysecondLong = JITVariable::KIND_VARIABLE === $seventysecondVar->kind
                ? $context->builder->load($seventysecondVar->value)
                : $seventysecondVar->value;
            $ov33Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_seventysecond_ov');
            $ok33Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_seventysecond_ok');
            $context->builder->branchIf($ov33, $ov33Block, $ok33Block);

            $context->builder->positionAtEnd($ov33Block);
            $seventysecondF33 = $context->builder->load($seventysecondVar->longArithOverflowDoubleSlot);
            $nF33 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF33 = $context->builder->fmul($seventysecondF33, $nF33);
            $ninetyfourthF33 = $context->builder->fmul($seventysecondF33, $nF33);
            $ninetyfourthF33 = $context->builder->fmul($ninetyfourthF33, $nF33);
            $ninetyfourthF33 = $context->builder->fmul($ninetyfourthF33, $nF33);
            $ninetyfourthF33 = $context->builder->fmul($ninetyfourthF33, $nF33);
            $ninetyfourthF33 = $context->builder->fmul($ninetyfourthF33, $nF33);
            $ninetyfourthF33 = $context->builder->fmul($ninetyfourthF33, $nF33);
            $ninetyfourthF33 = $context->builder->fmul($ninetyfourthF33, $nF33);
            $ninetyfourthF33 = $context->builder->fmul($ninetyfourthF33, $nF33);
            $ninetyfourthF33 = $context->builder->fmul($ninetyfourthF33, $nF33);
            $ninetyfourthF33 = $context->builder->fmul($ninetyfourthF33, $nF33);
            $ninetyfourthF33 = $context->builder->fmul($ninetyfourthF33, $nF33);
            $ninetyfourthF33 = $context->builder->fmul($ninetyfourthF33, $nF33);
            $ninetyfourthF33 = $context->builder->fmul($ninetyfourthF33, $nF33);
            $ninetyfourthF33 = $context->builder->fmul($ninetyfourthF33, $nF33);
            $ninetyfourthF33 = $context->builder->fmul($ninetyfourthF33, $nF33);
            $ninetyfourthF33 = $context->builder->fmul($ninetyfourthF33, $nF33);
            $ninetyfourthF33 = $context->builder->fmul($ninetyfourthF33, $nF33);
            $ninetyfourthF33 = $context->builder->fmul($ninetyfourthF33, $nF33);
            $ninetyfourthF33 = $context->builder->fmul($ninetyfourthF33, $nF33);
            $ninetyfourthF33 = $context->builder->fmul($ninetyfourthF33, $nF33);
            $ninetyfourthF33 = $context->builder->fmul($ninetyfourthF33, $nF33);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF33
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok33Block);
            $seventythirdVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $seventysecondLong,
                $n
            );
            $ov34 = JitLongArithOverflow::loadOverflowFlagI1($context, $seventythirdVar->longArithOverflowFlag);
            if (null === $ov34 || null === $seventythirdVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **94 expected smul overflow metadata (seventythird)');
            }
            $seventythirdLong = JITVariable::KIND_VARIABLE === $seventythirdVar->kind
                ? $context->builder->load($seventythirdVar->value)
                : $seventythirdVar->value;
            $ov34Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_seventythird_ov');
            $ok34Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_seventythird_ok');
            $context->builder->branchIf($ov34, $ov34Block, $ok34Block);

            $context->builder->positionAtEnd($ov34Block);
            $seventythirdF34 = $context->builder->load($seventythirdVar->longArithOverflowDoubleSlot);
            $nF34 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF34 = $context->builder->fmul($seventythirdF34, $nF34);
            $ninetyfourthF34 = $context->builder->fmul($ninetyfourthF34, $nF34);
            $ninetyfourthF34 = $context->builder->fmul($ninetyfourthF34, $nF34);
            $ninetyfourthF34 = $context->builder->fmul($ninetyfourthF34, $nF34);
            $ninetyfourthF34 = $context->builder->fmul($ninetyfourthF34, $nF34);
            $ninetyfourthF34 = $context->builder->fmul($ninetyfourthF34, $nF34);
            $ninetyfourthF34 = $context->builder->fmul($ninetyfourthF34, $nF34);
            $ninetyfourthF34 = $context->builder->fmul($ninetyfourthF34, $nF34);
            $ninetyfourthF34 = $context->builder->fmul($ninetyfourthF34, $nF34);
            $ninetyfourthF34 = $context->builder->fmul($ninetyfourthF34, $nF34);
            $ninetyfourthF34 = $context->builder->fmul($ninetyfourthF34, $nF34);
            $ninetyfourthF34 = $context->builder->fmul($ninetyfourthF34, $nF34);
            $ninetyfourthF34 = $context->builder->fmul($ninetyfourthF34, $nF34);
            $ninetyfourthF34 = $context->builder->fmul($ninetyfourthF34, $nF34);
            $ninetyfourthF34 = $context->builder->fmul($ninetyfourthF34, $nF34);
            $ninetyfourthF34 = $context->builder->fmul($ninetyfourthF34, $nF34);
            $ninetyfourthF34 = $context->builder->fmul($ninetyfourthF34, $nF34);
            $ninetyfourthF34 = $context->builder->fmul($ninetyfourthF34, $nF34);
            $ninetyfourthF34 = $context->builder->fmul($ninetyfourthF34, $nF34);
            $ninetyfourthF34 = $context->builder->fmul($ninetyfourthF34, $nF34);
            $ninetyfourthF34 = $context->builder->fmul($ninetyfourthF34, $nF34);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF34
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok34Block);
            $seventyfourthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $seventythirdLong,
                $n
            );
            $ov35 = JitLongArithOverflow::loadOverflowFlagI1($context, $seventyfourthVar->longArithOverflowFlag);
            if (null === $ov35 || null === $seventyfourthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **94 expected smul overflow metadata (seventyfourth)');
            }
            $seventyfourthLong = JITVariable::KIND_VARIABLE === $seventyfourthVar->kind
                ? $context->builder->load($seventyfourthVar->value)
                : $seventyfourthVar->value;
            $ov35Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_seventyfourth_ov');
            $ok35Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_seventyfourth_ok');
            $context->builder->branchIf($ov35, $ov35Block, $ok35Block);

            $context->builder->positionAtEnd($ov35Block);
            $seventyfourthF35 = $context->builder->load($seventyfourthVar->longArithOverflowDoubleSlot);
            $nF35 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF35 = $context->builder->fmul($seventyfourthF35, $nF35);
            $ninetyfourthF35 = $context->builder->fmul($seventyfourthF35, $nF35);
            $ninetyfourthF35 = $context->builder->fmul($ninetyfourthF35, $nF35);
            $ninetyfourthF35 = $context->builder->fmul($ninetyfourthF35, $nF35);
            $ninetyfourthF35 = $context->builder->fmul($ninetyfourthF35, $nF35);
            $ninetyfourthF35 = $context->builder->fmul($ninetyfourthF35, $nF35);
            $ninetyfourthF35 = $context->builder->fmul($ninetyfourthF35, $nF35);
            $ninetyfourthF35 = $context->builder->fmul($ninetyfourthF35, $nF35);
            $ninetyfourthF35 = $context->builder->fmul($ninetyfourthF35, $nF35);
            $ninetyfourthF35 = $context->builder->fmul($ninetyfourthF35, $nF35);
            $ninetyfourthF35 = $context->builder->fmul($ninetyfourthF35, $nF35);
            $ninetyfourthF35 = $context->builder->fmul($ninetyfourthF35, $nF35);
            $ninetyfourthF35 = $context->builder->fmul($ninetyfourthF35, $nF35);
            $ninetyfourthF35 = $context->builder->fmul($ninetyfourthF35, $nF35);
            $ninetyfourthF35 = $context->builder->fmul($ninetyfourthF35, $nF35);
            $ninetyfourthF35 = $context->builder->fmul($ninetyfourthF35, $nF35);
            $ninetyfourthF35 = $context->builder->fmul($ninetyfourthF35, $nF35);
            $ninetyfourthF35 = $context->builder->fmul($ninetyfourthF35, $nF35);
            $ninetyfourthF35 = $context->builder->fmul($ninetyfourthF35, $nF35);
            $ninetyfourthF35 = $context->builder->fmul($ninetyfourthF35, $nF35);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF35
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok35Block);
            $seventyfifthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $seventyfourthLong,
                $n
            );
            $ov36 = JitLongArithOverflow::loadOverflowFlagI1($context, $seventyfifthVar->longArithOverflowFlag);
            if (null === $ov36 || null === $seventyfifthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **94 expected smul overflow metadata (seventyfifth)');
            }
            $seventyfifthLong = JITVariable::KIND_VARIABLE === $seventyfifthVar->kind
                ? $context->builder->load($seventyfifthVar->value)
                : $seventyfifthVar->value;
            $ov36Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_seventyfifth_ov');
            $ok36Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_seventyfifth_ok');
            $context->builder->branchIf($ov36, $ov36Block, $ok36Block);

            $context->builder->positionAtEnd($ov36Block);
            $seventyfifthF36 = $context->builder->load($seventyfifthVar->longArithOverflowDoubleSlot);
            $nF36 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF36 = $context->builder->fmul($seventyfifthF36, $nF36);
            $ninetyfourthF36 = $context->builder->fmul($ninetyfourthF36, $nF36);
            $ninetyfourthF36 = $context->builder->fmul($ninetyfourthF36, $nF36);
            $ninetyfourthF36 = $context->builder->fmul($ninetyfourthF36, $nF36);
            $ninetyfourthF36 = $context->builder->fmul($ninetyfourthF36, $nF36);
            $ninetyfourthF36 = $context->builder->fmul($ninetyfourthF36, $nF36);
            $ninetyfourthF36 = $context->builder->fmul($ninetyfourthF36, $nF36);
            $ninetyfourthF36 = $context->builder->fmul($ninetyfourthF36, $nF36);
            $ninetyfourthF36 = $context->builder->fmul($ninetyfourthF36, $nF36);
            $ninetyfourthF36 = $context->builder->fmul($ninetyfourthF36, $nF36);
            $ninetyfourthF36 = $context->builder->fmul($ninetyfourthF36, $nF36);
            $ninetyfourthF36 = $context->builder->fmul($ninetyfourthF36, $nF36);
            $ninetyfourthF36 = $context->builder->fmul($ninetyfourthF36, $nF36);
            $ninetyfourthF36 = $context->builder->fmul($ninetyfourthF36, $nF36);
            $ninetyfourthF36 = $context->builder->fmul($ninetyfourthF36, $nF36);
            $ninetyfourthF36 = $context->builder->fmul($ninetyfourthF36, $nF36);
            $ninetyfourthF36 = $context->builder->fmul($ninetyfourthF36, $nF36);
            $ninetyfourthF36 = $context->builder->fmul($ninetyfourthF36, $nF36);
            $ninetyfourthF36 = $context->builder->fmul($ninetyfourthF36, $nF36);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF36
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok36Block);
            $seventysixthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $seventyfifthLong,
                $n
            );
            $ov37 = JitLongArithOverflow::loadOverflowFlagI1($context, $seventysixthVar->longArithOverflowFlag);
            if (null === $ov37 || null === $seventysixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **94 expected smul overflow metadata (seventysixth)');
            }
            $seventysixthLong = JITVariable::KIND_VARIABLE === $seventysixthVar->kind
                ? $context->builder->load($seventysixthVar->value)
                : $seventysixthVar->value;
            $ov37Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_seventysixth_ov');
            $ok37Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_seventysixth_ok');
            $context->builder->branchIf($ov37, $ov37Block, $ok37Block);

            $context->builder->positionAtEnd($ov37Block);
            $seventysixthF37 = $context->builder->load($seventysixthVar->longArithOverflowDoubleSlot);
            $nF37 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF37 = $context->builder->fmul($seventysixthF37, $nF37);
            $ninetyfourthF37 = $context->builder->fmul($ninetyfourthF37, $nF37);
            $ninetyfourthF37 = $context->builder->fmul($ninetyfourthF37, $nF37);
            $ninetyfourthF37 = $context->builder->fmul($ninetyfourthF37, $nF37);
            $ninetyfourthF37 = $context->builder->fmul($ninetyfourthF37, $nF37);
            $ninetyfourthF37 = $context->builder->fmul($ninetyfourthF37, $nF37);
            $ninetyfourthF37 = $context->builder->fmul($ninetyfourthF37, $nF37);
            $ninetyfourthF37 = $context->builder->fmul($ninetyfourthF37, $nF37);
            $ninetyfourthF37 = $context->builder->fmul($ninetyfourthF37, $nF37);
            $ninetyfourthF37 = $context->builder->fmul($ninetyfourthF37, $nF37);
            $ninetyfourthF37 = $context->builder->fmul($ninetyfourthF37, $nF37);
            $ninetyfourthF37 = $context->builder->fmul($ninetyfourthF37, $nF37);
            $ninetyfourthF37 = $context->builder->fmul($ninetyfourthF37, $nF37);
            $ninetyfourthF37 = $context->builder->fmul($ninetyfourthF37, $nF37);
            $ninetyfourthF37 = $context->builder->fmul($ninetyfourthF37, $nF37);
            $ninetyfourthF37 = $context->builder->fmul($ninetyfourthF37, $nF37);
            $ninetyfourthF37 = $context->builder->fmul($ninetyfourthF37, $nF37);
            $ninetyfourthF37 = $context->builder->fmul($ninetyfourthF37, $nF37);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF37
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok37Block);
            $seventyseventhVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $seventysixthLong,
                $n
            );
            $ov38 = JitLongArithOverflow::loadOverflowFlagI1($context, $seventyseventhVar->longArithOverflowFlag);
            if (null === $ov38 || null === $seventyseventhVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **94 expected smul overflow metadata (seventyseventh)');
            }
            $seventyseventhLong = JITVariable::KIND_VARIABLE === $seventyseventhVar->kind
                ? $context->builder->load($seventyseventhVar->value)
                : $seventyseventhVar->value;
            $ov38Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_seventyseventh_ov');
            $ok38Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_seventyseventh_ok');
            $context->builder->branchIf($ov38, $ov38Block, $ok38Block);

            $context->builder->positionAtEnd($ov38Block);
            $seventyseventhF38 = $context->builder->load($seventyseventhVar->longArithOverflowDoubleSlot);
            $nF38 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF38 = $context->builder->fmul($seventyseventhF38, $nF38);
            $ninetyfourthF38 = $context->builder->fmul($seventyseventhF38, $nF38);
            $ninetyfourthF38 = $context->builder->fmul($ninetyfourthF38, $nF38);
            $ninetyfourthF38 = $context->builder->fmul($ninetyfourthF38, $nF38);
            $ninetyfourthF38 = $context->builder->fmul($ninetyfourthF38, $nF38);
            $ninetyfourthF38 = $context->builder->fmul($ninetyfourthF38, $nF38);
            $ninetyfourthF38 = $context->builder->fmul($ninetyfourthF38, $nF38);
            $ninetyfourthF38 = $context->builder->fmul($ninetyfourthF38, $nF38);
            $ninetyfourthF38 = $context->builder->fmul($ninetyfourthF38, $nF38);
            $ninetyfourthF38 = $context->builder->fmul($ninetyfourthF38, $nF38);
            $ninetyfourthF38 = $context->builder->fmul($ninetyfourthF38, $nF38);
            $ninetyfourthF38 = $context->builder->fmul($ninetyfourthF38, $nF38);
            $ninetyfourthF38 = $context->builder->fmul($ninetyfourthF38, $nF38);
            $ninetyfourthF38 = $context->builder->fmul($ninetyfourthF38, $nF38);
            $ninetyfourthF38 = $context->builder->fmul($ninetyfourthF38, $nF38);
            $ninetyfourthF38 = $context->builder->fmul($ninetyfourthF38, $nF38);
            $ninetyfourthF38 = $context->builder->fmul($ninetyfourthF38, $nF38);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF38
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok38Block);
            $seventyeighthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $seventyseventhLong,
                $n
            );
            $ov39 = JitLongArithOverflow::loadOverflowFlagI1($context, $seventyeighthVar->longArithOverflowFlag);
            if (null === $ov39 || null === $seventyeighthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **94 expected smul overflow metadata (seventyeighth)');
            }
            $seventyeighthLong = JITVariable::KIND_VARIABLE === $seventyeighthVar->kind
                ? $context->builder->load($seventyeighthVar->value)
                : $seventyeighthVar->value;
            $ov39Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_seventyeighth_ov');
            $ok39Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_seventyeighth_ok');
            $context->builder->branchIf($ov39, $ov39Block, $ok39Block);

            $context->builder->positionAtEnd($ov39Block);
            $seventyeighthF39 = $context->builder->load($seventyeighthVar->longArithOverflowDoubleSlot);
            $nF39 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF39 = $context->builder->fmul($seventyeighthF39, $nF39);
            $ninetyfourthF39 = $context->builder->fmul($ninetyfourthF39, $nF39);
            $ninetyfourthF39 = $context->builder->fmul($ninetyfourthF39, $nF39);
            $ninetyfourthF39 = $context->builder->fmul($ninetyfourthF39, $nF39);
            $ninetyfourthF39 = $context->builder->fmul($ninetyfourthF39, $nF39);
            $ninetyfourthF39 = $context->builder->fmul($ninetyfourthF39, $nF39);
            $ninetyfourthF39 = $context->builder->fmul($ninetyfourthF39, $nF39);
            $ninetyfourthF39 = $context->builder->fmul($ninetyfourthF39, $nF39);
            $ninetyfourthF39 = $context->builder->fmul($ninetyfourthF39, $nF39);
            $ninetyfourthF39 = $context->builder->fmul($ninetyfourthF39, $nF39);
            $ninetyfourthF39 = $context->builder->fmul($ninetyfourthF39, $nF39);
            $ninetyfourthF39 = $context->builder->fmul($ninetyfourthF39, $nF39);
            $ninetyfourthF39 = $context->builder->fmul($ninetyfourthF39, $nF39);
            $ninetyfourthF39 = $context->builder->fmul($ninetyfourthF39, $nF39);
            $ninetyfourthF39 = $context->builder->fmul($ninetyfourthF39, $nF39);
            $ninetyfourthF39 = $context->builder->fmul($ninetyfourthF39, $nF39);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF39
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok39Block);
            $seventyninthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $seventyeighthLong,
                $n
            );
            $ov40 = JitLongArithOverflow::loadOverflowFlagI1($context, $seventyninthVar->longArithOverflowFlag);
            if (null === $ov40 || null === $seventyninthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **94 expected smul overflow metadata (seventyninth)');
            }
            $seventyninthLong = JITVariable::KIND_VARIABLE === $seventyninthVar->kind
                ? $context->builder->load($seventyninthVar->value)
                : $seventyninthVar->value;
            $ov40Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_seventyninth_ov');
            $ok40Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_seventyninth_ok');
            $context->builder->branchIf($ov40, $ov40Block, $ok40Block);

            $context->builder->positionAtEnd($ov40Block);
            $seventyninthF40 = $context->builder->load($seventyninthVar->longArithOverflowDoubleSlot);
            $nF40 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF40 = $context->builder->fmul($seventyninthF40, $nF40);
            $ninetyfourthF40 = $context->builder->fmul($ninetyfourthF40, $nF40);
            $ninetyfourthF40 = $context->builder->fmul($ninetyfourthF40, $nF40);
            $ninetyfourthF40 = $context->builder->fmul($ninetyfourthF40, $nF40);
            $ninetyfourthF40 = $context->builder->fmul($ninetyfourthF40, $nF40);
            $ninetyfourthF40 = $context->builder->fmul($ninetyfourthF40, $nF40);
            $ninetyfourthF40 = $context->builder->fmul($ninetyfourthF40, $nF40);
            $ninetyfourthF40 = $context->builder->fmul($ninetyfourthF40, $nF40);
            $ninetyfourthF40 = $context->builder->fmul($ninetyfourthF40, $nF40);
            $ninetyfourthF40 = $context->builder->fmul($ninetyfourthF40, $nF40);
            $ninetyfourthF40 = $context->builder->fmul($ninetyfourthF40, $nF40);
            $ninetyfourthF40 = $context->builder->fmul($ninetyfourthF40, $nF40);
            $ninetyfourthF40 = $context->builder->fmul($ninetyfourthF40, $nF40);
            $ninetyfourthF40 = $context->builder->fmul($ninetyfourthF40, $nF40);
            $ninetyfourthF40 = $context->builder->fmul($ninetyfourthF40, $nF40);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF40
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok40Block);
            $eightiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $seventyninthLong,
                $n
            );
            $ov41 = JitLongArithOverflow::loadOverflowFlagI1($context, $eightiethVar->longArithOverflowFlag);
            if (null === $ov41 || null === $eightiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **94 expected smul overflow metadata (eightieth)');
            }
            $eightiethLong = JITVariable::KIND_VARIABLE === $eightiethVar->kind
                ? $context->builder->load($eightiethVar->value)
                : $eightiethVar->value;
            $ov41Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_eightieth_ov');
            $ok41Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_eightieth_ok');
            $context->builder->branchIf($ov41, $ov41Block, $ok41Block);

            $context->builder->positionAtEnd($ov41Block);
            $eightiethF41 = $context->builder->load($eightiethVar->longArithOverflowDoubleSlot);
            $nF41 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF41 = $context->builder->fmul($eightiethF41, $nF41);
            $ninetyfourthF41 = $context->builder->fmul($ninetyfourthF41, $nF41);
            $ninetyfourthF41 = $context->builder->fmul($ninetyfourthF41, $nF41);
            $ninetyfourthF41 = $context->builder->fmul($ninetyfourthF41, $nF41);
            $ninetyfourthF41 = $context->builder->fmul($ninetyfourthF41, $nF41);
            $ninetyfourthF41 = $context->builder->fmul($ninetyfourthF41, $nF41);
            $ninetyfourthF41 = $context->builder->fmul($ninetyfourthF41, $nF41);
            $ninetyfourthF41 = $context->builder->fmul($ninetyfourthF41, $nF41);
            $ninetyfourthF41 = $context->builder->fmul($ninetyfourthF41, $nF41);
            $ninetyfourthF41 = $context->builder->fmul($ninetyfourthF41, $nF41);
            $ninetyfourthF41 = $context->builder->fmul($ninetyfourthF41, $nF41);
            $ninetyfourthF41 = $context->builder->fmul($ninetyfourthF41, $nF41);
            $ninetyfourthF41 = $context->builder->fmul($ninetyfourthF41, $nF41);
            $ninetyfourthF41 = $context->builder->fmul($ninetyfourthF41, $nF41);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF41
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok41Block);
            $eightyfirstVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $eightiethLong,
                $n
            );
            $ov42 = JitLongArithOverflow::loadOverflowFlagI1($context, $eightyfirstVar->longArithOverflowFlag);
            if (null === $ov42 || null === $eightyfirstVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **94 expected smul overflow metadata (eightyfirst)');
            }
            $eightyfirstLong = JITVariable::KIND_VARIABLE === $eightyfirstVar->kind
                ? $context->builder->load($eightyfirstVar->value)
                : $eightyfirstVar->value;
            $ov42Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_eightyfirst_ov');
            $ok42Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_eightyfirst_ok');
            $context->builder->branchIf($ov42, $ov42Block, $ok42Block);

            $context->builder->positionAtEnd($ov42Block);
            $eightyfirstF42 = $context->builder->load($eightyfirstVar->longArithOverflowDoubleSlot);
            $nF42 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF42 = $context->builder->fmul($eightyfirstF42, $nF42);
            $ninetyfourthF42 = $context->builder->fmul($ninetyfourthF42, $nF42);
            $ninetyfourthF42 = $context->builder->fmul($ninetyfourthF42, $nF42);
            $ninetyfourthF42 = $context->builder->fmul($ninetyfourthF42, $nF42);
            $ninetyfourthF42 = $context->builder->fmul($ninetyfourthF42, $nF42);
            $ninetyfourthF42 = $context->builder->fmul($ninetyfourthF42, $nF42);
            $ninetyfourthF42 = $context->builder->fmul($ninetyfourthF42, $nF42);
            $ninetyfourthF42 = $context->builder->fmul($ninetyfourthF42, $nF42);
            $ninetyfourthF42 = $context->builder->fmul($ninetyfourthF42, $nF42);
            $ninetyfourthF42 = $context->builder->fmul($ninetyfourthF42, $nF42);
            $ninetyfourthF42 = $context->builder->fmul($ninetyfourthF42, $nF42);
            $ninetyfourthF42 = $context->builder->fmul($ninetyfourthF42, $nF42);
            $ninetyfourthF42 = $context->builder->fmul($ninetyfourthF42, $nF42);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF42
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok42Block);
            $eightysecondVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $eightyfirstLong,
                $n
            );
            $ov43 = JitLongArithOverflow::loadOverflowFlagI1($context, $eightysecondVar->longArithOverflowFlag);
            if (null === $ov43 || null === $eightysecondVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **94 expected smul overflow metadata (eightysecond)');
            }
            $eightysecondLong = JITVariable::KIND_VARIABLE === $eightysecondVar->kind
                ? $context->builder->load($eightysecondVar->value)
                : $eightysecondVar->value;
            $ov43Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_eightysecond_ov');
            $ok43Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_eightysecond_ok');
            $context->builder->branchIf($ov43, $ov43Block, $ok43Block);

            $context->builder->positionAtEnd($ov43Block);
            $eightysecondF43 = $context->builder->load($eightysecondVar->longArithOverflowDoubleSlot);
            $nF43 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $ninetyfourthF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $ninetyfourthF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $ninetyfourthF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $ninetyfourthF43 = $context->builder->fmul($eightysecondF43, $nF43);
            $ninetyfourthF43 = $context->builder->fmul($ninetyfourthF43, $nF43);
            $ninetyfourthF43 = $context->builder->fmul($ninetyfourthF43, $nF43);
            $ninetyfourthF43 = $context->builder->fmul($ninetyfourthF43, $nF43);
            $ninetyfourthF43 = $context->builder->fmul($ninetyfourthF43, $nF43);
            $ninetyfourthF43 = $context->builder->fmul($ninetyfourthF43, $nF43);
            $ninetyfourthF43 = $context->builder->fmul($ninetyfourthF43, $nF43);
            $ninetyfourthF43 = $context->builder->fmul($ninetyfourthF43, $nF43);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF43
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok43Block);
            $eightythirdVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $eightysecondLong,
                $n
            );
            $ov44 = JitLongArithOverflow::loadOverflowFlagI1($context, $eightythirdVar->longArithOverflowFlag);
            if (null === $ov44 || null === $eightythirdVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **94 expected smul overflow metadata (eightythird)');
            }
            $eightythirdLong = JITVariable::KIND_VARIABLE === $eightythirdVar->kind
                ? $context->builder->load($eightythirdVar->value)
                : $eightythirdVar->value;
            $ov44Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_eightythird_ov');
            $ok44Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_eightythird_ok');
            $context->builder->branchIf($ov44, $ov44Block, $ok44Block);

            $context->builder->positionAtEnd($ov44Block);
            $eightythirdF44 = $context->builder->load($eightythirdVar->longArithOverflowDoubleSlot);
            $nF44 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF44 = $context->builder->fmul($eightythirdF44, $nF44);
            $ninetyfourthF44 = $context->builder->fmul($eightythirdF44, $nF44);
            $ninetyfourthF44 = $context->builder->fmul($eightythirdF44, $nF44);
            $ninetyfourthF44 = $context->builder->fmul($eightythirdF44, $nF44);
            $ninetyfourthF44 = $context->builder->fmul($ninetyfourthF44, $nF44);
            $ninetyfourthF44 = $context->builder->fmul($ninetyfourthF44, $nF44);
            $ninetyfourthF44 = $context->builder->fmul($ninetyfourthF44, $nF44);
            $ninetyfourthF44 = $context->builder->fmul($ninetyfourthF44, $nF44);
            $ninetyfourthF44 = $context->builder->fmul($ninetyfourthF44, $nF44);
            $ninetyfourthF44 = $context->builder->fmul($ninetyfourthF44, $nF44);
            $ninetyfourthF44 = $context->builder->fmul($ninetyfourthF44, $nF44);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF44
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok44Block);
            $eightyfourthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $eightythirdLong,
                $n
            );
            $ov45 = JitLongArithOverflow::loadOverflowFlagI1($context, $eightyfourthVar->longArithOverflowFlag);
            if (null === $ov45 || null === $eightyfourthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **94 expected smul overflow metadata (eightyfourth)');
            }
            $eightyfourthLong = JITVariable::KIND_VARIABLE === $eightyfourthVar->kind
                ? $context->builder->load($eightyfourthVar->value)
                : $eightyfourthVar->value;
            $ov45Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_eightyfourth_ov');
            $ok45Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_eightyfourth_ok');
            $context->builder->branchIf($ov45, $ov45Block, $ok45Block);

            $context->builder->positionAtEnd($ov45Block);
            $eightyfourthF45 = $context->builder->load($eightyfourthVar->longArithOverflowDoubleSlot);
            $nF45 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF45 = $context->builder->fmul($eightyfourthF45, $nF45);
            $ninetyfourthF45 = $context->builder->fmul($eightyfourthF45, $nF45);
            $ninetyfourthF45 = $context->builder->fmul($eightyfourthF45, $nF45);
            $ninetyfourthF45 = $context->builder->fmul($ninetyfourthF45, $nF45);
            $ninetyfourthF45 = $context->builder->fmul($ninetyfourthF45, $nF45);
            $ninetyfourthF45 = $context->builder->fmul($ninetyfourthF45, $nF45);
            $ninetyfourthF45 = $context->builder->fmul($ninetyfourthF45, $nF45);
            $ninetyfourthF45 = $context->builder->fmul($ninetyfourthF45, $nF45);
            $ninetyfourthF45 = $context->builder->fmul($ninetyfourthF45, $nF45);
            $ninetyfourthF45 = $context->builder->fmul($ninetyfourthF45, $nF45);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF45
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok45Block);
            $eightyfifthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $eightyfourthLong,
                $n
            );
            $ov46 = JitLongArithOverflow::loadOverflowFlagI1($context, $eightyfifthVar->longArithOverflowFlag);
            if (null === $ov46 || null === $eightyfifthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **94 expected smul overflow metadata (eightyfifth)');
            }
            $eightyfifthLong = JITVariable::KIND_VARIABLE === $eightyfifthVar->kind
                ? $context->builder->load($eightyfifthVar->value)
                : $eightyfifthVar->value;
            $ov46Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_eightyfifth_ov');
            $ok46Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_eightyfifth_ok');
            $context->builder->branchIf($ov46, $ov46Block, $ok46Block);

            $context->builder->positionAtEnd($ov46Block);
            $eightyfifthF46 = $context->builder->load($eightyfifthVar->longArithOverflowDoubleSlot);
            $nF46 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF46 = $context->builder->fmul($eightyfifthF46, $nF46);
            $ninetyfourthF46 = $context->builder->fmul($eightyfifthF46, $nF46);
            $ninetyfourthF46 = $context->builder->fmul($ninetyfourthF46, $nF46);
            $ninetyfourthF46 = $context->builder->fmul($ninetyfourthF46, $nF46);
            $ninetyfourthF46 = $context->builder->fmul($ninetyfourthF46, $nF46);
            $ninetyfourthF46 = $context->builder->fmul($ninetyfourthF46, $nF46);
            $ninetyfourthF46 = $context->builder->fmul($ninetyfourthF46, $nF46);
            $ninetyfourthF46 = $context->builder->fmul($ninetyfourthF46, $nF46);
            $ninetyfourthF46 = $context->builder->fmul($ninetyfourthF46, $nF46);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF46
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok46Block);
            $eightysixthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $eightyfifthLong,
                $n
            );
            $ov47 = JitLongArithOverflow::loadOverflowFlagI1($context, $eightysixthVar->longArithOverflowFlag);
            if (null === $ov47 || null === $eightysixthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **94 expected smul overflow metadata (eightysixth)');
            }
            $eightysixthLong = JITVariable::KIND_VARIABLE === $eightysixthVar->kind
                ? $context->builder->load($eightysixthVar->value)
                : $eightysixthVar->value;
            $ov47Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_eightysixth_ov');
            $ok47Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_eightysixth_ok');
            $context->builder->branchIf($ov47, $ov47Block, $ok47Block);

            $context->builder->positionAtEnd($ov47Block);
            $eightysixthF47 = $context->builder->load($eightysixthVar->longArithOverflowDoubleSlot);
            $nF47 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF47 = $context->builder->fmul($eightysixthF47, $nF47);
            $ninetyfourthF47 = $context->builder->fmul($ninetyfourthF47, $nF47);
            $ninetyfourthF47 = $context->builder->fmul($ninetyfourthF47, $nF47);
            $ninetyfourthF47 = $context->builder->fmul($ninetyfourthF47, $nF47);
            $ninetyfourthF47 = $context->builder->fmul($ninetyfourthF47, $nF47);
            $ninetyfourthF47 = $context->builder->fmul($ninetyfourthF47, $nF47);
            $ninetyfourthF47 = $context->builder->fmul($ninetyfourthF47, $nF47);
            $ninetyfourthF47 = $context->builder->fmul($ninetyfourthF47, $nF47);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF47
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok47Block);

            $eightyseventhVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $eightysixthLong,
                $n
            );
            $ov48 = JitLongArithOverflow::loadOverflowFlagI1($context, $eightyseventhVar->longArithOverflowFlag);
            if (null === $ov48 || null === $eightyseventhVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **94 expected smul overflow metadata (eightyseventh)');
            }
            $eightyseventhLong = JITVariable::KIND_VARIABLE === $eightyseventhVar->kind
                ? $context->builder->load($eightyseventhVar->value)
                : $eightyseventhVar->value;
            $ov48Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_eightyseventh_ov');
            $ok48Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_eightyseventh_ok');
            $context->builder->branchIf($ov48, $ov48Block, $ok48Block);

            $context->builder->positionAtEnd($ov48Block);
            $eightyseventhF48 = $context->builder->load($eightyseventhVar->longArithOverflowDoubleSlot);
            $nF48 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF48 = $context->builder->fmul($eightyseventhF48, $nF48);
            $ninetyfourthF48 = $context->builder->fmul($eightyseventhF48, $nF48);
            $ninetyfourthF48 = $context->builder->fmul($ninetyfourthF48, $nF48);
            $ninetyfourthF48 = $context->builder->fmul($ninetyfourthF48, $nF48);
            $ninetyfourthF48 = $context->builder->fmul($ninetyfourthF48, $nF48);
            $ninetyfourthF48 = $context->builder->fmul($ninetyfourthF48, $nF48);
            $ninetyfourthF48 = $context->builder->fmul($ninetyfourthF48, $nF48);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF48
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok48Block);
            $eightyeighthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $eightyseventhLong,
                $n
            );
            $ov49 = JitLongArithOverflow::loadOverflowFlagI1($context, $eightyeighthVar->longArithOverflowFlag);
            if (null === $ov49 || null === $eightyeighthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **94 expected smul overflow metadata (eightyeighth)');
            }
            $eightyeighthLong = JITVariable::KIND_VARIABLE === $eightyeighthVar->kind
                ? $context->builder->load($eightyeighthVar->value)
                : $eightyeighthVar->value;
            $ov49Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_eightyeighth_ov');
            $ok49Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_eightyeighth_ok');
            $context->builder->branchIf($ov49, $ov49Block, $ok49Block);

            $context->builder->positionAtEnd($ov49Block);
            $eightyeighthF49 = $context->builder->load($eightyeighthVar->longArithOverflowDoubleSlot);
            $nF49 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF49 = $context->builder->fmul($eightyeighthF49, $nF49);
            $ninetyfourthF49 = $context->builder->fmul($ninetyfourthF49, $nF49);
            $ninetyfourthF49 = $context->builder->fmul($ninetyfourthF49, $nF49);
            $ninetyfourthF49 = $context->builder->fmul($ninetyfourthF49, $nF49);
            $ninetyfourthF49 = $context->builder->fmul($ninetyfourthF49, $nF49);
            $ninetyfourthF49 = $context->builder->fmul($ninetyfourthF49, $nF49);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF49
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok49Block);
            $eightyninthVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $eightyeighthLong,
                $n
            );
            $ov50 = JitLongArithOverflow::loadOverflowFlagI1($context, $eightyninthVar->longArithOverflowFlag);
            if (null === $ov50 || null === $eightyninthVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **94 expected smul overflow metadata (eightyninth)');
            }
            $eightyninthLong = JITVariable::KIND_VARIABLE === $eightyninthVar->kind
                ? $context->builder->load($eightyninthVar->value)
                : $eightyninthVar->value;
            $ov50Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_eightyninth_ov');
            $ok50Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_eightyninth_ok');
            $context->builder->branchIf($ov50, $ov50Block, $ok50Block);

            $context->builder->positionAtEnd($ov50Block);
            $eightyninthF50 = $context->builder->load($eightyninthVar->longArithOverflowDoubleSlot);
            $nF50 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF50 = $context->builder->fmul($eightyninthF50, $nF50);
            $ninetyfourthF50 = $context->builder->fmul($ninetyfourthF50, $nF50);
            $ninetyfourthF50 = $context->builder->fmul($ninetyfourthF50, $nF50);
            $ninetyfourthF50 = $context->builder->fmul($ninetyfourthF50, $nF50);
            $ninetyfourthF50 = $context->builder->fmul($ninetyfourthF50, $nF50);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF50
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok50Block);
            $ninetiethVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $eightyninthLong,
                $n
            );
            $ov51 = JitLongArithOverflow::loadOverflowFlagI1($context, $ninetiethVar->longArithOverflowFlag);
            if (null === $ov51 || null === $ninetiethVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **94 expected smul overflow metadata (ninetieth)');
            }
            $ninetiethLong = JITVariable::KIND_VARIABLE === $ninetiethVar->kind
                ? $context->builder->load($ninetiethVar->value)
                : $ninetiethVar->value;
            $ov51Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_ninetieth_ov');
            $ok51Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_ninetieth_ok');
            $context->builder->branchIf($ov51, $ov51Block, $ok51Block);

            $context->builder->positionAtEnd($ov51Block);
            $ninetiethF51 = $context->builder->load($ninetiethVar->longArithOverflowDoubleSlot);
            $nF51 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF51 = $context->builder->fmul($ninetiethF51, $nF51);
            $ninetyfourthF51 = $context->builder->fmul($ninetyfourthF51, $nF51);
            $ninetyfourthF51 = $context->builder->fmul($ninetyfourthF51, $nF51);
            $ninetyfourthF51 = $context->builder->fmul($ninetyfourthF51, $nF51);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF51
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok51Block);
            $ninetyfirstVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $ninetiethLong,
                $n
            );
            $ov52 = JitLongArithOverflow::loadOverflowFlagI1($context, $ninetyfirstVar->longArithOverflowFlag);
            if (null === $ov52 || null === $ninetyfirstVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **94 expected smul overflow metadata (ninetyfirst)');
            }
            $ninetyfirstLong = JITVariable::KIND_VARIABLE === $ninetyfirstVar->kind
                ? $context->builder->load($ninetyfirstVar->value)
                : $ninetyfirstVar->value;
            $ov52Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_ninetyfirst_ov');
            $ok52Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_ninetyfirst_ok');
            $context->builder->branchIf($ov52, $ov52Block, $ok52Block);

            $context->builder->positionAtEnd($ov52Block);
            $ninetyfirstF52 = $context->builder->load($ninetyfirstVar->longArithOverflowDoubleSlot);
            $nF52 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF52 = $context->builder->fmul($ninetyfirstF52, $nF52);
            $ninetyfourthF52 = $context->builder->fmul($ninetyfourthF52, $nF52);
            $ninetyfourthF52 = $context->builder->fmul($ninetyfourthF52, $nF52);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF52
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok52Block);
            $ninetysecondVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $ninetyfirstLong,
                $n
            );
            $ov53 = JitLongArithOverflow::loadOverflowFlagI1($context, $ninetysecondVar->longArithOverflowFlag);
            if (null === $ov53 || null === $ninetysecondVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **94 expected smul overflow metadata (ninetysecond)');
            }
            $ninetysecondLong = JITVariable::KIND_VARIABLE === $ninetysecondVar->kind
                ? $context->builder->load($ninetysecondVar->value)
                : $ninetysecondVar->value;
            $ov53Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_ninetysecond_ov');
            $ok53Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_ninetysecond_ok');
            $context->builder->branchIf($ov53, $ov53Block, $ok53Block);

            $context->builder->positionAtEnd($ov53Block);
            $ninetysecondF53 = $context->builder->load($ninetysecondVar->longArithOverflowDoubleSlot);
            $nF53 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF53 = $context->builder->fmul($ninetysecondF53, $nF53);
            $ninetyfourthF53 = $context->builder->fmul($ninetyfourthF53, $nF53);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF53
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok53Block);
            $ninetythirdVar = JitLongArithOverflow::binaryNativeLong(
                $context,
                OpCode::TYPE_MUL,
                $ninetysecondLong,
                $n
            );
            $ov54 = JitLongArithOverflow::loadOverflowFlagI1($context, $ninetythirdVar->longArithOverflowFlag);
            if (null === $ov54 || null === $ninetythirdVar->longArithOverflowDoubleSlot) {
                throw new \LogicException('pow() **94 expected smul overflow metadata (ninetythird)');
            }
            $ninetythirdLong = JITVariable::KIND_VARIABLE === $ninetythirdVar->kind
                ? $context->builder->load($ninetythirdVar->value)
                : $ninetythirdVar->value;
            $ov54Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_ninetythird_ov');
            $ok54Block = BasicBlockHelper::append($context, 'pow_ninetyfourth_ninetythird_ok');
            $context->builder->branchIf($ov54, $ov54Block, $ok54Block);

            $context->builder->positionAtEnd($ov54Block);
            $ninetythirdF54 = $context->builder->load($ninetythirdVar->longArithOverflowDoubleSlot);
            $nF54 = $context->builder->siToFp($n, $f64);
            $ninetyfourthF54 = $context->builder->fmul($ninetythirdF54, $nF54);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $slotPtr,
                $ninetyfourthF54
            );
            JitValueBox::publishAfterWrite($context, $slotPtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($ok54Block);
            JitLongArithOverflow::writeBoxedBinary(
                $context,
                OpCode::TYPE_MUL,
                $ninetythirdLong,
                $n,
                $slotPtr
            );
            $context->builder->branch($doneBlock);


            $context->builder->positionAtEnd($doneBlock);

            return true;
        }



        throw new \LogicException('JitPowIntegerEmitHigh: unhandled expFold '.$expFold);
    }
}
